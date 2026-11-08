using System.Reflection;
using Sokna.Maintenance.Core;
var assembly = typeof(InactiveLocalHostReadiness).Assembly;
var lifecycle = assembly.GetType("Sokna.Maintenance.Core.RoutineLocalLifecycle", throwOnError:true)!;
var classifier = lifecycle.GetMethod("ClassifyIdleFailure", BindingFlags.NonPublic|BindingFlags.Static);
if(classifier is null) throw new Exception("Missing lifecycle classification method");
var cases = new (Exception error,string code)[] {
 (new InvalidDataException("Local updater has a recovery-required marker."),"LOCAL_LIFECYCLE_RECOVERY_MARKER_PRESENT"),
 (new InvalidDataException("Prior routine lifecycle rollback evidence still exists; inspect/recover before a new activation."),"LOCAL_LIFECYCLE_ROLLBACK_PROOF_PRESENT"),
 (new InvalidDataException("Local lifecycle version/format does not match the active Web release."),"LOCAL_LIFECYCLE_VERSION_OR_FORMAT_MISMATCH"),
 (new InvalidDataException("Local lifecycle is not idle: staged"),"LOCAL_LIFECYCLE_PENDING_STAGE"),
 (new InvalidDataException("Local lifecycle is not idle: activation_journal"),"LOCAL_LIFECYCLE_PENDING_ACTIVATION_JOURNAL"),
 (new InvalidDataException("Local lifecycle is not idle: recovery_mode"),"LOCAL_LIFECYCLE_RECOVERY_MODE_ACTIVE"),
 (new InvalidDataException("Local lifecycle state drifted during cross-version activation."),"LOCAL_LIFECYCLE_STATE_CHANGED"),
 (new InvalidDataException("Local managed inventory drifted during cross-version activation."),"LOCAL_LIFECYCLE_MANAGED_INVENTORY_CHANGED"),
 (new InvalidDataException("Local lifecycle JSON is invalid."),"LOCAL_LIFECYCLE_STATE_JSON_INVALID"),
 (new InvalidDataException("Local data-root discovery failed (CLI status 65)."),"LOCAL_LIFECYCLE_DATA_ROOT_DISCOVERY_FAILED"),
 (new IOException("Private path: C:\\Secret"),"LOCAL_LIFECYCLE_EVIDENCE_UNAVAILABLE"),
 (new InvalidDataException("private C:\\Secret token=abc"),"LOCAL_LIFECYCLE_EVIDENCE_UNAVAILABLE")
};
foreach(var (error, expected) in cases) {
    var actual = (string?)classifier.Invoke(null,new object[]{error});
    if(actual!=expected)throw new Exception($"Expected {expected}, got {actual}");
    if(actual is null || !actual.All(c=>char.IsUpper(c)||c=='_'||char.IsDigit(c)))
        throw new Exception("Unsafe classifying output");
}
Console.WriteLine($"PASS {cases.Length} lifecycle diagnostic classifications, no private paths");
// The inspector's readiness gate remains independent of the diagnostic status.
var type = typeof(InactiveLocalHostReadiness);
if(type.GetProperty("LocalLifecycleReasonCode") is null)throw new Exception("Missing bounded diagnostic field");
Console.WriteLine("PASS readiness record includes bounded subreason");
