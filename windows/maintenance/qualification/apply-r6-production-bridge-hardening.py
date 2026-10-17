from pathlib import Path

p = Path('windows/maintenance/src/Sokna.Maintenance.Core/ProductionLocalSlotMigration.cs')
text = p.read_text(encoding='utf-8')

old = '''    private readonly TimeSpan _serviceTimeout;\n    private readonly TimeSpan _healthTimeout;\n\n    public ProductionLocalSlotMigrationBridge(MaintenancePaths paths, TimeSpan? serviceTimeout = null, TimeSpan? healthTimeout = null)\n    {\n        _paths = paths;\n        _discovery = new LocalSlotManager(paths, serviceTimeout, healthTimeout);\n        _serviceTimeout = serviceTimeout ?? TimeSpan.FromSeconds(35);\n        _healthTimeout = healthTimeout ?? TimeSpan.FromSeconds(35);\n    }'''
new = '''    private readonly TimeSpan _serviceTimeout;\n    private readonly TimeSpan _healthTimeout;\n    private readonly Action<string>? _checkpoint;\n\n    public ProductionLocalSlotMigrationBridge(\n        MaintenancePaths paths,\n        TimeSpan? serviceTimeout = null,\n        TimeSpan? healthTimeout = null,\n        Action<string>? checkpoint = null)\n    {\n        _paths = paths;\n        _discovery = new LocalSlotManager(paths, serviceTimeout, healthTimeout);\n        _serviceTimeout = serviceTimeout ?? TimeSpan.FromSeconds(35);\n        _healthTimeout = healthTimeout ?? TimeSpan.FromSeconds(35);\n        _checkpoint = checkpoint;\n    }'''
if old not in text: raise SystemExit('constructor pattern missing')
text = text.replace(old, new, 1)

old = '''        var targetSlot = Path.Combine(releaseRoot, BuildProductionSlotName(expectedBuild, expectedManagedTreeSha256));\n        var events = new List<ProductionLocalSlotMigrationEvent>();\n        var state = NewState(layout, expectedBuild, expectedManagedFiles, expectedManagedTreeSha256, releaseRoot, targetSlot, events);'''
new = '''        var targetSlot = Path.Combine(releaseRoot, BuildProductionSlotName(expectedBuild, expectedManagedTreeSha256));\n        var priorPhysicalBackup = (string?)null;\n        var priorPreviousSlot = (string?)null;\n        try\n        {\n            if (File.Exists(StatePath))\n            {\n                var prior = AtomicFile.ReadJson<ProductionLocalSlotMigrationState>(StatePath);\n                if (string.Equals(prior.ExpectedBuild, expectedBuild, StringComparison.Ordinal) &&\n                    prior.ExpectedManagedFiles == expectedManagedFiles &&\n                    string.Equals(prior.ExpectedManagedTreeSha256, expectedManagedTreeSha256, StringComparison.OrdinalIgnoreCase))\n                {\n                    priorPhysicalBackup = prior.PhysicalWebBackup;\n                    priorPreviousSlot = prior.PreviousSlot;\n                }\n            }\n        }\n        catch\n        {\n            // A malformed prior production state must not be silently trusted. The new\n            // migration attempt will establish fresh state from the verified baseline.\n        }\n\n        var events = new List<ProductionLocalSlotMigrationEvent>();\n        var state = NewState(layout, expectedBuild, expectedManagedFiles, expectedManagedTreeSha256, releaseRoot, targetSlot, priorPhysicalBackup, priorPreviousSlot, events);'''
if old not in text: raise SystemExit('state creation pattern missing')
text = text.replace(old, new, 1)

# Preserve previous backup reference on idempotent/already-migrated reconciliation.
text = text.replace('''                PreviousSlot = activeSlot,\n                ActivationPerformed = false,''', '''                PreviousSlot = state.PreviousSlot ?? activeSlot,\n                ActivationPerformed = false,''', 1)

# Add checkpoints only as injectable test seam; production construction passes null.
for old, new in [
    ('state = Event(state, events, "BASELINE_HTTP_HEALTHY", layout.HealthUrl);', 'state = Event(state, events, "BASELINE_HTTP_HEALTHY", layout.HealthUrl);\n        Checkpoint("after_baseline_health");'),
    ('state = Event(state, events, "SLOT_STAGED_VERIFIED", targetSlot);', 'state = Event(state, events, "SLOT_STAGED_VERIFIED", targetSlot);\n        Checkpoint("after_slot_staged");'),
    ('state = Event(state, events, "APACHE_STOPPED", "production migration");', 'state = Event(state, events, "APACHE_STOPPED", "production migration");\n        Checkpoint("after_apache_stop");'),
    ('state = Event(state, events, "PHYSICAL_WEB_BACKED_UP", physicalBackup);', 'state = Event(state, events, "PHYSICAL_WEB_BACKED_UP", physicalBackup);\n            Checkpoint("after_physical_move");'),
    ('state = Event(state, events, "POINTER_SWITCHED", targetSlot);', 'state = Event(state, events, "POINTER_SWITCHED", targetSlot);\n            Checkpoint("after_pointer_switch");'),
    ('state = Event(state, events, "APACHE_STARTED", targetSlot);', 'state = Event(state, events, "APACHE_STARTED", targetSlot);\n            Checkpoint("after_apache_start");'),
]:
    if old not in text: raise SystemExit(f'checkpoint anchor missing: {old}')
    text = text.replace(old, new, 1)

old = '''        string releaseRoot,\n        string targetSlot,\n        List<ProductionLocalSlotMigrationEvent> events)'''
new = '''        string releaseRoot,\n        string targetSlot,\n        string? priorPhysicalBackup,\n        string? priorPreviousSlot,\n        List<ProductionLocalSlotMigrationEvent> events)'''
if old not in text: raise SystemExit('NewState signature pattern missing')
text = text.replace(old, new, 1)
# In state constructor replace first null backup and null previous after target slot.
old = '''            releaseRoot,\n            targetSlot,\n            null,\n            layout.ActiveSlotTarget,\n            null,'''
new = '''            releaseRoot,\n            targetSlot,\n            priorPhysicalBackup,\n            layout.ActiveSlotTarget,\n            priorPreviousSlot,'''
if old not in text: raise SystemExit('NewState constructor values pattern missing')
text = text.replace(old, new, 1)

anchor = '''    private static void ValidateManagedCount(int count)\n    {'''
insert = '''    private void Checkpoint(string name)\n    {\n        _checkpoint?.Invoke(name);\n    }\n\n'''
if anchor not in text: raise SystemExit('checkpoint method anchor missing')
text = text.replace(anchor, insert + anchor, 1)

p.write_text(text, encoding='utf-8')
print('R6 production bridge hardening patch applied')
