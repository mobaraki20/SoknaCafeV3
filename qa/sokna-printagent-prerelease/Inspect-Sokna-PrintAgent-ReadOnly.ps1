param(
  [string]$OutputDir = [Environment]::GetFolderPath('Desktop')
)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
# NO service stop/start, no install, no secrets/data file reads.
if ([string]::IsNullOrWhiteSpace($OutputDir)) { $OutputDir = $PWD.Path }
$stamp = [DateTime]::UtcNow.ToString('yyyyMMdd-HHmmss')
$output = Join-Path $OutputDir ("SOKNA-PrintAgent-ReadOnly-$stamp.json")
$result = [ordered]@{
  format = 'sokna-print-agent-readonly-upgrade-identity-v1'
  examined_at_utc = [DateTime]::UtcNow.ToString('o')
  machine_scope = 'must_be_run_on_target_cafe_pc'
  installation_permitted = $false
  code = 'CANNOT_APPROVE_WITHOUT_FULL_BASELINE_ATTESTATION'
  service_name = $null
  installed_version = $null
  image_path = $null
  service_exe_sha256 = $null
  worker_exe_sha256 = $null
  manifest_state = 'not_checked'
  manifest_version = $null
  manifest_valid = $false
  programdata_preserved_unmodified = $true
  programdata_present = (Test-Path (Join-Path $env:ProgramData 'Sokna\PrintAgent'))
  warnings = @()
}
$svc = Get-CimInstance Win32_Service -ErrorAction SilentlyContinue |
  Where-Object { $_.Name -in @('SoknaPrintWorker','SoknaPrintAgent6') } |
  Sort-Object { if ($_.Name -eq 'SoknaPrintWorker') {0} else {1} } |
  Select-Object -First 1
if ($null -eq $svc) {
  $result.code = 'PRINT_SERVICE_NOT_FOUND'
} else {
  $result.service_name = [string]$svc.Name
  $raw = [Environment]::ExpandEnvironmentVariables(([string]$svc.PathName).Trim())
  $exe = $null
  if ($raw -match '^"([^"]+\.exe)"') { $exe = $Matches[1] }
  elseif ($raw -match '^([^\s"]+\.exe)(?:\s|$)') { $exe = $Matches[1] }
  if (!$exe -or !(Test-Path -LiteralPath $exe -PathType Leaf)) {
    $result.code = 'SERVICE_IMAGE_PATH_UNREADABLE'
  } else {
    $full = [IO.Path]::GetFullPath($exe)
    if (!$full.EndsWith('\PrintAgent\Service\Sokna.PrintAgent.Service.exe',[StringComparison]::OrdinalIgnoreCase)) {
      $result.code = 'UNRECOGNIZED_SERVICE_IMAGE_PATH'
    } else {
      $result.image_path = $full
      $result.service_exe_sha256 = (Get-FileHash -LiteralPath $full -Algorithm SHA256).Hash.ToLowerInvariant()
      $result.installed_version = [string](Get-Item -LiteralPath $full).VersionInfo.ProductVersion
      $componentRoot = Split-Path (Split-Path $full -Parent) -Parent
      $worker = Join-Path $componentRoot 'Worker\Sokna.PrintAgent.Worker.exe'
      if (Test-Path -LiteralPath $worker -PathType Leaf) {
        $result.worker_exe_sha256 = (Get-FileHash -LiteralPath $worker -Algorithm SHA256).Hash.ToLowerInvariant()
      } else {
        $result.warnings += 'worker_executable_missing'
      }
      $manifestPath = Join-Path $componentRoot 'component-manifest.json'
      if (!(Test-Path -LiteralPath $manifestPath -PathType Leaf)) {
        $result.manifest_state = 'missing'
        $result.code = 'BLOCKED_NO_INSTALLED_MANIFEST'
      } else {
        try {
          $manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
          $result.manifest_version = [string]$manifest.version
          if ($manifest.format -ne 'sokna-print-agent-component-v1' -or $manifest.component -ne 'print-agent') {
            throw 'invalid_print_agent_manifest_shape'
          }
          $ok = $true
          foreach ($file in @($manifest.files)) {
            $rel = [string]$file.path
            if (!$rel -or [IO.Path]::IsPathRooted($rel) -or $rel.Contains('..')) { throw 'unsafe_manifest_path' }
            $path = [IO.Path]::GetFullPath((Join-Path $componentRoot $rel))
            if (!$path.StartsWith(($componentRoot.TrimEnd('\')+'\'),[StringComparison]::OrdinalIgnoreCase)) { throw 'outside_manifest_root' }
            if (!(Test-Path -LiteralPath $path -PathType Leaf)) { $ok=$false;continue }
            if ((Get-FileHash $path -Algorithm SHA256).Hash.ToLowerInvariant() -ne [string]$file.sha256) { $ok=$false }
          }
          $result.manifest_valid = $ok
          $result.manifest_state = $(if ($ok) {'all_file_hashes_match'} else {'files_missing_or_modified'})
          $result.code = $(if ($ok) {'BASELINE_IDENTITY_COLLECTED_NOT_YET_APPROVED'} else {'BLOCKED_MANIFEST_HASH_DRIFT'})
        } catch {
          $result.manifest_state = 'invalid'
          $result.code = 'BLOCKED_MANIFEST_PARSE_OR_PATH'
          $result.warnings += 'cannot_validate_installed_manifest'
        }
      }
    }
  }
}
# Only small metadata; never read/copy config.json, secret.dat, queue.db, token files or logs.
$result | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $output -Encoding UTF8
Write-Host ('SOKNA_READONLY_REPORT='+$output)
Write-Host ('SOKNA_PRINT_AGENT_PREFLIGHT_CODE='+[string]$result.code)
Write-Host 'No service, registry, database, printer or installed file was changed.'
