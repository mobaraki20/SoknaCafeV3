using System.Reflection;
using System.Security.Cryptography;
using System.Text.Json;
using System.Text.Json.Nodes;
using System.Diagnostics;
using Sokna.Maintenance.Core;

if (!OperatingSystem.IsWindows() || args.Length != 5)
    throw new InvalidOperationException("USAGE: Supervisor26 Engine26 Recovery17 Supervisor25 Engine25");
var paths = args.Select(Path.GetFullPath).ToArray();
foreach (var p in paths)
    if (!File.Exists(p)) throw new FileNotFoundException("Fixture executable missing", p);
string Sha(string path) => Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(path))).ToLowerInvariant();
void Need(bool value, string label)
{
    if (!value) throw new InvalidDataException("QA_FAILED_" + label);
    Console.WriteLine("PASS " + label);
}
void Save(string path, object obj)
{
    Directory.CreateDirectory(Path.GetDirectoryName(path)!);
    File.WriteAllText(path, JsonSerializer.Serialize(obj));
}
var root = Path.Combine(Path.GetTempPath(), "SOKNA-INDEPENDENT-PROOF-REPLAY-" + Guid.NewGuid().ToString("N"));
try
{
    var install = Path.Combine(root, "install");
    var markerRoot = Path.Combine(root, "setup", "maintenance-independent-pair-v1");
    var tx = Path.Combine(markerRoot, "tx-0123456789abcdef0123456789abcdef");
    var beforeRoot = Path.Combine(tx, "before");
    Directory.CreateDirectory(beforeRoot);
    var rel = new [] {
        "Maintenance/App/SoknaMaintenanceSupervisor.exe",
        "Maintenance/App/SoknaMaintenanceUpdateEngine.exe"
    };
    var installed = new string[2];
    var previous = new string[2];
    for (int i=0; i<2; i++)
    {
        installed[i] = Path.Combine(install, rel[i].Replace('/', Path.DirectorySeparatorChar));
        previous[i] = Path.Combine(beforeRoot, Path.GetFileName(rel[i]));
        Directory.CreateDirectory(Path.GetDirectoryName(installed[i])!);
        File.Copy(paths[i], installed[i], true);
        File.Copy(paths[i+3], previous[i], true);
    }
    var recovery = Path.Combine(install, "Maintenance", "Recovery", "SoknaMaintenanceRecoveryConsole.exe");
    Directory.CreateDirectory(Path.GetDirectoryName(recovery)!);
    File.Copy(paths[2], recovery, true);
    string Pe(string p) { var v=FileVersionInfo.GetVersionInfo(p);return $"{v.FileMajorPart}.{v.FileMinorPart}.{v.FileBuildPart}"; }
    Need(Pe(installed[0]) == "0.3.26" && Pe(installed[1]) == "0.3.26", "CURRENT_PHYSICAL_VERSION");
    Need(Pe(previous[0]) == "0.3.25" && Pe(previous[1]) == "0.3.25", "BEFORE_PHYSICAL_VERSION");
    Need(Pe(recovery) == "0.1.7", "PRESERVED_RECOVERY_VERSION");

    object Original(string version) => new {
        maintenance_supervisor_version = version,
        update_engine_version = version,
        local = new { release_id = "r112",version = "231dg", managed_tree_sha256 = new string('a',64) },
        @public = new { release_id = "r149",version = "35hh", managed_tree_sha256 = new string('b',64) },
        authority_epoch = 1
    };
    byte[] originalR125 = JsonSerializer.SerializeToUtf8Bytes(Original("0.3.22"));
    var originalIndependent = Path.Combine(beforeRoot, "installed-release-identity.json");
    File.WriteAllBytes(originalIndependent, JsonSerializer.SerializeToUtf8Bytes(Original("0.3.25")));

    var id = "0123456789abcdef0123456789abcdef";
    Save(Path.Combine(markerRoot,"active.json"),new {
        format="sokna-maintenance-independent-transaction-v1",id,phase="COMMITTED",
        baseline="0.3.25",target="0.3.26",
        database_mutated=false,local_mutated=false,public_mutated=false,print_mutated=false
    });
    var expected = rel.Select((p,i) => new { path=p, bytes=new FileInfo(installed[i]).Length,sha256=Sha(installed[i]) }).ToArray();
    string resultFile = Path.Combine(tx,"result.json");
    void WriteResult(bool recoveryPreserved=true,string status="COMMITTED")
        => Save(resultFile,new {
            format="sokna-maintenance-independent-result-v1",status,
            supervisor_version="0.3.26",engine_version="0.3.26",
            recovery_console_version="0.1.7",recovery_preserved=recoveryPreserved,
            source_version="0.3.25",source_commit="aba774d67e9d3890aa0f69d00f214ae82b395927",
            files=expected,archive_reconciled=false,database_mutated=false,
            local_mutated=false,public_mutated=false,print_mutated=false
        });
    WriteResult();
    var old = rel.Select((p,i) => new {name=p,sha256=Sha(previous[i])}).ToArray();
    string snapshotFile=Path.Combine(tx,"snapshot.json");
    void WriteSnapshot(string recoveryHash) => Save(snapshotFile,new {
        format="sokna-maintenance-independent-snapshot-v1",
        identity_sha256=Sha(originalIndependent),recovery_sha256=recoveryHash,files=old
    });
    WriteSnapshot(Sha(recovery));
    var assembly=typeof(MaintenancePaths).Assembly;
    var type=assembly.GetType("Sokna.Maintenance.Core.MaintenanceIdentityVersionProof",throwOnError:true)!;
    var method=type.GetMethod("RequireIndependentPair",BindingFlags.NonPublic|BindingFlags.Static)
        ??throw new InvalidOperationException("PATCHED_INDEPENDENT_PROOF_METHOD_MISSING");
    void Run()
        => method.Invoke(null,[originalR125,install,markerRoot,"0.3.26"]);
    void Reject(string label)
    {
        try { Run(); }
        catch (TargetInvocationException ex) when(ex.InnerException is InvalidDataException)
        {Console.WriteLine("PASS REJECT_" + label);return;}
        throw new InvalidOperationException("UNSAFE_ACCEPTED_"+label);
    }
    Run();
    Need(true,"REAL_TWO_COMPONENT_COMMITTED_RECEIPT_WITH_RECOVERY_PRESERVED");
    WriteResult(false);
    Reject("RECOVERY_PRESERVED_FALSE");
    WriteResult(true,"RECOVERY_REQUIRED");
    Reject("NOT_COMMITTED");
    WriteResult();
    WriteSnapshot(new string('f',64));
    Reject("RECOVERY_HASH_MISMATCH");
    WriteSnapshot(Sha(recovery));
    File.AppendAllText(originalIndependent," ");
    Reject("IDENTITY_PREIMAGE_HASH_MISMATCH");
    File.WriteAllBytes(originalIndependent,JsonSerializer.SerializeToUtf8Bytes(Original("0.3.25")));
    // Restore the genuine snapshot hash.
    WriteSnapshot(Sha(recovery));
    var changed = new {
        maintenance_supervisor_version="0.3.25",update_engine_version="0.3.25",
        local=new {release_id="DIFFERENT",version="231dg",managed_tree_sha256=new string('a',64)},
        @public=new {release_id="r149",version="35hh",managed_tree_sha256=new string('b',64)},
        authority_epoch=1
    };
    File.WriteAllBytes(originalIndependent,JsonSerializer.SerializeToUtf8Bytes(changed));
    WriteSnapshot(Sha(recovery));
    Reject("LOCAL_IDENTITY_FIELD_CHANGED");
    File.WriteAllBytes(originalIndependent,JsonSerializer.SerializeToUtf8Bytes(Original("0.3.25")));
    WriteSnapshot(Sha(recovery));
    File.Delete(previous[1]);
    Reject("BEFORE_ENGINE_BINARY_MISSING");
    Console.WriteLine("PASS R125 INDEPENDENT PAIR RECEIPT EXACT WINDOWS POSITIVE AND SIX NEGATIVE CASES; NO LIVE CAFE MUTATION");
}
finally
{
    try {Directory.Delete(root,true);}catch {}
}
