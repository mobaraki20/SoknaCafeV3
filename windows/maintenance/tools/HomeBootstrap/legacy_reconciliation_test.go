package main

import (
	"bytes"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

type legacyFixture struct{ root, operation, phase, rollback, migration string }

func makeLegacyFixture(t *testing.T) legacyFixture {
	t.Helper()
	pd := t.TempDir()
	t.Setenv("ProgramData", pd)
	site := filepath.Join(t.TempDir(), "SOKNA خانه with space")
	history := filepath.Join(maintenanceRoot(), "local-slots", "history")
	ops := filepath.Join(maintenanceRoot(), "operations")
	os.MkdirAll(history, 0700)
	os.MkdirAll(ops, 0700)
	f := legacyFixture{site, filepath.Join(ops, "op-144ce8bb82c641caa48ee0b898e5b22a.json"), filepath.Join(history, "phase3-state-20261017T031307337Z.json"), filepath.Join(history, "migration-state-r8-superseded-20261017T070438010Z.json"), filepath.Join(maintenanceRoot(), "local-slots", "migration-state.json")}
	var transform func(any) any
	transform = func(v any) any {
		switch x := v.(type) {
		case map[string]any:
			for k, a := range x {
				x[k] = transform(a)
			}
			return x
		case []any:
			for i, a := range x {
				x[i] = transform(a)
			}
			return x
		case string:
			if strings.HasPrefix(strings.TrimSpace(x), "{") {
				var m map[string]any
				if json.Unmarshal([]byte(x), &m) == nil {
					raw, _ := json.Marshal(transform(m))
					return string(raw)
				}
			}
			normalized := strings.ReplaceAll(x, `\`, "/")
			for strings.Contains(normalized, "//") {
				normalized = strings.ReplaceAll(normalized, "//", "/")
			}
			if strings.HasPrefix(normalized, "D:/SOKNA") {
				return site + filepath.FromSlash(strings.TrimPrefix(normalized, "D:/SOKNA"))
			}
			if strings.HasPrefix(normalized, "C:/ProgramData") {
				return pd + filepath.FromSlash(strings.TrimPrefix(normalized, "C:/ProgramData"))
			}
			return x
		}
		return v
	}
	for name, dest := range map[string]string{"legacy-operation.json": f.operation, "legacy-phase3.json": f.phase, "legacy-rollback.json": f.rollback, "legacy-committed.json": f.migration} {
		raw, e := os.ReadFile(filepath.Join("testdata", name))
		if e != nil {
			t.Fatal(e)
		}
		var m map[string]any
		if e = json.Unmarshal(raw, &m); e != nil {
			t.Fatal(e)
		}
		b, _ := json.MarshalIndent(transform(m), "", "  ")
		if e = os.WriteFile(dest, b, 0600); e != nil {
			t.Fatal(e)
		}
	}
	return f
}
func editLegacy(t *testing.T, path string, edit func(map[string]any)) {
	t.Helper()
	raw, e := os.ReadFile(path)
	if e != nil {
		t.Fatal(e)
	}
	var m map[string]any
	if e = json.Unmarshal(raw, &m); e != nil {
		t.Fatal(e)
	}
	edit(m)
	raw, _ = json.Marshal(m)
	if e = os.WriteFile(path, raw, 0600); e != nil {
		t.Fatal(e)
	}
}
func TestLegacyActualUploadedChainReadOnly(t *testing.T) {
	f := makeLegacyFixture(t)
	before, _ := os.ReadFile(f.operation)
	plan, e := assessPendingSignedV4Updates(f.root)
	if e != nil || plan == nil {
		t.Fatalf("actual linked chain rejected: %v", e)
	}
	if len(plan.sources) != 4 {
		t.Fatal("incomplete proof")
	}
	after, _ := os.ReadFile(f.operation)
	if !bytes.Equal(before, after) {
		t.Fatal("assessment mutated operation")
	}
	if _, e = os.Stat(filepath.Join(maintenanceRoot(), "installed-release-identity.json")); !os.IsNotExist(e) {
		t.Fatal("assessment fabricated identity")
	}
}
func TestLegacyRejectIncompleteOrConflictingEvidence(t *testing.T) {
	cases := map[string]func(*testing.T, legacyFixture){
		"db_mutation": func(t *testing.T, f legacyFixture) {
			editLegacy(t, f.operation, func(m map[string]any) { m["database_mutation_performed"] = true })
		},
		"missing_db_flag": func(t *testing.T, f legacyFixture) {
			editLegacy(t, f.phase, func(m map[string]any) { delete(m, "database_mutation_performed") })
		},
		"public_mutation": func(t *testing.T, f legacyFixture) {
			editLegacy(t, f.migration, func(m map[string]any) { m["public_mutation_performed"] = true })
		},
		"foreign_root": func(t *testing.T, f legacyFixture) {
			editLegacy(t, f.migration, func(m map[string]any) { m["package_root"] = f.root + "-other" })
		},
		"wrong_tree": func(t *testing.T, f legacyFixture) {
			editLegacy(t, f.migration, func(m map[string]any) { m["expected_managed_tree_sha256"] = strings.Repeat("a", 64) })
		},
		"wrong_request_hash": func(t *testing.T, f legacyFixture) {
			editLegacy(t, f.operation, func(m map[string]any) { m["canonical_request_sha256"] = strings.Repeat("b", 64) })
		},
		"missing_archive_link": func(t *testing.T, f legacyFixture) { os.Remove(f.rollback) },
		"foreign_archive_link": func(t *testing.T, f legacyFixture) {
			editLegacy(t, f.rollback, func(m map[string]any) {
				for _, v := range m["events"].([]any) {
					e := v.(map[string]any)
					if e["state"] == "LEGACY_PHASE3_STATE_ARCHIVED" {
						e["detail"] = f.phase + "-other"
					}
				}
			})
		},
		"missing_commit_health": func(t *testing.T, f legacyFixture) {
			editLegacy(t, f.migration, func(m map[string]any) {
				for _, v := range m["events"].([]any) {
					e := v.(map[string]any)
					if e["state"] == "HTTP_HEALTHY" {
						e["state"] = "HTTP_FAILED"
					}
				}
			})
		},
		"missing_commit": func(t *testing.T, f legacyFixture) {
			editLegacy(t, f.migration, func(m map[string]any) { m["phase"] = "RECOVERY_REQUIRED" })
		},
		"active_phase3": func(t *testing.T, f legacyFixture) {
			raw, _ := os.ReadFile(f.phase)
			os.WriteFile(filepath.Join(maintenanceRoot(), "local-slots", "phase3-state.json"), raw, 0600)
		},
		"duplicate_phase3": func(t *testing.T, f legacyFixture) {
			raw, _ := os.ReadFile(f.phase)
			os.WriteFile(filepath.Join(filepath.Dir(f.phase), "phase3-state-duplicate.json"), raw, 0600)
		},
		"another_active_operation": func(t *testing.T, f legacyFixture) {
			raw, _ := os.ReadFile(f.operation)
			path := filepath.Join(filepath.Dir(f.operation), "op-"+strings.Repeat("f", 32)+".json")
			os.WriteFile(path, raw, 0600)
			editLegacy(t, path, func(m map[string]any) { m["operation_id"] = strings.Repeat("f", 32); m["state"] = "running" })
		},
		"second_recovery": func(t *testing.T, f legacyFixture) {
			raw, _ := os.ReadFile(f.operation)
			path := filepath.Join(filepath.Dir(f.operation), "op-"+strings.Repeat("f", 32)+".json")
			os.WriteFile(path, raw, 0600)
			editLegacy(t, path, func(m map[string]any) { m["operation_id"] = strings.Repeat("f", 32) })
		},
		"staged_package": func(t *testing.T, f legacyFixture) {
			os.MkdirAll(filepath.Join(maintenanceRoot(), "staging", "release-sets", "other"), 0700)
		},
		"signed_sequence": func(t *testing.T, f legacyFixture) {
			dir := filepath.Join(maintenanceRoot(), "update")
			os.MkdirAll(dir, 0700)
			os.WriteFile(filepath.Join(dir, "release-sequence.json"), []byte("{}"), 0600)
		},
		"corrupt_json": func(t *testing.T, f legacyFixture) { os.WriteFile(f.migration, []byte("{"), 0600) },
		"invalid_event_order": func(t *testing.T, f legacyFixture) {
			editLegacy(t, f.migration, func(m map[string]any) { m["events"].([]any)[0].(map[string]any)["at_utc"] = "2099-01-01T00:00:00Z" })
		},
	}
	for name, change := range cases {
		t.Run(name, func(t *testing.T) {
			f := makeLegacyFixture(t)
			change(t, f)
			before, _ := os.ReadFile(f.operation)
			if _, e := assessPendingSignedV4Updates(f.root); e == nil {
				t.Fatal("unsafe evidence admitted")
			}
			after, _ := os.ReadFile(f.operation)
			if !bytes.Equal(before, after) {
				t.Fatal("refusal changed operation")
			}
		})
	}
}
func TestLegacyTransitionPreservesFailureAndPeerFields(t *testing.T) {
	f := makeLegacyFixture(t)
	editLegacy(t, f.operation, func(m map[string]any) { m["future_extension"] = map[string]any{"keep": "yes"} })
	original, _ := os.ReadFile(f.operation)
	p, e := assessPendingSignedV4Updates(f.root)
	if e != nil {
		t.Fatal(e)
	}
	if e = commitLegacyReconciliation(p, t.TempDir(), "test", baselineTree); e != nil {
		t.Fatal(e)
	}
	_, after, e := regularProof(f.operation)
	if e != nil {
		t.Fatal(e)
	}
	if after["state"] != "superseded_verified" || after["activation_performed"] != true || after["database_mutation_performed"] != false || after["future_extension"].(map[string]any)["keep"] != "yes" {
		t.Fatal("transition changed evidence")
	}
	backup, e := os.ReadFile(filepath.Join(filepath.Dir(f.operation), "reconciliation-home-144ce8bb82c641caa48ee0b898e5b22a.operation-before.json"))
	if e != nil || !bytes.Equal(backup, original) {
		t.Fatal("original bytes not retained")
	}
	if p, e = assessPendingSignedV4Updates(f.root); e != nil || p != nil {
		t.Fatalf("settled ledger not idle: %v", e)
	}
}
func TestLegacyCrashBeforeTransitionCanResume(t *testing.T) {
	f := makeLegacyFixture(t)
	p, e := assessPendingSignedV4Updates(f.root)
	if e != nil {
		t.Fatal(e)
	}
	original, _ := os.ReadFile(f.operation)
	if e = commitLegacyReconciliation(p, t.TempDir(), "first", baselineTree); e != nil {
		t.Fatal(e)
	}
	os.WriteFile(f.operation, original, 0600)
	if e = commitLegacyReconciliation(p, t.TempDir(), "retry", baselineTree); e != nil {
		t.Fatal(e)
	}
}
func TestLegacyChangedOperationRefused(t *testing.T) {
	f := makeLegacyFixture(t)
	p, e := assessPendingSignedV4Updates(f.root)
	if e != nil {
		t.Fatal(e)
	}
	editLegacy(t, f.operation, func(m map[string]any) { m["state"] = "running" })
	if e = commitLegacyReconciliation(p, t.TempDir(), "test", baselineTree); e == nil {
		t.Fatal("changed operation overwritten")
	}
}
func TestLegacyCurrentHostEvidenceMandatory(t *testing.T) {
	f := makeLegacyFixture(t)
	p, e := assessPendingSignedV4Updates(f.root)
	if e != nil {
		t.Fatal(e)
	}
	b := &bridge{legacyPlan: p}
	if e = b.reconcileVerifiedLegacy(); e == nil {
		t.Fatal("historical receipt used without fresh physical proof")
	}
}
func TestObservedMaintenanceVersionsRequired(t *testing.T) {
	good := "SOKNA Maintenance Update Engine 0.3.13 (DB mutation disabled)\nlocal-slot-inspect --expected-build <id> --expected-managed-files <n> --expected-managed-tree-sha256 <sha256>\n"
	if v, e := validateEngineHelp(good); e != nil || v != "0.3.13" {
		t.Fatalf("current engine rejected: %v", e)
	}
	for _, v := range []string{"0.3.12", "0.3.1", "0.3.99"} {
		if _, e := validateEngineHelp(strings.Replace(good, "0.3.13", v, 1)); e == nil {
			t.Fatal("unqualified engine admitted")
		}
	}
}
