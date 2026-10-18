using System.ComponentModel;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

/// <summary>
/// F1-S7C3. Read-only, live-host health and identity gate. This is NOT a
/// release install permit, does not attest backup/DB/Public readiness and
/// never mutates an Apache junction, machine overlay or lifecycle.
/// </summary>
public sealed record InactiveLocalHostReadiness(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("schema_version")] int SchemaVersion,
    [property: JsonPropertyName("checked_at_utc")] DateTimeOffset CheckedAtUtc,
    [property: JsonPropertyName("installed_web_version")] string? InstalledWebVersion,
    [property: JsonPropertyName("apache_discovery_and_http_healthy")] bool ApacheDiscoveryAndHttpHealthy,
    [property: JsonPropertyName("active_junction_qualified")] bool ActiveJunctionQualified,
    [property: JsonPropertyName("installed_identity_and_tree_qualified")] bool InstalledIdentityAndTreeQualified,
    [property: JsonPropertyName("machine_local_binding_qualified")] bool MachineLocalBindingQualified,
    [property: JsonPropertyName("persistent_overlay_qualified")] bool PersistentOverlayQualified,
    [property: JsonPropertyName("local_lifecycle_idle")] bool LocalLifecycleIdle,
    [property: JsonPropertyName("mutation_ledger_idle")] bool MutationLedgerIdle,
    [property: JsonPropertyName("host_preparation_preconditions_met")] bool HostPreparationPreconditionsMet,
    [property: JsonPropertyName("blockers")] string[] Blockers,
    [property: JsonPropertyName("real_host_uat_performed")] bool RealHostUatPerformed,
    [property: JsonPropertyName("backup_qualified")] bool BackupQualified,
    [property: JsonPropertyName("database_qualified")] bool DatabaseQualified,
    [property: JsonPropertyName("public_pair_qualified")] bool PublicPairQualified,
    [property: JsonPropertyName("activation_allowed")] bool ActivationAllowed,
    [property: JsonPropertyName("panel_update_ready")] bool PanelUpdateReady,
    [property: JsonPropertyName("activation_performed")] bool ActivationPerformed,
    [property: JsonPropertyName("database_mutation_performed")] bool DatabaseMutationPerformed,
    [property: JsonPropertyName("public_mutation_performed")] bool PublicMutationPerformed);

public sealed class InactiveLocalHostReadinessInspector
{
    private readonly MaintenancePaths _paths;
    private readonly MaintenanceOperationStore _operations;

    public InactiveLocalHostReadinessInspector(MaintenancePaths paths, MaintenanceOperationStore operations)
    { _paths = paths; _operations = operations; }

    public InactiveLocalHostReadiness Inspect()
    {
        var blockers = new SortedSet<string>(StringComparer.Ordinal);
        var mutationIdle = false;
        var apache = false;
        var junction = false;
        var installed = false;
        var binding = false;
        var overlay = false;
        var lifecycle = false;
        string? version = null;

        lock (LocalUpdateOperationGate.Sync)
        {
            try
            {
                mutationIdle = _operations.ListBlocking().Count == 0;
            }
            catch (Exception ex) when (IsEvidenceUnavailable(ex))
            {
                blockers.Add("MUTATION_LEDGER_EVIDENCE_UNAVAILABLE");
            }
            if (!mutationIdle) blockers.Add("UPDATER_MUTATION_OR_RECOVERY_PENDING");

            LocalInstallLayout? layout = null;
            try
            {
                // The service ImagePath, Apache configuration, live HTTP endpoint
                // and running build are discovered, not supplied by browser.
                layout = new LocalSlotManager(_paths).InspectHealthyCurrent();
                version = layout.Build;
                apache = true;
            }
            catch (Exception ex) when (IsEvidenceUnavailable(ex))
            {
                blockers.Add("SOKNA_APACHE_DISCOVERY_OR_HEALTH_UNPROVEN");
            }

            if (layout is not null)
            {
                try
                {
                    if (!layout.WebIsReparsePoint || layout.ActiveSlotTarget is null)
                        throw new InvalidDataException("R8 junction is absent.");
                    ProductionLocalSlotMigrationBridge.RequireProductionSlotPath(
                        layout.ActiveSlotTarget, layout.PackageRoot);
                    junction = true;
                }
                catch (Exception ex) when (IsEvidenceUnavailable(ex))
                { blockers.Add("ACTIVE_R8_JUNCTION_UNQUALIFIED"); }

                try
                {
                    var identity = AtomicFile.ReadJson<InstalledReleaseIdentity>(
                        _paths.InstalledReleaseIdentityPath);
                    if (identity.Local is null || identity.Local.Version != layout.Build)
                        throw new InvalidDataException("Signed installed identity / Web build mismatch.");
                    var inventory = Path.Combine(layout.WebRoot, "resources", "local-managed-files-v1.json");
                    using var json = JsonDocument.Parse(File.ReadAllBytes(inventory));
                    var count = json.RootElement.GetProperty("files").GetArrayLength();
                    LocalManagedTreeAttestation.RequireExact(
                        layout.WebRoot, count, identity.Local.ManagedTreeSha256);
                    installed = true;
                }
                catch (Exception ex) when (IsEvidenceUnavailable(ex))
                { blockers.Add("INSTALLED_LOCAL_IDENTITY_OR_TREE_MISMATCH"); }

                var persistent = Path.Combine(layout.PackageRoot, "LocalWeb", "persistent");
                try
                {
                    RoutineLocalBinding.RequireCurrent(
                        layout.WebRoot, layout.PackageRoot, layout.Build);
                    if (layout.ActiveSlotTarget is null)
                        throw new InvalidDataException("Physical active slot unknown.");
                    RoutineLocalBinding.RequireTarget(
                        persistent, layout.ActiveSlotTarget, layout.PackageRoot, layout.Build);
                    binding = true;
                }
                catch (Exception ex) when (IsEvidenceUnavailable(ex))
                { blockers.Add("MACHINE_BINDING_OR_INSTALL_LOCK_MISMATCH"); }

                try
                {
                    if (layout.ActiveSlotTarget is null || !Directory.Exists(persistent) ||
                        (File.GetAttributes(persistent) & FileAttributes.ReparsePoint) != 0)
                        throw new InvalidDataException("Persistent machine overlay is unsafe.");
                    foreach (var relative in new[]
                    {
                        Path.Combine("public", "local-recovery.php"),
                        Path.Combine("public", "local-update-runner.php")
                    })
                    {
                        var source = Path.Combine(persistent, relative);
                        var active = Path.Combine(layout.WebRoot, relative);
                        var samePresence = File.Exists(source) == File.Exists(active);
                        if (!samePresence ||
                            File.Exists(source) && (!FileContentsEqual(source, active)))
                            throw new InvalidDataException("Active/persistent overlay mismatch.");
                    }
                    overlay = true;
                }
                catch (Exception ex) when (IsEvidenceUnavailable(ex))
                { blockers.Add("PERSISTENT_MACHINE_LOCAL_OVERLAY_DRIFT"); }

                try
                {
                    var state = RoutineLocalLifecycle.Open(
                        layout.PackageRoot, _paths.LocalSlotStateRoot, layout.Build);
                    state.RequireIdle();
                    lifecycle = true;
                }
                catch (Exception ex) when (IsEvidenceUnavailable(ex))
                { blockers.Add("LOCAL_LIFECYCLE_NOT_IDLE_OR_UNPROVEN"); }
            }
            else
            {
                blockers.Add("ACTIVE_R8_JUNCTION_UNQUALIFIED");
                blockers.Add("INSTALLED_LOCAL_IDENTITY_OR_TREE_MISMATCH");
                blockers.Add("MACHINE_BINDING_OR_INSTALL_LOCK_MISMATCH");
                blockers.Add("PERSISTENT_MACHINE_LOCAL_OVERLAY_DRIFT");
                blockers.Add("LOCAL_LIFECYCLE_NOT_IDLE_OR_UNPROVEN");
            }
        }

        var preconditions = apache && junction && installed && binding &&
            overlay && lifecycle && mutationIdle;
        // Even when all structural prerequisites are met, host UAT,
        // backups, MariaDB and cross-origin Public compatibility are unproven.
        blockers.Add("REAL_HOST_UAT_EVIDENCE_REQUIRED");
        blockers.Add("VERIFIED_BACKUP_AND_RESTORE_DRILL_REQUIRED");
        blockers.Add("MARIADB_SCHEMA_AND_MIGRATION_GATE_REQUIRED");
        blockers.Add("PUBLIC_COMPATIBILITY_NOT_ATTESTED");
        blockers.Add("ACTIVATION_AND_ROLLBACK_TRANSACTION_NOT_QUALIFIED");
        return new InactiveLocalHostReadiness(
            "sokna-inactive-local-host-readiness-v1", 1, DateTimeOffset.UtcNow,
            version, apache, junction, installed, binding, overlay, lifecycle,
            mutationIdle, preconditions, blockers.ToArray(),
            RealHostUatPerformed: false, BackupQualified: false,
            DatabaseQualified: false, PublicPairQualified: false,
            ActivationAllowed: false, PanelUpdateReady: false,
            ActivationPerformed: false, DatabaseMutationPerformed: false,
            PublicMutationPerformed: false);
    }

    private static bool FileContentsEqual(string a, string b)
    {
        if (!File.Exists(a) || !File.Exists(b) ||
            (File.GetAttributes(a) & FileAttributes.ReparsePoint) != 0 ||
            (File.GetAttributes(b) & FileAttributes.ReparsePoint) != 0)
            return false;
        if (new FileInfo(a).Length != new FileInfo(b).Length) return false;
        using var left = File.OpenRead(a);
        using var right = File.OpenRead(b);
        var x = System.Security.Cryptography.SHA256.HashData(left);
        var y = System.Security.Cryptography.SHA256.HashData(right);
        return System.Security.Cryptography.CryptographicOperations.FixedTimeEquals(x, y);
    }

    private static bool IsEvidenceUnavailable(Exception ex) =>
        ex is InvalidDataException or IOException or UnauthorizedAccessException or
            InvalidOperationException or TimeoutException or JsonException or
            Win32Exception or System.Security.Cryptography.CryptographicException or
            System.Security.SecurityException or ArgumentException or
            System.ServiceProcess.ServiceProcessTimeoutException or
            System.Net.Http.HttpRequestException or System.Net.Sockets.SocketException;
}
