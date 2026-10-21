# Signed read-only local Health probe. Uses the existing administrator-owned
# recovery-console key; never creates, replaces, exports or logs a signing key.
function Assert-MaintenanceSignedHealth([string]$ExpectedVersion,[int]$Attempts=30){
  $store=Join-Path ([Environment]::GetFolderPath('CommonApplicationData')) 'SOKNA\Maintenance\secrets\recovery-console.key'
  if(-not(Test-Path -LiteralPath $store -PathType Leaf)){throw 'MAINTENANCE_SIGNED_HEALTH_KEY_MISSING'}
  $encoded=[IO.File]::ReadAllText($store).Trim()
  try{$key=[Convert]::FromBase64String($encoded)}catch{throw 'MAINTENANCE_SIGNED_HEALTH_KEY_INVALID'}
  if($key.Length-ne32){throw 'MAINTENANCE_SIGNED_HEALTH_KEY_INVALID'}
  $uri='http://127.0.0.1:17631/v1/health'
  $emptyHash=([BitConverter]::ToString([Security.Cryptography.SHA256]::Create().ComputeHash([byte[]]@())) -replace'-','').ToLowerInvariant()
  $lastCode='NO_RESPONSE'
  try{
    for($i=0;$i-lt$Attempts;$i++){
      $stamp=[DateTimeOffset]::UtcNow.ToString('O',[Globalization.CultureInfo]::InvariantCulture)
      $nonce=([Guid]::NewGuid().ToString('N')).ToLowerInvariant()
      $canonical="GET`n/v1/health`n1.0`n$stamp`n$nonce`n$emptyHash"
      $hmac=[Security.Cryptography.HMACSHA256]::new([byte[]]$key)
      try{$sig=([BitConverter]::ToString($hmac.ComputeHash([Text.Encoding]::UTF8.GetBytes($canonical))) -replace'-','').ToLowerInvariant()}finally{$hmac.Dispose()}
      $headers=@{
       'X-Sokna-Maintenance-Protocol'='1.0'
       'X-Sokna-Timestamp'=$stamp
       'X-Sokna-Nonce'=$nonce
       'X-Sokna-Key-Id'='recovery-console'
       'X-Sokna-Body-SHA256'=$emptyHash
       'X-Sokna-Signature'=$sig
      }
      try {
        $response=Invoke-RestMethod -Uri $uri -Method Get -Headers $headers -TimeoutSec 3 -UseBasicParsing
        $actual=[string]$response.data.supervisor_version
        if([bool]$response.ok -and $actual-eq$ExpectedVersion){return}
        $lastCode='WRONG_VERSION_OR_UNHEALTHY'
      }catch{
        $status=0
        try{$status=[int]$_.Exception.Response.StatusCode}catch{}
        if($status-in @(401,403)){throw 'MAINTENANCE_SIGNED_HEALTH_AUTH_REJECTED'}
        $lastCode=$(if($status){'HTTP_'+$status}else{'TRANSPORT_UNAVAILABLE'})
      }
      Start-Sleep -Milliseconds 500
    }
    throw ('MAINTENANCE_SIGNED_HEALTH_UNVERIFIED: '+$lastCode)
  }finally{
    # Key is only used in this elevated process, never written to receipt/log.
    [Array]::Clear($key,0,$key.Length)
  }
}
