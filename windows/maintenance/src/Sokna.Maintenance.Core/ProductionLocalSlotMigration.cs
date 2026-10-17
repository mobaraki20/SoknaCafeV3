using System.Diagnostics;
using System.Security.Cryptography;
using System.ServiceProcess;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Text.RegularExpressions;

namespace Sokna.Maintenance.Core;

public sealed record ProductionLocalSlotMigrationEvent(
    [property: JsonPropertyName("state")] string State,
    [property: JsonPropertyName("at_utc")] DateTimeOffset AtUtc,
    [property: JsonPropertyName("detail")] string? Detail = null);

public sealed record ProductionLocalSlotMigrationState(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("schema_version")] int SchemaVersion,
    [property: JsonPropertyName("phase")] string Phase,
    [property: JsonPropertyName("expected_build")] string ExpectedBuild,
    [property: JsonPropertyName("expected_managed_files")] int ExpectedManagedFiles,
    [property: JsonPropertyName("expected_managed_tree_sha256")] string ExpectedManagedTreeSha256,
    [property: JsonPropertyName("package_root")] string PackageRoot,
    [property: JsonPropertyName("stable_web_root")] string StableWebRoot,
    [property: JsonPropertyName("apache_executable")] string ApacheExecutable,
    [property: JsonPropertyName("health_url")] string HealthUrl,
    [property: JsonPropertyName("release_root")] string ReleaseRoot,
    [property: JsonPropertyName("target_slot")] string TargetSlot,
    [property: JsonPropertyName("physical_web_backup")] string? PhysicalWebBackup,
    [property: JsonPropertyName("active_slot")] string? ActiveSlot,
    [property: JsonPropertyName("previous_slot")] string? PreviousSlot,
    [property: JsonPropertyName("activation_performed")] bool ActivationPerformed,
    [property: JsonPropertyName("database_mutation_performed")] bool DatabaseMutationPerformed,
    [property: JsonPropertyName("public_mutation_performed")] bool PublicMutationPerformed,
    [property: JsonPropertyName("windows_reboot_required")] bool WindowsRebootRequired,
    [property: JsonPropertyName("recovery_required")] bool RecoveryRequired,
    [property: JsonPropertyName("updated_at_utc")] DateTimeOffset UpdatedAtUtc,
    [property: JsonPropertyName("events")] IReadOnlyList<ProductionLocalSlotMigrationEvent> Events);

public sealed record ProductionLocalSlotMigrationResult(
    [property: JsonPropertyName("ok")] bool Ok,
    [property: JsonPropertyName("phase")] string Phase,
    [property: JsonPropertyName("target_slot")] string TargetSlot,
    [property: JsonPropertyName("active_slot")] string? ActiveSlot,
    [property: JsonPropertyName("physical_web_backup")] string? PhysicalWebBackup,
    [property: JsonPropertyName("managed_tree_sha256")] string ManagedTreeSha256,
    [property: JsonPropertyName("activation_performed")] bool ActivationPerformed,
    [property: JsonPropertyName("database_mutation_performed")] bool DatabaseMutationPerformed,
    [property: JsonPropertyName("public_mutation_performed")] bool PublicMutationPerformed,
    [property: JsonPropertyName("windows_reboot_required")] bool WindowsRebootRequired,
    [property: JsonPropertyName("recovery_required")] bool RecoveryRequired,
    [property: JsonPropertyName("state_path")] string StatePath);

/// <summary>
/// One-time production bridge from the legacy physical D:\SOKNA\Web layout to the
/// stable Web junction + immutable LocalWeb\releases layout. This bridge does not
/// change Local application bytes, mutate Local/Public databases, or require a reboot.
/// </summary>
public sealed class ProductionLocalSlotMigrationBridge
{
    private const string ApacheServiceName = LocalSlotManager.ApacheServiceName;
    private const string InventoryRelativePath = "resources\\local-managed-files-v1.json";
    private static readonly string[] MachineLocalOverlayPaths =
    {
        ".sokna-installation.json",
        "public\\local-recovery.php",
        "public\\local-update-runner.php"
    };

    private readonly MaintenancePaths _paths;
    private readonly LocalSlotManager _discovery;
    private readonly TimeSpan _serviceTimeout;
    private readonly TimeSpan _healthTimeout;
    private readonly Action<string>? _checkpoint;

    public ProductionLocalSlotMigrationBridge(
        MaintenancePaths paths,
        TimeSpan? serviceTimeout = null,
        TimeSpan? healthTimeout = null,
        Action<string>? checkpoint = null)
    {
        _paths = paths;
        _discovery = new LocalSlotManager(paths, serviceTimeout, healthTimeout);
        _serviceTimeout = serviceTimeout ?? TimeSpan.FromSeconds(35);
        _healthTimeout = healthTimeout ?? TimeSpan.FromSeconds(35);
        _checkpoint = checkpoint;
    }

    public string StatePath => Path.Combine(_paths.LocalSlotStateRoot, "migration-state.json");

    public ProductionLocalSlotMigrationResult Migrate(
        string expectedBuild,
        int expectedManagedFiles,
        string expectedManagedTreeSha256)
    {
        expectedBuild = NormalizeBuild(expectedBuild);
        ValidateManagedCount(expectedManagedFiles);
        expectedManagedTreeSha256 = LocalManagedTreeAttestation.NormalizeSha256(expectedManagedTreeSha256);

        var layout = _discovery.DiscoverAndVerify(expectedBuild, expectedManagedFiles);
        var actualTree = LocalManagedTreeAttestation.RequireExact(layout.WebRoot, expectedManagedFiles, expectedManagedTreeSha256);
        RequireHttpHealth(expectedBuild, layout.HealthUrl);

        var releaseRoot = Path.Combine(layout.PackageRoot, "LocalWeb", "releases");
        var persistentRoot = Path.Combine(layout.PackageRoot, "LocalWeb", "persistent");
        var preSlotRoot = Path.Combine(layout.PackageRoot, "LocalWeb", "pre-slot");
        Directory.CreateDirectory(releaseRoot);
        Directory.CreateDirectory(persistentRoot);
        Directory.CreateDirectory(preSlotRoot);
        Directory.CreateDirectory(_paths.LocalSlotStateRoot);

        var targetSlot = Path.Combine(releaseRoot, BuildProductionSlotName(expectedBuild, expectedManagedTreeSha256));
        var priorPhysicalBackup = (string?)null;
        var priorPreviousSlot = (string?)null;
        try
        {
            if (File.Exists(StatePath))
            {
                var prior = AtomicFile.ReadJson<ProductionLocalSlotMigrationState>(StatePath);
                if (string.Equals(prior.ExpectedBuild, expectedBuild, StringComparison.Ordinal) &&
                    prior.ExpectedManagedFiles == expectedManagedFiles &&
                    string.Equals(prior.ExpectedManagedTreeSha256, expectedManagedTreeSha256, StringComparison.OrdinalIgnoreCase))
                {
                    priorPhysicalBackup = prior.PhysicalWebBackup;
                    priorPreviousSlot = prior.PreviousSlot;
                }
            }
        }
        catch
        {
            // A malformed prior production state must not be silently trusted. The new
            // migration attempt will establish fresh state from the verified baseline.
        }

        var events = new List<ProductionLocalSlotMigrationEvent>();
        var state = NewState(layout, expectedBuild, expectedManagedFiles, expectedManagedTreeSha256, releaseRoot, targetSlot, priorPhysicalBackup, priorPreviousSlot, events);
        state = Event(state, events, "BASELINE_VERIFIED", $"tree={actualTree}");
        state = Event(state, events, "BASELINE_HTTP_HEALTHY", layout.HealthUrl);
        Checkpoint("after_baseline_health");

        ArchiveLegacyPhase3State(events);
        state = SaveWithEvents(state, events);

        if (layout.WebIsReparsePoint)
        {
            RequireSlotUnderRoot(layout.ActiveSlotTarget, releaseRoot);
            var activeSlot = Path.GetFullPath(layout.ActiveSlotTarget!);
            var activeTree = LocalManagedTreeAttestation.RequireExact(activeSlot, expectedManagedFiles, expectedManagedTreeSha256);
            RequireHttpHealth(expectedBuild, layout.HealthUrl);
            state = state with
            {
                TargetSlot = activeSlot,
                ActiveSlot = activeSlot,
                PreviousSlot = state.PreviousSlot ?? activeSlot,
                ActivationPerformed = false,
                RecoveryRequired = false
            };
            state = Event(state, events, "ALREADY_MIGRATED", $"slot={activeSlot};tree={activeTree}");
            state = Event(state, events, "COMMITTED", "Stable Web already points to a verified release slot.");
            return Result(state, actualTree);
        }

        var managedFiles = ReadManagedFiles(layout.WebRoot, expectedManagedFiles);
        CaptureMachineLocalFiles(layout.WebRoot, persistentRoot);
        StageProductionSlot(layout.WebRoot, targetSlot, managedFiles, persistentRoot);
        VerifyStagedSlot(targetSlot, expectedBuild, expectedManagedFiles, expectedManagedTreeSha256);
        state = Event(state, events, "SLOT_STAGED_VERIFIED", targetSlot);
        Checkpoint("after_slot_staged");

        var physicalBackup = Path.Combine(preSlotRoot, "physical-web-" + DateTimeOffset.UtcNow.ToString("yyyyMMddTHHmmssfffZ"));
        state = state with { PhysicalWebBackup = physicalBackup };
        state = Event(state, events, "READY_TO_ACTIVATE", physicalBackup);

        StopApacheAndProveExit(layout.ApacheExecutable);
        state = Event(state, events, "APACHE_STOPPED", "production migration");
        Checkpoint("after_apache_stop");

        try
        {
            Directory.Move(layout.WebRoot, physicalBackup);
            state = Event(state, events, "PHYSICAL_WEB_BACKED_UP", physicalBackup);
            Checkpoint("after_physical_move");

            CreateJunction(layout.WebRoot, targetSlot);
            state = state with
            {
                ActiveSlot = targetSlot,
                PreviousSlot = physicalBackup,
                ActivationPerformed = true
            };
            state = Event(state, events, "POINTER_SWITCHED", targetSlot);
            Checkpoint("after_pointer_switch");

            StartApache();
            state = Event(state, events, "APACHE_STARTED", targetSlot);
            Checkpoint("after_apache_start");
            RequireHttpHealth(expectedBuild, layout.HealthUrl);
            var finalLayout = _discovery.DiscoverAndVerify(expectedBuild, expectedManagedFiles);
            RequireSlotUnderRoot(finalLayout.ActiveSlotTarget, releaseRoot);
            var finalTree = LocalManagedTreeAttestation.RequireExact(finalLayout.WebRoot, expectedManagedFiles, expectedManagedTreeSha256);
            state = state with { RecoveryRequired = false, ActiveSlot = finalLayout.ActiveSlotTarget };
            state = Event(state, events, "HTTP_HEALTHY", layout.HealthUrl);
            state = Event(state, events, "COMMITTED", $"tree={finalTree}");
            return Result(state, finalTree);
        }
        catch (Exception activationError)
        {
            try
            {
                RestorePhysicalWebAndRequireHealth(layout, physicalBackup, expectedBuild, expectedManagedFiles, expectedManagedTreeSha256);
                state = state with
                {
                    ActiveSlot = null,
                    PreviousSlot = targetSlot,
                    ActivationPerformed = true,
                    RecoveryRequired = false
                };
                state = Event(state, events, "ROLLED_BACK", activationError.Message);
            }
            catch (Exception rollbackError)
            {
                state = state with { RecoveryRequired = true, ActivationPerformed = true };
                state = Event(state, events, "RECOVERY_REQUIRED", rollbackError.Message);
                throw new AggregateException(
                    "Production Local slot migration failed and automatic physical-Web rollback health could not be proven.",
                    activationError,
                    rollbackError);
            }

            throw;
        }
    }

    public ProductionLocalSlotMigrationResult Recover()
    {
        if (!File.Exists(StatePath)) throw new FileNotFoundException("Production Local migration state is unavailable.", StatePath);
        var state = AtomicFile.ReadJson<ProductionLocalSlotMigrationState>(StatePath);
        if (state.DatabaseMutationPerformed || state.PublicMutationPerformed)
            throw new InvalidDataException("Automatic code-layout recovery is forbidden after database/Public mutation.");

        var events = state.Events.ToList();

        // Recovery must not require Local Web to be healthy. If the stable entry is present
        // and healthy, reconcile state without mutation; otherwise restore from durable
        // physical-Web backup using Apache/health facts captured before activation.
        if (Directory.Exists(state.StableWebRoot))
        {
            try
            {
                var currentLayout = _discovery.DiscoverAndVerify(state.ExpectedBuild, state.ExpectedManagedFiles);
                var currentTree = LocalManagedTreeAttestation.RequireExact(currentLayout.WebRoot, state.ExpectedManagedFiles, state.ExpectedManagedTreeSha256);
                RequireHttpHealth(state.ExpectedBuild, currentLayout.HealthUrl);
                state = state with { RecoveryRequired = false, ActiveSlot = currentLayout.ActiveSlotTarget };
                state = Event(state, events, currentLayout.WebIsReparsePoint ? "RECOVERED_TO_VERIFIED_SLOT" : "RECOVERED_TO_PHYSICAL_WEB", currentLayout.WebRoot);
                return Result(state, currentTree);
            }
            catch (Exception ex) when (ex is InvalidDataException or IOException or UnauthorizedAccessException)
            {
                state = Event(state, events, "RECOVERY_CURRENT_LAYOUT_UNHEALTHY", ex.Message);
            }
        }

        var backup = state.PhysicalWebBackup;
        if (string.IsNullOrWhiteSpace(backup) || !Directory.Exists(backup))
            throw new DirectoryNotFoundException("Production Local migration physical-Web backup is unavailable for recovery.");

        StopApacheAndProveExit(state.ApacheExecutable);
        state = Event(state, events, "RECOVERY_APACHE_STOPPED", backup);

        if (Directory.Exists(state.StableWebRoot))
        {
            var attributes = File.GetAttributes(state.StableWebRoot);
            if ((attributes & FileAttributes.ReparsePoint) != 0)
            {
                RemoveJunction(state.StableWebRoot);
            }
            else
            {
                throw new IOException("Refusing recovery because stable Web is occupied by an unhealthy physical directory.");
            }
        }

        Directory.Move(backup, state.StableWebRoot);
        StartApache();
        RequireHttpHealth(state.ExpectedBuild, state.HealthUrl);
        var restoredLayout = _discovery.DiscoverAndVerify(state.ExpectedBuild, state.ExpectedManagedFiles);
        if (restoredLayout.WebIsReparsePoint) throw new InvalidDataException("Recovery expected a physical Web directory but found a reparse point.");
        var restoredTree = LocalManagedTreeAttestation.RequireExact(restoredLayout.WebRoot, state.ExpectedManagedFiles, state.ExpectedManagedTreeSha256);
        state = state with
        {
            ActiveSlot = null,
            PreviousSlot = state.TargetSlot,
            RecoveryRequired = false
        };
        state = Event(state, events, "RECOVERED_TO_PHYSICAL_WEB", $"tree={restoredTree}");
        return Result(state, restoredTree);
    }

    private void RestorePhysicalWebAndRequireHealth(
        LocalInstallLayout layout,
        string physicalBackup,
        string expectedBuild,
        int expectedManagedFiles,
        string expectedManagedTreeSha256)
    {
        try { StopApacheAndProveExit(layout.ApacheExecutable); } catch { }

        if (Directory.Exists(layout.WebRoot))
        {
            var attributes = File.GetAttributes(layout.WebRoot);
            if ((attributes & FileAttributes.ReparsePoint) != 0)
                RemoveJunction(layout.WebRoot);
            else
                throw new IOException("Cannot restore physical Web because stable Web is occupied by a non-junction directory.");
        }

        if (!Directory.Exists(physicalBackup))
            throw new DirectoryNotFoundException("Physical Web backup is unavailable for rollback.");

        Directory.Move(physicalBackup, layout.WebRoot);
        StartApache();
        RequireHttpHealth(expectedBuild, layout.HealthUrl);
        var restored = _discovery.DiscoverAndVerify(expectedBuild, expectedManagedFiles);
        if (restored.WebIsReparsePoint) throw new InvalidDataException("Automatic rollback did not restore the physical Web layout.");
        LocalManagedTreeAttestation.RequireExact(restored.WebRoot, expectedManagedFiles, expectedManagedTreeSha256);
    }

    private void ArchiveLegacyPhase3State(List<ProductionLocalSlotMigrationEvent> events)
    {
        if (!File.Exists(_paths.LocalSlotStatePath)) return;
        var historyRoot = Path.Combine(_paths.LocalSlotStateRoot, "history");
        Directory.CreateDirectory(historyRoot);
        var history = Path.Combine(historyRoot, "phase3-state-" + DateTimeOffset.UtcNow.ToString("yyyyMMddTHHmmssfffZ") + ".json");
        File.Move(_paths.LocalSlotStatePath, history);
        events.Add(new ProductionLocalSlotMigrationEvent("LEGACY_PHASE3_STATE_ARCHIVED", DateTimeOffset.UtcNow, history));
    }

    private static IReadOnlyList<string> ReadManagedFiles(string webRoot, int expectedManagedFiles)
    {
        var inventoryPath = Path.Combine(webRoot, InventoryRelativePath);
        if (!File.Exists(inventoryPath)) throw new FileNotFoundException("Current release managed inventory is missing.", inventoryPath);
        using var document = JsonDocument.Parse(File.ReadAllBytes(inventoryPath));
        var root = document.RootElement;
        if (!root.TryGetProperty("format", out var format) || format.GetString() != "sokna-local-managed-files-v1")
            throw new InvalidDataException("Managed inventory format is invalid.");
        if (!root.TryGetProperty("files", out var files) || files.ValueKind != JsonValueKind.Array)
            throw new InvalidDataException("Managed inventory files array is invalid.");

        var result = new List<string>();
        var seen = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (var entry in files.EnumerateArray())
        {
            var rel = (entry.GetString() ?? string.Empty).Replace('/', Path.DirectorySeparatorChar).TrimStart(Path.DirectorySeparatorChar);
            if (string.IsNullOrWhiteSpace(rel) || Path.IsPathRooted(rel) || rel.Split(Path.DirectorySeparatorChar).Any(static p => p is "" or "." or ".."))
                throw new InvalidDataException("Managed inventory contains an unsafe relative path.");
            if (!seen.Add(rel)) throw new InvalidDataException($"Managed inventory contains a duplicate path: {rel}");
            result.Add(rel);
        }
        if (result.Count != expectedManagedFiles)
            throw new InvalidDataException($"Managed inventory count mismatch. Expected {expectedManagedFiles}, found {result.Count}.");
        return result;
    }

    private static void CaptureMachineLocalFiles(string webRoot, string persistentRoot)
    {
        var binding = Path.Combine(webRoot, ".sokna-installation.json");
        if (!File.Exists(binding)) throw new InvalidDataException("Machine-local installation binding is missing before production slot migration.");
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

    private static void StageProductionSlot(string sourceWebRoot, string targetSlot, IReadOnlyList<string> managedFiles, string persistentRoot)
    {
        if (Directory.Exists(targetSlot)) return;
        Directory.CreateDirectory(targetSlot);
        foreach (var rel in managedFiles)
        {
            var source = Path.Combine(sourceWebRoot, rel);
            if (!File.Exists(source)) throw new FileNotFoundException($"Managed Local file is missing: {rel}", source);
            var target = Path.Combine(targetSlot, rel);
            Directory.CreateDirectory(Path.GetDirectoryName(target)!);
            File.Copy(source, target, overwrite: false);
        }
        foreach (var rel in MachineLocalOverlayPaths)
        {
            var source = Path.Combine(persistentRoot, rel);
            if (!File.Exists(source)) continue;
            var target = Path.Combine(targetSlot, rel);
            Directory.CreateDirectory(Path.GetDirectoryName(target)!);
            File.Copy(source, target, overwrite: true);
        }
    }

    private static void VerifyStagedSlot(string targetSlot, string expectedBuild, int expectedManagedFiles, string expectedManagedTreeSha256)
    {
        var versionPath = Path.Combine(targetSlot, "VERSION.txt");
        if (!File.Exists(versionPath)) throw new FileNotFoundException("Staged production slot VERSION.txt is missing.", versionPath);
        var build = File.ReadAllText(versionPath).Trim();
        if (!string.Equals(build, expectedBuild, StringComparison.Ordinal))
            throw new InvalidDataException($"Staged production slot build mismatch. Expected '{expectedBuild}', found '{build}'.");
        LocalManagedTreeAttestation.RequireExact(targetSlot, expectedManagedFiles, expectedManagedTreeSha256);
        if (!File.Exists(Path.Combine(targetSlot, ".sokna-installation.json")))
            throw new InvalidDataException("Staged production slot machine-local binding overlay is missing.");
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
        throw new TimeoutException("SoknaApache service stopped but Apache process exit could not be proven.");
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
                var builder = new UriBuilder(baseUri) { Query = "migration=" + Guid.NewGuid().ToString("N") };
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
        throw new InvalidDataException($"Local HTTP health was not proven at '{baseUri}' within the migration timeout. Last: {last?.Message ?? "no response"}", last);
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

    private static void CreateJunction(string linkPath, string targetPath)
    {
        targetPath = Path.GetFullPath(targetPath);
        if (!Directory.Exists(targetPath)) throw new DirectoryNotFoundException($"Junction target does not exist: {targetPath}");
        if (Directory.Exists(linkPath) || File.Exists(linkPath)) throw new IOException($"Junction path already exists: {linkPath}");

        var psi = new ProcessStartInfo
        {
            FileName = Path.Combine(Environment.SystemDirectory, "cmd.exe"),
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
        var attributes = File.GetAttributes(linkPath);
        if ((attributes & FileAttributes.ReparsePoint) == 0) throw new IOException("Refusing to remove a non-junction stable Web directory.");
        Directory.Delete(linkPath, recursive: false);
    }

    private static void RequireSlotUnderRoot(string? slot, string releaseRoot)
    {
        if (string.IsNullOrWhiteSpace(slot)) throw new InvalidDataException("Active Local slot is empty.");
        var root = Path.GetFullPath(releaseRoot).TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar) + Path.DirectorySeparatorChar;
        var full = Path.GetFullPath(slot).TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar) + Path.DirectorySeparatorChar;
        if (!full.StartsWith(root, StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("Active Local slot escapes the production release root.");
        if (!Directory.Exists(slot)) throw new DirectoryNotFoundException("Active Local slot does not exist.");
    }

    private ProductionLocalSlotMigrationState NewState(
        LocalInstallLayout layout,
        string expectedBuild,
        int expectedManagedFiles,
        string expectedManagedTreeSha256,
        string releaseRoot,
        string targetSlot,
        string? priorPhysicalBackup,
        string? priorPreviousSlot,
        List<ProductionLocalSlotMigrationEvent> events)
    {
        events.Add(new ProductionLocalSlotMigrationEvent("DISCOVERED", DateTimeOffset.UtcNow));
        var state = new ProductionLocalSlotMigrationState(
            "sokna-local-slot-migration-state-v1",
            1,
            "DISCOVERED",
            expectedBuild,
            expectedManagedFiles,
            expectedManagedTreeSha256,
            layout.PackageRoot,
            layout.WebRoot,
            layout.ApacheExecutable,
            layout.HealthUrl,
            releaseRoot,
            targetSlot,
            priorPhysicalBackup,
            layout.ActiveSlotTarget,
            priorPreviousSlot,
            false,
            false,
            false,
            false,
            false,
            DateTimeOffset.UtcNow,
            events.ToArray());
        SaveState(state);
        return state;
    }

    private ProductionLocalSlotMigrationState Event(
        ProductionLocalSlotMigrationState state,
        List<ProductionLocalSlotMigrationEvent> events,
        string phase,
        string? detail = null)
    {
        events.Add(new ProductionLocalSlotMigrationEvent(phase, DateTimeOffset.UtcNow, detail));
        state = state with { Phase = phase, UpdatedAtUtc = DateTimeOffset.UtcNow, Events = events.ToArray() };
        SaveState(state);
        return state;
    }

    private ProductionLocalSlotMigrationState SaveWithEvents(
        ProductionLocalSlotMigrationState state,
        List<ProductionLocalSlotMigrationEvent> events)
    {
        state = state with { UpdatedAtUtc = DateTimeOffset.UtcNow, Events = events.ToArray() };
        SaveState(state);
        return state;
    }

    private void SaveState(ProductionLocalSlotMigrationState state)
    {
        Directory.CreateDirectory(_paths.LocalSlotStateRoot);
        AtomicFile.WriteJson(StatePath, state);
    }

    private ProductionLocalSlotMigrationResult Result(ProductionLocalSlotMigrationState state, string tree)
        => new(
            !state.RecoveryRequired && state.Phase is "COMMITTED" or "ALREADY_MIGRATED" or "RECOVERED_TO_VERIFIED_SLOT" or "RECOVERED_TO_PHYSICAL_WEB",
            state.Phase,
            state.TargetSlot,
            state.ActiveSlot,
            state.PhysicalWebBackup,
            tree,
            state.ActivationPerformed,
            state.DatabaseMutationPerformed,
            state.PublicMutationPerformed,
            state.WindowsRebootRequired,
            state.RecoveryRequired,
            StatePath);

    private static string BuildProductionSlotName(string expectedBuild, string treeSha256)
    {
        var token = Regex.Match(expectedBuild, @"\.(?<v>\d+[a-z]+)(?:-|$)", RegexOptions.IgnoreCase).Groups["v"].Value;
        if (string.IsNullOrWhiteSpace(token)) token = "local";
        token = Regex.Replace(token.ToLowerInvariant(), "[^a-z0-9._-]", "-");
        return $"{token}-{treeSha256[..12]}";
    }

    private void Checkpoint(string name)
    {
        _checkpoint?.Invoke(name);
    }

    private static void ValidateManagedCount(int count)
    {
        if (count is < 1 or > 20_000) throw new InvalidDataException("expected_managed_files is invalid.");
    }

    private static string NormalizeBuild(string value)
    {
        value = (value ?? string.Empty).Trim();
        if (string.IsNullOrWhiteSpace(value) || value.Length > 180 || value.Any(char.IsControl))
            throw new InvalidDataException("expected_build is invalid.");
        return value;
    }

    private static bool PathsEqual(string first, string second)
        => string.Equals(
            Path.GetFullPath(first).TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar),
            Path.GetFullPath(second).TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar),
            StringComparison.OrdinalIgnoreCase);
}
