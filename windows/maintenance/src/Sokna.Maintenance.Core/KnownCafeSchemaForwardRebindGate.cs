namespace Sokna.Maintenance.Core;

/// <summary>
/// One explicitly observed Cafe 231bf -> 231bz historical transition. The
/// persistent Maintenance identity is still 231bf / schema 0074, although the
/// physical Web is 231bz and the actual database has 76 migrations. This
/// gate does not silently relax schema/epoch matching for other installations.
/// The caller must ALSO verify the exact physical managed-tree hash, unique
/// release-set baseline, signed/operator-authorized package and a read-only
/// live MariaDB ledger against the physically attested migration SQL catalog.
/// </summary>
internal static class KnownCafeSchemaForwardRebindGate
{
    internal const string OldVersion = "3.0.0-g6.2-workspace.231bf-u01-iran-time-updater-rc1";
    internal const string OldReleaseId = "sokna-local-231bf-u01-iran-time-updater-20261023";
    internal const string OldTree = "80fd968a9e4f1642c3f1519f66d7ab6a2fb1bb64f1a8c0a26444d0d8da1712be";
    internal const string OldHead = "0074_iran_time_notification_boundaries";
    internal const string OldFingerprint = "migration-ledger-sha256-v1:43e4572dea9709b4dcd278497f0373962bb4e2460022d7400ef51d8b317fdcbf";

    internal const string TargetVersion = "3.0.0-g6.2-workspace.231bz-r38-87-integrated-panel-uat";
    internal const string TargetReleaseId = "sokna-87-complete-home-r38-20261030-local";
    internal const string TargetTree = "827037f2efdf313560fbf24a430cec3eeeedfd274b1fc658d45dfcb3aa4d7fa1";
    internal const string TargetHead = "0076_late_served_accounting";
    internal const string TargetFingerprint = "migration-ledger-sha256-v1:deb5af9ad17f91af67360dd7a89de3b00b84be171675f4fb710ca1dd73e52ed9";

    internal static bool IsApprovedHistoricalPair(ReleaseComponentIdentity previous,
        ReleaseComponentIdentity candidate)
    {
        // A null historical epoch is NOT equivalent to "preserved" generally.
        // The only allowed conversion is from the pinned, already trusted 231bf
        // identity to the pinned, physically verified 231bz baseline.
        return previous.ReleaseId == OldReleaseId &&
            previous.Version == OldVersion &&
            string.Equals(previous.ManagedTreeSha256, OldTree, StringComparison.OrdinalIgnoreCase) &&
            previous.SchemaFingerprint == OldFingerprint &&
            previous.MigrationHead == OldHead &&
            previous.AuthorityEpochPolicy is null &&
            candidate.ReleaseId == TargetReleaseId &&
            candidate.Version == TargetVersion &&
            string.Equals(candidate.ManagedTreeSha256, TargetTree, StringComparison.OrdinalIgnoreCase) &&
            candidate.SchemaFingerprint == TargetFingerprint &&
            candidate.MigrationHead == TargetHead &&
            candidate.AuthorityEpochPolicy == "preserved";
    }

    internal static void RequirePhysicalCatalog(ReleaseComponentIdentity candidate,
        RoutineMigrationPlan plan)
    {
        if (candidate.ReleaseId != TargetReleaseId ||
            candidate.Version != TargetVersion ||
            !string.Equals(candidate.ManagedTreeSha256, TargetTree, StringComparison.OrdinalIgnoreCase) ||
            candidate.MigrationHead != TargetHead ||
            candidate.SchemaFingerprint != TargetFingerprint ||
            plan.RequiresMigration || plan.SourceHead != TargetHead ||
            plan.TargetHead != TargetHead ||
            plan.SourceFingerprint != TargetFingerprint ||
            plan.TargetFingerprint != TargetFingerprint ||
            plan.SourceCount != 76 || plan.TargetCount != 76)
            throw new InvalidDataException("LOCAL_REBIND_PHYSICAL_SCHEMA_CATALOG_MISMATCH");
    }
}
