using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Sokna.Maintenance.Core;

// F1-S7D2: ISOLATED, ARTIFICIAL cutover simulation. The "Apache", pointer
// and schema below are files under an ephemeral CI fixture. There is NO
// Windows service control, NTFS junction switch, SQL, HTTP, or deploy action.
// Reconstructing the reader models a restart, NOT a true OS process kill.
internal static class SyntheticLocalCutoverSimulator
{
    private const string StageId = "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb";
    private const string InstalledHash = "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc";

    internal static int Run(string inputRoot)
    {
        var root = Path.GetFullPath(inputRoot);
        Directory.CreateDirectory(root);
        for (var cut = -1; cut < 12; cut++)
            RunCrashAtEveryBoundary(Path.Combine(root, $"crash-{cut + 1:00}"), cut);
        foreach (var fault in new[] { "apache-stop", "pointer-swap", "apache-start", "target-health" })
            RunFailedAction(Path.Combine(root, $"fault-{fault}"), fault);
        for (var boundary = 0; boundary < 3; boundary++)
            RunCodeOnlyBoundary(Path.Combine(root, $"code-only-{boundary:00}"), boundary);
        Console.WriteLine(JsonSerializer.Serialize(new
        {
            format = "sokna-s7d2-isolated-cutover-simulation-v1",
            result = "PASS",
            crash_boundary_cases = 13,
            code_only_no_sql_recovery_boundaries = 3,
            injected_action_failures = 4,
            fake_apache_only = true,
            synthetic_pointer_only = true,
            synthetic_schema_only = true,
            restart_mode = "reconstruct_reader_from_durable_fixture",
            supervisor_lease_verified = true,
            no_automatic_rollback = true,
            forward_only_from_db_forward_intent = true,
            real_windows_junction_tested = false,
            real_apache_tested = false,
            real_mariadb_tested = false,
            real_host_uat = false,
            panel_update_ready = false
        }));
        return 0;
    }

    private static void RunCrashAtEveryBoundary(string root, int cut)
    {
        var host = new FakeHost(root);
        host.Seed();
        var oldHash = host.SlotHash("old");
        var newHash = host.SlotHash("new");
        var paths = new MaintenancePaths(Path.Combine(root, "maintenance"));
        using var lease = MachineMutationLease.Acquire(paths, LocalCodeCutoverWriteAheadJournal.Owner);
        var wal = new LocalCodeCutoverWriteAheadJournal(paths);
        const string id = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";
        wal.Begin(lease, id, StageId, host.OldSlot, host.NewSlot, InstalledHash);

        // Two different stage writers must never hold a mutation lease together.
        if (cut == -1)
        {
            bool refused = false;
            try
            {
                using var competing = MachineMutationLease.Acquire(paths, "unrelated-engine");
            }
            catch (InvalidOperationException ex) when
                (ex.Message == MachineMutationLease.ConflictCode) { refused = true; }
            Check(refused, "Concurrent cutover writer acquired the lease");
            refused = false;
            try { wal.Begin(lease, id, StageId, host.OldSlot, host.NewSlot, InstalledHash); }
            catch (InvalidOperationException ex) when
                (ex.Message == "CUTOVER_JOURNAL_ALREADY_EXISTS_OR_INTERRUPTED") { refused = true; }
            Check(refused, "A second begin overwrote the first transaction");
        }

        Action[] steps =
        {
            () => wal.Advance(lease, id, "apache_stop_intent", host.Evidence()),
            () => host.StopApache(),
            () => wal.Advance(lease, id, "apache_stop_observed", host.Evidence()),
            () => wal.Advance(lease, id, "pointer_switch_intent", host.Evidence()),
            () => host.SwitchPointer("new"),
            () => wal.Advance(lease, id, "pointer_switch_observed", host.Evidence()),
            () => host.StartApache(),
            () => wal.Advance(lease, id, "target_http_observed", host.Evidence()),
            () => wal.Advance(lease, id, "db_forward_intent", host.Evidence()),
            () => host.SetSchema(2),
            () => wal.Advance(lease, id, "db_forward_committed", host.Evidence()),
            () => wal.Advance(lease, id, "committed", host.Evidence())
        };
        for (int i = 0; i <= cut; i++) steps[i]();

        // Crash/restart boundary: deliberately discard in-memory WAL instance.
        var reloaded = new LocalCodeCutoverWriteAheadJournal(paths);
        var observedPointer = host.Pointer() == "new" ? host.NewSlot : host.OldSlot;
        var recovery = reloaded.Classify(id, observedPointer);
        Check(!recovery.ActivationAllowed && !recovery.PanelUpdateReady &&
              !recovery.AutomaticRollbackAllowed, "Crash incorrectly enabled update/rollback");
        Check(reloaded.ReadVerified(id).Sequence > 0, "Durable replay was unavailable");

        if (cut < 8) // strictly BEFORE the durable DB forward intent
        {
            Check(!recovery.DatabaseForwardOnly, "Pre-DB state called forward-only");
            // An explicit, audited compensation exercise, NEVER automatic.
            reloaded.Advance(lease, id, "pre_db_rollback_intent", host.Evidence());
            var midway = new LocalCodeCutoverWriteAheadJournal(paths)
                .Classify(id, observedPointer);
            Check(!midway.AutomaticRollbackAllowed, "Rollback intent became auto-authorized");
            host.StopApache();
            host.SwitchPointer("old");
            var pointerMovedButUnreported = new LocalCodeCutoverWriteAheadJournal(paths)
                .Classify(id, host.OldSlot);
            Check(!pointerMovedButUnreported.ActivationAllowed,
                "Physical pointer alone was accepted as healthy");
            reloaded.Advance(lease, id, "previous_pointer_observed", host.Evidence());
            host.StartApache();
            Check(host.IsApacheRunning && host.Pointer() == "old" && host.Schema() == 1,
                "Explicit pre-DB compensation did not restore synthetic host");
            reloaded.Advance(lease, id, "previous_http_observed", host.Evidence());
            var final = new LocalCodeCutoverWriteAheadJournal(paths).Classify(id, host.OldSlot);
            Check(!final.ActivationAllowed && !final.AutomaticRollbackAllowed,
                "Synthetic compensation authorized production activation");
            bool secondRollbackDenied = false;
            try { reloaded.Advance(lease, id, "pre_db_rollback_intent", host.Evidence()); }
            catch (InvalidOperationException ex) when
                (ex.Message == "CUTOVER_JOURNAL_ILLEGAL_TRANSITION") { secondRollbackDenied = true; }
            Check(secondRollbackDenied, "Duplicate rollback altered terminal evidence");
        }
        else
        {
            Check(recovery.DatabaseForwardOnly &&
                  recovery.Decision == "FORWARD_ONLY_RECOVERY_REQUIRED",
                  "DB forward intent was not fail-closed");
            bool denied = false;
            try { reloaded.Advance(lease, id, "pre_db_rollback_intent", host.Evidence()); }
            catch (InvalidOperationException ex) when
                (ex.Message == "CUTOVER_JOURNAL_ILLEGAL_TRANSITION") { denied = true; }
            Check(denied, "Pre-DB rollback allowed after DB forward intent");
            Check(host.Pointer() == "new", "Forward-only fixture changed target pointer");
            Check(host.Schema() == (cut >= 9 ? 2 : 1),
                "Forward-only fixture silently rewrote DB simulation");
        }
        Check(host.SlotHash("old") == oldHash && host.SlotHash("new") == newHash,
            "A synthetic test modified an immutable code slot");
    }

    private static void RunCodeOnlyBoundary(string root, int boundary)
    {
        var host = new FakeHost(root);
        host.Seed();
        var oldHash = host.SlotHash("old");
        var targetHash = host.SlotHash("new");
        var paths = new MaintenancePaths(Path.Combine(root, "maintenance"));
        using var lease = MachineMutationLease.Acquire(paths, LocalCodeCutoverWriteAheadJournal.Owner);
        var wal = new LocalCodeCutoverWriteAheadJournal(paths);
        const string id = "eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee";
        wal.Begin(lease, id, StageId, host.OldSlot, host.NewSlot, InstalledHash);
        wal.Advance(lease, id, "apache_stop_intent", host.Evidence());
        host.StopApache();
        wal.Advance(lease, id, "apache_stop_observed", host.Evidence());
        wal.Advance(lease, id, "pointer_switch_intent", host.Evidence());
        host.SwitchPointer("new");
        wal.Advance(lease, id, "pointer_switch_observed", host.Evidence());
        host.StartApache();
        wal.Advance(lease, id, "target_http_observed", host.Evidence());

        // No schema mutation or fake DB event is needed for code-only Local
        // releases. Simulate the three new durable boundaries and restart
        // the journal reader independently at each interruption.
        var phases = new[] {
            "code_only_lifecycle_intent",
            "code_only_lifecycle_observed",
            "code_only_committed"
        };
        for (var i = 0; i <= boundary; i++)
            wal.Advance(lease, id, phases[i], host.Evidence());

        var restored = new LocalCodeCutoverWriteAheadJournal(paths);
        var read = restored.ReadVerified(id);
        var decision = restored.Classify(id, host.NewSlot);
        Check(read.Phase == phases[boundary] && !decision.DatabaseForwardOnly &&
              !decision.AutomaticRollbackAllowed && !decision.ActivationAllowed &&
              !decision.PanelUpdateReady,
            "Code-only journal incorrectly committed SQL or authorized a real update");
        if (boundary == 2)
            Check(decision.Decision == "CODE_ONLY_COMMIT_REQUIRES_LIVE_REATTESTATION",
                "Code-only terminal event skipped independent live validation");
        else
            Check(decision.Decision == "PRE_DB_TARGET_STATE_REQUIRES_RECONCILIATION",
                "Interrupted code-only lifecycle was falsely marked healthy");
        Check(host.Schema() == 1 && host.Pointer() == "new" && host.IsApacheRunning &&
              host.SlotHash("old") == oldHash && host.SlotHash("new") == targetHash,
              "Code-only scenario changed SQL or modified immutable release bytes");
    }

    private static void RunFailedAction(string root, string fault)
    {
        var host = new FakeHost(root);
        host.Seed();
        var paths = new MaintenancePaths(Path.Combine(root, "maintenance"));
        using var lease = MachineMutationLease.Acquire(paths, LocalCodeCutoverWriteAheadJournal.Owner);
        var wal = new LocalCodeCutoverWriteAheadJournal(paths);
        const string id = "dddddddddddddddddddddddddddddddd";
        wal.Begin(lease, id, StageId, host.OldSlot, host.NewSlot, InstalledHash);
        wal.Advance(lease, id, "apache_stop_intent", host.Evidence());
        if (fault != "apache-stop")
        {
            host.StopApache();
            wal.Advance(lease, id, "apache_stop_observed", host.Evidence());
            wal.Advance(lease, id, "pointer_switch_intent", host.Evidence());
        }
        if (fault is "apache-start" or "target-health")
        {
            host.SwitchPointer("new");
            wal.Advance(lease, id, "pointer_switch_observed", host.Evidence());
        }
        if (fault == "target-health") host.StartApache();
        // Simulate a thrown action or a failed health check. Never write
        // the corresponding OBSERVED event without independent evidence.
        var phase = new LocalCodeCutoverWriteAheadJournal(paths).ReadVerified(id).Phase;
        Check(phase == (fault switch
        {
            "apache-stop" => "apache_stop_intent",
            "pointer-swap" => "pointer_switch_intent",
            _ => "pointer_switch_observed"
        }), "Failed action advanced the journal as if successful");
        var crashed = new LocalCodeCutoverWriteAheadJournal(paths).Classify(id,
            host.Pointer() == "old" ? host.OldSlot : host.NewSlot);
        Check(!crashed.AutomaticRollbackAllowed && !crashed.ActivationAllowed,
            "Failed action produced unauthorized automatic recovery");
        wal.Advance(lease, id, "pre_db_rollback_intent", host.Evidence());
        host.StopApache();
        host.SwitchPointer("old");
        wal.Advance(lease, id, "previous_pointer_observed", host.Evidence());
        host.StartApache();
        wal.Advance(lease, id, "previous_http_observed", host.Evidence());
        Check(host.Schema() == 1 && host.Pointer() == "old" && host.IsApacheRunning,
            "Failed action could not be explicitly compensated in fixture");
    }

    private static void Check(bool value, string message)
    {
        if (!value) throw new InvalidDataException("S7D2_SIMULATION_FAILED: " + message);
    }

    private sealed class FakeHost
    {
        private readonly string _root;
        public string OldSlot => Path.Combine(_root, "package", "Web.release.old");
        public string NewSlot => Path.Combine(_root, "package", "Web.release.new");
        private string PointerPath => Path.Combine(_root, "active-slot.pointer");
        private string ApachePath => Path.Combine(_root, "fake-apache.running");
        private string SchemaPath => Path.Combine(_root, "fake-schema.version");
        public bool IsApacheRunning => File.ReadAllText(ApachePath) == "running";
        public FakeHost(string root) => _root = root;

        public void Seed()
        {
            Directory.CreateDirectory(OldSlot);
            Directory.CreateDirectory(NewSlot);
            File.WriteAllText(Path.Combine(OldSlot, "VERSION.txt"), "old");
            File.WriteAllText(Path.Combine(NewSlot, "VERSION.txt"), "new");
            AtomicWrite(PointerPath, "old");
            AtomicWrite(ApachePath, "running");
            AtomicWrite(SchemaPath, "1");
        }
        public void StopApache() => AtomicWrite(ApachePath, "stopped");
        public void StartApache() => AtomicWrite(ApachePath, "running");
        public void SwitchPointer(string slot)
        {
            if (slot is not ("old" or "new")) throw new InvalidDataException("Bad fake pointer");
            AtomicWrite(PointerPath, slot);
        }
        public void SetSchema(int schema) => AtomicWrite(SchemaPath, schema.ToString());
        public string Pointer() => File.ReadAllText(PointerPath);
        public int Schema() => int.Parse(File.ReadAllText(SchemaPath));
        public string SlotHash(string slot) => Hash(File.ReadAllBytes(Path.Combine(
            slot == "old" ? OldSlot : NewSlot, "VERSION.txt")));
        public string Evidence() => Hash(Encoding.UTF8.GetBytes(
            $"{Pointer()}|{File.ReadAllText(ApachePath)}|{Schema()}"));

        private static string Hash(byte[] bytes) => Convert.ToHexString(
            SHA256.HashData(bytes)).ToLowerInvariant();

        private static void AtomicWrite(string path, string value)
        {
            var temporary = path + ".writing";
            File.WriteAllText(temporary, value);
            File.Move(temporary, path, overwrite: true);
        }
    }
}
