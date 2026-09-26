# Shared Windows setup mechanics; no business/backup state lives here.
Set-StrictMode -Version 2
$ErrorActionPreference = 'Stop'
$script:Redactions = @()

function Assert-SoknaSafePath([string]$Path) {
    $cursor = [IO.Path]::GetFullPath($Path)
    while ($cursor) {
        if (Test-Path -LiteralPath $cursor) {
            if ((Get-Item -Force -LiteralPath $cursor).Attributes -band [IO.FileAttributes]::ReparsePoint) {
                throw 'Setup refuses a reparse-point path.'
            }
        }
        $parent = [IO.Directory]::GetParent($cursor)
        if ($null -eq $parent) { break }
        $cursor = $parent.FullName
    }
}

function Assert-SoknaPrivateInputFile([string]$Path,[string]$Label) {
    Assert-SoknaSafePath $Path
    if(-not(Test-Path -LiteralPath $Path -PathType Leaf)){throw "$Label file was not found."}
    $blocked=@('S-1-1-0','S-1-5-11','S-1-5-32-545')
    $acl=Get-Acl -LiteralPath $Path
    foreach($rule in @($acl.Access)){
        if([string]$rule.AccessControlType -ne 'Allow'){continue}
        try{$sid=$rule.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value}catch{continue}
        if($blocked -contains $sid){throw "$Label must not be readable by Everyone, Authenticated Users, or BUILTIN\Users."}
    }
    return [IO.Path]::GetFullPath($Path)
}

function New-SoknaPrivateDirectory([string]$Path) {
    Assert-SoknaSafePath $Path
    [IO.Directory]::CreateDirectory($Path) | Out-Null
    $acl = New-Object Security.AccessControl.DirectorySecurity
    $acl.SetAccessRuleProtection($true, $false)
    $sids = @('S-1-5-18', 'S-1-5-32-544', [Security.Principal.WindowsIdentity]::GetCurrent().User.Value) | Select-Object -Unique
    foreach ($sid in $sids) {
        $identity = New-Object Security.Principal.SecurityIdentifier($sid)
        $rule = New-Object Security.AccessControl.FileSystemAccessRule($identity, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow')
        $acl.AddAccessRule($rule)
    }
    Set-Acl -LiteralPath $Path -AclObject $acl
    return $Path
}


function Test-SoknaPendingReboot {
    $markers = @(
        'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending',
        'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired'
    )
    foreach ($marker in $markers) { if (Test-Path $marker) { return $true } }
    try {
        $value = (Get-ItemProperty 'HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager' -Name PendingFileRenameOperations -ErrorAction Stop).PendingFileRenameOperations
        if ($null -ne $value -and @($value).Count -gt 0) { return $true }
    } catch { }
    return $false
}

function Get-SoknaFreeBytes([string]$Path) {
    $full = [IO.Path]::GetFullPath($Path)
    $root = [IO.Path]::GetPathRoot($full)
    if ([string]::IsNullOrWhiteSpace($root)) { throw 'Setup could not determine the target drive.' }
    $drive = New-Object IO.DriveInfo($root)
    return [int64]$drive.AvailableFreeSpace
}

function Get-SoknaTcpListenerOwners([int]$Port) {
    if ($Port -lt 1 -or $Port -gt 65535) { throw 'Invalid TCP port.' }
    $items = @()
    try {
        $connections = @(Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction Stop)
        foreach ($connection in $connections) {
            $listenerProcessId = [int]$connection.OwningProcess
            $processPath = ''
            try { $processPath = [string](Get-Process -Id $listenerProcessId -ErrorAction Stop).Path } catch { }
            $items += [pscustomobject]@{ pid=$listenerProcessId; path=$processPath; address=[string]$connection.LocalAddress }
        }
    } catch {
        # Get-NetTCPConnection is not guaranteed on every supported Windows image.
        $netstat = & "$env:SystemRoot\System32\netstat.exe" -ano -p tcp 2>$null
        foreach ($line in @($netstat)) {
            if ($line -match '^\s*TCP\s+\S+:' + [regex]::Escape([string]$Port) + '\s+\S+\s+LISTENING\s+(\d+)\s*$') {
                $listenerProcessId = [int]$matches[1]
                $processPath = ''
                try { $processPath = [string](Get-Process -Id $listenerProcessId -ErrorAction Stop).Path } catch { }
                $items += [pscustomobject]@{ pid=$listenerProcessId; path=$processPath; address='' }
            }
        }
    }
    return @($items | Sort-Object pid -Unique)
}

function Resolve-SoknaWebServerExecutable([string]$Candidate,[string[]]$ExecutableNames=@('httpd.exe','apache.exe')) {
    if (-not [string]::IsNullOrWhiteSpace($Candidate)) {
        $full = [IO.Path]::GetFullPath($Candidate)
        Assert-SoknaSafePath $full
        if (-not (Test-Path -LiteralPath $full -PathType Leaf)) { throw 'Web server executable was not found.' }
        return $full
    }
    foreach ($name in $ExecutableNames) {
        $command = Get-Command $name -ErrorAction SilentlyContinue
        if ($command -and $command.Source) { return [string]$command.Source }
    }
    return ''
}


function Get-SoknaServiceDiagnostic([string]$Name) {
    $service = Get-Service -Name $Name -ErrorAction SilentlyContinue
    if (-not $service) {
        return [ordered]@{ name=$Name; exists=$false; status='not-installed'; start_mode='unknown' }
    }
    $startMode = 'unknown'
    try {
        $escaped = $Name.Replace("'","''")
        $instance = Get-CimInstance Win32_Service -Filter "Name='$escaped'" -ErrorAction Stop
        if ($instance -and $instance.StartMode) { $startMode = [string]$instance.StartMode }
    } catch { }
    return [ordered]@{ name=$Name; exists=$true; status=[string]$service.Status; start_mode=$startMode }
}

function Get-SoknaSafeFileVersion([string]$Path) {
    try {
        if (-not $Path -or -not (Test-Path -LiteralPath $Path -PathType Leaf)) { return '' }
        $item = Get-Item -LiteralPath $Path -ErrorAction Stop
        $value = [string]$item.VersionInfo.FileVersion
        if ([string]::IsNullOrWhiteSpace($value)) { $value = [string]$item.VersionInfo.ProductVersion }
        return (Protect-SoknaLog $value)
    } catch { return '' }
}

function Get-SoknaPrintHealthDiagnostic([string]$DataRoot) {
    $path = Join-Path $DataRoot 'print-worker\health.json'
    try {
        if (-not (Test-Path -LiteralPath $path -PathType Leaf -ErrorAction Stop)) { return [ordered]@{ available=$false } }
        $health = Get-Content -LiteralPath $path -Raw -ErrorAction Stop | ConvertFrom-Json
        # Strict allowlist: never copy the durable health file itself into support.zip.
        return [ordered]@{
            available=$true
            version=(Protect-SoknaLog ([string]$health.agent_version))
            state=(Protect-SoknaLog ([string]$health.state))
            updated_at=(Protect-SoknaLog ([string]$health.updated_at))
            local_backlog_count=$(if ($null -ne $health.local_backlog_count) { [int]$health.local_backlog_count } else { 0 })
            local_unknown_count=$(if ($null -ne $health.local_unknown_count) { [int]$health.local_unknown_count } else { 0 })
            pending_report_count=$(if ($null -ne $health.pending_report_count) { [int]$health.pending_report_count } else { 0 })
            reconciliation_report_count=$(if ($null -ne $health.reconciliation_report_count) { [int]$health.reconciliation_report_count } else { 0 })
            printer_discovery_fresh=$(if ($null -ne $health.printer_discovery_fresh) { [bool]$health.printer_discovery_fresh } else { $false })
            transport_state=(Protect-SoknaLog ([string]$health.transport_state))
            coordinator_state=(Protect-SoknaLog ([string]$health.coordinator_state))
            claim_reconciliation_required=$(if ($null -ne $health.claim_reconciliation_required) { [bool]$health.claim_reconciliation_required } else { $false })
            claim_conflict_count=$(if ($null -ne $health.claim_conflict_count) { [int]$health.claim_conflict_count } else { 0 })
        }
    } catch {
        return [ordered]@{ available=$false; parse_error=(Protect-SoknaLog $_.Exception.Message) }
    }
}

function Copy-SoknaSanitizedDiagnosticLog([string]$Source,[string]$Destination,[int64]$MaxBytes=2097152) {
    if ([string]::IsNullOrWhiteSpace($Source) -or -not (Test-Path -LiteralPath $Source -PathType Leaf)) { return $false }
    Assert-SoknaSafePath $Source
    $info = Get-Item -LiteralPath $Source
    if ($info.Length -gt $MaxBytes) { throw 'Diagnostic log is larger than the support-package limit.' }
    $text = [IO.File]::ReadAllText($Source)
    [IO.File]::WriteAllText($Destination, (Protect-SoknaLog $text), (New-Object Text.UTF8Encoding($false)))
    return $true
}

function Write-SoknaSupportSnapshot(
    [string]$Session,
    [string]$AppRoot,
    [string]$DataRoot,
    [string]$RuntimeServiceName,
    [string]$PrintServiceName,
    [string]$ServiceHostPath,
    [string]$PrintServicePath
) {
    $appVersion = ''
    try {
        $versionPath = Join-Path $AppRoot 'VERSION.txt'
        if (Test-Path -LiteralPath $versionPath -PathType Leaf) { $appVersion = (Get-Content -LiteralPath $versionPath -Raw).Trim() }
    } catch { }
    $snapshot = [ordered]@{
        schema_version=1
        captured_utc=[DateTime]::UtcNow.ToString('o')
        os=[ordered]@{ version=[Environment]::OSVersion.Version.ToString(); x64=[Environment]::Is64BitOperatingSystem }
        app=[ordered]@{ version=(Protect-SoknaLog $appVersion) }
        runtime_service=(Get-SoknaServiceDiagnostic $RuntimeServiceName)
        print_worker_service=(Get-SoknaServiceDiagnostic $PrintServiceName)
        runtime_host=[ordered]@{ version=(Get-SoknaSafeFileVersion $ServiceHostPath) }
        print_worker=[ordered]@{ binary_version=(Get-SoknaSafeFileVersion $PrintServicePath); health=(Get-SoknaPrintHealthDiagnostic $DataRoot) }
    }
    $snapshot | ConvertTo-Json -Depth 10 | Set-Content -LiteralPath (Join-Path $Session 'components.json') -Encoding UTF8
}

function Add-SoknaSecret([string]$Value) {
    if ($Value) { $script:Redactions += $Value }
}

function Protect-SoknaLog([string]$Text) {
    foreach ($value in ($script:Redactions | Sort-Object Length -Descending | Select-Object -Unique)) {
        $Text = $Text.Replace($value, '[REDACTED]')
    }
    $Text = [regex]::Replace($Text, '(?i)(password|passphrase|shared_secret|token|authorization|app[._]key)(\s*[=:]\s*)[^\s,;]+', '$1$2[REDACTED]')
    return $Text
}

function ConvertTo-SoknaArgument([string]$Value) {
    # Windows CommandLineToArgvW/C runtime quoting, including trailing backslashes.
    '"' + [regex]::Replace([regex]::Replace($Value, '(\\*)"', '$1$1\"'), '(\\+)$', '$1$1') + '"'
}

function Invoke-SoknaProcess {
    param([string]$File, [string[]]$Arguments, [int[]]$SuccessCodes = @(0), [int]$TimeoutSeconds = 120, [string]$WorkingDirectory = '')
    $info = New-Object Diagnostics.ProcessStartInfo
    $info.FileName = $File
    if ($WorkingDirectory) { $info.WorkingDirectory = $WorkingDirectory }
    $info.Arguments = ($Arguments | ForEach-Object { ConvertTo-SoknaArgument $_ }) -join ' '
    $info.UseShellExecute = $false
    $info.CreateNoWindow = $true
    $info.RedirectStandardOutput = $true
    $info.RedirectStandardError = $true
    $info.StandardOutputEncoding = New-Object Text.UTF8Encoding($false)
    $info.StandardErrorEncoding = New-Object Text.UTF8Encoding($false)
    $process = New-Object Diagnostics.Process
    $process.StartInfo = $info
    try {
        if (-not $process.Start()) { throw 'Child process could not start.' }
        $stdout = $process.StandardOutput.ReadToEndAsync()
        $stderr = $process.StandardError.ReadToEndAsync()
        if (-not $process.WaitForExit($TimeoutSeconds * 1000)) {
            # Do not allow a timed-out restore to continue silently in the background.
            & "$env:SystemRoot\System32\taskkill.exe" /PID $process.Id /T /F 2>&1 | Out-Null
            $process.WaitForExit(10000) | Out-Null
            throw 'Child process timed out; inspect the setup session before retrying.'
        }
        $result = [pscustomobject]@{ ExitCode = $process.ExitCode; Output = $stdout.Result; Error = $stderr.Result }
        if ($SuccessCodes -notcontains $result.ExitCode) {
            $detail = Protect-SoknaLog ($result.Error + ' ' + $result.Output)
            throw "Child exit code $($result.ExitCode): $detail"
        }
        return $result
    } finally { $process.Dispose() }
}

function Wait-SoknaService([string]$Name, [string]$Status) {
    $service = New-Object ServiceProcess.ServiceController($Name)
    try { $service.WaitForStatus([ServiceProcess.ServiceControllerStatus]::$Status, [TimeSpan]::FromSeconds(30)) }
    finally { $service.Dispose() }
}

Export-ModuleMember -Function *-Sokna*
