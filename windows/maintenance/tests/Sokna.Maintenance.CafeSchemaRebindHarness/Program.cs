using Sokna.Maintenance.Core;

static void Need(bool value, string reason)
{
    if (!value) throw new InvalidOperationException(reason);
}
static void MustReject(Action action, string reason)
{
    try { action(); }
    catch (InvalidDataException) { return; }
    throw new InvalidOperationException(reason);
}
var previous = new ReleaseComponentIdentity(
    KnownCafeSchemaForwardRebindGate.OldReleaseId,
    KnownCafeSchemaForwardRebindGate.OldVersion,
    KnownCafeSchemaForwardRebindGate.OldTree,
    KnownCafeSchemaForwardRebindGate.OldFingerprint,
    KnownCafeSchemaForwardRebindGate.OldHead,
    AuthorityEpochPolicy: null);
var current = new ReleaseComponentIdentity(
    KnownCafeSchemaForwardRebindGate.TargetReleaseId,
    KnownCafeSchemaForwardRebindGate.TargetVersion,
    KnownCafeSchemaForwardRebindGate.TargetTree,
    KnownCafeSchemaForwardRebindGate.TargetFingerprint,
    KnownCafeSchemaForwardRebindGate.TargetHead,
    AuthorityEpochPolicy: "preserved");
Need(KnownCafeSchemaForwardRebindGate.IsApprovedHistoricalPair(previous,current),
    "Observed 231bf/0074/null -> exact 231bz/0076/preserved not recognized");
var plan = new RoutineMigrationPlan(
    KnownCafeSchemaForwardRebindGate.TargetFingerprint,
    KnownCafeSchemaForwardRebindGate.TargetFingerprint,
    KnownCafeSchemaForwardRebindGate.TargetHead,
    KnownCafeSchemaForwardRebindGate.TargetHead,
    76,76);
KnownCafeSchemaForwardRebindGate.RequirePhysicalCatalog(current,plan);
Need(!KnownCafeSchemaForwardRebindGate.IsApprovedHistoricalPair(
        previous with { ReleaseId = "untrusted-other-install" },current),
    "Fake previous trusted identity passed");
Need(!KnownCafeSchemaForwardRebindGate.IsApprovedHistoricalPair(
        previous with { AuthorityEpochPolicy = "unknown" },current),
    "Unqualified previous epoch accepted");
Need(!KnownCafeSchemaForwardRebindGate.IsApprovedHistoricalPair(
        previous with { ManagedTreeSha256 = new string('0',64) },current),
    "Legacy tree mismatch was accepted");
Need(!KnownCafeSchemaForwardRebindGate.IsApprovedHistoricalPair(
        previous,current with { ManagedTreeSha256 = new string('0',64) }),
    "Physical target tree mismatch was accepted");
Need(!KnownCafeSchemaForwardRebindGate.IsApprovedHistoricalPair(
        previous,current with { SchemaFingerprint = previous.SchemaFingerprint }),
    "Schema fingerprint substitution was accepted");
Need(!KnownCafeSchemaForwardRebindGate.IsApprovedHistoricalPair(
        previous,current with { AuthorityEpochPolicy = null }),
    "Target epoch-policy downgrade was accepted");
MustReject(() => KnownCafeSchemaForwardRebindGate.RequirePhysicalCatalog(
    current, plan with { SourceCount = 75 }), "Incomplete 75-row migration catalog accepted");
MustReject(() => KnownCafeSchemaForwardRebindGate.RequirePhysicalCatalog(
    current, plan with { SourceFingerprint = previous.SchemaFingerprint }),
    "Old 0074 migration fingerprint accepted");
MustReject(() => KnownCafeSchemaForwardRebindGate.RequirePhysicalCatalog(
    current, plan with { TargetHead = previous.MigrationHead }),
    "Different physical migration head accepted");
Console.WriteLine("PASS: exact Cafe historical 231bf/0074 to 231bz/0076 gate, 76-catalog attestation contract and negative cases. Real MariaDB proof occurs in Supervisor preflight on the Cafe host.");