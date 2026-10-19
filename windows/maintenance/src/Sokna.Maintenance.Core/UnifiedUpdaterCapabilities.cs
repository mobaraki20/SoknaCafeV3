using System.Text.Json.Serialization;

namespace Sokna.Maintenance.Core;

// Advertise ONLY signed panel operations actually available in this build.
// Update Engine CLI availability is not proof of a safe Local panel action.
public static class UnifiedUpdaterCapabilities
{
    public const string Format = "sokna-unified-updater-capabilities-v1";

    public static UnifiedUpdaterCapabilitiesResult Current() => new(
        Format, 1, "signed_supervisor_hmac_v1",
        new LocalRoutineActions(false, false, false, false),
        false,
        "FOUNDATION_UPDATE_REQUIRED",
        "Cross-version Local updater is not enabled from the ordinary panel in this build.",
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
