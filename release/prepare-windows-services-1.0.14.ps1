$ErrorActionPreference='Stop'
$utf8=New-Object System.Text.UTF8Encoding($false)
$dashboard='packaging/windows/setup-ui/PersianDashboardV2.cs'
$text=[IO.File]::ReadAllText((Resolve-Path $dashboard))

function Replace-Once([string]$old,[string]$new,[string]$label){
    $count=([regex]::Matches($script:text,[regex]::Escape($old))).Count
    if($count-ne1){throw "$label patch point count=$count"}
    $script:text=$script:text.Replace($old,$new)
}

Replace-Once @'
internal sealed record WindowsDashboardState(
    WindowsInstallState InstallState,
    string InstalledVersion,
    bool RuntimeInstalled,
    bool RuntimeRunning,
    bool PrintInstalled,
    bool PrintRunning,
    bool Paired,
    string LocalBaseUrl,
    bool PrerequisiteReady,
    string PrerequisiteVersion);
'@ @'
internal sealed record WindowsDashboardState(
    WindowsInstallState InstallState,
    string InstalledVersion,
    bool RuntimeInstalled,
    bool RuntimeRunning,
    string RuntimeVersion,
    bool PrintInstalled,
    bool PrintRunning,
    string PrintAgentVersion,
    bool Paired,
    string LocalBaseUrl,
    bool PrerequisiteReady,
    string PrerequisiteVersion);
'@ 'state-record'

Replace-Once '        AddDetail(details, 2, "نسخه نصب‌شده", _versionDetail);' '        AddDetail(details, 2, "Windows Services", _versionDetail);' 'windows-services-label'

Replace-Once @'
        var anyInstalled = runtime.Installed || print.Installed;
        var installedVersion = ReadInstalledVersion(anyInstalled);
'@ @'
        var anyInstalled = runtime.Installed || print.Installed;
        var installedVersion = ReadInstalledVersion(anyInstalled);
        var runtimeVersion = ReadServiceBinaryVersion(RuntimeService);
        var printAgentVersion = ReadServiceBinaryVersion(PrintService);
'@ 'read-component-versions'

Replace-Once @'
            runtime.Installed,
            runtime.Running,
            print.Installed,
            print.Running,
            paired,
'@ @'
            runtime.Installed,
            runtime.Running,
            runtimeVersion,
            print.Installed,
            print.Running,
            printAgentVersion,
            paired,
'@ 'state-constructor'

Replace-Once @'
        _runtimeDetail.Text = state.RuntimeInstalled
            ? state.RuntimeRunning ? "نصب شده • در حال اجرا" : "نصب شده • متوقف"
            : "نصب نشده";
        _printDetail.Text = state.PrintInstalled
            ? state.PrintRunning ? "نصب شده • در حال اجرا" : "نصب شده • متوقف"
            : "نصب نشده";
        _versionDetail.Text = string.IsNullOrWhiteSpace(state.InstalledVersion) ? "قابل تشخیص نیست" : state.InstalledVersion;
'@ @'
        _runtimeDetail.Text = ComponentStatus(state.RuntimeInstalled, state.RuntimeRunning, state.RuntimeVersion);
        _printDetail.Text = ComponentStatus(state.PrintInstalled, state.PrintRunning, state.PrintAgentVersion);
        _versionDetail.Text = string.IsNullOrWhiteSpace(state.InstalledVersion) ? "قابل تشخیص نیست" : state.InstalledVersion;
'@ 'render-component-versions'

Replace-Once @'
            true,
            true,
            true,
            true,
            true,
            "http://127.0.0.1:18080/",
'@ @'
            true,
            true,
            "1.0.2",
            true,
            true,
            "6.2.9",
            true,
            "http://127.0.0.1:18080/",
'@ 'layout-test-state'

$marker='    private string ReadInstalledVersion(bool anyInstalled)'
if(([regex]::Matches($text,[regex]::Escape($marker))).Count-ne1){throw 'version-helper insertion point missing'}
$helpers=@'
    private static string ReadServiceBinaryVersion(string serviceName)
    {
        try
        {
            var image = ReadServiceImage(serviceName);
            var executable = Environment.ExpandEnvironmentVariables(ExtractExecutablePath(image));
            if (string.IsNullOrWhiteSpace(executable) || !File.Exists(executable)) return "";
            var info = FileVersionInfo.GetVersionInfo(executable);
            var raw = !string.IsNullOrWhiteSpace(info.ProductVersion) ? info.ProductVersion : info.FileVersion;
            return string.IsNullOrWhiteSpace(raw) ? "" : NormalizeVersion(raw);
        }
        catch { return ""; }
    }

    private static string ComponentStatus(bool installed, bool running, string version)
    {
        if (!installed) return "نصب نشده";
        var versionText = string.IsNullOrWhiteSpace(version) ? "نسخه نامشخص" : $"نسخه {version}";
        return $"نصب شده • {versionText} • {(running ? "در حال اجرا" : "متوقف")}";
    }

'@
$text=$text.Replace($marker,$helpers+$marker)
[IO.File]::WriteAllText((Resolve-Path $dashboard),$text,$utf8)

$versionPath='packaging/windows/WINDOWS_SERVICES_VERSION.txt'
$current=([IO.File]::ReadAllText((Resolve-Path $versionPath))).Trim()
if($current-ne'1.0.13'){throw "Expected 1.0.13 base, got $current"}
[IO.File]::WriteAllText((Resolve-Path $versionPath),"1.0.14`n",$utf8)

$compat='packaging/windows/windows-services-compatibility-v1.json'
$compatText=[IO.File]::ReadAllText((Resolve-Path $compat))
if(-not$compatText.Contains('"package_version": "1.0.13"')){throw 'compatibility base package version missing'}
$compatText=$compatText.Replace('"package_version": "1.0.13"','"package_version": "1.0.14"')
[IO.File]::WriteAllText((Resolve-Path $compat),$compatText,$utf8)

$notes='packaging/windows/WINDOWS_SERVICES_1.0.14_NOTES_FA.md'
$noteText=@'
# SOKNA Windows Services 1.0.14 — Component Version Visibility

- Windows Services: 1.0.14
- Windows Runtime: 1.0.2 (unchanged)
- Print Agent: 6.2.9 (unchanged)
- The active Persian dashboard shows the independently installed Runtime and Print Agent executable versions next to each service status.
- Windows Services package version remains independently visible.
- Installed component versions are read from actual Windows Service ImagePath executable metadata; protocol/contract numbers are not presented as software versions.
- Local/Public are outside this release scope.
'@
[IO.File]::WriteAllText((Join-Path (Resolve-Path .) $notes),$noteText.TrimStart()+"`n",$utf8)

$expected=@($dashboard,$versionPath,$compat,$notes)|Sort-Object
git add -- $dashboard $versionPath $compat $notes
$actual=@(git diff --cached --name-only)|Sort-Object
if(($actual -join "`n")-ne($expected -join "`n")){Write-Host ($actual -join "`n");throw 'Unexpected 1.0.14 source delta'}
Write-Host 'Windows Services 1.0.14 source preparation: PASS'
