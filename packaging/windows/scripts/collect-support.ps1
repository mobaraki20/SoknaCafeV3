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
function Resolve-InstallRoot([string]$statePath){
    try{
        if(Test-Path -LiteralPath $statePath -PathType Leaf){
            $state=Get-Content -LiteralPath $statePath -Raw -ErrorAction Stop|ConvertFrom-Json
            $candidate=[string]$state.install_root
            if(Test-FullyQualifiedPath $candidate){return [IO.Path]::GetFullPath($candidate)}
        }
    }catch{}
    try{
        $svc=Get-CimInstance Win32_Service -Filter "Name='SoknaPrintWorker'" -ErrorAction Stop
        $raw=[string]$svc.PathName
        if($raw -match '^\s*"([^"]+\.exe)"'){$exe=$Matches[1]}
        elseif($raw -match '^\s*([^\s]+\.exe)'){$exe=$Matches[1]}
        else{return ''}
        $serviceDir=[IO.DirectoryInfo]::new([IO.Path]::GetDirectoryName($exe))
        if($serviceDir.Parent -and $serviceDir.Parent.Parent){return $serviceDir.Parent.Parent.FullName}
    }catch{}
    return ''
}
function Get-RelatedProcessSnapshot([string]$installRoot){
    $all=@(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue)
    $servicePids=@()
    foreach($serviceName in @('SoknaRuntime','SoknaPrintWorker')){
        try{
            $svc=Get-CimInstance Win32_Service -Filter ("Name='"+$serviceName+"'") -ErrorAction Stop
            if([uint32]$svc.ProcessId -gt 0){$servicePids += [uint32]$svc.ProcessId}
        }catch{}
    }
    $rows=$all|Where-Object{
        $path=[string]$_.ExecutablePath
        ($servicePids -contains [uint32]$_.ProcessId) -or
        $_.Name -match '(?i)^Sokna\.' -or
        $_.Name -match '(?i)^SoknaRuntime' -or
        (-not[string]::IsNullOrWhiteSpace($installRoot) -and -not[string]::IsNullOrWhiteSpace($path) -and $path.StartsWith($installRoot,[StringComparison]::OrdinalIgnoreCase))
    }|Select-Object ProcessId,ParentProcessId,Name,ExecutablePath,CreationDate
    return ($rows|Format-List|Out-String -Width 260).TrimEnd()
}
function Ensure-RestartManagerType {
    if('SoknaSupport.RestartManagerLocks' -as [type]){return}
    $source=@'
using System;
using System.Collections.Generic;
using System.Runtime.InteropServices;
using System.Runtime.InteropServices.ComTypes;
using System.Text;

namespace SoknaSupport {
    public static class RestartManagerLocks {
        private const int ERROR_MORE_DATA = 234;
        private const int CCH_RM_SESSION_KEY = 32;
        private const int CCH_RM_MAX_APP_NAME = 255;
        private const int CCH_RM_MAX_SVC_NAME = 63;

        [StructLayout(LayoutKind.Sequential)]
        private struct RM_UNIQUE_PROCESS {
            public int dwProcessId;
            public FILETIME ProcessStartTime;
        }

        private enum RM_APP_TYPE {
            RmUnknownApp = 0,
            RmMainWindow = 1,
            RmOtherWindow = 2,
            RmService = 3,
            RmExplorer = 4,
            RmConsole = 5,
            RmCritical = 1000
        }

        [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
        private struct RM_PROCESS_INFO {
            public RM_UNIQUE_PROCESS Process;
            [MarshalAs(UnmanagedType.ByValTStr, SizeConst = CCH_RM_MAX_APP_NAME + 1)] public string strAppName;
            [MarshalAs(UnmanagedType.ByValTStr, SizeConst = CCH_RM_MAX_SVC_NAME + 1)] public string strServiceShortName;
            public RM_APP_TYPE ApplicationType;
            public uint AppStatus;
            public uint TSSessionId;
            [MarshalAs(UnmanagedType.Bool)] public bool bRestartable;
        }

        [DllImport("rstrtmgr.dll", CharSet = CharSet.Unicode)]
        private static extern int RmStartSession(out uint pSessionHandle, int dwSessionFlags, StringBuilder strSessionKey);
        [DllImport("rstrtmgr.dll")]
        private static extern int RmEndSession(uint pSessionHandle);
        [DllImport("rstrtmgr.dll", CharSet = CharSet.Unicode)]
        private static extern int RmRegisterResources(uint pSessionHandle, uint nFiles, string[] rgsFilenames, uint nApplications, IntPtr rgApplications, uint nServices, string[] rgsServiceNames);
        [DllImport("rstrtmgr.dll")]
        private static extern int RmGetList(uint dwSessionHandle, out uint pnProcInfoNeeded, ref uint pnProcInfo, [In, Out] RM_PROCESS_INFO[] rgAffectedApps, ref uint lpdwRebootReasons);

        public static string[] GetLockers(string filePath) {
            if (String.IsNullOrWhiteSpace(filePath)) return Array.Empty<string>();
            uint handle;
            var key = new StringBuilder(CCH_RM_SESSION_KEY + 1);
            var start = RmStartSession(out handle, 0, key);
            if (start != 0) return new[] { "Restart Manager start failed: " + start };
            try {
                var register = RmRegisterResources(handle, 1, new[] { filePath }, 0, IntPtr.Zero, 0, Array.Empty<string>());
                if (register != 0) return new[] { "Restart Manager register failed: " + register };
                uint needed = 0, count = 0, reasons = 0;
                var first = RmGetList(handle, out needed, ref count, null, ref reasons);
                if (first == 0 && needed == 0) return Array.Empty<string>();
                if (first != ERROR_MORE_DATA) return new[] { "Restart Manager query failed: " + first };
                var infos = new RM_PROCESS_INFO[needed];
                count = needed;
                var second = RmGetList(handle, out needed, ref count, infos, ref reasons);
                if (second != 0) return new[] { "Restart Manager query failed: " + second };
                var result = new List<string>();
                for (var i = 0; i < count; i++) {
                    var info = infos[i];
                    result.Add("pid=" + info.Process.dwProcessId + "; app=" + info.strAppName + "; service=" + info.strServiceShortName + "; type=" + info.ApplicationType + "; restartable=" + info.bRestartable);
                }
                return result.ToArray();
            }
            finally { RmEndSession(handle); }
        }
    }
}
'@
    Add-Type -TypeDefinition $source -Language CSharp -ErrorAction Stop
}
function Capture-FileLockDiagnostics([string]$installRoot,[string]$destination){
    New-Item -ItemType Directory -Path $destination -Force|Out-Null
    Write-Text (Join-Path $destination 'related-processes.txt') (Capture { Get-RelatedProcessSnapshot $installRoot })
    Write-Text (Join-Path $destination 'tasklist-services.txt') (Capture { & "$env:SystemRoot\System32\tasklist.exe" /svc /fo list })
    Write-Text (Join-Path $destination 'tasklist-e_sqlite3.txt') (Capture { & "$env:SystemRoot\System32\tasklist.exe" /m e_sqlite3.dll /fo list })

    if([string]::IsNullOrWhiteSpace($installRoot) -or -not(Test-Path -LiteralPath $installRoot -PathType Container)){
        Write-Text (Join-Path $destination 'restart-manager-locks.txt') 'Install root could not be resolved; file-level lock inspection was skipped.'
        return
    }

    $targets=@()
    $targets += @(Get-ChildItem -LiteralPath $installRoot -Filter 'e_sqlite3.dll' -File -Recurse -ErrorAction SilentlyContinue | Select-Object -ExpandProperty FullName)
    foreach($relative in @('Runtime\SoknaRuntimeService.exe','PrintAgent\Service\Sokna.PrintAgent.Service.exe','PrintAgent\Worker\Sokna.PrintAgent.Worker.exe')){
        $candidate=Join-Path $installRoot $relative
        if(Test-Path -LiteralPath $candidate -PathType Leaf){$targets += $candidate}
    }
    $targets=@($targets|Sort-Object -Unique)

    $meta=@()
    foreach($target in $targets){
        try{
            $f=Get-Item -LiteralPath $target -ErrorAction Stop
            $meta += [pscustomobject]@{Path=$f.FullName;Length=$f.Length;LastWriteTimeUtc=$f.LastWriteTimeUtc;Attributes=[string]$f.Attributes}
        }catch{
            $meta += [pscustomobject]@{Path=$target;Length='';LastWriteTimeUtc='';Attributes=('ERROR: '+$_.Exception.Message)}
        }
    }
    Write-Text (Join-Path $destination 'target-files.txt') (($meta|Format-List|Out-String -Width 260).TrimEnd())

    $lockText=New-Object Text.StringBuilder
    try{
        Ensure-RestartManagerType
        foreach($target in $targets){
            [void]$lockText.AppendLine('FILE: '+$target)
            $lockers=@([SoknaSupport.RestartManagerLocks]::GetLockers($target))
            if($lockers.Count -eq 0){[void]$lockText.AppendLine('LOCKERS: none reported')}
            else{$lockers|ForEach-Object{[void]$lockText.AppendLine('LOCKER: '+$_)}}
            [void]$lockText.AppendLine('')
        }
    }catch{
        [void]$lockText.AppendLine('ERROR: Restart Manager inspection failed: '+$_.Exception.Message)
    }
    Write-Text (Join-Path $destination 'restart-manager-locks.txt') $lockText.ToString().TrimEnd()
}

$DataRoot=Safe-Full $DataRoot 'DataRoot'
$OutputRoot=Safe-Full $OutputRoot 'OutputRoot'
New-Item -ItemType Directory -Path $OutputRoot -Force|Out-Null

$stamp=(Get-Date).ToString('yyyyMMdd-HHmmss')
$work=Join-Path $env:TEMP ("SOKNA-Support-"+$stamp+"-"+[guid]::NewGuid().ToString('N'))
$zip=Join-Path $OutputRoot ("SOKNA-Support-"+$stamp+".zip")
New-Item -ItemType Directory -Path $work -Force|Out-Null

try {
    $setupState=Join-Path $DataRoot 'setup\windows-services-state.json'
    $installRoot=Resolve-InstallRoot $setupState
    $summary = [ordered]@{
        format='sokna-windows-support-v2'
        created_at=(Get-Date).ToString('o')
        computer_name=$env:COMPUTERNAME
        os=(Capture { Get-CimInstance Win32_OperatingSystem | Select-Object Caption,Version,BuildNumber,OSArchitecture | Format-List })
        data_root=$DataRoot
        install_root=$installRoot
        lock_diagnostics='Restart Manager + tasklist module/service snapshots are included when install files can be resolved.'
        note='Secrets, pairing files, tokens, credentials and process command lines are intentionally excluded.'
    }
    $summary|ConvertTo-Json -Depth 5|Set-Content -LiteralPath (Join-Path $work 'summary.json') -Encoding UTF8

    $servicesDir=Join-Path $work 'services'
    New-Item -ItemType Directory -Path $servicesDir -Force|Out-Null
    foreach($name in @('SoknaRuntime','SoknaPrintWorker')){
        Write-Text (Join-Path $servicesDir ($name+'-queryex.txt')) (Capture { & "$env:SystemRoot\System32\sc.exe" queryex $name })
        Write-Text (Join-Path $servicesDir ($name+'-qc.txt')) (Capture { & "$env:SystemRoot\System32\sc.exe" qc $name })
        Write-Text (Join-Path $servicesDir ($name+'-cim.txt')) (Capture { Get-CimInstance Win32_Service -Filter ("Name='"+$name+"'") | Select-Object Name,DisplayName,State,Status,StartMode,ProcessId,ExitCode,PathName | Format-List })
    }

    Capture-FileLockDiagnostics $installRoot (Join-Path $work 'file-locks')

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
