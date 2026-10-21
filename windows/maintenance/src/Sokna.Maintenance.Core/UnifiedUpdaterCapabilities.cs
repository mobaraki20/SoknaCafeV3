using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

// Advertise ONLY signed panel operations actually available in this build.
// Update Engine CLI availability is not proof of a safe Local panel action.
public static class UnifiedUpdaterCapabilities
{
    public const string Format = "sokna-unified-updater-capabilities-v1";

    /// <summary>
    /// Live, fail-closed panel capability diagnostics. A clean historical
    /// operation ledger is necessary but never sufficient for activation.
    /// </summary>
    public static UnifiedUpdaterCapabilitiesResult Current(
        InactiveLocalHostReadiness host,
        IReadOnlyList<MaintenanceOperation> blocking)
    {
        ArgumentNullException.ThrowIfNull(host);
        ArgumentNullException.ThrowIfNull(blocking);
        var code = blocking.Any(static x => x.State == "recovery_required")
            ? "LOCAL_RECOVERY_REQUIRED"
            : blocking.Count != 0
                ? "LOCAL_OPERATION_IN_PROGRESS"
                : host.Blockers.Contains("INSTALLED_LOCAL_IDENTITY_OR_TREE_MISMATCH",
                    StringComparer.Ordinal)
                    ? "LOCAL_INSTALLED_IDENTITY_REBIND_REQUIRED"
                    : !host.HostPreparationPreconditionsMet
                        ? "LOCAL_HOST_PRECONDITIONS_UNPROVEN"
                        : "LOCAL_CROSS_VERSION_UAT_REQUIRED";
        var detail = code switch
        {
            "LOCAL_RECOVERY_REQUIRED" =>
                "Unresolved Maintenance recovery operations remain blocking until verified reconciliation.",
            "LOCAL_OPERATION_IN_PROGRESS" =>
                "A durable Maintenance operation is still running or reserved.",
            "LOCAL_INSTALLED_IDENTITY_REBIND_REQUIRED" =>
                "Live Local build/tree differs from trusted Maintenance identity. Require authenticated adoption; never rewrite an identity from UI-supplied version.",
            "LOCAL_HOST_PRECONDITIONS_UNPROVEN" =>
                "Active junction, Apache HTTP, managed tree, persistent machine binding or lifecycle proof is missing.",
            _ => "Local cross-version installation and verified backup/recovery still require real-host qualification."
        };
        return Current() with
        {
            // Read-only checks are available independently of activation.
            BlockingCode = code,
            Detail = detail,
            PanelUpdateReady = host.PanelUpdateReady && host.ActivationAllowed &&
                host.BackupQualified && host.DatabaseQualified &&
                host.RealHostUatPerformed && blocking.Count == 0,
            LocalRoutine = new LocalRoutineActions(true, true,
                host.ActivationAllowed && blocking.Count == 0, false)
        };
    }

    public static UnifiedUpdaterCapabilitiesResult Current() => new(
        Format, 1, "signed_supervisor_hmac_v1",
        // Signed HMAC routes are implemented; advertise their presence
        // truthfully. This is NOT permission to mutate installed Local.
        // HTTP actions enforce signed Stage, host/backup/identity and
        // one-use Engine permit on every request.
        new LocalRoutineActions(true, true, true, false),
        false,
        "LOCAL_CROSS_VERSION_UAT_REQUIRED",
        "Windows Foundation is installed, but Local cross-version Stage/Activate and recovery have not passed real-host UAT. Signed inbox transport alone does not authorize activation.",
        true); // Transport-only availability; NOT an installability claim.
}

public sealed record LocalRoutineActions(
    [property: JsonPropertyName("preflight")] bool Preflight,
    [property: JsonPropertyName("stage")] bool Stage,
    [property: JsonPropertyName("activate")] bool Activate,
    [property: JsonPropertyName("recover")] bool Recover);

public sealed record UnifiedUpdaterCapabilitiesResult(
    [property: JsonPropertyName("format")] string Format,
    [property: JsonPropertyName("schema_version")] int SchemaVersion,
    [property: JsonPropertyName("auth_contract")] string AuthContract,
    [property: JsonPropertyName("local_routine")] LocalRoutineActions LocalRoutine,
    [property: JsonPropertyName("panel_update_ready")] bool PanelUpdateReady,
    [property: JsonPropertyName("blocking_code")] string BlockingCode,
    [property: JsonPropertyName("detail")] string Detail,
    [property: JsonPropertyName("inbox_transport_available")] bool InboxTransportAvailable);
