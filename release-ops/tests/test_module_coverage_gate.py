"""Negative tests: any new or removed source module MUST receive an audit owner."""
from __future__ import annotations
import io
import json
import pathlib
import sys
import tempfile
import unittest
import zipfile

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1]))
from module_coverage_gate import inspect


def pack(files):
    out=io.BytesIO()
    with zipfile.ZipFile(out,"w",zipfile.ZIP_DEFLATED) as z:
        for name in sorted(files):
            z.writestr(name,files[name])
    return out.getvalue()


class ModuleRiskMapTest(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        p=pathlib.Path(self.tmp.name)
        self.source=p/"source.zip"
        self.map=p/"map.json"
        self.register=p/"register.json"
        self.map.write_text(json.dumps({
            "format":"sokna-module-coverage-map-v1",
            "local_src":{"Core":["DB-01"],"Domain":["DB-01"]},
            "local_domain":{"Orders":["FIN-01"]},
            "public_src":{"Core":["DB-01"],"Update":["PKG-01"]}
        }))
        self.register.write_text(json.dumps({
            "rows":[{"id":"DB-01"},{"id":"FIN-01"},{"id":"PKG-01"}]
        }))
        self.write({"src/Core/Migrations.php":b"<?php","src/Domain/Orders/OrderCommitService.php":b"<?php"})

    def tearDown(self):
        self.tmp.cleanup()

    def write(self,files):
        self.source.write_bytes(pack(files))

    def test_local_full_directory_map_can_pass_inventory_only(self):
        r=inspect(self.source,"local",self.map,self.register)
        self.assertEqual(r["status"],"PASS_INVENTORY_ONLY",r)
        self.assertFalse(r["business_behavior_qualified"])
        self.assertFalse(r["production_authorized"])

    def test_new_local_domain_blocks_without_review(self):
        self.write({
            "src/Core/Migrations.php":b"<?php",
            "src/Domain/Orders/OrderCommitService.php":b"<?php",
            "src/Domain/NewCriticalSubsystem/Important.php":b"<?php"})
        r=inspect(self.source,"local",self.map,self.register)
        self.assertEqual(r["status"],"FAIL")
        self.assertIn("src/Domain/NewCriticalSubsystem",r["unexpected"])

    def test_new_public_top_level_module_blocks_without_review(self):
        self.write({"src/Core/Migrations.php":b"<?php",
                    "src/Update/RoutinePublicUpdateService.php":b"<?php",
                    "src/Remote/UnhandledNewOwner.php":b"<?php"})
        r=inspect(self.source,"public",self.map,self.register)
        self.assertEqual(r["status"],"FAIL")
        self.assertIn("src/Remote",r["unexpected"])

    def test_removed_approved_module_requires_audit(self):
        self.write({"src/Core/Migrations.php":b"<?php"})
        r=inspect(self.source,"local",self.map,self.register)
        self.assertEqual(r["status"],"FAIL")
        self.assertIn("src/Domain",r["missing"])
        self.assertIn("src/Domain/Orders",r["missing"])

    def test_bad_risk_id_ref_fails_closed(self):
        mapping=json.loads(self.map.read_text())
        mapping["local_domain"]["Orders"]=["UNKNOWN-99"]
        self.map.write_text(json.dumps(mapping))
        r=inspect(self.source,"local",self.map,self.register)
        self.assertEqual(r["status"],"FAIL")
        self.assertIn("Orders:UNKNOWN-99",r["bad_references"])


if __name__=="__main__":
    unittest.main(verbosity=2)
