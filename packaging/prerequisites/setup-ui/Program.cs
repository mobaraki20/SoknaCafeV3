using Microsoft.Win32;
using System.Diagnostics;
using System.IO.Compression;
using System.Net;
using System.Net.Http.Headers;
using System.Net.Sockets;
using System.Runtime.InteropServices;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Text.RegularExpressions;

namespace Sokna.Prerequisites.Setup;

internal sealed class InfrastructurePolicy
{
    [JsonPropertyName("format")] public string Format { get; init; } = "";
    [JsonPropertyName("schema_version")] public int SchemaVersion { get; init; }
    [JsonPropertyName("automatic_download_allowed")] public bool AutomaticDownloadAllowed { get; init; }
    [JsonPropertyName("automatic_install_allowed")] public bool AutomaticInstallAllowed { get; init; }
    [JsonPropertyName("local_web_payload_allowed")] public bool LocalWebPayloadAllowed { get; init; }
    [JsonPropertyName("database_application_provisioning_allowed")] public bool DatabaseApplicationProvisioningAllowed { get; init; }
}

internal sealed class ReleaseLock
{
    [JsonPropertyName("format")] public string Format { get; init; } = "";
    [JsonPropertyName("schema_version")] public int SchemaVersion { get; init; }
    [JsonPropertyName("release_frozen")] public bool ReleaseFrozen { get; init; }
    [JsonPropertyName("artifacts")] public List<LockedArtifact> Artifacts { get; init; } = [];
}

internal sealed class LockedArtifact
{
    [JsonPropertyName("dependency")] public string Dependency { get; init; } = "";
    [JsonPropertyName("version")] public string Version { get; init; } = "";
    [JsonPropertyName("filename")] public string FileName { get; init; } = "";
    [JsonPropertyName("source_url")] public string SourceUrl { get; init; } = "";
    [JsonPropertyName("fallback_urls")] public List<string> FallbackUrls { get; init; } = [];
    [JsonPropertyName("sha256")] public string Sha256 { get; init; } = "";
    [JsonPropertyName("size")] public long Size { get; init; }
}

internal enum OperationMode { Install, Repair, Recover }

internal sealed record ProcessResult(int ExitCode, string Output, string Error);

internal static class PhpRuntimeConfiguration
{
    public static readonly string[] RequiredWebExtensions = ["pdo","pdo_mysql","json","mbstring","sodium","zlib","zip","session"];
    public static readonly string[] RequiredCliExtensions = ["pdo","pdo_mysql","json","mbstring","sodium","zlib","zip","session","fileinfo","openssl"];
    public static readonly string[] RequiredDlls = ["php_fileinfo.dll","php_mbstring.dll","php_mysqli.dll","php_pdo_mysql.dll","php_openssl.dll","php_sodium.dll","php_zip.dll"];

    public static void Configure(string phpPath)
    {
        var ini = Path.Combine(phpPath, "php.ini");
        if (!File.Exists(ini))
        {
            var source = Path.Combine(phpPath, "php.ini-production");
            if (!File.Exists(source)) throw new InvalidOperationException("php.ini-production پیدا نشد.");
            File.Copy(source, ini, true);
        }

        var extDir = Path.Combine(phpPath, "ext").Replace('\\','/');
        var text = File.ReadAllText(ini, Encoding.UTF8);
        var extDirRx = new Regex(@"(?im)^\s*;?\s*extension_dir\s*=.*$");
        if (extDirRx.IsMatch(text)) text = extDirRx.Replace(text, $"extension_dir = \"{extDir}\"", 1);
        else text += Environment.NewLine + $"extension_dir = \"{extDir}\"";

        foreach (var dll in RequiredDlls)
        {
            var dllPath = Path.Combine(phpPath, "ext", dll);
            if (!File.Exists(dllPath))
                throw new InvalidOperationException($"PHP extension DLL داخل بسته رسمی پیدا نشد: {dll}");
            var rx = new Regex(@"(?im)^\s*;?\s*extension\s*=\s*" + Regex.Escape(dll) + @"\s*$");
            if (rx.IsMatch(text)) text = rx.Replace(text, $"extension={dll}", 1);
            else text += Environment.NewLine + $"extension={dll}";
        }
        File.WriteAllText(ini, text, new UTF8Encoding(false));
    }

    public static bool ConfigurationReady(string phpPath)
    {
        try
        {
            var ini = Path.Combine(phpPath, "php.ini");
            if (!File.Exists(ini)) return false;
            var text = File.ReadAllText(ini, Encoding.UTF8);
            var m = Regex.Match(text, @"(?im)^\s*extension_dir\s*=\s*[""']?(?<v>[^""'\r\n]+)[""']?\s*$");
            if (!m.Success) return false;
            var configured = m.Groups["v"].Value.Trim();
            return InfrastructureOwnershipDetector.PathEquals(configured, Path.Combine(phpPath, "ext"));
        }
        catch { return false; }
    }

    public static int ConfigureForQualification(string phpPath)
    {
        try
        {
            Configure(phpPath);
            Console.WriteLine("PHP_CONFIG_READY="+ConfigurationReady(phpPath));
            Console.WriteLine("PHP_INI="+Path.Combine(phpPath,"php.ini"));
            Console.WriteLine("PHP_EXT_DIR="+Path.Combine(phpPath,"ext"));
            return ConfigurationReady(phpPath)?0:71;
        }
        catch(Exception ex)
        {
            Console.Error.WriteLine(ex);
            return 72;
        }
    }
}

internal static class LocalEndpointPortPolicy
{
    public static readonly int[] FallbackCandidates = [18081, 18082, 18083, 8080, 8081, 8088, 8000, 8888];

    public static int SelectPort(int selected, Func<int, bool> canBind)
    {
        if (selected is < 1024 or > 65535) return 0;
        if (canBind(selected)) return selected;
        foreach (var candidate in FallbackCandidates)
            if (candidate != selected && canBind(candidate)) return candidate;
        return 0;
    }

    public static bool CanBindLoopback(int port, out string? error)
    {
        TcpListener? listener = null;
        try
        {
            if (port is < 1024 or > 65535)
            {
                error = "Port outside Local Web allowed range.";
                return false;
            }
            listener = new TcpListener(IPAddress.Loopback, port);
            listener.Start();
            error = null;
            return true;
        }
        catch (SocketException ex)
        {
            error = $"SocketError={ex.SocketErrorCode}; NativeError={ex.ErrorCode}; {ex.Message}";
            return false;
        }
        catch (Exception ex)
        {
            error = ex.Message;
            return false;
        }
        finally
        {
            try { listener?.Stop(); } catch { }
        }
    }

    public static int SelfTest()
    {
        if (SelectPort(18080, p => p == 18080) != 18080) return 11;
        if (SelectPort(18080, p => p == 18081) != 18081) return 12;
        if (SelectPort(18080, _ => false) != 0) return 13;
        if (SelectPort(80, _ => true) != 0) return 14;

        using var occupied = new TcpListener(IPAddress.Loopback, 0);
        occupied.Start();
        var occupiedPort = ((IPEndPoint)occupied.LocalEndpoint).Port;
        if (CanBindLoopback(occupiedPort, out _)) return 15;
        occupied.Stop();

        if (!CanBindLoopback(occupiedPort, out _)) return 16;
        return 0;
    }
}


internal sealed record InfrastructureOwnershipReport(
    string TargetRoot,
    IReadOnlyList<string> Conflicts,
    IReadOnlyList<string> Evidence)
{
    public bool HasConflict => Conflicts.Count > 0;

    public string ToUserMessage(OperationMode mode)
    {
        var sb=new StringBuilder();
        sb.AppendLine("یک نصب قبلی SOKNA/MariaDB خارج از Root انتخاب‌شده پیدا شد.");
        sb.AppendLine();
        sb.AppendLine($"Root انتخاب‌شده: {TargetRoot}");
        foreach(var line in Conflicts) sb.AppendLine("• "+line);
        sb.AppendLine();
        sb.AppendLine("برای جلوگیری از split-root، تغییر ناخواسته سرویس‌ها یا ورود Windows Installer به Maintenance Mode، این عملیات قبل از هر تغییر متوقف شد.");
        sb.AppendLine("هیچ Data یا نصب قبلی به‌صورت خودکار حذف یا منتقل نمی‌شود.");
        sb.AppendLine();
        sb.AppendLine(mode switch
        {
            OperationMode.Install => "اگر نصب قبلی را می‌خواهید، Root همان نصب را انتخاب و Repair/Recover کنید. اگر واقعاً نصب تازه روی Root جدید می‌خواهید، ابتدا از Data قبلی Backup بگیرید و نصب/registration قبلی را آگاهانه تعیین تکلیف کنید.",
            OperationMode.Repair => "Repair باید روی همان Root نصب موجود اجرا شود. Root را به مسیر نصب قبلی تغییر دهید.",
            _ => "Recover باید روی Root مربوط به Data/Infrastructure مورد بازیابی اجرا شود. Root را به مسیر نصب قبلی تغییر دهید."
        });
        return sb.ToString().Trim();
    }
}

internal static class InfrastructureOwnershipDetector
{
    private const string UninstallPath=@"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall";

    public static InfrastructureOwnershipReport Detect(string targetRoot,string expectedMariaVersion)
    {
        var target=Normalize(targetRoot);
        var conflicts=new List<string>();
        var evidence=new List<string>();

        InspectManagedService("SoknaApache","Apache",target,null,conflicts,evidence);
        InspectManagedService("SoknaMariaDB","MariaDB",target,"--defaults-file",conflicts,evidence);

        var targetMaria=Normalize(Path.Combine(target,"Infrastructure","MariaDB"));
        var targetMariaPresent=File.Exists(Path.Combine(targetMaria,"bin","mariadbd.exe"))||File.Exists(Path.Combine(targetMaria,"bin","mysqld.exe"));
        foreach(var product in RegisteredMariaProducts(expectedMariaVersion))
        {
            var display=product.DisplayName+(string.IsNullOrWhiteSpace(product.Version)?"":$" {product.Version}");
            if(string.IsNullOrWhiteSpace(product.InstallLocation))
            {
                evidence.Add($"WindowsInstaller: {display}; InstallLocation=<unknown>; Source={product.Source}");
                if(!targetMariaPresent)
                    conflicts.Add($"{display} در Windows Installer ثبت است ولی InstallLocation قابل تشخیص نیست و MariaDB در Root انتخاب‌شده وجود ندارد؛ اجرای MSI می‌تواند وارد Maintenance Mode نصب دیگری شود.");
                continue;
            }

            var installed=Normalize(product.InstallLocation!);
            evidence.Add($"WindowsInstaller: {display}; InstallLocation={installed}; Source={product.Source}");
            if(!PathEquals(installed,targetMaria))
                conflicts.Add($"{display} در «{installed}» ثبت شده ولی MariaDB این Root باید در «{targetMaria}» باشد.");
        }

        return new(target,conflicts.Distinct(StringComparer.OrdinalIgnoreCase).ToArray(),evidence.Distinct(StringComparer.OrdinalIgnoreCase).ToArray());
    }

    private static void InspectManagedService(
        string serviceName,
        string component,
        string targetRoot,
        string? dataArgument,
        List<string> conflicts,
        List<string> evidence)
    {
        var image=ReadServiceImagePath(serviceName);
        if(string.IsNullOrWhiteSpace(image)) return;

        evidence.Add($"{serviceName}: ImagePath={image}");
        var exe=ExtractExecutablePath(image!);

        // Apache has no application/business data of its own. If its SOKNA-owned
        // service registration points to an executable that no longer exists,
        // treat it as a recoverable orphan rather than a live cross-root owner.
        if(string.Equals(component,"Apache",StringComparison.OrdinalIgnoreCase) &&
           exe is not null && !File.Exists(exe))
        {
            evidence.Add($"{serviceName}: stale registration; executable is missing and may be safely rebound during Repair/Install.");
            return;
        }

        var existingRoot=exe is null?null:InferSoknaRootFromInfrastructureExecutable(exe,component);
        if(existingRoot is null)
        {
            conflicts.Add($"سرویس {serviceName} وجود دارد اما Root آن از ImagePath قابل تشخیص نیست: {image}");
        }
        else if(!PathEquals(existingRoot,targetRoot))
        {
            conflicts.Add($"سرویس {serviceName} متعلق به Root «{existingRoot}» است، نه «{targetRoot}».");
        }

        if(dataArgument is not null)
        {
            var defaults=ExtractArgumentPath(image!,dataArgument);
            if(!string.IsNullOrWhiteSpace(defaults))
            {
                evidence.Add($"{serviceName}: defaults-file={defaults}");
                var dataRoot=InferSoknaRootFromDataPath(defaults!);
                if(dataRoot is not null && !PathEquals(dataRoot,targetRoot))
                    conflicts.Add($"Data سرویس {serviceName} متعلق به Root «{dataRoot}» است، نه «{targetRoot}».");
            }
        }
    }

    internal static string? ReadServiceImagePath(string serviceName)
    {
        try
        {
            using var key=Registry.LocalMachine.OpenSubKey($@"SYSTEM\CurrentControlSet\Services\{serviceName}");
            var raw=key?.GetValue("ImagePath")?.ToString();
            return string.IsNullOrWhiteSpace(raw)?null:Environment.ExpandEnvironmentVariables(raw.Trim());
        }
        catch{return null;}
    }

    internal static string? ExtractExecutablePath(string commandLine)
    {
        var text=Environment.ExpandEnvironmentVariables(commandLine).Trim();
        if(text.Length==0) return null;
        if(text[0]=='"')
        {
            var end=text.IndexOf('"',1);
            return end>1?SafeFull(text[1..end]):null;
        }

        var exe=text.IndexOf(".exe",StringComparison.OrdinalIgnoreCase);
        if(exe<0) return null;
        return SafeFull(text[..(exe+4)].Trim());
    }

    internal static string? ExtractArgumentPath(string commandLine,string argument)
    {
        var pattern=Regex.Escape(argument)+"\\s*=\\s*(?:\\\"(?<q>[^\\\"]+)\\\"|(?<u>[^\\s]+))";
        var rx=new Regex(pattern,RegexOptions.IgnoreCase);
        var m=rx.Match(commandLine);
        if(!m.Success) return null;
        var value=m.Groups["q"].Success?m.Groups["q"].Value:m.Groups["u"].Value;
        return SafeFull(Environment.ExpandEnvironmentVariables(value));
    }

    internal static string? InferSoknaRootFromInfrastructureExecutable(string executable,string component)
    {
        var full=SafeFull(executable);
        if(full is null) return null;
        var marker=Path.DirectorySeparatorChar+"Infrastructure"+Path.DirectorySeparatorChar+component+Path.DirectorySeparatorChar;
        var i=full.IndexOf(marker,StringComparison.OrdinalIgnoreCase);
        return i>1?Normalize(full[..i]):null;
    }

    internal static string? InferSoknaRootFromDataPath(string path)
    {
        var full=SafeFull(path);
        if(full is null) return null;
        var marker=Path.DirectorySeparatorChar+"Data"+Path.DirectorySeparatorChar+"MariaDB"+Path.DirectorySeparatorChar;
        var i=full.IndexOf(marker,StringComparison.OrdinalIgnoreCase);
        if(i<0)
        {
            marker=Path.DirectorySeparatorChar+"Data"+Path.DirectorySeparatorChar+"MariaDB";
            i=full.IndexOf(marker,StringComparison.OrdinalIgnoreCase);
        }
        return i>1?Normalize(full[..i]):null;
    }

    private const int ErrorSuccess=0;
    private const int ErrorNoMoreItems=259;
    private const int ErrorMoreData=234;

    [DllImport("msi.dll",CharSet=CharSet.Unicode)]
    private static extern int MsiEnumProducts(int iProductIndex,StringBuilder lpProductBuf);

    [DllImport("msi.dll",CharSet=CharSet.Unicode)]
    private static extern int MsiGetProductInfo(string szProduct,string szProperty,StringBuilder lpValueBuf,ref int pcchValueBuf);

    private static string MsiProperty(string productCode,string property)
    {
        var size=0;
        var probe=new StringBuilder(1);
        var rc=MsiGetProductInfo(productCode,property,probe,ref size);
        if(rc!=ErrorMoreData && rc!=ErrorSuccess) return "";
        size=Math.Max(size+1,2);
        var buffer=new StringBuilder(size);
        rc=MsiGetProductInfo(productCode,property,buffer,ref size);
        return rc==ErrorSuccess?buffer.ToString().Trim():"";
    }

    private static IEnumerable<(string DisplayName,string Version,string? InstallLocation,string Source)> RegisteredMariaProducts(string expectedVersion)
    {
        var seen=new HashSet<string>(StringComparer.OrdinalIgnoreCase);

        for(var i=0;;i++)
        {
            var code=new StringBuilder(39);
            var rc=MsiEnumProducts(i,code);
            if(rc==ErrorNoMoreItems) break;
            if(rc!=ErrorSuccess) break;
            var productCode=code.ToString();
            var name=MsiProperty(productCode,"ProductName");
            var version=MsiProperty(productCode,"VersionString");
            if(name.IndexOf("MariaDB",StringComparison.OrdinalIgnoreCase)<0) continue;
            if(!string.IsNullOrWhiteSpace(expectedVersion) && !VersionMatches(version,expectedVersion)) continue;
            var location=MsiProperty(productCode,"InstallLocation");
            var key=$"msi:{productCode}";
            if(seen.Add(key))
                yield return(name,version,string.IsNullOrWhiteSpace(location)?null:location,$"MSI:{productCode}");
        }

        foreach(var view in new[]{RegistryView.Registry64,RegistryView.Registry32})
        {
            RegistryKey? baseKey=null;
            RegistryKey? uninstall=null;
            try
            {
                baseKey=RegistryKey.OpenBaseKey(RegistryHive.LocalMachine,view);
                uninstall=baseKey.OpenSubKey(UninstallPath);
                if(uninstall is null) continue;
                foreach(var sub in uninstall.GetSubKeyNames())
                {
                    using var key=uninstall.OpenSubKey(sub);
                    if(key is null) continue;
                    var name=key.GetValue("DisplayName")?.ToString()?.Trim()??"";
                    var version=key.GetValue("DisplayVersion")?.ToString()?.Trim()??"";
                    if(name.IndexOf("MariaDB",StringComparison.OrdinalIgnoreCase)<0) continue;
                    if(!string.IsNullOrWhiteSpace(expectedVersion) && !VersionMatches(version,expectedVersion)) continue;
                    var location=key.GetValue("InstallLocation")?.ToString()?.Trim();
                    var id=$"registry:{view}:{sub}";
                    if(!seen.Add(id)) continue;
                    yield return(name,version,string.IsNullOrWhiteSpace(location)?null:location,$@"HKLM({view})\{UninstallPath}\{sub}");
                }
            }
            finally
            {
                uninstall?.Dispose();
                baseKey?.Dispose();
            }
        }
    }

    internal static bool VersionMatches(string actual,string expected)
    {
        if(string.Equals(actual?.Trim(),expected?.Trim(),StringComparison.OrdinalIgnoreCase)) return true;
        if(Version.TryParse(actual?.Trim(),out var a)&&Version.TryParse(expected?.Trim(),out var e))
            return a.Major==e.Major&&a.Minor==e.Minor&&a.Build==e.Build;
        return false;
    }

    internal static bool PathEquals(string a,string b)
    {
        try{return string.Equals(Normalize(a),Normalize(b),StringComparison.OrdinalIgnoreCase);}
        catch{return false;}
    }

    internal static string Normalize(string p)
    {
        var full=Path.GetFullPath(Environment.ExpandEnvironmentVariables(p.Trim().Trim('"')));
        return full.TrimEnd(Path.DirectorySeparatorChar,Path.AltDirectorySeparatorChar);
    }

    private static string? SafeFull(string p)
    {
        try{return Normalize(p);}catch{return null;}
    }

    public static int SelfTest()
    {
        if(!PathEquals(@"D:\SOKNA\",@"d:\sokna")) return 31;
        var cmd="\"D:\\SOKNA\\Infrastructure\\MariaDB\\bin\\mariadbd.exe\" --defaults-file=\"D:\\SOKNA\\Data\\MariaDB\\my.ini\"";
        var exe=ExtractExecutablePath(cmd);
        if(exe is null || !exe.EndsWith(@"D:\SOKNA\Infrastructure\MariaDB\bin\mariadbd.exe",StringComparison.OrdinalIgnoreCase)) return 32;
        var defaults=ExtractArgumentPath(cmd,"--defaults-file");
        if(defaults is null || !defaults.EndsWith(@"D:\SOKNA\Data\MariaDB\my.ini",StringComparison.OrdinalIgnoreCase)) return 33;
        if(!PathEquals(InferSoknaRootFromInfrastructureExecutable(exe,"MariaDB")??"", @"D:\SOKNA")) return 34;
        if(!PathEquals(InferSoknaRootFromDataPath(defaults)??"", @"D:\SOKNA")) return 35;
        if(PathEquals(@"D:\SOKNA",@"E:\SOKNA")) return 36;
        return 0;
    }

    public static int Probe(string targetRoot,string expectedMariaVersion)
    {
        var report=Detect(targetRoot,expectedMariaVersion);
        Console.WriteLine($"TargetRoot={report.TargetRoot}");
        foreach(var e in report.Evidence) Console.WriteLine("EVIDENCE "+e);
        foreach(var c in report.Conflicts) Console.WriteLine("CONFLICT "+c);
        return report.HasConflict?42:0;
    }
}

internal static class Program
{
    [STAThread]
    static int Main(string[] args)
    {
        if (args.Any(x => string.Equals(x, "--self-test-endpoint-port", StringComparison.OrdinalIgnoreCase)))
            return LocalEndpointPortPolicy.SelfTest();
        if (args.Any(x => string.Equals(x, "--self-test-infrastructure-ownership", StringComparison.OrdinalIgnoreCase)))
            return InfrastructureOwnershipDetector.SelfTest();
        var probeIndex=Array.FindIndex(args,x=>string.Equals(x,"--probe-infrastructure-ownership",StringComparison.OrdinalIgnoreCase));
        if(probeIndex>=0)
        {
            if(args.Length<=probeIndex+2) return 43;
            return InfrastructureOwnershipDetector.Probe(args[probeIndex+1],args[probeIndex+2]);
        }
        var phpConfigIndex=Array.FindIndex(args,x=>string.Equals(x,"--qualify-php-config",StringComparison.OrdinalIgnoreCase));
        if(phpConfigIndex>=0)
        {
            if(args.Length<=phpConfigIndex+1) return 73;
            return PhpRuntimeConfiguration.ConfigureForQualification(args[phpConfigIndex+1]);
        }

        ApplicationConfiguration.Initialize();
        Application.Run(new MainForm());
        return 0;
    }
}

internal sealed class MainForm : Form
{
    private readonly InfrastructurePolicy _policy;
    private readonly ReleaseLock _lock;
    private readonly HttpClient _http = new(new SocketsHttpHandler { AutomaticDecompression = DecompressionMethods.All })
    {
        Timeout = TimeSpan.FromMinutes(30)
    };
    private CancellationTokenSource? _operationCts;

    private readonly TextBox _root = new();
    private readonly NumericUpDown _apachePort = new() { Minimum = 1024, Maximum = 65535, Value = 18080, Width = 110, TextAlign = HorizontalAlignment.Left };
    private readonly RadioButton _install = new() { Text = "نصب جدید", Checked = true, AutoSize = true };
    private readonly RadioButton _repair = new() { Text = "تعمیر نصب موجود", AutoSize = true };
    private readonly RadioButton _recover = new() { Text = "بازیابی بعد از نصب مجدد ویندوز", AutoSize = true };
    private readonly TextBox _password = new() { UseSystemPasswordChar = true };
    private readonly TextBox _password2 = new() { UseSystemPasswordChar = true };
    private readonly CheckBox _showPassword = new() { Text = "نمایش رمز", AutoSize = true };
    private readonly Label _paths = new() { AutoSize = true, Dock = DockStyle.Fill, TextAlign = ContentAlignment.TopRight, RightToLeft = RightToLeft.Yes };
    private readonly Label _modeHelp = new() { AutoSize = true, Dock = DockStyle.Fill, TextAlign = ContentAlignment.TopRight, RightToLeft = RightToLeft.Yes, Padding = new Padding(0, 6, 0, 8) };
    private readonly RichTextBox _status = new() { ReadOnly = true, Dock = DockStyle.Fill, BackColor = SystemColors.Window, BorderStyle = BorderStyle.FixedSingle, RightToLeft = RightToLeft.Yes, DetectUrls = false };
    private readonly ProgressBar _progress = new() { Dock = DockStyle.Fill, Minimum = 0, Maximum = 100 };
    private readonly Label _progressText = new() { AutoSize = true, Dock = DockStyle.Fill, TextAlign = ContentAlignment.MiddleRight };
    private readonly Button _run = new() { Text = "شروع", AutoSize = true, Padding = new Padding(14, 7, 14, 7) };
    private readonly Button _analyze = new() { Text = "بررسی وضعیت", AutoSize = true, Padding = new Padding(14, 7, 14, 7) };
    private readonly Button _browse = new() { Text = "انتخاب مسیر", AutoSize = true };
    private readonly Button _logs = new() { Text = "باز کردن لاگ‌ها", AutoSize = true };
    private readonly Button _support = new() { Text = "ساخت بسته پشتیبانی", AutoSize = true };
    private readonly Button _cancel = new() { Text = "لغو عملیات", AutoSize = true, Enabled = false };
    private readonly Button _offlineFolder = new() { Text = "انتخاب پوشه آفلاین", AutoSize = true };
    private readonly Dictionary<string, Label> _artifactStatusLabels = new(StringComparer.OrdinalIgnoreCase);
    private readonly Dictionary<string, ProgressBar> _artifactProgressBars = new(StringComparer.OrdinalIgnoreCase);
    private readonly Dictionary<string, Button> _artifactSelectButtons = new(StringComparer.OrdinalIgnoreCase);
    private string? _currentLog;
    private string? _detectedExistingRoot;
    private readonly string _sessionLog = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "SOKNA", "Prerequisites", "Logs", $"ui-{DateTime.Now:yyyyMMdd-HHmmss}.log");

    public MainForm()
    {
        Text = "آماده‌سازی زیرساخت SOKNA";
        Width = 1120;
        Height = 800;
        MinimumSize = new Size(900, 650);
        StartPosition = FormStartPosition.CenterScreen;
        AutoScaleMode = AutoScaleMode.Dpi;
        RightToLeft = RightToLeft.Yes;
        RightToLeftLayout = false;
        Font = PickFont();
        Icon = TryLoadIcon();

        _http.DefaultRequestHeaders.UserAgent.Add(new ProductInfoHeaderValue("SOKNA-Prerequisites", Application.ProductVersion));
        _policy = LoadJson<InfrastructurePolicy>("infrastructure-prerequisites.json", "سیاست زیرساخت");
        _lock = LoadJson<ReleaseLock>("release-lock.json", "فهرست نسخه‌های قفل‌شده");
        ValidateContracts();

        _detectedExistingRoot = DetectExistingInfrastructureRoot();
        if (!string.IsNullOrWhiteSpace(_detectedExistingRoot))
        {
            _root.Text = _detectedExistingRoot;
            _install.Checked = false;
            _repair.Checked = true;
        }
        else
        {
            _root.Text = DefaultRoot();
        }
        TryLoadExistingApachePort();
        _root.TextChanged += (_, _) => RefreshPathSummary();
        _apachePort.ValueChanged += (_, _) => RefreshPathSummary();
        _browse.Click += (_, _) => BrowseRoot();
        _analyze.Click += async (_, _) => await AnalyzeAsync();
        _run.Click += async (_, _) => await RunAsync();
        _logs.Click += (_, _) => OpenLogs();
        _support.Click += async (_, _) => await CreateSupportBundleAsync();
        _cancel.Click += (_, _) => _operationCts?.Cancel();
        _offlineFolder.Click += async (_, _) => await SelectOfflineFolderAsync();
        _showPassword.CheckedChanged += (_, _) => _password.UseSystemPasswordChar = _password2.UseSystemPasswordChar = !_showPassword.Checked;
        _install.CheckedChanged += (_, _) => RefreshModeHelp();
        _repair.CheckedChanged += (_, _) => RefreshModeHelp();
        _recover.CheckedChanged += (_, _) => RefreshModeHelp();

        Controls.Add(BuildUi());
        Load += (_, _) => FitToWorkingArea();
        EnsureSessionLog();
        Log($"Prerequisites UI started. Version={Application.ProductVersion}");
        RefreshPathSummary();
        RefreshModeHelp();
        RefreshArtifactSourceStatus();
    }

    private static Font PickFont()
    {
        foreach (var name in new[] { "Tahoma", "Segoe UI" })
        {
            try
            {
                using var f = new Font(name, 10.0f, FontStyle.Regular, GraphicsUnit.Point);
                if (string.Equals(f.Name, name, StringComparison.OrdinalIgnoreCase))
                    return new Font(name, 10.0f, FontStyle.Regular, GraphicsUnit.Point);
            }
            catch { }
        }
        return SystemFonts.MessageBoxFont;
    }

    private Icon? TryLoadIcon()
    {
        try
        {
            var p = Path.Combine(AppContext.BaseDirectory, "Sokna.ico");
            return File.Exists(p) ? new Icon(p) : null;
        }
        catch { return null; }
    }

    private Control BuildUi()
    {
        var root = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 1,
            RowCount = 3,
            RightToLeft = RightToLeft.Yes,
            Padding = new Padding(0),
            Margin = new Padding(0)
        };
        root.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        root.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        root.RowStyles.Add(new RowStyle(SizeType.AutoSize));

        var scroll = new Panel
        {
            Dock = DockStyle.Fill,
            AutoScroll = true,
            RightToLeft = RightToLeft.Yes,
            Padding = new Padding(24, 20, 24, 12)
        };

        var content = new TableLayoutPanel
        {
            Dock = DockStyle.Top,
            AutoSize = true,
            AutoSizeMode = AutoSizeMode.GrowAndShrink,
            ColumnCount = 1,
            RowCount = 8,
            RightToLeft = RightToLeft.Yes,
            Margin = new Padding(0),
            Padding = new Padding(0)
        };
        content.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));

        var title = new Label
        {
            Text = $"آماده‌سازی زیرساخت Local Web — نسخه {Application.ProductVersion}",
            Font = new Font(Font.FontFamily, 14.0f, FontStyle.Bold),
            AutoSize = true,
            Dock = DockStyle.Fill,
            TextAlign = ContentAlignment.MiddleRight,
            Padding = new Padding(0, 0, 0, 8)
        };
        var intro = new Label
        {
            AutoSize = true,
            Dock = DockStyle.Fill,
            TextAlign = ContentAlignment.TopRight,
            MaximumSize = new Size(1040, 0),
            Padding = new Padding(0, 0, 0, 12),
            Text = "این ابزار فقط PHP، Apache و MariaDB را آماده می‌کند. هیچ فایل Local Web را نصب یا کپی نمی‌کند و هیچ دیتابیس کاربردی SOKNA نمی‌سازد. بعد از پایان این مرحله، بسته Local Web را جداگانه مثل WordPress داخل مسیر Web قرار می‌دهید و نصب را در مرورگر انجام می‌دهید."
        };

        var modeBox = new GroupBox { Text = "حالت اجرا", Dock = DockStyle.Top, AutoSize = true, Padding = new Padding(14, 12, 14, 14), RightToLeft = RightToLeft.Yes };
        var modes = new FlowLayoutPanel
        {
            Dock = DockStyle.Top,
            AutoSize = true,
            FlowDirection = FlowDirection.RightToLeft,
            WrapContents = true,
            RightToLeft = RightToLeft.Yes,
            Padding = new Padding(4)
        };
        foreach (var radio in new[] { _install, _repair, _recover })
        {
            radio.RightToLeft = RightToLeft.Yes;
            radio.AutoSize = true;
            radio.Margin = new Padding(16, 4, 0, 4);
        }
        modes.Controls.AddRange([_install, _repair, _recover]);
        modeBox.Controls.Add(modes);

        var pathBox = new GroupBox { Text = "مسیر زیرساخت", Dock = DockStyle.Top, AutoSize = true, Padding = new Padding(14, 12, 14, 14), RightToLeft = RightToLeft.Yes };
        var pathLayout = new TableLayoutPanel { Dock = DockStyle.Top, AutoSize = true, ColumnCount = 3, RowCount = 3, RightToLeft = RightToLeft.No };
        pathLayout.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));
        pathLayout.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        pathLayout.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));
        _browse.MinimumSize = new Size(105, 34);
        _root.Dock = DockStyle.Fill;
        _root.RightToLeft = RightToLeft.No;
        _root.TextAlign = HorizontalAlignment.Left;
        _root.Margin = new Padding(8, 3, 8, 8);
        var rootLabel = new Label { Text = "ریشه:", AutoSize = true, Anchor = AnchorStyles.Right, RightToLeft = RightToLeft.Yes, TextAlign = ContentAlignment.MiddleRight, Padding = new Padding(8, 8, 0, 0) };
        pathLayout.Controls.Add(_browse, 0, 0);
        pathLayout.Controls.Add(_root, 1, 0);
        pathLayout.Controls.Add(rootLabel, 2, 0);
        _apachePort.RightToLeft = RightToLeft.No;
        _apachePort.Margin = new Padding(8, 3, 8, 7);
        var portLabel = new Label { Text = "پورت Apache:", AutoSize = true, Anchor = AnchorStyles.Right, RightToLeft = RightToLeft.Yes, TextAlign = ContentAlignment.MiddleRight, Padding = new Padding(8, 7, 0, 0) };
        var portHint = new Label { Text = "پورت Local Web مستقل از برنامه‌های دیگر است. پیش‌فرض 18080 است؛ اگر اشغال باشد، Setup یک پورت آزاد دیگر پیشنهاد می‌دهد.", AutoSize = true, Dock = DockStyle.Fill, RightToLeft = RightToLeft.Yes, TextAlign = ContentAlignment.MiddleRight, Padding = new Padding(4, 6, 4, 4) };
        pathLayout.Controls.Add(portHint, 0, 1);
        pathLayout.Controls.Add(_apachePort, 1, 1);
        pathLayout.Controls.Add(portLabel, 2, 1);
        _paths.Padding = new Padding(4, 2, 4, 0);
        pathLayout.Controls.Add(_paths, 0, 2);
        pathLayout.SetColumnSpan(_paths, 3);
        pathBox.Controls.Add(pathLayout);

        var sourcesBox = BuildArtifactSourcesBox();

        var dbBox = new GroupBox { Text = "MariaDB — فقط برای نصب جدید", Dock = DockStyle.Top, AutoSize = true, Padding = new Padding(14, 12, 14, 14), RightToLeft = RightToLeft.Yes };
        var db = new TableLayoutPanel { Dock = DockStyle.Top, AutoSize = true, ColumnCount = 3, RowCount = 3, RightToLeft = RightToLeft.No };
        db.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));
        db.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        db.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));
        _showPassword.RightToLeft = RightToLeft.Yes;
        _showPassword.Margin = new Padding(0, 6, 8, 0);
        _password.Dock = DockStyle.Fill;
        _password.RightToLeft = RightToLeft.No;
        _password.TextAlign = HorizontalAlignment.Left;
        _password.Margin = new Padding(8, 3, 8, 7);
        _password2.Dock = DockStyle.Fill;
        _password2.RightToLeft = RightToLeft.No;
        _password2.TextAlign = HorizontalAlignment.Left;
        _password2.Margin = new Padding(8, 3, 8, 7);
        var passLabel = new Label { Text = "رمز مدیر MariaDB:", AutoSize = true, Anchor = AnchorStyles.Right, RightToLeft = RightToLeft.Yes, TextAlign = ContentAlignment.MiddleRight, Padding = new Padding(8, 7, 0, 0) };
        var pass2Label = new Label { Text = "تکرار رمز:", AutoSize = true, Anchor = AnchorStyles.Right, RightToLeft = RightToLeft.Yes, TextAlign = ContentAlignment.MiddleRight, Padding = new Padding(8, 7, 0, 0) };
        db.Controls.Add(_showPassword, 0, 0);
        db.Controls.Add(_password, 1, 0);
        db.Controls.Add(passLabel, 2, 0);
        db.Controls.Add(new Panel { Width = 1, Height = 1 }, 0, 1);
        db.Controls.Add(_password2, 1, 1);
        db.Controls.Add(pass2Label, 2, 1);
        var dbHelp = new Label
        {
            Text = "این رمز فقط هنگام راه‌اندازی اولیه MariaDB استفاده می‌شود و در Log یا State ذخیره نمی‌شود. اگر Data قبلی وجود داشته باشد، Setup اجازه راه‌اندازی مجدد دیتابیس را نمی‌دهد.",
            AutoSize = true,
            Dock = DockStyle.Fill,
            RightToLeft = RightToLeft.Yes,
            TextAlign = ContentAlignment.TopRight,
            MaximumSize = new Size(980, 0),
            Padding = new Padding(4, 4, 4, 0)
        };
        db.Controls.Add(dbHelp, 0, 2);
        db.SetColumnSpan(dbHelp, 3);
        dbBox.Controls.Add(db);

        var statusBox = new GroupBox { Text = "وضعیت و جزئیات", Dock = DockStyle.Top, Height = 250, Padding = new Padding(12), RightToLeft = RightToLeft.Yes };
        _status.Font = new Font(Font.FontFamily, 9.75f, FontStyle.Regular);
        _status.RightToLeft = RightToLeft.Yes;
        _status.WordWrap = true;
        statusBox.Controls.Add(_status);

        content.Controls.Add(title, 0, 0);
        content.Controls.Add(intro, 0, 1);
        content.Controls.Add(modeBox, 0, 2);
        content.Controls.Add(pathBox, 0, 3);
        content.Controls.Add(sourcesBox, 0, 4);
        content.Controls.Add(dbBox, 0, 5);
        content.Controls.Add(_modeHelp, 0, 6);
        content.Controls.Add(statusBox, 0, 7);
        scroll.Controls.Add(content);

        var progressLayout = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            AutoSize = true,
            ColumnCount = 1,
            RowCount = 2,
            Padding = new Padding(24, 4, 24, 2),
            RightToLeft = RightToLeft.Yes
        };
        _progressText.TextAlign = ContentAlignment.MiddleRight;
        _progressText.Padding = new Padding(0, 0, 0, 3);
        progressLayout.Controls.Add(_progressText, 0, 0);
        progressLayout.Controls.Add(_progress, 0, 1);

        var actions = new FlowLayoutPanel
        {
            Dock = DockStyle.Fill,
            AutoSize = true,
            FlowDirection = FlowDirection.RightToLeft,
            WrapContents = true,
            RightToLeft = RightToLeft.Yes,
            Padding = new Padding(24, 8, 24, 14)
        };
        foreach (var button in new[] { _run, _analyze, _logs, _support, _cancel })
        {
            button.MinimumSize = new Size(118, 38);
            button.Margin = new Padding(8, 0, 0, 0);
        }
        _run.MinimumSize = new Size(100, 38);
        actions.Controls.AddRange([_run, _analyze, _logs, _support, _cancel]);

        root.Controls.Add(scroll, 0, 0);
        root.Controls.Add(progressLayout, 0, 1);
        root.Controls.Add(actions, 0, 2);
        return root;
    }

    private Control BuildArtifactSourcesBox()
    {
        var box = new GroupBox
        {
            Text = "فایل‌های پیش‌نیاز و نصب آفلاین",
            Dock = DockStyle.Top,
            AutoSize = true,
            Padding = new Padding(14, 12, 14, 14),
            RightToLeft = RightToLeft.Yes
        };

        var layout = new TableLayoutPanel
        {
            Dock = DockStyle.Top,
            AutoSize = true,
            ColumnCount = 5,
            RowCount = 4,
            RightToLeft = RightToLeft.No
        };
        layout.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));
        layout.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 190));
        layout.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 38));
        layout.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 62));
        layout.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));

        var kitHelp = new Label
        {
            Text = "اگر اینترنت در دسترس نیست، فایل‌های رسمی را از هر روش دیگری تهیه کنید و جداگانه انتخاب کنید؛ یا پوشه Offline Kit را یک‌جا به برنامه بدهید. همه فایل‌ها قبل از استفاده با اندازه و SHA-256 قفل‌شده بررسی می‌شوند.",
            AutoSize = true,
            Dock = DockStyle.Fill,
            RightToLeft = RightToLeft.Yes,
            TextAlign = ContentAlignment.TopRight,
            MaximumSize = new Size(980, 0),
            Padding = new Padding(4, 0, 4, 8)
        };
        layout.Controls.Add(_offlineFolder, 0, 0);
        layout.Controls.Add(kitHelp, 1, 0);
        layout.SetColumnSpan(kitHelp, 4);

        var row = 1;
        foreach (var dependency in new[] { "php", "apache", "mariadb" })
        {
            var artifact = Artifact(dependency);
            var select = new Button
            {
                Text = "انتخاب فایل",
                AutoSize = true,
                MinimumSize = new Size(105, 34),
                Margin = new Padding(4, 5, 4, 5)
            };
            var progress = new ProgressBar
            {
                Dock = DockStyle.Fill,
                Minimum = 0,
                Maximum = 100,
                Margin = new Padding(8, 9, 8, 9)
            };
            var status = new Label
            {
                Text = "در انتظار بررسی",
                AutoSize = true,
                Dock = DockStyle.Fill,
                RightToLeft = RightToLeft.Yes,
                TextAlign = ContentAlignment.MiddleRight,
                Padding = new Padding(6, 8, 6, 6)
            };
            var details = new Label
            {
                Text = $"{artifact.Version}  •  {FormatBytes(artifact.Size)}\n{artifact.FileName}",
                AutoSize = true,
                Dock = DockStyle.Fill,
                RightToLeft = RightToLeft.No,
                TextAlign = ContentAlignment.MiddleLeft,
                Padding = new Padding(6, 4, 6, 4)
            };
            var name = new Label
            {
                Text = DependencyDisplayName(dependency),
                AutoSize = true,
                Anchor = AnchorStyles.Right,
                RightToLeft = RightToLeft.Yes,
                TextAlign = ContentAlignment.MiddleRight,
                Font = new Font(Font, FontStyle.Bold),
                Padding = new Padding(8, 8, 0, 0)
            };

            _artifactSelectButtons[dependency] = select;
            _artifactProgressBars[dependency] = progress;
            _artifactStatusLabels[dependency] = status;
            select.Click += async (_, _) => await SelectManualArtifactAsync(dependency, false);

            layout.Controls.Add(select, 0, row);
            layout.Controls.Add(progress, 1, row);
            layout.Controls.Add(status, 2, row);
            layout.Controls.Add(details, 3, row);
            layout.Controls.Add(name, 4, row);
            row++;
        }

        box.Controls.Add(layout);
        return box;
    }

    private static string DependencyDisplayName(string dependency) => dependency switch
    {
        "php" => "PHP",
        "apache" => "Apache",
        "mariadb" => "MariaDB",
        _ => dependency
    };

    private static string FormatBytes(long bytes)
    {
        if (bytes >= 1024L * 1024L * 1024L) return $"{bytes / 1024d / 1024d / 1024d:0.00} GB";
        if (bytes >= 1024L * 1024L) return $"{bytes / 1024d / 1024d:0.0} MB";
        if (bytes >= 1024L) return $"{bytes / 1024d:0.0} KB";
        return $"{bytes} B";
    }

    private static string FormatSpeed(double bytesPerSecond)
    {
        if (bytesPerSecond <= 0) return "—";
        return FormatBytes((long)bytesPerSecond) + "/s";
    }

    private static string FormatEta(double seconds)
    {
        if (double.IsNaN(seconds) || double.IsInfinity(seconds) || seconds < 0) return "—";
        var t = TimeSpan.FromSeconds(seconds);
        if (t.TotalHours >= 1) return $"{(int)t.TotalHours}:{t.Minutes:00}:{t.Seconds:00}";
        return $"{t.Minutes:00}:{t.Seconds:00}";
    }

    private static string PrerequisiteCacheRoot() =>
        Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "SOKNA", "PrerequisiteCache");

    private void RefreshArtifactSourceStatus()
    {
        foreach (var dependency in new[] { "php", "apache", "mariadb" })
        {
            var artifact = Artifact(dependency);
            var cached = Path.Combine(PrerequisiteCacheRoot(), artifact.FileName);
            if (File.Exists(cached) && new FileInfo(cached).Length == artifact.Size)
                UpdateArtifactProgress(dependency, 100, "فایل در Cache موجود است؛ SHA هنگام استفاده دوباره بررسی می‌شود.");
            else
                UpdateArtifactProgress(dependency, 0, "فایل آماده نیست؛ دانلود خودکار یا انتخاب فایل دستی.");
        }
    }

    private void UpdateArtifactProgress(string dependency, int percent, string status)
    {
        if (InvokeRequired)
        {
            BeginInvoke(new Action(() => UpdateArtifactProgress(dependency, percent, status)));
            return;
        }

        if (_artifactProgressBars.TryGetValue(dependency, out var progress))
            progress.Value = Math.Clamp(percent, 0, 100);
        if (_artifactStatusLabels.TryGetValue(dependency, out var label))
            label.Text = status;
    }

    private async Task<string?> SelectManualArtifactAsync(string dependency, bool fallbackFromDownload)
    {
        var artifact = Artifact(dependency);
        using var dialog = new OpenFileDialog
        {
            CheckFileExists = true,
            Multiselect = false,
            Title = $"انتخاب فایل {DependencyDisplayName(dependency)} — نسخه {artifact.Version}",
            FileName = artifact.FileName,
            Filter = $"فایل مورد انتظار ({artifact.FileName})|{artifact.FileName}|همه فایل‌ها (*.*)|*.*"
        };

        if (dialog.ShowDialog(this) != DialogResult.OK)
        {
            if (fallbackFromDownload)
                UpdateArtifactProgress(dependency, 0, "دانلود آنلاین ناموفق بود؛ فایل دستی انتخاب نشد.");
            return null;
        }

        return await ImportVerifiedArtifactAsync(artifact, dialog.FileName);
    }

    private async Task<string> ImportVerifiedArtifactAsync(LockedArtifact artifact, string sourcePath)
    {
        UpdateArtifactProgress(artifact.Dependency, 5, "در حال بررسی اندازه فایل...");
        var info = new FileInfo(sourcePath);
        if (!info.Exists)
            throw new FileNotFoundException("فایل انتخاب‌شده پیدا نشد.", sourcePath);
        if (info.Length != artifact.Size)
        {
            UpdateArtifactProgress(artifact.Dependency, 0, $"حجم فایل معتبر نیست؛ انتظار: {FormatBytes(artifact.Size)}");
            throw new InvalidOperationException($"حجم فایل انتخاب‌شده برای {DependencyDisplayName(artifact.Dependency)} معتبر نیست. انتظار: {FormatBytes(artifact.Size)}؛ دریافت‌شده: {FormatBytes(info.Length)}.");
        }

        UpdateArtifactProgress(artifact.Dependency, 35, "اندازه درست است؛ در حال بررسی SHA-256...");
        var verified = await Task.Run(() => VerifyFile(sourcePath, artifact));
        if (!verified)
        {
            UpdateArtifactProgress(artifact.Dependency, 0, "SHA-256 با نسخه تأییدشده تطبیق ندارد.");
            throw new InvalidOperationException($"فایل انتخاب‌شده برای {DependencyDisplayName(artifact.Dependency)} با SHA-256 نسخه تأییدشده تطبیق ندارد. هیچ فایلی نصب یا اجرا نشد.");
        }

        var cache = PrerequisiteCacheRoot();
        Directory.CreateDirectory(cache);
        var final = Path.Combine(cache, artifact.FileName);
        var sourceFull = Path.GetFullPath(sourcePath);
        var finalFull = Path.GetFullPath(final);
        if (!string.Equals(sourceFull, finalFull, StringComparison.OrdinalIgnoreCase))
        {
            var temp = final + ".importing";
            try
            {
                File.Copy(sourceFull, temp, true);
                File.Move(temp, final, true);
            }
            finally
            {
                try { if (File.Exists(temp)) File.Delete(temp); } catch { }
            }
        }

        var partial = final + ".partial";
        try { if (File.Exists(partial)) File.Delete(partial); } catch { }
        UpdateArtifactProgress(artifact.Dependency, 100, "فایل دستی تأیید شد و آماده استفاده است.");
        Log($"Manual artifact accepted: {artifact.Dependency}; file={artifact.FileName}; sha256={artifact.Sha256}");
        return final;
    }

    private async Task SelectOfflineFolderAsync()
    {
        using var dialog = new FolderBrowserDialog
        {
            Description = "پوشه Offline Kit شامل فایل‌های رسمی PHP، Apache و MariaDB را انتخاب کنید.",
            ShowNewFolderButton = false
        };
        if (dialog.ShowDialog(this) != DialogResult.OK) return;

        SetBusy(true, "در حال بررسی پوشه آفلاین...");
        try
        {
            var accepted = new List<string>();
            var missing = new List<string>();
            var invalid = new List<string>();

            foreach (var dependency in new[] { "php", "apache", "mariadb" })
            {
                var artifact = Artifact(dependency);
                UpdateArtifactProgress(dependency, 2, "در حال جست‌وجوی فایل در Offline Kit...");
                var candidate = Directory.EnumerateFiles(dialog.SelectedPath, artifact.FileName, SearchOption.AllDirectories).FirstOrDefault();
                if (candidate is null)
                {
                    missing.Add(artifact.FileName);
                    UpdateArtifactProgress(dependency, 0, "فایل در پوشه انتخاب‌شده پیدا نشد.");
                    continue;
                }

                try
                {
                    await ImportVerifiedArtifactAsync(artifact, candidate);
                    accepted.Add(artifact.FileName);
                }
                catch (Exception ex)
                {
                    invalid.Add($"{artifact.FileName}: {ex.Message}");
                }
            }

            var summary = new StringBuilder();
            summary.AppendLine($"فایل‌های تأییدشده: {accepted.Count} از 3");
            if (accepted.Count > 0) summary.AppendLine("\nتأیید شد:\n" + string.Join("\n", accepted));
            if (missing.Count > 0) summary.AppendLine("\nپیدا نشد:\n" + string.Join("\n", missing));
            if (invalid.Count > 0) summary.AppendLine("\nنامعتبر:\n" + string.Join("\n", invalid));
            ShowNotice("بررسی Offline Kit", summary.ToString(), invalid.Count > 0);
        }
        catch (Exception ex) { ShowError(ex); }
        finally { SetBusy(false, "آماده"); }
    }

    private void RefreshModeHelp()
    {
        var modeText = SelectedMode() switch
        {
            OperationMode.Install => "نصب جدید: نسخه‌های تأییدشده دانلود و بررسی می‌شوند، PHP / Apache / MariaDB آماده می‌شوند و سرویس‌های ویندوز ثبت می‌شوند. اگر Data قبلی پیدا شود، عملیات برای جلوگیری از بازنویسی متوقف می‌شود.",
            OperationMode.Repair => "تعمیر نصب موجود: فایل‌ها و تنظیمات زیرساخت بررسی و ترمیم می‌شوند. Web و Data موجود حفظ می‌شوند و MariaDB دوباره initialize نمی‌شود.",
            _ => "بازیابی بعد از نصب مجدد ویندوز: از فایل‌ها و Data باقی‌مانده روی درایو انتخاب‌شده استفاده می‌شود و سرویس‌های ویندوز دوباره ثبت می‌شوند. Web و Data قبلی دست‌نخورده می‌مانند."
        };
        if (!string.IsNullOrWhiteSpace(_detectedExistingRoot))
            modeText = $"نصب موجود در «{_detectedExistingRoot}» تشخیص داده شد؛ حالت «تعمیر نصب موجود» به‌صورت خودکار انتخاب شده است.\n" + modeText;
        _modeHelp.Text = modeText;
    }

    private void FitToWorkingArea()
    {
        var work = Screen.FromControl(this).WorkingArea;
        var width = Math.Min(1160, Math.Max(900, work.Width - 80));
        var height = Math.Min(820, Math.Max(650, work.Height - 80));
        Size = new Size(width, height);
        Location = new Point(work.Left + Math.Max(0, (work.Width - Width) / 2), work.Top + Math.Max(0, (work.Height - Height) / 2));
    }

    private void EnsureSessionLog()
    {
        try
        {
            Directory.CreateDirectory(Path.GetDirectoryName(_sessionLog)!);
            if (!File.Exists(_sessionLog))
                File.WriteAllText(_sessionLog, $"[{DateTime.Now:yyyy-MM-dd HH:mm:ss.fff}] SOKNA Prerequisites UI session started.{Environment.NewLine}", new UTF8Encoding(false));
        }
        catch { }
    }

    private OperationMode SelectedMode() => _recover.Checked ? OperationMode.Recover : _repair.Checked ? OperationMode.Repair : OperationMode.Install;

    private void BrowseRoot()
    {
        using var d = new FolderBrowserDialog { Description = "پوشه ریشه SOKNA را انتخاب کنید", SelectedPath = _root.Text, ShowNewFolderButton = true };
        if (d.ShowDialog(this) == DialogResult.OK)
        {
            _root.Text = d.SelectedPath;
            TryLoadExistingApachePort();
        }
    }

    private static string? DetectExistingInfrastructureRoot()
    {
        foreach (var (service, component) in new[] { ("SoknaMariaDB", "MariaDB"), ("SoknaApache", "Apache") })
        {
            try
            {
                var image = InfrastructureOwnershipDetector.ReadServiceImagePath(service);
                if (string.IsNullOrWhiteSpace(image)) continue;
                var exe = InfrastructureOwnershipDetector.ExtractExecutablePath(image);
                if (string.IsNullOrWhiteSpace(exe) || !File.Exists(exe)) continue;
                var root = InfrastructureOwnershipDetector.InferSoknaRootFromInfrastructureExecutable(exe, component);
                if (!string.IsNullOrWhiteSpace(root) && Directory.Exists(root)) return root;
            }
            catch { }
        }
        return null;
    }

    private static string DefaultRoot()
    {
        try
        {
            var systemRoot = Path.GetPathRoot(Environment.SystemDirectory);
            var drive = DriveInfo.GetDrives()
                .Where(d => d.DriveType == DriveType.Fixed && d.IsReady && !string.Equals(d.RootDirectory.FullName, systemRoot, StringComparison.OrdinalIgnoreCase))
                .OrderByDescending(d => d.AvailableFreeSpace)
                .FirstOrDefault();
            if (drive is not null) return Path.Combine(drive.RootDirectory.FullName, "SOKNA");
        }
        catch { }
        var root = Path.GetPathRoot(Environment.SystemDirectory) ?? "C:\\";
        return Path.Combine(root, "SOKNA");
    }

    private int ApachePort() => (int)_apachePort.Value;

    private string LocalWebUrl() => $"http://127.0.0.1:{ApachePort()}/";

    private void TryLoadExistingApachePort()
    {
        try
        {
            var root = Path.GetFullPath(Environment.ExpandEnvironmentVariables(_root.Text.Trim()));
            var conf = Path.Combine(root, "Infrastructure", "Apache", "conf", "httpd.conf");
            if (!File.Exists(conf)) return;
            var text = File.ReadAllText(conf, Encoding.UTF8);
            var match = Regex.Match(text, @"(?im)^\s*Listen\s+127\.0\.0\.1:(\d+)\s*$");
            if (!match.Success || !int.TryParse(match.Groups[1].Value, out var port)) return;
            if (port < (int)_apachePort.Minimum || port > (int)_apachePort.Maximum)
            {
                Log($"Existing Apache port {port} is outside the SOKNA Local Web allowed range; keeping default {ApachePort()} for repair/migration.");
                return;
            }
            _apachePort.Value = port;
        }
        catch { }
    }

    private string RootPath() => Path.GetFullPath(Environment.ExpandEnvironmentVariables(_root.Text.Trim()));
    private string InfraPath() => Path.Combine(RootPath(), "Infrastructure");
    private string PhpPath() => Path.Combine(InfraPath(), "PHP");
    private string ApachePath() => Path.Combine(InfraPath(), "Apache");
    private string MariaPath() => Path.Combine(InfraPath(), "MariaDB");
    private string LogsPath() => Path.Combine(InfraPath(), "Logs");
    private string WebPath() => Path.Combine(RootPath(), "Web");
    private string WebPublicPath() => Path.Combine(WebPath(), "public");
    private string DataPath() => Path.Combine(RootPath(), "Data", "MariaDB");
    private string StatePath() => Path.Combine(InfraPath(), "infrastructure-state.json");

    private void RefreshPathSummary()
    {
        try
        {
            _paths.Text =
                $"زیرساخت: {Technical(InfraPath())}\n" +
                $"داده MariaDB: {Technical(DataPath())}\n" +
                $"Local Web Root: {Technical(WebPath())}\n" +
                $"Apache DocumentRoot: {Technical(WebPublicPath())}\n" +
                $"آدرس Local Web: {Technical(LocalWebUrl())}\n" +
                "Local Web در این مرحله نصب نمی‌شود.";
        }
        catch { _paths.Text = "مسیر واردشده معتبر نیست."; }
    }

    private void ValidateContracts()
    {
        if (_policy.Format != "sokna-infrastructure-prerequisites-v1" || _policy.SchemaVersion != 1 ||
            !_policy.AutomaticDownloadAllowed || !_policy.AutomaticInstallAllowed ||
            _policy.LocalWebPayloadAllowed || _policy.DatabaseApplicationProvisioningAllowed)
            throw new InvalidOperationException("قرارداد مستقل زیرساخت معتبر نیست.");
        if (_lock.Format != "sokna-windows-prerequisite-lock-v1" || _lock.SchemaVersion != 1 || !_lock.ReleaseFrozen)
            throw new InvalidOperationException("release-lock پیش‌نیازها معتبر نیست.");
        foreach (var dep in new[] { "php", "apache", "mariadb" })
            if (_lock.Artifacts.SingleOrDefault(x => x.Dependency == dep) is null)
                throw new InvalidOperationException($"artifact قفل‌شده برای {dep} وجود ندارد.");
    }

    private T LoadJson<T>(string file, string title)
    {
        var p = Path.Combine(AppContext.BaseDirectory, file);
        if (!File.Exists(p)) throw new FileNotFoundException($"{title} پیدا نشد.", p);
        return JsonSerializer.Deserialize<T>(File.ReadAllText(p, Encoding.UTF8)) ?? throw new InvalidOperationException($"{title} قابل خواندن نیست.");
    }

    private async Task AnalyzeAsync()
    {
        try
        {
            SetBusy(true, "در حال بررسی وضعیت...");
            await Task.Run(() =>
            {
                var sb = new StringBuilder();
                sb.AppendLine($"مسیر اصلی: {Technical(RootPath())}");
                sb.AppendLine($"PHP: {(File.Exists(Path.Combine(PhpPath(), "php.exe")) ? "موجود" : "پیدا نشد")}");
                sb.AppendLine($"Apache — فایل‌ها: {(File.Exists(Path.Combine(ApachePath(), "bin", "httpd.exe")) ? "موجود" : "پیدا نشد")}");
                sb.AppendLine($"Apache — سرویس ویندوز: {ServiceStatus("SoknaApache")}");
                sb.AppendLine($"Apache — پورت {Technical(ApachePort().ToString())}: {(TcpOpen(ApachePort()) ? "پاسخ می‌دهد" : "در دسترس نیست")}");
                sb.AppendLine($"MariaDB — فایل‌ها: {(FindMariaServer() is not null ? "موجود" : "پیدا نشد")}");
                sb.AppendLine($"MariaDB — Data: {(MariaDataInitialized() ? "موجود و محافظت‌شده" : "هنوز راه‌اندازی نشده")}");
                sb.AppendLine($"MariaDB — سرویس ویندوز: {ServiceStatus("SoknaMariaDB")}");
                sb.AppendLine($"MariaDB — پورت {Technical("3306")}: {(TcpOpen(3306) ? "پاسخ می‌دهد" : "در دسترس نیست")}");
                var ownership=InfrastructureOwnershipDetector.Detect(RootPath(),Artifact("mariadb").Version);
                if(ownership.HasConflict)
                {
                    sb.AppendLine("⚠ تداخل Root نصب موجود:");
                    foreach(var conflict in ownership.Conflicts) sb.AppendLine("  - "+conflict);
                }
                sb.AppendLine($"Web Root: {Technical(WebPath())}");
                if (File.Exists(StatePath())) sb.AppendLine($"State: {Technical(StatePath())}");
                BeginInvoke(new Action(() => _status.Text = sb.ToString()));
            });
        }
        catch (Exception ex) { ShowError(ex); }
        finally { SetBusy(false, "بررسی تمام شد."); }
    }

    private async Task RunAsync()
    {
        try
        {
            _operationCts?.Dispose();
            _operationCts = new CancellationTokenSource();
            var token = _operationCts.Token;
            SetBusy(true, "در حال بررسی ورودی‌ها...");
            var mode = SelectedMode();
            ValidateInputs(mode);
            _progressText.Text = "Preflight مالکیت نصب موجود...";
            var ownership=InfrastructureOwnershipDetector.Detect(RootPath(),Artifact("mariadb").Version);
            if(ownership.HasConflict)
            {
                Log($"Cross-root preflight blocked operation. Target={ownership.TargetRoot}; Evidence={string.Join(" | ",ownership.Evidence)}");
                throw new InvalidOperationException(ownership.ToUserMessage(mode));
            }
            _progressText.Text = "شروع عملیات...";
            PreparePersistentLayout();
            StartLog(mode);

            Log($"Mode={mode}; Root={RootPath()}");
            Log("Local Web payload is explicitly out of scope.");

            if (mode == OperationMode.Install && MariaDataInitialized())
                throw new InvalidOperationException("در مسیر انتخاب‌شده Data قبلی MariaDB وجود دارد. برای جلوگیری از overwrite، «تعمیر» یا «بازیابی بعد از ویندوز» را انتخاب کنید.");

            await InstallPhpAsync(token);
            await InstallApacheAsync(token);
            await InstallMariaAsync(mode, token);
            await ValidateHealthAsync(token);
            WriteState(mode);

            SetProgress(100, "زیرساخت آماده است.");
            _status.AppendText("\n✓ زیرساخت آماده شد.\n");
            _status.AppendText($"مرحله بعد: فایل Local Web را داخل «{WebPath()}» قرار دهید و {LocalWebUrl()} را در مرورگر باز کنید.\n");
            ShowNotice(
                "زیرساخت آماده شد",
                $"Web Root:\n{WebPath()}\n\nدر مرحله بعد Local Web را جداگانه داخل این مسیر قرار دهید و {LocalWebUrl()} را باز کنید.",
                false);
        }
        catch (OperationCanceledException)
        {
            Log("Operation cancelled by user.");
            _status.AppendText("\nعملیات توسط کاربر لغو شد.\n");
        }
        catch (Exception ex)
        {
            Log("ERROR: " + ex);
            ShowError(ex);
        }
        finally
        {
            SetBusy(false, "آماده");
            _operationCts?.Dispose();
            _operationCts = null;
        }
    }

    private void ValidateInputs(OperationMode mode)
    {
        var root = RootPath();
        if (string.IsNullOrWhiteSpace(root) || Path.GetPathRoot(root) is null) throw new InvalidOperationException("مسیر ریشه معتبر نیست.");
        if (mode == OperationMode.Install && !MariaDataInitialized())
        {
            if (_password.Text.Length < 10) throw new InvalidOperationException("برای نصب جدید، رمز root ماریا‌دی‌بی حداقل ۱۰ نویسه باشد.");
            if (_password.Text.Contains('"') || _password.Text.Contains('\r') || _password.Text.Contains('\n'))
                throw new InvalidOperationException("رمز root نباید شامل علامت نقل‌قول یا خط جدید باشد.");
            if (_password.Text != _password2.Text) throw new InvalidOperationException("رمز و تکرار آن یکسان نیستند.");
        }
    }

    private void PreparePersistentLayout()
    {
        Directory.CreateDirectory(InfraPath());
        Directory.CreateDirectory(LogsPath());
        Directory.CreateDirectory(WebPath());
        Directory.CreateDirectory(WebPublicPath());
        Directory.CreateDirectory(Path.Combine(RootPath(), "Data"));
        Directory.CreateDirectory(Path.Combine(RootPath(), "Backups"));
    }

    private void StartLog(OperationMode mode)
    {
        _currentLog = Path.Combine(LogsPath(), $"prerequisites-{DateTime.Now:yyyyMMdd-HHmmss}.log");
        Log($"SOKNA Prerequisites Setup {Application.ProductVersion} | {mode}");
    }

    private async Task InstallPhpAsync(CancellationToken ct)
    {
        SetProgress(5, "PHP: بررسی وضعیت...");
        UpdateArtifactProgress("php", 2, "در حال بررسی نصب موجود...");
        var a = Artifact("php");
        if (PhpReady(a.Version))
        {
            Log($"PHP {a.Version} already ready; install step skipped.");
            SetProgress(25, "PHP از قبل آماده است؛ ادامه نصب...");
            UpdateArtifactProgress("php", 100, "PHP از قبل نصب و آماده است.");
            return;
        }

        StopApacheForMaintenance();

        if (PhpBinaryVersionReady(a.Version))
        {
            Log($"PHP {a.Version} binaries already exist but configuration/extensions need reconciliation.");
            ConfigurePhp();
            if (!PhpReady(a.Version))
                throw new InvalidOperationException("PHP موجود بعد از ترمیم php.ini و extensionهای لازم آماده نشد.");
            SetProgress(25, "تنظیمات PHP موجود ترمیم شد.");
            UpdateArtifactProgress("php", 100, "PHP موجود بدون دانلود مجدد ترمیم و آماده شد.");
            return;
        }

        SetProgress(8, "PHP: دریافت و بررسی فایل رسمی...");
        var zip = await DownloadVerifiedAsync(a, ct);
        var tmp = NewTemp("php");
        try
        {
            ZipFile.ExtractToDirectory(zip, tmp, true);
            var source = FindRootContaining(tmp, "php.exe") ?? throw new InvalidOperationException("php.exe داخل بسته پیدا نشد.");
            CopyDirectory(source, PhpPath());
            ConfigurePhp();
            if (!PhpReady(a.Version))
                throw new InvalidOperationException("PHP بعد از نصب یا تنظیم extensionهای لازم آماده نشد.");
            Log($"PHP {a.Version} ready.");
            SetProgress(25, "PHP آماده شد.");
            UpdateArtifactProgress("php", 100, "PHP آماده است.");
        }
        finally { SafeDelete(tmp); }
    }

    private bool PhpBinaryVersionReady(string expectedVersion)
    {
        var php = Path.Combine(PhpPath(), "php.exe");
        if (!File.Exists(php)) return false;
        var version = RunProcess(php, "-v", "-v", allowFailure: true);
        return version.ExitCode == 0 && version.Output.Contains($"PHP {expectedVersion}", StringComparison.OrdinalIgnoreCase);
    }

    private bool PhpConfigurationReady() => PhpRuntimeConfiguration.ConfigurationReady(PhpPath());

    private bool PhpReady(string expectedVersion)
    {
        if (!PhpBinaryVersionReady(expectedVersion) || !PhpConfigurationReady()) return false;
        var php = Path.Combine(PhpPath(), "php.exe");
        var modules = RunProcess(php, "-m", "-m", allowFailure: true);
        if (modules.ExitCode != 0) return false;
        var loaded = modules.Output.Split(['\r', '\n'], StringSplitOptions.RemoveEmptyEntries)
            .Select(x => x.Trim())
            .ToHashSet(StringComparer.OrdinalIgnoreCase);
        return PhpRuntimeConfiguration.RequiredCliExtensions.All(loaded.Contains);
    }

    private void ConfigurePhp()
    {
        var ini = Path.Combine(PhpPath(), "php.ini");
        if (File.Exists(ini)) BackupFile(ini);
        PhpRuntimeConfiguration.Configure(PhpPath());
    }

    private async Task InstallApacheAsync(CancellationToken ct)
    {
        SetProgress(30, "Apache: بررسی وضعیت...");
        UpdateArtifactProgress("apache", 2, "در حال بررسی نصب و سرویس موجود...");
        if (ApacheReady())
        {
            Log("Apache already ready; install step skipped.");
            SetProgress(55, "Apache از قبل آماده است؛ ادامه به MariaDB...");
            UpdateArtifactProgress("apache", 100, "Apache از قبل نصب، ثبت و در حال اجراست.");
            return;
        }

        StopApacheForMaintenance();
        EnsureApachePortAvailableWithFallback();
        SetProgress(34, "Apache: دریافت و آماده‌سازی...");
        var a = Artifact("apache");
        var zip = await DownloadVerifiedAsync(a, ct);
        var tmp = NewTemp("apache");
        try
        {
            ZipFile.ExtractToDirectory(zip, tmp, true);
            var source = FindRootContaining(tmp, Path.Combine("bin", "httpd.exe")) ?? throw new InvalidOperationException("httpd.exe داخل بسته Apache پیدا نشد.");
            CopyDirectory(source, ApachePath());
            ConfigureApache();

            var httpd = Path.Combine(ApachePath(), "bin", "httpd.exe");
            var conf = Path.Combine(ApachePath(), "conf", "httpd.conf");
            var syntax = RunProcess(httpd, $"-t -f \"{conf}\"", $"-t -f \"{conf}\"");
            if (syntax.ExitCode != 0) throw new InvalidOperationException("Apache config معتبر نیست: " + syntax.Error + syntax.Output);

            ReinstallApacheService(httpd, conf);
            if (!WaitForPort(ApachePort(), TimeSpan.FromSeconds(30)))
                throw new InvalidOperationException($"سرویس Apache ثبت شد اما روی پورت {ApachePort()} پاسخ نداد.");
            SetProgress(55, "Apache و سرویس SoknaApache آماده شدند.");
            UpdateArtifactProgress("apache", 100, "Apache نصب و سرویس آن آماده است.");
        }
        finally { SafeDelete(tmp); }
    }

    private bool ApacheReady()
    {
        var httpd = Path.Combine(ApachePath(), "bin", "httpd.exe");
        var conf = Path.Combine(ApachePath(), "conf", "httpd.conf");
        if (!File.Exists(httpd) || !File.Exists(conf) || !ServiceExists("SoknaApache")) return false;

        if (!ApacheConfigurationReady(conf))
        {
            Log("Apache binaries/service exist but Local Web configuration is incomplete; repair is required.");
            return false;
        }

        var syntax = RunProcess(httpd, $"-t -f \"{conf}\"", $"-t -f \"{conf}\"", allowFailure: true);
        if (syntax.ExitCode != 0) return false;
        if (!string.Equals(ServiceStatus("SoknaApache"), "RUNNING", StringComparison.OrdinalIgnoreCase))
            RunProcess("sc.exe", "start SoknaApache", "start SoknaApache", allowFailure: true);
        return WaitForPort(ApachePort(), TimeSpan.FromSeconds(12));
    }

    private bool ApacheConfigurationReady(string conf)
    {
        try
        {
            var text=File.ReadAllText(conf);
            var rewriteReady=Regex.IsMatch(text, @"(?im)^\s*LoadModule\s+rewrite_module\s+modules/mod_rewrite\.so\s*$");
            var phpDll=Path.Combine(PhpPath(),"php8apache2_4.dll").Replace("\\","/");
            var phpReady=Regex.IsMatch(text, @"(?im)^\s*LoadModule\s+php_module\s+""" + Regex.Escape(phpDll) + @"""\s*$");
            var web=WebPublicPath().Replace("\\","/");
            var documentRootReady=Regex.IsMatch(text, @"(?im)^\s*DocumentRoot\s+""" + Regex.Escape(web) + @"""\s*$");
            var directoryReady=Regex.IsMatch(text, @"(?is)<Directory\s+""" + Regex.Escape(web) + @"""\s*>.*?AllowOverride\s+All.*?</Directory>");
            if(!rewriteReady) Log("Apache readiness: mod_rewrite is not enabled.");
            if(!phpReady) Log("Apache readiness: PHP module binding is missing or stale.");
            if(!documentRootReady) Log("Apache readiness: DocumentRoot is not Local Web public.");
            if(!directoryReady) Log("Apache readiness: Local Web directory does not allow .htaccess overrides.");
            return rewriteReady&&phpReady&&documentRootReady&&directoryReady;
        }
        catch(Exception ex)
        {
            Log("Apache readiness config check failed: "+ex.Message);
            return false;
        }
    }

    private void StopApacheForMaintenance()
    {
        if (!ServiceExists("SoknaApache")) return;
        var status = ServiceStatus("SoknaApache");
        if (string.Equals(status, "STOPPED", StringComparison.OrdinalIgnoreCase)) return;
        Log("Stopping SoknaApache before updating PHP/Apache files.");
        RunProcess("sc.exe", "stop SoknaApache", "stop SoknaApache", allowFailure: true);
        var until = DateTime.UtcNow.AddSeconds(30);
        while (DateTime.UtcNow < until)
        {
            if (string.Equals(ServiceStatus("SoknaApache"), "STOPPED", StringComparison.OrdinalIgnoreCase))
            {
                Thread.Sleep(500);
                return;
            }
            Thread.Sleep(500);
        }
        throw new InvalidOperationException("Apache برای به‌روزرسانی فایل‌ها متوقف نشد. لطفاً چند ثانیه صبر کنید و دوباره تلاش کنید.");
    }

    private void EnsureApachePortAvailableWithFallback()
    {
        var selected = ApachePort();
        if (LocalEndpointPortPolicy.CanBindLoopback(selected, out _)) return;

        var detail = DescribePortConflict(selected);
        var fallback = LocalEndpointPortPolicy.FallbackCandidates
            .FirstOrDefault(p => p != selected && LocalEndpointPortPolicy.CanBindLoopback(p, out _));

        if (fallback > 0)
        {
            var answer = MessageBox.Show(
                this,
                $"Apache نمی‌تواند روی پورت {selected} اجرا شود.\n\n{detail}\n\nپورت {fallback} آزاد است. آیا Setup از پورت {fallback} استفاده کند؟\n\nآدرس Local Web در این حالت {($"http://127.0.0.1:{fallback}/")} خواهد بود.",
                "پورت Apache در دسترس نیست",
                MessageBoxButtons.YesNo,
                MessageBoxIcon.Warning,
                MessageBoxDefaultButton.Button1,
                MessageBoxOptions.RtlReading | MessageBoxOptions.RightAlign);
            if (answer == DialogResult.Yes)
            {
                _apachePort.Value = fallback;
                Log($"Apache port changed from {selected} to {fallback} after bind preflight.");
                return;
            }
        }

        throw new InvalidOperationException(
            $"Apache نمی‌تواند روی پورت {selected} اجرا شود. {detail} " +
            "پورت Apache را آزاد کنید یا یک پورت آزاد دیگر در فیلد «پورت Apache» وارد کنید.");
    }

    private string DescribePortConflict(int port)
    {
        try
        {
            var netstat = RunProcess("netstat.exe", "-ano -p tcp", "-ano -p tcp", allowFailure: true);
            foreach (var raw in netstat.Output.Split(['\r', '\n'], StringSplitOptions.RemoveEmptyEntries))
            {
                var line = raw.Trim();
                if (!line.StartsWith("TCP", StringComparison.OrdinalIgnoreCase) || !line.Contains("LISTENING", StringComparison.OrdinalIgnoreCase))
                    continue;
                var parts = Regex.Split(line, @"\s+");
                if (parts.Length < 5) continue;
                var local = parts[1];
                if (!local.EndsWith($":{port}", StringComparison.OrdinalIgnoreCase)) continue;
                if (!int.TryParse(parts[^1], out var pid)) continue;
                var processName = "نامشخص";
                try { processName = Process.GetProcessById(pid).ProcessName; } catch { }
                if (pid == 4)
                    return $"پورت {port} در اختیار Windows HTTP.sys/System (PID 4) است؛ معمولاً IIS یا یکی از سرویس‌های وب ویندوز از آن استفاده می‌کند.";
                return $"پورت {port} توسط فرایند «{processName}» با PID {pid} استفاده می‌شود.";
            }
        }
        catch (Exception ex)
        {
            Log("Port owner diagnostic failed: " + ex.Message);
        }

        return $"Windows اجازه bind روی 127.0.0.1:{port} را نمی‌دهد. ممکن است پورت رزرو شده باشد یا یک سرویس سیستمی آن را در اختیار داشته باشد.";
    }

    private void ConfigureApache()
    {
        var conf = Path.Combine(ApachePath(), "conf", "httpd.conf");
        if (!File.Exists(conf)) throw new InvalidOperationException("httpd.conf پیدا نشد.");
        BackupFile(conf);

        var a = Slash(ApachePath());
        var p = Slash(PhpPath());
        var w = Slash(WebPublicPath());
        var text = File.ReadAllText(conf, Encoding.UTF8);
        text = Regex.Replace(text, "(?im)^\\s*Define\\s+SRVROOT\\s+\\\".*?\\\"\\s*$", $"Define SRVROOT \"{a}\"");
        text = new Regex(@"(?im)^\s*Listen\s+.*$").Replace(text, $"Listen 127.0.0.1:{ApachePort()}", 1);
        text = new Regex("(?im)^\\s*DocumentRoot\\s+\\\".*?\\\"\\s*$").Replace(text, $"DocumentRoot \"{w}\"", 1);

        // Local Web ships an .htaccess that uses RewriteEngine/RewriteRule.
        // Apache Lounge keeps mod_rewrite commented by default, which turns
        // every request into HTTP 500 as soon as that .htaccess is present.
        var rewriteRx=new Regex(@"(?im)^\s*#?\s*LoadModule\s+rewrite_module\s+modules/mod_rewrite\.so\s*$");
        if(rewriteRx.IsMatch(text))
            text=rewriteRx.Replace(text,"LoadModule rewrite_module modules/mod_rewrite.so",1);
        else if(File.Exists(Path.Combine(ApachePath(),"modules","mod_rewrite.so")))
            text+=Environment.NewLine+"LoadModule rewrite_module modules/mod_rewrite.so"+Environment.NewLine;
        else
            throw new InvalidOperationException("Apache mod_rewrite پیدا نشد؛ Local Web بدون این ماژول با HTTP 500 اجرا می‌شود.");

        text = Regex.Replace(text, @"(?is)\r?\n# BEGIN SOKNA MANAGED.*?# END SOKNA MANAGED\r?\n?", Environment.NewLine);
        text += $"""
# BEGIN SOKNA MANAGED
ServerName 127.0.0.1:{ApachePort()}
LoadModule php_module "{p}/php8apache2_4.dll"
PHPIniDir "{p}"
<FilesMatch \.php$>
    SetHandler application/x-httpd-php
</FilesMatch>
<Directory "{w}">
    Options Indexes FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
DirectoryIndex index.php index.html
# END SOKNA MANAGED

""";
        File.WriteAllText(conf, text, new UTF8Encoding(false));
    }

    private void ReinstallApacheService(string httpd, string conf)
    {
        if (ServiceExists("SoknaApache"))
        {
            RunProcess("sc.exe", "stop SoknaApache", "stop SoknaApache", allowFailure: true);
            RunProcess(httpd, "-k uninstall -n \"SoknaApache\"", "-k uninstall -n \"SoknaApache\"", allowFailure: true);
            if(ServiceExists("SoknaApache"))
                RunProcess("sc.exe", "delete SoknaApache", "delete SoknaApache", allowFailure: true);

            var deleteUntil=DateTime.UtcNow.AddSeconds(20);
            while(DateTime.UtcNow<deleteUntil && ServiceExists("SoknaApache"))
                Thread.Sleep(400);
            if(ServiceExists("SoknaApache"))
                throw new InvalidOperationException("ثبت قدیمی سرویس SoknaApache برای بازسازی حذف نشد. چند ثانیه صبر کنید و دوباره Repair را اجرا کنید.");
        }
        var install = RunProcess(httpd, $"-k install -n \"SoknaApache\" -f \"{conf}\"", $"-k install -n \"SoknaApache\" -f \"{conf}\"", allowFailure: true);
        if (install.ExitCode != 0 && !ServiceExists("SoknaApache"))
            throw new InvalidOperationException("ثبت سرویس Apache ناموفق بود: " + install.Error + install.Output);
        if (install.ExitCode != 0)
            Log("Apache service exists but httpd -k install returned a non-zero config/start preflight result: " + TrimLog(install.Error + " " + install.Output));
        RunProcess("sc.exe", "config SoknaApache start= auto", "config SoknaApache start= auto", allowFailure: true);
        var start = RunProcess("sc.exe", "start SoknaApache", "start SoknaApache", allowFailure: true);
        if (start.ExitCode != 0 && !string.Equals(ServiceStatus("SoknaApache"), "RUNNING", StringComparison.OrdinalIgnoreCase))
            throw new InvalidOperationException($"سرویس Apache ثبت شد اما شروع نشد. پورت انتخاب‌شده: {ApachePort()}. جزئیات: {TrimLog(start.Error + " " + start.Output)}");
    }

    private async Task InstallMariaAsync(OperationMode mode, CancellationToken ct)
    {
        SetProgress(60, "MariaDB: آماده‌سازی binary...");
        UpdateArtifactProgress("mariadb", 2, "در حال بررسی MariaDB...");
        await EnsureMariaBinariesAsync(ct);
        var initialized = MariaDataInitialized();

        if (!initialized)
        {
            if (mode != OperationMode.Install)
                throw new InvalidOperationException("Data قبلی MariaDB پیدا نشد. برای ساخت دیتای جدید، حالت «نصب جدید» را انتخاب کنید.");
            Directory.CreateDirectory(DataPath());
            var init = FindMariaInstallDb() ?? throw new InvalidOperationException("mariadb-install-db.exe پیدا نشد.");
            var args = $"--datadir=\"{DataPath()}\" --service=SoknaMariaDB --password=\"{_password.Text}\" --port=3306 --silent";
            var safe = $"--datadir=\"{DataPath()}\" --service=SoknaMariaDB --password=<redacted> --port=3306 --silent";
            var r = RunProcess(init, args, safe, timeoutMs: 180000);
            if (r.ExitCode != 0) throw new InvalidOperationException("Initialize اولیه MariaDB ناموفق بود: " + r.Error + r.Output);
            Log("MariaDB data initialized once.");
        }
        else
        {
            Log("Existing MariaDB data detected; initialization skipped.");
            EnsureMariaConfigForExistingData();
            RegisterExistingMariaServiceIfNeeded();
        }

        RunProcess("sc.exe", "config SoknaMariaDB start= auto", "config SoknaMariaDB start= auto", allowFailure: true);
        RunProcess("sc.exe", "start SoknaMariaDB", "start SoknaMariaDB", allowFailure: true);
        SetProgress(82, "MariaDB آماده شد؛ Data موجود حفظ شده است.");
        UpdateArtifactProgress("mariadb", 100, "MariaDB و سرویس آن آماده است.");
    }

    private async Task EnsureMariaBinariesAsync(CancellationToken ct)
    {
        if (FindMariaServer() is not null)
        {
            Log("MariaDB binaries already present; MSI binary install skipped.");
            return;
        }

        var a = Artifact("mariadb");
        var msi = await DownloadVerifiedAsync(a, ct);
        Directory.CreateDirectory(MariaPath());
        var msiLog = Path.Combine(LogsPath(), $"mariadb-msi-{DateTime.Now:yyyyMMdd-HHmmss}.log");
        var args = $"/i \"{msi}\" INSTALLDIR=\"{MariaPath()}\" ADDLOCAL=MYSQLSERVER,Client,SharedLibraries REMOVE=DBInstance,DEVEL,HeidiSQL /qn /norestart /l*v \"{msiLog}\"";
        var r = RunProcess("msiexec.exe", args, args, timeoutMs: 600000);
        if (r.ExitCode is not (0 or 3010))
            throw new InvalidOperationException($"نصب binary ماریا‌دی‌بی ناموفق بود (Exit {r.ExitCode}). لاگ MSI: {msiLog}");
        if (FindMariaServer() is null) throw new InvalidOperationException("MariaDB MSI پایان یافت ولی mariadbd.exe در مسیر انتخاب‌شده پیدا نشد.");
    }

    private void EnsureMariaConfigForExistingData()
    {
        var ini = Path.Combine(DataPath(), "my.ini");
        if (File.Exists(ini)) return;
        var server = FindMariaServer() ?? throw new InvalidOperationException("mariadbd.exe پیدا نشد.");
        var baseDir = Directory.GetParent(Path.GetDirectoryName(server)!)!.FullName;
        var log = Slash(Path.Combine(LogsPath(), "mariadb-error.log"));
        var text = $"[mysqld]\r\nbasedir={Slash(baseDir)}\r\ndatadir={Slash(DataPath())}\r\nport=3306\r\nbind-address=127.0.0.1\r\ncharacter-set-server=utf8mb4\r\ncollation-server=utf8mb4_unicode_ci\r\nlog-error={log}\r\n";
        File.WriteAllText(ini, text, new UTF8Encoding(false));
    }

    private void RegisterExistingMariaServiceIfNeeded()
    {
        if (ServiceExists("SoknaMariaDB")) return;
        var server = FindMariaServer() ?? throw new InvalidOperationException("mariadbd.exe پیدا نشد.");
        var ini = Path.Combine(DataPath(), "my.ini");
        var r = RunProcess(server, $"--defaults-file=\"{ini}\" --install SoknaMariaDB", $"--defaults-file=\"{ini}\" --install SoknaMariaDB");
        if (r.ExitCode != 0) throw new InvalidOperationException("ثبت مجدد سرویس MariaDB ناموفق بود: " + r.Error + r.Output);
        Log("Existing MariaDB data reattached to Windows service without reinitialization.");
    }

    private async Task ValidateHealthAsync(CancellationToken ct)
    {
        SetProgress(86, "Health check نهایی...");
        await Task.Delay(1500, ct);

        var php = RunProcess(Path.Combine(PhpPath(), "php.exe"), "-m", "-m");
        if (php.ExitCode != 0) throw new InvalidOperationException("PHP health check ناموفق بود.");
        foreach (var ext in PhpRuntimeConfiguration.RequiredCliExtensions)
            if (!php.Output.Split('\n').Any(x => string.Equals(x.Trim(), ext, StringComparison.OrdinalIgnoreCase)))
                throw new InvalidOperationException($"PHP extension آماده نیست: {ext}");

        if (!ServiceExists("SoknaApache")) throw new InvalidOperationException("سرویس SoknaApache ثبت نشده است.");
        if (!ServiceExists("SoknaMariaDB")) throw new InvalidOperationException("سرویس SoknaMariaDB ثبت نشده است.");

        var until = DateTime.UtcNow.AddSeconds(30);
        while (DateTime.UtcNow < until && (!TcpOpen(ApachePort()) || !TcpOpen(3306)))
        {
            await Task.Delay(1000, ct);
        }
        if (!TcpOpen(ApachePort())) throw new InvalidOperationException($"Apache روی 127.0.0.1:{ApachePort()} پاسخ نمی‌دهد. از «باز کردن لاگ‌ها» استفاده کنید.");
        if (!TcpOpen(3306)) throw new InvalidOperationException("MariaDB روی 127.0.0.1:3306 پاسخ نمی‌دهد. از «باز کردن لاگ‌ها» استفاده کنید.");

        await ValidateApachePhpRuntimeAsync(ct);

        SetProgress(96, "Health check موفق بود.");
    }

    private async Task ValidateApachePhpRuntimeAsync(CancellationToken ct)
    {
        Directory.CreateDirectory(WebPublicPath());
        var fileName = $".sokna-php-runtime-probe-{Guid.NewGuid():N}.php";
        var probePath = Path.Combine(WebPublicPath(), fileName);
        var required = PhpRuntimeConfiguration.RequiredWebExtensions;
        var php = @"<?php
header('Content-Type: application/json; charset=utf-8');
$exts=['pdo','pdo_mysql','json','mbstring','sodium','zlib','zip','session'];
$out=['version'=>PHP_VERSION,'ini'=>php_ini_loaded_file(),'extension_dir'=>ini_get('extension_dir'),'extensions'=>[]];
foreach($exts as $e){$out['extensions'][$e]=extension_loaded($e);}
echo json_encode($out, JSON_UNESCAPED_SLASHES);
";
        await File.WriteAllTextAsync(probePath, php, new UTF8Encoding(false), ct);
        try
        {
            using var response = await _http.GetAsync(LocalWebUrl() + fileName, ct);
            var body = await response.Content.ReadAsStringAsync(ct);
            if (!response.IsSuccessStatusCode)
                throw new InvalidOperationException($"PHP داخل Apache health probe با HTTP {(int)response.StatusCode} شکست خورد: {TrimLog(body)}");

            using var doc = JsonDocument.Parse(body);
            var root = doc.RootElement;
            var version = root.GetProperty("version").GetString() ?? "";
            var ini = root.GetProperty("ini").GetString() ?? "";
            var extensionDir = root.GetProperty("extension_dir").GetString() ?? "";
            if (!version.StartsWith(Artifact("php").Version, StringComparison.OrdinalIgnoreCase))
                throw new InvalidOperationException($"PHP داخل Apache نسخه مورد انتظار را اجرا نمی‌کند: {version}");
            if (!InfrastructureOwnershipDetector.PathEquals(ini, Path.Combine(PhpPath(), "php.ini")))
                throw new InvalidOperationException($"PHP داخل Apache php.ini دیگری را خوانده است: {ini}");
            if (!InfrastructureOwnershipDetector.PathEquals(extensionDir, Path.Combine(PhpPath(), "ext")))
                throw new InvalidOperationException($"PHP داخل Apache extension_dir نادرست دارد: {extensionDir}");

            var extensions = root.GetProperty("extensions");
            var missing = required.Where(x => !extensions.TryGetProperty(x, out var v) || v.ValueKind != JsonValueKind.True).ToArray();
            if (missing.Length > 0)
                throw new InvalidOperationException("PHP داخل Apache extensionهای لازم Local Web را لود نکرده است: " + string.Join(", ", missing));

            Log($"Apache PHP runtime ready. Version={version}; php.ini={ini}; extension_dir={extensionDir}; required_extensions=OK");
        }
        finally
        {
            try { if (File.Exists(probePath)) File.Delete(probePath); } catch { }
        }
    }

    private void WriteState(OperationMode mode)
    {
        var state = new
        {
            format = "sokna-infrastructure-state-v1",
            schema_version = 1,
            updated_at_utc = DateTime.UtcNow,
            last_operation = mode.ToString().ToLowerInvariant(),
            root = RootPath(),
            paths = new { infrastructure = InfraPath(), php = PhpPath(), apache = ApachePath(), mariadb = MariaPath(), mariadb_data = DataPath(), local_web_root = WebPath(), apache_document_root = WebPublicPath(), logs = LogsPath() },
            services = new { apache = "SoknaApache", mariadb = "SoknaMariaDB" },
            endpoints = new
            {
                local_web = new { scheme = "http", host = "127.0.0.1", port = ApachePort(), base_url = LocalWebUrl(), origin = LocalWebUrl().TrimEnd('/') },
                mariadb = "127.0.0.1:3306"
            },
            safeguards = new { local_web_payload_managed = false, sokna_database_managed = false, existing_mariadb_data_reinitialized = false, cross_root_existing_installation_detected = false }
        };
        File.WriteAllText(StatePath(), JsonSerializer.Serialize(state, new JsonSerializerOptions { WriteIndented = true }), new UTF8Encoding(false));
    }

    private LockedArtifact Artifact(string dependency) =>
        _lock.Artifacts.Single(x => string.Equals(x.Dependency, dependency, StringComparison.OrdinalIgnoreCase));

    private async Task<string> DownloadVerifiedAsync(LockedArtifact artifact, CancellationToken ct)
    {
        var cache = PrerequisiteCacheRoot();
        Directory.CreateDirectory(cache);
        var final = Path.Combine(cache, artifact.FileName);
        var partial = final + ".partial";

        if (File.Exists(partial) && new FileInfo(partial).Length == artifact.Size)
        {
            UpdateArtifactProgress(artifact.Dependency, 94, "فایل ناقص قبلی به حجم کامل رسیده؛ در حال بررسی SHA-256...");
            if (await Task.Run(() => VerifyFile(partial, artifact), ct))
            {
                File.Move(partial, final, true);
                UpdateArtifactProgress(artifact.Dependency, 100, "دانلود قبلی کامل و تأیید شد.");
                return final;
            }
            try { File.Delete(partial); } catch { }
            Log($"Completed partial artifact failed hash and was removed: {artifact.FileName}");
        }

        if (File.Exists(final))
        {
            UpdateArtifactProgress(artifact.Dependency, 20, "فایل Cache پیدا شد؛ در حال بررسی SHA-256...");
            if (await Task.Run(() => VerifyFile(final, artifact), ct))
            {
                Log($"Cache hit verified: {artifact.FileName}");
                UpdateArtifactProgress(artifact.Dependency, 100, "فایل Cache تأیید شد.");
                return final;
            }
            Log($"Cache artifact invalid and removed: {artifact.FileName}");
            try { File.Delete(final); } catch { }
        }

        var urls = new[] { artifact.SourceUrl }
            .Concat(artifact.FallbackUrls ?? [])
            .Where(x => !string.IsNullOrWhiteSpace(x))
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .ToList();

        var failures = new List<string>();
        for (var i = 0; i < urls.Count; i++)
        {
            ct.ThrowIfCancellationRequested();
            var url = urls[i];
            var host = new Uri(url).Host;
            try
            {
                UpdateArtifactProgress(artifact.Dependency, 1, $"بررسی دسترسی به منبع {i + 1} از {urls.Count}: {host}");
                Log($"Download probe/start: {artifact.Dependency} from {url}");
                await DownloadArtifactResumableAsync(artifact, url, partial, ct);

                var file = new FileInfo(partial);
                if (!file.Exists || file.Length != artifact.Size)
                    throw new InvalidOperationException($"حجم نهایی دانلود صحیح نیست. انتظار: {FormatBytes(artifact.Size)}؛ دریافت‌شده: {(file.Exists ? FormatBytes(file.Length) : "0 B")}.");

                UpdateArtifactProgress(artifact.Dependency, 96, "دانلود کامل شد؛ در حال بررسی SHA-256...");
                if (!await Task.Run(() => VerifyFile(partial, artifact), ct))
                {
                    try { File.Delete(partial); } catch { }
                    throw new InvalidOperationException("SHA-256 فایل دانلودشده با نسخه قفل‌شده تطبیق ندارد؛ فایل ناقص/خراب حذف شد.");
                }

                File.Move(partial, final, true);
                UpdateArtifactProgress(artifact.Dependency, 100, "فایل دانلود و SHA-256 تأیید شد.");
                return final;
            }
            catch (OperationCanceledException) when (ct.IsCancellationRequested)
            {
                UpdateArtifactProgress(artifact.Dependency, 0, "عملیات توسط کاربر لغو شد؛ فایل ناقص برای Resume نگه داشته شد.");
                throw;
            }
            catch (Exception ex)
            {
                var message = $"{host}: {ex.Message}";
                failures.Add(message);
                Log($"Download source failed: {artifact.Dependency}; {message}");
                UpdateArtifactProgress(artifact.Dependency, 0, $"منبع {i + 1} در دسترس نبود؛ {(i + 1 < urls.Count ? "در حال تلاش از منبع بعدی..." : "دانلود آنلاین ناموفق بود.")}");
            }
        }

        var choose = MessageBox.Show(
            this,
            $"دانلود آنلاین «{artifact.FileName}» از هیچ منبع تأییدشده‌ای ممکن نشد.\n\nمی‌توانید همین فایل را با مرورگر، فیلترشکن، کامپیوتر دیگر یا فلش تهیه کنید و اکنون به برنامه تحویل دهید. فایل قبل از استفاده با حجم و SHA-256 بررسی می‌شود.\n\nفایل را از کامپیوتر انتخاب می‌کنید؟",
            "دریافت آنلاین ممکن نشد",
            MessageBoxButtons.YesNo,
            MessageBoxIcon.Information,
            MessageBoxDefaultButton.Button1,
            MessageBoxOptions.RtlReading | MessageBoxOptions.RightAlign);

        if (choose == DialogResult.Yes)
        {
            var manual = await SelectManualArtifactAsync(artifact.Dependency, true);
            if (!string.IsNullOrWhiteSpace(manual)) return manual;
        }

        throw new InvalidOperationException(
            $"فایل {artifact.FileName} به‌صورت آنلاین در دسترس نبود. از بخش «فایل‌های پیش‌نیاز و نصب آفلاین» فایل دستی یا Offline Kit را انتخاب کنید. " +
            string.Join(" | ", failures));
    }

    private async Task DownloadArtifactResumableAsync(LockedArtifact artifact, string sourceUrl, string partial, CancellationToken ct)
    {
        var existing = File.Exists(partial) ? new FileInfo(partial).Length : 0L;
        if (existing < 0 || existing > artifact.Size)
        {
            try { File.Delete(partial); } catch { }
            existing = 0;
        }

        using var request = new HttpRequestMessage(HttpMethod.Get, sourceUrl);
        request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/octet-stream"));
        request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("*/*"));
        if (existing > 0) request.Headers.Range = new RangeHeaderValue(existing, null);

        HttpResponseMessage response;
        using (var headerCts = CancellationTokenSource.CreateLinkedTokenSource(ct))
        {
            headerCts.CancelAfter(TimeSpan.FromSeconds(7));
            try
            {
                response = await _http.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, headerCts.Token);
            }
            catch (OperationCanceledException) when (!ct.IsCancellationRequested)
            {
                throw new TimeoutException("سرور ظرف ۷ ثانیه پاسخ اولیه نداد.");
            }
        }

        using (response)
        {
            if (response.RequestMessage?.RequestUri?.Scheme != Uri.UriSchemeHttps)
                throw new InvalidOperationException("مسیر دانلود از HTTPS خارج شد و برای امنیت متوقف شد.");

            if (!response.IsSuccessStatusCode)
                throw new HttpRequestException($"سرور کد {(int)response.StatusCode} ({response.ReasonPhrase}) برگرداند.", null, response.StatusCode);

            if (existing > 0 && response.StatusCode != HttpStatusCode.PartialContent)
            {
                Log($"Server does not support resume for {artifact.Dependency}; restarting file from zero.");
                existing = 0;
                try { if (File.Exists(partial)) File.Delete(partial); } catch { }
            }

            var expectedTransfer = artifact.Size - existing;
            var serverLength = response.Content.Headers.ContentLength;
            if (serverLength.HasValue && serverLength.Value != expectedTransfer)
                throw new InvalidOperationException($"حجم اعلام‌شده توسط سرور ({FormatBytes(serverLength.Value)}) با حجم مورد انتظار این مرحله ({FormatBytes(expectedTransfer)}) یکسان نیست.");

            var mode = existing > 0 ? FileMode.Append : FileMode.Create;
            await using var input = await response.Content.ReadAsStreamAsync(ct);
            await using var output = new FileStream(partial, mode, FileAccess.Write, FileShare.None, 256 * 1024, true);

            var buffer = new byte[256 * 1024];
            long done = existing;
            long sampledBytes = 0;
            var sample = Stopwatch.StartNew();
            double lastSpeed = 0;

            UpdateDownloadUi(artifact, done, lastSpeed, serverLength);

            while (true)
            {
                int read;
                try
                {
                    read = await input.ReadAsync(buffer.AsMemory(0, buffer.Length), ct)
                        .AsTask()
                        .WaitAsync(TimeSpan.FromSeconds(20), ct);
                }
                catch (TimeoutException)
                {
                    throw new TimeoutException("در ۲۰ ثانیه گذشته هیچ داده‌ای از سرور دریافت نشد.");
                }

                if (read <= 0) break;
                await output.WriteAsync(buffer.AsMemory(0, read), ct);
                done += read;
                sampledBytes += read;

                if (done > artifact.Size)
                    throw new InvalidOperationException("حجم داده دریافت‌شده از اندازه نسخه تأییدشده بیشتر شد.");

                if (sample.ElapsedMilliseconds >= 500)
                {
                    lastSpeed = sampledBytes / Math.Max(sample.Elapsed.TotalSeconds, 0.001);
                    sampledBytes = 0;
                    sample.Restart();
                    UpdateDownloadUi(artifact, done, lastSpeed, serverLength);
                }
            }

            await output.FlushAsync(ct);
            UpdateDownloadUi(artifact, done, lastSpeed, serverLength);
        }
    }

    private void UpdateDownloadUi(LockedArtifact artifact, long done, double bytesPerSecond, long? serverLength)
    {
        if (InvokeRequired)
        {
            BeginInvoke(new Action(() => UpdateDownloadUi(artifact, done, bytesPerSecond, serverLength)));
            return;
        }

        var pct = artifact.Size <= 0 ? 0 : (int)Math.Clamp(done * 100L / artifact.Size, 0, 100);
        var remaining = Math.Max(0, artifact.Size - done);
        var eta = bytesPerSecond > 0 ? remaining / bytesPerSecond : double.NaN;
        var server = serverLength.HasValue ? $" • حجم پاسخ سرور: {FormatBytes(serverLength.Value)}" : "";
        var text = $"{FormatBytes(done)} / {FormatBytes(artifact.Size)} • {pct}% • {FormatSpeed(bytesPerSecond)} • باقی‌مانده {FormatEta(eta)}{server}";
        UpdateArtifactProgress(artifact.Dependency, pct, text);

        var (start, end) = artifact.Dependency switch
        {
            "php" => (8, 20),
            "apache" => (34, 48),
            "mariadb" => (60, 74),
            _ => (0, 100)
        };
        _progress.Value = Math.Clamp(start + (end - start) * pct / 100, 0, 100);
        _progressText.Text = $"{DependencyDisplayName(artifact.Dependency)} — {text}";
    }

    private static bool VerifyFile(string path, LockedArtifact a)
    {
        var fi = new FileInfo(path);
        if (!fi.Exists || (a.Size > 0 && fi.Length != a.Size)) return false;
        using var s = File.OpenRead(path);
        var hash = Convert.ToHexString(SHA256.HashData(s)).ToLowerInvariant();
        return string.Equals(hash, a.Sha256, StringComparison.OrdinalIgnoreCase);
    }

    private string? FindMariaServer()
    {
        var candidates = new[]
        {
            Path.Combine(MariaPath(), "bin", "mariadbd.exe"),
            Path.Combine(MariaPath(), "bin", "mysqld.exe")
        };
        return candidates.FirstOrDefault(File.Exists);
    }

    private string? FindMariaInstallDb()
    {
        foreach (var name in new[] { "mariadb-install-db.exe", "mysql_install_db.exe" })
        {
            var p = Path.Combine(MariaPath(), "bin", name);
            if (File.Exists(p)) return p;
        }
        return null;
    }

    private bool MariaDataInitialized() => Directory.Exists(Path.Combine(DataPath(), "mysql"));

    private static string? FindRootContaining(string root, string relative)
    {
        if (File.Exists(Path.Combine(root, relative))) return root;
        foreach (var d in Directory.EnumerateDirectories(root, "*", SearchOption.AllDirectories))
            if (File.Exists(Path.Combine(d, relative))) return d;
        return null;
    }

    private static void CopyDirectory(string source, string dest)
    {
        Directory.CreateDirectory(dest);
        foreach (var file in Directory.GetFiles(source))
            File.Copy(file, Path.Combine(dest, Path.GetFileName(file)), true);
        foreach (var dir in Directory.GetDirectories(source))
            CopyDirectory(dir, Path.Combine(dest, Path.GetFileName(dir)));
    }

    private static string NewTemp(string name)
    {
        var p = Path.Combine(Path.GetTempPath(), $"sokna-prereq-{name}-{Guid.NewGuid():N}");
        Directory.CreateDirectory(p);
        return p;
    }

    private static void SafeDelete(string path)
    {
        try { if (Directory.Exists(path)) Directory.Delete(path, true); } catch { }
    }

    private void BackupFile(string file)
    {
        if (!File.Exists(file)) return;
        var backup = file + ".sokna-backup-" + DateTime.Now.ToString("yyyyMMdd-HHmmss");
        File.Copy(file, backup, true);
    }

    private ProcessResult RunProcess(string file, string args, string safeArgs, int timeoutMs = 120000, bool allowFailure = false)
    {
        Log($"RUN {file} {safeArgs}");
        using var p = new Process
        {
            StartInfo = new ProcessStartInfo
            {
                FileName = file,
                Arguments = args,
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                WorkingDirectory = Path.GetDirectoryName(file) is { Length: > 0 } d && Directory.Exists(d) ? d : Environment.CurrentDirectory
            }
        };
        p.Start();
        var so = p.StandardOutput.ReadToEndAsync();
        var se = p.StandardError.ReadToEndAsync();
        if (!p.WaitForExit(timeoutMs))
        {
            try { p.Kill(true); } catch { }
            throw new TimeoutException($"زمان اجرای {Path.GetFileName(file)} تمام شد.");
        }
        Task.WaitAll(so, se);
        var r = new ProcessResult(p.ExitCode, so.Result, se.Result);
        Log($"EXIT {p.ExitCode} | {TrimLog(r.Output)} | {TrimLog(r.Error)}");
        if (!allowFailure && r.ExitCode != 0) return r;
        return r;
    }

    private static string TrimLog(string s)
    {
        s = s.Replace("\r", " ").Replace("\n", " ").Trim();
        return s.Length <= 700 ? s : s[..700] + "...";
    }

    private bool ServiceExists(string name)
    {
        var r = RunProcess("sc.exe", $"query \"{name}\"", $"query \"{name}\"", allowFailure: true);
        return r.ExitCode == 0;
    }

    private string ServiceStatus(string name)
    {
        var r = RunProcess("sc.exe", $"query \"{name}\"", $"query \"{name}\"", allowFailure: true);
        if (r.ExitCode != 0) return "ثبت نشده";
        var m = Regex.Match(r.Output, @"STATE\s*:\s*\d+\s+(\w+)", RegexOptions.IgnoreCase);
        return m.Success ? m.Groups[1].Value : "ثبت شده";
    }

    private static bool TcpOpen(int port)
    {
        try
        {
            using var c = new TcpClient();
            var t = c.ConnectAsync("127.0.0.1", port);
            return t.Wait(TimeSpan.FromMilliseconds(700)) && c.Connected;
        }
        catch { return false; }
    }

    private static bool WaitForPort(int port, TimeSpan timeout)
    {
        var until = DateTime.UtcNow + timeout;
        while (DateTime.UtcNow < until)
        {
            if (TcpOpen(port)) return true;
            Thread.Sleep(500);
        }
        return TcpOpen(port);
    }

    private static string Slash(string p) => p.Replace('\\', '/');
    private static string Technical(string value) => "\u200E" + value + "\u200E";
    private static string FirstLine(string s) => s.Split(['\r', '\n'], StringSplitOptions.RemoveEmptyEntries).FirstOrDefault() ?? "";

    private void SetBusy(bool busy, string message)
    {
        _run.Enabled = !busy;
        _analyze.Enabled = !busy;
        _browse.Enabled = !busy;
        _cancel.Enabled = busy && _operationCts is not null;
        _offlineFolder.Enabled = !busy;
        foreach (var button in _artifactSelectButtons.Values) button.Enabled = !busy;
        _progressText.Text = message;
        UseWaitCursor = busy;
    }

    private void SetProgress(int value, string text)
    {
        if (InvokeRequired) { BeginInvoke(new Action(() => SetProgress(value, text))); return; }
        _progress.Value = Math.Clamp(value, 0, 100);
        _progressText.Text = text;
        _status.AppendText(text + Environment.NewLine);
        Log(text);
    }

    private void Log(string line)
    {
        var record = $"[{DateTime.Now:yyyy-MM-dd HH:mm:ss.fff}] {line}{Environment.NewLine}";
        try
        {
            EnsureSessionLog();
            File.AppendAllText(_sessionLog, record, new UTF8Encoding(false));
        }
        catch { }

        if (string.IsNullOrWhiteSpace(_currentLog) || string.Equals(_currentLog, _sessionLog, StringComparison.OrdinalIgnoreCase))
            return;

        try
        {
            Directory.CreateDirectory(Path.GetDirectoryName(_currentLog)!);
            File.AppendAllText(_currentLog, record, new UTF8Encoding(false));
        }
        catch { }
    }

    private void OpenLogs()
    {
        try
        {
            string target;
            try
            {
                Directory.CreateDirectory(LogsPath());
                target = LogsPath();
            }
            catch
            {
                EnsureSessionLog();
                target = Path.GetDirectoryName(_sessionLog)!;
            }
            Process.Start(new ProcessStartInfo("explorer.exe", $"\"{target}\"") { UseShellExecute = true });
        }
        catch (Exception ex) { ShowError(ex); }
    }

    private async Task CreateSupportBundleAsync()
    {
        try
        {
            PreparePersistentLayout();
            var support = Path.Combine(InfraPath(), "Support");
            Directory.CreateDirectory(support);
            var temp = NewTemp("support");
            try
            {
                if (File.Exists(StatePath())) File.Copy(StatePath(), Path.Combine(temp, "infrastructure-state.json"), true);
                var dst = Path.Combine(temp, "logs");
                Directory.CreateDirectory(dst);
                if (Directory.Exists(LogsPath()))
                {
                    foreach (var f in Directory.GetFiles(LogsPath(), "*.log").TakeLast(20))
                        File.Copy(f, Path.Combine(dst, Path.GetFileName(f)), true);
                }
                EnsureSessionLog();
                if (File.Exists(_sessionLog))
                    File.Copy(_sessionLog, Path.Combine(dst, Path.GetFileName(_sessionLog)), true);

                var apacheLog=Path.Combine(ApachePath(),"logs","error.log");
                if(File.Exists(apacheLog))
                    File.Copy(apacheLog,Path.Combine(dst,"apache-error.log"),true);

                var apacheConf=Path.Combine(ApachePath(),"conf","httpd.conf");
                if(File.Exists(apacheConf))
                    File.Copy(apacheConf,Path.Combine(dst,"apache-httpd.conf"),true);

                var diag = new StringBuilder();
                diag.AppendLine("SOKNA Prerequisites support bundle");
                diag.AppendLine("No passwords or application credentials are intentionally included.");
                diag.AppendLine($"Root={RootPath()}");
                diag.AppendLine($"Apache={ServiceStatus("SoknaApache")}; Port{ApachePort()}={TcpOpen(ApachePort())}");
                diag.AppendLine($"ApacheImagePath={InfrastructureOwnershipDetector.ReadServiceImagePath("SoknaApache") ?? "<not-registered>"}");
                diag.AppendLine($"ApacheLocalWebConfigReady={(File.Exists(apacheConf) && ApacheConfigurationReady(apacheConf))}");
                diag.AppendLine($"MariaDB={ServiceStatus("SoknaMariaDB")}; Port3306={TcpOpen(3306)}");
                diag.AppendLine($"MariaDataPresent={MariaDataInitialized()}");
                var ownership=InfrastructureOwnershipDetector.Detect(RootPath(),Artifact("mariadb").Version);
                diag.AppendLine($"CrossRootConflict={ownership.HasConflict}");
                foreach(var evidence in ownership.Evidence) diag.AppendLine("OwnershipEvidence="+evidence);
                foreach(var conflict in ownership.Conflicts) diag.AppendLine("OwnershipConflict="+conflict);
                await File.WriteAllTextAsync(Path.Combine(temp, "diagnostics.txt"), diag.ToString(), new UTF8Encoding(false));
                var zip = Path.Combine(support, $"SOKNA-Prerequisites-Support-{DateTime.Now:yyyyMMdd-HHmmss}.zip");
                ZipFile.CreateFromDirectory(temp, zip, CompressionLevel.Optimal, false);
                ShowNotice("بسته پشتیبانی ساخته شد", $"{zip}\n\nرمزها و اطلاعات ورود Local Web عمداً داخل این بسته قرار نگرفته‌اند.", false);
                Process.Start(new ProcessStartInfo("explorer.exe", $"/select,\"{zip}\"") { UseShellExecute = true });
            }
            finally { SafeDelete(temp); }
        }
        catch (Exception ex) { ShowError(ex); }
    }

    private void ShowError(Exception ex)
    {
        Log("ERROR: " + ex);
        _status.AppendText($"\nخطا: {ex.Message}\n");
        var logPath = !string.IsNullOrWhiteSpace(_currentLog) ? _currentLog : _sessionLog;
        ShowNotice(
            "خطای آماده‌سازی زیرساخت",
            $"{ex.Message}\n\nفایل گزارش:\n{logPath}\n\nبرای بررسی بیشتر از «ساخت بسته پشتیبانی» استفاده کنید.",
            true);
    }

    private void ShowNotice(string title, string message, bool isError)
    {
        using var dialog = new Form
        {
            Text = title,
            Width = 600,
            Height = 285,
            MinimumSize = new Size(520, 240),
            StartPosition = FormStartPosition.CenterParent,
            FormBorderStyle = FormBorderStyle.FixedDialog,
            MaximizeBox = false,
            MinimizeBox = false,
            ShowInTaskbar = false,
            RightToLeft = RightToLeft.Yes,
            RightToLeftLayout = false,
            Font = Font,
            Icon = Icon
        };

        var layout = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 2,
            RowCount = 2,
            Padding = new Padding(20),
            RightToLeft = RightToLeft.Yes
        };
        layout.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        layout.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 52));
        layout.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        layout.RowStyles.Add(new RowStyle(SizeType.AutoSize));

        var text = new Label
        {
            Text = message,
            Dock = DockStyle.Fill,
            AutoSize = false,
            RightToLeft = RightToLeft.Yes,
            TextAlign = ContentAlignment.TopRight,
            Padding = new Padding(8, 4, 8, 4)
        };
        var icon = new PictureBox
        {
            Image = (isError ? SystemIcons.Error : SystemIcons.Information).ToBitmap(),
            SizeMode = PictureBoxSizeMode.CenterImage,
            Dock = DockStyle.Top,
            Height = 48
        };
        var close = new Button
        {
            Text = "بستن",
            AutoSize = true,
            MinimumSize = new Size(100, 36),
            DialogResult = DialogResult.OK
        };

        var buttons = new FlowLayoutPanel
        {
            Dock = DockStyle.Fill,
            AutoSize = true,
            FlowDirection = FlowDirection.RightToLeft,
            RightToLeft = RightToLeft.Yes
        };
        buttons.Controls.Add(close);

        layout.Controls.Add(text, 0, 0);
        layout.Controls.Add(icon, 1, 0);
        layout.Controls.Add(buttons, 0, 1);
        layout.SetColumnSpan(buttons, 2);
        dialog.Controls.Add(layout);
        dialog.AcceptButton = close;
        dialog.CancelButton = close;
        dialog.ShowDialog(this);
    }

    protected override void OnFormClosing(FormClosingEventArgs e)
    {
        if (_cancel.Enabled) _operationCts?.Cancel();
        base.OnFormClosing(e);
    }
}
