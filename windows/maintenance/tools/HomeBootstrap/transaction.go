package main

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// Persistent, one-owner interruption guard. It does not purport to perform
// unattended recovery after a power loss: a pending transaction must be
// inspected and recovered using authenticated host evidence first.
const transactionFormat = "sokna-local-231au-m06-slot-transaction-v1"

type transactionEvidence struct {
	Format                         string `json:"format"`
	AttemptID                      string `json:"attempt_id"`
	Phase                          string `json:"phase"`
	CreatedAt                      string `json:"created_at"`
	UpdatedAt                      string `json:"updated_at"`
	BaselineVersion                string `json:"baseline_version"`
	TargetVersion                  string `json:"target_version"`
	PackageRoot                    string `json:"package_root"`
	StableWeb                      string `json:"stable_web"`
	TargetSlot                     string `json:"target_slot"`
	StageSlot                      string `json:"stage_slot"`
	PreviousSlot                   string `json:"previous_slot"`
	EvidenceDir                    string `json:"evidence_dir"`
	LifecycleState                 string `json:"lifecycle_state"`
	LifecycleManaged               string `json:"lifecycle_managed"`
	LifecycleStateSnapshotSHA256   string `json:"lifecycle_state_snapshot_sha256"`
	LifecycleManagedSnapshotSHA256 string `json:"lifecycle_managed_snapshot_sha256,omitempty"`
	LifecycleManagedExisted        bool   `json:"lifecycle_managed_existed"`
}

func (b *bridge) transactionPath() string {
	return filepath.Join(filepath.Dir(b.evidenceDir), "portable-231au-m06-active-transaction.json")
}

func readPendingTransaction(path string) (*transactionEvidence, error) {
	raw, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	var tx transactionEvidence
	if err := json.Unmarshal(raw, &tx); err != nil {
		return nil, fmt.Errorf("pending transaction corrupt: %w", err)
	}
	if tx.Format != transactionFormat || tx.AttemptID == "" || tx.Phase == "" {
		return nil, errors.New("pending transaction identity invalid")
	}
	return &tx, nil
}

func snapshotSHA256(path string) (string, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return "", err
	}
	sum := sha256.Sum256(data)
	return hex.EncodeToString(sum[:]), nil
}

func (b *bridge) beginMutationTransaction() error {
	path := b.transactionPath()
	// Ensure that no earlier interrupted/corrupt transaction can be bypassed.
	if _, err := os.Lstat(path); err == nil {
		return errors.New("pending home Local upgrade transaction exists; STOP and use recovery assessment")
	} else if !os.IsNotExist(err) {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(path), 0700); err != nil {
		return err
	}
	stateHash, err := snapshotSHA256(b.lifecycleStateBackup)
	if err != nil {
		return err
	}
	managedHash := ""
	if b.lifecycleManagedExisted {
		managedHash, err = snapshotSHA256(b.lifecycleManagedBackup)
		if err != nil {
			return err
		}
	}
	now := time.Now().UTC().Format(time.RFC3339Nano)
	tx := transactionEvidence{Format: transactionFormat, AttemptID: b.ev.AttemptID, Phase: "PREPARING", CreatedAt: now, UpdatedAt: now, BaselineVersion: baselineVersion, TargetVersion: targetVersion,
		PackageRoot: b.packageRoot, StableWeb: b.stableWeb, TargetSlot: b.targetSlot, StageSlot: b.stageSlot, PreviousSlot: b.backupPath,
		EvidenceDir: b.evidenceDir, LifecycleState: b.lifecycleStatePath, LifecycleManaged: b.lifecycleManagedPath,
		LifecycleStateSnapshotSHA256: stateHash, LifecycleManagedSnapshotSHA256: managedHash, LifecycleManagedExisted: b.lifecycleManagedExisted}
	raw, err := json.MarshalIndent(tx, "", "  ")
	if err != nil {
		return err
	}
	f, err := os.OpenFile(path, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0600)
	if err != nil {
		return fmt.Errorf("cannot reserve exclusive Local slot upgrade: %w", err)
	}
	_, werr := f.Write(append(raw, '\n'))
	serr := f.Sync()
	cerr := f.Close()
	if werr != nil || serr != nil || cerr != nil {
		return errors.New("transaction journal creation not durable; STOP and inspect manually")
	}
	b.transaction = &tx
	return nil
}

func (b *bridge) checkpointTransaction(phase string) error {
	if b.transaction == nil {
		return errors.New("no active transition journal")
	}
	if strings.TrimSpace(phase) == "" {
		return errors.New("missing transition checkpoint phase")
	}
	b.transaction.Phase = phase
	b.transaction.UpdatedAt = time.Now().UTC().Format(time.RFC3339Nano)
	raw, err := json.MarshalIndent(b.transaction, "", "  ")
	if err != nil {
		return err
	}
	if err := atomicWrite(b.transactionPath(), append(raw, '\n')); err != nil {
		return fmt.Errorf("cannot durably checkpoint %s: %w", phase, err)
	}
	return nil
}

func (b *bridge) closeTransaction(final string) error {
	if b.transaction == nil {
		return nil
	}
	if final != "COMMITTED" && final != "ROLLED_BACK" && final != "ABORTED_BEFORE_CUTOVER" {
		return errors.New("unsafe transaction close state")
	}
	if err := b.checkpointTransaction(final); err != nil {
		return err
	}
	src := b.transactionPath()
	dest := filepath.Join(b.evidenceDir, "transaction-final.json")
	raw, err := os.ReadFile(src)
	if err != nil {
		return err
	}
	if err := atomicWrite(dest, raw); err != nil {
		return err
	}
	if err := os.Remove(src); err != nil {
		return err
	}
	b.transaction = nil
	return nil
}

// Failure before moving the physical Web is not an upgrade. Only remove the
// pending owner once independent current Web and lifecycle evidence remain sane.
// Revalidate just before stopping the HTTP service. A preflight result from
// several seconds earlier is not a license to cut over a newly changed Web.
