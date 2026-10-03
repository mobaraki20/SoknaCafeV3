$ErrorActionPreference='Stop'
$runtimeName='SoknaRuntime'
$printName='SoknaPrintWorker'
$dataRoot=Join-Path $env:ProgramData 'SOKNA'

function Quote([string]$v){ if($v -notmatch '[\\s\"]'){ return $v }; return '"'+($v -replace '(\\*)"','$1$1\\"' -replace '(\\+)$','$1$1')+'"' }
function ExpectedRuntime(){ return ((@((Join-Path $dataRoot 'bin\\SoknaRuntimeService.exe'),'--config',(Join-Path $dataRoot 'runtime\\runtime-config.json')) | ForEach-Object { Quote $_ }) -join ' ') }
function ExpectedPrint(){ return (Quote (Join-Path $dataRoot 'bin\\print-worker\\Service\\Sokna.PrintAgent.Service.exe')) }
function RemoveOwned([string]$name,[string]$expected){
  $svc=Get-Service -Name $name -ErrorAction SilentlyContinue
  if(-not $svc){ return }
  try{
    $actual=[string](Get-ItemProperty ("HKLM:\\SYSTEM\\CurrentControlSet\\Services\\"+$name)).ImagePath
    if($actual -cne $expected){ throw "Service ownership mismatch for $name; uninstall stopped before deleting a foreign service." }
    if($svc.Status -ne 'Stopped'){ Stop-Service -Name $name -ErrorAction Stop; $svc.WaitForStatus('Stopped',[TimeSpan]::FromSeconds(30)) }
    & "$env:SystemRoot\\System32\\sc.exe" delete $name | Out-Null
    if($LASTEXITCODE -ne 0){ throw "Service deletion failed for $name." }
  } finally { $svc.Dispose() }
}
RemoveOwned $runtimeName (ExpectedRuntime)
RemoveOwned $printName (ExpectedPrint)
# Business data, config, TLS material, queues and shared prerequisites are intentionally preserved.
