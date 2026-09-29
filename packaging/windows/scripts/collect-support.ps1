param(
    [Parameter(Mandatory=$true)][string]$DataRoot,
    [Parameter(Mandatory=$true)][string]$OutputRoot
)
$ErrorActionPreference='Stop'

function Test-FullyQualifiedPath([string]$Path){
      if([string]::IsNullOrWhiteSpace($Path)){return $false}
      try{
        $root=[IO.Path]::GetPathRoot($Path)
        if([string]::IsNullOrWhiteSpace($root)){return $false}
        if($root -match '^[A-Za-z]:[\\/]'){return $true}
        if($root.StartsWith('\\')){return $true}
      }catch{return $false}
      return $false
    }
function Safe-Full([string]$Path,[string]$Label){
    if(-not (Test-FullyQualifiedPath $Path)){throw "$Label must be an absolute path."}
    return [IO.Path]::GetFullPath($Path)
}
function Write-Text([string]$Path,[object]$Value){
    $text = if($null -eq $Value){''}else{[string]$Value}
    [IO.File]::WriteAllText($Path,$text,(New-Object Text.UTF8Encoding($false)))
}
function Capture([scriptblock]$Block){
    try { (& $Block 2>&1 | Out-String -Width 260).TrimEnd() }
    catch { "ERROR: $($_.Exception.Message)" }
}
function Copy-SafeLogs([string]$Source,[string]$Destination){
    if(-not(Test-Path -LiteralPath $Source -PathType Container)){return}
    New-Item -ItemType Directory -Path $Destination -Force|Out-Null
    Get-ChildItem -LiteralPath $Source -File -ErrorAction SilentlyContinue |
      Where-Object { $_.Name -notmatch '(?i)(secret|token|credential|private|pairing)' -and $_.Extension -in @('.log','.txt','.json') } |
      ForEach-Object { Copy-Item -LiteralPath $_.FullName -Destination (Join-Path $Destination $_.Name) -Force -ErrorAction SilentlyContinue }
}

$DataRoot=Safe-Full $DataRoot 'DataRoot'
$OutputRoot=Safe-Full $OutputRoot 'OutputRoot'
New-Item -ItemType Directory -Path $OutputRoot -Force|Out-Null

$stamp=(Get-Date).ToString('yyyyMMdd-HHmmss')
$work=Join-Path $env:TEMP ("SOKNA-Support-"+$stamp+"-"+[guid]::NewGuid().ToString('N'))
$zip=Join-Path $OutputRoot ("SOKNA-Support-"+$stamp+".zip")
New-Item -ItemType Directory -Path $work -Force|Out-Null

try {
    $summary = [ordered]@{
        format='sokna-windows-support-v1'
        created_at=(Get-Date).ToString('o')
        computer_name=$env:COMPUTERNAME
        os=(Capture { Get-CimInstance Win32_OperatingSystem | Select-Object Caption,Version,BuildNumber,OSArchitecture | Format-List })
        data_root=$DataRoot
        note='Secrets, pairing files, tokens and credentials are intentionally excluded.'
    }
    $summary|ConvertTo-Json -Depth 5|Set-Content -LiteralPath (Join-Path $work 'summary.json') -Encoding UTF8

    $servicesDir=Join-Path $work 'services'
    New-Item -ItemType Directory -Path $servicesDir -Force|Out-Null
    foreach($name in @('SoknaRuntime','SoknaPrintWorker')){
        Write-Text (Join-Path $servicesDir ($name+'-queryex.txt')) (Capture { & "$env:SystemRoot\System32\sc.exe" queryex $name })
        Write-Text (Join-Path $servicesDir ($name+'-qc.txt')) (Capture { & "$env:SystemRoot\System32\sc.exe" qc $name })
        Write-Text (Join-Path $servicesDir ($name+'-cim.txt')) (Capture { Get-CimInstance Win32_Service -Filter ("Name='"+$name+"'") | Select-Object Name,DisplayName,State,Status,StartMode,ProcessId,ExitCode,PathName | Format-List })
    }

    $events = Capture {
        Get-WinEvent -FilterHashtable @{LogName='System';ProviderName='Service Control Manager';StartTime=(Get-Date).AddDays(-7)} -ErrorAction Stop |
          Where-Object { $_.Message -match 'SoknaRuntime|SoknaPrintWorker|SOKNA Runtime|SOKNA Print Worker' } |
          Select-Object -First 120 TimeCreated,Id,LevelDisplayName,Message |
          Format-List
    }
    Write-Text (Join-Path $work 'service-control-manager-events.txt') $events

    $appEvents = Capture {
        Get-WinEvent -FilterHashtable @{LogName='Application';StartTime=(Get-Date).AddDays(-7)} -ErrorAction Stop |
          Where-Object { $_.Message -match 'SoknaRuntime|SoknaPrintWorker|Sokna\.PrintAgent|SOKNA Print Worker' } |
          Select-Object -First 120 TimeCreated,ProviderName,Id,LevelDisplayName,Message |
          Format-List
    }
    Write-Text (Join-Path $work 'application-events.txt') $appEvents

    $setupState=Join-Path $DataRoot 'setup\windows-services-state.json'
    if(Test-Path -LiteralPath $setupState -PathType Leaf){Copy-Item -LiteralPath $setupState -Destination (Join-Path $work 'windows-services-state.json') -Force}

    $logsDir=Join-Path $work 'logs'
    New-Item -ItemType Directory -Path $logsDir -Force|Out-Null
    Copy-SafeLogs (Join-Path $DataRoot 'Logs') (Join-Path $logsDir 'setup')
    Copy-SafeLogs (Join-Path $DataRoot 'runtime\state\logs') (Join-Path $logsDir 'runtime')
    $printDataRoot=Join-Path $DataRoot 'print-worker'
    try{
        $configured=[string](Get-ItemProperty -LiteralPath 'HKLM:\SOFTWARE\Sokna\Local\PrintWorker' -Name DataRoot -ErrorAction Stop).DataRoot
        if(-not[string]::IsNullOrWhiteSpace($configured)){ $printDataRoot=Safe-Full $configured 'Print Agent DataRoot' }
    }catch{}
    Write-Text (Join-Path $work 'print-worker-data-root.txt') $printDataRoot
    Copy-SafeLogs (Join-Path $printDataRoot 'logs') (Join-Path $logsDir 'print-worker')

    $runtimeState=Join-Path $DataRoot 'runtime\state\runtime-state.json'
    if(Test-Path -LiteralPath $runtimeState -PathType Leaf){Copy-Item -LiteralPath $runtimeState -Destination (Join-Path $work 'runtime-state.json') -Force}

    $network = Capture {
        Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue |
          Where-Object { $_.LocalPort -in @(17621,80,443,3306) } |
          Select-Object LocalAddress,LocalPort,OwningProcess,State |
          Sort-Object LocalPort |
          Format-Table -AutoSize
    }
    Write-Text (Join-Path $work 'listening-ports.txt') $network

    if(Test-Path -LiteralPath $zip){Remove-Item -LiteralPath $zip -Force}
    Compress-Archive -Path (Join-Path $work '*') -DestinationPath $zip -CompressionLevel Optimal
    Write-Host $zip
    $global:LASTEXITCODE=0
}
finally {
    Remove-Item -LiteralPath $work -Recurse -Force -ErrorAction SilentlyContinue
}
$global:LASTEXITCODE=0
exit 0
