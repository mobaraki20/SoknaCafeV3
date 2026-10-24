using System.IO.Compression;
using System.Security.Cryptography;
using Sokna.Maintenance.Core;
var root=Path.GetFullPath(args[0]);Directory.CreateDirectory(root);var paths=new MaintenancePaths(root);Directory.CreateDirectory(paths.InboxRoot);var inbox=new LocalInboxUploadCoordinator(paths);
using var memory=new MemoryStream();using(var z=new ZipArchive(memory,ZipArchiveMode.Create,true)){using var w=z.CreateEntry("payload.bin",CompressionLevel.NoCompression).Open();var data=new byte[400000];RandomNumberGenerator.Fill(data);w.Write(data);}var bytes=memory.ToArray();var hash=Convert.ToHexString(SHA256.HashData(bytes)).ToLowerInvariant();
void Need(bool ok,string why){if(!ok)throw new Exception(why);}
void Upload(string id){for(var offset=0;offset<bytes.Length;offset+=131072){var length=Math.Min(131072,bytes.Length-offset);var r=inbox.Append(new(id,"user:1",hash,bytes.Length,offset,Convert.ToBase64String(bytes,offset,length)));Need(r.ReceivedBytes==offset+length,"browser progress contract mismatch");Need(r.Complete==(offset+length==bytes.Length),"premature complete");}}
Upload(new string('a',32));var original=File.ReadAllBytes(Path.Combine(paths.InboxRoot,hash+".zip"));
Upload(new string('b',32)); // Exact real browser retry: SAME bytes, NEW upload session.
Upload(new string('b',32)); // Lost response/replay on a completed session.
Need(File.ReadAllBytes(Path.Combine(paths.InboxRoot,hash+".zip")).SequenceEqual(original),"completed blob changed");
try{inbox.Append(new(new string('b',32),"user:2",hash,bytes.Length,0,Convert.ToBase64String(bytes,0,131072)));throw new Exception("foreign session accepted");}catch(InvalidDataException ex) when(ex.Message=="INBOX_UPLOAD_IDENTITY_CONFLICT"){}
var bad=bytes[..131072];bad[100]^=1;try{inbox.Append(new(new string('c',32),"user:1",hash,bytes.Length,0,Convert.ToBase64String(bad)));throw new Exception("mismatching chunk accepted");}catch(InvalidDataException ex) when(ex.Message=="INBOX_UPLOAD_CHUNK_MISMATCH"){}
Console.WriteLine("PASS: initial browser-size upload, same ZIP/new session, completed-session replay, exact progress semantics, foreign actor and wrong bytes refusal; completed blob unchanged.");
