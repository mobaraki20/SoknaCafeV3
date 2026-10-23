package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// Cafe 2026-10-22: missing installed identity MUST be recognized before DB
// cutover and permitted for an exact, operator-confirmed first bind later.
func TestHomeMaintenanceIdentityPreflight(t *testing.T) {
	root := t.TempDir()
	t.Setenv("ProgramData", root)
	path := filepath.Join(root, "SOKNA", "Maintenance", "installed-release-identity.json")
	b := &bridge{evidenceDir: t.TempDir(), ev: evidence{AttemptID: "test-home-identity"}}
	if err := b.preflightInstalledMaintenanceIdentity(); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(path); !os.IsNotExist(err) {
		t.Fatal("unexpected identity mutation during preflight")
	}
	if err := os.MkdirAll(filepath.Dir(path), 0700); err != nil {
		t.Fatal(err)
	}
	mk := func(version, tree string) []byte {
		raw, _ := json.Marshal(map[string]any{"local": map[string]any{"release_id": "home-test", "version": version, "managed_tree_sha256": tree, "schema_fingerprint": "migration-ledger-sha256-v1:" + strings.Repeat("a", 64), "migration_head": "0069_runtime_pending_receipt_index"}, "public": nil})
		return raw
	}
	for _, x := range []struct {
		version, tree string
		okay          bool
	}{
		{baselineVersion, baselineTree, true},
		{"3.0.0-g6.2-workspace.231at-runtime-timebase-receipt-index-home-uat", strings.Repeat("1", 64), true},
		{"3.0.0-g6.2-workspace.231au-fake", baselineTree, false},
		{"3.0.0-g6.2-workspace.231zz-fake", baselineTree, false},
	} {
		if err := os.WriteFile(path, mk(x.version, x.tree), 0600); err != nil {
			t.Fatal(err)
		}
		ok := b.preflightInstalledMaintenanceIdentity() == nil
		if ok != x.okay {
			t.Fatalf("unexpected identity state for %s", x.version)
		}
	}
}
func TestCafeRegressionGuardWiring(t *testing.T) {
	content, err := os.ReadFile("main.go")
	if err != nil {
		t.Fatal(err)
	}
	s := string(content)
	early := strings.Index(s, "b.backupMariaDB()")
	stop := strings.Index(s, "b.stopApache()")
	if early <= 0 || stop <= 0 || early >= stop {
		t.Fatal("MariaDB backup is no longer verified before Apache downtime")
	}
	if !strings.Contains(s, "b.verifyBaselineBackupBeforeBoundary()") || !strings.Contains(s, "b.preflightInstalledMaintenanceIdentity()") {
		t.Fatal("preflight trust guards missing")
	}
	ph, err := os.ReadFile("db_migration.go")
	if err != nil {
		t.Fatal(err)
	}
	x := string(ph)
	if !strings.Contains(x, "/src/Core/MigrationExecutionException.php") || !strings.Contains(x, "count($new)!==4") {
		t.Fatal("repaired three-migration forward PHP missing")
	}
	by, err := os.ReadFile("identity_attestation.go")
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(by), "os.O_EXCL") || !strings.Contains(string(by), "MAINTENANCE_IDENTITY_CHANGED_FROM_PREFLIGHT") {
		t.Fatal("missing trusted first-bind / one-owner guard")
	}
}
