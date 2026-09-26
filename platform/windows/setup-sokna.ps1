param(
    [ValidateSet('New','Recover','Repair','Validate','RemovePlatform')][string]$Mode = 'Validate',
    [string]$AppRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path,
    [Parameter(Mandatory=$true)][string]$PhpExe,
    [string]$OpenSslExe = '',
    [string]$ServiceHostExe = '',
    [string]$DataRoot = "$env:ProgramData\SOKNA",
    [string]$Hostname = 'sokna.local',
    [string]$InstallerLogFile = '',
    [string]$SetupConfigFile = '',
    [string]$RecoveryFile = '',
    [string]$RecoveryPassphraseFile = '',
    [switch]$ValidateRepair,
    [string]$PrintWorkerBundle = '',
    [string]$WebServerExe = '',
    [string]$PrerequisiteManifest = '',
    [ValidateRange(1,65535)][int]$HttpsPort = 443,
    [ValidateRange(268435456,1099511627776)][int64]$MinimumFreeBytes = 2147483648,
    [switch]$RequireWebServerPreflight,
    [switch]$PreflightOnly,
    [switch]$SkipHttps,
    [switch]$SkipService
)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object Text.UTF8Encoding($false)
Import-Module (Join-Path $PSScriptRoot 'setup-support.psm1') -DisableNameChecking -Force
$ServiceName = 'SoknaRuntime'
$PrintWorkerServiceName = 'SoknaPrintWorker'
$PrintWorkerRegistryPath = 'HKLM:\SOFTWARE\Sokna\Local\PrintWorker'
$LegacyPrintWorkerServiceName = 'SoknaPrintAgent6'
$LegacyPrintWorkerRegistryPath = 'HKLM:\SOFTWARE\Sokna\PrintAgent'
$sessionId = if($env:SOKNA_SETUP_SESSION_ID -match '^[a-fA-F0-9]{32}$'){$env:SOKNA_SETUP_SESSION_ID.ToLowerInvariant()}else{[guid]::NewGuid().ToString('N')}
$session = ''
$stage = 'diagnostics'
$privateConfig = ''
$privatePassphrase = ''
$printWorkerProvisionFile = ''
$exitCode = 2
$oldDataDir = $env:SOKNA_DATA_DIR
$summary = [ordered]@{ session_id=$sessionId; mode=$Mode; preflight_only=[bool]$PreflightOnly; ok=$false; started_utc=[DateTime]::UtcNow.ToString('o'); stage=$stage; version='unknown'; reboot_required=$false; business_setup_committed=$false; preflight=[ordered]@{} }

function Write-SetupEvent([string]$Name, [string]$Message) {
    if ($session) {
        [ordered]@{ utc=[DateTime]::UtcNow.ToString('o'); session_id=$sessionId; stage=$Name; message=(Protect-SoknaLog $Message) } |
            ConvertTo-Json -Compress | Add-Content -LiteralPath (Join-Path $session 'events.jsonl') -Encoding UTF8
    }
}

function Assert-File([string]$Path, [string]$Label) {
    if (-not $Path -or -not (Test-Path -LiteralPath $Path -PathType Leaf)) { throw "$Label file was not found." }
    Assert-SoknaSafePath $Path
}

function Copy-PrivateInput([string]$Path, [string]$Name) {
    # Session ACL is already restricted before any secret bytes are written.
    $destination = Join-Path $session ($Name + '.private')
    [IO.File]::WriteAllBytes($destination, [IO.File]::ReadAllBytes($Path))
    return $destination
}

function Test-SoknaLocalWebHealth([string]$HostName, [int]$Port) {
    $authority = if ($Port -eq 443) { $HostName } else { $HostName + ':' + $Port }
    $uri = 'https://' + $authority + '/login.php'
    $request = [Net.HttpWebRequest]::Create($uri)
    $request.Method = 'GET'
    $request.Proxy = $null
    $request.AllowAutoRedirect = $true
    $request.Timeout = 10000
    $request.ReadWriteTimeout = 10000
    $request.Headers['Cache-Control'] = 'no-store'
    $response = $null
    try {
        $response = [Net.HttpWebResponse]$request.GetResponse()
        $status = [int]$response.StatusCode
        $runtimeHeader = [string]$response.Headers['X-Sokna-Runtime']
        $reader = New-Object IO.StreamReader($response.GetResponseStream())
        try { $body = $reader.ReadToEnd() } finally { $reader.Dispose() }
        if ($status -ne 200 -or $runtimeHeader -ne 'php-ready' -or $body -match '<\?php' -or $body -notmatch '<html\s+lang="fa"\s+dir="rtl"' -or $body -notmatch 'id="loginForm"') {
            throw 'Local HTTPS endpoint answered, but PHP execution or the expected SOKNA login surface was not proven.'
        }
        return [ordered]@{ ok=$true; uri=$uri; status=$status; marker='sokna-login'; php_execution='requirements-proven' }
    } catch {
        throw ('Local HTTPS health check failed for ' + $uri + '. Apache must load the SOKNA managed configuration before setup can be complete. ' + $_.Exception.Message)
    } finally {
        if ($response) { $response.Close() }
    }
}

function Get-RuntimeBinPath([string]$HostExe) {
    ((@($HostExe,'--php',$PhpExe,'--app-root',$AppRoot,'--data-root',$DataRoot) | ForEach-Object { ConvertTo-SoknaArgument $_ }) -join ' ')
}

function Install-RuntimeService([string]$Candidate) {
    $binDir = New-SoknaPrivateDirectory (Join-Path $DataRoot 'bin')
    $hostExe = Join-Path $binDir 'SoknaRuntimeService.exe'
    $existing = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
    $wasRunning = $existing -and $existing.Status -eq 'Running'
    $backup = Join-Path $session 'previous-service.exe.private'
    $hadBinary = Test-Path -LiteralPath $hostExe
    $created = $false
    $sc = "$env:SystemRoot\System32\sc.exe"
    if ($hadBinary) { Copy-Item -LiteralPath $hostExe -Destination $backup }
    try {
        if ($existing -and $existing.Status -ne 'Stopped') {
            Stop-Service -Name $ServiceName -ErrorAction Stop
            Wait-SoknaService $ServiceName 'Stopped'
        }
        Copy-Item -LiteralPath $Candidate -Destination $hostExe -Force
        if (-not $existing) {
            Invoke-SoknaProcess $sc @('create',$ServiceName,'binPath=',(Get-RuntimeBinPath $hostExe),'start=','auto','DisplayName=','SOKNA Local Runtime') | Out-Null
            $created = $true
            Invoke-SoknaProcess $sc @('description',$ServiceName,'SOKNA Local Runtime process supervisor') | Out-Null
            Invoke-SoknaProcess $sc @('failure',$ServiceName,'reset=','86400','actions=','restart/5000/restart/15000/restart/30000') | Out-Null
        }
        Start-Service -Name $ServiceName
        Wait-SoknaService $ServiceName 'Running'
        Write-SetupEvent 'runtime-service' 'Service reached RUNNING; application health is a separate gate.'
    } catch {
        $failure = $_
        try {
            $current = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
            if ($current -and $current.Status -ne 'Stopped') { Stop-Service $ServiceName; Wait-SoknaService $ServiceName 'Stopped' }
            if ($hadBinary) { Copy-Item -LiteralPath $backup -Destination $hostExe -Force }
            elseif (Test-Path -LiteralPath $hostExe) { Remove-Item -LiteralPath $hostExe -Force }
            if ($created) { Invoke-SoknaProcess $sc @('delete',$ServiceName) | Out-Null }
            elseif ($wasRunning) { Start-Service $ServiceName; Wait-SoknaService $ServiceName 'Running' }
            Write-SetupEvent 'service-rollback' 'Previous service binary and running state restored.'
        } catch { Write-SetupEvent 'service-rollback-failed' $_.Exception.Message }
        throw $failure
    } finally { Remove-Item -LiteralPath $backup -Force -ErrorAction SilentlyContinue }
}

function Remove-RuntimeService {
    # Validate every service before stopping any: a foreign print service must
    # not cause partial removal of an otherwise healthy runtime.
    $owned = @(
        @{ Name=$ServiceName; Command=(Get-RuntimeBinPath (Join-Path $DataRoot 'bin\SoknaRuntimeService.exe')) },
        @{ Name=$PrintWorkerServiceName; Command=(ConvertTo-SoknaArgument (Join-Path $DataRoot 'bin\print-worker\Service\Sokna.PrintAgent.Service.exe')) }
    )
    foreach ($entry in $owned) {
        $service = Get-Service -Name $entry.Name -ErrorAction SilentlyContinue
        if (-not $service) { continue }
        try {
            $actual = (Get-ItemProperty ("HKLM:\SYSTEM\CurrentControlSet\Services\" + $entry.Name)).ImagePath
            if ($actual -cne $entry.Command) { throw 'Service ownership mismatch; nothing was removed.' }
        } finally { $service.Dispose() }
    }
    foreach ($entry in $owned) {
        $service = Get-Service -Name $entry.Name -ErrorAction SilentlyContinue
        if (-not $service) { continue }
        try {
            $actual = (Get-ItemProperty ("HKLM:\SYSTEM\CurrentControlSet\Services\" + $entry.Name)).ImagePath
            if ($actual -cne $entry.Command) { throw 'Service ownership changed during removal; retry after inspection.' }
            if ($service.Status -ne 'Stopped') { Stop-Service -Name $entry.Name -ErrorAction Stop; Wait-SoknaService $entry.Name 'Stopped' }
            Invoke-SoknaProcess "$env:SystemRoot\System32\sc.exe" @('delete',$entry.Name) | Out-Null
        } finally { $service.Dispose() }
        $remaining = Get-Service -Name $entry.Name -ErrorAction SilentlyContinue
        if ($remaining) { $remaining.Dispose(); throw 'Service removal is pending; close service-management windows and retry uninstall.' }
    }
    # Preserve queue/config/DPAPI material and legacy services. Never remove an
    # external/shared dependency or a user's business data as part of uninstall.
    Write-SetupEvent 'remove-platform' 'Owned runtime and print services removed; application, queue, data, keys and TLS preserved.'
}

function Resolve-PrintWorkerBundle([string]$Candidate) {
    $resolved = $Candidate
    if ([string]::IsNullOrWhiteSpace($resolved)) { $resolved = Join-Path $PSScriptRoot 'bin\print-worker' }
    $resolved = [IO.Path]::GetFullPath($resolved).TrimEnd('\')
    Assert-SoknaSafePath $resolved
    if (-not (Test-Path -LiteralPath $resolved -PathType Container)) { throw 'Bundled SOKNA Print Worker was not built. Build the internal component before setup.' }
    foreach ($required in @('component-manifest.json','Service\Sokna.PrintAgent.Service.exe','Worker\Sokna.PrintAgent.Worker.exe','Worker\Fonts\Vazirmatn-Regular.ttf','Worker\Fonts\Vazirmatn-Bold.ttf','Worker\Fonts\OFL.txt')) {
        Assert-File (Join-Path $resolved $required) ('Print Worker ' + $required)
    }
    $manifestPath = Join-Path $resolved 'component-manifest.json'
    $manifest = Get-Content $manifestPath -Raw | ConvertFrom-Json
    if ([string]$manifest.format -ne 'sokna-internal-print-worker-v1' -or [string]$manifest.upstream_baseline -ne '6.2.5') { throw 'Bundled Print Worker manifest is invalid or untrusted.' }
    $entries = @($manifest.files)
    if ($entries.Count -lt 1) { throw 'Bundled Print Worker manifest contains no payload files.' }
    $prefix = $resolved + '\'
    $expected = @{}
    foreach ($entry in $entries) {
        $rawRel = ([string]$entry.path).Trim()
        $rel = $rawRel.Replace('/','\')
        if ([string]::IsNullOrWhiteSpace($rel) -or [IO.Path]::IsPathRooted($rel)) { throw 'Print Worker manifest contains an invalid path.' }
        $file = [IO.Path]::GetFullPath((Join-Path $resolved $rel))
        if (-not $file.StartsWith($prefix,[StringComparison]::OrdinalIgnoreCase)) { throw 'Print Worker manifest path escapes the bundle root.' }
        if (-not (Test-Path -LiteralPath $file -PathType Leaf)) { throw "Print Worker payload file is missing: $rawRel" }
        if ((Get-Item -LiteralPath $file).Length -ne [int64]$entry.size) { throw "Print Worker payload size mismatch: $rawRel" }
        $expectedHash = ([string]$entry.sha256).Trim().ToLowerInvariant()
        if ($expectedHash -notmatch '^[a-f0-9]{64}$') { throw "Print Worker manifest SHA256 is invalid: $rawRel" }
        if ((Get-FileHash -LiteralPath $file -Algorithm SHA256).Hash.ToLowerInvariant() -ne $expectedHash) { throw "Print Worker payload SHA256 mismatch: $rawRel" }
        $key = $rawRel.Replace('\','/').ToLowerInvariant()
        if ($expected.ContainsKey($key)) { throw "Print Worker manifest contains duplicate path: $rawRel" }
        $expected[$key] = $true
    }
    $actual = @(Get-ChildItem -LiteralPath $resolved -File -Recurse | Where-Object { $_.FullName -ne $manifestPath } | ForEach-Object { $_.FullName.Substring($prefix.Length).Replace('\','/').ToLowerInvariant() })
    if ($actual.Count -ne $expected.Count) { throw 'Print Worker payload/manifest file count mismatch.' }
    foreach ($key in $actual) { if (-not $expected.ContainsKey($key)) { throw "Unexpected Print Worker payload file: $key" } }
    return $resolved
}

function Get-RegistryString([string]$Path,[string]$Name) {
    try {
        if (-not (Test-Path $Path)) { return '' }
        return [string](Get-ItemProperty -Path $Path -Name $Name -ErrorAction Stop).$Name
    } catch { return '' }
}

function Install-PrintWorkerComponent([string]$BundleRoot, [string]$ProvisionFile = '') {
    $componentRoot = Join-Path $DataRoot 'bin\print-worker'
    $componentData = Join-Path $DataRoot 'print-worker'
    $serviceExe = Join-Path $componentRoot 'Service\Sokna.PrintAgent.Service.exe'
    $sc = "$env:SystemRoot\System32\sc.exe"
    $existing = Get-Service -Name $PrintWorkerServiceName -ErrorAction SilentlyContinue
    $wasRunning = $existing -and $existing.Status -eq 'Running'
    $legacy = Get-Service -Name $LegacyPrintWorkerServiceName -ErrorAction SilentlyContinue
    $legacyWasRunning = $legacy -and $legacy.Status -eq 'Running'
    $legacyStartMode = ''
    if ($legacy) {
        try { $legacyStartMode = [string](Get-CimInstance Win32_Service -Filter "Name='$LegacyPrintWorkerServiceName'").StartMode } catch { }
    }
    $previousRegistryDataRoot = Get-RegistryString $PrintWorkerRegistryPath 'DataRoot'
    $backup = Join-Path $session 'previous-print-worker.private'
    $hadComponent = Test-Path -LiteralPath $componentRoot -PathType Container
    $createdService = $false
    $migratedLegacyData = $false
    $componentDataExistedBeforeMigration = Test-Path -LiteralPath $componentData -PathType Container
    $componentDataWasEmptyBeforeMigration = $false

    try {
        if ($legacy -and $legacy.Status -ne 'Stopped') { Stop-Service $LegacyPrintWorkerServiceName -ErrorAction Stop; Wait-SoknaService $LegacyPrintWorkerServiceName 'Stopped' }
        if ($existing -and $existing.Status -ne 'Stopped') { Stop-Service $PrintWorkerServiceName -ErrorAction Stop; Wait-SoknaService $PrintWorkerServiceName 'Stopped' }

        if ($hadComponent) { Copy-Item -LiteralPath $componentRoot -Destination $backup -Recurse -Force }
        if (Test-Path -LiteralPath $componentRoot) { Remove-Item -LiteralPath $componentRoot -Recurse -Force }
        New-Item $componentRoot -ItemType Directory -Force | Out-Null
        Copy-Item -Path (Join-Path $BundleRoot '*') -Destination $componentRoot -Recurse -Force

        $legacyDataRoot = Get-RegistryString $LegacyPrintWorkerRegistryPath 'DataRoot'
        $legacyRootExists = -not [string]::IsNullOrWhiteSpace($legacyDataRoot) -and (Test-Path -LiteralPath $legacyDataRoot -PathType Container)
        $legacyIsDifferentRoot = $legacyRootExists -and ([IO.Path]::GetFullPath($legacyDataRoot).TrimEnd('\') -ne [IO.Path]::GetFullPath($componentData).TrimEnd('\'))
        $legacyEntries = if ($legacyRootExists) { @(Get-ChildItem -LiteralPath $legacyDataRoot -Force -ErrorAction Stop) } else { @() }
        $newEntries = if (Test-Path -LiteralPath $componentData -PathType Container) { @(Get-ChildItem -LiteralPath $componentData -Force -ErrorAction Stop) } else { @() }
        $legacyHasData = $legacyEntries.Count -gt 0
        $newHasData = $newEntries.Count -gt 0
        $componentDataWasEmptyBeforeMigration = $componentDataExistedBeforeMigration -and -not $newHasData

        # Never merge two independently populated durable roots. Even a seemingly harmless
        # log/config-only destination can carry pairing or reconciliation state.
        if ($legacyIsDifferentRoot -and $legacyHasData -and $newHasData) {
            throw 'Both legacy and internal Print Worker data roots contain state. Automatic merge is unsafe; reconcile before Repair.'
        }
        if ($legacyIsDifferentRoot -and $legacyHasData -and -not $newHasData) {
            if (Test-Path -LiteralPath $componentData) { Remove-Item -LiteralPath $componentData -Recurse -Force }
            New-Item $componentData -ItemType Directory -Force | Out-Null
            Get-ChildItem -LiteralPath $legacyDataRoot -Force | Copy-Item -Destination $componentData -Recurse -Force
            $migratedLegacyData = $true
        } else {
            New-Item $componentData -ItemType Directory -Force | Out-Null
        }
        # Restrict durable print state to SYSTEM/Admins/current elevated setup owner.
        New-SoknaPrivateDirectory $componentData | Out-Null

        New-Item $PrintWorkerRegistryPath -Force | Out-Null
        New-ItemProperty $PrintWorkerRegistryPath -Name DataRoot -Value $componentData -PropertyType String -Force | Out-Null

        # Healthy Repair preserves the existing pairing. Only a genuinely missing local
        # config/secret gets a new server token, and services are already stopped here.
        $needsRepairProvision = -not (Test-Path -LiteralPath (Join-Path $componentData 'config.json') -PathType Leaf) -or -not (Test-Path -LiteralPath (Join-Path $componentData 'secret.dat') -PathType Leaf)
        if ([string]::IsNullOrWhiteSpace($ProvisionFile) -and $Mode -eq 'Repair' -and $needsRepairProvision) {
            $ProvisionFile = Join-Path $session 'print-worker-repair-provision.private'
            Invoke-SoknaProcess $PhpExe @((Join-Path $AppRoot 'tools\provision-print-worker.php'),("--output-file=" + $ProvisionFile)) | Out-Null
            Write-SetupEvent 'print-worker-repair-pairing' 'Missing internal Print Worker pairing was regenerated while print services were stopped.'
        }

        if (-not $existing) {
            Invoke-SoknaProcess $sc @('create',$PrintWorkerServiceName,'binPath=',(ConvertTo-SoknaArgument $serviceExe),'start=','delayed-auto','obj=','LocalSystem','DisplayName=','SOKNA Print Worker') | Out-Null
            $createdService = $true
        } else {
            Invoke-SoknaProcess $sc @('config',$PrintWorkerServiceName,'binPath=',(ConvertTo-SoknaArgument $serviceExe),'start=','delayed-auto','obj=','LocalSystem','DisplayName=','SOKNA Print Worker') | Out-Null
        }
        Invoke-SoknaProcess $sc @('description',$PrintWorkerServiceName,'Internal SOKNA Local durable print worker') | Out-Null
        Invoke-SoknaProcess $sc @('failure',$PrintWorkerServiceName,'reset=','86400','actions=','restart/5000/restart/15000/restart/60000') | Out-Null
        Invoke-SoknaProcess $sc @('failureflag',$PrintWorkerServiceName,'1') | Out-Null

        if (-not [string]::IsNullOrWhiteSpace($ProvisionFile)) {
            Assert-File $ProvisionFile 'Private Print Worker provision'
            # The token is read from the private file and protected with machine-scoped DPAPI.
            # Never place the secret in process arguments, logs, registry or setup summary.
            Invoke-SoknaProcess $serviceExe @('--provision-file',$ProvisionFile) | Out-Null
            Write-SetupEvent 'print-worker-provision' 'Internal Print Worker pairing was provisioned from a private setup file.'
        }
        $healthPath = Join-Path $componentData 'health.json'
        $startupFatalPath = Join-Path $componentData 'logs\startup-fatal.json'
        Remove-Item -LiteralPath $healthPath -Force -ErrorAction SilentlyContinue
        Remove-Item -LiteralPath $startupFatalPath -Force -ErrorAction SilentlyContinue
        Start-Service $PrintWorkerServiceName
        Wait-SoknaService $PrintWorkerServiceName 'Running'
        $healthDeadline = (Get-Date).AddSeconds(20)
        do {
            if (Test-Path -LiteralPath $healthPath -PathType Leaf) { break }
            $serviceState = Get-Service -Name $PrintWorkerServiceName -ErrorAction Stop
            if ($serviceState.Status -eq 'Stopped') { throw 'Internal Print Worker stopped before producing health.json.' }
            Start-Sleep -Milliseconds 500
        } while ((Get-Date) -lt $healthDeadline)
        if (-not (Test-Path -LiteralPath $healthPath -PathType Leaf)) { throw 'Internal Print Worker did not produce a fresh health.json within 20 seconds.' }
        $healthSnapshot = Get-Content -LiteralPath $healthPath -Raw | ConvertFrom-Json
        if (-not $healthSnapshot.updated_at) { throw 'Internal Print Worker health.json is incomplete.' }

        # A legacy standalone service must never race the internal worker for the same Print API queue.
        if ($legacy) { Invoke-SoknaProcess $sc @('config',$LegacyPrintWorkerServiceName,'start=','disabled') | Out-Null }
        Write-SetupEvent 'print-worker' ("Internal Print Worker RUNNING. legacy_data_migrated=$migratedLegacyData")
    } catch {
        $failure = $_
        try {
            $current = Get-Service -Name $PrintWorkerServiceName -ErrorAction SilentlyContinue
            if ($current -and $current.Status -ne 'Stopped') { Stop-Service $PrintWorkerServiceName -ErrorAction SilentlyContinue; try { Wait-SoknaService $PrintWorkerServiceName 'Stopped' } catch { } }
            if (Test-Path -LiteralPath $componentRoot) { Remove-Item -LiteralPath $componentRoot -Recurse -Force -ErrorAction SilentlyContinue }
            if ($hadComponent -and (Test-Path -LiteralPath $backup)) {
                New-Item $componentRoot -ItemType Directory -Force | Out-Null
                Get-ChildItem -LiteralPath $backup -Force | Copy-Item -Destination $componentRoot -Recurse -Force
            }
            if ($createdService) { Invoke-SoknaProcess $sc @('delete',$PrintWorkerServiceName) | Out-Null }
            elseif ($wasRunning -and (Get-Service $PrintWorkerServiceName -ErrorAction SilentlyContinue)) { Start-Service $PrintWorkerServiceName; Wait-SoknaService $PrintWorkerServiceName 'Running' }
            if ([string]::IsNullOrWhiteSpace($previousRegistryDataRoot)) { Remove-Item $PrintWorkerRegistryPath -Recurse -Force -ErrorAction SilentlyContinue }
            else { New-Item $PrintWorkerRegistryPath -Force | Out-Null; New-ItemProperty $PrintWorkerRegistryPath -Name DataRoot -Value $previousRegistryDataRoot -PropertyType String -Force | Out-Null }
            if ($migratedLegacyData -and (Test-Path -LiteralPath $componentData)) {
                Remove-Item -LiteralPath $componentData -Recurse -Force -ErrorAction SilentlyContinue
                if ($componentDataWasEmptyBeforeMigration) {
                    New-Item $componentData -ItemType Directory -Force | Out-Null
                    New-SoknaPrivateDirectory $componentData | Out-Null
                }
            }
            if ($legacy) {
                if ($legacyStartMode -eq 'Disabled') { Invoke-SoknaProcess $sc @('config',$LegacyPrintWorkerServiceName,'start=','disabled') | Out-Null }
                elseif ($legacyStartMode -eq 'Auto') { Invoke-SoknaProcess $sc @('config',$LegacyPrintWorkerServiceName,'start=','auto') | Out-Null }
                elseif ($legacyStartMode -eq 'Manual') { Invoke-SoknaProcess $sc @('config',$LegacyPrintWorkerServiceName,'start=','demand') | Out-Null }
                if ($legacyWasRunning) { Start-Service $LegacyPrintWorkerServiceName; Wait-SoknaService $LegacyPrintWorkerServiceName 'Running' }
            }
            Write-SetupEvent 'print-worker-rollback' 'Previous print ownership/state restored after failed internal component install.'
        } catch { Write-SetupEvent 'print-worker-rollback-failed' $_.Exception.Message }
        throw $failure
    } finally {
        Remove-Item -LiteralPath $backup -Recurse -Force -ErrorAction SilentlyContinue
    }
}

try {
    # Available even when preflight fails before ProgramData is writable.
    $session = New-SoknaPrivateDirectory (Join-Path $env:TEMP ('SOKNA-setup-' + $sessionId))
    $stage = 'preflight'
    Write-SetupEvent $stage 'Preflight started; no application mutation yet.'
    $AppRoot = [IO.Path]::GetFullPath($AppRoot).TrimEnd('\')
    $DataRoot = [IO.Path]::GetFullPath($DataRoot).TrimEnd('\')
    Assert-SoknaSafePath $AppRoot
    Assert-SoknaSafePath $DataRoot
    $isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
    if ($Mode -ne 'Validate' -and -not $isAdmin) { throw 'Administrator privileges are required.' }
    if ($ValidateRepair -and $Mode -ne 'Validate') { throw 'ValidateRepair requires Validate mode.' }
    if ($Mode -eq 'RemovePlatform') {
        $stage = 'remove-platform'
        Remove-RuntimeService
        $summary.ok = $true
        $summary.data_preserved = $true
        $exitCode = 0
    } else {
    if(-not $PrerequisiteManifest){$PrerequisiteManifest=Join-Path $PSScriptRoot 'prerequisites.json'}
    Assert-File $PrerequisiteManifest 'Windows prerequisite manifest'
    $prerequisites=Get-Content -LiteralPath $PrerequisiteManifest -Raw | ConvertFrom-Json
    if([string]$prerequisites.format -ne 'sokna-windows-prerequisites-v1' -or [int]$prerequisites.schema_version -ne 1){throw 'Windows prerequisite manifest is unsupported.'}
    if([bool]$prerequisites.automatic_download_allowed){throw 'Automatic prerequisite download is not allowed until release artifact hashes are frozen.'}
    $summary.preflight.prerequisite_policy=[string]$prerequisites.acquisition_policy
    $summary.preflight.os_windows = ($env:OS -eq 'Windows_NT')
    if (-not $summary.preflight.os_windows) { throw 'SOKNA Windows setup requires Windows.' }
    $summary.preflight.os_64bit = [Environment]::Is64BitOperatingSystem
    if (-not $summary.preflight.os_64bit) { throw 'SOKNA requires 64-bit Windows.' }
    $summary.preflight.os_version = [Environment]::OSVersion.Version.ToString()
    $summary.preflight.pending_reboot = Test-SoknaPendingReboot
    $summary.reboot_required = [bool]$summary.preflight.pending_reboot
    if ($summary.reboot_required) { throw '[REBOOT_REQUIRED] Windows has a pending reboot. Reboot before installing or repairing SOKNA.' }
    $summary.preflight.app_drive_free_bytes = Get-SoknaFreeBytes $AppRoot
    $summary.preflight.data_drive_free_bytes = Get-SoknaFreeBytes $DataRoot
    $summary.preflight.minimum_free_bytes = $MinimumFreeBytes
    if ([int64]$summary.preflight.app_drive_free_bytes -lt $MinimumFreeBytes -or [int64]$summary.preflight.data_drive_free_bytes -lt $MinimumFreeBytes) { throw 'Not enough free disk space for SOKNA setup/rollback.' }
    Assert-File $PhpExe 'PHP executable'
    Assert-File (Join-Path $AppRoot 'runtime\sokna-runtime.php') 'Runtime entrypoint'
    if ($Hostname -notmatch '^(?=.{1,253}$)[a-z0-9]+(?:[.-][a-z0-9]+)*$') { throw 'Invalid local hostname.' }
    if (Test-Path (Join-Path $AppRoot 'VERSION.txt')) { $summary.version = (Get-Content (Join-Path $AppRoot 'VERSION.txt') -Raw).Trim() }
    $summary.preflight.is_admin = [bool]$isAdmin
    if (-not $SkipHttps) {
        Assert-File $OpenSslExe 'OpenSSL executable'
        Invoke-SoknaProcess $OpenSslExe @('version') | Out-Null
        & (Join-Path $PSScriptRoot 'provision-local-https.ps1') -OpenSslExe $OpenSslExe -DataRoot $DataRoot -Hostname $Hostname -ValidateOnly | Out-Null
    }
    if (-not $ServiceHostExe) { $ServiceHostExe = Join-Path $PSScriptRoot 'bin\SoknaRuntimeService.exe' }
    Assert-File $ServiceHostExe 'Prebuilt Runtime Service Host (build it in CI)'
    $phpCheck = 'echo json_encode(["version"=>PHP_VERSION,"version_id"=>PHP_VERSION_ID,"sapi"=>PHP_SAPI,"arch"=>PHP_INT_SIZE===8?"x64":"x86","extensions"=>array_values(get_loaded_extensions())]);'
    $phpResult = Invoke-SoknaProcess $PhpExe @('-r',$phpCheck)
    $summary.preflight.php = $phpResult.Output | ConvertFrom-Json
    if ([int]$summary.preflight.php.version_id -lt [int]$prerequisites.php.minimum_version_id) { throw 'Installed PHP is older than the SOKNA prerequisite contract.' }
    if ([string]$summary.preflight.php.arch -ne [string]$prerequisites.php.architecture) { throw 'Installed PHP architecture does not match the SOKNA prerequisite contract.' }
    $loadedExtensions=@($summary.preflight.php.extensions | ForEach-Object { ([string]$_).ToLowerInvariant() })
    foreach($extension in @($prerequisites.php.required_extensions)){if($loadedExtensions -notcontains ([string]$extension).ToLowerInvariant()){throw "Missing PHP extension required by SOKNA: $extension"}}
    $webServer = Resolve-SoknaWebServerExecutable $WebServerExe @($prerequisites.web_server.executable_names)
    $summary.preflight.web_server_exe = $(if ($webServer) { $webServer } else { 'not-detected' })
    if ((-not $SkipHttps -or $RequireWebServerPreflight) -and -not $webServer) { throw 'Web server executable was not detected. Install/configure the supported Apache runtime before SOKNA setup.' }
    if ($webServer) {
        try { Invoke-SoknaProcess $webServer @('-v') | Out-Null } catch { throw 'Web server executable failed its version probe.' }
        if (-not $SkipHttps) {
            $apacheOwner = Join-Path $PSScriptRoot 'configure-apache.ps1'
            Assert-File $apacheOwner 'SOKNA Apache integration owner'
            $apachePreflight = (& $apacheOwner -WebServerExe $webServer -AppRoot $AppRoot -DataRoot $DataRoot -Hostname $Hostname -HttpsPort $HttpsPort -ValidateOnly | Out-String).Trim() | ConvertFrom-Json
            $summary.preflight.apache = $apachePreflight
        }
    }
    $listeners = @(Get-SoknaTcpListenerOwners $HttpsPort)
    $summary.preflight.https_port = $HttpsPort
    $summary.preflight.https_port_listener_count = $listeners.Count
    if ($listeners.Count -gt 0 -and $webServer) {
        $expectedWebServer = [IO.Path]::GetFullPath($webServer)
        $foreign = @($listeners | Where-Object { -not $_.path -or [IO.Path]::GetFullPath($_.path) -ne $expectedWebServer })
        if ($foreign.Count -gt 0) { throw "HTTPS port $HttpsPort is already owned by another process." }
    } elseif ($listeners.Count -gt 0 -and $RequireWebServerPreflight) {
        throw "HTTPS port $HttpsPort is already in use and its web-server owner could not be verified."
    }
    # Validate touches only this private session, never the target data root or SCM.
    Invoke-SoknaProcess $ServiceHostExe @('--self-test','--php',$PhpExe,'--app-root',$AppRoot,'--data-root',(Join-Path $session 'host-validation')) | Out-Null
    $PrintWorkerBundle = Resolve-PrintWorkerBundle $PrintWorkerBundle
    $existing = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
    if ($Mode -in @('New','Recover') -and $existing) { throw 'Runtime service already exists; use Repair with the installed paths.' }
    if ($Mode -eq 'Repair' -or $ValidateRepair) {
        Assert-File (Join-Path $AppRoot 'config.php') 'Installed configuration'
        Assert-File (Join-Path $AppRoot 'install.lock') 'Installation lock'
        if ($existing) {
            $imagePath = (Get-ItemProperty "HKLM:\SYSTEM\CurrentControlSet\Services\$ServiceName").ImagePath
            $expected = Get-RuntimeBinPath (Join-Path $DataRoot 'bin\SoknaRuntimeService.exe')
            if ($imagePath -ne $expected) { throw 'Service ownership/path mismatch; repair with the original installation paths. Existing service was not changed.' }
        }
    }
    if ($Mode -in @('New','Recover')) {
        $SetupConfigFile = Assert-SoknaPrivateInputFile $SetupConfigFile 'Setup config'
        if ((Get-Item -LiteralPath $SetupConfigFile).Length -gt 1MB) { throw 'Setup config is too large.' }
        try { $inputConfig = Get-Content -LiteralPath $SetupConfigFile -Raw | ConvertFrom-Json }
        catch { throw 'Setup config JSON is invalid; fix the input file before installation.' }
        # Register secrets before invoking any child process that can echo an error.
        foreach ($value in @($inputConfig.db.pass,$inputConfig.admin_password,$inputConfig.relay.shared_secret)) { if ($value) { Add-SoknaSecret ([string]$value) } }
        if ($inputConfig.data_dir -and [IO.Path]::GetFullPath($inputConfig.data_dir).TrimEnd('\') -ne $DataRoot) { throw 'Config data_dir does not match DataRoot.' }
        $inputConfig | Add-Member -Force NoteProperty data_dir $DataRoot
        $inputConfig | Add-Member -Force NoteProperty local_hostname $Hostname
        $printWorkerProvisionFile = Join-Path $session 'print-worker-provision.private'
        $inputConfig | Add-Member -Force NoteProperty print_worker_provision_file $printWorkerProvisionFile
        $privateConfig = Join-Path $session 'setup-config.private'
        [IO.File]::WriteAllText($privateConfig, ($inputConfig | ConvertTo-Json -Depth 20), (New-Object Text.UTF8Encoding($false)))
        if ($Mode -eq 'Recover') {
            Assert-File $RecoveryFile 'Recovery Set'
            if ($RecoveryPassphraseFile) {
                $RecoveryPassphraseFile = Assert-SoknaPrivateInputFile $RecoveryPassphraseFile 'Recovery passphrase'
                if ((Get-Item -LiteralPath $RecoveryPassphraseFile).Length -gt 8192) { throw 'Recovery passphrase is too large.' }
                Add-SoknaSecret ([IO.File]::ReadAllText($RecoveryPassphraseFile).TrimEnd("`r","`n"))
                $privatePassphrase = Copy-PrivateInput $RecoveryPassphraseFile 'recovery-passphrase'
            }
        }
        Invoke-SoknaProcess $PhpExe @((Join-Path $AppRoot 'tools\setup-machine.php'),"--mode=$($Mode.ToLower())","--config-file=$privateConfig",'--validate-only') | Out-Null
    }
    Write-SetupEvent $stage 'Prerequisite and target checks passed.'
    if ($Mode -eq 'Validate' -or $PreflightOnly) {
        $summary.ok = $true
        $stage = 'preflight-complete'
        $summary.stage = $stage
        $exitCode = 0
        Write-SetupEvent 'preflight-complete' 'All preflight checks passed; mutation was intentionally skipped.'
    }
    else {
        $env:SOKNA_DATA_DIR = $DataRoot
        if ($Mode -in @('New','Recover')) {
            $stage = 'application-setup'
            $arguments = @((Join-Path $AppRoot 'tools\setup-machine.php'),"--mode=$($Mode.ToLower())","--config-file=$privateConfig")
            if ($Mode -eq 'Recover') {
                $arguments += "--recovery-file=$RecoveryFile"
                if ($privatePassphrase) { $arguments += "--passphrase-file=$privatePassphrase" }
            }
            Invoke-SoknaProcess -File $PhpExe -Arguments $arguments -TimeoutSeconds 1800 | Out-Null
            $summary.business_setup_committed = $true
            Write-SetupEvent $stage 'Application owner completed; later failures require Repair, not re-running New/Recover.'
        }
        $stage = 'https'
        if (-not $SkipHttps) { & (Join-Path $PSScriptRoot 'provision-local-https.ps1') -OpenSslExe $OpenSslExe -DataRoot $DataRoot -Hostname $Hostname | Out-Null }
        $stage = 'web-server-config'
        if (-not $SkipHttps -and $webServer) {
            $apacheOwner = Join-Path $PSScriptRoot 'configure-apache.ps1'
            $apacheResult = (& $apacheOwner -WebServerExe $webServer -AppRoot $AppRoot -DataRoot $DataRoot -Hostname $Hostname -HttpsPort $HttpsPort | Out-String).Trim() | ConvertFrom-Json
            $summary.web_server = $apacheResult
            $summary.web_server_reload_required = [bool]$apacheResult.reload_required
            Write-SetupEvent $stage 'SOKNA Apache include validated and installed; Apache lifecycle remains external and reload is not performed by SOKNA.'
        }
        $stage = 'runtime-service'
        if (-not $SkipService) { Install-RuntimeService $ServiceHostExe }
        $stage = 'print-worker'
        Install-PrintWorkerComponent $PrintWorkerBundle $printWorkerProvisionFile
        $stage = 'runtime-self-check'
        Invoke-SoknaProcess $PhpExe @((Join-Path $AppRoot 'runtime\sokna-runtime.php'),'--self-check') | Out-Null
        $summary.runtime_service = $(if ($SkipService) { 'skipped' } else { 'running' })
        $summary.print_worker = 'running'
        $summary.https = $(if ($SkipHttps) { 'skipped' } else { 'provisioned' })
        if (-not $SkipHttps -and [bool]$summary.web_server_reload_required) {
            $stage = 'web-server-reload-required'
            $summary.ok = $false
            $summary.error_code = 'SOKNA_SETUP_WEB_RELOAD_REQUIRED'
            $summary.message_fa = 'پیکربندی Apache آماده است، اما برای فعال‌شدن باید وب‌سرور توسط مالک سرویس Reload/Restart شود.'
            $summary.next_action = 'Reload or restart the externally managed Apache service, then run Repair again to complete the HTTPS health gate.'
            $summary.health_scope = 'runtime-self-check passed; local HTTPS health is pending external Apache reload'
            $exitCode = 20
            Write-SetupEvent $stage 'Apache configuration changed; setup remains incomplete until the external owner reloads Apache and Repair proves local HTTPS health.'
        } else {
            if (-not $SkipHttps) {
                $stage = 'web-health'
                try {
                    $summary.web_health = Test-SoknaLocalWebHealth $Hostname $HttpsPort
                } catch {
                    $summary.ok = $false
                    $summary.error_code = 'SOKNA_SETUP_WEB_HEALTH'
                    $summary.message_fa = 'سلامت HTTPS محلی تأیید نشد؛ Apache و تنظیمات SOKNA را بررسی کنید.'
                    $summary.next_action = 'Verify/reload the externally managed Apache service, then run Repair again. Do not rerun New/Recover on committed business data.'
                    $summary.error = Protect-SoknaLog ([string]$_.Exception.Message)
                    $summary.health_scope = 'runtime-self-check passed; local HTTPS health failed'
                    $exitCode = 21
                    Write-SetupEvent $stage $summary.error
                }
            }
            if ($exitCode -notin @(20,21)) {
                $summary.ok = $true
                $summary.health_scope = $(if ($SkipHttps) { 'runtime-self-check; HTTPS intentionally skipped by technical owner' } else { 'runtime-self-check + local HTTPS login surface' })
                $exitCode = $(if ($summary.reboot_required) { 3010 } else { 0 })
            }
        }
    }
    }
} catch {
    $rawError = [string]$_.Exception.Message
    if ($rawError.StartsWith('[REBOOT_REQUIRED]')) {
        $exitCode = 3010
        $summary.error_code = 'SOKNA_SETUP_REBOOT_REQUIRED'
        $summary.message_fa = 'ویندوز نیاز به راه‌اندازی مجدد دارد. پیش از نصب یا تعمیر سکنا، رایانه را راه‌اندازی مجدد کنید.'
        $summary.next_action = 'Reboot Windows, then run the same setup action again.'
    } else {
        $summary.error_code = 'SOKNA_SETUP_' + $stage.Replace('-','_').ToUpperInvariant()
        $summary.message_fa = 'نصب یا تعمیر کامل نشد. مرحله خطا و گزارش تشخیص را بررسی کنید.'
        $summary.next_action = $(if ($summary.business_setup_committed) { 'Business setup completed: preserve data and use Repair after fixing the reported prerequisite.' } else { 'Fix the reported error. If application setup had started, inspect target state before retrying.' })
    }
    $summary.error = Protect-SoknaLog $rawError
    Write-SetupEvent $stage $summary.error
} finally {
    $env:SOKNA_DATA_DIR = $oldDataDir
    foreach ($file in @($privateConfig,$privatePassphrase,$printWorkerProvisionFile)) { if ($file) { Remove-Item -LiteralPath $file -Force -ErrorAction SilentlyContinue } }
    $summary.stage = $stage
    $summary.exit_code = $exitCode
    $summary.finished_utc = [DateTime]::UtcNow.ToString('o')
    $summary.os_version = [Environment]::OSVersion.VersionString
    $summary.os_64bit = [Environment]::Is64BitOperatingSystem
    try {
        $serviceSnapshot = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
        $summary.service_status_at_exit = $(if ($serviceSnapshot) { [string]$serviceSnapshot.Status } else { 'not_installed' })
        $hostLog = Join-Path $DataRoot 'logs\runtime-service-host.log'
        Assert-SoknaSafePath $hostLog
        if (Test-Path -LiteralPath $hostLog -PathType Leaf) {
            $summary.service_host_recent_events = @(Get-Content -LiteralPath $hostLog -Tail 50 | ForEach-Object { Protect-SoknaLog ([string]$_) })
        }
    } catch { $summary.service_diagnostics = 'unavailable; setup error remains authoritative' }
    if ($session) {
        # Explicit allowlist: never recursively archive setup inputs, keys, durable queue state or backups.
        $summary.diagnostics_directory = $session
        $summaryPath = Join-Path $session 'summary.json'
        $eventsPath = Join-Path $session 'events.jsonl'
        $componentsPath = Join-Path $session 'components.json'
        $summary | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $summaryPath -Encoding UTF8
        $installedRuntimeHost = Join-Path $DataRoot 'bin\SoknaRuntimeService.exe'
        if (-not (Test-Path -LiteralPath $installedRuntimeHost -PathType Leaf)) { $installedRuntimeHost = $ServiceHostExe }
        $installedPrintHost = Join-Path $DataRoot 'bin\print-worker\Service\Sokna.PrintAgent.Service.exe'
        try {
            Write-SoknaSupportSnapshot -Session $session -AppRoot $AppRoot -DataRoot $DataRoot -RuntimeServiceName $ServiceName -PrintServiceName $PrintWorkerServiceName -ServiceHostPath $installedRuntimeHost -PrintServicePath $installedPrintHost
        } catch {
            [ordered]@{ schema_version=1; captured_utc=[DateTime]::UtcNow.ToString('o'); diagnostic_error=(Protect-SoknaLog $_.Exception.Message) } |
                ConvertTo-Json -Depth 4 | Set-Content -LiteralPath $componentsPath -Encoding UTF8
        }
        $supportFiles = @($summaryPath,$eventsPath,$componentsPath)
        foreach ($external in @(
            @{ env='SOKNA_BURN_LOG_PATH'; name='burn.log' },
            @{ env='SOKNA_MSI_LOG_PATH'; name='msi.log' }
        )) {
            $source = [Environment]::GetEnvironmentVariable([string]$external.env)
            if ([string]::IsNullOrWhiteSpace($source)) { continue }
            $destination = Join-Path $session ([string]$external.name)
            try {
                if (Copy-SoknaSanitizedDiagnosticLog -Source $source -Destination $destination) { $supportFiles += $destination }
            } catch {
                Write-SetupEvent 'diagnostics-log-skip' ("Optional $($external.name) was not included: " + $_.Exception.Message)
            }
        }
        if ($InstallerLogFile) {
            $summary.installer_log_scope = 'Snapshot through setup-owner completion; later installer finalization is not included.'
            try {
                Assert-SoknaSafePath $InstallerLogFile
                $stream = [IO.File]::Open($InstallerLogFile, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::ReadWrite)
                try {
                    if ($stream.Length -gt 8MB) { throw 'Installer log exceeds the 8 MiB diagnostic limit.' }
                    $reader = New-Object IO.StreamReader($stream, [Text.Encoding]::UTF8, $true)
                    try { $logText = $reader.ReadToEnd() } finally { $reader.Dispose() }
                } finally { $stream.Dispose() }
                $snapshot = Join-Path $session 'installer-snapshot.log'
                [IO.File]::WriteAllText($snapshot, (Protect-SoknaLog $logText), (New-Object Text.UTF8Encoding($true)))
                $supportFiles += $snapshot
                $summary.installer_log_status = 'included'
            } catch {
                $summary.installer_log_status = 'unavailable'
                $summary.installer_log_warning = Protect-SoknaLog $_.Exception.Message
            }
        }
        $summary.support_bundle = Join-Path $session 'support.zip'
        $summary.support_bundle_status = 'created'
        $summary | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath (Join-Path $session 'summary.json') -Encoding UTF8
        try {
            Compress-Archive -LiteralPath $supportFiles -DestinationPath (Join-Path $session 'support.zip') -Force
        } catch {
            $summary.support_bundle_status = 'unavailable'
            $summary | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath (Join-Path $session 'summary.json') -Encoding UTF8
            [Console]::Error.WriteLine('Support ZIP could not be created; summary.json and events.jsonl remain available.')
        }
    }
    $summary | ConvertTo-Json -Depth 8
}
exit $exitCode
