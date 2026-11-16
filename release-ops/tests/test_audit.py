#!/usr/bin/env python3
"""Synthetic negative and positive tests of the SAME release-ops/audit.py shipped to GitHub."""
import hashlib
import io
import json
import pathlib
import sys
import tempfile
import unittest
import zipfile

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1]))
import audit as a


def j(obj):
    return (json.dumps(obj, ensure_ascii=False, sort_keys=True, separators=(",", ":")) + "\n").encode()


def zip_bytes(items):
    b = io.BytesIO()
    with zipfile.ZipFile(b, "w", zipfile.ZIP_DEFLATED) as z:
        for name, data in sorted(items.items()):
            z.writestr(name, data)
    return b.getvalue()


def rows(items):
    return [{"path": k, "sha256": a.sha(v), "size": len(v)} for k, v in sorted(items.items())]


class ReleaseAudit(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.dir = pathlib.Path(self.temp.name)

    def tearDown(self):
        self.temp.cleanup()

    def save(self, blob, name="candidate.zip"):
        p = self.dir / name
        p.write_bytes(blob)
        return p

    def public(self, version="3.0.0-g3.6-workspace.35hz-r258", extra=None, edit=None):
        code = b"<?php final class EmergencyOwnerCandidate {}\n"
        owner = {"release_version": version, "sha256": a.sha(code)}
        files = {
            "VERSION.txt": (version + "\n").encode(),
            "src/Update/EmergencyOwnerCandidate.php": code,
            "src/Update/emergency-owner-v1.json": j(owner),
            "assets/product-ui.css": b"/* active UI */\n",
        }
        if extra:
            files.update(extra)
        m = {"format": "sokna-component-package-v1", "schema_version": 1,
             "component": "public-edge", "version": version,
             "target_managed_tree_sha256": a.tree({k: (a.sha(v), len(v)) for k, v in files.items()}),
             "compatibility": {"supported_baselines": [
                 {"version": "3.0.0-g3.6-workspace.35ht-r171", "managed_tree_sha256": "a" * 64}]},
             "files": rows(files)}
        if edit:
            edit(m, files)
        blob = zip_bytes({"manifest.json": j(m), **{"payload/" + k: v for k, v in files.items()}})
        return self.save(blob)

    def local(self, edit=None, files_edit=None):
        version = "3.0.0-g6.2-workspace.231zz-r258"
        files = {"VERSION.txt": (version + "\n").encode(),
                 "bootstrap.php": b"<?php\n",
                 "public/index.php": b"<?php\n",
                 "resources/local-managed-files-v1.json": b""}
        files["resources/local-managed-files-v1.json"] = j({"format": "sokna-local-managed-files-v1", "files": sorted(files)})
        if files_edit:
            files_edit(files)
        inner = zip_bytes(files)
        t = a.tree({k: (a.sha(v), len(v)) for k, v in files.items()})
        release_id = "sokna-test-release-r258"
        target = dict(zip(a.IDENTITY, [release_id, version, t, "migration-ledger-sha256-v1:some", "0081", "preserved"]))
        target["managed_files"] = len(files)
        base = dict(zip(a.IDENTITY, ["historical-full-r200", "3.0.0.previous", "a" * 64, "schema-proof", "0080", "preserved"]))
        m = {
            "format": "sokna-maintenance-release-set-v2.1", "schema_version": "2.1",
            "release_type": "local_only", "release_set_id": release_id,
            "for_install": False, "production_pass": False, "status": "SOURCE_QA_DO_NOT_INSTALL",
            "targets": {"local": target}, "supported_baselines": [{"local": base}],
            "database": {"local": {"rollback_after_boundary": "forbidden"}},
            "install_plan": [{"phase": "preflight", "mutation": False}, {"phase": "stage", "mutation": False},
                             {"phase": "activate", "mutation": True}],
            "pre_update_backup_policy": {"required": True, "system_recovery_snapshot_required": True,
                                          "on_failure": "reject_pre_mutation"},
            "artifacts": {"local-web": {"path": "artifacts/local/web.zip",
                                        "sha256": a.sha(inner), "size_bytes": len(inner)}}}
        if edit:
            edit(m)
        blob = zip_bytes({"release-set.json": j(m), "artifacts/local/web.zip": inner})
        return self.save(blob)

    def test_valid_public_is_static_only(self):
        report = a.audit(self.public())
        self.assertEqual(report["static"], "PASS", report)
        self.assertEqual(report["kind"], "public-update")
        self.assertFalse(report["release_authorized"])
        self.assertIn("REAL_WINDOWS_OR_PUBLIC_HOST_STAGE_ACTIVATE_READBACK_NOT_RUN_BY_THIS_TOOL", report["not_proven"])

    def test_valid_local_is_static_only(self):
        r = a.audit(self.local())
        self.assertEqual(r["static"], "PASS", r)
        self.assertFalse(r["release_authorized"])
        self.assertIn("LOCAL_NOT_MARKED_INSTALL_QUALIFIED", r["not_proven"])

    def test_wrong_emergency_version(self):
        def edit(m, files):
            d = json.loads(files["src/Update/emergency-owner-v1.json"])
            d["release_version"] = "old-version"
            files["src/Update/emergency-owner-v1.json"] = j(d)
            m["files"] = rows(files)
            m["target_managed_tree_sha256"] = a.tree({k: (a.sha(v), len(v)) for k, v in files.items()})
        r = a.audit(self.public(edit=edit))
        self.assertIn("PUBLIC_EMERGENCY_OWNER_VERSION_OR_HASH_MISMATCH", r["errors"])

    def test_owner_digest_mismatch(self):
        def edit(m, files):
            d = json.loads(files["src/Update/emergency-owner-v1.json"])
            d["sha256"] = "0" * 64
            files["src/Update/emergency-owner-v1.json"] = j(d)
            m["files"] = rows(files)
            m["target_managed_tree_sha256"] = a.tree({k: (a.sha(v), len(v)) for k, v in files.items()})
        self.assertIn("PUBLIC_EMERGENCY_OWNER_VERSION_OR_HASH_MISMATCH", a.audit(self.public(edit=edit))["errors"])

    def test_undeclared_file_hash_corruption(self):
        def edit(m, files):
            m["files"][0]["sha256"] = "0" * 64
        self.assertIn("MANIFEST_FILE_HASH_OR_SIZE_MISMATCH", a.audit(self.public(edit=edit))["errors"])

    def test_protected_public_file_rejected(self):
        p = self.public(extra={"src/Emergency/PublicUpdateService.php": b"<?php\n"})
        self.assertIn("PROTECTED_PUBLIC_FILE_IN_UPDATE", a.audit(p)["errors"])

    def test_from_version_specific_public_rejected(self):
        def edit(m, files):
            m["compatibility"]["from_version"] = "35ht"
        self.assertIn("PUBLIC_ONE_SOURCE_ONLY_COMPATIBILITY", a.audit(self.public(edit=edit))["errors"])

    def test_public_tree_mismatch(self):
        def edit(m, files):
            m["target_managed_tree_sha256"] = "0" * 64
        self.assertIn("PUBLIC_MANAGED_TREE_MISMATCH", a.audit(self.public(edit=edit))["errors"])

    def test_collision_35gg_quarantined(self):
        self.assertIn("KNOWN_35GG_COLLISION_QUARANTINED", a.audit(self.public(version="3.0.0-g3.6-workspace.35gg-r99-hall-ui-scoped-uat"))["errors"])

    def test_missing_r75_delta_baseline_fails_with_catalog(self):
        p = self.public()
        cat = self.dir / "catalog.json"
        cat.write_text(json.dumps({"entries": [
            {"identity": {"version": "3.0.0-g3.6-workspace.35other", "managed_tree_sha256": "b" * 64},
             "origin": "delta", "source_archive_sha256": "c" * 64}
        ]}))
        self.assertIn("VERIFIED_HISTORICAL_BASELINE_NOT_SUPPORTED", a.audit(p, baseline_catalog=cat)["errors"])

    def test_local_wrong_managed_inventory(self):
        def break_inventory(files):
            files["resources/local-managed-files-v1.json"] = j({"files": ["VERSION.txt"]})
        self.assertIn("LOCAL_MANAGED_INVENTORY_MISMATCH", a.audit(self.local(files_edit=break_inventory))["errors"])

    def test_local_wrong_release_id(self):
        def edit(m):
            m["targets"]["local"]["release_id"] = "something-else"
        self.assertIn("LOCAL_TARGET_RELEASE_ID_MISMATCH", a.audit(self.local(edit=edit))["errors"])

    def test_local_backup_missing(self):
        def edit(m):
            m["pre_update_backup_policy"]["required"] = False
        self.assertIn("LOCAL_REQUIRED_BACKUP_POLICY_MISSING", a.audit(self.local(edit=edit))["errors"])

    def test_local_component_update_not_installer_release_set(self):
        b = zip_bytes({"manifest.json": j({"format": "sokna-component-package-v1", "component": "local"}),
                       "payload/VERSION.txt": b"test"})
        r = a.audit(self.save(b), expected_kind="local-update")
        self.assertEqual(r["kind"], "local-legacy-update")
        self.assertIn("PACKAGE_KIND_MISMATCH", r["errors"])
        self.assertIn("LEGACY_LOCAL_COMPONENT_SCHEMA_INVALID", r["errors"])

    def test_corrupted_zip(self):
        r = a.audit(self.save(b"not a zip"))
        self.assertEqual(r["static"], "FAIL")

    def test_wrong_package_sha(self):
        self.assertIn("PINNED_PACKAGE_SHA_MISMATCH", a.audit(self.public(), expected_sha="0" * 64)["errors"])

    def test_duplicate_zip_entry(self):
        b = io.BytesIO()
        import warnings
        with warnings.catch_warnings():
            warnings.simplefilter("ignore", UserWarning)
            with zipfile.ZipFile(b, "w") as z:
                z.writestr("manifest.json", b"{}")
                z.writestr("manifest.json", b"{}")
        self.assertIn("PACKAGE_INSPECTION_ERROR_ValueError", a.audit(self.save(b.getvalue()))["errors"])

    def test_traversal_zip_entry(self):
        b = zip_bytes({"../escape.php": b"<?php", "manifest.json": b"{}"})
        self.assertIn("PACKAGE_INSPECTION_ERROR_ValueError", a.audit(self.save(b))["errors"])

    def test_clean_public_valid_static(self):
        version = "3.0.0-g3.6-workspace.35hz-r258"
        files = {"VERSION.txt": (version + "\n").encode(),
                 "public/index.php": b"<?php echo 'hi';"}
        m = {"format": "sokna-public-deploy-v1", "component": "public-edge",
             "version": version, "files": rows(files)}
        r = a.audit(self.save(zip_bytes({"manifest.json": j(m), **files})))
        self.assertEqual(r["static"], "PASS", r)
        self.assertEqual(r["kind"], "public-clean")

    def test_clean_local_valid_static(self):
        version = "3.0.0-g6.2-workspace.231zz-r258"
        files = {"VERSION.txt": (version + "\n").encode(), "bootstrap.php": b"<?php",
                 "public/index.php": b"<?php", "public/setup/index.php": b"<?php",
                 "public/setup/api.php": b"<?php"}
        m = {"format": "sokna-local-web-clean-install-v1", "component": "local-web",
             "version": version, "files": rows(files)}
        r = a.audit(self.save(zip_bytes({"sokna-install-manifest.json": j(m), **files})))
        self.assertEqual(r["static"], "PASS", r)

    def test_pair_rejects_code_drift(self):
        version = "3.0.0-g3.6-workspace.35hz-r258"
        files = {"VERSION.txt": (version + "\n").encode(),
                 "public/index.php": b"<?php echo 'different';",
                 "assets/product-ui.css": b"/* changed */"}
        m = {"format": "sokna-public-deploy-v1", "component": "public-edge",
             "version": version, "files": rows(files)}
        clean = self.save(zip_bytes({"manifest.json": j(m), **files}), "clean.zip")
        self.assertIn("UPDATE_CLEAN_SHARED_CODE_MISMATCH", a.audit(self.public(), paired=clean)["errors"])

    def test_source_snapshot_mismatch(self):
        source = self.dir / "source"
        source.mkdir()
        (source / "VERSION.txt").write_text("wrong", encoding="utf-8")
        r = a.audit(self.public(), source_dir=source)
        self.assertIn("PACKAGE_DIFFERS_FROM_DECLARED_SOURCE", r["errors"])

    def test_accepted_fix_disappeared(self):
        accepted = self.dir / "accepted.json"
        accepted.write_text(json.dumps({"required_files": [
            {"id": "REG-003", "path": "assets/missing-approved.css", "sha256": "1" * 64}
        ]}))
        r = a.audit(self.public(), accepted=accepted)
        self.assertIn("ACCEPTED_FIX_FILE_MISSING_OR_CHANGED", r["errors"])


    def test_version_registry_rejects_new_colliding_35hz(self):
        p = self.public()
        register = self.dir / "version-registry.json"
        register.write_text(json.dumps({
            "format": "sokna-release-version-ledger-v1", "historical_complete": False,
            "quarantined_short_versions": ["35gg"],
            "released": [{"component": "public", "kind": "public-update",
                          "short_code": "35hz", "version": "3.0.0-g3.6-workspace.35hz-old",
                          "sha256": "f" * 64}]
        }))
        r = a.audit(p, registry=register)
        self.assertIn("RELEASE_VERSION_IDENTITY_COLLISION", r["errors"])

    def test_version_registry_accepts_exact_existing_same_bytes(self):
        p = self.public()
        register = self.dir / "version-registry.json"
        register.write_text(json.dumps({
            "format": "sokna-release-version-ledger-v1", "historical_complete": False,
            "quarantined_short_versions": ["35gg"],
            "released": [{"component": "public", "kind": "public-update",
                          "short_code": "35hz", "version": "3.0.0-g3.6-workspace.35hz-r258",
                          "sha256": a.sha(p.read_bytes())}]
        }))
        r = a.audit(p, registry=register)
        self.assertEqual(r["static"], "PASS", r)
        self.assertIn("HISTORICAL_RELEASE_VERSION_LEDGER_INCOMPLETE", r["not_proven"])

    def test_two_public_routes_must_use_same_zip(self):
        p = self.public()
        evidence = self.dir / "route-evidence.json"
        with zipfile.ZipFile(p) as z:
            baseline = json.loads(z.read("manifest.json"))["compatibility"]["supported_baselines"][0]
        digest = a.sha(p.read_bytes())
        evidence.write_text(json.dumps({
            "format": "sokna-public-two-route-evidence-v1",
            "package_sha256": digest,
            "baselines": [{
                "identity": baseline,
                "routine": {"status": "PASS", "package_sha256": digest,
                            "evidence_url": "https://github.com/mobaraki20/SoknaCafeV3/actions/runs/1"},
                "emergency": {"status": "PASS", "package_sha256": digest,
                              "evidence_url": "https://github.com/mobaraki20/SoknaCafeV3/actions/runs/2"}
            }]
        }))
        r = a.audit(p, routes=evidence)
        self.assertEqual(r["static"], "PASS", r)
        self.assertIn("PUBLIC_ROUTE_DECLARATION_NOT_INDEPENDENT_HOST_PROOF", r["not_proven"])
        data = json.loads(evidence.read_text())
        data["baselines"][0]["emergency"]["package_sha256"] = "0" * 64
        evidence.write_text(json.dumps(data))
        r2 = a.audit(p, routes=evidence)
        self.assertIn("PUBLIC_TWO_ROUTE_EVIDENCE_MISSING_OR_DIVERGENT", r2["errors"])

    def test_public_route_baseline_omission_denied(self):
        p = self.public()
        evidence = self.dir / "route-evidence.json"
        evidence.write_text(json.dumps({
            "format": "sokna-public-two-route-evidence-v1",
            "package_sha256": a.sha(p.read_bytes()), "baselines": []
        }))
        r = a.audit(p, routes=evidence)
        self.assertIn("PUBLIC_TWO_ROUTE_BASELINE_COVERAGE_INCOMPLETE", r["errors"])



    def test_valid_legacy_local_is_not_maintenance_release_set(self):
        v = "3.0.0-g6.2-workspace.231zz-qa"
        files = {"VERSION.txt": (v + "\n").encode(), "bootstrap.php": b"<?php"}
        m = {"format": "sokna-component-package-v1", "component": "local",
             "updater_contract": 2, "upgrade": {"mode": "legacy-full-bridge"},
             "version": v, "files": rows(files)}
        path = self.save(zip_bytes({"manifest.json": j(m),
                                   **{"payload/" + k: x for k, x in files.items()}}))
        r = a.audit(path, expected_kind="local-legacy-update")
        self.assertEqual(r["static"], "PASS", r)
        self.assertIn("LEGACY_LOCAL_NOT_A_MAINTENANCE_RELEASE_SET", r["not_proven"])
        self.assertIn("PACKAGE_KIND_MISMATCH", a.audit(path, expected_kind="local-update")["errors"])

    def test_real_public_flat_root_clean_format(self):
        v = "3.0.0-g3.6-workspace.35hz-qa"
        code = b"<?php class Candidate {}"
        files = {
            ".htaccess": b"RewriteEngine On\n", "VERSION.txt": (v + "\n").encode(),
            "index.php": b"<?php", "bootstrap.php": b"<?php", "setup/index.php": b"<?php",
            "setup/api.php": b"<?php",
            "src/Emergency/PublicUpdateService.php": b"<?php class Stable {}",
            "src/Update/EmergencyOwnerCandidate.php": code,
            "src/Update/emergency-owner-v1.json": j({"release_version": v, "sha256": a.sha(code)}),
        }
        p = self.save(zip_bytes(files))
        r = a.audit(p, expected_kind="public-clean")
        self.assertEqual(r["kind"], "public-clean")
        self.assertEqual(r["static"], "PASS", r)
        self.assertIn("PUBLIC_FLAT_ROOT_CLEAN_HAS_NO_EMBEDDED_SOURCE_MANIFEST", r["not_proven"])

    def test_flat_root_clean_stale_owner_rejected(self):
        v = "3.0.0-g3.6-workspace.35hz-qa"
        code = b"<?php class Candidate {}"
        files = {
            ".htaccess": b"RewriteEngine On\n", "VERSION.txt": (v + "\n").encode(),
            "index.php": b"<?php", "bootstrap.php": b"<?php", "setup/index.php": b"<?php",
            "setup/api.php": b"<?php",
            "src/Emergency/PublicUpdateService.php": b"<?php class Stable {}",
            "src/Update/EmergencyOwnerCandidate.php": code,
            "src/Update/emergency-owner-v1.json": j({
                "release_version": "3.0.0-g3.6-workspace.35hk-OLD",
                "sha256": a.sha(code)
            }),
        }
        r = a.audit(self.save(zip_bytes(files)), expected_kind="public-clean")
        self.assertIn("PUBLIC_CLEAN_EMERGENCY_OWNER_VERSION_OR_HASH_MISMATCH", r["errors"])



    def test_qa_observed_identity_also_prevents_version_collision(self):
        p = self.public()
        register = self.dir / "version-registry.json"
        register.write_text(json.dumps({
            "format": "sokna-release-version-ledger-v1", "historical_complete": False,
            "quarantined_short_versions": ["35gg"], "released": [],
            "observed": [{
                "component": "public", "kind": "public-update",
                "short_code": "35hz", "version": "3.0.0-g3.6-workspace.35hz-r258",
                "sha256": "0" * 64, "status": "QA_ONLY"
            }]
        }))
        r = a.audit(p, registry=register)
        self.assertIn("RELEASE_VERSION_IDENTITY_COLLISION", r["errors"])


if __name__ == "__main__":
    unittest.main(verbosity=2)
