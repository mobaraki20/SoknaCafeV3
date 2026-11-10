using System.Diagnostics;
using System.Reflection;
using System.Security.Cryptography;
using System.Text.Json;
using Sokna.Maintenance.Core;

if (!OperatingSystem.IsWindows() || args.Length != 7)
    throw new InvalidOperationException("USAGE: v27Supervisor v27Engine v26Supervisor v26Engine v25Supervisor v25Engine Recovery");
string Hash(string path) => Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(path))).ToLowerInvariant();
void Need(bool ok, string name) { if (!ok) throw new Exception("FAILED "+name); Console.WriteLine("PASS "+name); }
void Save(string path, object data) {Directory.CreateDirectory(Path.GetDirectoryName(path)!);File.WriteAllText(path,JsonSerializer.Serialize(data));}
string Physical(string p) {var x=FileVersionInfo.GetVersionInfo(p);return $"{x.FileMajorPart}.{x.FileMinorPart}.{x.FileBuildPart}";}
var root=Path.Combine(Path.GetTempPath(),"sokna-r125-v27-two-generation-"+Guid.NewGuid().ToString("N"));
var install=Path.Combine(root,"installation");
var setup=Path.Combine(root,"setup");
var snapshot26=Path.Combine(setup,"maintenance-independent-pair-v1");
var snapshot27=Path.Combine(setup,"maintenance-independent-pair-027-v1");
var id26="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";
var id27="bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb";
var tx26=Path.Combine(snapshot26,"tx-"+id26);
var tx27=Path.Combine(snapshot27,"tx-"+id27);
var rel=new [] {"Maintenance/App/SoknaMaintenanceSupervisor.exe","Maintenance/App/SoknaMaintenanceUpdateEngine.exe"};
object Identity(string ver)=>new {
    maintenance_supervisor_version=ver,update_engine_version=ver,
    local=new {release_id="cafe-r112",version="231dg",managed_tree_sha256=new string('a',64)},
    @public=new {release_id="public-r149",version="35hh",managed_tree_sha256=new string('b',64)},
    authority_epoch="preserved"
};
var all=args.Select(Path.GetFullPath).ToArray();
try {
    for(int i=0;i<all.Length;i++) Need(File.Exists(all[i]),"HAS_PHYSICAL_EXE_"+i);
    Need(Physical(all[0])=="0.3.27"&&Physical(all[1])=="0.3.27","PHYSICAL_0327");
    Need(Physical(all[2])=="0.3.26"&&Physical(all[3])=="0.3.26","PHYSICAL_0326");
    Need(Physical(all[4])=="0.3.25"&&Physical(all[5])=="0.3.25","PHYSICAL_0325");
    var recovery=Path.Combine(install,"Maintenance/Recovery/SoknaMaintenanceRecoveryConsole.exe");
    Directory.CreateDirectory(Path.GetDirectoryName(recovery)!);
    File.Copy(all[6],recovery);
    var installed=rel.Select(x=>Path.Combine(install,x.Replace('/',Path.DirectorySeparatorChar))).ToArray();
    var archived26=rel.Select(x=>Path.Combine(tx27,"before",Path.GetFileName(x))).ToArray();
    var archived25=rel.Select(x=>Path.Combine(tx26,"before",Path.GetFileName(x))).ToArray();
    for(int i=0;i<2;i++) {
        Directory.CreateDirectory(Path.GetDirectoryName(installed[i])!);
        Directory.CreateDirectory(Path.GetDirectoryName(archived26[i])!);
        Directory.CreateDirectory(Path.GetDirectoryName(archived25[i])!);
        File.Copy(all[i],installed[i]);File.Copy(all[i+2],archived26[i]);File.Copy(all[i+4],archived25[i]);
    }
    var pre26=Path.Combine(tx26,"before/installed-release-identity.json");
    var pre27=Path.Combine(tx27,"before/installed-release-identity.json");
    File.WriteAllBytes(pre26,JsonSerializer.SerializeToUtf8Bytes(Identity("0.3.25")));
    File.WriteAllBytes(pre27,JsonSerializer.SerializeToUtf8Bytes(Identity("0.3.26")));
    var r25=rel.Select((name,i)=>new{name,sha256=Hash(archived25[i])}).ToArray();
    var r26=rel.Select((name,i)=>new{name,sha256=Hash(archived26[i])}).ToArray();
    var r26Files=rel.Select((path,i)=>new{path,bytes=new FileInfo(archived26[i]).Length,sha256=Hash(archived26[i])}).ToArray();
    var r27Files=rel.Select((path,i)=>new{path,bytes=new FileInfo(installed[i]).Length,sha256=Hash(installed[i])}).ToArray();
    void Marker(string path,string id,string from,string to) => Save(path,new {format="sokna-maintenance-independent-transaction-v1",
        id,phase="COMMITTED",baseline=from,target=to,database_mutated=false,local_mutated=false,public_mutated=false,print_mutated=false});
    Marker(Path.Combine(snapshot26,"active.json"),id26,"0.3.25","0.3.26");
    Marker(Path.Combine(snapshot27,"active.json"),id27,"0.3.26","0.3.27");
    void Receipt(string path,string from,string to,string source,object files) => Save(path,new {
        format="sokna-maintenance-independent-result-v1",status="COMMITTED",supervisor_version=to,
        engine_version=to,source_version=from,source_commit=source,recovery_console_version=Physical(recovery),
        recovery_preserved=true,archive_reconciled=false,database_mutated=false,local_mutated=false,
        public_mutated=false,print_mutated=false,files
    });
    Receipt(Path.Combine(tx26,"result.json"),"0.3.25","0.3.26","aba774d67e9d3890aa0f69d00f214ae82b395927",r26Files);
    Receipt(Path.Combine(tx27,"result.json"),"0.3.26","0.3.27","59eb342eb3980aa0f02412392dbb9304ae650b9b",r27Files);
    void Snapshot(string path,string identity,string recoverySha,object files)=>Save(path,new{
        format="sokna-maintenance-independent-snapshot-v1",identity_sha256=Hash(identity),recovery_sha256=recoverySha,files});
    Snapshot(Path.Combine(tx26,"snapshot.json"),pre26,Hash(recovery),r25);
    Snapshot(Path.Combine(tx27,"snapshot.json"),pre27,Hash(recovery),r26);
    var before=JsonSerializer.SerializeToUtf8Bytes(Identity("0.3.22"));
    var core=typeof(MaintenancePaths).Assembly.GetType("Sokna.Maintenance.Core.MaintenanceIdentityVersionProof",true)!;
    var method=core.GetMethod("RequireIndependentPair27",BindingFlags.Static|BindingFlags.NonPublic)!;
    void Run()=>method.Invoke(null,[before,install,setup]);
    void Reject(string label){
        try{Run();}catch(TargetInvocationException e)when(e.InnerException is InvalidDataException){
            Console.WriteLine("PASS REJECT_"+label);return;}
        throw new Exception("UNSAFE_ACCEPTED_"+label);
    }
    Run();Need(true,"TWO_GENERATION_CHAIN_PASSED");
    var p27result=Path.Combine(tx27,"result.json");
    Receipt(p27result,"0.3.26","0.3.27","WRONG_BUILD",r27Files);
    Reject("NEW_SOURCE_IDENTITY_TAMPER");
    Receipt(p27result,"0.3.26","0.3.27","59eb342eb3980aa0f02412392dbb9304ae650b9b",r27Files);
    Snapshot(Path.Combine(tx27,"snapshot.json"),pre27,new string('f',64),r26);
    Reject("PRESERVED_RECOVERY_SHA");
    Snapshot(Path.Combine(tx27,"snapshot.json"),pre27,Hash(recovery),r26);
    File.AppendAllText(pre27," ");
    Reject("PREIMAGE_TAMPER");
    File.WriteAllBytes(pre27,JsonSerializer.SerializeToUtf8Bytes(Identity("0.3.26")));
    File.AppendAllText(Path.Combine(tx26,"result.json")," ");
    // Whitespace alone is not a tamper: exact semantic receipt is used. Change
    // the older historical source identity and demand rejection.
    Receipt(Path.Combine(tx26,"result.json"),"0.3.25","0.3.26","FAKE_PREVIOUS_SOURCE",r26Files);
    Reject("PREVIOUS_0326_RECEIPT_TAMPER");
    Receipt(Path.Combine(tx26,"result.json"),"0.3.25","0.3.26","aba774d67e9d3890aa0f69d00f214ae82b395927",r26Files);
    File.AppendAllText(archived26[1],"TAMPER");
    Reject("BEFORE_0326_BINARY_TAMPER");
    File.Copy(all[3],archived26[1],true);
    var wrong = new {maintenance_supervisor_version="0.3.26",update_engine_version="0.3.26",
        local=new {release_id="different",version="231dg",managed_tree_sha256=new string('a',64)},
        @public=new {release_id="public-r149",version="35hh",managed_tree_sha256=new string('b',64)},authority_epoch="preserved"};
    File.WriteAllBytes(pre27,JsonSerializer.SerializeToUtf8Bytes(wrong));
    Snapshot(Path.Combine(tx27,"snapshot.json"),pre27,Hash(recovery),r26);
    Reject("LOCAL_IDENTITY_DRIFT");
    Console.WriteLine("PASS R125 0327 FULL PREDECESSOR CHAIN + SIX NEGATIVE PROOF CHECKS ON DISPOSABLE WINDOWS");
} finally {try{Directory.Delete(root,true);}catch{}}
