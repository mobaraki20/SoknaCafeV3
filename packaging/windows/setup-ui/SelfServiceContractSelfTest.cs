using System.Reflection;
using System.Runtime.CompilerServices;
using System.Text;

namespace Sokna.SetupUi;

internal static class SelfServiceContractSelfTest
{
    [ModuleInitializer]
    internal static void Initialize()
    {
        var args = Environment.GetCommandLineArgs();
        var index = Array.FindIndex(args, value => value.Equals("--self-service-self-test", StringComparison.OrdinalIgnoreCase));
        if (index < 0) return;
        var output = index + 1 < args.Length
            ? Path.GetFullPath(args[index + 1])
            : Path.Combine(Path.GetTempPath(), "sokna-self-service-self-test.txt");
        Environment.Exit(Run(output));
    }

    private static int Run(string outputPath)
    {
        var failures = new List<string>();
        var report = new StringBuilder();
        try
        {
            var method = typeof(SelfServiceDiagnostics).GetMethod("BuildFindings", BindingFlags.Static | BindingFlags.NonPublic);
            if (method is null) throw new InvalidOperationException("BuildFindings method was not found.");

            void AssertScenario(string name, SelfServiceSnapshot snapshot, params string[] expectedCodes)
            {
                var value = method.Invoke(null, new object[] { snapshot });
                if (value is not List<SelfServiceFinding> findings)
                    throw new InvalidOperationException("BuildFindings returned an unexpected value.");
                var codes = findings.Select(finding => finding.Code).ToArray();
                report.AppendLine(name + ": " + string.Join(",", codes));
                foreach (var code in expectedCodes)
                {
                    if (!codes.Contains(code, StringComparer.Ordinal))
                        failures.Add(name + " missing " + code);
                }
                foreach (var finding in findings)
                {
                    if (string.IsNullOrWhiteSpace(finding.Code) || string.IsNullOrWhiteSpace(finding.Title) || string.IsNullOrWhiteSpace(finding.Guidance))
                        failures.Add(name + " contains a finding without code/title/guidance");
                }
            }

            static ServiceProbe Service(string name, bool installed = true, bool running = true, bool executableExists = true) =>
                new(name, installed, running, installed ? $"C:\\Program Files\\SOKNA Windows Services\\{name}.exe" : "", installed ? $"C:\\Program Files\\SOKNA Windows Services\\{name}.exe" : "", installed && executableExists);

            var healthy = new SelfServiceSnapshot(
                Service("SoknaRuntime"), Service("SoknaPrintWorker"), true, "14.44.35211", true,
                "http://127.0.0.1:18080/", "1.0.11", "D:\\SOKNA\\Data", true, true, true, true);
            AssertScenario("healthy", healthy, "WS-HEALTH-OK");

            AssertScenario("print-stopped", healthy with { Print = Service("SoknaPrintWorker", running: false) }, "WS-SVC-PRINT-STOPPED-001");
            AssertScenario("runtime-stopped", healthy with { Runtime = Service("SoknaRuntime", running: false) }, "WS-SVC-RUNTIME-STOPPED-001");
            AssertScenario("service-missing", healthy with { Print = Service("SoknaPrintWorker", installed: false) }, "WS-SVC-MISSING-001");
            AssertScenario("payload-missing", healthy with { Print = Service("SoknaPrintWorker", executableExists: false) }, "WS-FILE-PRINT-001");
            AssertScenario("prerequisite-missing", healthy with { PrerequisiteReady = false }, "WS-PREREQ-VC-001");
            AssertScenario("state-bad", healthy with { StateReadable = false }, "WS-STATE-001");
            AssertScenario("version-unknown", healthy with { InstalledVersion = "" }, "WS-VERSION-001");
            AssertScenario("data-missing", healthy with { DataRootExists = false, DataRootWritable = false }, "WS-DATA-ROOT-001");
            AssertScenario("pairing-missing", healthy with { Paired = false, LocalBaseUrl = "", LocalWebReachable = false }, "WS-PAIR-001");
            AssertScenario("local-offline", healthy with { LocalWebReachable = false }, "WS-LOCAL-OFFLINE-001");

            var stoppedFindings = (List<SelfServiceFinding>)method.Invoke(null, new object[] { healthy with { Print = Service("SoknaPrintWorker", running: false) } })!;
            if (!stoppedFindings.Any(finding => finding.Code == "WS-SVC-PRINT-STOPPED-001" && finding.Remediation == SelfServiceRemediation.StartPrint))
                failures.Add("print-stopped remediation is not StartPrint");

            var missingFindings = (List<SelfServiceFinding>)method.Invoke(null, new object[] { healthy with { Print = Service("SoknaPrintWorker", installed: false) } })!;
            if (!missingFindings.Any(finding => finding.Code == "WS-SVC-MISSING-001" && finding.Remediation == SelfServiceRemediation.Repair))
                failures.Add("service-missing remediation is not Repair");

            // Regression: WinForms/GDI may draw U+2066/U+2069 isolate controls as visible glyphs.
            // Presentation must never leave those controls in user-visible strings.
            using var bidiProbe = new Form();
            var bidiLabel = new Label { Text = "اتصال به Local Web و Runtime" };
            bidiProbe.Controls.Add(bidiLabel);
            SelfServiceDashboardUx.RefreshPresentation(bidiProbe);
            if (SelfServiceDashboardUx.ContainsUnsupportedBidiIsolate(bidiLabel.Text))
                failures.Add("unsupported U+2066/U+2069 bidi isolate leaked into a visible control");
            if (!string.Equals(SelfServiceDashboardUx.RemoveIsolation(bidiLabel.Text), "اتصال به Local Web و Runtime", StringComparison.Ordinal))
                failures.Add("bidi presentation changed the underlying user-visible text");
            report.AppendLine("bidi-winforms-safe: " + (SelfServiceDashboardUx.ContainsUnsupportedBidiIsolate(bidiLabel.Text) ? "FAIL" : "PASS"));
        }
        catch (Exception exception)
        {
            failures.Add(exception.ToString());
        }

        report.AppendLine();
        report.AppendLine(failures.Count == 0 ? "PASS" : "FAIL");
        foreach (var failure in failures) report.AppendLine("FAIL: " + failure);
        Directory.CreateDirectory(Path.GetDirectoryName(outputPath) ?? Path.GetTempPath());
        File.WriteAllText(outputPath, report.ToString());
        return failures.Count == 0 ? 0 : 17;
    }
}
