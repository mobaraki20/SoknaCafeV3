param()
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Get-Sha256Hex([string]$Path) {
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try {
        $stream = [System.IO.File]::Open($Path,[System.IO.FileMode]::Open,[System.IO.FileAccess]::Read,[System.IO.FileShare]::ReadWrite)
        try {
            return ([System.BitConverter]::ToString($sha.ComputeHash($stream))).Replace('-','').ToLowerInvariant()
        }
        finally { $stream.Dispose() }
    }
    finally { $sha.Dispose() }
}

function Test-Tcp([string]$HostName,[int]$Port,[int]$TimeoutMs) {
    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $async = $client.BeginConnect($HostName,$Port,$null,$null)
        if (-not $async.AsyncWaitHandle.WaitOne($TimeoutMs,$false)) { return $false }
        $client.EndConnect($async)
        return $client.Connected
    }
    catch { return $false }
    finally { $client.Close() }
}

function Get-IniValue([string[]]$Lines,[string]$KeyName) {
    $section = ''
    foreach ($raw in $Lines) {
        $line = ([string]$raw).Trim()
        if ($line.Length -eq 0) { continue }
        if ($line.StartsWith('#') -or $line.StartsWith(';')) { continue }
        if ($line -match '^\[(.+)\]$') {
            $section = $Matches[1].Trim().ToLowerInvariant()
            continue
        }
        if (($section -ne 'mysqld') -and ($section -ne 'server')) { continue }
        if ($line -match '^([^=\s]+)\s*=\s*(.*)$') {
            $k = $Matches[1].Trim().ToLowerInvariant().Replace('_','-')
            $v = $Matches[2].Trim().Trim('"').Trim("'")
            if ($k -eq $KeyName.ToLowerInvariant().Replace('_','-')) { return $v }
        }
    }
    return ''
}

function Probe-LocalWeb {
    $result = [ordered]@{ status = $null; build = ''; runtime = ''; error = '' }
    try {
        $request = [System.Net.HttpWebRequest]::Create('http://127.0.0.1:18080/login.php')
        $request.Method = 'GET'
        $request.AllowAutoRedirect = $false
        $request.Timeout = 5000
        $response = $request.GetResponse()
        try {
            $result.status = [int]$response.StatusCode
            $result.build = [string]$response.Headers['X-Sokna-Build']
            $result.runtime = [string]$response.Headers['X-Sokna-Runtime']
        }
        finally { $response.Close() }
    }
    catch [System.Net.WebException] {
        $result.error = $_.Exception.Message
        if ($null -ne $_.Exception.Response) {
            try {
                $response = $_.Exception.Response
                $result.status = [int]$response.StatusCode
                $result.build = [string]$response.Headers['X-Sokna-Build']
                $result.runtime = [string]$response.Headers['X-Sokna-Runtime']
            }
            finally { $response.Close() }
        }
    }
    catch { $result.error = $_.Exception.Message }
    return [pscustomobject]$result
}

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host 'Please run 01-RECOVER-SOKNA-MARIADB.cmd as Administrator.' -ForegroundColor Red
    exit 5
}

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$desktop = [Environment]::GetFolderPath([Environment+SpecialFolder]::Desktop)
$evidenceDir = Join-Path $desktop ('SOKNA-MARIADB-RECOVERY-' + $stamp)
New-Item -ItemType Directory -Path $evidenceDir -Force | Out-Null
$transcriptPath = Join-Path $evidenceDir 'recovery-transcript.txt'
Start-Transcript -LiteralPath $transcriptPath -Force | Out-Null

try {
    Write-Host 'SOKNA MariaDB tc.log recovery' -ForegroundColor Cyan
    Write-Host 'No database table/data files will be deleted.' -ForegroundColor DarkGray

    $svc = Get-CimInstance Win32_Service -Filter "Name='SoknaMariaDB'" -ErrorAction Stop
    if ($null -eq $svc) { throw 'SoknaMariaDB service was not found.' }
    if ([string]$svc.State -ne 'Stopped') { throw ('SoknaMariaDB must be Stopped. Current state: ' + [string]$svc.State) }

    $pathName = [string]$svc.PathName
    $exeMatch = [regex]::Match($pathName,'^\s*"(?<exe>[^"]+mysqld\.exe)"',[System.Text.RegularExpressions.RegexOptions]::IgnoreCase)
    if (-not $exeMatch.Success) { throw 'Could not resolve mysqld.exe from SoknaMariaDB ImagePath.' }
    $mysqldExe = $exeMatch.Groups['exe'].Value

    $cfgMatch = [regex]::Match($pathName,'--defaults-file=(?<cfg>[^"\s]+)',[System.Text.RegularExpressions.RegexOptions]::IgnoreCase)
    if (-not $cfgMatch.Success) { throw 'Could not resolve --defaults-file from SoknaMariaDB ImagePath.' }
    $defaultsFile = $cfgMatch.Groups['cfg'].Value.Trim()

    if (-not (Test-Path -LiteralPath $mysqldExe -PathType Leaf)) { throw ('mysqld.exe not found: ' + $mysqldExe) }
    if (-not (Test-Path -LiteralPath $defaultsFile -PathType Leaf)) { throw ('MariaDB my.ini not found: ' + $defaultsFile) }

    $packageRoot = Split-Path -Parent (Split-Path -Parent (Split-Path -Parent (Split-Path -Parent $mysqldExe)))
    $expectedRoot = [System.IO.Path]::GetFullPath((Join-Path $packageRoot 'Data\MariaDB'))
    $defaultsFull = [System.IO.Path]::GetFullPath($defaultsFile)
    if (-not $defaultsFull.StartsWith($expectedRoot,[StringComparison]::OrdinalIgnoreCase)) {
        throw ('Refusing recovery: defaults-file is outside expected SOKNA MariaDB root: ' + $defaultsFull)
    }

    $ini = Get-Content -LiteralPath $defaultsFull -ErrorAction Stop
    $datadirValue = Get-IniValue $ini 'datadir'
    if ([string]::IsNullOrWhiteSpace($datadirValue)) {
        $dataDir = Split-Path -Parent $defaultsFull
    }
    elseif ([System.IO.Path]::IsPathRooted($datadirValue)) {
        $dataDir = [System.IO.Path]::GetFullPath($datadirValue)
    }
    else {
        $dataDir = [System.IO.Path]::GetFullPath((Join-Path (Split-Path -Parent $defaultsFull) $datadirValue))
    }
    if (-not $dataDir.StartsWith($expectedRoot,[StringComparison]::OrdinalIgnoreCase)) {
        throw ('Refusing recovery: datadir is outside expected SOKNA MariaDB root: ' + $dataDir)
    }
    if (-not (Test-Path -LiteralPath $dataDir -PathType Container)) { throw ('MariaDB datadir not found: ' + $dataDir) }

    $port = 3306
    $portValue = Get-IniValue $ini 'port'
    if (-not [string]::IsNullOrWhiteSpace($portValue)) {
        $parsedPort = 0
        if ([int]::TryParse($portValue,[ref]$parsedPort)) { $port = $parsedPort }
    }

    $tcPath = Join-Path $dataDir 'tc.log'
    $logTc = Get-IniValue $ini 'log-tc'
    if (-not [string]::IsNullOrWhiteSpace($logTc)) {
        if ([System.IO.Path]::IsPathRooted($logTc)) { $tcPath = [System.IO.Path]::GetFullPath($logTc) }
        else { $tcPath = [System.IO.Path]::GetFullPath((Join-Path $dataDir $logTc)) }
    }
    if (-not $tcPath.StartsWith($expectedRoot,[StringComparison]::OrdinalIgnoreCase)) {
        throw ('Refusing recovery: tc.log is outside expected SOKNA MariaDB root: ' + $tcPath)
    }
    if (-not (Test-Path -LiteralPath $tcPath -PathType Leaf)) { throw ('tc.log not found: ' + $tcPath) }

    $recent = @(Get-WinEvent -FilterHashtable @{LogName='Application'; StartTime=(Get-Date).AddHours(-12)} -ErrorAction SilentlyContinue | Where-Object { $_.ProviderName -eq 'MariaDB' })
    $hasBadMagic = (@($recent | Where-Object { ([string]$_.Message) -match 'Bad magic header in tc log' }).Count -gt 0)
    $hasCantInit = (@($recent | Where-Object { ([string]$_.Message) -match "Can't init tc log" }).Count -gt 0)
    if (-not ($hasBadMagic -and $hasCantInit)) {
        throw 'Expected tc.log corruption signature was not found in recent MariaDB Event Log. No change made.'
    }

    $tcHash = Get-Sha256Hex $tcPath
    $backupCopy = Join-Path $evidenceDir ('tc.log.corrupt.' + $tcHash.Substring(0,12) + '.original')
    Copy-Item -LiteralPath $tcPath -Destination $backupCopy -Force
    $renamedPath = Join-Path $dataDir ('tc.log.corrupt.' + $stamp + '.' + $tcHash.Substring(0,12) + '.bak')
    if (Test-Path -LiteralPath $renamedPath) { throw ('Backup target already exists: ' + $renamedPath) }

    [ordered]@{
        captured_at = (Get-Date).ToString('o')
        service_state_before = [string]$svc.State
        service_start_mode = [string]$svc.StartMode
        mysqld_exe = $mysqldExe
        defaults_file = $defaultsFull
        datadir = $dataDir
        port = $port
        tc_log = $tcPath
        tc_log_sha256 = $tcHash
        event_bad_magic = $hasBadMagic
        event_cant_init = $hasCantInit
    } | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $evidenceDir 'PRE_RECOVERY.json') -Encoding UTF8

    Move-Item -LiteralPath $tcPath -Destination $renamedPath
    Write-Host ('Preserved corrupt tc.log as: ' + $renamedPath) -ForegroundColor Yellow

    Start-Service -Name 'SoknaMariaDB' -ErrorAction Stop
    $deadline = (Get-Date).AddSeconds(40)
    $running = $false
    while ((Get-Date) -lt $deadline) {
        Start-Sleep -Seconds 2
        $state = (Get-Service -Name 'SoknaMariaDB').Status
        if ($state -eq [System.ServiceProcess.ServiceControllerStatus]::Running) { $running = $true; break }
        if ($state -eq [System.ServiceProcess.ServiceControllerStatus]::Stopped) { break }
    }

    if (-not $running) {
        $afterSvc = Get-CimInstance Win32_Service -Filter "Name='SoknaMariaDB'" -ErrorAction SilentlyContinue
        [ordered]@{
            result = 'FAILED_TO_START'
            service_state = if ($null -ne $afterSvc) { [string]$afterSvc.State } else { 'unknown' }
            exit_code = if ($null -ne $afterSvc) { [int]$afterSvc.ExitCode } else { -1 }
            service_specific_exit_code = if ($null -ne $afterSvc) { [int]$afterSvc.ServiceSpecificExitCode } else { -1 }
            corrupt_tc_preserved_as = $renamedPath
            database_files_deleted = $false
        } | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $evidenceDir 'RESULT.json') -Encoding UTF8
        throw 'MariaDB still failed to start. The corrupt tc.log backup is preserved; no database files were deleted.'
    }

    $tcpOk = Test-Tcp '127.0.0.1' $port 3000
    Start-Sleep -Seconds 3
    $local = Probe-LocalWeb
    $newTcExists = Test-Path -LiteralPath $tcPath -PathType Leaf
    $newTcSha = ''
    if ($newTcExists) { $newTcSha = Get-Sha256Hex $tcPath }

    $healthy = ($tcpOk -and ($local.status -eq 200) -and ($local.runtime -eq 'php-ready'))
    $resultName = 'MARIADB_RUNNING_LOCAL_NOT_HEALTHY'
    if ($healthy) { $resultName = 'RECOVERED' }

    [ordered]@{
        result = $resultName
        mariadb_state = [string](Get-Service -Name 'SoknaMariaDB').Status
        tcp_port = $port
        tcp_ok = $tcpOk
        local_http_status = $local.status
        local_build = $local.build
        local_runtime = $local.runtime
        local_error = $local.error
        corrupt_tc_preserved_as = $renamedPath
        corrupt_tc_sha256 = $tcHash
        new_tc_exists = $newTcExists
        new_tc_sha256 = $newTcSha
        database_files_deleted = $false
    } | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $evidenceDir 'RESULT.json') -Encoding UTF8

    if ($healthy) {
        Write-Host ''
        Write-Host 'RECOVERY PASS' -ForegroundColor Green
        Write-Host 'MariaDB is Running, port 3306 is reachable, and Local Web is healthy.' -ForegroundColor Green
    }
    else {
        Write-Host ''
        Write-Host 'MariaDB is Running, but Local Web is not fully healthy yet.' -ForegroundColor Yellow
    }
}
catch {
    $_ | Out-String | Set-Content -LiteralPath (Join-Path $evidenceDir 'ERROR.txt') -Encoding UTF8
    Write-Host ''
    Write-Host ('STOP: ' + $_.Exception.Message) -ForegroundColor Red
}
finally {
    try { Stop-Transcript | Out-Null } catch {}
    $zipPath = $evidenceDir + '.zip'
    try {
        if (Test-Path -LiteralPath $zipPath) { Remove-Item -LiteralPath $zipPath -Force }
        Compress-Archive -Path (Join-Path $evidenceDir '*') -DestinationPath $zipPath -CompressionLevel Optimal
        Write-Host ('Evidence ZIP: ' + $zipPath) -ForegroundColor Cyan
    }
    catch {
        Write-Host ('Could not create evidence ZIP: ' + $_.Exception.Message) -ForegroundColor Yellow
    }
}
