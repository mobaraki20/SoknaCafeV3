package main

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

// The one-time converter holds Supervisor's machine writer lease. It may
// reconcile only linked historical NON-DB Phase3 evidence, after independently
// re-proving the sealed CURRENT AU tree/junction/binding/71 SQL/HTTP. No host
// name, UUID exception, date cutoff, fake migration or false committed outcome.
type legacyReconciliationPlan struct {
	operationPath string
	operation     map[string]any
	sources       map[string][]byte
}

func maintenanceRoot() string {
	pd := os.Getenv("ProgramData")
	if pd == "" {
		pd = `C:\ProgramData`
	}
	return filepath.Join(pd, "SOKNA", "Maintenance")
}
func proofPath(p string) string {
	return strings.ToLower(filepath.Clean(strings.ReplaceAll(p, `\`, string(filepath.Separator))))
}
func proofEqualPath(a, b string) bool              { return a != "" && b != "" && proofPath(a) == proofPath(b) }
func jsonString(m map[string]any, k string) string { s, _ := m[k].(string); return s }
func explicitBool(m map[string]any, k string, want bool) bool {
	b, ok := m[k].(bool)
	return ok && b == want
}
func proofTime(m map[string]any, k string) (time.Time, error) {
	return time.Parse(time.RFC3339Nano, jsonString(m, k))
}
func proofSHA(b []byte) string { s := sha256.Sum256(b); return hex.EncodeToString(s[:]) }
func regularProof(path string) ([]byte, map[string]any, error) {
	for p := path; ; p = filepath.Dir(p) {
		s, e := os.Lstat(p)
		if e != nil {
			return nil, nil, e
		}
		if isReparse(p) || s.Mode()&os.ModeSymlink != 0 {
			return nil, nil, errors.New("LEGACY_PROOF_REDIRECTED")
		}
		if p == filepath.Dir(p) {
			break
		}
	}
	s, e := os.Stat(path)
	if e != nil {
		return nil, nil, e
	}
	if !s.Mode().IsRegular() || s.Size() > 2*1024*1024 {
		return nil, nil, errors.New("LEGACY_PROOF_FILE_INVALID")
	}
	raw, e := os.ReadFile(path)
	if e != nil {
		return nil, nil, e
	}
	var m map[string]any
	if e = json.Unmarshal(raw, &m); e != nil || m == nil {
		return nil, nil, errors.New("LEGACY_PROOF_JSON_INVALID")
	}
	return raw, m, nil
}
func proofEvents(m map[string]any, required []string) ([]map[string]any, error) {
	list, ok := m["events"].([]any)
	if !ok || len(list) == 0 || len(list) > 200 {
		return nil, errors.New("LEGACY_EVENTS_INVALID")
	}
	updated, e := proofTime(m, "updated_at_utc")
	if e != nil {
		return nil, e
	}
	out := []map[string]any{}
	last := time.Time{}
	at := 0
	for _, v := range list {
		event, ok := v.(map[string]any)
		if !ok {
			return nil, errors.New("LEGACY_EVENT_INVALID")
		}
		t, e := proofTime(event, "at_utc")
		if e != nil || t.Before(last) || t.After(updated) {
			return nil, errors.New("LEGACY_EVENT_ORDER_INVALID")
		}
		last = t
		out = append(out, event)
		if at < len(required) && event["state"] == required[at] {
			at++
		}
	}
	if at != len(required) {
		return nil, errors.New("LEGACY_REQUIRED_EVENTS_MISSING")
	}
	return out, nil
}
func migrationMatches(m, phase map[string]any, root, tree string, count float64, state string) bool {
	return m["format"] == "sokna-local-slot-migration-state-v1" && m["schema_version"] == float64(1) && m["phase"] == state && m["expected_build"] == phase["expected_build"] && m["expected_managed_tree_sha256"] == tree && m["expected_managed_files"] == count && proofEqualPath(jsonString(m, "package_root"), root) && proofEqualPath(jsonString(m, "stable_web_root"), filepath.Join(root, "Web")) && explicitBool(m, "recovery_required", false) && explicitBool(m, "database_mutation_performed", false) && explicitBool(m, "public_mutation_performed", false) && explicitBool(m, "windows_reboot_required", false) && explicitBool(m, "activation_performed", true)
}
func assessPendingSignedV4Updates(packageRoot string) (*legacyReconciliationPlan, error) {
	root := maintenanceRoot()
	if list, e := os.ReadDir(filepath.Join(root, "staging", "release-sets")); e == nil {
		if len(list) != 0 {
			return nil, errors.New("V4_STAGED_RELEASE_PRESENT")
		}
	} else if !os.IsNotExist(e) {
		return nil, e
	}
	if _, e := os.Lstat(filepath.Join(root, "update", "release-sequence.json")); e == nil {
		return nil, errors.New("V4_SEQUENCE_PRESENT")
	} else if !os.IsNotExist(e) {
		return nil, e
	}
	list, e := os.ReadDir(filepath.Join(root, "operations"))
	if os.IsNotExist(e) {
		return nil, nil
	}
	if e != nil {
		return nil, e
	}
	if len(list) > 20000 {
		return nil, errors.New("V4_LEDGER_LIMIT")
	}
	var plan *legacyReconciliationPlan
	for _, file := range list {
		n := strings.ToLower(file.Name())
		if file.IsDir() || !strings.HasPrefix(n, "op-") || !strings.HasSuffix(n, ".json") {
			continue
		}
		path := filepath.Join(root, "operations", file.Name())
		raw, op, e := regularProof(path)
		if e != nil {
			return nil, e
		}
		id := jsonString(op, "operation_id")
		typ := jsonString(op, "operation_type")
		state := jsonString(op, "state")
		if !regexp.MustCompile(`^[a-f0-9]{32}$`).MatchString(id) || n != "op-"+id+".json" || typ == "" || state == "" || op["format"] != "sokna-maintenance-operation-v1" || op["schema_version"] != float64(1) {
			return nil, errors.New("V4_OPERATION_LEDGER_CORRUPT")
		}
		switch state {
		case "accepted", "running", "activating", "recovering", "staged":
			return nil, fmt.Errorf("V4_OPERATION_NOT_IDLE: %s (%s)", id, state)
		case "recovery_required":
			if plan != nil {
				return nil, errors.New("V4_MULTIPLE_RECOVERY_BLOCKERS")
			}
			if typ != "update.local-slot-home-cycle" || !explicitBool(op, "database_mutation_performed", false) || !explicitBool(op, "activation_performed", true) || op["result_code"] != "LOCAL_SLOT_RECOVERY_REQUIRED" {
				return nil, errors.New("V4_UNSUPPORTED_RECOVERY_BLOCKER")
			}
			p, e := proveLegacySupersession(path, raw, op, packageRoot)
			if e != nil {
				return nil, e
			}
			plan = p
		}
	}
	return plan, nil
}
func proveLegacySupersession(opPath string, raw []byte, op map[string]any, packageRoot string) (*legacyReconciliationPlan, error) {
	fail := func() (*legacyReconciliationPlan, error) { return nil, errors.New("LEGACY_SUPERSESSION_UNPROVEN") }
	parts := strings.Split(jsonString(op, "package_ref"), ":")
	if len(parts) != 4 || parts[0] != "phase3-local-slots" || !regexp.MustCompile(`^[a-f0-9]{64}$`).MatchString(parts[3]) {
		return fail()
	}
	var count float64
	if e := json.Unmarshal([]byte(parts[2]), &count); e != nil || count < 1 || count > 2000 {
		return fail()
	}
	canonical := strings.ToLower(strings.TrimSpace(jsonString(op, "operation_type"))) + "\n" + strings.TrimSpace(jsonString(op, "package_ref")) + "\n" + strings.TrimSpace(jsonString(op, "operator_id"))
	if proofSHA([]byte(canonical)) != jsonString(op, "canonical_request_sha256") {
		return fail()
	}
	var result map[string]any
	if json.Unmarshal([]byte(jsonString(op, "result_message")), &result) != nil || result["engine_version"] != "0.3.1" || result["code"] != "LOCAL_SLOT_PHASE3_FAILED" || result["phase"] != "FAILED" || !explicitBool(result, "activation_performed", true) || !explicitBool(result, "database_mutation_performed", false) || !explicitBool(result, "public_mutation_performed", false) || result["expected_managed_tree_sha256"] != parts[3] {
		return fail()
	}
	slotRoot := filepath.Join(maintenanceRoot(), "local-slots")
	if !proofEqualPath(jsonString(op, "stage_receipt_path"), filepath.Join(slotRoot, "phase3-state.json")) || !proofEqualPath(jsonString(result, "state_path"), filepath.Join(slotRoot, "phase3-state.json")) {
		return fail()
	}
	if _, e := os.Lstat(filepath.Join(slotRoot, "phase3-state.json")); !os.IsNotExist(e) {
		return nil, errors.New("LEGACY_PHASE3_STILL_ACTIVE_OR_UNREADABLE")
	}
	history := filepath.Join(slotRoot, "history")
	list, e := os.ReadDir(history)
	if e != nil {
		return nil, e
	}
	if len(list) > 2000 {
		return fail()
	}
	created, e := proofTime(op, "created_at_utc")
	if e != nil {
		return fail()
	}
	updated, e := proofTime(op, "updated_at_utc")
	if e != nil || !updated.After(created) {
		return fail()
	}
	var phase map[string]any
	var phasePath string
	var phaseRaw []byte
	for _, f := range list {
		if f.IsDir() || !strings.HasPrefix(f.Name(), "phase3-state-") || !strings.HasSuffix(f.Name(), ".json") {
			continue
		}
		path := filepath.Join(history, f.Name())
		b, m, e := regularProof(path)
		if e != nil {
			return nil, e
		}
		t, e := proofTime(m, "updated_at_utc")
		if e != nil {
			return fail()
		}
		delta := updated.Sub(t)
		if m["expected_build"] != parts[1] || delta < 0 || delta > time.Second {
			continue
		}
		if phase != nil {
			return nil, errors.New("LEGACY_PHASE3_PROOF_AMBIGUOUS")
		}
		if m["format"] != "sokna-local-slot-phase3-state-v1" || m["schema_version"] != float64(1) || m["phase"] != "RECOVERY_REQUIRED" || !explicitBool(m, "recovery_required", true) || !explicitBool(m, "database_mutation_performed", false) || !explicitBool(m, "public_mutation_performed", false) || !explicitBool(m, "windows_reboot_required", false) || !proofEqualPath(jsonString(m, "package_root"), packageRoot) || !proofEqualPath(jsonString(m, "stable_web_root"), filepath.Join(packageRoot, "Web")) {
			return fail()
		}
		events, e := proofEvents(m, []string{"DISCOVERED", "BASELINE_VERIFIED", "SLOTS_STAGED", "SLOTS_VERIFIED", "APACHE_STOPPED", "POINTER_SWITCHED", "RECOVERY_REQUIRED"})
		if e != nil {
			return nil, e
		}
		first, _ := proofTime(events[0], "at_utc")
		if first.Before(created) {
			return fail()
		}
		phase = m
		phasePath = path
		phaseRaw = b
	}
	if phase == nil {
		return fail()
	}
	migrationPath := filepath.Join(slotRoot, "migration-state.json")
	migrationRaw, migration, e := regularProof(migrationPath)
	if e != nil {
		return nil, e
	}
	if !migrationMatches(migration, phase, packageRoot, parts[3], count, "COMMITTED") {
		return fail()
	}
	if _, e = proofEvents(migration, []string{"BASELINE_VERIFIED", "BASELINE_HTTP_HEALTHY", "SLOT_STAGED_VERIFIED", "READY_TO_ACTIVATE", "APACHE_STOPPED", "PHYSICAL_WEB_BACKED_UP", "POINTER_SWITCHED", "APACHE_STARTED", "HTTP_HEALTHY", "COMMITTED"}); e != nil {
		return nil, e
	}
	committed, e := proofTime(migration, "updated_at_utc")
	if e != nil || !committed.After(updated) {
		return fail()
	}
	release := jsonString(migration, "target_slot")
	if !proofEqualPath(release, jsonString(migration, "active_slot")) || !proofEqualPath(filepath.Dir(proofPath(release)), packageRoot) || !strings.HasPrefix(strings.ToLower(filepath.Base(proofPath(release))), "web.release.") || !strings.HasSuffix(release, parts[3][:12]) {
		return fail()
	}
	var linkPath string
	var linkRaw []byte
	for _, f := range list {
		if f.IsDir() || !strings.HasPrefix(f.Name(), "migration-state-") || !strings.HasSuffix(f.Name(), ".json") {
			continue
		}
		path := filepath.Join(history, f.Name())
		b, m, e := regularProof(path)
		if e != nil {
			return nil, e
		}
		if !migrationMatches(m, phase, packageRoot, parts[3], count, "ROLLED_BACK") {
			continue
		}
		events, e := proofEvents(m, []string{"BASELINE_VERIFIED", "BASELINE_HTTP_HEALTHY", "LEGACY_PHASE3_STATE_ARCHIVED", "SLOT_STAGED_VERIFIED", "POINTER_SWITCHED", "ROLLED_BACK"})
		if e != nil {
			continue
		}
		rollback, e := proofTime(m, "updated_at_utc")
		if e != nil || !rollback.After(updated) || !rollback.Before(committed) {
			continue
		}
		for _, event := range events {
			t, _ := proofTime(event, "at_utc")
			if event["state"] == "LEGACY_PHASE3_STATE_ARCHIVED" && t.After(updated) && proofEqualPath(jsonString(event, "detail"), phasePath) {
				if linkPath != "" {
					return nil, errors.New("LEGACY_ARCHIVE_LINK_AMBIGUOUS")
				}
				linkPath = path
				linkRaw = b
			}
		}
	}
	if linkPath == "" {
		return fail()
	}
	return &legacyReconciliationPlan{opPath, op, map[string][]byte{opPath: raw, phasePath: phaseRaw, linkPath: linkRaw, migrationPath: migrationRaw}}, nil
}

// Caller owns the machine writer lease and transaction. Re-read every proof
// and the live baseline immediately before a metadata-only reconciliation.
func (b *bridge) reconcileVerifiedLegacy() error {
	if b.legacyPlan == nil {
		return nil
	}
	if b.readOnly || b.transaction == nil || !b.baselineAttested || b.ev.MigrationsApplied != migrationCount || b.maintenanceSupervisorVersion != "0.3.13" || b.maintenanceEngineVersion != "0.3.13" {
		return errors.New("LEGACY_CURRENT_HOST_PROOF_REQUIRED")
	}
	if err := b.verifyOriginalSlot(b.backupPath); err != nil {
		return err
	}
	if err := httpHealth(baselineVersion); err != nil {
		return err
	}
	if n, err := b.phpMigrationPreflight(b.backupPath); err != nil || n != migrationCount {
		return errors.New("LEGACY_CURRENT_DB_PROOF_CHANGED")
	}
	if err := b.verifySignedFoundation(); err != nil {
		return err
	}
	fresh, err := assessPendingSignedV4Updates(b.packageRoot)
	if err != nil {
		return err
	}
	if fresh == nil || fresh.operationPath != b.legacyPlan.operationPath || len(fresh.sources) != len(b.legacyPlan.sources) {
		return errors.New("LEGACY_PROOF_CHANGED")
	}
	for path, raw := range b.legacyPlan.sources {
		if !bytes.Equal(raw, fresh.sources[path]) {
			return errors.New("LEGACY_PROOF_CHANGED")
		}
	}
	return commitLegacyReconciliation(fresh, b.evidenceDir, b.ev.AttemptID)
}

// Evidence first; original attempt becomes superseded_verified, never success.
// Crash before transition keeps the blocker; retry preserves original bytes.
func commitLegacyReconciliation(plan *legacyReconciliationPlan, evidenceDir, attemptID string) error {
	id := jsonString(plan.operation, "operation_id")
	root := filepath.Dir(plan.operationPath)
	proof := map[string]string{}
	for path, raw := range plan.sources {
		proof[path] = proofSHA(raw)
	}
	receipt := map[string]any{"format": "sokna-home-legacy-reconciliation-v1", "schema_version": 1, "old_operation_id": id, "original_operation": plan.operation, "source_sha256": proof, "observed_version": baselineVersion, "observed_managed_files": baselineFiles, "observed_tree_sha256": baselineTree, "observed_migrations": migrationCount, "attempt_id": attemptID, "evidence_dir": evidenceDir, "evidence": "linked_archived_phase3_and_committed_r8_plus_fresh_exact_au_ntfs_http_binding_db71_signed_foundation", "database_mutation_performed": false}
	path := filepath.Join(root, "reconciliation-home-"+id+".json")
	if isReparse(path) {
		return errors.New("LEGACY_RECEIPT_REDIRECTED")
	}
	raw, err := json.MarshalIndent(receipt, "", "  ")
	if err != nil {
		return err
	}
	if previous, e := os.ReadFile(path); e == nil {
		var old map[string]any
		if json.Unmarshal(previous, &old) != nil || old["format"] != receipt["format"] || old["old_operation_id"] != id {
			return errors.New("LEGACY_RECONCILIATION_RECEIPT_CONFLICT")
		}
		want, _ := json.Marshal(receipt["source_sha256"])
		got, _ := json.Marshal(old["source_sha256"])
		original, _ := json.Marshal(old["original_operation"])
		now, _ := json.Marshal(plan.operation)
		if !bytes.Equal(want, got) || !bytes.Equal(original, now) {
			return errors.New("LEGACY_RECONCILIATION_RECEIPT_CONFLICT")
		}
	} else if !os.IsNotExist(e) {
		return e
	} else if err = writeExclusiveProof(path, raw); err != nil {
		return err
	}
	originalPath := filepath.Join(root, "reconciliation-home-"+id+".operation-before.json")
	if isReparse(originalPath) {
		return errors.New("LEGACY_ORIGINAL_BACKUP_REDIRECTED")
	}
	if previous, e := os.ReadFile(originalPath); e == nil {
		if !bytes.Equal(previous, plan.sources[plan.operationPath]) {
			return errors.New("LEGACY_ORIGINAL_BACKUP_CONFLICT")
		}
	} else if !os.IsNotExist(e) {
		return e
	} else if err = writeExclusiveProof(originalPath, plan.sources[plan.operationPath]); err != nil {
		return err
	}
	current, err := os.ReadFile(plan.operationPath)
	if err != nil || !bytes.Equal(current, plan.sources[plan.operationPath]) {
		return errors.New("LEGACY_OPERATION_CHANGED_BEFORE_TRANSITION")
	}
	next := map[string]any{}
	for k, v := range plan.operation {
		next[k] = v
	}
	next["state"] = "superseded_verified"
	next["result_code"] = "LOCAL_SLOT_SUPERSEDED_BY_VERIFIED_MIGRATION"
	next["result_message"] = "Original Phase3 failure preserved in reconciliation receipt; linked R8 commit and fresh AU junction/tree/binding/HTTP/DB71 independently verified."
	next["updated_at_utc"] = time.Now().UTC().Format(time.RFC3339Nano)
	encoded, err := json.MarshalIndent(next, "", "  ")
	if err != nil {
		return err
	}
	if err = atomicWrite(plan.operationPath, append(encoded, '\n')); err != nil {
		return err
	}
	readback, err := os.ReadFile(plan.operationPath)
	if err != nil || !bytes.Equal(readback, append(encoded, '\n')) {
		return errors.New("LEGACY_TRANSITION_READBACK_UNPROVEN")
	}
	return nil
}
func writeExclusiveProof(path string, raw []byte) error {
	f, err := os.OpenFile(path, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0600)
	if err != nil {
		return err
	}
	_, w := f.Write(raw)
	s := f.Sync()
	c := f.Close()
	if w != nil {
		return w
	}
	if s != nil {
		return s
	}
	return c
}
func (b *bridge) verifySignedFoundation() error {
	script := `<?php require_once $argv[1].'/src/Domain/System/MaintenanceSupervisorClient.php';$d=(new \Sokna\Local\Domain\System\MaintenanceSupervisorClient())->connectionDiagnostics();$ok=($d['authenticated']??false)===true&&($d['signed_health']??'')==='valid';echo json_encode(['ok'=>$ok,'code'=>$d['error_code']??'unknown']);exit($ok?0:78);`
	path := filepath.Join(b.evidenceDir, "foundation-signed-health.php")
	if err := os.WriteFile(path, []byte(script), 0600); err != nil {
		return err
	}
	out, err := runHidden(b.phpPath, path, b.targetSlot)
	if err != nil {
		return fmt.Errorf("SIGNED_FOUNDATION_UNPROVEN: %s", strings.TrimSpace(out))
	}
	return nil
}
