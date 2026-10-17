using System.Diagnostics;
using System.Security.Cryptography;
using System.ServiceProcess;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Text.RegularExpressions;

namespace Sokna.Maintenance.Core;

public sealed partial class ProductionLocalSlotMigrationBridge
{
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

        // IMPORTANT: ReleaseRoot intentionally equals PackageRoot in the R8 contract.
        // PHP resolves a directory junction to its physical target before evaluating
        // __DIR__. A nested LocalWeb\releases\<id> target therefore changes package-root
        // discovery inside the existing Local application. Direct sibling releases keep
        // dirname(<release>) == PackageRoot without changing Local bytes.
        var releaseRoot = layout.PackageRoot;
        var persistentRoot = Path.Combine(layout.PackageRoot, "LocalWeb", "persistent");
        var preSlotRoot = Path.Combine(layout.PackageRoot, "LocalWeb", "pre-slot");
        Directory.CreateDirectory(persistentRoot);
        Directory.CreateDirectory(preSlotRoot);
        Directory.CreateDirectory(_paths.LocalSlotStateRoot);

        var targetSlot = BuildProductionSlotPath(layout.PackageRoot, expectedBuild, expectedManagedTreeSha256);
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
            // A malformed prior production state must not be silently trusted. A fresh
            // durable state is established from the exact healthy baseline below.
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
            RequireProductionSlotPath(layout.ActiveSlotTarget, layout.PackageRoot);
            var activeSlot = Path.GetFullPath(layout.ActiveSlotTarget!);
            var activeTree = LocalManagedTreeAttestation.RequireExact(activeSlot, expectedManagedFiles, expectedManagedTreeSha256);
            CaptureMachineLocalFiles(layout.WebRoot, persistentRoot);
            VerifyMachineLocalOverlay(activeSlot, persistentRoot);
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
            state = Event(state, events, "COMMITTED", "Stable Web already points to a verified sibling release slot.");
            return Result(state, actualTree);
        }

        var managedFiles = ReadManagedFiles(layout.WebRoot, expectedManagedFiles);
        CaptureMachineLocalFiles(layout.WebRoot, persistentRoot);
        StageProductionSlot(layout.WebRoot, targetSlot, managedFiles, persistentRoot, preSlotRoot);
        VerifyStagedSlot(targetSlot, persistentRoot, expectedBuild, expectedManagedFiles, expectedManagedTreeSha256);
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
            RequireProductionSlotPath(finalLayout.ActiveSlotTarget, layout.PackageRoot);
            var finalTree = LocalManagedTreeAttestation.RequireExact(finalLayout.WebRoot, expectedManagedFiles, expectedManagedTreeSha256);
            VerifyMachineLocalOverlay(finalLayout.WebRoot, persistentRoot);
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
        // and healthy, reconcile it only when a junction resolves to the R8 sibling-release
        // namespace; old nested release targets are intentionally rejected.
        if (Directory.Exists(state.StableWebRoot))
        {
            try
            {
                var currentLayout = _discovery.DiscoverAndVerify(state.ExpectedBuild, state.ExpectedManagedFiles);
                var currentTree = LocalManagedTreeAttestation.RequireExact(currentLayout.WebRoot, state.ExpectedManagedFiles, state.ExpectedManagedTreeSha256);
                if (currentLayout.WebIsReparsePoint)
                    RequireProductionSlotPath(currentLayout.ActiveSlotTarget, state.PackageRoot);
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
}
