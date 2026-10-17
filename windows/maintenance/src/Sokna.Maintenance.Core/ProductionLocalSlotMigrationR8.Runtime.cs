using System.Diagnostics;
using System.Security.Cryptography;
using System.ServiceProcess;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Text.RegularExpressions;

namespace Sokna.Maintenance.Core;

public sealed partial class ProductionLocalSlotMigrationBridge
{
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
}
