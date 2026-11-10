using System.Text.Json;
using Sokna.Maintenance.Core;

static void Need(bool ok, string reason)
{
    if (!ok) throw new InvalidOperationException(reason);
}
static void Reject(ReleaseSetManifest doc, string expected)
{
    try { ReleaseInstallQualificationGate.Require(doc); }
    catch (InvalidDataException e)
    {
        Need(e.Message == expected, "GATE_WRONG_CODE_" + e.Message);
        Console.WriteLine("PASS NATIVE_FAIL_CLOSED " + expected);
        return;
    }
    throw new InvalidOperationException("GATE_UNSAFE_ACCEPTED_" + expected);
}
// Exercise the actual production Maintenance Core qualification gate. The
// JSON manifest is a scoped gate fixture, not a signed deployable release.
const string shape = """
{
 "release_type": "paired",
 "for_install": true,
 "production_pass": true,
 "qualification": {
   "failure_matrix_id": "qa-disposable-only",
   "failure_matrix_cases_required": 120,
   "real_windows": true,
   "real_mariadb": true,
   "real_public_host": true,
   "canary": true,
   "recovery_console_web_down": true,
   "business_backup_consistency_real_mariadb": true,
   "remote_vault_real_host": true,
   "clean_machine_remote_restore": true
 }
}
""";
var all = JsonSerializer.Deserialize<ReleaseSetManifest>(shape)
    ?? throw new InvalidDataException("NATIVE_PROBE_MANIFEST_PARSE_FAILED");
Need(all.Qualification is not null, "NATIVE_PROBE_QUALIFICATION_MISSING");
var q = all.Qualification;
ReleaseInstallQualificationGate.Require(all);
Console.WriteLine("PASS NATIVE_QUALIFICATION_SHAPE_FULL_PAIRED_ONLY_NOT_ACTIVATION");
Reject(all with { Qualification = q with { RealPublicHost = false } }, "RELEASE_PAIRED_QUALIFICATION_INCOMPLETE");
Reject(all with { Qualification = q with { RemoteVaultRealHost = false } }, "RELEASE_PAIRED_QUALIFICATION_INCOMPLETE");
Reject(all with { Qualification = q with { CleanMachineRemoteRestore = false } }, "RELEASE_PAIRED_QUALIFICATION_INCOMPLETE");
Reject(all with { Qualification = q with { RealMariaDb = false } }, "RELEASE_PAIRED_QUALIFICATION_INCOMPLETE");
Reject(all with { Qualification = q with { Canary = false } }, "RELEASE_COMMON_QUALIFICATION_INCOMPLETE");
Reject(all with { Qualification = q with { FailureMatrixCasesRequired = 79 } }, "RELEASE_COMMON_QUALIFICATION_INCOMPLETE");
var scoped = all with
{
    ReleaseType = "local_only",
    Qualification = q with { RealPublicHost = false, RemoteVaultRealHost = false, CleanMachineRemoteRestore = false }
};
ReleaseInstallQualificationGate.Require(scoped);
Console.WriteLine("PASS NATIVE_LOCAL_ONLY_NOT_IMPROPERLY_DEPENDENT_ON_PUBLIC");
Reject(scoped with { Qualification = scoped.Qualification with { RecoveryConsoleWebDown = false } },
    "RELEASE_LOCAL_QUALIFICATION_INCOMPLETE");
Console.WriteLine("PASS REAL_MAINTENANCE_CORE_PAIRED_RELEASE_GATE_POLICY_QA_ONLY");
