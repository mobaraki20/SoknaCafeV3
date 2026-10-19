namespace Sokna.Maintenance.Core;

/// <summary>
/// Component-scoped release qualification. A Local-only code update does not
/// mutate Public or its remote vault; Public qualification therefore cannot
/// be an unconditional dependency of its install preflight.
/// This gate is one check among signature, exact baseline, compatibility,
/// backup, installed-state and physical-activation guards; it authorizes
/// neither Stage nor Activate by itself.
/// </summary>
public static class ReleaseInstallQualificationGate
{
    public static void Require(ReleaseSetManifest manifest)
    {
        ArgumentNullException.ThrowIfNull(manifest);
        if (!manifest.ForInstall) return;

        var q = manifest.Qualification
            ?? throw new InvalidDataException("RELEASE_QUALIFICATION_MISSING");
        if (!manifest.ProductionPass || string.IsNullOrWhiteSpace(q.FailureMatrixId) ||
            q.FailureMatrixCasesRequired < 80 || !q.Canary)
            throw new InvalidDataException("RELEASE_COMMON_QUALIFICATION_INCOMPLETE");

        // Explicit independent Local release: no Public-host, remote-vault
        // or Public clean-restore mutation is performed by this operation.
        // DB-forward migrations still require their separately safe policy.
        if (manifest.ReleaseType == "local_only")
        {
            if (!q.RealWindows || !q.RealMariaDb || !q.RecoveryConsoleWebDown ||
                !q.BusinessBackupConsistencyRealMariaDb)
                throw new InvalidDataException("RELEASE_LOCAL_QUALIFICATION_INCOMPLETE");
            return;
        }

        // Other release types are unchanged until their own independently
        // qualified owner/rollback contract exists. In particular, a paired
        // release cannot use the Local-only exception.
        if (!q.RealWindows || !q.RealMariaDb || !q.RealPublicHost ||
            !q.RecoveryConsoleWebDown || !q.BusinessBackupConsistencyRealMariaDb ||
            !q.RemoteVaultRealHost || !q.CleanMachineRemoteRestore)
            throw new InvalidDataException("RELEASE_PAIRED_QUALIFICATION_INCOMPLETE");
    }
}
