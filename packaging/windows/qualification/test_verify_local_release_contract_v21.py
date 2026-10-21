#!/usr/bin/env python3
"""Source-only regression tests: never create signing keys or install packages."""
import hashlib
import importlib.util
import io
import json
import tempfile
import unittest
import zipfile
from copy import deepcopy
from pathlib import Path

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("contract", HERE / "verify-local-release-contract-v21.py")
guard = importlib.util.module_from_spec(spec)
spec.loader.exec_module(guard)

def digest(blob):
    return hashlib.sha256(blob).hexdigest()

def fixture(manifest_change=None, payload_change=None, extra=False):
    files = {"VERSION.txt": b"3.0.0.test2\n",
             "database/migrations/001.sql": b"-- same schema\n"}
    if payload_change:
        payload_change(files)
    b = io.BytesIO()
    with zipfile.ZipFile(b, "w", zipfile.ZIP_DEFLATED) as z:
        for name, blob in files.items():
            z.writestr(name, blob)
    web = b.getvalue()
    tree = digest(b"".join((p + "\0" + digest(files[p]) + "\n").encode()
                           for p in sorted(files)))
    identity = {
        "release_id": "sample", "version": "3.0.0.test2",
        "managed_tree_sha256": tree, "schema_fingerprint": "known-db",
        "migration_head": "001", "authority_epoch_policy": "preserved"
    }
    manifest = {
        "format": "sokna-maintenance-release-set-v2.1",
        "schema_version": "2.1", "release_type": "local_only",
        "supported_baselines": [{"local": dict(identity, version="3.0.0.test1")}],
        "targets": {"local": dict(identity, managed_files=len(files))},
        "artifacts": {"local-web": {
            "component": "local-web", "kind": "web-tree-zip-v1",
            "path": "artifacts/local/web.zip", "sha256": digest(web),
            "size_bytes": len(web)}},
        "for_install": False, "production_pass": False, "signature": {"value": ""}
    }
    if manifest_change:
        manifest_change(manifest)
    archive = io.BytesIO()
    with zipfile.ZipFile(archive, "w", zipfile.ZIP_DEFLATED) as z:
        z.writestr("release-set-template-UNQUALIFIED.json", json.dumps(manifest))
        z.writestr("artifacts/local/web.zip", web)
        if extra:
            z.writestr("README.md", "engineering fixture")
    return archive.getvalue()

class ContractTests(unittest.TestCase):
    def inspect(self, blob, preparation=True):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "set.zip"
            path.write_bytes(blob)
            return guard.inspect(path, preparation=preparation)

    def test_valid_source_fixture_is_only_structural(self):
        self.assertEqual(self.inspect(fixture()), [])

    def test_missing_target_count(self):
        codes = self.inspect(fixture(lambda m: m["targets"]["local"].pop("managed_files")))
        self.assertIn("SIGNED_TARGET_MANAGED_FILES_MISSING_OR_WRONG", codes)

    def test_wrong_target_count(self):
        codes = self.inspect(fixture(lambda m: m["targets"]["local"].update(managed_files=1)))
        self.assertIn("SIGNED_TARGET_MANAGED_FILES_MISSING_OR_WRONG", codes)

    def test_wrong_target_hash(self):
        codes = self.inspect(fixture(lambda m: m["targets"]["local"].update(managed_tree_sha256="0"*64)))
        self.assertIn("SIGNED_TARGET_TREE_MISMATCH", codes)

    def test_wrong_target_version(self):
        codes = self.inspect(fixture(lambda m: m["targets"]["local"].update(version="3.0.0.other")))
        self.assertIn("SIGNED_TARGET_VERSION_MISMATCH", codes)

    def test_unresolved_identity_placeholder(self):
        codes = self.inspect(fixture(lambda m: m["supported_baselines"][0]["local"].update(
            migration_head="__READ_TRUSTED_CAFE_IDENTITY__")))
        self.assertIn("UNRESOLVED_IDENTITY_MIGRATION_HEAD", codes)

    def test_nested_artifact_hash(self):
        codes = self.inspect(fixture(lambda m: m["artifacts"]["local-web"].update(sha256="0"*64)))
        self.assertIn("ARTIFACT_HASH_OR_SIZE_INVALID", codes)

    def test_wrong_release_type(self):
        codes = self.inspect(fixture(lambda m: m.update(release_type="paired")))
        self.assertIn("NOT_LOCAL_ONLY", codes)

    def test_not_installable_without_signed_manifest(self):
        self.assertIn("MANIFEST_MISSING_OR_DUPLICATE", self.inspect(fixture(), preparation=False))

if __name__ == "__main__":
    unittest.main()
