namespace Sokna.Maintenance.Core;

public sealed class SupervisorStateStore
{
    private readonly MaintenancePaths _paths;

    public SupervisorStateStore(MaintenancePaths paths) => _paths = paths;

    public SupervisorState LoadOrCreate()
    {
        if (File.Exists(_paths.StatePath))
        {
            var existing = AtomicFile.ReadJson<SupervisorState>(_paths.StatePath);
            Validate(existing);
            return existing with { LastStartedAtUtc = DateTimeOffset.UtcNow };
        }

        var now = DateTimeOffset.UtcNow;
        return new SupervisorState(
            Format: "sokna-maintenance-supervisor-state-v1",
            SchemaVersion: 1,
            Protocol: HmacProtocol.Protocol,
            InstanceId: Guid.NewGuid().ToString("D"),
            InstallGeneration: 1,
            CreatedAtUtc: now,
            LastStartedAtUtc: now,
            RecoveryRequired: false,
            LocalReleaseBindingState: "unbound_p1");
    }

    public void Save(SupervisorState state)
    {
        Validate(state);
        AtomicFile.WriteJson(_paths.StatePath, state);
    }

    private static void Validate(SupervisorState state)
    {
        if (state.Format != "sokna-maintenance-supervisor-state-v1" || state.SchemaVersion != 1)
            throw new InvalidDataException("Unsupported Supervisor state format.");
        if (state.Protocol != HmacProtocol.Protocol)
            throw new InvalidDataException("Supervisor state protocol mismatch.");
        if (!Guid.TryParse(state.InstanceId, out _))
            throw new InvalidDataException("Supervisor instance_id is invalid.");
        if (state.InstallGeneration < 1)
            throw new InvalidDataException("Supervisor install_generation is invalid.");
    }
}
