param(
    [Parameter(Mandatory=$true)][string]$WebServerExe,
    [Parameter(Mandatory=$true)][string]$AppRoot,
    [Parameter(Mandatory=$true)][string]$DataRoot,
    [string]$Hostname = 'sokna.local',
    [ValidateRange(1,65535)][int]$HttpsPort = 443,
    [string]$TemplatePath = '',
    [switch]$ValidateOnly
)
$ErrorActionPreference='Stop'
Import-Module (Join-Path $PSScriptRoot 'setup-support.psm1') -DisableNameChecking -Force

$BeginMarker='# BEGIN SOKNA LOCAL MANAGED INCLUDE v1'
$EndMarker='# END SOKNA LOCAL MANAGED INCLUDE v1'
$ManagedHeader='# SOKNA-MANAGED-APACHE-VHOST v1'

function ConvertTo-ApachePath([string]$Path){
    ([IO.Path]::GetFullPath($Path).TrimEnd('\')).Replace('\','/')
}
function Assert-DisjointRoots([string]$A,[string]$B){
    $aFull=[IO.Path]::GetFullPath($A).TrimEnd('\')
    $bFull=[IO.Path]::GetFullPath($B).TrimEnd('\')
    if($aFull.Equals($bFull,[StringComparison]::OrdinalIgnoreCase) -or
       $aFull.StartsWith($bFull+'\',[StringComparison]::OrdinalIgnoreCase) -or
       $bFull.StartsWith($aFull+'\',[StringComparison]::OrdinalIgnoreCase)){
        throw 'AppRoot/WebRoot and DataRoot must be separate non-overlapping directories.'
    }
}
function Get-ApacheOwnerInfo([string]$Exe){
    $result=Invoke-SoknaProcess $Exe @('-V')
    $text=($result.Output+"`n"+$result.Error)
    $rootMatch=[regex]::Match($text,'(?m)-D\s+HTTPD_ROOT="([^"]+)"')
    $cfgMatch=[regex]::Match($text,'(?m)-D\s+SERVER_CONFIG_FILE="([^"]+)"')
    if(-not $rootMatch.Success -or -not $cfgMatch.Success){throw 'Apache did not report HTTPD_ROOT/SERVER_CONFIG_FILE; SOKNA will not guess the shared configuration path.'}
    $reportedRoot=[IO.Path]::GetFullPath($rootMatch.Groups[1].Value)
    $cfgRaw=$cfgMatch.Groups[1].Value
    # Windows PowerShell 5.1 runs on .NET Framework, which has no IsPathFullyQualified.
    $absoluteConfig = $cfgRaw -match '^(?:[A-Za-z]:[\\/]|[\\/]{2}[^\\/]+[\\/][^\\/]+)'
    if (-not $absoluteConfig -and [IO.Path]::IsPathRooted($cfgRaw)) { throw 'Apache config path is rooted but not absolute.' }
    $reportedCfg=if($absoluteConfig){[IO.Path]::GetFullPath($cfgRaw)}else{[IO.Path]::GetFullPath((Join-Path $reportedRoot $cfgRaw))}
    $root=$reportedRoot
    $cfg=$reportedCfg
    if(-not $absoluteConfig){
        # Apache Lounge archives retain a compiled C:\Apache24 HTTPD_ROOT even when
        # their complete Apache24 directory is relocated.  Anchor the alternate root
        # only to the explicitly selected ...\bin\httpd.exe, never to a global search.
        $exeDir=[IO.Path]::GetDirectoryName([IO.Path]::GetFullPath($Exe))
        if([IO.Path]::GetFileName($exeDir).Equals('bin',[StringComparison]::OrdinalIgnoreCase)){
            $adjacentRoot=[IO.Directory]::GetParent($exeDir).FullName
            $adjacentCfg=[IO.Path]::GetFullPath((Join-Path $adjacentRoot $cfgRaw))
            $reportedExists=Test-Path -LiteralPath $reportedCfg -PathType Leaf
            $adjacentExists=Test-Path -LiteralPath $adjacentCfg -PathType Leaf
            if($reportedExists -and $adjacentExists -and -not $reportedCfg.Equals($adjacentCfg,[StringComparison]::OrdinalIgnoreCase)){
                throw 'Apache configuration ownership is ambiguous between compiled and executable-adjacent roots.'
            }
            if($adjacentExists -and -not $reportedExists){$root=$adjacentRoot;$cfg=$adjacentCfg}
        }
    }
    Assert-SoknaSafePath $root
    Assert-SoknaSafePath $cfg
    if(-not(Test-Path -LiteralPath $cfg -PathType Leaf)){throw 'Apache main configuration file was not found.'}
    [pscustomobject]@{root=$root;config=$cfg}
}
function Read-Utf8Strict([string]$Path){
    $bytes=[IO.File]::ReadAllBytes($Path)
    $utf8=New-Object Text.UTF8Encoding($false,$true)
    try{return $utf8.GetString($bytes)}catch{throw 'Apache configuration is not UTF-8/ASCII; SOKNA refuses to rewrite a shared config with unknown encoding.'}
}
function Write-Utf8Atomic([string]$Path,[string]$Text){
    $dir=[IO.Path]::GetDirectoryName($Path)
    $temp=Join-Path $dir ('.'+[IO.Path]::GetFileName($Path)+'.sokna-'+[guid]::NewGuid().ToString('N')+'.tmp')
    try{
        [IO.File]::WriteAllText($temp,$Text,(New-Object Text.UTF8Encoding($false)))
        if(Test-Path -LiteralPath $Path -PathType Leaf){
            $backup=Join-Path $dir ('.'+[IO.Path]::GetFileName($Path)+'.sokna-replace-'+[guid]::NewGuid().ToString('N')+'.bak')
            try{[IO.File]::Replace($temp,$Path,$backup,$true)}finally{Remove-Item -LiteralPath $backup -Force -ErrorAction SilentlyContinue}
        }else{Move-Item -LiteralPath $temp -Destination $Path}
    }finally{Remove-Item -LiteralPath $temp -Force -ErrorAction SilentlyContinue}
}
function Set-ManagedBlock([string]$Text,[string]$IncludePath){
    $include='Include "'+(ConvertTo-ApachePath $IncludePath)+'"'
    $block=$BeginMarker+"`r`n"+$include+"`r`n"+$EndMarker
    $beginCount=([regex]::Matches($Text,[regex]::Escape($BeginMarker))).Count
    $endCount=([regex]::Matches($Text,[regex]::Escape($EndMarker))).Count
    if($beginCount -ne $endCount -or $beginCount -gt 1){throw 'Apache main config contains an ambiguous SOKNA managed block; manual reconciliation is required.'}
    if($beginCount -eq 0){
        $newline=if($Text.Contains("`r`n")){"`r`n"}else{"`n"}
        return $Text.TrimEnd("`r","`n")+$newline+$newline+($block -replace "`r`n",$newline)+$newline
    }
    $pattern='(?s)'+[regex]::Escape($BeginMarker)+'.*?'+[regex]::Escape($EndMarker)
    return [regex]::Replace($Text,$pattern,[Text.RegularExpressions.MatchEvaluator]{param($m) $block},1)
}
function Render-SoknaVhost([string]$Template,[string]$WebRoot,[string]$Data,[string]$HostName,[int]$Port){
    $text=$Template
    $text=$text.Replace('{{SOKNA_WEB_ROOT}}',(ConvertTo-ApachePath $WebRoot))
    $text=$text.Replace('{{SOKNA_DATA_ROOT}}',(ConvertTo-ApachePath $Data))
    $text=$text.Replace('{{SOKNA_HOSTNAME}}',$HostName)
    $text=$text.Replace('{{SOKNA_HTTPS_PORT}}',[string]$Port)
    if($text.Contains('{{')){throw 'Apache template contains an unresolved SOKNA token.'}
    return $text
}

$WebServerExe=[IO.Path]::GetFullPath($WebServerExe)
$AppRoot=[IO.Path]::GetFullPath($AppRoot).TrimEnd('\')
$DataRoot=[IO.Path]::GetFullPath($DataRoot).TrimEnd('\')
Assert-SoknaSafePath $WebServerExe
Assert-SoknaSafePath $AppRoot
Assert-SoknaSafePath $DataRoot
Assert-DisjointRoots $AppRoot $DataRoot
if(-not(Test-Path -LiteralPath $WebServerExe -PathType Leaf)){throw 'Apache executable was not found.'}
if(-not(Test-Path -LiteralPath $AppRoot -PathType Container)){throw 'AppRoot/WebRoot does not exist.'}
if($Hostname -notmatch '^(?=.{1,253}$)[a-z0-9]+(?:[.-][a-z0-9]+)*$'){throw 'Invalid local hostname.'}
if(-not $TemplatePath){
    $TemplatePath=Join-Path $PSScriptRoot 'apache\sokna-local-https.conf.template'
    if(-not(Test-Path -LiteralPath $TemplatePath -PathType Leaf)){$TemplatePath=Join-Path $PSScriptRoot 'sokna-local-https.conf.template'}
}
$TemplatePath=[IO.Path]::GetFullPath($TemplatePath)
if(-not(Test-Path -LiteralPath $TemplatePath -PathType Leaf)){throw 'SOKNA Apache template was not found.'}
Assert-SoknaSafePath $TemplatePath

$owner=Get-ApacheOwnerInfo $WebServerExe
Invoke-SoknaProcess $WebServerExe @('-t','-f',$owner.config) | Out-Null
$moduleProbe=Invoke-SoknaProcess $WebServerExe @('-M','-f',$owner.config)
$modules=($moduleProbe.Output+"`n"+$moduleProbe.Error)
foreach($required in @('ssl_module','headers_module','authz_core_module')){if($modules -notmatch ('(?m)^\s*'+[regex]::Escape($required)+'\s+\(shared\)|^\s*'+[regex]::Escape($required)+'\s+\(static\)')){throw "Apache module required by SOKNA is not loaded: $required"}}

$configDir=[IO.Path]::GetDirectoryName($owner.config)
$managedInclude=Join-Path $configDir 'sokna-local.conf'
Assert-SoknaSafePath $managedInclude
$mainText=Read-Utf8Strict $owner.config
$existingInclude=$null
if(Test-Path -LiteralPath $managedInclude -PathType Leaf){
    $existingInclude=Read-Utf8Strict $managedInclude
    if(-not $existingInclude.TrimStart().StartsWith($ManagedHeader,[StringComparison]::Ordinal)){throw 'Existing sokna-local.conf is not owned by SOKNA; setup refuses to overwrite it.'}
}
$newMain=Set-ManagedBlock $mainText $managedInclude
$template=Read-Utf8Strict $TemplatePath
$rendered=$ManagedHeader+"`r`n"+(Render-SoknaVhost $template $AppRoot $DataRoot $Hostname $HttpsPort).Trim()+"`r`n"
$mainChanged=($newMain -cne $mainText)
$includeChanged=($null -eq $existingInclude -or $existingInclude -cne $rendered)

$tlsCert=Join-Path $DataRoot 'secrets\tls\server.crt.pem'
$tlsKey=Join-Path $DataRoot 'secrets\tls\server.key.pem'
$tlsReady=(Test-Path -LiteralPath $tlsCert -PathType Leaf) -and (Test-Path -LiteralPath $tlsKey -PathType Leaf)
$result=[ordered]@{
    schema_version=1
    web_root=$AppRoot
    data_root=$DataRoot
    apache_root=$owner.root
    main_config=$owner.config
    managed_include=$managedInclude
    tls_ready=[bool]$tlsReady
    validate_only=[bool]$ValidateOnly
    changed=[bool]($mainChanged -or $includeChanged)
    reload_required=[bool]($mainChanged -or $includeChanged)
    lifecycle_owner='external'
}

# Before TLS exists, ValidateOnly still proves discovery/base syntax/modules/ownership without mutating shared config.
if($ValidateOnly -and -not $tlsReady){$result | ConvertTo-Json -Depth 5 -Compress; return}
if(-not $tlsReady){throw 'SOKNA TLS identity is not ready; Apache configuration was not changed.'}
if(-not $result.changed){$result | ConvertTo-Json -Depth 5 -Compress; return}

$stage=New-SoknaPrivateDirectory (Join-Path $env:TEMP ('SOKNA-apache-'+[guid]::NewGuid().ToString('N')))
$mainBackup=Join-Path $stage 'httpd.conf.original'
$includeBackup=Join-Path $stage 'sokna-local.conf.original'
$hadInclude=Test-Path -LiteralPath $managedInclude -PathType Leaf
try{
    [IO.File]::WriteAllBytes($mainBackup,[IO.File]::ReadAllBytes($owner.config))
    if($hadInclude){[IO.File]::WriteAllBytes($includeBackup,[IO.File]::ReadAllBytes($managedInclude))}

    # Prove the candidate config before touching shared Apache files.
    $candidateInclude=Join-Path $stage 'sokna-local.candidate.conf'
    $candidateMain=Join-Path $stage 'httpd.candidate.conf'
    [IO.File]::WriteAllText($candidateInclude,$rendered,(New-Object Text.UTF8Encoding($false)))
    $candidateMainText=Set-ManagedBlock $mainText $candidateInclude
    [IO.File]::WriteAllText($candidateMain,$candidateMainText,(New-Object Text.UTF8Encoding($false)))
    Invoke-SoknaProcess $WebServerExe @('-t','-f',$candidateMain) | Out-Null

    if($ValidateOnly){$result | ConvertTo-Json -Depth 5 -Compress; return}

    try{
        Write-Utf8Atomic $managedInclude $rendered
        Write-Utf8Atomic $owner.config $newMain
        Invoke-SoknaProcess $WebServerExe @('-t','-f',$owner.config) | Out-Null
    }catch{
        [IO.File]::WriteAllBytes($owner.config,[IO.File]::ReadAllBytes($mainBackup))
        if($hadInclude){[IO.File]::WriteAllBytes($managedInclude,[IO.File]::ReadAllBytes($includeBackup))}else{Remove-Item -LiteralPath $managedInclude -Force -ErrorAction SilentlyContinue}
        throw
    }
    $result | ConvertTo-Json -Depth 5 -Compress
}finally{Remove-Item -LiteralPath $stage -Recurse -Force -ErrorAction SilentlyContinue}
