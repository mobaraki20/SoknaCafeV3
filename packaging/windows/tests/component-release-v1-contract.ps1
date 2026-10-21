param([Parameter(Mandatory=$true)][string]$RepoRoot)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$base=Join-Path $env:RUNNER_TEMP ("sokna-component-contract-"+[guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $base -Force | Out-Null
try {
  $source=Join-Path $base 'source'
  $print=Join-Path $source 'print'
  $maint=Join-Path $source 'maintenance'
  $runtime=Join-Path $source 'SoknaRuntimeService.exe'
  New-Item -ItemType Directory -Path (Join-Path $print 'Service'),(Join-Path $print 'Worker'),$maint -Force|Out-Null
  [IO.File]::WriteAllBytes($runtime,[byte[]](1,2,3,4))
  [IO.File]::WriteAllBytes((Join-Path $print 'Service/Sokna.PrintAgent.Service.exe'),[byte[]](5,6,7,8))
  [IO.File]::WriteAllBytes((Join-Path $print 'Worker/Sokna.PrintAgent.Worker.exe'),[byte[]](9,10,11,12))
  [IO.File]::WriteAllBytes((Join-Path $maint 'SoknaMaintenanceSupervisor.exe'),[byte[]](13,14,15,16))
  [IO.File]::WriteAllBytes((Join-Path $maint 'SoknaMaintenanceUpdateEngine.exe'),[byte[]](17,18,19,20))
  $builder=Join-Path $RepoRoot 'packaging/windows/scripts/build-component-release-v1.ps1'
  $sourceCommit='0123456789abcdef0123456789abcdef01234567'
  foreach($case in @(
    @{id='runtime';source=$runtime;required=@('runtime/SoknaRuntimeService.exe');excluded=@('print-agent','maintenance')},
    @{id='print-agent';source=$print;required=@('print-agent/Service/Sokna.PrintAgent.Service.exe','print-agent/Worker/Sokna.PrintAgent.Worker.exe');excluded=@('runtime','maintenance')},
    @{id='maintenance';source=$maint;required=@('maintenance/app/SoknaMaintenanceSupervisor.exe','maintenance/app/SoknaMaintenanceUpdateEngine.exe');excluded=@('runtime','print-agent')}
  )){
    $out=Join-Path $base $case.id
    $raw=@(& $builder -RepoRoot $RepoRoot -OutputRoot $out -ComponentId $case.id -PayloadSource $case.source -SourceCommit $sourceCommit)
    if($LASTEXITCODE -ne 0){throw "Failed to build $($case.id) candidate."}
    $result=($raw -join [Environment]::NewLine)|ConvertFrom-Json
    if($result.component_id-ne$case.id -or $result.installable -ne $false -or -not(Test-Path $result.zip)){throw "Invalid $($case.id) build result"}
    $z=[IO.Compression.ZipFile]::OpenRead([string]$result.zip)
    try {
      $names=@($z.Entries|ForEach-Object{$_.FullName})
      if('component-release-v1.json' -notin $names){throw "Missing candidate manifest for $($case.id)"}
      foreach($name in $case.required){if($name -notin $names){throw "Missing $name"}}
      foreach($skip in $case.excluded){if(@($names|Where-Object { $_.StartsWith($skip+'/') }).Count-ne0){throw "Unselected service $skip leaked into candidate"}}
      $manifestEntry=$z.GetEntry('component-release-v1.json')
      $reader=[IO.StreamReader]::new($manifestEntry.Open())
      try {$manifest=$reader.ReadToEnd()|ConvertFrom-Json}finally{$reader.Dispose()}
      if($manifest.installable -ne $false -or $manifest.release_scope-ne'component_only' -or $manifest.component_id-ne$case.id){throw 'Candidate policy invalid'}
      foreach($entry in $manifest.files){
        $zipEntry=$z.GetEntry([string]$entry.path)
        if($null-eq$zipEntry -or $zipEntry.Length -ne [long]$entry.size_bytes){throw "Candidate file not found: $($entry.path)"}
        $stream=$zipEntry.Open()
        try{$digest=[Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($stream)).ToLowerInvariant()}finally{$stream.Dispose()}
        if($digest-ne[string]$entry.sha256){throw "Candidate digest mismatch: $($entry.path)"}
      }
    }finally{$z.Dispose()}
    Write-Host "$($case.id): metadata, exact scope, payload hashes PASS"
  }
  $missingFailure=$false
  try {
    & $builder -RepoRoot $RepoRoot -OutputRoot (Join-Path $base 'missing') -ComponentId 'print-agent' -PayloadSource (Join-Path $base 'does-not-exist') -SourceCommit $sourceCommit|Out-Null
  }catch{$missingFailure=$true}
  if(-not$missingFailure){throw 'Missing payload was accepted.'}
  Write-Host 'single-component release source contract: PASS'
}finally{
  Remove-Item -LiteralPath $base -Recurse -Force -ErrorAction SilentlyContinue
}
