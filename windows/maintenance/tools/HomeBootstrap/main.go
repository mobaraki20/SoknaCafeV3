package main

import (
	"crypto/sha256"
	"embed"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"
)

//go:embed payload/baseline-manifest.json payload/target-manifest.json payload/migration-catalog.json payload/local-m06-target.zip
var embedded embed.FS

// Last-resort one-time migration-aware UAT transition from exact Local 231au to U01.
// Machine name and drive are discovered; source tree/DB/layout are locked.
var allowMutationBuild = "false"
var runMode = "normal"

func main() {
	readOnly := allowMutationBuild != "true"
	// Preflight validates exact installed 231au source tree before any active-site mutation.
	// It performs a complete fresh preflight before any mutation.
	if !isAdmin() {
		if err := elevateSelf(); err != nil {
			showError("دسترسی Administrator دریافت نشد.\n" + err.Error())
		}
		return
	}
	if runMode != "normal" && runMode != "recovery-assessment" {
		showError("این ابزار فقط برای بررسی و مهاجرت کنترل‌شده ساخته شده است.")
		return
	}
	if runMode == "normal" && !readOnly && !confirmStart() {
		return
	}
	b, err := newBridge()
	if err != nil {
		showError("پیش‌نیازهای بسته معتبر نیست.\n" + err.Error())
		return
	}
	b.readOnly = readOnly
	if runMode == "recovery-assessment" {
		status, er := b.assessInterruptedTransaction()
		b.ev.Phase = status
		b.ev.OK = er == nil
		b.ev.FinishedAt = time.Now().UTC().Format(time.RFC3339)
		if er != nil {
			b.ev.Error = er.Error()
		}
		b.writeEvidence()
		if er != nil {
			showError("بررسی شواهد بازیابی نیاز به رسیدگی دارد. هیچ فایلی تغییر نکرد.\n\n" + er.Error() + "\n\n" + b.evidenceDir)
		} else {
			showSuccess("نتیجه بررسی فقط‌خواندنی بازیابی: " + status + "\n\nهیچ تغییر در Local/Apache انجام نشد.\n" + b.evidenceDir)
		}
		return
	}
	if err = b.run(); err != nil {
		b.ev.OK = false
		b.ev.FinishedAt = time.Now().UTC().Format(time.RFC3339)
		b.ev.Error = err.Error()
		if b.ev.Phase == "" {
			b.ev.Phase = "FAILED"
		}
		b.writeEvidence()
		if !readOnly && b.transaction != nil && !b.dbBoundary && !b.ev.RollbackAttempted {
			if e := b.abortIfHomeUnmodified(); e != nil {
				b.log("INTERRUPTED TRANSACTION REMAINS BLOCKED: " + e.Error())
			}
		}
		b.log("FAIL: " + err.Error())
		if readOnly {
			showError("پیش‌بررسی بدون نصب تأیید نشد. Apache و نسخهٔ Local تغییر نکردند.\n\n" + err.Error() + "\n\nشناسه گزارش: " + b.ev.AttemptID + "\nگزارش: " + b.evidenceDir)
		} else {
			showError(failureOperatorMessage(b.ev, err, b.transaction == nil && !b.switched && !b.lifecycleMutated))
		}
		return
	}
	b.ev.OK = b.ev.Phase != "LOCAL_RUNNING_PANEL_BLOCKED"
	if readOnly {
		b.ev.Phase = "READ_ONLY_PASSED"
	} else if b.ev.Phase != "LOCAL_RUNNING_PANEL_BLOCKED" {
		b.ev.Phase = "COMPLETED"
	}
	b.ev.FinishedAt = time.Now().UTC().Format(time.RFC3339)
	b.writeEvidence()
	if readOnly {
		b.log("PASS: read-only transition preflight; no Local/Apache/service changes")
		showSuccess("بررسی بدون نصب با موفقیت انجام شد. نسخه‌ها و دیتابیس بررسی شدند.\n\nهیچ به‌روزرسانی یا تغییر Apache انجام نشد.\n\nشناسه گزارش: " + b.ev.AttemptID + "\nگزارش: " + b.evidenceDir)
		return
	}
	if b.ev.Phase == "LOCAL_RUNNING_PANEL_BLOCKED" {
		b.log("Local healthy on U01 but signed panel readiness blocked; no DB recovery required")
		showError("Local 231bk بالا آمده و دیتابیس ۷۵/۷۵ است، اما اتصال امضاشده پنل Maintenance هنوز آماده نیست. نصب‌کننده را تکرار نکنید.\n" + b.evidenceDir)
		return
	}
	b.log("PASS: independent Local transition completed")
	showSuccess("Local 231bk و ۷۵ مهاجرت دیتابیس با موفقیت بررسی شدند.\n\nفقط Local و دیتابیس آن تغییر کرده‌اند؛ Windows Services و Public بدون تغییر هستند.\nآزمون نهایی ارتباط پنل Maintenance را در مرورگر اجرا کنید.\n\nشناسه بررسی: " + b.ev.AttemptID)
}

func newBridge() (*bridge, error) {
	now := time.Now().UTC()
	id := "local231au-m06-" + now.Format("20060102T150405Z") + "-" + fmt.Sprintf("%x", sha256.Sum256([]byte(fmt.Sprintf("%d-%d", now.UnixNano(), os.Getpid()))))[:10]
	baseData, err := embedded.ReadFile("payload/baseline-manifest.json")
	if err != nil {
		return nil, err
	}
	targetData, err := embedded.ReadFile("payload/target-manifest.json")
	if err != nil {
		return nil, err
	}
	migData, err := embedded.ReadFile("payload/migration-catalog.json")
	if err != nil {
		return nil, err
	}
	zipData, err := embedded.ReadFile("payload/local-m06-target.zip")
	if err != nil {
		return nil, err
	}
	base, err := loadManifest(baseData, "baseline", baselineVersion, baselineFiles, baselineTree)
	if err != nil {
		return nil, err
	}
	target, err := loadManifest(targetData, "target", targetVersion, targetFiles, targetTree)
	if err != nil {
		return nil, err
	}
	var mig migrationManifest
	if err = json.Unmarshal(migData, &mig); err != nil {
		return nil, err
	}
	if mig.Format != "sokna-cafe-local-bridge-migrations-v1" || mig.SchemaVersion != 1 || mig.Count != migrationCount || len(mig.Files) != migrationCount || mig.Unchanged || mig.TargetCount != targetMigrationCount || len(mig.Added) != targetMigrationCount-migrationCount {
		return nil, errors.New("migration contract invalid")
	}
	h := sha256.Sum256(zipData)
	if !strings.EqualFold(hex.EncodeToString(h[:]), targetZipSHA) {
		return nil, errors.New("embedded target ZIP SHA256 mismatch")
	}
	pd := os.Getenv("ProgramData")
	if pd == "" {
		pd = `C:\ProgramData`
	}
	evidenceDir := filepath.Join(pd, "Sokna", "Maintenance", "local-final-oneoff-transitions", id)
	if err = os.MkdirAll(evidenceDir, 0755); err != nil {
		return nil, err
	}
	desktop := ""
	if exe, er := os.Executable(); er == nil {
		desktop = filepath.Join(filepath.Dir(exe), "SOKNA-Local-231au-U01-"+id+".log")
	}
	b := &bridge{baseline: base, target: target, migrations: mig, targetZip: zipData, evidenceDir: evidenceDir, logPath: filepath.Join(evidenceDir, "bridge.log"), desktopLog: desktop}
	b.ev = evidence{Format: "sokna-local-exact-baseline-bootstrap-v1", AttemptID: id, StartedAt: now.Format(time.RFC3339), Phase: "PREFLIGHT", BaselineVersion: baselineVersion, BaselineFiles: baselineFiles, BaselineTree: baselineTree, TargetVersion: targetVersion, TargetFiles: targetFiles, TargetTree: targetTree, MigrationsExpected: targetMigrationCount, DatabaseMutation: false, PublicMutation: false, WindowsServicesMutation: false, WindowsReboot: false}
	b.writeEvidence()
	return b, nil
}

func (b *bridge) run() error {
	b.addStep("verified exact Local 231au baseline and SHA-sealed U01 target")
	out, err := runHidden("sc.exe", "qc", apacheService)
	if err != nil {
		return fmt.Errorf("SoknaApache missing: %v %s", err, out)
	}
	exe, err := parseImagePathFromSC(out)
	if err != nil {
		return err
	}
	root, err := derivePackageRoot(exe)
	if err != nil {
		return err
	}
	b.packageRoot = root
	b.stableWeb = filepath.Join(root, "Web")
	b.ev.PackageRoot = root
	b.ev.StableWeb = b.stableWeb
	b.writeEvidence()
	if _, err = os.Stat(filepath.Join(root, "config.php")); err != nil {
		return fmt.Errorf("missing stable config: %w", err)
	}
	if _, err = os.Stat(filepath.Join(root, "install.lock")); err != nil {
		return fmt.Errorf("missing install lock: %w", err)
	}
	// Serialize this one-time canary with the existing Windows V4 machine-wide
	// maintenance writer lock. Read-only probes never need the lock. Keep the
	// handle open through Apache switch, health, rollback and lifecycle commit.
	if !b.readOnly {
		// No computer name is pinned; exact Local version, 510-file SHA tree, DB and host health still gate mutation.
		lease, e := acquireSharedMutationLease()
		if e != nil {
			return fmt.Errorf("Windows Maintenance already updating or lease unavailable: %w", e)
		}
		defer lease.Close()
		if _, er := os.Lstat(filepath.Join(os.Getenv("ProgramData"), "Sokna", "Maintenance", "local-final-oneoff-transitions", "home-231as-231at-active-transaction.json")); er == nil {
			return errors.New("PREVIOUS_LOCAL_TRANSACTION_PENDING: old 231as-to-231at transaction unresolved")
		} else if !os.IsNotExist(er) {
			return fmt.Errorf("previous transaction inspection unavailable: %w", er)
		}
		plan, e := assessPendingSignedV4Updates(b.packageRoot)
		if e != nil {
			return e
		}
		b.legacyPlan = plan
	}
	oldSlot := filepath.Join(root, "Web.release.231au-"+baselineTree[:12])
	b.backupPath = oldSlot
	b.ev.BackupPath = oldSlot
	if err = b.verifyOriginalSlot(oldSlot); err != nil {
		return err
	}
	b.addStep("current junction resolves to verified direct-sibling 231au physical release")
	if err = httpHealth(baselineVersion); err != nil {
		return fmt.Errorf("original Local HTTP not healthy: %w", err)
	}
	b.addStep("current HTTP exact 231au/php-ready")
	b.phpPath, err = findFile(filepath.Join(root, "Infrastructure"), "php.exe", 7)
	if err != nil {
		return err
	}
	if err = b.findUpdateEngine(); err != nil {
		return err
	}
	if err = b.preflightSupervisorDescriptor(); err != nil {
		return err
	}
	b.ev.UpdateEnginePath = b.updateEngine
	b.writeEvidence()
	b.addStep("installed Maintenance read-only inspect CLI contract verified (no service update)")
	if err = b.preflightInstalledMaintenanceIdentity(); err != nil {
		return fmt.Errorf("Maintenance identity cannot be reconciled safely BEFORE database mutation: %w", err)
	}
	b.addStep("Maintenance identity status qualified for exact Home 231au first-bind/recognized stale bridge")
	if err = b.discoverDataRootAndLifecycle(); err != nil {
		return err
	}
	if err = b.preflightLifecycle(); err != nil {
		return err
	}
	b.addStep("Local lifecycle idle with saved state and inventory evidence")
	applied, err := b.phpMigrationPreflight(oldSlot)
	if err != nil {
		return err
	}
	if applied != migrationCount {
		return errors.New("Local schema migration mismatch")
	}
	b.ev.MigrationsApplied = applied
	b.writeEvidence()
	b.addStep("migration catalog exactly 71/71 on installed 231au")
	b.baselineAttested = true
	if b.readOnly {
		if _, err = assessPendingSignedV4Updates(b.packageRoot); err != nil {
			return fmt.Errorf("Maintenance operation ledger not idle: %w", err)
		}
		if err = b.preflightMariaDBBackupConfig(); err != nil {
			return fmt.Errorf("MariaDB backup requirements not ready: %w", err)
		}
		b.addStep("dry-run qualified: no staged Maintenance conflict and SQL backup client/config present")
		return b.readOnlyTargetPreflight()
	}
	slot := filepath.Join(root, "Web.release.231bk-"+targetTree[:12])
	b.targetSlot = slot
	suffix := b.ev.AttemptID[len(b.ev.AttemptID)-10:]
	b.stageSlot = slot + ".staging-" + suffix
	b.ev.TargetSlot = slot
	b.ev.BackupPath = oldSlot
	b.writeEvidence()
	if err = b.beginMutationTransaction(); err != nil {
		return err
	}
	preMutationFailure := func(err error) error {
		if closeErr := b.abortIfHomeUnmodified(); closeErr != nil {
			return fmt.Errorf("%v; HOME_TRANSACTION_PENDING %v", err, closeErr)
		}
		return err
	}
	if _, err = archiveStaleInactiveSlot(b.stageSlot, filepath.Join(root, "LocalWeb", "slot-quarantine"), suffix); err != nil {
		return preMutationFailure(err)
	}
	if _, err = archiveStaleInactiveSlot(b.targetSlot, filepath.Join(root, "LocalWeb", "slot-quarantine"), suffix); err != nil {
		return preMutationFailure(err)
	}
	if err = extractZipExact(b.targetZip, b.stageSlot, b.target); err != nil {
		return preMutationFailure(err)
	}
	b.addStep("inactive transport bridge staged and manifest verified 510/510")
	if err = b.prepareHomeOverlays(); err != nil {
		return preMutationFailure(err)
	}
	if err = b.verifyOverlayAt(b.stageSlot); err != nil {
		return preMutationFailure(err)
	}
	if err = b.phpTargetSetupPreflight(b.stageSlot); err != nil {
		return preMutationFailure(err)
	}
	applied, err = b.phpMigrationTransitionPreflight(b.stageSlot)
	if err != nil || applied != migrationCount {
		return preMutationFailure(fmt.Errorf("inactive U01 migration lineage 71→75 rejected: %v", err))
	}
	b.addStep("inactive U01 setup OK; 71 previous migrations unchanged, four forward migrations pending")
	// Validate the REAL config.php backup credentials before Apache downtime.
	if err = b.preflightMariaDBBackupConfig(); err != nil {
		return preMutationFailure(fmt.Errorf("MariaDB backup configuration preflight failed before Apache stop: %w", err))
	}
	b.addStep("MariaDB backup credentials and numeric port validated before Apache stop (no DB mutation)")
	// Back up and authenticate bytes while Apache is still running. No outage
	// on credential, path, dump, or storage error (cafe 2026-10-22 regression).
	if err = b.backupMariaDB(); err != nil {
		return preMutationFailure(fmt.Errorf("MariaDB baseline backup unavailable BEFORE Apache downtime: %w", err))
	}
	if err = b.verifyBaselineBackupBeforeBoundary(); err != nil {
		return preMutationFailure(fmt.Errorf("MariaDB backup proof unavailable BEFORE Apache downtime: %w", err))
	}
	b.addStep("private pre-boundary SQL backup stored and SHA256-verified BEFORE Apache stop")
	if err = os.Rename(b.stageSlot, b.targetSlot); err != nil {
		return preMutationFailure(err)
	}
	if err = b.verifyOriginalSlot(oldSlot); err != nil {
		return preMutationFailure(err)
	}
	if err = b.assertHomeStateUnchanged(); err != nil {
		return preMutationFailure(err)
	}
	if err = b.reconcileVerifiedLegacy(); err != nil {
		return preMutationFailure(err)
	}
	if err = b.checkpointTransaction("READY_TO_SWITCH"); err != nil {
		return preMutationFailure(err)
	}
	b.ev.Phase = "ACTIVATING"
	b.writeEvidence()
	if err = b.checkpointTransaction("STOPPING_APACHE"); err != nil {
		return preMutationFailure(err)
	}
	if err = b.stopApache(); err != nil {
		return b.failWithHomeRollback(err, oldSlot)
	}
	b.addStep("SoknaApache stopped; previous PID exit checked")
	// Repeat the dump AFTER Apache is quiesced; the first dump catches bad
	// credentials/disk/client errors before downtime, but is not the boundary snapshot.
	if err = b.backupMariaDB(); err != nil {
		return b.failWithHomeRollback(fmt.Errorf("quiesced SQL backup failed: %w", err), oldSlot)
	}
	if err = b.verifyBaselineBackupBeforeBoundary(); err != nil {
		return b.failWithHomeRollback(fmt.Errorf("pre-boundary SQL backup changed: %w", err), oldSlot)
	}
	b.addStep("verified pre-boundary SQL backup retained and unchanged after Apache stop")
	if err = b.checkpointTransaction("REPLACING_JUNCTION"); err != nil {
		return b.failWithHomeRollback(err, oldSlot)
	}
	if err = b.verifyOriginalSlot(oldSlot); err != nil {
		return b.failWithHomeRollback(err, oldSlot)
	}
	if err = b.removeJunction(b.stableWeb); err != nil {
		return b.failWithHomeRollback(err, oldSlot)
	}
	b.switched = true
	if err = b.makeJunction(b.stableWeb, b.targetSlot); err != nil {
		return b.failWithHomeRollback(err, oldSlot)
	}
	if err = b.checkpointTransaction("WEB_JUNCTION_SWITCHED"); err != nil {
		return b.failWithHomeRollback(err, oldSlot)
	}
	if err = b.verifyNewJunction(b.targetSlot); err != nil {
		return b.failWithHomeRollback(err, oldSlot)
	}
	if err = b.checkpointTransaction("DB_FORWARD_BOUNDARY"); err != nil {
		return b.failWithHomeRollback(err, oldSlot)
	}
	// No automatic code rollback is safe from this durable point onward.
	b.dbBoundary = true
	b.ev.DatabaseBoundaryReached = true
	b.ev.DatabaseMutation = true // conservative: DDL may execute before process exit
	b.ev.Phase = "DB_FORWARD_BOUNDARY"
	b.writeEvidence()
	if err = b.phpApplyTargetMigrations(b.targetSlot); err != nil {
		return b.failAfterDbBoundary(fmt.Errorf("U01 forward migration failed: %w", err))
	}
	b.ev.DatabaseMutation = true
	b.ev.MigrationsApplied = targetMigrationCount
	b.writeEvidence()
	b.addStep("U01 SQL migrations executed and 75/75 ledger verified; forward-only DB boundary")
	if err = b.startApache(); err != nil {
		return b.failAfterDbBoundary(err)
	}
	if err = waitHealth(targetVersion, 45*time.Second); err != nil {
		return b.failAfterDbBoundary(err)
	}
	b.addStep("HTTP Local 231bk/php-ready healthy after DB forward migration")
	if err = b.verifyOverlayAt(b.targetSlot); err != nil {
		return b.failAfterDbBoundary(err)
	}
	applied, err = b.phpMigrationPreflightExpected(b.targetSlot, targetMigrationCount)
	if err != nil || applied != targetMigrationCount {
		return b.failAfterDbBoundary(fmt.Errorf("post-activation DB catalog mismatch: %v", err))
	}
	if err = b.reconcileLocalLifecycle(); err != nil {
		return b.failAfterDbBoundary(err)
	}
	b.addStep("Local lifecycle and managed inventory 522/522 committed")
	if err = b.inspectSlot(); err != nil {
		return b.failAfterDbBoundary(err)
	}
	b.ev.UpdateEngineInspectOK = true
	b.writeEvidence()
	if err = verifyTree(b.targetSlot, b.target); err != nil {
		return b.failAfterDbBoundary(err)
	}
	if err = b.verifyNewJunction(b.targetSlot); err != nil {
		return b.failAfterDbBoundary(err)
	}
	if err = b.verifyLifecycleTarget(); err != nil {
		return b.failAfterDbBoundary(err)
	}
	if err = b.attestAndRegisterU01Identity(); err != nil {
		return b.failAfterDbBoundary(err)
	}
	if err = b.verifyNextPanelReadiness(); err != nil {
		// Home Local and DB are healthy. An unavailable panel must NEVER take
		// the working cafe/home Local offline or fabricate DB recovery_required.
		b.ev.Phase = "LOCAL_RUNNING_PANEL_BLOCKED"
		b.ev.Error = "PANEL_SIGNED_CAPABILITIES_NOT_READY: " + err.Error()
		b.addStep("Local HTTP/DB healthy and Maintenance identity committed; panel signed capabilities not proven")
		if closeErr := b.closeTransaction("COMMITTED"); closeErr != nil {
			return closeErr
		}
		return nil
	}
	if err = b.checkpointTransaction("VERIFIED_READY_TO_COMMIT"); err != nil {
		return fmt.Errorf("new Local healthy but durable commit unproven: %w", err)
	}
	if err = b.closeTransaction("COMMITTED"); err != nil {
		return fmt.Errorf("Local healthy but durable journal closure unproven: %w", err)
	}
	b.addStep("COMPLETE: U01 + 75 migrations; old physical release retained for qualified disaster recovery, NOT a post-DDL code rollback")
	return nil
}

func failureOperatorMessage(ev evidence, err error, noMutation bool) string {
	status := "نصب Local انجام نشد. نتیجه بازگشت به نسخه قبلی هنوز تأیید نشده است. تا بررسی گزارش، اجرای دوباره نکنید."
	if ev.DatabaseBoundaryReached {
		status = "مهاجرت دیتابیس آغاز شده؛ بازگرداندن خودکار کد قدیمی ممنوع است. از روی گزارش و بکاپ، بازیابی رو به جلو انجام شود."
	} else if ev.RollbackAttempted && ev.RollbackOK {
		status = "نصب U01 انجام نشد. بازگشت پیش از مرز دیتابیس به نسخه 231au تأیید شد."
	} else if noMutation && !ev.RollbackAttempted {
		status = "نصب Local پیش از هر تغییر در نسخه فعال متوقف شد. بازگشت لازم نبود."
	} else if !ev.RollbackAttempted && ev.Phase == "FAILED" {
		status = "نصب Local انجام نشد. علت توقف را در گزارش بررسی کنید؛ هیچ موفقیت یا بازگشتی را فرض نکنید."
	}
	return status + "\n\n" + err.Error() + "\n\nشناسه بررسی: " + ev.AttemptID + "\nگزارش در همان پوشه EXE با پسوند log و json ثبت می‌شود."
}
