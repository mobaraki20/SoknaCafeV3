param(
  [Parameter(Mandatory=$true)][string]$SupervisorExe
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest

function Assert([bool]$Condition,[string]$Message){if(-not$Condition){throw $Message}}
function Wait-File([string]$Path,[int]$Seconds=20){$d=[DateTime]::UtcNow.AddSeconds($Seconds);while([DateTime]::UtcNow-lt$d){if(Test-Path -LiteralPath $Path -PathType Leaf){return};Start-Sleep -Milliseconds 150};throw "Timed out waiting for $Path"}
function Stop-P($p){if($p){try{if(-not$p.HasExited){Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue;$p.WaitForExit(5000)|Out-Null}}catch{}}}
function Invoke-SignedGet([string]$BaseUrl,[string]$KeyPath,[string]$Path){
  $key=[Convert]::FromBase64String((Get-Content -LiteralPath $KeyPath -Raw).Trim())
  $timestamp=[DateTimeOffset]::UtcNow.ToString('O',[Globalization.CultureInfo]::InvariantCulture)
  $nonce=[guid]::NewGuid().ToString('N')
  $bodyHash='e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'
  $canonical="GET`n$Path`n1.0`n$timestamp`n$nonce`n$bodyHash"
  $h=[Security.Cryptography.HMACSHA256]::new($key)
  try{$sig=[Convert]::ToHexString($h.ComputeHash([Text.Encoding]::UTF8.GetBytes($canonical))).ToLowerInvariant()}finally{$h.Dispose()}
  $headers=@{
    'X-Sokna-Maintenance-Protocol'='1.0';'X-Sokna-Timestamp'=$timestamp;'X-Sokna-Nonce'=$nonce;
    'X-Sokna-Key-Id'='web-adapter';'X-Sokna-Body-SHA256'=$bodyHash;'X-Sokna-Signature'=$sig
  }
  Invoke-WebRequest -Uri ($BaseUrl.TrimEnd('/')+$Path) -Method Get -Headers $headers -SkipHttpErrorCheck -TimeoutSec 8
}
function AllowedSids([string]$Path){
  @((Get-Acl -LiteralPath $Path).Access | Where-Object AccessControlType -eq Allow | ForEach-Object {$_.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value})
}
function Assert-NoBroadRead([string]$Path){
  $sids=AllowedSids $Path
  foreach($bad in @('S-1-1-0','S-1-5-11','S-1-5-32-545','S-1-5-32-546')){Assert (-not($sids-contains$bad)) "Broad SID $bad has access to $Path"}
}

Add-Type -TypeDefinition @'
using System;
using System.ComponentModel;
using System.IO;
using System.Runtime.InteropServices;
using System.Security.Principal;
using Microsoft.Win32.SafeHandles;
public static class SoknaAclProbe {
  [DllImport("advapi32.dll", SetLastError=true, CharSet=CharSet.Unicode)]
  private static extern bool LogonUser(string user, string domain, string password, int logonType, int provider, out SafeAccessTokenHandle token);
  public static bool CanRead(string user, string domain, string password, string path) {
    SafeAccessTokenHandle token;
    if(!LogonUser(user, domain, password, 2, 0, out token)) throw new Win32Exception(Marshal.GetLastWin32Error());
    using(token) {
      return WindowsIdentity.RunImpersonated(token, () => {
        try { File.ReadAllText(path); return true; }
        catch(UnauthorizedAccessException) { return false; }
      });
    }
  }
}
'@

$SupervisorExe=(Resolve-Path -LiteralPath $SupervisorExe).Path
$serviceName='SoknaApache'
Assert ($null-eq(Get-Service -Name $serviceName -ErrorAction SilentlyContinue)) 'SoknaApache already exists on qualification runner.'
$root=Join-Path $env:RUNNER_TEMP ('sokna-maintenance-p1a-'+[guid]::NewGuid().ToString('N'))
$apacheRoot=Join-Path $root 'fake-apache\Apache24\bin'
New-Item -ItemType Directory -Path $apacheRoot -Force|Out-Null
$fakeHttpd=Join-Path $apacheRoot 'httpd.exe'
Copy-Item -LiteralPath "$env:SystemRoot\System32\cmd.exe" -Destination $fakeHttpd
$binPath='"'+$fakeHttpd+'" -k runservice -n "SoknaApache"'
$p=$null
$probeUser='SoknaAclProbe'+([guid]::NewGuid().ToString('N').Substring(0,8))
$probePassword='S0kna!'+([guid]::NewGuid().ToString('N'))+'aA9'
try {
  New-Service -Name $serviceName -BinaryPathName $binPath -DisplayName 'SOKNA Apache Identity Fixture' -StartupType Manual|Out-Null
  $serviceKey='HKLM:\SYSTEM\CurrentControlSet\Services\SoknaApache'
  $objectName=[string](Get-ItemProperty -LiteralPath $serviceKey -Name ObjectName).ObjectName
  Assert ($objectName-eq'LocalSystem') "New-Service default account was not LocalSystem: $objectName"

  $p=Start-Process -FilePath $SupervisorExe -ArgumentList @('--data-root',$root,'--port','17633') -PassThru -WindowStyle Hidden
  Wait-File (Join-Path $root 'web-adapter-acl.json')
  Wait-File (Join-Path $root 'control-plane.json')
  $evidence=Get-Content -LiteralPath (Join-Path $root 'web-adapter-acl.json') -Raw|ConvertFrom-Json
  Assert ([string]$evidence.state-eq'ready') "Web adapter ACL not ready: $($evidence|ConvertTo-Json -Compress)"
  Assert ([string]$evidence.service_name-eq'SoknaApache') 'Wrong Apache service name.'
  Assert ([string]$evidence.service_account-eq'LocalSystem') 'Resolved service account mismatch.'
  Assert ([string]$evidence.service_sid-eq'S-1-5-18') 'Resolved LocalSystem SID mismatch.'
  Assert ([string]$evidence.php_execution_contract-eq'mod_php_in_soknaapache_service_token') 'PHP execution contract mismatch.'
  Assert ([string]$evidence.key_acl_mode-eq'existing_system_acl') 'Unexpected key ACL mode.'
  Assert ([bool]$evidence.exact_identity_read_granted) 'Exact identity read is not qualified.'
  Assert ([bool]$evidence.descriptor_read_granted) 'Descriptor read is not qualified.'
  Assert (-not[bool]$evidence.broad_read_granted) 'Broad read was granted.'

  $webKey=Join-Path $root 'secrets\web-adapter.key'
  $recoveryKey=Join-Path $root 'secrets\recovery-console.key'
  $descriptor=Join-Path $root 'control-plane.json'
  foreach($path in @($webKey,$recoveryKey,$descriptor)){Assert-NoBroadRead $path}
  $webSids=AllowedSids $webKey
  $recoverySids=AllowedSids $recoveryKey
  Assert ($webSids-contains'S-1-5-18') 'SYSTEM is missing from web-adapter.key ACL.'
  Assert ($webSids-contains'S-1-5-32-544') 'Administrators are missing from web-adapter.key ACL.'
  Assert ($recoverySids-contains'S-1-5-18') 'SYSTEM is missing from recovery-console.key ACL.'
  Assert ($recoverySids-contains'S-1-5-32-544') 'Administrators are missing from recovery-console.key ACL.'

  $base='http://127.0.0.1:17633/'
  foreach($route in @('/v1/health','/v1/status','/v1/components','/v1/operations/active','/v1/backups','/v1/recovery','/v1/journal/head')){
    $r=Invoke-SignedGet $base $webKey $route
    Assert ([int]$r.StatusCode-eq200) "Web-adapter signed route failed [$route]: $($r.Content)"
  }

  $null=& net.exe user $probeUser $probePassword /add 2>&1
  Assert ($LASTEXITCODE-eq0) 'Unable to create unrelated local user ACL probe.'
  $canRead=[SoknaAclProbe]::CanRead($probeUser,$env:COMPUTERNAME,$probePassword,$webKey)
  Assert (-not$canRead) 'Unrelated unprivileged local user could read web-adapter.key.'

  Stop-P $p;$p=$null
  $p=Start-Process -FilePath $SupervisorExe -ArgumentList @('--data-root',$root,'--port','17633') -PassThru -WindowStyle Hidden
  Wait-File $descriptor
  $evidence2=Get-Content -LiteralPath (Join-Path $root 'web-adapter-acl.json') -Raw|ConvertFrom-Json
  Assert ([string]$evidence2.state-eq'ready') 'Supervisor restart lost Web adapter ACL readiness.'
  Assert ([bool]$evidence2.descriptor_read_granted) 'Supervisor restart did not re-qualify descriptor ACL.'
  $afterRestart=Invoke-SignedGet $base $webKey '/v1/health'
  Assert ([int]$afterRestart.StatusCode-eq200) 'Web-adapter HMAC failed after Supervisor restart.'
}
finally {
  Stop-P $p
  & net.exe user $probeUser /delete *> $null
  $svc=Get-Service -Name $serviceName -ErrorAction SilentlyContinue
  if($svc){try{if($svc.Status-ne'Stopped'){Stop-Service -Name $serviceName -Force -ErrorAction SilentlyContinue}}catch{};$svc.Dispose()}
  & sc.exe delete $serviceName *> $null
  for($i=0;$i-lt40;$i++){if(-not(Get-Service -Name $serviceName -ErrorAction SilentlyContinue)){break};Start-Sleep -Milliseconds 250}
}

# Executable fail-closed probe for an unqualified shared account. This is CI behavior evidence, not real-host UAT.
$pendingRoot=Join-Path $env:RUNNER_TEMP ('sokna-maintenance-p1a-pending-'+[guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path (Join-Path $pendingRoot 'fake-apache\Apache24\bin') -Force|Out-Null
$pendingHttpd=Join-Path $pendingRoot 'fake-apache\Apache24\bin\httpd.exe'
Copy-Item -LiteralPath "$env:SystemRoot\System32\cmd.exe" -Destination $pendingHttpd
$p=$null
try {
  New-Service -Name $serviceName -BinaryPathName ('"'+$pendingHttpd+'" -k runservice') -DisplayName 'SOKNA Apache Pending Fixture' -StartupType Manual|Out-Null
  Set-ItemProperty -LiteralPath 'HKLM:\SYSTEM\CurrentControlSet\Services\SoknaApache' -Name ObjectName -Value 'NT AUTHORITY\LocalService'
  $p=Start-Process -FilePath $SupervisorExe -ArgumentList @('--data-root',$pendingRoot,'--port','17634') -PassThru -WindowStyle Hidden
  Wait-File (Join-Path $pendingRoot 'web-adapter-acl.json')
  $pending=Get-Content -LiteralPath (Join-Path $pendingRoot 'web-adapter-acl.json') -Raw|ConvertFrom-Json
  Assert ([string]$pending.state-eq'acl_pending') 'Shared LocalService identity was not rejected fail-closed.'
  Assert (-not[bool]$pending.exact_identity_read_granted) 'Shared LocalService identity received secret access.'
}
finally {
  Stop-P $p
  $svc=Get-Service -Name $serviceName -ErrorAction SilentlyContinue
  if($svc){$svc.Dispose()}
  & sc.exe delete $serviceName *> $null
  for($i=0;$i-lt40;$i++){if(-not(Get-Service -Name $serviceName -ErrorAction SilentlyContinue)){break};Start-Sleep -Milliseconds 250}
}

[ordered]@{
  format='sokna-maintenance-v4-windows-p1a-web-adapter-acl-qualification-v1'
  result='PASS'
  p1_baseline='01558cc3220092dd857e5d196c2157c00c5de390'
  supervisor_version='0.1.1'
  protocol='1.0'
  supported_setup_service='SoknaApache'
  supported_setup_identity='LocalSystem'
  php_execution_contract='mod_php_in_soknaapache_service_token'
  exact_identity_resolution='PASS'
  key_acl_system_admin_only='PASS'
  web_adapter_hmac_all_seven_p1_routes='PASS'
  supervisor_restart_descriptor_reconcile='PASS'
  unrelated_local_user_key_denial='PASS'
  unqualified_shared_identity_fail_closed='PASS'
  application_or_db_mutation='NOT_IMPLEMENTED_P1A'
  evidence_tier='windows-2022-ci-scm-and-acl-not-real-cafe-host'
  real_cafe_host_uat=$false
}|ConvertTo-Json -Depth 4
