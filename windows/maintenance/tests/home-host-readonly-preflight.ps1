# SOKNA Home / Cafe independent read-only host evidence capture.
# No Service Control Manager mutation, no updater API, no installers, no writes.
# Run with Windows PowerShell 5.1 or PowerShell 7: outputs one JSON document.
[CmdletBinding()]
param(
    [string]$PackageRoot = '',
    [string]$LocalUrl = 'http://127.0.0.1:18080/login.php'
)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$uri = [Uri]$LocalUrl
if ($uri.Scheme -ne 'http' -or
    $uri.Host -notin @('127.0.0.1','localhost') -or
    $uri.Port -ne 18080 -or
    $uri.AbsolutePath -ne '/login.php' -or
    -not [string]::IsNullOrEmpty($uri.Query) -or
    -not [string]::IsNullOrEmpty($uri.Fragment) -or
    -not [string]::IsNullOrEmpty($uri.UserInfo)) {
    throw 'LOCAL_HTTP_URL_NOT_ALLOWLISTED: only http://127.0.0.1:18080/login.php is permitted.'
}

if (-not $PackageRoot) {
    foreach ($candidate in @('D:\SOKNA', 'C:\SOKNA')) {
        if (Test-Path -LiteralPath $candidate -PathType Container) {
            $PackageRoot = $candidate
            break
        }
    }
}
$issues = [System.Collections.Generic.List[string]]::new()
$rootVerified = $false
$root = $null
if ($PackageRoot) {
    try {
        $root = [IO.Path]::GetFullPath($PackageRoot)
        $rootVerified = Test-Path -LiteralPath $root -PathType Container
    }
    catch { $rootVerified = $false }
}
if (-not $rootVerified) { $issues.Add('LOCAL_PACKAGE_ROOT_UNVERIFIED') }

$services = [ordered]@{}
foreach ($name in @('SoknaApache','SoknaMaintenanceSupervisor','SoknaRuntime','SoknaPrintWorker')) {
    try {
        $service = Get-CimInstance -ClassName Win32_Service -Filter "Name='$name'" -ErrorAction Stop
        if ($null -eq $service) {
            $services[$name] = [ordered]@{ installed=$false; state='absent'; start_mode=$null }
            $issues.Add("SERVICE_NOT_INSTALLED_$name")
        } else {
            $services[$name] = [ordered]@{
                installed=$true
                state=[string]$service.State
                start_mode=[string]$service.StartMode
            }
            if ($name -eq 'SoknaApache' -and $service.State -ne 'Running') {
                $issues.Add('APACHE_SERVICE_NOT_RUNNING')
            }
        }
    }
    catch {
        $services[$name] = [ordered]@{installed=$null;state='evidence_unavailable';start_mode=$null}
        $issues.Add("SERVICE_EVIDENCE_UNAVAILABLE_$name")
    }
}

$web = [ordered]@{
    present=$false
    is_reparse_point=$null
    active_release_name=$null
    version=$null
    version_file_sha256=$null
    managed_inventory_present=$false
    managed_inventory_sha256=$null
    managed_inventory_entries=$null
    managed_tree_sha256_verified=$false
}
if ($rootVerified) {
    $webPath = Join-Path $root 'Web'
    try {
        if (Test-Path -LiteralPath $webPath -PathType Container) {
            $web.present = $true
            $item = Get-Item -LiteralPath $webPath -Force -ErrorAction Stop
            $web.is_reparse_point = [bool](($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0)
            if ($web.is_reparse_point) {
                # This is advisory only: read link display name, do not claim
                # authoritative physical target attestation on old PowerShell.
                try {
                    $target = @($item.Target)[0]
                    if ($target) { $web.active_release_name = [IO.Path]::GetFileName(([string]$target).TrimEnd('\')) }
                } catch {}
            } else {
                $issues.Add('WEB_STABLE_PATH_IS_NOT_JUNCTION')
            }
            $versionFile = Join-Path $webPath 'VERSION.txt'
            if (Test-Path -LiteralPath $versionFile -PathType Leaf) {
                $web.version = (Get-Content -LiteralPath $versionFile -Raw -ErrorAction Stop).Trim()
                $web.version_file_sha256 = (Get-FileHash -LiteralPath $versionFile -Algorithm SHA256).Hash.ToLowerInvariant()
            } else { $issues.Add('ACTIVE_VERSION_FILE_MISSING') }
            $inventory = Join-Path $webPath 'resources\local-managed-files-v1.json'
            if (Test-Path -LiteralPath $inventory -PathType Leaf) {
                $obj = Get-Content -LiteralPath $inventory -Raw -ErrorAction Stop | ConvertFrom-Json
                if ($obj.format -eq 'sokna-local-managed-files-v1' -and $null -ne $obj.files) {
                    $web.managed_inventory_present = $true
                    $web.managed_inventory_entries = @($obj.files).Count
                    $web.managed_inventory_sha256 = (Get-FileHash -LiteralPath $inventory -Algorithm SHA256).Hash.ToLowerInvariant()
                } else {
                    $issues.Add('ACTIVE_MANAGED_INVENTORY_FORMAT_INVALID')
                }
            } else { $issues.Add('ACTIVE_MANAGED_INVENTORY_MISSING') }
        } else {
            $issues.Add('ACTIVE_WEB_MISSING')
        }
    }
    catch {
        $issues.Add('ACTIVE_WEB_EVIDENCE_UNAVAILABLE')
    }
}

$listener = [ordered]@{ port=18080; listening=$null; status='not_checked' }
try {
    $connections = @(Get-NetTCPConnection -LocalPort 18080 -State Listen -ErrorAction Stop)
    $listener.listening = ($connections.Count -gt 0)
    $listener.status = 'observed'
    if (-not $listener.listening) { $issues.Add('LOCAL_HTTP_PORT_NOT_LISTENING') }
}
catch {
    $listener.status='evidence_unavailable'
    $issues.Add('LISTENING_PORT_EVIDENCE_UNAVAILABLE')
}

$http = [ordered]@{ attempted=$true; response_received=$false; status_code=$null; x_sokna_build=$null; x_sokna_runtime=$null; error_type=$null }
try {
    $response = Invoke-WebRequest -Uri $uri.AbsoluteUri -UseBasicParsing -TimeoutSec 5 -ErrorAction Stop
    $http.response_received = $true
    $http.status_code = [int]$response.StatusCode
    $http.x_sokna_build = [string]$response.Headers['X-Sokna-Build']
    $http.x_sokna_runtime = [string]$response.Headers['X-Sokna-Runtime']
    if ($http.status_code -ne 200) { $issues.Add('LOCAL_HTTP_STATUS_NOT_OK') }
    if ($web.version -and $http.x_sokna_build -and $http.x_sokna_build -ne $web.version) {
        $issues.Add('LOCAL_HTTP_BUILD_MISMATCH')
    }
}
catch {
    $http.error_type = $_.Exception.GetType().Name
    $issues.Add('LOCAL_HTTP_HEALTH_UNPROVEN')
}

# No script may infer current installation from a historical support bundle.
$document = [ordered]@{
    format='sokna-home-host-readonly-evidence-v1'
    schema_version=1
    captured_at_utc=[DateTime]::UtcNow.ToString('o')
    local_machine_checked=$true
    no_mutation_performed=$true
    no_installer_or_updater_called=$true
    package_root_present=$rootVerified
    services=$services
    web=$web
    local_port=$listener
    local_http=$http
    evidence_blockers=@($issues | Sort-Object -Unique)
    new_supervisor_api_invoked=$false
    exact_current_maintenance_version_qualified=$false
    active_managed_tree_qualified=$false
    lifecycle_and_recovery_qualified=$false
    local_and_remote_backup_qualified=$false
    mariadb_schema_qualified=$false
    public_paired_compatibility_qualified=$false
    real_host_uat_complete=$false
    upgrade_permitted=$false
    activation_allowed=$false
    panel_update_ready=$false
}
$document | ConvertTo-Json -Depth 12
