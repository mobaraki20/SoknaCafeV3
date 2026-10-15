namespace Sokna.Maintenance.Core;

public sealed record MaintenancePaths(string DataRoot)
{
    public string SecretsRoot => Path.Combine(DataRoot, "secrets");
    public string JournalPath => Path.Combine(DataRoot, "maintenance-journal.jsonl");
    public string StatePath => Path.Combine(DataRoot, "supervisor-state.json");
    public string LockPath => Path.Combine(DataRoot, "supervisor.lock");
    public string DescriptorPath => Path.Combine(DataRoot, "control-plane.json");
    public string WebAdapterAclEvidencePath => Path.Combine(DataRoot, "web-adapter-acl.json");
    public string WebAdapterKeyPath => Path.Combine(SecretsRoot, "web-adapter.key");
    public string RecoveryConsoleKeyPath => Path.Combine(SecretsRoot, "recovery-console.key");

    public static string DefaultDataRoot()
    {
        var common = Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData);
        if (string.IsNullOrWhiteSpace(common))
        {
            throw new InvalidOperationException("CommonApplicationData is unavailable.");
        }

        return Path.Combine(common, "SOKNA", "Maintenance");
    }
}
