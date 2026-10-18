#!/usr/bin/env python3
"""F1-S7E2B: independent disposable MariaDB forward-only crash + encrypted backup restore."""
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import signal
import subprocess
import sys
import tempfile
import time


def check(flag, label):
    if not flag:
        raise RuntimeError("S7E2B_" + label)


def guarded():
    env = os.environ
    check(env.get("GITHUB_ACTIONS") == "true" and env.get("RUNNER_OS") == "Linux" and
          env.get("GITHUB_WORKFLOW") == "M10 Release Qualification" and
          env.get("SOKNA_MARIADB_CI_ONLY") == "1" and
          env.get("SOKNA_TEST_DB_HOST") == "127.0.0.1" and
          env.get("SOKNA_TEST_DB_PORT") == "3306" and
          env.get("SOKNA_TEST_DB_ROOT_USER") == "root" and
          bool(env.get("SOKNA_TEST_DB_ROOT_PASS")), "DISPOSABLE_M10_DATABASE_ONLY")
    for tool in ("mariadb", "mariadb-dump", "openssl"):
        check(shutil.which(tool) is not None, "MISSING_BINARY_" + tool)
    return Path(env["RUNNER_TEMP"]).resolve(strict=True)


def cli(*extra):
    env = os.environ.copy()
    env["MYSQL_PWD"] = env["SOKNA_TEST_DB_ROOT_PASS"]
    return ["mariadb", "--protocol=tcp", "-h", "127.0.0.1", "-P", "3306",
            "-u", "root", "--batch", "--skip-column-names", *extra], env


def sql(statement, database=None):
    args, env = cli(*([database] if database else []))
    p = subprocess.run([*args, "-e", statement], env=env, capture_output=True,
                       text=True, timeout=20)
    if p.returncode:
        raise RuntimeError("S7E2B_DATABASE_COMMAND_FAILED:" + p.stderr[:250])
    return p.stdout.strip()


def durable(path, content):
    temporary = path.with_suffix(".writing")
    with open(temporary, "w") as f:
        f.write(content)
        f.flush()
        os.fsync(f.fileno())
    os.replace(temporary, path)
    parent_fd = os.open(str(path.parent), os.O_RDONLY)
    try:
        os.fsync(parent_fd)
    finally:
        os.close(parent_fd)


def encrypt(raw, key):
    env = os.environ.copy()
    env["SOKNA_S7E2B_KEY"] = key
    cmd = ["openssl", "enc", "-aes-256-cbc", "-pbkdf2", "-iter", "150000",
           "-salt", "-pass", "env:SOKNA_S7E2B_KEY"]
    p = subprocess.run(cmd, env=env, input=raw, capture_output=True, timeout=30)
    check(p.returncode == 0 and len(p.stdout) > 32, "ENCRYPTION_FAILED")
    return p.stdout


def decrypt(ciphertext, key):
    env = os.environ.copy()
    env["SOKNA_S7E2B_KEY"] = key
    cmd = ["openssl", "enc", "-d", "-aes-256-cbc", "-pbkdf2", "-iter", "150000",
           "-pass", "env:SOKNA_S7E2B_KEY"]
    p = subprocess.run(cmd, env=env, input=ciphertext, capture_output=True, timeout=30)
    check(p.returncode == 0, "DECRYPTION_FAILED")
    return p.stdout


def run_worker(root, database):
    runner = guarded()
    check(re.fullmatch(r"sokna_s7e2b_[0-9a-f]{12}", database) is not None,
          "WORKER_SCHEMA_NOT_SCOPED")
    root = Path(root).resolve(strict=True)
    check(root.parent == runner and root.name.startswith("sokna-s7e2b-") and
          (root / "owner-sentinel").read_text() == database,
          "WORKER_NOT_IN_DISPOSABLE_ROOT")
    sql("ALTER TABLE ledger_fixture ADD COLUMN migration_phase INT NOT NULL DEFAULT 1", database)
    durable(root / "ddl-executed-uncommitted", "DDL done; owner must reconcile DB")
    time.sleep(30)
    raise RuntimeError("S7E2B_WORKER_WAS_NOT_KILLED")


def run():
    runner = guarded()
    root = Path(tempfile.mkdtemp(prefix="sokna-s7e2b-", dir=runner))
    check(root.parent == runner, "WRONG_FIXTURE_ROOT")
    name = "sokna_s7e2b_" + secrets.token_hex(6)
    restored = name + "_restore"
    check(re.fullmatch(r"sokna_s7e2b_[0-9a-f]{12}", name) is not None, "RANDOM_ID_INVALID")
    worker = None
    created = False
    try:
        sql(f"CREATE DATABASE `{name}`; CREATE DATABASE `{restored}`")
        created = True
        sql("CREATE TABLE ledger_fixture (id INT PRIMARY KEY, amount INT NOT NULL) ENGINE=InnoDB; "
            "INSERT INTO ledger_fixture VALUES (1,100),(2,200),(3,300)", name)
        baseline = sql("SELECT COUNT(*),SUM(amount) FROM ledger_fixture", name)
        check(baseline == "3\t600", "BASELINE_MISMATCH")
        args, env = cli()
        dump = subprocess.run(["mariadb-dump", "--protocol=tcp", "-h", "127.0.0.1",
                               "-P", "3306", "-u", "root", "--single-transaction",
                               "--quick", "--skip-lock-tables", name],
                              env=env, capture_output=True, timeout=30)
        check(dump.returncode == 0 and len(dump.stdout) > 100,
              "CONSISTENT_LOGICAL_BACKUP_FAILED")
        digest = hashlib.sha256(dump.stdout).hexdigest()
        key = secrets.token_urlsafe(32)
        mac_key = secrets.token_bytes(32)
        ciphertext = encrypt(dump.stdout, key)
        cipher_file = root / "backup.enc"
        cipher_file.write_bytes(ciphertext)
        mac = hmac.new(mac_key, ciphertext, hashlib.sha256).digest()
        mutated = bytearray(ciphertext)
        mutated[-13] ^= 0x10
        check(not hmac.compare_digest(mac, hmac.new(mac_key, mutated, hashlib.sha256).digest()),
              "TAMPERED_ENCRYPTED_BACKUP_ACCEPTED")
        restored_dump = decrypt(cipher_file.read_bytes(), key)
        check(hashlib.sha256(restored_dump).hexdigest() == digest and
              hmac.compare_digest(mac, hmac.new(mac_key, cipher_file.read_bytes(), hashlib.sha256).digest()),
              "ENCRYPTED_BACKUP_HASH_INVALID")
        # Always restore into a distinct schema, never overwrite forward DB.
        restore_args, restore_env = cli(restored)
        restore_run = subprocess.run(restore_args, env=restore_env, input=restored_dump,
                                     capture_output=True, timeout=30)
        check(restore_run.returncode == 0, "INDEPENDENT_RESTORE_FAILED")
        check(sql("SELECT COUNT(*),SUM(amount) FROM ledger_fixture", restored) == baseline,
              "RESTORED_BASELINE_INVALID")
        # A durable forward-intent is written before the ALTER TABLE. DDL can
        # commit in MariaDB even when the owner dies before writing observed.
        durable(root / "owner-sentinel", name)
        durable(root / "forward-wal", "db_forward_intent")
        worker = subprocess.Popen([sys.executable, str(Path(__file__).resolve()),
                                   "--worker", str(root), name],
                                  stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                  start_new_session=True)
        deadline = time.monotonic() + 15
        while time.monotonic() < deadline and not (root / "ddl-executed-uncommitted").exists():
            check(worker.poll() is None, "MIGRATION_CHILD_DIED_EARLY")
            time.sleep(0.05)
        check((root / "ddl-executed-uncommitted").exists(), "DDL_EXECUTION_TIMEOUT")
        os.killpg(worker.pid, signal.SIGKILL)
        worker.wait(timeout=6)
        worker = None
        check((root / "forward-wal").read_text() == "db_forward_intent", "WAL_FALSE_COMMIT")
        check(sql("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='" +
                  name + "' AND TABLE_NAME='ledger_fixture' AND COLUMN_NAME='migration_phase'") == "1",
              "DDL_EFFECT_NOT_PRESENT_AFTER_CRASH")
        check(sql("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='" +
                  restored + "' AND TABLE_NAME='ledger_fixture' AND COLUMN_NAME='migration_phase'") == "0",
              "BACKUP_SCHEMA_MUTATED")
        # Explicit forward reconciliation; no rollback of already committed DDL.
        durable(root / "forward-wal", "forward_recovery_required")
        sql("INSERT INTO ledger_fixture (id,amount,migration_phase) VALUES (4,400,2)", name)
        check(sql("SELECT COUNT(*),SUM(amount) FROM ledger_fixture", name) == "4\t1000" and
              sql("SELECT COUNT(*),SUM(amount) FROM ledger_fixture", restored) == baseline,
              "FORWARD_RECONCILIATION_OR_RESTORE_ISOLATION_FAILED")
        durable(root / "forward-wal", "db_forward_committed")
        check((root / "forward-wal").read_text() == "db_forward_committed", "WAL_COMMIT_MISSING")
        print(json.dumps({"format": "sokna-f1-s7e2b-mariadb-forward-crash-encrypted-backup-v1",
                          "result": "PASS", "real_mariadb_container": True,
                          "consistent_transactional_dump": True,
                          "encrypted_backup_pbkdf2": True,
                          "encrypt_then_hmac_integrity_verified": True,
                          "tampered_ciphertext_rejected": True,
                          "independent_restore_verified": True,
                          "kill_after_ddl_before_wal_observed": True,
                          "forward_only_reconciliation_proven": True,
                          "source_not_overwritten_by_restore": True,
                          "actual_production_schema_migration_tested": False,
                          "off_host_backup_tested": False, "live_host_uat": False,
                          "public_mutation": False, "automatic_rollback_authorized": False,
                          "panel_update_ready": False}, separators=(",", ":")))
    finally:
        if worker and worker.poll() is None:
            os.killpg(worker.pid, signal.SIGKILL)
            worker.wait(timeout=6)
        if created:
            try:
                sql(f"DROP DATABASE IF EXISTS `{restored}`; DROP DATABASE IF EXISTS `{name}`")
            except Exception:
                print("S7E2B_CLEANUP_DATABASE_ERROR", file=sys.stderr)
        shutil.rmtree(root)


if __name__ == "__main__":
    try:
        if len(sys.argv) == 4 and sys.argv[1] == "--worker":
            run_worker(sys.argv[2], sys.argv[3])
        elif len(sys.argv) == 1:
            run()
        else:
            raise RuntimeError("S7E2B_INVALID_ARGUMENTS")
    except Exception as e:
        print("S7E2B_FAILED: " + str(e), file=sys.stderr)
        sys.exit(1)