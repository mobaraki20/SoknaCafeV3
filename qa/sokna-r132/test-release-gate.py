#!/usr/bin/env python3
"""Synthetic release-archive regressions; no production credentials or payloads."""
import hashlib
import importlib.util
import io
import json
import tempfile
import unittest
import zipfile
from pathlib import Path

HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location("release_gate",HERE/"release-gate.py")
gate=importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)

def archive(path, missing=False, approved=False):
    present={"VERSION.txt":b"0.0.0-qa\n",
             "database/migrations/0001_fake.sql":b"SELECT 1;\n"}
    listed=sorted(present)
    if missing:listed.remove("database/migrations/0001_fake.sql")
    inv=json.dumps({"format":"sokna-local-managed-files-v1","files":sorted(listed+["resources/local-managed-files-v1.json"])},separators=(",",":")).encode()
    present["resources/local-managed-files-v1.json"]=inv
    digest=hashlib.sha256()
    for name in sorted(present):
        digest.update(name.encode()+b"\x00"+hashlib.sha256(present[name]).hexdigest().encode()+b"\n")
    data=io.BytesIO()
    with zipfile.ZipFile(data,"w",compression=zipfile.ZIP_DEFLATED) as web:
        for name,body in present.items():web.writestr(name,body)
    payload=data.getvalue()
    qualification={"failure_matrix_id":"test-only-placeholder","failure_matrix_cases_required":80,
         "real_windows":True,"real_mariadb":True,"canary":True,
         "recovery_console_web_down":True,"business_backup_consistency_real_mariadb":True}
    schema='migration-ledger-sha256-v1:'+hashlib.sha256(('0001_fake\0'+hashlib.sha256(present['database/migrations/0001_fake.sql']).hexdigest()+'\n').encode()).hexdigest()
    manifest={"release_type":"local_only","status":"PRODUCTION_CANDIDATE" if approved else "R130_UAT_NOT_FIELD_APPROVED",
              "for_install":True,"production_pass":approved,
              "artifacts":{"local-web":{"sha256":hashlib.sha256(payload).hexdigest(),"size_bytes":len(payload)}},
              "targets":{"local":{"version":"0.0.0-qa","managed_files":len(present),"managed_tree_sha256":digest.hexdigest(),"migration_head":"0001_fake","schema_fingerprint":schema}},
              "database":{"local":{"migration_dag":["0001_fake"]}},
              "qualification":qualification if approved else {},
              "signature":{"algorithm":"ecdsa-p256-sha256","key_id":"qa","value":"synthetic-not-cryptographically-verified"} if approved else None,
              "pre_update_backup_policy":{"required":True}}
    with zipfile.ZipFile(path,"w",compression=zipfile.ZIP_DEFLATED) as out:
        out.writestr("release-set.json",json.dumps(manifest))
        out.writestr("artifacts/local/web.zip",payload)

class Regression(unittest.TestCase):
    def test_missing_migration_is_rejected(self):
        with tempfile.TemporaryDirectory() as td:
            name=Path(td)/"bad.zip";archive(name,missing=True)
            code,result=gate.examine(name)
            self.assertEqual(code,2)
            self.assertIn("database/migrations/0001_fake.sql",result["missing_inventory_files"])
    def test_valid_but_unapproved_package_is_rejected(self):
        with tempfile.TemporaryDirectory() as td:
            name=Path(td)/"uat.zip";archive(name)
            code,result=gate.examine(name)
            self.assertEqual(code,3)
            self.assertTrue(result["structurally_valid"])
            self.assertFalse(result["deployment_authorized"])
    def test_offline_eligible_is_still_not_authorized(self):
        with tempfile.TemporaryDirectory() as td:
            name=Path(td)/"ok.zip";archive(name,approved=True)
            code,result=gate.examine(name)
            self.assertEqual(code,3)
            self.assertIn("INDEPENDENT_QUALIFICATION_EVIDENCE_MISSING",result["production_failures"])
            self.assertFalse(result["offline_production_fields_valid"])
            self.assertFalse(result["deployment_authorized"])

if __name__=="__main__":unittest.main(verbosity=2)

