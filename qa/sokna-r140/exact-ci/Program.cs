using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;
using System.Text.Json.Nodes;
using Sokna.Maintenance.Core;

static void Check(bool pass, string what)
{
    if (!pass) throw new Exception("R140_EXACT_CI_" + what);
    Console.WriteLine("PASS " + what);
}
static string Sha(byte[] data) => Convert.ToHexString(SHA256.HashData(data)).ToLowerInvariant();

if (args.Length != 2) throw new Exception("Expected exact original R75 and R140 ZIP paths.");
var oldZip = Path.GetFullPath(args[0]);
var targetZip = Path.GetFullPath(args[1]);
using var oldArchive = ZipFile.OpenRead(oldZip);
using var targetArchive = ZipFile.OpenRead(targetZip);

static byte[] Entry(ZipArchive archive, string name)
{
    using var e = archive.GetEntry(name)?.Open() ?? throw new InvalidDataException("Missing " + name);
    using var m = new MemoryStream();
    e.CopyTo(m);
    return m.ToArray();
}
var oldJson = JsonDocument.Parse(Entry(oldArchive, "release-set.json"));
var targetJson = JsonDocument.Parse(Entry(targetArchive, "release-set.json"));
var origSource = oldJson.RootElement.GetProperty("targets").GetProperty("local");
var baselines = targetJson.RootElement.GetProperty("supported_baselines").EnumerateArray().ToArray();
var matching = baselines.Where(x =>
{
    var local = x.GetProperty("local");
    return local.ValueKind == JsonValueKind.Object
        && local.GetProperty("version").GetString() == origSource.GetProperty("version").GetString()
        && local.GetProperty("managed_tree_sha256").GetString() == origSource.GetProperty("managed_tree_sha256").GetString()
        && local.GetProperty("schema_fingerprint").GetString() == origSource.GetProperty("schema_fingerprint").GetString()
        && local.GetProperty("migration_head").GetString() == origSource.GetProperty("migration_head").GetString();
}).ToArray();
Check(matching.Length == 1, "R75 exact baseline uniquely supported");
Check(targetJson.RootElement.GetProperty("for_install").GetBoolean() == false, "original remains NOT_FOR_INSTALL");
Check(targetJson.RootElement.GetProperty("production_pass").GetBoolean() == false, "original remains NOT_PRODUCTION");

var source = matching[0].GetProperty("local");
var identity = new ReleaseComponentIdentity(
    source.GetProperty("release_id").GetString()!,
    source.GetProperty("version").GetString()!,
    source.GetProperty("managed_tree_sha256").GetString()!,
    source.GetProperty("schema_fingerprint").GetString()!,
    source.GetProperty("migration_head").GetString()!,
    source.GetProperty("authority_epoch_policy").GetString()!);
var installed = new InstalledReleaseIdentity(identity, null, "0.3.22", "0.3.22", null, null);
var root = Path.Combine(Path.GetTempPath(), "sokna-r140-exact-qa-" + Guid.NewGuid().ToString("N"));
try
{
    var paths = new MaintenancePaths(root);
    Directory.CreateDirectory(paths.InboxRoot);
    var exactBytes = File.ReadAllBytes(targetZip);
    Check(Sha(exactBytes) == "63cb6f20033372094a823fc724fbb3d7b6fcd285c98abee28f250482babe6c9b", "exact R140 original archive bytes");
    var inboxSource = Path.Combine(paths.InboxRoot, Sha(exactBytes) + ".zip");
    File.WriteAllBytes(inboxSource, exactBytes);
    var engine = new ReleaseSetVerifierStager(paths);
    var result = engine.VerifyOnly(inboxSource, installed);
    Check(result.ArtifactIntegrityVerified && result.ExactBaselineVerified == true && result.OperatorAuthorized,
        "actual Maintenance VerifyOnly exact R140 ZIP with exact R75");
    var blocked = false;
    try { engine.VerifyAndStage(inboxSource, installed); }
    catch (InvalidDataException ex) when (ex.Message.Contains("RELEASE_SET_NOT_INSTALLABLE_STAGE_FORBIDDEN"))
    { blocked = true; }
    Check(blocked, "unqualified original fail-closed before staging");
    Check(!Directory.Exists(paths.StagingRoot), "unqualified original did not create Stage");

    // The following synthetic qualified manifest is a TEMPORARY CI TEST FIXTURE.
    // It is never distributed, published or copied back to the user.
    // It exercises the existing stage mechanics with identical original Local web bytes.
    var fixtureManifest = JsonNode.Parse(Entry(targetArchive, "release-set.json"))
                          ?? throw new InvalidDataException("manifest missing");
    fixtureManifest["for_install"] = true;
    fixtureManifest["production_pass"] = true;
    fixtureManifest["status"] = "R140_CI_DISPOSABLE_QUALIFIED";
    var q = fixtureManifest["qualification"] ?? throw new InvalidDataException("qualification missing");
    q["failure_matrix_id"] = "R140_CI_DISPOSABLE_ISOLATED_NOT_A_REAL_PRODUCTION_APPROVAL";
    q["failure_matrix_cases_required"] = 80;
    q["canary"] = true;
    q["real_windows"] = true;
    q["real_mariadb"] = true;
    q["recovery_console_web_down"] = true;
    q["business_backup_consistency_real_mariadb"] = true;
    var stagedFixturePath = Path.Combine(root, "fixture.zip");
    var actualWeb = Entry(targetArchive, "artifacts/local/web.zip");
    using (var zip = ZipFile.Open(stagedFixturePath, ZipArchiveMode.Create))
    {
        using (var dest = zip.CreateEntry("release-set.json").Open())
        {
            var json = JsonSerializer.SerializeToUtf8Bytes(fixtureManifest);
            dest.Write(json);
        }
        using (var dest = zip.CreateEntry("artifacts/local/web.zip").Open())
            dest.Write(actualWeb);
    }
    var fixtureBytes = File.ReadAllBytes(stagedFixturePath);
    var fixtureInbox = Path.Combine(paths.InboxRoot, Sha(fixtureBytes) + ".zip");
    File.Copy(stagedFixturePath, fixtureInbox);
    var qualifiedCheck = engine.VerifyOnly(fixtureInbox, installed);
    Check(qualifiedCheck.ArtifactIntegrityVerified && qualifiedCheck.OperatorAuthorized,
        "CI synthetic qualified package VerifyOnly");
    var staged = engine.VerifyAndStage(fixtureInbox, installed);
    Check(!string.IsNullOrWhiteSpace(staged.StageRoot) && Directory.Exists(staged.StageRoot),
        "CI synthetic qualified package Stage with real original Local web");
    Check(File.Exists(Path.Combine(staged.StageRoot, "stage-receipt.json")),
        "CI synthetic qualified stage durable receipt");
    Console.WriteLine("R140_EXACT_WINDOWS_MAINTENANCE_STAGE_QA_PASS");
}
finally
{
    try { if (Directory.Exists(root)) Directory.Delete(root, true); } catch { }
}
