"""Synthetic source admission: original VERSION can be older than frozen R233 source."""
from __future__ import annotations

import hashlib
import io
import json
import pathlib
import sys
import tempfile
import unittest
import zipfile

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1]))
import source_gate as g
from audit import sha


def zip_bytes(files):
    b = io.BytesIO()
    with zipfile.ZipFile(b, "w", compression=zipfile.ZIP_DEFLATED) as z:
        for name, data in sorted(files.items()):
            z.writestr(name, data)
    return b.getvalue()


def canonical(x):
    return (json.dumps(x, sort_keys=True, ensure_ascii=False, separators=(",", ":")) + "\n").encode()


class SourceGate(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.base = pathlib.Path(self.temp.name)
        self.local = {
            "VERSION.txt": b"old-local-v1\n",
            "bootstrap.php": b"<?php\n",
            "database/migrations/0001_m2.sql": b"CREATE TABLE test (id INT);\n",
            "public/index.php": b"<?php echo 'app';\n",
            "resources/local-managed-files-v1.json": b"",
        }
        self.local["resources/local-managed-files-v1.json"] = canonical({
            "format": "sokna-local-managed-files-v1",
            "files": sorted(self.local),
        })
        self.public = {
            ".htaccess": b"RewriteEngine On\n",
            "VERSION.txt": b"old-public-v1\n",
            "bootstrap.php": b"<?php\n",
            "index.php": b"<?php echo 'public';\n",
            "setup/index.php": b"<?php\n",
            "database/migrations/0001_p.sql": b"SELECT 1;\n",
            "src/Update/EmergencyOwnerCandidate.php": b"<?php class Dummy {}\n",
            "src/Update/emergency-owner-v1.json": b"",
            "src/Emergency/PublicUpdateService.php": b"<?php class Emergency {}\n",
        }
        self.public["src/Update/emergency-owner-v1.json"] = canonical({
            "release_version": "much-older-35hk",
            "sha256": sha(self.public["src/Update/EmergencyOwnerCandidate.php"]),
        })

    def tearDown(self):
        self.temp.cleanup()

    def pin(self, comp, files):
        packed = zip_bytes(files)
        src = self.base / (comp + ".zip")
        src.write_bytes(packed)
        record = {
            "zip_sha256": sha(packed),
            "source_tree_sha256": g.source_tree({n: (sha(b), len(b)) for n, b in files.items()}),
            "file_count": len(files),
            "original_version": files["VERSION.txt"].decode().strip(),
            "migration_count": len([p for p in files if p.startswith("database/migrations/")]),
        }
        register = {"format": "sokna-release-source-register-v1", "checkpoints": {
            "T": {"status": "SOURCE_ONLY_NOT_INSTALLABLE", "sources": {comp: record}}
        }}
        reg = self.base / "register.json"
        reg.write_text(json.dumps(register, ensure_ascii=False), encoding="utf-8")
        return src, reg

    def test_local_source_pinned_pass_and_no_release_authorization(self):
        p, reg = self.pin("local", self.local)
        r = g.inspect(p, "T", "local", reg)
        self.assertEqual(r["source_integrity"], "PASS", r)
        self.assertEqual(r["release_blockers"], [])
        self.assertFalse(r["release_authorized"])

    def test_public_source_old_emergency_owner_is_not_release_ready(self):
        p, reg = self.pin("public", self.public)
        r = g.inspect(p, "T", "public", reg)
        self.assertEqual(r["source_integrity"], "PASS")
        self.assertIn("PUBLIC_RELEASE_OWNER_VERSION_STAMP_REQUIRED", r["release_blockers"])

    def test_r233_style_missing_3_inventory_paths_reported(self):
        data = dict(self.local)
        extra = {f"src/NewFeature/{i}.php": b"<?php echo 'new';\n" for i in range(3)}
        data.update(extra)
        p, reg = self.pin("local", data)
        r = g.inspect(p, "T", "local", reg)
        self.assertEqual(r["source_integrity"], "PASS")
        self.assertEqual(len(r["inventory_missing"]), 3)
        self.assertIn("LOCAL_MAINTENANCE_TARGET_INVENTORY_INCOMPLETE", r["release_blockers"])

    def test_corrupt_pinned_archive_is_rejected(self):
        p, reg = self.pin("local", self.local)
        p.write_bytes(p.read_bytes() + b"tamper")
        r = g.inspect(p, "T", "local", reg)
        self.assertEqual(r["source_integrity"], "FAIL")
        self.assertIn("SOURCE_ZIP_SHA256_NOT_PINNED", r["errors"])

    def test_original_version_mismatch_is_rejected(self):
        p, reg = self.pin("public", self.public)
        data = json.loads(reg.read_text())
        data["checkpoints"]["T"]["sources"]["public"]["original_version"] = "fake-future"
        reg.write_text(json.dumps(data))
        self.assertIn("SOURCE_ORIGINAL_VERSION_CHANGED",
                      g.inspect(p, "T", "public", reg)["errors"])

    def test_public_flat_clean_version_stamp_only(self):
        src, reg = self.pin("public", self.public)
        target = dict(self.public)
        target["VERSION.txt"] = b"new-public-v2\n"
        target["src/Update/emergency-owner-v1.json"] = canonical({
            "release_version": "new-public-v2",
            "sha256": sha(target["src/Update/EmergencyOwnerCandidate.php"])
        })
        final = self.base / "public-clean.zip"
        final.write_bytes(zip_bytes(target))
        r = g.inspect(src, "T", "public", reg, final, "public-root-clean")
        self.assertEqual(r["source_integrity"], "PASS", r)
        self.assertEqual(r["candidate_comparison"]["functional_drift_count"], 0)

    def test_public_flat_clean_code_drift_is_rejected(self):
        src, reg = self.pin("public", self.public)
        target = dict(self.public)
        target["VERSION.txt"] = b"new-public-v2\n"
        target["index.php"] = b"<?php echo 'regressed';"
        final = self.base / "public-clean.zip"
        final.write_bytes(zip_bytes(target))
        r = g.inspect(src, "T", "public", reg, final, "public-root-clean")
        self.assertIn("SOURCE_TO_CANDIDATE_FUNCTIONAL_BYTE_DRIFT", r["errors"])

    def test_local_component_is_not_maintenance_release_set(self):
        src, reg = self.pin("local", self.local)
        target = dict(self.local)
        target["VERSION.txt"] = b"new-local-v2\n"
        m = {"component": "local", "format": "sokna-component-package-v1",
             "version": "new-local-v2"}
        final = self.base / "legacy.zip"
        final.write_bytes(zip_bytes({"manifest.json": canonical(m),
                                     **{"payload/" + name: b for name, b in target.items()}}))
        right = g.inspect(src, "T", "local", reg, final, "local-legacy-update")
        self.assertEqual(right["source_integrity"], "PASS", right)
        wrong = g.inspect(src, "T", "local", reg, final, "local-maintenance-update")
        self.assertIn("CANDIDATE_MAINTENANCE_RELEASE_SET_REQUIRED", wrong["errors"])

    def test_public_routine_managed_exclusions(self):
        src, reg = self.pin("public", self.public)
        target = {n: b for n, b in self.public.items() if not n.startswith("src/Emergency/")}
        target["VERSION.txt"] = b"new-public-v2\n"
        target["src/Update/emergency-owner-v1.json"] = canonical({
            "release_version": "new-public-v2",
            "sha256": sha(target["src/Update/EmergencyOwnerCandidate.php"]),
        })
        manifest = {"format": "sokna-component-package-v1", "component": "public-edge",
                    "version": "new-public-v2"}
        final = self.base / "public-update.zip"
        final.write_bytes(zip_bytes({"manifest.json": canonical(manifest),
                                      **{"payload/" + k: v for k, v in target.items()}}))
        r = g.inspect(src, "T", "public", reg, final, "public-update")
        self.assertEqual(r["source_integrity"], "PASS", r)



    def test_slot_managed_candidate_must_rebuild_exact_593_file_inventory(self):
        source_files = dict(self.local)
        for i in range(3):
            source_files[f"src/NewFeature/{i}.php"] = b"<?php echo 'new';"
        src, reg = self.pin("local", source_files)
        target = dict(source_files)
        target["VERSION.txt"] = b"new-local-v2\n"
        target["resources/local-managed-files-v1.json"] = canonical({
            "format": "sokna-local-managed-files-v1", "files": sorted(target)
        })
        inner = zip_bytes(target)
        final = self.base / "slot-release.zip"
        final.write_bytes(zip_bytes({
            "release-set.json": canonical({"format": "sokna-maintenance-release-set-v2.1"}),
            "artifacts/local/web.zip": inner,
        }))
        r = g.inspect(src, "T", "local", reg, final, "local-maintenance-update")
        self.assertEqual(r["source_integrity"], "PASS", r)
        self.assertNotIn("LOCAL_MAINTENANCE_TARGET_INVENTORY_INCOMPLETE", r["release_blockers"])
        self.assertEqual(r["candidate_comparison"]["functional_drift_count"], 0)
        broken = dict(target)
        broken["resources/local-managed-files-v1.json"] = source_files["resources/local-managed-files-v1.json"]
        final.write_bytes(zip_bytes({"release-set.json": b"{}",
                                    "artifacts/local/web.zip": zip_bytes(broken)}))
        r2 = g.inspect(src, "T", "local", reg, final, "local-maintenance-update")
        self.assertIn("MAINTENANCE_CANDIDATE_INVENTORY_MISMATCH", r2["errors"])

    def test_public_candidate_owner_must_be_restamped(self):
        src, reg = self.pin("public", self.public)
        final = self.base / "bad-clean.zip"
        target = dict(self.public)
        target["VERSION.txt"] = b"new-public-v2\n"
        final.write_bytes(zip_bytes(target))
        r = g.inspect(src, "T", "public", reg, final, "public-root-clean")
        self.assertIn("CANDIDATE_PUBLIC_OWNER_RELEASE_IDENTITY_STALE", r["errors"])


if __name__ == "__main__":
    unittest.main(verbosity=2)
