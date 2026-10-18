using System.Diagnostics;
using System.Security.Cryptography;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Text.RegularExpressions;
using Microsoft.Win32;
using System.ServiceProcess;

namespace Sokna.Maintenance.Core;

public sealed record LocalInstallLayout(
    [property: JsonPropertyName("package_root")] string PackageRoot,
    [property: JsonPropertyName("web_root")] string WebRoot,
    [property: JsonPropertyName("document_root")] string DocumentRoot,
    [property: JsonPropertyName("apache_executable")] string ApacheExecutable,
    [property: JsonPropertyName("apache_config")] string ApacheConfig,
    [property: JsonPropertyName("health_url")] string HealthUrl,
    [property: JsonPropertyName("build")] string Build,
    [property: JsonPropertyName("managed_file_count")] int ManagedFileCount,
    [property: JsonPropertyName("web_is_reparse_point")] bool WebIsReparsePoint,
    [property: JsonPropertyName("active_slot_target")] string? ActiveSlotTarget);

public sealed record LocalSlotPhase3State(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("schema_version")] int SchemaVersion,
    [property: JsonPropertyName("phase")] string Phase,
    [property: JsonPropertyName("expected_build")] string ExpectedBuild,
    [property: JsonPropertyName("package_root")] string PackageRoot,
    [property: JsonPropertyName("stable_web_root")] string StableWebRoot,
    [property: JsonPropertyName("slot_root")] string SlotRoot,
    [property: JsonPropertyName("slot_a")] string SlotA,
    [property: JsonPropertyName("slot_b")] string SlotB,
    [property: JsonPropertyName("active_slot")] string? ActiveSlot,
    [property: JsonPropertyName("previous_slot")] string? PreviousSlot,
    [property: JsonPropertyName("physical_web_backup")] string? PhysicalWebBackup,
    [property: JsonPropertyName("database_mutation_performed")] bool DatabaseMutationPerformed,
    [property: JsonPropertyName("public_mutation_performed")] bool PublicMutationPerformed,
    [property: JsonPropertyName("windows_reboot_required")] bool WindowsRebootRequired,
    [property: JsonPropertyName("recovery_required")] bool RecoveryRequired,
    [property: JsonPropertyName("updated_at_utc")] DateTimeOffset UpdatedAtUtc,
    [property: JsonPropertyName("events")] IReadOnlyList<LocalSlotPhase3Event> Events);

public sealed record LocalSlotPhase3Event(
    [property: JsonPropertyName("state")] string State,
    [property: JsonPropertyName("at_utc")] DateTimeOffset AtUtc,
    [property: JsonPropertyName("detail")] string? Detail = null);

public sealed record LocalSlotHomeCycleResult(
    [property: JsonPropertyName("ok")] bool Ok,
    [property: JsonPropertyName("expected_build")] string ExpectedBuild,
    [property: JsonPropertyName("slot_a")] string SlotA,
    [property: JsonPropertyName("slot_b")] string SlotB,
    [property: JsonPropertyName("final_active_slot")] string FinalActiveSlot,
    [property: JsonPropertyName("managed_file_count")] int ManagedFileCount,
    [property: JsonPropertyName("database_mutation_performed")] bool DatabaseMutationPerformed,
    [property: JsonPropertyName("public_mutation_performed")] bool PublicMutationPerformed,
    [property: JsonPropertyName("windows_reboot_required")] bool WindowsRebootRequired,
    [property: JsonPropertyName("state_path")] string StatePath);

public sealed class LocalSlotManager
{
    public const string ApacheServiceName = "SoknaApache";
    private const string InventoryRelativePath = "resources\\local-managed-files-v1.json";
    private static readonly string[] MachineLocalOverlayPaths =
    {
        ".sokna-installation.json",
        "public\\local-recovery.php",
        "public\\local-update-runner.php"
    };
    private readonly MaintenancePaths _paths;
    private readonly TimeSpan _serviceTimeout;
    private readonly TimeSpan _healthTimeout;

    public LocalSlotManager(MaintenancePaths paths, TimeSpan? serviceTimeout = null, TimeSpan? healthTimeout = null)
    {
        _paths = paths;
        _serviceTimeout = serviceTimeout ?? TimeSpan.FromSeconds(35);
        _healthTimeout = healthTimeout ?? TimeSpan.FromSeconds(35);
    }

    /// <summary>
    /// Native Windows authoritative, non-mutating discovery for panel diagnostics.
    /// ResolveLinkTarget handles Windows directory junctions, unlike Go EvalSymlinks.
    /// HTTP readiness and DB readiness are separate gates; they are NOT implied here.
    /// </summary>
    public LocalInstallLayout InspectCurrent()
    {
        if (!OperatingSystem.IsWindows()) throw new PlatformNotSupportedException("Local slot inspection is Windows-only.");
        var apacheExecutable = DiscoverServiceExecutable(ApacheServiceName);
        var (apacheRoot, apacheConfig) = DiscoverApacheConfig(apacheExecutable);
        var documentRoot = DiscoverDocumentRoot(apacheConfig, apacheRoot);
        if (!string.Equals(Path.GetFileName(documentRoot.TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar)),
            "public", StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("Apache DocumentRoot is not the expected Local public directory.");
        var webRoot = Path.GetFullPath(Path.GetDirectoryName(documentRoot) ??
            throw new InvalidDataException("Apache DocumentRoot has no parent."));
        var buildPath = Path.Combine(webRoot, "VERSION.txt");
        RequireFile(buildPath, "Local VERSION.txt is missing.");
        var build = File.ReadAllText(buildPath).Trim();
        var managedCount = ReadManagedFiles(webRoot).Count;
        return DiscoverAndVerify(build, managedCount);
    }

    /// <summary>Read-only real-host Apache and installed version health gate.</summary>
    public LocalInstallLayout InspectHealthyCurrent()
    {
        var layout = InspectCurrent();
        RequireHttpHealth(layout.Build, layout.HealthUrl);
        return layout;
    }

    public LocalInstallLayout DiscoverAndVerify(string expectedBuild, int expectedManagedFileCount)
    {
        if (!OperatingSystem.IsWindows()) throw new PlatformNotSupportedException("Local slot activation is Windows-only.");
        expectedBuild = RequireExpectedBuild(expectedBuild);
        if (expectedManagedFileCount < 1) throw new InvalidDataException("Expected managed file count must be positive.");

        var apacheExecutable = DiscoverServiceExecutable(ApacheServiceName);
        var (apacheRoot, apacheConfig) = DiscoverApacheConfig(apacheExecutable);
        var documentRoot = DiscoverDocumentRoot(apacheConfig, apacheRoot);
        var healthUrl = DiscoverHealthUrl(apacheConfig, apacheRoot);
        if (!string.Equals(Path.GetFileName(documentRoot.TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar)), "public", StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("SoknaApache DocumentRoot must end in the Local Web public directory.");

        var webRoot = Path.GetFullPath(Path.GetDirectoryName(documentRoot) ?? throw new InvalidDataException("Apache DocumentRoot has no parent."));
        var packageRoot = Path.GetFullPath(Path.GetDirectoryName(webRoot) ?? throw new InvalidDataException("Local Web root has no package root."));
        RequireFile(Path.Combine(packageRoot, "config.php"), "Persistent config.php is missing.");
        RequireFile(Path.Combine(packageRoot, "install.lock"), "Persistent install.lock is missing.");
        var buildPath = Path.Combine(webRoot, "VERSION.txt");
        RequireFile(buildPath, "Local VERSION.txt is missing.");
        var build = File.ReadAllText(buildPath).Trim();
        if (!string.Equals(build, expectedBuild, StringComparison.Ordinal))
            throw new InvalidDataException($"Local build mismatch. Expected '{expectedBuild}', found '{build}'.");

        var managed = ReadManagedFiles(webRoot);
        if (managed.Count != expectedManagedFileCount)
            throw new InvalidDataException($"Managed inventory count mismatch. Expected {expectedManagedFileCount}, found {managed.Count}.");
        foreach (var rel in managed) RequireFile(Path.Combine(webRoot, rel), $"Managed Local file is missing: {rel}");

        var attributes = File.GetAttributes(webRoot);
        var isReparse = (attributes & FileAttributes.ReparsePoint) != 0;
        string? activeTarget = null;
        if (isReparse)
        {
            var resolved = Directory.ResolveLinkTarget(webRoot, returnFinalTarget: true);
            activeTarget = resolved is null ? null : Path.GetFullPath(resolved.FullName);
        }

        return new LocalInstallLayout(packageRoot, webRoot, documentRoot, apacheExecutable, apacheConfig, healthUrl, build, managed.Count, isReparse, activeTarget);
    }

    public LocalSlotHomeCycleResult ConvertAndCycleSameBuild(string expectedBuild, int expectedManagedFileCount)
    {
        var layout = DiscoverAndVerify(expectedBuild, expectedManagedFileCount);
        var managedFiles = ReadManagedFiles(layout.WebRoot);
        var slotRoot = Path.Combine(layout.PackageRoot, "LocalWeb", "releases");
        var persistentRoot = Path.Combine(layout.PackageRoot, "LocalWeb", "persistent");
        var preSlotRoot = Path.Combine(layout.PackageRoot, "LocalWeb", "pre-slot");
        Directory.CreateDirectory(slotRoot);
        Directory.CreateDirectory(persistentRoot);
        Directory.CreateDirectory(preSlotRoot);
        Directory.CreateDirectory(_paths.LocalSlotStateRoot);

        var identity = ShortIdentity(expectedBuild, managedFiles, layout.WebRoot);
        var slotA = Path.Combine(slotRoot, $"{identity}-a");
        var slotB = Path.Combine(slotRoot, $"{identity}-b");
        var events = new List<LocalSlotPhase3Event>();
        string? physicalBackup = null;
        string? activeSlot = layout.ActiveSlotTarget;
        string? previousSlot = null;
        var state = NewState(layout, expectedBuild, slotRoot, slotA, slotB, activeSlot, previousSlot, physicalBackup, events, "DISCOVERED", false);
        SaveState(state);

        VerifyCurrentManagedTree(layout.WebRoot, managedFiles);
        state = Event(state, events, "BASELINE_VERIFIED");
        RequireHttpHealth(expectedBuild, layout.HealthUrl);
        state = Event(state, events, "BASELINE_HTTP_HEALTHY", layout.HealthUrl);

        CaptureMachineBinding(layout.WebRoot, persistentRoot);
        StageSlot(layout.WebRoot, slotA, managedFiles, persistentRoot);
        StageSlot(layout.WebRoot, slotB, managedFiles, persistentRoot);
        state = Event(state, events, "SLOTS_STAGED");
        VerifySlot(slotA, layout.WebRoot, managedFiles, expectedBuild);
        VerifySlot(slotB, layout.WebRoot, managedFiles, expectedBuild);
        state = Event(state, events, "SLOTS_VERIFIED");

        if (!layout.WebIsReparsePoint)
        {
            physicalBackup = Path.Combine(preSlotRoot, "physical-web-" + DateTimeOffset.UtcNow.ToString("yyyyMMddTHHmmssfffZ"));
            state = state with { PhysicalWebBackup = physicalBackup };
            SaveState(state);
            StopApacheAndProveExit(layout.ApacheExecutable);
            state = Event(state, events, "APACHE_STOPPED", "initial conversion");
            try
            {
                Directory.Move(layout.WebRoot, physicalBackup);
                CreateJunction(layout.WebRoot, slotA);
                previousSlot = physicalBackup;
                activeSlot = slotA;
                state = state with { PreviousSlot = previousSlot, ActiveSlot = activeSlot };
                state = Event(state, events, "POINTER_SWITCHED", "physical Web -> slot A");
                StartApache();
                RequireHttpHealth(expectedBuild, layout.HealthUrl);
                state = Event(state, events, "HTTP_HEALTHY", "slot A");
            }
            catch (Exception conversionError)
            {
                try
                {
                    RestorePhysicalWebAndRequireHealth(layout.WebRoot, physicalBackup, expectedBuild, layout.HealthUrl);
                    state = state with { ActiveSlot = null, PreviousSlot = slotA };
                    state = Event(state, events, "ROLLED_BACK", conversionError.Message);
                }
                catch (Exception rollbackError)
                {
                    state = state with { RecoveryRequired = true };
                    state = Event(state, events, "RECOVERY_REQUIRED", rollbackError.Message);
                    throw new AggregateException("Initial Local slot conversion failed and physical-Web rollback health could not be proven.", conversionError, rollbackError);
                }
                throw;
            }
        }
        else
        {
            RequireSlotTarget(layout.ActiveSlotTarget, slotRoot);
            activeSlot = layout.ActiveSlotTarget;
            state = state with { ActiveSlot = activeSlot };
            state = Event(state, events, "ALREADY_SLOT_LAYOUT", activeSlot);
        }

        ActivateSlot(layout, slotB, expectedBuild, slotRoot, state, events, out state);
        ActivateSlot(layout, slotA, expectedBuild, slotRoot, state, events, out state);
        state = Event(state, events, "COMMITTED", "A->B->A same-build Phase 3 cycle passed");
        return new LocalSlotHomeCycleResult(true, expectedBuild, slotA, slotB, slotA, expectedManagedFileCount, false, false, false, _paths.LocalSlotStatePath);
    }

    public LocalSlotPhase3State ActivateSlot(string targetSlot, string expectedBuild, int expectedManagedFileCount)
    {
        var layout = DiscoverAndVerify(expectedBuild, expectedManagedFileCount);
        var slotRoot = Path.Combine(layout.PackageRoot, "LocalWeb", "releases");
        RequireSlotTarget(targetSlot, slotRoot);
        var managed = ReadManagedFiles(targetSlot);
        if (managed.Count != expectedManagedFileCount) throw new InvalidDataException("Target slot managed inventory count mismatch.");
        VerifySlot(targetSlot, targetSlot, managed, expectedBuild);
        var existing = File.Exists(_paths.LocalSlotStatePath)
            ? AtomicFile.ReadJson<LocalSlotPhase3State>(_paths.LocalSlotStatePath)
            : NewState(layout, expectedBuild, slotRoot, targetSlot, targetSlot, layout.ActiveSlotTarget, null, null, new List<LocalSlotPhase3Event>(), "DISCOVERED", false);
        var events = existing.Events.ToList();
        ActivateSlot(layout, targetSlot, expectedBuild, slotRoot, existing, events, out var updated);
        return updated;
    }

    private void ActivateSlot(LocalInstallLayout layout, string targetSlot, string expectedBuild, string slotRoot, LocalSlotPhase3State state, List<LocalSlotPhase3Event> events, out LocalSlotPhase3State updated)
    {
        targetSlot = Path.GetFullPath(targetSlot);
        RequireSlotTarget(targetSlot, slotRoot);
        var currentResolved = Directory.ResolveLinkTarget(layout.WebRoot, returnFinalTarget: true)?.FullName;
        if (string.IsNullOrWhiteSpace(currentResolved)) throw new InvalidDataException("Stable Web entry is not a resolvable junction.");
        var previous = Path.GetFullPath(currentResolved);
        if (PathsEqual(previous, targetSlot))
        {
            updated = Event(state with { ActiveSlot = targetSlot, PreviousSlot = previous }, events, "ACTIVATION_NOOP", targetSlot);
            RequireHttpHealth(expectedBuild, layout.HealthUrl);
            return;
        }

        StopApacheAndProveExit(layout.ApacheExecutable);
        updated = Event(state with { PreviousSlot = previous }, events, "APACHE_STOPPED", $"activate {targetSlot}");
        try
        {
            RemoveJunction(layout.WebRoot);
            CreateJunction(layout.WebRoot, targetSlot);
            updated = updated with { ActiveSlot = targetSlot, PreviousSlot = previous };
            updated = Event(updated, events, "POINTER_SWITCHED", targetSlot);
            StartApache();
            RequireHttpHealth(expectedBuild, layout.HealthUrl);
            updated = Event(updated, events, "HTTP_HEALTHY", targetSlot);
        }
        catch (Exception activationError)
        {
            try
            {
                StopApacheAndProveExit(layout.ApacheExecutable);
                if (Directory.Exists(layout.WebRoot)) RemoveJunction(layout.WebRoot);
                CreateJunction(layout.WebRoot, previous);
                StartApache();
                RequireHttpHealth(expectedBuild, layout.HealthUrl);
                updated = updated with { ActiveSlot = previous, PreviousSlot = targetSlot };
                updated = Event(updated, events, "ROLLED_BACK", activationError.Message);
            }
            catch (Exception rollbackError)
            {
                updated = updated with { RecoveryRequired = true };
                updated = Event(updated, events, "RECOVERY_REQUIRED", rollbackError.Message);
                throw new AggregateException("Local slot activation failed and rollback health could not be proven.", activationError, rollbackError);
            }
            throw;
        }
    }

    private static void StageSlot(string sourceWebRoot, string slot, IReadOnlyList<string> managedFiles, string persistentRoot)
    {
        if (Directory.Exists(slot)) return;
        Directory.CreateDirectory(slot);
        foreach (var rel in managedFiles)
        {
            var source = Path.Combine(sourceWebRoot, rel);
            var target = Path.Combine(slot, rel);
            Directory.CreateDirectory(Path.GetDirectoryName(target)!);
            File.Copy(source, target, overwrite: true);
        }
        foreach (var rel in MachineLocalOverlayPaths)
        {
            var source = Path.Combine(persistentRoot, rel);
            if (!File.Exists(source)) continue;
            var target = Path.Combine(slot, rel);
            Directory.CreateDirectory(Path.GetDirectoryName(target)!);
            File.Copy(source, target, overwrite: true);
        }
    }

    private static void CaptureMachineBinding(string webRoot, string persistentRoot)
    {
        var binding = Path.Combine(webRoot, ".sokna-installation.json");
        if (!File.Exists(binding)) throw new InvalidDataException("Machine-local installation binding is missing before slot conversion.");
        Directory.CreateDirectory(persistentRoot);
        foreach (var rel in MachineLocalOverlayPaths)
        {
            var source = Path.Combine(webRoot, rel);
            if (!File.Exists(source)) continue;
            var target = Path.Combine(persistentRoot, rel);
            Directory.CreateDirectory(Path.GetDirectoryName(target)!);
            File.Copy(source, target, overwrite: true);
        }
    }

    private static void VerifySlot(string slot, string sourceRoot, IReadOnlyList<string> managedFiles, string expectedBuild)
    {
        var slotBuild = File.ReadAllText(Path.Combine(slot, "VERSION.txt")).Trim();
        if (!string.Equals(slotBuild, expectedBuild, StringComparison.Ordinal)) throw new InvalidDataException("Target slot build mismatch.");
        foreach (var rel in managedFiles)
        {
            var expected = Path.Combine(sourceRoot, rel);
            var actual = Path.Combine(slot, rel);
            RequireFile(actual, $"Target slot file is missing: {rel}");
            if (!HashEquals(expected, actual)) throw new InvalidDataException($"Target slot hash mismatch: {rel}");
        }
        RequireFile(Path.Combine(slot, ".sokna-installation.json"), "Target slot machine binding overlay is missing.");
    }

    private static void VerifyCurrentManagedTree(string webRoot, IReadOnlyList<string> managedFiles)
    {
        foreach (var rel in managedFiles) RequireFile(Path.Combine(webRoot, rel), $"Current managed file is missing: {rel}");
    }

    private static IReadOnlyList<string> ReadManagedFiles(string webRoot)
    {
        var path = Path.Combine(webRoot, InventoryRelativePath);
        RequireFile(path, "Current release managed inventory is missing.");
        using var document = JsonDocument.Parse(File.ReadAllBytes(path));
        var root = document.RootElement;
        if (!root.TryGetProperty("format", out var format) || format.GetString() != "sokna-local-managed-files-v1")
            throw new InvalidDataException("Managed inventory format is invalid.");
        if (!root.TryGetProperty("files", out var files) || files.ValueKind != JsonValueKind.Array)
            throw new InvalidDataException("Managed inventory files array is invalid.");
        var result = new List<string>();
        var seen = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (var entry in files.EnumerateArray())
        {
            var rel = entry.GetString() ?? string.Empty;
            rel = rel.Replace('/', Path.DirectorySeparatorChar).TrimStart(Path.DirectorySeparatorChar);
            if (string.IsNullOrWhiteSpace(rel) || Path.IsPathRooted(rel) || rel.Split(Path.DirectorySeparatorChar).Any(static p => p is ".." or "." or ""))
                throw new InvalidDataException("Managed inventory contains an unsafe relative path.");
            if (!seen.Add(rel)) throw new InvalidDataException($"Managed inventory contains a duplicate path: {rel}");
            result.Add(rel);
        }
        return result;
    }

    private static string ShortIdentity(string expectedBuild, IReadOnlyList<string> managedFiles, string webRoot)
    {
        using var sha = SHA256.Create();
        foreach (var rel in managedFiles.OrderBy(static x => x, StringComparer.Ordinal))
        {
            var text = rel.Replace('\\', '/') + "\0" + Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(Path.Combine(webRoot, rel)))).ToLowerInvariant() + "\n";
            var bytes = System.Text.Encoding.UTF8.GetBytes(text);
            sha.TransformBlock(bytes, 0, bytes.Length, null, 0);
        }
        sha.TransformFinalBlock(Array.Empty<byte>(), 0, 0);
        var digest = Convert.ToHexString(sha.Hash!).ToLowerInvariant()[..12];
        var versionToken = Regex.Match(expectedBuild, @"\.(\d+[a-z]+)(?:-|$)", RegexOptions.IgnoreCase).Groups[1].Value;
        if (string.IsNullOrWhiteSpace(versionToken)) versionToken = "local";
        return $"{versionToken}-{digest}".ToLowerInvariant();
    }

    private void StopApacheAndProveExit(string apacheExecutable)
    {
        using var service = new ServiceController(ApacheServiceName);
        service.Refresh();
        if (service.Status != ServiceControllerStatus.Stopped)
        {
            if (service.Status != ServiceControllerStatus.StopPending) service.Stop();
            service.WaitForStatus(ServiceControllerStatus.Stopped, _serviceTimeout);
        }
        var deadline = DateTimeOffset.UtcNow + _serviceTimeout;
        while (DateTimeOffset.UtcNow < deadline)
        {
            if (!AnyProcessUsingExecutable(apacheExecutable)) return;
            Thread.Sleep(200);
        }
        throw new TimeoutException("SoknaApache service stopped but Apache/PHP process exit could not be proven.");
    }

    private void StartApache()
    {
        using var service = new ServiceController(ApacheServiceName);
        service.Refresh();
        if (service.Status != ServiceControllerStatus.Running)
        {
            if (service.Status != ServiceControllerStatus.StartPending) service.Start();
            service.WaitForStatus(ServiceControllerStatus.Running, _serviceTimeout);
        }
    }

    private void RequireHttpHealth(string expectedBuild, string healthUrl)
    {
        if (!Uri.TryCreate(healthUrl, UriKind.Absolute, out var baseUri) || baseUri.Scheme != Uri.UriSchemeHttp || !baseUri.IsLoopback)
            throw new InvalidDataException("Local health URL must be absolute loopback HTTP.");
        var deadline = DateTimeOffset.UtcNow + _healthTimeout;
        Exception? last = null;
        while (DateTimeOffset.UtcNow < deadline)
        {
            try
            {
                using var handler = new HttpClientHandler { UseProxy = false, AllowAutoRedirect = false };
                using var client = new HttpClient(handler) { Timeout = TimeSpan.FromSeconds(4) };
                var builder = new UriBuilder(baseUri) { Query = "p3=" + Guid.NewGuid().ToString("N") };
                using var request = new HttpRequestMessage(HttpMethod.Get, builder.Uri);
                request.Headers.CacheControl = new System.Net.Http.Headers.CacheControlHeaderValue { NoCache = true, NoStore = true };
                using var response = client.Send(request);
                var build = response.Headers.TryGetValues("X-Sokna-Build", out var builds) ? builds.SingleOrDefault() : null;
                var runtime = response.Headers.TryGetValues("X-Sokna-Runtime", out var runtimes) ? runtimes.SingleOrDefault() : null;
                if (response.IsSuccessStatusCode && string.Equals(build, expectedBuild, StringComparison.Ordinal) && string.Equals(runtime, "php-ready", StringComparison.Ordinal)) return;
                last = new InvalidDataException($"HTTP health mismatch at '{baseUri}'. status={(int)response.StatusCode}, build='{build}', runtime='{runtime}'.");
            }
            catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException or InvalidDataException)
            {
                last = ex;
            }
            Thread.Sleep(250);
        }
        throw new InvalidDataException($"Target Local HTTP health was not proven at '{baseUri}' within the Phase 3 timeout. Last: {last?.Message ?? "no response"}", last);
    }

    private static bool AnyProcessUsingExecutable(string executable)
    {
        var expected = Path.GetFullPath(executable);
        var processName = Path.GetFileNameWithoutExtension(expected);
        foreach (var process in Process.GetProcessesByName(processName))
        {
            using (process)
            {
                try
                {
                    var actual = process.MainModule?.FileName;
                    if (!string.IsNullOrWhiteSpace(actual) && PathsEqual(actual, expected)) return true;
                }
                catch (Exception ex) when (ex is System.ComponentModel.Win32Exception or InvalidOperationException)
                {
                    return true;
                }
            }
        }
        return false;
    }

    private static string DiscoverServiceExecutable(string serviceName)
    {
        using var key = Registry.LocalMachine.OpenSubKey($@"SYSTEM\CurrentControlSet\Services\{serviceName}")
                        ?? throw new InvalidDataException($"Windows service '{serviceName}' is not installed.");
        var imagePath = key.GetValue("ImagePath") as string;
        if (string.IsNullOrWhiteSpace(imagePath)) throw new InvalidDataException($"Windows service '{serviceName}' has no ImagePath.");
        var text = Environment.ExpandEnvironmentVariables(imagePath.Trim());
        string executable;
        if (text.StartsWith('"'))
        {
            var end = text.IndexOf('"', 1);
            if (end < 2) throw new InvalidDataException("SoknaApache ImagePath quoting is invalid.");
            executable = text[1..end];
        }
        else
        {
            var exe = text.IndexOf(".exe", StringComparison.OrdinalIgnoreCase);
            executable = exe >= 0 ? text[..(exe + 4)].Trim() : text.Split(' ', 2)[0];
        }
        executable = Path.GetFullPath(executable);
        RequireFile(executable, "SoknaApache executable from service ImagePath does not exist.");
        return executable;
    }

    private static (string ApacheRoot, string ConfigPath) DiscoverApacheConfig(string apacheExecutable)
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
                RedirectStandardError = true,
                RedirectStandardInput = true
            };
            psi.ArgumentList.Add("-V");
            using var process = Process.Start(psi);
            if (process is not null)
            {
                // Never synchronously ReadToEnd BEFORE WaitForExit: a malformed
                // service ImagePath (e.g. interactive cmd.exe -V) can hang the
                // entire signed Supervisor request without honoring timeout.
                var stdoutTask = process.StandardOutput.ReadToEndAsync();
                var stderrTask = process.StandardError.ReadToEndAsync();
                process.StandardInput.Close();
                if (!process.WaitForExit(4_000))
                {
                    try { process.Kill(entireProcessTree: true); } catch { }
                    throw new TimeoutException("SoknaApache -V probe timed out.");
                }
                if (!Task.WhenAll(stdoutTask, stderrTask).Wait(1_000))
                    throw new TimeoutException("SoknaApache -V output stream did not close.");
                var text = stdoutTask.Result + "\n" + stderrTask.Result;
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

    private static string? ExtractApacheDefine(string text, string name)
    {
        var match = Regex.Match(text, $"-D\\s+{Regex.Escape(name)}=\"([^\"]+)\"", RegexOptions.IgnoreCase);
        return match.Success ? match.Groups[1].Value : null;
    }

    private static string DiscoverDocumentRoot(string apacheConfig, string apacheRoot)
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

    private static string DiscoverHealthUrl(string apacheConfig, string apacheRoot)
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

    private static void CreateJunction(string linkPath, string targetPath)
    {
        targetPath = Path.GetFullPath(targetPath);
        if (!Directory.Exists(targetPath)) throw new DirectoryNotFoundException($"Junction target does not exist: {targetPath}");
        if (Directory.Exists(linkPath) || File.Exists(linkPath)) throw new IOException($"Junction path already exists: {linkPath}");
        var cmd = Path.Combine(Environment.SystemDirectory, "cmd.exe");
        var psi = new ProcessStartInfo
        {
            FileName = cmd,
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true
        };
        psi.ArgumentList.Add("/d");
        psi.ArgumentList.Add("/c");
        psi.ArgumentList.Add("mklink");
        psi.ArgumentList.Add("/J");
        psi.ArgumentList.Add(linkPath);
        psi.ArgumentList.Add(targetPath);
        using var process = Process.Start(psi) ?? throw new InvalidOperationException("Unable to create Local Web junction.");
        var stdout = process.StandardOutput.ReadToEnd();
        var stderr = process.StandardError.ReadToEnd();
        if (!process.WaitForExit(15_000))
        {
            try { process.Kill(entireProcessTree: true); } catch { }
            throw new TimeoutException("Junction creation timed out.");
        }
        if (process.ExitCode != 0) throw new IOException($"Junction creation failed: {stderr} {stdout}".Trim());
        var resolved = Directory.ResolveLinkTarget(linkPath, returnFinalTarget: true)?.FullName;
        if (string.IsNullOrWhiteSpace(resolved) || !PathsEqual(resolved, targetPath))
            throw new IOException("Created Local Web junction did not resolve to the requested target.");
    }

    private static void RemoveJunction(string linkPath)
    {
        if (!Directory.Exists(linkPath)) return;
        var attrs = File.GetAttributes(linkPath);
        if ((attrs & FileAttributes.ReparsePoint) == 0) throw new IOException("Refusing to remove a non-junction stable Web directory.");
        Directory.Delete(linkPath, recursive: false);
    }

    private static void RequireSlotTarget(string? slot, string slotRoot)
    {
        if (string.IsNullOrWhiteSpace(slot)) throw new InvalidDataException("Active/target Local slot is empty.");
        var root = Path.GetFullPath(slotRoot).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
        var full = Path.GetFullPath(slot).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
        if (!full.StartsWith(root, StringComparison.OrdinalIgnoreCase)) throw new InvalidDataException("Local slot target escapes the allowed release-slot root.");
        if (!Directory.Exists(slot)) throw new DirectoryNotFoundException("Local slot target does not exist.");
    }

    private void RestorePhysicalWebAndRequireHealth(string webRoot, string physicalBackup, string expectedBuild, string healthUrl)
    {
        if (Directory.Exists(webRoot))
        {
            var attrs = File.GetAttributes(webRoot);
            if ((attrs & FileAttributes.ReparsePoint) != 0)
            {
                Directory.Delete(webRoot, false);
            }
            else if (!PathsEqual(webRoot, physicalBackup))
            {
                throw new IOException("Cannot restore physical Web because stable Web path is occupied by a non-junction directory.");
            }
        }
        if (!Directory.Exists(webRoot))
        {
            if (!Directory.Exists(physicalBackup)) throw new DirectoryNotFoundException("Physical Web backup is unavailable for rollback.");
            Directory.Move(physicalBackup, webRoot);
        }
        StartApache();
        RequireHttpHealth(expectedBuild, healthUrl);
    }

    private LocalSlotPhase3State NewState(LocalInstallLayout layout, string expectedBuild, string slotRoot, string slotA, string slotB, string? activeSlot, string? previousSlot, string? physicalBackup, List<LocalSlotPhase3Event> events, string phase, bool recoveryRequired)
    {
        events.Add(new LocalSlotPhase3Event(phase, DateTimeOffset.UtcNow));
        return new LocalSlotPhase3State("sokna-local-slot-phase3-state-v1", 1, phase, expectedBuild, layout.PackageRoot, layout.WebRoot, slotRoot, slotA, slotB, activeSlot, previousSlot, physicalBackup, false, false, false, recoveryRequired, DateTimeOffset.UtcNow, events.ToArray());
    }

    private LocalSlotPhase3State Event(LocalSlotPhase3State state, List<LocalSlotPhase3Event> events, string phase, string? detail = null)
    {
        events.Add(new LocalSlotPhase3Event(phase, DateTimeOffset.UtcNow, detail));
        state = state with { Phase = phase, UpdatedAtUtc = DateTimeOffset.UtcNow, Events = events.ToArray() };
        SaveState(state);
        return state;
    }

    private void SaveState(LocalSlotPhase3State state)
    {
        Directory.CreateDirectory(_paths.LocalSlotStateRoot);
        AtomicFile.WriteJson(_paths.LocalSlotStatePath, state);
    }

    private static void RequireFile(string path, string message)
    {
        if (!File.Exists(path)) throw new FileNotFoundException(message, path);
    }

    private static bool HashEquals(string first, string second)
    {
        var left = SHA256.HashData(File.ReadAllBytes(first));
        var right = SHA256.HashData(File.ReadAllBytes(second));
        return CryptographicOperations.FixedTimeEquals(left, right);
    }

    private static bool PathsEqual(string first, string second)
        => string.Equals(Path.GetFullPath(first).TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar), Path.GetFullPath(second).TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar), StringComparison.OrdinalIgnoreCase);

    private static string RequireExpectedBuild(string value)
    {
        value = value.Trim();
        if (string.IsNullOrWhiteSpace(value) || value.Length > 180 || value.IndexOfAny(Path.GetInvalidFileNameChars()) >= 0)
            throw new InvalidDataException("Expected Local build identity is invalid.");
        return value;
    }
}
