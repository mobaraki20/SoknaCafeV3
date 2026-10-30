#!/usr/bin/env python3
"""Regression proofs: UUP-01 must not grant install from self-reported support."""
import gzip
import importlib.util
import io
import json
import tempfile
import unittest
import zipfile
from datetime import datetime, timedelta, timezone
from pathlib import Path

HERE = Path(__file__).resolve().parent
p = HERE / "uup01_standard_support_gate.py"
spec = importlib.util.spec_from_file_location("uup01_gate", p)
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)
H = "a" * 64


def utc(hours=0):
    return (datetime.now(timezone.utc) + timedelta(hours=hours)).isoformat()


class GateTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        root = Path(self.tmp.name)
        self.zip = root / "windows.zip"
        self.pub = root / "public.json"
        self.local = root / "local.json.gz"
        v = "3.0.0-g3.6-workspace.35fh-test"
        self.windows = {"format": "sokna-windows-support-v2", "completeness": "complete",
                        "created_at_utc": utc(), "collection_errors": []}
        self.inventory = {
            "format": "sokna-support-universal-fingerprints-v1",
            "services": [{"component": c, "status": "verified", "sha256": H}
                         for c in ("runtime", "print-agent", "maintenance")],
            "local": {"status": "verified", "matches": [{
                "status": "verified", "version": "3.0.0-g6.2-workspace.231br-test",
                "source": {"status": "verified", "sha256": H}}]}}
        self.public = {
            "format": "sokna-public-operator-support-v1", "generated_at": utc(),
            "system": {"ok": True,
                "health": {"ok": True, "version": v, "pending_migrations": 0,
                           "update": {"active_version": v}},
                "routine_update": {
                    "protocol": 2, "format": "sokna-public-delta-v2",
                    "current_version": v, "active_version": v,
                    "managed_tree_matches_expected": True,
                    "managed_tree_sha256": H,
                    "expected_managed_tree_sha256": H,
                    "db_forward_fence": None, "pending_verification": None,
                    "activation_journal": None, "interrupted_activation": False
                },
                "stable_emergency_owner": {
                    "status": "verified", "files": [
                        {"path": name, "status": "verified", "sha256": H}
                        for name in sorted(gate.EXPECTED_OWNER)]}
            }}
        self.local_doc = {
            "format": "sokna-support-bundle-v1",
            "local_source_identity": {
                "status": "verified", "sha256": H,
                "version": "3.0.0-g6.2-workspace.231br-test",
                "version_coherence": "match"}}
        self.write()

    def write(self, inventory=True):
        with zipfile.ZipFile(self.zip, "w", compression=zipfile.ZIP_DEFLATED) as z:
            z.writestr("support-v2-summary.json", json.dumps(self.windows))
            if inventory:
                z.writestr("windows/universal-source-inventory.json",
                           json.dumps(self.inventory))
        self.pub.write_text(json.dumps(self.public))
        with gzip.open(self.local, "wb") as out:
            out.write(json.dumps(self.local_doc).encode())

    def evaluate(self, inventory=True, include_local=True):
        self.write(inventory)
        return gate.assess(self.zip, self.pub, self.local if include_local else None)

    def assertBlocked(self, expected):
        doc = self.evaluate()
        self.assertIn(expected, doc["blockers"])
        self.assertEqual(doc["decision"], "BLOCK_RELEASE")
        self.assertFalse(doc["installation_authorized"])
        self.assertFalse(doc["host_mutation_performed"])

    def test_best_possible_self_report_still_not_release_permit(self):
        d = self.evaluate()
        self.assertEqual(d["blockers"], ["REAL_MULTI_BASELINE_ACTIVATION_RECOVERY_UAT_NOT_ATTESTED"])

    def test_real_case_emergency_35es_stale_after_35fh(self):
        self.public["system"]["health"]["update"]["active_version"] = "35es"
        self.assertBlocked("PUBLIC_EMERGENCY_ROUTINE_OWNER_DRIFT")

    def test_forward_fence_never_ignored_even_when_no_pending_migration(self):
        self.public["system"]["routine_update"]["db_forward_fence"] = {
            "transaction_id": "prior", "policy": "forward_recovery_only"}
        self.assertBlocked("PUBLIC_DB_FORWARD_RECOVERY_FENCE")

    def test_missing_public_stable_emergency_owner(self):
        self.public["system"].pop("stable_emergency_owner")
        self.assertBlocked("PUBLIC_STABLE_EMERGENCY_OWNER_UNVERIFIED")

    def test_local_version_identity_mismatch(self):
        self.local_doc["local_source_identity"]["version_coherence"] = "mismatch"
        self.assertBlocked("LOCAL_INSTALLED_VERSION_COHERENCE_UNVERIFIED")

    def test_windows_local_support_digest_diverges(self):
        self.local_doc["local_source_identity"]["sha256"] = "b" * 64
        self.assertBlocked("WINDOWS_LOCAL_SUPPORT_IDENTITY_DISAGREES")

    def test_missing_standard_windows_support_inventory(self):
        d = self.evaluate(inventory=False)
        self.assertIn("WINDOWS_STANDARD_SUPPORT_SOURCE_INVENTORY_MISSING", d["blockers"])

    def test_standard_local_support_is_required(self):
        d = self.evaluate(include_local=False)
        self.assertIn("LOCAL_ORDINARY_SUPPORT_NOT_PROVIDED", d["blockers"])

    def test_stale_support_is_not_valid(self):
        self.public["generated_at"] = utc(-150)
        self.assertBlocked("PUBLIC_SUPPORT_NOT_FRESH")

    def test_incomplete_windows_support(self):
        self.windows["completeness"] = "partial"
        self.assertBlocked("WINDOWS_SUPPORT_INCOMPLETE")

    def test_public_lifecycle_pending(self):
        self.public["system"]["routine_update"]["pending_verification"] = {"txn": "example"}
        self.assertBlocked("PUBLIC_LIFECYCLE_IN_PROGRESS")

    def test_corrupted_zip_fails_closed(self):
        self.zip.write_bytes(b"not-a-zip")
        with self.assertRaises(ValueError):
            gate.assess(self.zip, self.pub, self.local)

    def test_no_host_identifiers_appear_in_gate_output(self):
        self.windows["computer_name"] = "MACHINE-NOT-FOR-OUTPUT"
        d = self.evaluate()
        self.assertNotIn("MACHINE-NOT-FOR-OUTPUT", json.dumps(d))


if __name__ == "__main__":
    unittest.main()
