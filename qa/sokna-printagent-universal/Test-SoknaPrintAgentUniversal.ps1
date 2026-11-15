param([Parameter(Mandatory=$true)][string]$PackageZip,[Parameter(Mandatory=$true)][string]$ScriptPath)
$ErrorActionPreference='Stop'
$origProgramFiles=$env:ProgramFiles
$root=Join-Path $env:RUNNER_TEMP ('universal-agent-qa-'+[guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Force $root|Out-Null
function Read-Report([string]$Out){
  $f=Get-ChildItem -LiteralPath $Out -Filter 'SOKNA-PrintAgent-Universal-*.json'|Sort-Object LastWriteTime -Descending|Select-Object -First 1
  if(!$f){throw 'QA_REPORT_MISSING'}
  return (Get-Content -LiteralPath $f.FullName -Raw|ConvertFrom-Json)
}
function Run-Case([string]$Name,[string]$Version,[string]$ExpectedStatus,[string]$ExpectedMode,[bool]$Apply=$false){
  $case=Join-Path $root $Name
  $env:ProgramFiles=Join-Path $case 'Program Files'
  $reports=Join-Path $case 'reports'
  $install=Join-Path $env:ProgramFiles 'SOKNA Windows Services\PrintAgent'
  New-Item -ItemType Directory -Force $reports|Out-Null
  $fixture=$null
  if($Version){
    New-Item -ItemType Directory -Force $install|Out-Null
    Expand-Archive -LiteralPath $PackageZip -DestinationPath $install -Force
    $m=Join-Path $install 'component-manifest.json'
    $j=Get-Content $m -Raw | ConvertFrom-Json
    $j.version=$Version
    $j|ConvertTo-Json -Depth 20|Set-Content -LiteralPath $m -Encoding UTF8
    $fixture=Join-Path $case 'fixture.json'
    @{Name='SoknaPrintWorker';PathName=('"'+(Join-Path $install 'Service\Sokna.PrintAgent.Service.exe')+'"');StartName='LocalSystem';State='Running'} | ConvertTo-Json | Set-Content $fixture -Encoding UTF8
  }
  $args=@('-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',$ScriptPath,'-PackageZip',$PackageZip,'-OutputDir',$reports,'-Action',$(if($Apply){'Apply'}else{'Probe'}))
  if($fixture){$args+=@('-FixtureJson',$fixture,'-FixtureProgramFiles',$env:ProgramFiles)}
  if($Apply){$args+='-AllowMutation'}
  & powershell.exe @args | Out-Host
  $exit=$LASTEXITCODE
  $r=Read-Report $reports
  if($r.status -ne $ExpectedStatus){throw "CASE_${Name}_STATUS_$($r.status)_EXPECTED_${ExpectedStatus}"}
  if($ExpectedMode -and $r.install_mode -ne $ExpectedMode){throw "CASE_${Name}_MODE_$($r.install_mode)_EXPECTED_${ExpectedMode}"}
  if(($ExpectedStatus -eq 'BLOCKED' -and $exit -eq 0) -or ($ExpectedStatus -ne 'BLOCKED' -and $exit -ne 0)){throw "CASE_${Name}_EXIT_$exit"}
  if($r.programdata_mutated -ne $false -or $r.maintenance_mutated -ne $false -or $r.runtime_mutated -ne $false){throw "CASE_${Name}_SCOPE_DRIFT"}
  if($Version){
    $p=Join-Path $install 'component-manifest.json'
    $m=Get-Content $p -Raw|ConvertFrom-Json
    if($m.version -ne $Version){throw "CASE_${Name}_MUTATED_INSTALLED_VERSION"}
  }
  Write-Host "PASS_UNIVERSAL_${Name}_$ExpectedStatus"
}
try{
  Run-Case 'CLEAN' '' 'READY_FOR_GATED_APPLY' 'clean_install'
  Run-Case 'OLD_6210' '6.2.10' 'READY_FOR_GATED_APPLY' 'upgrade'
  Run-Case 'CAFE_6211' '6.2.11' 'READY_FOR_GATED_APPLY' 'upgrade'
  Run-Case 'SAME_6212' '6.2.12' 'READY_FOR_GATED_APPLY' 'verify_or_repair'
  Run-Case 'NEWER_6213' '6.2.13' 'BLOCKED' ''
  Run-Case 'QA_APPLY_HOLD' '6.2.11' 'BLOCKED' '' $true
  Write-Host 'PASS_UNIVERSAL_6_CASES_ALL_NONMUTATING'
  $global:LASTEXITCODE = 0
}finally{$env:ProgramFiles=$origProgramFiles}