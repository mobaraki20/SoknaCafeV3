package main

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"
)

func (b *bridge) verifyOriginalSlot(oldSlot string) error {
	if !isReparse(b.stableWeb) {
		return errors.New("stable Web must be an existing Windows junction; no legacy physical mutation allowed")
	}
	info, err := os.Lstat(oldSlot)
	if err != nil {
		return fmt.Errorf("expected 231au sibling slot missing: %w", err)
	}
	if !info.IsDir() || info.Mode()&os.ModeSymlink != 0 || isReparse(oldSlot) {
		return errors.New("231au sibling slot is not a physical directory")
	}
	same, err := samePhysicalDirectory(b.stableWeb, oldSlot)
	if err != nil {
		return fmt.Errorf("Windows junction identity unavailable: %w", err)
	}
	if !same {
		return errors.New("stable Web does not resolve to pinned 231au sibling slot")
	}
	v, err := os.ReadFile(filepath.Join(oldSlot, "VERSION.txt"))
	if err != nil {
		return err
	}
	if strings.TrimSpace(string(v)) != baselineVersion {
		return fmt.Errorf("expected installed 231au, received %q", strings.TrimSpace(string(v)))
	}
	if err = verifyTree(oldSlot, b.baseline); err != nil {
		return fmt.Errorf("installed Local 231au managed-tree drift: %w", err)
	}
	_, _, err = bindingIdentity(filepath.Join(oldSlot, ".sokna-installation.json"), filepath.Join(b.packageRoot, "install.lock"), baselineVersion)
	return err
}

func (b *bridge) verifyNewJunction(target string) error {
	if !isReparse(b.stableWeb) {
		return errors.New("new Web is not Windows junction")
	}
	info, err := os.Lstat(target)
	if err != nil {
		return err
	}
	if !info.IsDir() || info.Mode()&os.ModeSymlink != 0 || isReparse(target) {
		return errors.New("target sibling is not a physical directory")
	}
	same, err := samePhysicalDirectory(b.stableWeb, target)
	if err != nil {
		return err
	}
	if !same {
		return errors.New("Web points to unknown directory after cutover")
	}
	return nil
}

func (b *bridge) prepareHomeOverlays() error {
	// Work only within this attempt's inactive staging and protected snapshots;
	// never rewrite global persistent/old-slot binding in the slot-to-slot path.
	b.persistentRoot = filepath.Join(b.evidenceDir, "baseline-machine-overlays")
	if err := os.MkdirAll(b.persistentRoot, 0700); err != nil {
		return err
	}
	for _, rel := range machineLocalOverlay {
		old := filepath.Join(b.backupPath, rel)
		info, err := os.Lstat(old)
		if os.IsNotExist(err) {
			if rel == ".sokna-installation.json" {
				return errors.New("required machine binding missing")
			}
			continue
		}
		if err != nil {
			return err
		}
		if !info.Mode().IsRegular() || info.Mode()&os.ModeSymlink != 0 || isReparse(old) {
			return fmt.Errorf("non-regular machine overlay %s", rel)
		}
		if err = copyFile(old, filepath.Join(b.persistentRoot, rel)); err != nil {
			return err
		}
		if err = copyFile(old, filepath.Join(b.stageSlot, rel)); err != nil {
			return err
		}
	}
	return synthesizeTargetBinding(b.persistentRoot, b.stageSlot, b.packageRoot)
}

func (b *bridge) assertHomeStateUnchanged() error {
	if b.transaction == nil {
		return errors.New("durable operation owner missing")
	}
	if err := b.verifyOriginalSlot(b.backupPath); err != nil {
		return err
	}
	h, err := snapshotSHA256(b.lifecycleStatePath)
	if err != nil {
		return err
	}
	if h != b.transaction.LifecycleStateSnapshotSHA256 {
		return errors.New("Local lifecycle changed since preflight")
	}
	if b.transaction.LifecycleManagedExisted {
		h, err = snapshotSHA256(b.lifecycleManagedPath)
		if err != nil {
			return err
		}
		if h != b.transaction.LifecycleManagedSnapshotSHA256 {
			return errors.New("Local managed inventory changed since preflight")
		}
	} else if _, err = os.Lstat(b.lifecycleManagedPath); !os.IsNotExist(err) {
		return errors.New("Local inventory appeared since preflight")
	}
	return nil
}

func (b *bridge) abortIfHomeUnmodified() error {
	if b.transaction == nil {
		return nil
	}
	if b.switched || b.lifecycleMutated {
		return errors.New("cannot close after mutation without verified recovery")
	}
	// Even after Apache stop, a pre-cutover failure requires proving baseline
	// availability; a failure to start is NOT a successful abort.
	if err := b.verifyOriginalSlot(b.backupPath); err != nil {
		return err
	}
	state, err := snapshotSHA256(b.lifecycleStatePath)
	if err != nil {
		return err
	}
	if state != b.transaction.LifecycleStateSnapshotSHA256 {
		return errors.New("lifecycle diverged after preflight")
	}
	if b.transaction.LifecycleManagedExisted {
		managed, er := snapshotSHA256(b.lifecycleManagedPath)
		if er != nil {
			return er
		}
		if managed != b.transaction.LifecycleManagedSnapshotSHA256 {
			return errors.New("managed inventory diverged after preflight")
		}
	}
	if err = httpHealth(baselineVersion); err != nil {
		return fmt.Errorf("baseline HTTP health not proven after aborted attempt: %w", err)
	}
	return b.closeTransaction("ABORTED_BEFORE_CUTOVER")
}

func (b *bridge) rollbackHome(oldSlot string) error {
	b.ev.RollbackAttempted = true
	b.ev.Phase = "ROLLBACK"
	b.writeEvidence()
	if b.transaction != nil {
		if err := b.checkpointTransaction("ROLLBACK_IN_PROGRESS"); err != nil {
			return err
		}
	}
	// Stop Apache first. Never remove a junction while an unknown service process
	// could be using it.
	if err := b.stopApache(); err != nil {
		return err
	}
	if isReparse(b.stableWeb) {
		oldOK, oldErr := samePhysicalDirectory(b.stableWeb, oldSlot)
		newOK, newErr := samePhysicalDirectory(b.stableWeb, b.targetSlot)
		if oldErr != nil && newErr != nil {
			return errors.New("unable to attest Windows junction before rollback")
		}
		if !oldOK && !newOK {
			return errors.New("junction is neither verified baseline nor target; recovery requires inspection")
		}
		if newOK {
			if err := b.removeJunction(b.stableWeb); err != nil {
				return err
			}
		}
	} else if _, err := os.Lstat(b.stableWeb); err == nil {
		return errors.New("non-junction Web exists; refusing overwrite")
	} else if !os.IsNotExist(err) {
		return err
	}
	if _, err := os.Lstat(b.stableWeb); os.IsNotExist(err) {
		if err = b.makeJunction(b.stableWeb, oldSlot); err != nil {
			return err
		}
	}
	if err := b.verifyOriginalSlot(oldSlot); err != nil {
		return err
	}
	// Mark lifecycle dirty before restoration: writes may have been partially made.
	b.lifecycleMutated = true
	if err := b.restoreLocalLifecycle(); err != nil {
		return err
	}
	if err := b.startApache(); err != nil {
		return err
	}
	if err := waitHealth(baselineVersion, 40*time.Second); err != nil {
		return err
	}
	if err := b.verifyOriginalSlot(oldSlot); err != nil {
		return err
	}
	if b.transaction != nil {
		if err := b.closeTransaction("ROLLED_BACK"); err != nil {
			return err
		}
	}
	b.ev.RollbackOK = true
	b.writeEvidence()
	return nil
}
func (b *bridge) failWithHomeRollback(cause error, oldSlot string) error {
	if err := b.rollbackHome(oldSlot); err != nil {
		return fmt.Errorf("activation failed (%v); rollback UNPROVEN (%v) — DO NOT RUN AGAIN", cause, err)
	}
	return fmt.Errorf("activation failed, verified HTTP 231au rollback: %w", cause)
}

// A pending journal must be preserved after hard interruption. This is
// deliberately a non-mutating assessment; it never invents a recovery PASS.
func (b *bridge) assessInterruptedTransaction() (string, error) {
	tx, err := readPendingTransaction(b.transactionPath())
	if err != nil {
		return "RECOVERY_EVIDENCE_INVALID", err
	}
	if tx == nil {
		return "NO_PENDING_TRANSACTION", nil
	}
	if tx.Format != transactionFormat || tx.BaselineVersion != baselineVersion || tx.TargetVersion != targetVersion {
		return "RECOVERY_EVIDENCE_INVALID", errors.New("operation version mismatch")
	}
	return "PENDING_HOME_SLOT_TRANSACTION_REQUIRES_RECONCILIATION", fmt.Errorf("interrupted operation %s in phase %s; do not rerun installer", tx.AttemptID, tx.Phase)
}
