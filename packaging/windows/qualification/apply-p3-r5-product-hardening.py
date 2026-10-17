from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]


def replace_once(path: Path, old: str, new: str):
    text = path.read_text(encoding='utf-8')
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{path}: expected exactly one match, got {count}: {old[:100]!r}')
    path.write_text(text.replace(old, new, 1), encoding='utf-8')

p = ROOT / 'windows/maintenance/src/Sokna.Maintenance.Core/LocalSlotManager.cs'

replace_once(p,
'''    [property: JsonPropertyName("apache_config")] string ApacheConfig,\n    [property: JsonPropertyName("build")] string Build,''',
'''    [property: JsonPropertyName("apache_config")] string ApacheConfig,\n    [property: JsonPropertyName("health_url")] string HealthUrl,\n    [property: JsonPropertyName("build")] string Build,''')

replace_once(p,
'''    private const string InventoryRelativePath = "resources\\\\local-managed-files-v1.json";''',
'''    private const string InventoryRelativePath = "resources\\\\local-managed-files-v1.json";\n    private static readonly string[] MachineLocalOverlayPaths =\n    {\n        ".sokna-installation.json",\n        "public\\\\local-recovery.php",\n        "public\\\\local-update-runner.php"\n    };''')

replace_once(p,
'''        var (apacheRoot, apacheConfig) = DiscoverApacheConfig(apacheExecutable);\n        var documentRoot = DiscoverDocumentRoot(apacheConfig, apacheRoot);''',
'''        var (apacheRoot, apacheConfig) = DiscoverApacheConfig(apacheExecutable);\n        var documentRoot = DiscoverDocumentRoot(apacheConfig, apacheRoot);\n        var healthUrl = DiscoverHealthUrl(apacheConfig, apacheRoot);''')

replace_once(p,
'''        return new LocalInstallLayout(packageRoot, webRoot, documentRoot, apacheExecutable, apacheConfig, build, managed.Count, isReparse, activeTarget);''',
'''        return new LocalInstallLayout(packageRoot, webRoot, documentRoot, apacheExecutable, apacheConfig, healthUrl, build, managed.Count, isReparse, activeTarget);''')

replace_once(p,
'''        VerifyCurrentManagedTree(layout.WebRoot, managedFiles);\n        state = Event(state, events, "BASELINE_VERIFIED");\n\n        CaptureMachineBinding(layout.WebRoot, persistentRoot);''',
'''        VerifyCurrentManagedTree(layout.WebRoot, managedFiles);\n        state = Event(state, events, "BASELINE_VERIFIED");\n        RequireHttpHealth(expectedBuild, layout.HealthUrl);\n        state = Event(state, events, "BASELINE_HTTP_HEALTHY", layout.HealthUrl);\n\n        CaptureMachineBinding(layout.WebRoot, persistentRoot);''')

text = p.read_text(encoding='utf-8')
text = text.replace('RequireHttpHealth(expectedBuild);', 'RequireHttpHealth(expectedBuild, layout.HealthUrl);')
text = text.replace('RestorePhysicalWebAndRequireHealth(layout.WebRoot, physicalBackup, expectedBuild);', 'RestorePhysicalWebAndRequireHealth(layout.WebRoot, physicalBackup, expectedBuild, layout.HealthUrl);')
p.write_text(text, encoding='utf-8')

replace_once(p,
'''    private static void StageSlot(string sourceWebRoot, string slot, IReadOnlyList<string> managedFiles, string persistentRoot)\n    {\n        if (Directory.Exists(slot)) return;\n        Directory.CreateDirectory(slot);\n        foreach (var rel in managedFiles)\n        {\n            var source = Path.Combine(sourceWebRoot, rel);\n            var target = Path.Combine(slot, rel);\n            Directory.CreateDirectory(Path.GetDirectoryName(target)!);\n            File.Copy(source, target, overwrite: true);\n        }\n        var bindingSource = Path.Combine(persistentRoot, ".sokna-installation.json");\n        if (File.Exists(bindingSource)) File.Copy(bindingSource, Path.Combine(slot, ".sokna-installation.json"), overwrite: true);\n    }\n\n    private static void CaptureMachineBinding(string webRoot, string persistentRoot)\n    {\n        var binding = Path.Combine(webRoot, ".sokna-installation.json");\n        if (!File.Exists(binding)) throw new InvalidDataException("Machine-local installation binding is missing before slot conversion.");\n        Directory.CreateDirectory(persistentRoot);\n        File.Copy(binding, Path.Combine(persistentRoot, ".sokna-installation.json"), overwrite: true);\n    }''',
'''    private static void StageSlot(string sourceWebRoot, string slot, IReadOnlyList<string> managedFiles, string persistentRoot)\n    {\n        if (Directory.Exists(slot)) return;\n        Directory.CreateDirectory(slot);\n        foreach (var rel in managedFiles)\n        {\n            var source = Path.Combine(sourceWebRoot, rel);\n            var target = Path.Combine(slot, rel);\n            Directory.CreateDirectory(Path.GetDirectoryName(target)!);\n            File.Copy(source, target, overwrite: true);\n        }\n        foreach (var rel in MachineLocalOverlayPaths)\n        {\n            var source = Path.Combine(persistentRoot, rel);\n            if (!File.Exists(source)) continue;\n            var target = Path.Combine(slot, rel);\n            Directory.CreateDirectory(Path.GetDirectoryName(target)!);\n            File.Copy(source, target, overwrite: true);\n        }\n    }\n\n    private static void CaptureMachineBinding(string webRoot, string persistentRoot)\n    {\n        var binding = Path.Combine(webRoot, ".sokna-installation.json");\n        if (!File.Exists(binding)) throw new InvalidDataException("Machine-local installation binding is missing before slot conversion.");\n        Directory.CreateDirectory(persistentRoot);\n        foreach (var rel in MachineLocalOverlayPaths)\n        {\n            var source = Path.Combine(webRoot, rel);\n            if (!File.Exists(source)) continue;\n            var target = Path.Combine(persistentRoot, rel);\n            Directory.CreateDirectory(Path.GetDirectoryName(target)!);\n            File.Copy(source, target, overwrite: true);\n        }\n    }''')

anchor = '''    private static string ParseSingleDocumentRoot(string text, string apacheRoot, string sourceName)\n'''
health_method = r'''    private static string DiscoverHealthUrl(string apacheConfig, string apacheRoot)
    {
        const string beginMarker = "# BEGIN SOKNA LOCAL MANAGED INCLUDE v1";
        const string endMarker = "# END SOKNA LOCAL MANAGED INCLUDE v1";
        const string managedHeader = "# SOKNA-MANAGED-APACHE-VHOST v1";

        var mainText = File.ReadAllText(apacheConfig);
        var selectedText = mainText;
        var beginCount = Regex.Matches(mainText, Regex.Escape(beginMarker), RegexOptions.CultureInvariant).Count;
        var endCount = Regex.Matches(mainText, Regex.Escape(endMarker), RegexOptions.CultureInvariant).Count;
        if (beginCount != endCount || beginCount > 1)
            throw new InvalidDataException("Apache main configuration contains an ambiguous SOKNA managed include block.");

        if (beginCount == 1)
        {
            var block = Regex.Match(mainText, Regex.Escape(beginMarker) + "(?<body>.*?)" + Regex.Escape(endMarker), RegexOptions.Singleline | RegexOptions.CultureInvariant);
            if (!block.Success) throw new InvalidDataException("SOKNA managed Apache include block could not be parsed for health discovery.");
            var includeMatches = Regex.Matches(block.Groups["body"].Value, "(?im)^\\s*Include\\s+\\\"?([^\\\"\\r\\n]+)\\\"?\\s*$", RegexOptions.CultureInvariant);
            if (includeMatches.Count != 1) throw new InvalidDataException("SOKNA managed Apache include block must contain exactly one Include directive for health discovery.");
            var includeRaw = includeMatches[0].Groups[1].Value.Trim()
                .Replace("${SRVROOT}", apacheRoot, StringComparison.OrdinalIgnoreCase)
                .Replace('/', Path.DirectorySeparatorChar);
            var includePath = Path.IsPathFullyQualified(includeRaw)
                ? Path.GetFullPath(includeRaw)
                : Path.GetFullPath(Path.Combine(apacheRoot, includeRaw));
            RequireFile(includePath, "SOKNA managed Apache include file is missing for health discovery.");
            selectedText = File.ReadAllText(includePath);
            if (!selectedText.TrimStart().StartsWith(managedHeader, StringComparison.Ordinal))
                throw new InvalidDataException("Resolved Apache include is not owned by SOKNA for health discovery.");
        }

        var ports = new HashSet<int>();
        foreach (Match match in Regex.Matches(selectedText, @"(?im)<VirtualHost\s+(?:\*|127\.0\.0\.1|localhost):(?<port>\d{2,5})\s*>", RegexOptions.CultureInvariant))
        {
            if (int.TryParse(match.Groups["port"].Value, out var port) && port is > 0 and <= 65535) ports.Add(port);
        }
        if (ports.Count == 0)
        {
            foreach (Match match in Regex.Matches(selectedText, @"(?im)^\s*Listen\s+(?:(?:127\.0\.0\.1|localhost):)?(?<port>\d{2,5})\s*$", RegexOptions.CultureInvariant))
            {
                if (int.TryParse(match.Groups["port"].Value, out var port) && port is > 0 and <= 65535) ports.Add(port);
            }
        }
        if (ports.Count != 1)
            throw new InvalidDataException($"SoknaApache Local health port could not be resolved unambiguously; candidates={string.Join(',', ports.OrderBy(static p => p))}.");
        return $"http://127.0.0.1:{ports.Single()}/login.php";
    }

'''
text = p.read_text(encoding='utf-8')
if anchor not in text:
    raise SystemExit('ParseSingleDocumentRoot anchor missing')
p.write_text(text.replace(anchor, health_method + anchor, 1), encoding='utf-8')

replace_once(p,
'''    private void RequireHttpHealth(string expectedBuild)\n    {\n        var deadline = DateTimeOffset.UtcNow + _healthTimeout;\n        Exception? last = null;\n        while (DateTimeOffset.UtcNow < deadline)\n        {\n            try\n            {\n                using var handler = new HttpClientHandler { UseProxy = false, AllowAutoRedirect = false };\n                using var client = new HttpClient(handler) { Timeout = TimeSpan.FromSeconds(4) };\n                using var request = new HttpRequestMessage(HttpMethod.Get, "http://127.0.0.1:18080/login.php?p3=" + Guid.NewGuid().ToString("N"));\n                request.Headers.CacheControl = new System.Net.Http.Headers.CacheControlHeaderValue { NoCache = true, NoStore = true };\n                using var response = client.Send(request);\n                var build = response.Headers.TryGetValues("X-Sokna-Build", out var builds) ? builds.SingleOrDefault() : null;\n                var runtime = response.Headers.TryGetValues("X-Sokna-Runtime", out var runtimes) ? runtimes.SingleOrDefault() : null;\n                if (string.Equals(build, expectedBuild, StringComparison.Ordinal) && string.Equals(runtime, "php-ready", StringComparison.Ordinal)) return;\n                last = new InvalidDataException($"HTTP health identity mismatch. build='{build}', runtime='{runtime}'.");\n            }\n            catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException or InvalidDataException)\n            {\n                last = ex;\n            }\n            Thread.Sleep(250);\n        }\n        throw new InvalidDataException("Target Local HTTP health was not proven within the Phase 3 timeout.", last);\n    }''',
'''    private void RequireHttpHealth(string expectedBuild, string healthUrl)\n    {\n        if (!Uri.TryCreate(healthUrl, UriKind.Absolute, out var baseUri) || baseUri.Scheme != Uri.UriSchemeHttp || !baseUri.IsLoopback)\n            throw new InvalidDataException("Local health URL must be absolute loopback HTTP.");\n        var deadline = DateTimeOffset.UtcNow + _healthTimeout;\n        Exception? last = null;\n        while (DateTimeOffset.UtcNow < deadline)\n        {\n            try\n            {\n                using var handler = new HttpClientHandler { UseProxy = false, AllowAutoRedirect = false };\n                using var client = new HttpClient(handler) { Timeout = TimeSpan.FromSeconds(4) };\n                var builder = new UriBuilder(baseUri) { Query = "p3=" + Guid.NewGuid().ToString("N") };\n                using var request = new HttpRequestMessage(HttpMethod.Get, builder.Uri);\n                request.Headers.CacheControl = new System.Net.Http.Headers.CacheControlHeaderValue { NoCache = true, NoStore = true };\n                using var response = client.Send(request);\n                var build = response.Headers.TryGetValues("X-Sokna-Build", out var builds) ? builds.SingleOrDefault() : null;\n                var runtime = response.Headers.TryGetValues("X-Sokna-Runtime", out var runtimes) ? runtimes.SingleOrDefault() : null;\n                if (response.IsSuccessStatusCode && string.Equals(build, expectedBuild, StringComparison.Ordinal) && string.Equals(runtime, "php-ready", StringComparison.Ordinal)) return;\n                last = new InvalidDataException($"HTTP health mismatch at '{baseUri}'. status={(int)response.StatusCode}, build='{build}', runtime='{runtime}'.");\n            }\n            catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException or InvalidDataException)\n            {\n                last = ex;\n            }\n            Thread.Sleep(250);\n        }\n        throw new InvalidDataException($"Target Local HTTP health was not proven at '{baseUri}' within the Phase 3 timeout. Last: {last?.Message ?? "no response"}", last);\n    }''')

replace_once(p,
'''    private void RestorePhysicalWebAndRequireHealth(string webRoot, string physicalBackup, string expectedBuild)''',
'''    private void RestorePhysicalWebAndRequireHealth(string webRoot, string physicalBackup, string expectedBuild, string healthUrl)''')
replace_once(p,
'''        StartApache();\n        RequireHttpHealth(expectedBuild, layout.HealthUrl);\n    }\n\n    private LocalSlotPhase3State NewState''',
'''        StartApache();\n        RequireHttpHealth(expectedBuild, healthUrl);\n    }\n\n    private LocalSlotPhase3State NewState''')

# Product versions / provenance.
for rel in [
    'windows/maintenance/src/Sokna.Maintenance.Supervisor/Sokna.Maintenance.Supervisor.csproj',
    'windows/maintenance/src/Sokna.Maintenance.UpdateEngine/Sokna.Maintenance.UpdateEngine.csproj',
]:
    cp = ROOT / rel
    text = cp.read_text(encoding='utf-8')
    text = text.replace('<Version>0.3.1</Version>', '<Version>0.3.2</Version>')
    text = text.replace('<AssemblyVersion>0.3.1.0</AssemblyVersion>', '<AssemblyVersion>0.3.2.0</AssemblyVersion>')
    text = text.replace('<FileVersion>0.3.1.0</FileVersion>', '<FileVersion>0.3.2.0</FileVersion>')
    text = text.replace('<InformationalVersion>4.0.0-p3-r4-apache-discovery</InformationalVersion>', '<InformationalVersion>4.0.0-p3-r5-health-recovery-hardening</InformationalVersion>')
    cp.write_text(text, encoding='utf-8')

cp = ROOT / 'windows/maintenance/src/Sokna.Maintenance.Core/Sokna.Maintenance.Core.csproj'
text = cp.read_text(encoding='utf-8').replace(
    '<InformationalVersion>4.0.0-p3-r4-apache-discovery</InformationalVersion>',
    '<InformationalVersion>4.0.0-p3-r5-health-recovery-hardening</InformationalVersion>')
cp.write_text(text, encoding='utf-8')

eng = ROOT / 'windows/maintenance/src/Sokna.Maintenance.UpdateEngine/Program.cs'
text = eng.read_text(encoding='utf-8')
text = text.replace('Update Engine 0.3.1 (P3 R4 Local slots; DB mutation disabled)', 'Update Engine 0.3.2 (P3 R5 health/recovery hardening; DB mutation disabled)')
text = text.replace('engine_version = "0.3.1"', 'engine_version = "0.3.2"')
eng.write_text(text, encoding='utf-8')

print('R5 product hardening patch applied.')
