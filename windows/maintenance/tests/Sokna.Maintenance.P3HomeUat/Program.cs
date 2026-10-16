using System.Text;
using System.Text.Json;
using System.Windows.Forms;
using Sokna.Maintenance.Core;

const string ExpectedBuild = "3.0.0-g6.2-workspace.231ai-browser-recovery-managed-inventory";
const int ExpectedManagedFiles = 497;
const string IdempotencyKey = "home-p3-231ai-r1";

Application.EnableVisualStyles();
Application.SetCompatibleTextRenderingDefault(false);

var confirm = MessageBox.Show(
    "این آزمون، Local سالم 231ai را به ساختار release-slot تبدیل می‌کند، فقط SoknaApache را در مرز فعال‌سازی متوقف/راه‌اندازی می‌کند و چرخه A→B→A را با همان build انجام می‌دهد.\n\nPublic و دیتابیس تغییر نمی‌کنند و Windows reboot نمی‌شود.\n\nادامه می‌دهید؟",
    "SOKNA Maintenance V4 — Phase 3 Home UAT",
    MessageBoxButtons.YesNo,
    MessageBoxIcon.Warning,
    MessageBoxDefaultButton.Button2);
if (confirm != DialogResult.Yes) return;

var desktop = Environment.GetFolderPath(Environment.SpecialFolder.DesktopDirectory);
var resultPath = Path.Combine(desktop, "SOKNA-Phase3-Home-UAT-result.json");
try
{
    var paths = new MaintenancePaths(MaintenancePaths.DefaultDataRoot());
    if (!File.Exists(paths.DescriptorPath)) throw new InvalidDataException("Maintenance Supervisor control-plane descriptor is missing.");
    var descriptor = AtomicFile.ReadJson<ControlPlaneDescriptor>(paths.DescriptorPath);
    if (descriptor.Protocol != HmacProtocol.Protocol || descriptor.ReadOnlyP1)
        throw new InvalidDataException("Installed Maintenance Supervisor does not expose the Phase 3 mutation contract.");
    if (!Uri.TryCreate(descriptor.BaseUrl, UriKind.Absolute, out var baseUri) || !baseUri.IsLoopback)
        throw new InvalidDataException("Maintenance Supervisor base URL is not loopback-only.");

    var key = new SecretStore(paths).LoadKey("recovery-console");
    var body = JsonSerializer.SerializeToUtf8Bytes(new LocalSlotHomeCycleRequest(ExpectedBuild, ExpectedManagedFiles, "home-uat"), AtomicFile.JsonOptions);
    using var request = HmacProtocol.CreateSignedJson(baseUri, "v1/local-slots/home-cycle", "recovery-console", key, body, IdempotencyKey);
    using var handler = new HttpClientHandler { UseProxy = false, AllowAutoRedirect = false };
    using var client = new HttpClient(handler) { Timeout = TimeSpan.FromMinutes(5) };
    using var response = await client.SendAsync(request);
    var responseBody = await response.Content.ReadAsStringAsync();
    File.WriteAllText(resultPath, responseBody, new UTF8Encoding(false));

    var ok = false;
    var state = "unknown";
    var code = "unknown";
    try
    {
        using var doc = JsonDocument.Parse(responseBody);
        ok = doc.RootElement.TryGetProperty("ok", out var okElement) && okElement.GetBoolean();
        if (doc.RootElement.TryGetProperty("state", out var stateElement)) state = stateElement.GetString() ?? state;
        if (doc.RootElement.TryGetProperty("code", out var codeElement)) code = codeElement.GetString() ?? code;
    }
    catch (JsonException)
    {
    }

    if (response.IsSuccessStatusCode && ok && state == "committed")
    {
        MessageBox.Show(
            $"Phase 3 Home UAT با موفقیت کامل شد.\n\nLocal همچنان 231ai است، اما Web اکنون از release slots فعال می‌شود.\n\nEvidence:\n{resultPath}",
            "SOKNA Phase 3 — PASS",
            MessageBoxButtons.OK,
            MessageBoxIcon.Information);
    }
    else
    {
        MessageBox.Show(
            $"Phase 3 Home UAT کامل نشد. Force یا اقدام دستی انجام ندهید.\n\nState: {state}\nCode: {code}\n\nEvidence:\n{resultPath}",
            "SOKNA Phase 3 — STOP",
            MessageBoxButtons.OK,
            MessageBoxIcon.Error);
    }
}
catch (Exception ex)
{
    var payload = JsonSerializer.Serialize(new
    {
        ok = false,
        state = "launcher_failed",
        code = "P3_HOME_UAT_LAUNCHER_FAILED",
        error = ex.ToString(),
        expected_build = ExpectedBuild,
        expected_managed_files = ExpectedManagedFiles,
        database_mutation_performed = false,
        public_mutation_performed = false,
        windows_reboot_required = false,
        captured_at_utc = DateTimeOffset.UtcNow
    }, AtomicFile.JsonOptions);
    try { File.WriteAllText(resultPath, payload, new UTF8Encoding(false)); } catch { }
    MessageBox.Show(
        $"Launcher قبل از تکمیل UAT متوقف شد. Force نکنید.\n\n{ex.Message}\n\nLog:\n{resultPath}",
        "SOKNA Phase 3 — STOP",
        MessageBoxButtons.OK,
        MessageBoxIcon.Error);
}
