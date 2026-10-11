$ErrorActionPreference = 'Stop'

$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$sourcePath = Join-Path $repoRoot 'packaging\windows\setup-ui\PersianDashboardV2.cs'
$versionPath = Join-Path $repoRoot 'packaging\windows\WINDOWS_SERVICES_VERSION.txt'

$text = Get-Content -LiteralPath $sourcePath -Raw -Encoding UTF8

$versionPattern = '_versionDetail\.Text = string\.IsNullOrWhiteSpace\(state\.InstalledVersion\) \? .*? : state\.InstalledVersion;'
$versionReplacement = '_versionDetail.Text = string.IsNullOrWhiteSpace(state.InstalledVersion) ? "قابل تشخیص نیست" : state.InstalledVersion;'
$text = [regex]::Replace($text, $versionPattern, $versionReplacement)

$componentPattern = '(?s)    private static string ComponentStatus\(bool installed, bool running, string version\)\s*\{.*?\r?\n    \}\r?\n    private string ReadInstalledVersion'
$componentReplacement = @'
    private static string ComponentStatus(bool installed, bool running, string version)
    {
        if (!installed) return "نصب نشده";
        var versionText = string.IsNullOrWhiteSpace(version) ? "نسخه نامشخص" : $"نسخه {version}";
        return $"نصب شده • {versionText} • {(running ? "در حال اجرا" : "متوقف")}";
    }
    private string ReadInstalledVersion
'@
$text = [regex]::Replace($text, $componentPattern, $componentReplacement)

Set-Content -LiteralPath $sourcePath -Value $text -Encoding UTF8 -NoNewline
Set-Content -LiteralPath $versionPath -Value "1.0.15`n" -Encoding ASCII -NoNewline

$final = Get-Content -LiteralPath $sourcePath -Raw -Encoding UTF8
$badChars = @([char]0x00D8, [char]0x00D9, [char]0x00DB, [char]0x00E2, [char]0x00C3)
foreach ($bad in $badChars) {
    if ($final.Contains([string]$bad)) { throw "Mojibake marker U+$([int]$bad).ToString('X4') remains in PersianDashboardV2.cs after hotfix." }
}

$required = @(
    '"قابل تشخیص نیست"',
    '"نصب نشده"',
    '"نسخه نامشخص"',
    '$"نسخه {version}"',
    '$"نصب شده • {versionText} • {(running ? "در حال اجرا" : "متوقف")}"'
)
foreach ($needle in $required) {
    if (-not $final.Contains($needle)) { throw "Expected corrected Persian string missing: $needle" }
}

Write-Host 'Windows Services 1.0.15 Persian encoding hotfix applied and verified.'
