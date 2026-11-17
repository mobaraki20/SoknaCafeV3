"""Fail-closed release factory evidence regression tests (never publishes)."""
from __future__ import annotations
import json
import pathlib
import sys
import tempfile
import unittest

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1]))
from factory_decision import EXPECTED_SHA, verify


class FactoryEvidenceTest(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.base=pathlib.Path(self.tmp.name)
        self.reports=self.base/"reports"
        self.reports.mkdir()
        self.active=self.base/"active.json"
        self.coverage=self.base/"matrix.json"
        self.save(self.active,{
            "format":"sokna-release-active-source-v1",
            "active_checkpoint":"R233-20261115",
            "components":{k:{"sha256":v} for k,v in EXPECTED_SHA.items()},
        })
        self.save(self.coverage,{
            "schema":"sokna-independent-release-coverage-v1",
            "rows":[{"id":f"QA-{i:03}", "priority":"P0",
                     "installed_accepted":False} for i in range(72)]
        })
        for component in ("local","public"):
            self.save(self.reports/("SOKNA-CURRENT-SOURCE-INTAKE-QA-REPORT-ONLY-"+component)/"source-intake.json",{
                "checkpoint":"R233-20261115","component":component,
                "source_sha256":EXPECTED_SHA[component], "source_status":"PASS",
                "database_status":"PASS","production_authorized":False,"package_constructed":False
            })
        self.local=self.reports/"SOKNA-R233-LOCAL-MAINTENANCE-QA-REPORT-ONLY"/"local-static.json"
        self.public=self.reports/"SOKNA-R233-PUBLIC-TWO-ROUTE-QA-REPORT-ONLY"/"public-static.json"
        self.database=self.reports/"SOKNA-R233-DB-RELEASE-QA-REPORT-ONLY"/"release-boundaries.json"
        self.save(self.local,{
            "source_zip_sha256":EXPECTED_SHA["local"],
            "candidate_zip_sha256":"a"*64,
            "local_static":"PASS","functional_byte_changes":0,
            "production_installable":False,"native_windows_qualification":"NOT_RUN"
        })
        self.save(self.public,{
            "source_sha256":EXPECTED_SHA["public"],"public_update_sha256":"b"*64,
            "public_clean_sha256":"c"*64,"update_static":"PASS","clean_static":"PASS",
            "functional_byte_drift":0,
            "same_zip_native_php_route_baseline_combinations":4,
            "both_routes_native_php":"PASS_DISPOSABLE_R153_35HK_AND_R171_35HT",
            "release_authorized":False,"production_installable":False
        })
        self.save(self.database,{
            "source":"R233-20261115","db_service":"MariaDB 11.4 disposable only",
            "live_database_modified":False,"release_authorized":False,
            "public_owner_migration_reapply":"PASS_DISPOSABLE_R233_CORE_MIGRATOR_NOOP",
            "public_routine_emergency_with_real_db":"PASS_DISPOSABLE_TWO_BASELINES_FOUR_ROUTES"
        })

    def tearDown(self):
        self.tmp.cleanup()

    @staticmethod
    def save(path,data):
        path.parent.mkdir(parents=True,exist_ok=True)
        path.write_text(json.dumps(data))

    def check(self,checkpoint="R233-20261115"):
        return verify(self.reports,self.active,self.coverage,checkpoint)

    def test_all_qa_pass_still_blocks_real_install(self):
        result=self.check()
        self.assertEqual(result["classification"],"QA_PASS_EXTERNAL_HOST_ACCEPTANCE_BLOCKED",result)
        self.assertFalse(result["production_authorized"])
        self.assertFalse(result["operator_package_created"])
        self.assertGreaterEqual(len(result["applicable_p0_unapproved"]),72)
        self.assertIn("public_https_host",result["host_evidence"])

    def test_factory_attribution_is_scoped_to_own_pinned_qa_run(self):
        report=verify(self.reports,self.active,self.coverage,
                      "R233-20261115","44025122333")
        self.assertTrue(report["factory_workflow_executed"])
        self.assertEqual(report["factory_workflow_run_id"],"44025122333")
        self.assertEqual(report["factory_scope"],"PINNED_HISTORICAL_R233_QA_ONLY")
        self.assertFalse(report["qualified_external_public_candidate"])
        self.assertFalse(report["production_authorized"])

    def test_missing_real_db_proof_blocks(self):
        self.database.unlink()
        result=self.check()
        self.assertEqual(result["classification"],"BLOCKED_MISSING_OR_FAILED_QA")
        self.assertTrue(result["errors"])

    def test_wrong_artifact_sha_blocks(self):
        obj=json.loads(self.local.read_text())
        obj["source_zip_sha256"]="f"*64
        self.save(self.local,obj)
        self.assertIn("LOCAL_QA_FORMAT_OR_SAFETY_FAIL",self.check()["errors"])

    def test_unsanctioned_installable_qa_flag_blocks(self):
        obj=json.loads(self.public.read_text())
        obj["production_installable"]=True
        self.save(self.public,obj)
        self.assertIn("PUBLIC_QA_SAME_ZIP_OR_SAFETY_FAIL",self.check()["errors"])

    def test_new_source_never_inherits_old_r233_qa(self):
        active=json.loads(self.active.read_text())
        active["active_checkpoint"]="R265-new"
        self.save(self.active,active)
        result=self.check("R265-new")
        self.assertEqual(result["classification"],"BLOCKED_MISSING_OR_FAILED_QA")
        self.assertTrue(any("HISTORICAL_R233_QA_BUILDER" in x for x in result["errors"]))

    def test_bogus_coverage_cannot_pass(self):
        obj=json.loads(self.coverage.read_text())
        obj["rows"]=obj["rows"][:2]
        self.save(self.coverage,obj)
        self.assertEqual(self.check()["classification"],"BLOCKED_MISSING_OR_FAILED_QA")

    def test_partial_public_same_zip_route_is_not_success(self):
        obj=json.loads(self.public.read_text())
        obj["same_zip_native_php_route_baseline_combinations"]=3
        self.save(self.public,obj)
        self.assertIn("PUBLIC_QA_SAME_ZIP_OR_SAFETY_FAIL",self.check()["errors"])


if __name__=="__main__":
    unittest.main(verbosity=2)
