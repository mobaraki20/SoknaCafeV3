param(
  [Parameter(Mandatory=$true)][string]$DataRoot,
  [Parameter(Mandatory=$true)][string]$OutputRoot,
  [ValidateRange(1,10)][int]$InstallerTransactions=5
)
$ErrorActionPreference='Stop'
function Full([string]$p,[string]$label){
  if([string]::IsNullOrWhiteSpace($p)){throw "$label must be absolute."}
  try{$root=[IO.Path]::GetPathRoot($p);$full=[IO.Path]::GetFullPath($p)}catch{throw "$label must be an absolute valid path."}
  if([string]::IsNullOrWhiteSpace($root)-or-not(($root-match'^[A-Za-z]:[\\/]')-or$root.StartsWith('\\'))){throw "$label must be absolute."}
  return $full
}
function Redact([string]$v){if($null-eq$v){return ''};$v=$v-replace'(?i)ws1_[a-f0-9]{24}_[a-f0-9]{48}','[PAIRING_CODE_REDACTED]';$v=$v-replace'(?i)(token|secret|credential|password)(\s*[:=]\s*)[^\s,;\}\]]+','$1$2[REDACTED]';$v}
function WriteText([string]$p,[string]$v){[IO.File]::WriteAllText($p,(Redact $v),(New-Object Text.UTF8Encoding($false)))}
function Capture([scriptblock]$b){try{Redact ((& $b 2>&1|Out-String -Width 260).TrimEnd())}catch{"ERROR: "+(Redact $_.Exception.Message)}}
function CopySafeTextTree([string]$src,[string]$dst){
  if(-not(Test-Path $src -PathType Container)){return};$root=[IO.Path]::GetFullPath($src).TrimEnd('\')
  Get-ChildItem $root -File -Recurse -ErrorAction SilentlyContinue|Where-Object{$_.Extension -in @('.json','.jsonl','.log','.txt') -and $_.Name -notmatch '(?i)(secret|token|credential|private|pairing)' }|ForEach-Object{
    $rel=$_.FullName.Substring($root.Length+1);$out=Join-Path $dst $rel;New-Item -ItemType Directory -Path ([IO.Path]::GetDirectoryName($out)) -Force|Out-Null
    try{$raw=Get-Content $_.FullName -Raw -ErrorAction Stop;if($raw.Length-gt5MB){$raw=$raw.Substring([Math]::Max(0,$raw.Length-5MB))};WriteText $out $raw}catch{}
  }
}
$DataRoot=Full $DataRoot 'DataRoot';$OutputRoot=Full $OutputRoot 'OutputRoot';New-Item -ItemType Directory -Path $OutputRoot -Force|Out-Null
$stamp=(Get-Date).ToString('yyyyMMdd-HHmmss');$work=Join-Path $env:TEMP ("SOKNA-Support-v2-$stamp-"+[guid]::NewGuid().ToString('N'));New-Item -ItemType Directory -Path $work -Force|Out-Null
$zip=Join-Path $OutputRoot "SOKNA-Support-v2-$stamp.zip"
try {
  $core=Join-Path $PSScriptRoot 'collect-support-core.ps1'
  if(Test-Path $core -PathType Leaf){
    $legacy=Join-Path $work '_legacy-out';New-Item -ItemType Directory -Path $legacy -Force|Out-Null
    & $core -DataRoot $DataRoot -OutputRoot $legacy
    $legacyZip=Get-ChildItem $legacy -Filter '*.zip' -File|Sort-Object LastWriteTimeUtc -Descending|Select-Object -First 1
    if($legacyZip){$baseline=Join-Path $work 'baseline';Expand-Archive -LiteralPath $legacyZip.FullName -DestinationPath $baseline -Force}
    Remove-Item $legacy -Recurse -Force -ErrorAction SilentlyContinue
  }
  $summary=[ordered]@{format='sokna-windows-support-v2';created_at_utc=[DateTime]::UtcNow.ToString('o');computer_name=$env:COMPUTERNAME;installer_transactions_requested=$InstallerTransactions;installer_transaction_root=(Join-Path $DataRoot 'Installer\logs');maintenance_service='SoknaMaintenanceSupervisor';note='Text evidence is redacted; secrets, pairing codes, credentials and private files are excluded.'}
  $summary|ConvertTo-Json -Depth 5|Set-Content (Join-Path $work 'support-v2-summary.json') -Encoding UTF8

  $svcDir=Join-Path $work 'maintenance-service';New-Item -ItemType Directory -Path $svcDir -Force|Out-Null
  foreach($name in @('SoknaRuntime','SoknaPrintWorker','SoknaMaintenanceSupervisor')){
    WriteText (Join-Path $svcDir ($name+'-query.txt')) (Capture {& "$env:SystemRoot\System32\sc.exe" queryex $name})
    WriteText (Join-Path $svcDir ($name+'-config.txt')) (Capture {& "$env:SystemRoot\System32\sc.exe" qc $name})
  }
  WriteText (Join-Path $work 'maintenance-scm-events.txt') (Capture {Get-WinEvent -FilterHashtable @{LogName='System';ProviderName='Service Control Manager';StartTime=(Get-Date).AddDays(-7)} -ErrorAction Stop|Where-Object{$_.Message-match'SoknaRuntime|SoknaPrintWorker|SoknaMaintenanceSupervisor|SOKNA Maintenance'}|Select-Object -First 160 TimeCreated,Id,LevelDisplayName,Message|Format-List})

  $installerRoot=Join-Path $DataRoot 'Installer\logs';$dest=Join-Path $work 'installer-transactions';New-Item -ItemType Directory -Path $dest -Force|Out-Null
  if(Test-Path $installerRoot -PathType Container){
    $txs=@(Get-ChildItem $installerRoot -Directory|Sort-Object LastWriteTimeUtc -Descending|Select-Object -First $InstallerTransactions)
    foreach($tx in $txs){CopySafeTextTree $tx.FullName (Join-Path $dest $tx.Name)}
  }
  $userBootstrap=Join-Path $env:LOCALAPPDATA 'SOKNA\Installer\logs';$userDest=Join-Path $work 'user-bootstrap';New-Item -ItemType Directory -Path $userDest -Force|Out-Null
  if(Test-Path $userBootstrap -PathType Container){foreach($tx in @(Get-ChildItem $userBootstrap -Directory|Sort-Object LastWriteTimeUtc -Descending|Select-Object -First $InstallerTransactions)){CopySafeTextTree $tx.FullName (Join-Path $userDest $tx.Name)}}

  Compress-Archive -Path (Join-Path $work '*') -DestinationPath $zip -CompressionLevel Optimal -Force
  $hash=(Get-FileHash $zip -Algorithm SHA256).Hash.ToLowerInvariant();Write-Host ("SOKNA_SUPPORT_BUNDLE="+$zip);Write-Host ("SOKNA_SUPPORT_SHA256="+$hash)
}finally{Remove-Item $work -Recurse -Force -ErrorAction SilentlyContinue}
