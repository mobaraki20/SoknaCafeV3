$ErrorActionPreference = 'Stop'

$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$sourcePath = Join-Path $repoRoot 'packaging\windows\setup-ui\PersianDashboardV2.cs'
$versionPath = Join-Path $repoRoot 'packaging\windows\WINDOWS_SERVICES_VERSION.txt'

$text = Get-Content -LiteralPath $sourcePath -Raw -Encoding UTF8
$replacements = [ordered]@{
    '"Ù‚Ø§Ø¨Ù„ ØªØ´Ø®ÛŒØµ Ù†ÛŒØ³Øª"' = '"قابل تشخیص نیست"'
    '"Ù†ØµØ¨ Ù†Ø´Ø¯Ù‡"' = '"نصب نشده"'
    '"Ù†Ø³Ø®Ù‡ Ù†Ø§Ù…Ø´Ø®Øµ"' = '"نسخه نامشخص"'
    '$"Ù†Ø³Ø®Ù‡ {version}"' = '$"نسخه {version}"'
    '$"Ù†ØµØ¨ Ø´Ø¯Ù‡ â€¢ {versionText} â€¢ {(running ? "Ø¯Ø± Ø­Ø§Ù„ Ø§Ø¬Ø±Ø§" : "Ù…ØªÙˆÙ‚Ù")}"' = '$"نصب شده • {versionText} • {(running ? "در حال اجرا" : "متوقف")}"'
}

foreach ($entry in $replacements.GetEnumerator()) {
    if ($text.Contains($entry.Key)) {
        $text = $text.Replace($entry.Key, $entry.Value)
    }
}

Set-Content -LiteralPath $sourcePath -Value $text -Encoding UTF8 -NoNewline
Set-Content -LiteralPath $versionPath -Value "1.0.15`n" -Encoding ASCII -NoNewline

$final = Get-Content -LiteralPath $sourcePath -Raw -Encoding UTF8
if ($final -match '[ÙØÛ]' -or $final.Contains('â€¢') -or $final.Contains('â€”')) {
    throw 'Mojibake marker remains in PersianDashboardV2.cs after hotfix.'
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
