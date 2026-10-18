global using TimeoutException = System.TimeoutException;

namespace Sokna.Maintenance.Core;

public sealed record MaintenancePaths(string DataRoot)
{
    public string SecretsRoot => Path.Combine(DataRoot, "secrets");
    public string JournalPath => Path.Combine(DataRoot, "maintenance-journal.jsonl");
    public string StatePath => Path.Combine(DataRoot, "supervisor-state.json");
    public string LockPath => Path.Combine(DataRoot, "supervisor.lock");
    public string DescriptorPath => Path.Combine(DataRoot, "control-plane.json");
    public string WebAdapterKeyPath => Path.Combine(SecretsRoot, "web-adapter.key");
    public string RecoveryConsoleKeyPath => Path.Combine(SecretsRoot, "recovery-console.key");
    public string EngineDelegationKeyPath => Path.Combine(SecretsRoot, "engine-delegation.key");
    public string EngineDelegationsRoot => Path.Combine(UpdateRoot, "delegations");
    public string ActivationPlansRoot => Path.Combine(UpdateRoot, "activation-plans");
    public string UpdateRoot => Path.Combine(DataRoot, "update");
    public string InboxRoot => Path.Combine(UpdateRoot, "inbox");
    public string OperationsRoot => Path.Combine(DataRoot, "operations");
    public string InstalledReleaseIdentityPath => Path.Combine(DataRoot, "installed-release-identity.json");
    public string WebAdapterAclEvidencePath => Path.Combine(DataRoot, "web-adapter-acl.json");
    public string StagingRoot => Path.Combine(DataRoot, "staging", "release-sets");
    public string TrustRoot => Path.Combine(DataRoot, "trust");
    public string ReleaseSigningTrustRoot => Path.Combine(TrustRoot, "release-signing");
    public string UpdateStageLockPath => Path.Combine(UpdateRoot, "stage.lock");
    public string MachineMutationLockPath => Path.Combine(UpdateRoot, "machine-mutation.lock");
    public string ReleaseSequenceStatePath => Path.Combine(UpdateRoot, "release-sequence.json");
    public string LocalSlotStateRoot => Path.Combine(DataRoot, "local-slots");
    public string LocalSlotStatePath => Path.Combine(LocalSlotStateRoot, "phase3-state.json");
    public string BootstrapRoot => Path.Combine(DataRoot, "bootstrap");
    public string LocalSlotAdoptionRequestPath => Path.Combine(BootstrapRoot, "local-slot-adoption-request.json");
    public string LocalSlotAdoptionStatusPath => Path.Combine(BootstrapRoot, "local-slot-adoption-status.json");

    public static string DefaultDataRoot()
    {
        var common = Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData);
        if (string.IsNullOrWhiteSpace(common))
        {
            throw new InvalidOperationException("CommonApplicationData is unavailable.");
        }

        return Path.Combine(common, "SOKNA", "Maintenance");
    }
}

public static class Phase3HomeReleaseContract
{
    public const string Build = "3.0.0-g6.2-workspace.231ai-browser-recovery-managed-inventory";
    public const int ManagedFiles = 497;
    public const string ManagedTreeSha256 = "b0e252b62b804c00e25c925d31ded8986fa23c3ab0d58b668eb79a79b961f56a";

    public static string RequireCanonicalManagedTree(string expectedBuild, int expectedManagedFiles)
    {
        if (!string.Equals(expectedBuild, Build, StringComparison.Ordinal) || expectedManagedFiles != ManagedFiles)
            throw new InvalidDataException("Phase 3 Home cycle is allowlisted only for the exact qualified 231ai release identity.");
        return ManagedTreeSha256;
    }
}

public static class SupervisorLocalSlotCoordinatorHomeExtensions
{
    public static LocalSlotOperationResult RunHomeCycle(
        this SupervisorLocalSlotCoordinator coordinator,
        string expectedBuild,
        int expectedManagedFiles,
        string operatorId,
        string idempotencyKey)
    {
        var canonicalTree = Phase3HomeReleaseContract.RequireCanonicalManagedTree(expectedBuild, expectedManagedFiles);
        return coordinator.RunHomeCycle(expectedBuild, expectedManagedFiles, canonicalTree, operatorId, idempotencyKey);
    }
}
