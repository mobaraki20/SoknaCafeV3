param(
    [string]$InstallRoot = (Join-Path $env:ProgramFiles 'SOKNA\MaintenanceV4-UAT'),
    [string]$OutputRoot = (Join-Path $env:ProgramData 'SOKNA\Maintenance-UAT-Evidence')
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$MaintenanceRoot = Join-Path $env:ProgramData 'SOKNA\Maintenance'
$DescriptorPath = Join-Path $MaintenanceRoot 'control-plane.json'
$AclEvidencePath = Join-Path $MaintenanceRoot 'web-adapter-acl.json'
$WebAdapterKeyPath = Join-Path $MaintenanceRoot 'secrets\web-adapter.key'
$RecoveryKeyPath = Join-Path $MaintenanceRoot 'secrets\recovery-console.key'
$timestamp = [DateTimeOffset]::UtcNow.ToString('yyyyMMddTHHmmssZ')
$CaptureDir = Join-Path $OutputRoot ("capture-$timestamp")
$ZipPath = "$CaptureDir.zip"

function Assert-Administrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = [Security.Principal.WindowsPrincipal]::new($identity)
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'Evidence capture must run from an elevated Administrator PowerShell.'
    }
}

function Get-ServiceSafe([string]$Name) {
    $svc = Get-CimInstance Win32_Service -Filter ("Name='{0}'" -f $Name.Replace("'", "''")) -ErrorAction SilentlyContinue
    if ($null -eq $svc) {
        return [ordered]@{ name=$Name; present=$false }
    }
    return [ordered]@{
        name = [string]$svc.Name
        present = $true
        state = [string]$svc.State
        start_mode = [string]$svc.StartMode
        start_name = [string]$svc.StartName
        path_name = [string]$svc.PathName
        process_id = [int]$svc.ProcessId
    }
}

function Get-IcaclsText([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path)) { return @('MISSING') }
    $text = & icacls.exe $Path 2>&1
    return @($text | ForEach-Object { [string]$_ })
}

function Test-UnprivilegedKeyDenial([string]$KeyPath) {
    if (-not (Test-Path -LiteralPath $KeyPath -PathType Leaf)) {
        return [ordered]@{ status='INCONCLUSIVE'; reason='web-adapter.key is missing'; temp_user_removed=$true }
    }

    $suffix = [Guid]::NewGuid().ToString('N').Substring(0, 10)
    $userName = "SoknaUatProbe_$suffix"
    $password = ([Convert]::ToBase64String([Security.Cryptography.RandomNumberGenerator]::GetBytes(24)) + 'Aa1!')
    $computer = [ADSI]("WinNT://{0},computer" -f $env:COMPUTERNAME)
    $created = $false
    try {
        $user = $computer.Create('user', $userName)
        $user.SetPassword($password)
        $user.SetInfo()
        $created = $true

        $secure = ConvertTo-SecureString $password -AsPlainText -Force
        $credential = [Management.Automation.PSCredential]::new(("{0}\{1}" -f $env:COMPUTERNAME, $userName), $secure)
        $escaped = $KeyPath.Replace("'", "''")
        $probe = "try { [IO.File]::ReadAllBytes('$escaped') | Out-Null; exit 10 } catch { exit 0 }"
        $powershellExe = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
        try {
            $process = Start-Process -FilePath $powershellExe -ArgumentList @('-NoProfile','-NonInteractive','-Command',$probe) -Credential $credential -PassThru -Wait -WindowStyle Hidden
        } catch {
            return [ordered]@{ status='INCONCLUSIVE'; reason=('Unable to launch unrelated unprivileged probe token: ' + $_.Exception.Message); temp_user_removed=$false }
        }

        if ($process.ExitCode -eq 0) {
            return [ordered]@{ status='PASS'; reason='Unrelated temporary standard-user process was denied key read.'; exit_code=$process.ExitCode; temp_user_removed=$false }
        }
        if ($process.ExitCode -eq 10) {
            return [ordered]@{ status='FAIL'; reason='Unrelated temporary standard-user process could read web-adapter.key.'; exit_code=$process.ExitCode; temp_user_removed=$false }
        }
        return [ordered]@{ status='INCONCLUSIVE'; reason='Probe process returned an unexpected exit code.'; exit_code=$process.ExitCode; temp_user_removed=$false }
    }
    finally {
        if ($created) {
            try { $computer.Delete('user', $userName) } catch { }
        }
        $password = $null
    }
}

Assert-Administrator
New-Item -ItemType Directory -Force -Path $CaptureDir | Out-Null

$services = @(
    Get-ServiceSafe 'SoknaMaintenanceSupervisor'
    Get-ServiceSafe 'SoknaApache'
    Get-ServiceSafe 'SoknaRuntime'
    Get-ServiceSafe 'SoknaPrintWorker'
)
$services | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath (Join-Path $CaptureDir 'SERVICES.json') -Encoding utf8

$descriptor = $null
if (Test-Path -LiteralPath $DescriptorPath) {
    try { $descriptor = Get-Content -Raw -LiteralPath $DescriptorPath | ConvertFrom-Json } catch { $descriptor = [ordered]@{ parse_error=$_.Exception.Message } }
}
$descriptor | ConvertTo-Json -Depth 10 | Set-Content -LiteralPath (Join-Path $CaptureDir 'CONTROL_PLANE.json') -Encoding utf8

$aclEvidence = $null
if (Test-Path -LiteralPath $AclEvidencePath) {
    try { $aclEvidence = Get-Content -Raw -LiteralPath $AclEvidencePath | ConvertFrom-Json } catch { $aclEvidence = [ordered]@{ parse_error=$_.Exception.Message } }
}
$aclEvidence | ConvertTo-Json -Depth 10 | Set-Content -LiteralPath (Join-Path $CaptureDir 'WEB_ADAPTER_ACL_EVIDENCE.json') -Encoding utf8

$aclSnapshot = [ordered]@{
    maintenance_root = Get-IcaclsText $MaintenanceRoot
    secrets_root = Get-IcaclsText (Join-Path $MaintenanceRoot 'secrets')
    web_adapter_key = Get-IcaclsText $WebAdapterKeyPath
    recovery_console_key = Get-IcaclsText $RecoveryKeyPath
    control_plane = Get-IcaclsText $DescriptorPath
}
$aclSnapshot | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath (Join-Path $CaptureDir 'ACL_SNAPSHOT.json') -Encoding utf8

$binaryPaths = @(
    (Join-Path $InstallRoot 'app\SoknaMaintenanceSupervisor.exe'),
    (Join-Path $InstallRoot 'app\SoknaMaintenanceUpdateEngine.exe'),
    (Join-Path $InstallRoot 'recovery\SoknaMaintenanceRecoveryConsole.exe')
)
$binaries = foreach ($path in $binaryPaths) {
    if (Test-Path -LiteralPath $path -PathType Leaf) {
        [ordered]@{ path=$path; exists=$true; sha256=(Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant(); size=(Get-Item -LiteralPath $path).Length }
    } else {
        [ordered]@{ path=$path; exists=$false }
    }
}
$binaries | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath (Join-Path $CaptureDir 'INSTALLED_BINARIES.json') -Encoding utf8

$listener = $null
if ($null -ne $descriptor -and $null -ne $descriptor.base_url) {
    try {
        $uri = [Uri]([string]$descriptor.base_url)
        $connections = @(Get-NetTCPConnection -State Listen -LocalPort $uri.Port -ErrorAction SilentlyContinue | Where-Object { $_.LocalAddress -in @('127.0.0.1','::1') })
        $listener = [ordered]@{
            base_url = [string]$descriptor.base_url
            discovered_port = $uri.Port
            listener_count = $connections.Count
            loopback_listener_present = ($connections.Count -gt 0)
            owning_process_ids = @($connections | Select-Object -ExpandProperty OwningProcess -Unique)
        }
    } catch {
        $listener = [ordered]@{ parse_error=$_.Exception.Message }
    }
}
$listener | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath (Join-Path $CaptureDir 'LISTENER.json') -Encoding utf8

$probe = Test-UnprivilegedKeyDenial $WebAdapterKeyPath
$probe.temp_user_removed = $true
$probe | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath (Join-Path $CaptureDir 'UNPRIVILEGED_KEY_DENIAL.json') -Encoding utf8

$summary = [ordered]@{
    format = 'sokna-maintenance-v4-realhost-uat-capture-v1'
    captured_at_utc = [DateTimeOffset]::UtcNow.ToString('O')
    computer_name = $env:COMPUTERNAME
    product_source_commit_expected = '61f881a0d68ab978de2e3435d37d759c4f07b4b4'
    service_supervisor_present = [bool](($services | Where-Object { $_.name -eq 'SoknaMaintenanceSupervisor' }).present)
    service_apache_present = [bool](($services | Where-Object { $_.name -eq 'SoknaApache' }).present)
    descriptor_present = (Test-Path -LiteralPath $DescriptorPath)
    acl_evidence_present = (Test-Path -LiteralPath $AclEvidencePath)
    web_adapter_key_present = (Test-Path -LiteralPath $WebAdapterKeyPath)
    recovery_console_key_present = (Test-Path -LiteralPath $RecoveryKeyPath)
    unprivileged_key_denial = $probe.status
    secrets_exported = $false
    secret_values_read_by_capture = $false
    local_public_source_mutated = $false
    evidence_boundary = 'No key value/content is copied, hashed, printed, or written to evidence.'
}
$summary | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath (Join-Path $CaptureDir 'SUMMARY.json') -Encoding utf8

$hashLines = Get-ChildItem -LiteralPath $CaptureDir -File | Sort-Object Name | ForEach-Object {
    $hash = (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
    "$hash  $($_.Name)"
}
$hashLines | Set-Content -LiteralPath (Join-Path $CaptureDir 'SHA256SUMS.txt') -Encoding ascii

if (Test-Path -LiteralPath $ZipPath) { Remove-Item -LiteralPath $ZipPath -Force }
Compress-Archive -LiteralPath (Join-Path $CaptureDir '*') -DestinationPath $ZipPath -CompressionLevel Optimal

[ordered]@{
    result = if ($probe.status -eq 'FAIL') { 'FAIL' } else { 'CAPTURED' }
    capture_directory = $CaptureDir
    evidence_zip = $ZipPath
    unprivileged_key_denial = $probe.status
    secret_values_exported = $false
    local_public_source_mutated = $false
} | ConvertTo-Json -Depth 6
