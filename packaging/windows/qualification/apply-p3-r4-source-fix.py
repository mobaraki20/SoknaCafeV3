from pathlib import Path

root = Path.cwd()
manager_path = root / 'windows/maintenance/src/Sokna.Maintenance.Core/LocalSlotManager.cs'
engine_path = root / 'windows/maintenance/src/Sokna.Maintenance.UpdateEngine/Program.cs'
launcher_path = root / 'windows/maintenance/tests/Sokna.Maintenance.P3HomeUat/Program.cs'

manager = manager_path.read_text(encoding='utf-8')

start = manager.index('    private static (string ApacheRoot, string ConfigPath) DiscoverApacheConfig(string apacheExecutable)')
end = manager.index('    private static string? ExtractApacheDefine', start)
replacement = r'''    private static (string ApacheRoot, string ConfigPath) DiscoverApacheConfig(string apacheExecutable)
    {
        apacheExecutable = Path.GetFullPath(apacheExecutable);
        var executableDirectory = Path.GetDirectoryName(apacheExecutable)
                                  ?? throw new InvalidDataException("SoknaApache executable has no parent directory.");
        var adjacentRoot = string.Equals(Path.GetFileName(executableDirectory), "bin", StringComparison.OrdinalIgnoreCase)
            ? Path.GetFullPath(Directory.GetParent(executableDirectory)?.FullName
                               ?? throw new InvalidDataException("SoknaApache bin directory has no parent."))
            : Path.GetFullPath(Path.Combine(executableDirectory, ".."));

        string? reportedRootText = null;
        string? configText = null;
        try
        {
            var psi = new ProcessStartInfo
            {
                FileName = apacheExecutable,
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true
            };
            psi.ArgumentList.Add("-V");
            using var process = Process.Start(psi);
            if (process is not null)
            {
                var stdout = process.StandardOutput.ReadToEnd();
                var stderr = process.StandardError.ReadToEnd();
                if (!process.WaitForExit(10_000))
                {
                    try { process.Kill(entireProcessTree: true); } catch { }
                    throw new TimeoutException("SoknaApache -V probe timed out.");
                }
                var text = stdout + "\n" + stderr;
                reportedRootText = ExtractApacheDefine(text, "HTTPD_ROOT");
                configText = ExtractApacheDefine(text, "SERVER_CONFIG_FILE");
            }
        }
        catch (Exception ex) when (ex is System.ComponentModel.Win32Exception or InvalidOperationException or IOException or TimeoutException)
        {
            // The service ImagePath remains the trusted ownership anchor. A failed -V probe
            // may use only the executable-adjacent Apache root; there is no global search.
        }

        var reportedRoot = !string.IsNullOrWhiteSpace(reportedRootText) && Path.IsPathFullyQualified(reportedRootText)
            ? Path.GetFullPath(reportedRootText)
            : null;
        var configValue = string.IsNullOrWhiteSpace(configText) ? Path.Combine("conf", "httpd.conf") : configText.Trim();

        if (Path.IsPathFullyQualified(configValue))
        {
            var absoluteConfig = Path.GetFullPath(configValue);
            RequireFile(absoluteConfig, "SoknaApache configuration file could not be resolved.");
            var selectedRoot = reportedRoot is not null && IsPathUnder(absoluteConfig, reportedRoot)
                ? reportedRoot
                : adjacentRoot;
            return (selectedRoot, absoluteConfig);
        }

        if (Path.IsPathRooted(configValue))
            throw new InvalidDataException("SoknaApache configuration path is rooted but not fully qualified.");

        var candidates = new List<(string Root, string Config, string Source)>();
        if (reportedRoot is not null)
            candidates.Add((reportedRoot, Path.GetFullPath(Path.Combine(reportedRoot, configValue)), "reported"));
        if (reportedRoot is null || !PathsEqual(reportedRoot, adjacentRoot))
            candidates.Add((adjacentRoot, Path.GetFullPath(Path.Combine(adjacentRoot, configValue)), "executable-adjacent"));

        var existing = candidates.Where(static candidate => File.Exists(candidate.Config)).ToList();
        if (existing.Count == 0)
            throw new FileNotFoundException("SoknaApache configuration file could not be resolved.", candidates.LastOrDefault().Config);

        var distinctConfigs = existing.Select(static candidate => candidate.Config)
                                      .Distinct(StringComparer.OrdinalIgnoreCase)
                                      .ToArray();
        if (distinctConfigs.Length > 1)
            throw new InvalidDataException("SoknaApache configuration ownership is ambiguous between compiled and executable-adjacent roots.");

        var selected = existing[0];
        return (selected.Root, selected.Config);
    }

'''
manager = manager[:start] + replacement + manager[end:]

start = manager.index('    private static string DiscoverDocumentRoot(string apacheConfig, string apacheRoot)')
end = manager.index('    private static void CreateJunction', start)
replacement = r'''    private static string DiscoverDocumentRoot(string apacheConfig, string apacheRoot)
    {
        const string beginMarker = "# BEGIN SOKNA LOCAL MANAGED INCLUDE v1";
        const string endMarker = "# END SOKNA LOCAL MANAGED INCLUDE v1";
        const string managedHeader = "# SOKNA-MANAGED-APACHE-VHOST v1";

        var mainText = File.ReadAllText(apacheConfig);
        var beginCount = Regex.Matches(mainText, Regex.Escape(beginMarker), RegexOptions.CultureInvariant).Count;
        var endCount = Regex.Matches(mainText, Regex.Escape(endMarker), RegexOptions.CultureInvariant).Count;
        if (beginCount != endCount || beginCount > 1)
            throw new InvalidDataException("Apache main configuration contains an ambiguous SOKNA managed include block.");

        if (beginCount == 1)
        {
            var block = Regex.Match(
                mainText,
                Regex.Escape(beginMarker) + "(?<body>.*?)" + Regex.Escape(endMarker),
                RegexOptions.Singleline | RegexOptions.CultureInvariant);
            if (!block.Success) throw new InvalidDataException("SOKNA managed Apache include block could not be parsed.");

            var includeMatches = Regex.Matches(
                block.Groups["body"].Value,
                "(?im)^\\s*Include\\s+\\\"?([^\\\"\\r\\n]+)\\\"?\\s*$",
                RegexOptions.CultureInvariant);
            if (includeMatches.Count != 1)
                throw new InvalidDataException("SOKNA managed Apache include block must contain exactly one Include directive.");

            var includeRaw = includeMatches[0].Groups[1].Value.Trim()
                                           .Replace("${SRVROOT}", apacheRoot, StringComparison.OrdinalIgnoreCase)
                                           .Replace('/', Path.DirectorySeparatorChar);
            var includePath = Path.IsPathFullyQualified(includeRaw)
                ? Path.GetFullPath(includeRaw)
                : Path.GetFullPath(Path.Combine(apacheRoot, includeRaw));
            RequireFile(includePath, "SOKNA managed Apache include file is missing.");

            var includeText = File.ReadAllText(includePath);
            if (!includeText.TrimStart().StartsWith(managedHeader, StringComparison.Ordinal))
                throw new InvalidDataException("Resolved Apache include is not owned by SOKNA.");
            return ParseSingleDocumentRoot(includeText, apacheRoot, "SOKNA managed Apache include");
        }

        return ParseSingleDocumentRoot(mainText, apacheRoot, "Apache main configuration");
    }

    private static string ParseSingleDocumentRoot(string text, string apacheRoot, string sourceName)
    {
        var matches = Regex.Matches(
            text,
            "(?im)^\\s*DocumentRoot\\s+\\\"?([^\\\"\\r\\n]+)\\\"?\\s*$",
            RegexOptions.CultureInvariant);
        if (matches.Count == 0) throw new InvalidDataException($"Apache DocumentRoot was not found in the {sourceName}.");
        var resolved = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (Match match in matches)
        {
            var raw = match.Groups[1].Value.Trim()
                           .Replace("${SRVROOT}", apacheRoot, StringComparison.OrdinalIgnoreCase)
                           .Replace('/', Path.DirectorySeparatorChar);
            var path = Path.IsPathFullyQualified(raw)
                ? Path.GetFullPath(raw)
                : Path.GetFullPath(Path.Combine(apacheRoot, raw));
            resolved.Add(path);
        }
        if (resolved.Count != 1) throw new InvalidDataException($"Apache DocumentRoot is ambiguous in the {sourceName}.");
        var documentRoot = resolved.Single();
        if (!Directory.Exists(documentRoot)) throw new InvalidDataException($"Apache DocumentRoot does not exist: {documentRoot}");
        return documentRoot;
    }

    private static bool IsPathUnder(string path, string root)
    {
        var fullPath = Path.GetFullPath(path);
        var fullRoot = Path.GetFullPath(root).TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar)
                       + Path.DirectorySeparatorChar;
        return fullPath.StartsWith(fullRoot, StringComparison.OrdinalIgnoreCase);
    }

'''
manager = manager[:start] + replacement + manager[end:]
manager_path.write_text(manager, encoding='utf-8', newline='\n')

engine = engine_path.read_text(encoding='utf-8')
needle = 'var slots = new LocalSlotManager(paths);\ntry\n{'
if needle not in engine:
    raise SystemExit('Update Engine attempt start anchor not found')
engine = engine.replace(needle, 'var slots = new LocalSlotManager(paths);\nvar attemptStartedAtUtc = DateTimeOffset.UtcNow;\ntry\n{', 1)
old = '        activation_performed = command is "local-slot-home-cycle" or "local-slot-activate",'
new = '        activation_performed = ActivationPerformedInAttempt(paths.LocalSlotStatePath, attemptStartedAtUtc),'
if old not in engine:
    raise SystemExit('Update Engine activation bookkeeping anchor not found')
engine = engine.replace(old, new, 1)
helper_anchor = '\nstatic void RunVerifyStage(string package, string identity, MaintenancePaths paths)\n'
if helper_anchor not in engine:
    raise SystemExit('Update Engine helper insertion anchor not found')
helper = r'''static bool ActivationPerformedInAttempt(string statePath, DateTimeOffset attemptStartedAtUtc)
{
    try
    {
        if (!File.Exists(statePath)) return false;
        var state = AtomicFile.ReadJson<LocalSlotPhase3State>(statePath);
        return state.Events.Any(entry =>
            entry.AtUtc >= attemptStartedAtUtc &&
            string.Equals(entry.State, "POINTER_SWITCHED", StringComparison.Ordinal));
    }
    catch
    {
        return false;
    }
}

'''
engine = engine.replace(helper_anchor, '\n' + helper + 'static void RunVerifyStage(string package, string identity, MaintenancePaths paths)\n', 1)
engine = engine.replace('Update Engine 0.3.0 (P3 Local slots; DB mutation disabled)', 'Update Engine 0.3.1 (P3 R4 Local slots; DB mutation disabled)')
engine = engine.replace('engine_version = "0.3.0"', 'engine_version = "0.3.1"')
engine_path.write_text(engine, encoding='utf-8', newline='\n')

launcher = launcher_path.read_text(encoding='utf-8')
if 'const string IdempotencyKey = "home-p3-231ai-r1";' not in launcher:
    raise SystemExit('Launcher idempotency anchor not found')
launcher = launcher.replace('const string IdempotencyKey = "home-p3-231ai-r1";', 'const string IdempotencyKey = "home-p3-231ai-r2";', 1)
launcher_path.write_text(launcher, encoding='utf-8', newline='\n')

versions = [
    ('windows/maintenance/src/Sokna.Maintenance.Core/Sokna.Maintenance.Core.csproj', '0.2.0', '0.2.1'),
    ('windows/maintenance/src/Sokna.Maintenance.UpdateEngine/Sokna.Maintenance.UpdateEngine.csproj', '0.3.0', '0.3.1'),
    ('windows/maintenance/src/Sokna.Maintenance.Supervisor/Sokna.Maintenance.Supervisor.csproj', '0.3.0', '0.3.1'),
]
for rel, old_version, new_version in versions:
    p = root / rel
    text = p.read_text(encoding='utf-8')
    text = text.replace(f'<Version>{old_version}</Version>', f'<Version>{new_version}</Version>')
    text = text.replace(f'<AssemblyVersion>{old_version}.0</AssemblyVersion>', f'<AssemblyVersion>{new_version}.0</AssemblyVersion>')
    text = text.replace(f'<FileVersion>{old_version}.0</FileVersion>', f'<FileVersion>{new_version}.0</FileVersion>')
    text = text.replace('<InformationalVersion>4.0.0-p3-local-slots</InformationalVersion>', '<InformationalVersion>4.0.0-p3-r4-apache-discovery</InformationalVersion>')
    p.write_text(text, encoding='utf-8', newline='\n')
