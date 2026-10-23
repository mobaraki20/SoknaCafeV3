package main

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
)

const homeTargetReleaseID = "sokna-local-231bk-durable-updater-20261023"

// A one-time authenticated operator-led bridge from a byte-verified physical
// Home Local 231au and schema 71/71. No fabricated signature or trust keys.
// Explicitly reject unrecognized previous identity BEFORE Apache downtime.
func (b *bridge) preflightInstalledMaintenanceIdentity() error {
	path := b.installedIdentityPath()
	raw, err := os.ReadFile(path)
	if os.IsNotExist(err) {
		b.addStep("Maintenance identity absent on old Home host; exact physical and 71/71 DB preflight required before first-bind")
		return nil
	}
	if err != nil {
		return fmt.Errorf("MAINTENANCE_IDENTITY_UNREADABLE: %w", err)
	}
	var document map[string]json.RawMessage
	if err := json.Unmarshal(raw, &document); err != nil {
		return errors.New("MAINTENANCE_IDENTITY_INVALID_JSON")
	}
	var local struct {
		ReleaseID     string `json:"release_id"`
		Version       string `json:"version"`
		Tree          string `json:"managed_tree_sha256"`
		Fingerprint   string `json:"schema_fingerprint"`
		MigrationHead string `json:"migration_head"`
	}
	if err = json.Unmarshal(document["local"], &local); err != nil || local.ReleaseID == "" || local.Version == "" || len(local.Tree) != 64 || local.Fingerprint == "" || local.MigrationHead == "" {
		return errors.New("MAINTENANCE_PREVIOUS_LOCAL_IDENTITY_NOT_VERIFIABLE")
	}
	// Only the known Home upgrade lineage is allowed for controlled rebind.
	good := local.Version == baselineVersion && strings.EqualFold(local.Tree, baselineTree)
	knownStale := strings.HasPrefix(local.Version, "3.0.0-g6.2-workspace.231at-") || strings.HasPrefix(local.Version, "3.0.0-g6.2-workspace.231as-") || strings.HasPrefix(local.Version, "3.0.0-g6.2-workspace.231ap-") || strings.HasPrefix(local.Version, "3.0.0-g6.2-workspace.231ar-")
	if !good && !knownStale {
		return errors.New("MAINTENANCE_IDENTITY_NOT_SUPPORTED_231AU_HOME_BASELINE")
	}
	b.addStep("Maintenance identity previously recorded: verified Home predecessor lineage; preserve peer metadata")
	return nil
}
func (b *bridge) installedIdentityPath() string {
	pd := os.Getenv("ProgramData")
	if pd == "" {
		pd = `C:\ProgramData`
	}
	return filepath.Join(pd, "SOKNA", "Maintenance", "installed-release-identity.json")
}

// Bind or rebind post-DB only. Expected immutable target and complete schema
// attestation precede the SINGLE atomic identity write.
func (b *bridge) attestAndRegisterU01Identity() error {
	if !b.dbBoundary || b.ev.MigrationsApplied != targetMigrationCount {
		return errors.New("IDENTITY_TARGET_DB_FORWARD_UNPROVEN")
	}
	if err := verifyTree(b.targetSlot, b.target); err != nil {
		return err
	}
	if err := b.verifyLifecycleTarget(); err != nil {
		return err
	}
	proof, err := b.phpTargetSchemaAttestation()
	if err != nil {
		return err
	}
	if !regexp.MustCompile(`^migration-ledger-sha256-v1:[0-9a-f]{64}$`).MatchString(proof.Fingerprint) || proof.Count != targetMigrationCount || proof.Head != "0074_iran_time_notification_boundaries" {
		return errors.New("DB_SCHEMA_FINGERPRINT_INVALID")
	}
	data, err := os.ReadFile(filepath.Join(b.targetSlot, "resources", "release-identity-v1.json"))
	if err != nil {
		return err
	}
	var release struct {
		ReleaseSetID string `json:"release_set_id"`
		Version      string `json:"version"`
		Source       struct {
			ManagedFileCount int `json:"managed_file_count"`
		} `json:"source"`
	}
	if err = json.Unmarshal(data, &release); err != nil || release.ReleaseSetID != homeTargetReleaseID || release.Version != targetVersion || release.Source.ManagedFileCount != targetFiles {
		return errors.New("TARGET_RELEASE_IDENTITY_UNVERIFIED")
	}
	path := b.installedIdentityPath()
	original, err := os.ReadFile(path)
	var existing map[string]json.RawMessage
	exists := err == nil
	if exists {
		if err = json.Unmarshal(original, &existing); err != nil {
			return errors.New("MAINTENANCE_PREVIOUS_IDENTITY_CORRUPT")
		}
		var old map[string]any
		if err = json.Unmarshal(existing["local"], &old); err != nil {
			return errors.New("MAINTENANCE_PREVIOUS_LOCAL_IDENTITY_UNQUALIFIED")
		}
		version, ok := old["version"].(string)
		if !ok {
			return errors.New("MAINTENANCE_PREVIOUS_LOCAL_IDENTITY_UNQUALIFIED")
		}
		knownStale := strings.HasPrefix(version, "3.0.0-g6.2-workspace.231at-") || strings.HasPrefix(version, "3.0.0-g6.2-workspace.231as-") || strings.HasPrefix(version, "3.0.0-g6.2-workspace.231ap-") || strings.HasPrefix(version, "3.0.0-g6.2-workspace.231ar-")
		if !(version == baselineVersion || knownStale) {
			return errors.New("MAINTENANCE_IDENTITY_CHANGED_FROM_PREFLIGHT")
		}
		snapshot := filepath.Join(b.evidenceDir, "installed-release-identity.before.json")
		if err = os.WriteFile(snapshot, original, 0600); err != nil {
			return err
		}
		a, _, er := shaFile(snapshot)
		c, _, er2 := shaFile(path)
		if er != nil || er2 != nil || a != c {
			return errors.New("IDENTITY_BACKUP_NOT_VERIFIED")
		}
	} else if os.IsNotExist(err) {
		existing = map[string]json.RawMessage{"public": json.RawMessage("null"), "backup_recovery_engine_version": json.RawMessage("null"), "public_maintenance_kernel_version": json.RawMessage("null")}
	} else {
		return fmt.Errorf("MAINTENANCE_IDENTITY_READ_ERROR: %w", err)
	}
	next := map[string]any{"release_id": homeTargetReleaseID, "version": targetVersion, "managed_tree_sha256": targetTree, "schema_fingerprint": proof.Fingerprint, "migration_head": proof.Head, "authority_epoch_policy": "preserved"}
	encoded, err := json.Marshal(next)
	if err != nil {
		return err
	}
	existing["local"] = encoded
	if b.maintenanceSupervisorVersion != "0.3.13" || b.maintenanceEngineVersion != "0.3.13" {
		return errors.New("MAINTENANCE_OBSERVED_VERSIONS_UNPROVEN")
	}
	existing["maintenance_supervisor_version"], _ = json.Marshal(b.maintenanceSupervisorVersion)
	existing["update_engine_version"], _ = json.Marshal(b.maintenanceEngineVersion)
	body, err := json.MarshalIndent(existing, "", "  ")
	if err != nil {
		return err
	}
	body = append(body, '\n')
	if exists {
		// Existing file is preserved separately; atomic replacement only after proving unchanged bytes.
		now, er := os.ReadFile(path)
		if er != nil || !bytes.Equal(now, original) {
			return errors.New("IDENTITY_CHANGED_DURING_UPGRADE")
		}
		if err = atomicWrite(path, body); err != nil {
			return err
		}
	} else {
		f, er := os.OpenFile(path, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0600)
		if er != nil {
			return fmt.Errorf("IDENTITY_FIRST_BIND_CREATE_FAILED: %w", er)
		}
		_, writeErr := f.Write(body)
		syncErr := f.Sync()
		closeErr := f.Close()
		if writeErr != nil || syncErr != nil || closeErr != nil {
			return errors.New("IDENTITY_FIRST_BIND_WRITE_UNCERTAIN")
		}
	}
	rb, err := os.ReadFile(path)
	if err != nil || !bytes.Equal(rb, body) {
		return errors.New("IDENTITY_BIND_READBACK_INVALID")
	}
	b.addStep("Maintenance local identity atomically registered from exact 231bk tree and 75/75 DB proof; no publisher key or machine-specific signing")
	return nil
}

type schemaProof struct {
	Fingerprint string `json:"fingerprint"`
	Head        string `json:"head"`
	Count       int    `json:"count"`
}

func (b *bridge) phpTargetSchemaAttestation() (schemaProof, error) {
	var parsed schemaProof
	php := `<?php
 if($argc!==3)exit(64);$root=$argv[1];$web=$argv[2];$cfg=require $root.'/config.php';if(!is_array($cfg))exit(65);
 require_once $web.'/src/Core/Config.php';require_once $web.'/src/Core/Database.php';require_once $web.'/src/Core/MigrationExecutionException.php';require_once $web.'/src/Core/Migrations.php';
 $pdo=\Sokna\Local\Core\Database::connect(\Sokna\Local\Core\Config::fromArray($cfg));$m=new \Sokna\Local\Core\Migrations($pdo,$web.'/database/migrations');$catalog=$m->catalog();$applied=$m->appliedVersions();$keys=array_keys($catalog);sort($keys,SORT_STRING);sort($applied,SORT_STRING);
 if(count($keys)!==75||$keys!==$applied||end($applied)!=='0074_iran_time_notification_boundaries')exit(67);
 $q=$pdo->query("SELECT COUNT(*) FROM schema_migration_statements WHERE state<>'applied'")->fetchColumn();if((int)$q!==0)exit(68);
 $lines=[];foreach($keys as $v)$lines[]=$v."\0".hash_file('sha256',$catalog[$v]);echo json_encode(['fingerprint'=>'migration-ledger-sha256-v1:'.hash('sha256',implode("\n",$lines)."\n"),'head'=>end($applied),'count'=>count($applied)],JSON_UNESCAPED_SLASHES),"\n";
 ?>`
	path := filepath.Join(b.evidenceDir, "db-schema-attest.php")
	if err := os.WriteFile(path, []byte(php), 0600); err != nil {
		return parsed, err
	}
	out, err := runHidden(b.phpPath, path, b.packageRoot, b.targetSlot)
	if err != nil {
		return parsed, errors.New("DB_SCHEMA_ATTESTATION_FAILED: " + strings.TrimSpace(out))
	}
	if err = json.Unmarshal([]byte(strings.TrimSpace(out)), &parsed); err != nil {
		return parsed, err
	}
	if parsed.Count != targetMigrationCount {
		return parsed, errors.New("DB_SCHEMA_ATTESTATION_WRONG_COUNT")
	}
	return parsed, nil
}
func (b *bridge) verifyNextPanelReadiness() error {
	php := `<?php
 if($argc!==2)exit(64);require_once $argv[1].'/src/Domain/System/MaintenanceSupervisorClient.php';
 $d=(new \Sokna\Local\Domain\System\MaintenanceSupervisorClient())->connectionDiagnostics();
 $ok=($d['authenticated']??false)===true&&($d['verification_ready']??false)===true&&($d['update_ready']??false)===true;
 echo json_encode(['ok'=>$ok,'discovery'=>$d['discovery']??'unknown','signed_health'=>$d['signed_health']??'unknown','verification_ready'=>$d['verification_ready']??false,'update_ready'=>$d['update_ready']??false,'code'=>$d['error_code']??'UNKNOWN'],JSON_UNESCAPED_SLASHES),"\n";exit($ok?0:78);
 ?>`
	path := filepath.Join(b.evidenceDir, "next-panel-verify.php")
	if err := os.WriteFile(path, []byte(php), 0600); err != nil {
		return err
	}
	out, err := runHidden(b.phpPath, path, b.targetSlot)
	if err != nil {
		return fmt.Errorf("PANEL_AFTER_BOOTSTRAP_NOT_READY: %s", strings.TrimSpace(out))
	}
	var parsed struct {
		OK   bool   `json:"ok"`
		Code string `json:"code"`
	}
	if err = json.Unmarshal([]byte(strings.TrimSpace(out)), &parsed); err != nil || !parsed.OK {
		return errors.New("PANEL_SIGNED_CAPABILITIES_NOT_READY")
	}
	b.addStep("PHP signed health and full panel_update_ready=true verified on installed home host")
	return nil
}
