using System.ComponentModel;
using System.Diagnostics;
using System.Reflection;
using System.Text.Json;
using System.Windows.Forms;

namespace Sokna.InstallerBootstrap;

internal static class Program
{
    [STAThread]
    private static int Main(string[] args)
    {
        Application.EnableVisualStyles();
        var tx = "ins-" + DateTimeOffset.UtcNow.ToString("yyyyMMddTHHmmssZ") + "-" + Guid.NewGuid().ToString("N")[..10];
        var corr = "corr-" + Guid.NewGuid().ToString("N");
        var session = "ui-" + Guid.NewGuid().ToString("N");
        var userRoot = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "SOKNA", "Installer");
        var logDir = Path.Combine(userRoot, "logs", tx);
        Directory.CreateDirectory(logDir);
        var events = Path.Combine(logDir, "bootstrap-events.jsonl");
        void Ev(string name, string result, object? data = null)
        {
            try
            {
                var row = new { format = "sokna-installer-event-v1", at_utc = DateTimeOffset.UtcNow, transaction_id = tx, correlation_id = corr, setup_session_id = session, event_name = name, result, data };
                File.AppendAllText(events, JsonSerializer.Serialize(row) + Environment.NewLine);
            }
            catch { }
        }
        try
        {
            Ev("setup_bootstrap_started", "started", new { process_id = Environment.ProcessId, elevated = false });
            Directory.CreateDirectory(userRoot);
            var context = new
            {
                format = "sokna-installer-active-context-v1",
                transaction_id = tx,
                correlation_id = corr,
                setup_session_id = session,
                log_directory = logDir,
                created_at_utc = DateTimeOffset.UtcNow,
                expires_at_utc = DateTimeOffset.UtcNow.AddMinutes(45)
            };
            File.WriteAllText(Path.Combine(userRoot, "active-context.json"), JsonSerializer.Serialize(context, new JsonSerializerOptions { WriteIndented = true }));
            File.WriteAllText(Path.Combine(logDir, "bootstrap-context.json"), JsonSerializer.Serialize(context, new JsonSerializerOptions { WriteIndented = true }));

            var tmpDir = Path.Combine(Path.GetTempPath(), "SOKNA", "Installer", tx);
            Directory.CreateDirectory(tmpDir);
            var core = Path.Combine(tmpDir, "SOKNA-Windows-Services-Core.exe");
            using (var source = Assembly.GetExecutingAssembly().GetManifestResourceStream("WindowsServicesInstallerPayload") ?? throw new InvalidOperationException("Core installer resource is missing."))
            using (var target = new FileStream(core, FileMode.Create, FileAccess.Write, FileShare.None)) source.CopyTo(target);
            Ev("core_installer_extracted", "completed", new { size = new FileInfo(core).Length });

            var innoLog = Path.Combine(logDir, "inno-elevated.log");
            Ev("elevation_requested", "started");
            var psi = new ProcessStartInfo(core)
            {
                UseShellExecute = true,
                Verb = "runas",
                WorkingDirectory = tmpDir,
                Arguments = $"/SOKNA_TX={tx} /LOG=\"{innoLog}\""
            };
            try
            {
                using var process = Process.Start(psi) ?? throw new InvalidOperationException("Elevated installer did not start.");
                Ev("elevation_succeeded", "completed", new { child_process_id = process.Id });
                process.WaitForExit();
                Ev("core_installer_completed", process.ExitCode == 0 ? "completed" : "failed", new { exit_code = process.ExitCode });
                if (process.ExitCode != 0)
                {
                    MessageBox.Show($"نصب کامل نشد.\r\n\r\nشناسه عیب‌یابی: {tx}\r\nلاگ نصب برای بررسی نگه داشته شده است.", "نصب سرویس‌های سکنا", MessageBoxButtons.OK, MessageBoxIcon.Error, MessageBoxDefaultButton.Button1, MessageBoxOptions.RtlReading | MessageBoxOptions.RightAlign);
                }
                return process.ExitCode;
            }
            catch (Win32Exception ex) when (ex.NativeErrorCode == 1223)
            {
                Ev("elevation_cancelled", "cancelled");
                MessageBox.Show($"درخواست دسترسی Administrator لغو شد.\r\n\r\nشناسه عیب‌یابی: {tx}", "نصب سرویس‌های سکنا", MessageBoxButtons.OK, MessageBoxIcon.Information, MessageBoxDefaultButton.Button1, MessageBoxOptions.RtlReading | MessageBoxOptions.RightAlign);
                return 1223;
            }
            finally
            {
                try { File.Delete(core); } catch { }
                try { Directory.Delete(tmpDir, true); } catch { }
            }
        }
        catch (Exception ex)
        {
            Ev("setup_bootstrap_failed", "failed", new { error = Safe(ex.Message), exception = ex.GetType().Name });
            MessageBox.Show($"شروع نصب با خطا متوقف شد.\r\n\r\nشناسه عیب‌یابی: {tx}\r\n{Safe(ex.Message)}", "نصب سرویس‌های سکنا", MessageBoxButtons.OK, MessageBoxIcon.Error, MessageBoxDefaultButton.Button1, MessageBoxOptions.RtlReading | MessageBoxOptions.RightAlign);
            return 2;
        }
    }

    private static string Safe(string value)
    {
        value = (value ?? "").Replace('\r', ' ').Replace('\n', ' ').Trim();
        return value.Length <= 500 ? value : value[..500];
    }
}
