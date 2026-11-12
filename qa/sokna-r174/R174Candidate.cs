using System;
using System.IO;
using System.IO.Compression;
using System.Linq;
using System.Reflection;
using System.Text.Json;
using Sokna.Maintenance.Core;

if(!OperatingSystem.IsWindows() || Environment.GetEnvironmentVariable("GITHUB_ACTIONS")!="true" ||
   Environment.GetEnvironmentVariable("RUNNER_OS")!="Windows")
    throw new InvalidOperationException("DISPOSABLE_WINDOWS_RUNNER_ONLY");
if(args.Length!=2)throw new ArgumentException("Usage R171_BROKEN.zip R174_REPAIRED.zip");
static byte[] Read(ZipArchive archive,string name){using var s=archive.GetEntry(name)?.Open()??throw new InvalidDataException(name);using var m=new MemoryStream();s.CopyTo(m);return m.ToArray();}
static void Check(bool value,string label){if(!value)throw new InvalidDataException("R174_"+label);Console.WriteLine("PASS "+label);}
var gate=typeof(SupervisorInactiveLocalCandidateCoordinator).GetMethod("ValidateCandidateTree",BindingFlags.NonPublic|BindingFlags.Static)
         ??throw new MissingMethodException("PRIVATE_CANDIDATE_VALIDATION_GATE_MISSING");
void Validate(string archivePath,bool expectValid){
 var temporary=Path.Combine(Path.GetTempPath(),"sokna-r174-candidate-"+Guid.NewGuid().ToString("N"));
 try{
   Directory.CreateDirectory(temporary);
   using var archive=ZipFile.OpenRead(archivePath);
   using var manifest=JsonDocument.Parse(Read(archive,"release-set.json"));
   var target=manifest.RootElement.GetProperty("targets").GetProperty("local");
   var version=target.GetProperty("version").GetString()!;
   var tree=target.GetProperty("managed_tree_sha256").GetString()!;
   var count=target.GetProperty("managed_files").GetInt32();
   var nested=Path.Combine(temporary,"web.zip");
   File.WriteAllBytes(nested,Read(archive,"artifacts/local/web.zip"));
   var web=Path.Combine(temporary,"web");Directory.CreateDirectory(web);
   ZipFile.ExtractToDirectory(nested,web);
   try{
     gate.Invoke(null,new object[]{web,version,tree,count});
     Check(expectValid,expectValid?"REPAIRED_NATIVE_PRIVATE_CANDIDATE_ACCEPTED":"BROKEN_CANDIDATE_UNEXPECTEDLY_ACCEPTED");
   }catch(TargetInvocationException ex)when(ex.InnerException is InvalidDataException){
     var cause=(InvalidDataException)ex.InnerException;
     Check(!expectValid&&cause.Message=="LOCAL_WEB_INVENTORY_FILES_MISMATCH", "R171_REAL_PRIVATE_CANDIDATE_GATE_REPRODUCED_583_VS_589");
   }
 }finally{try{Directory.Delete(temporary,true);}catch{}}
}
Validate(args[0],false);
Validate(args[1],true);
Console.WriteLine("PASS R174_NATIVE_CANDIDATE_VALIDATOR_BROKEN_VS_REPAIRED");
