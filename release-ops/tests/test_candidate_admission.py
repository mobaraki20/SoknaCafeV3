"""Candidate gate: real ZIP vs claimed source/run, no false install/Factory proof."""
import hashlib
import io
import json
import pathlib
import sys
import tempfile
import unittest
import zipfile

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1]))
from candidate_admission import inspect
from audit import sha, tree


def enc(x):
    return (json.dumps(x, sort_keys=True, separators=(",", ":"))+"\n").encode()


def zip_bytes(items):
    raw=io.BytesIO()
    with zipfile.ZipFile(raw,"w",zipfile.ZIP_DEFLATED) as z:
        for k,v in sorted(items.items()):
            z.writestr(k,v)
    return raw.getvalue()


class CandidateIntakeTest(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory()
        self.root=pathlib.Path(self.temp.name)
        self.artifacts=self.root/"original-producer-zip"
        self.artifacts.mkdir()
        self.path=self.root/"contract.json"
        self.version="3.0.0-g3.6-workspace.35zz-r999-static-qa"
        code=b"<?php final class EmergencyOwnerCandidate {}\n"
        files={
            "VERSION.txt":(self.version+"\n").encode(),
            "src/Update/EmergencyOwnerCandidate.php":code,
            "src/Update/emergency-owner-v1.json":
                enc({"release_version":self.version,"sha256":sha(code)}),
            "assets/product-ui.css":b"/* test */",
        }
        m={"format":"sokna-component-package-v1","schema_version":1,
           "component":"public-edge","version":self.version,
           "target_managed_tree_sha256":tree({k:(sha(v),len(v)) for k,v in files.items()}),
           "compatibility":{"supported_baselines":[{"version":"3.0.0-old",
                                                      "managed_tree_sha256":"a"*64}]},
           "files":[{"path":k,"sha256":sha(v),"size":len(v)} for k,v in sorted(files.items())]}
        self.package=self.artifacts/"public-new.zip"
        self.package.write_bytes(zip_bytes({
            "manifest.json":enc(m), **{"payload/"+k:v for k,v in files.items()}
        }))
        self.contract={
            "schema":"sokna-candidate-contract-v1",
            "producer":{"run_id":123456,"artifact":"PUBLIC-NEW-ARTIFACT"},
            "component":"public","source_authority":{
                "approved_checkpoint":"workspace-r999-reviewed-by-owner",
                "source_sha256":"b"*64},
            "packages":[{"file":self.package.name,"sha256":sha(self.package.read_bytes()),
                         "component":"public","kind":"public-update","version":self.version}],
            "production_authorized":False,
        }
        self.write()

    def tearDown(self):
        self.temp.cleanup()

    def write(self):
        self.path.write_text(json.dumps(self.contract))

    def check(self,run="123456",artifact="PUBLIC-NEW-ARTIFACT",gate="123456"):
        return inspect(self.path,self.artifacts,run,artifact,gate)

    def test_valid_generic_candidate_is_only_static_qa_and_host_blocked(self):
        r=self.check()
        self.assertEqual(r["status"],"QA_EXACT_BYTES_PASS_FIELD_AND_SOURCE_APPROVAL_BLOCKED",r)
        self.assertTrue(r["factory_validated_exact_bytes"])
        self.assertTrue(r["built_inside_factory"])
        self.assertFalse(r["production_authorized"])
        self.assertFalse(r["source_approved"])
        self.assertEqual(r["database_migration_path"],"NOT_PROVEN_BY_STATIC_AUDIT")

    def test_external_producer_can_pass_shared_gate_but_not_be_called_built_inside(self):
        r=self.check(gate="77777")
        self.assertTrue(r["factory_gate_used"])
        self.assertTrue(r["factory_validated_exact_bytes"])
        self.assertFalse(r["built_inside_factory"])

    def test_wrong_sha_refused(self):
        self.contract["packages"][0]["sha256"]="0"*64
        self.write()
        r=self.check()
        self.assertFalse(r["factory_validated_exact_bytes"])
        self.assertIn("EXACT_ZIP_SHA_MISMATCH",r["errors"][0])

    def test_mismatched_producer_run_id_refused(self):
        r=self.check(run="123457")
        self.assertIn("PRODUCER_RUN_ID_NOT_PINNED",r["errors"][0])

    def test_mismatched_artifact_name_refused(self):
        r=self.check(artifact="OTHER-ARTIFACT")
        self.assertIn("ARTIFACT_NAME_NOT_PINNED",r["errors"][0])

    def test_extra_file_in_original_producer_artifact_refused(self):
        (self.artifacts/"other.zip").write_bytes(b"do not silently add")
        self.assertIn("ARTIFACT_CONTAINS_MISSING_OR_UNDECLARED_FILES",self.check()["errors"][0])

    def test_missing_approved_source_checkpoint_refused(self):
        self.contract["source_authority"]["approved_checkpoint"]=""
        self.write()
        self.assertIn("SOURCE_APPROVAL_CHECKPOINT_REQUIRED",self.check()["errors"][0])

    def test_claimed_production_authorization_refused(self):
        self.contract["production_authorized"]=True
        self.write()
        self.assertIn("UNSUPPORTED_PRODUCTION_AUTHORIZATION",self.check()["errors"][0])

    def test_illegal_nested_filename_refused(self):
        self.contract["packages"][0]["file"]="../outside.zip"
        self.write()
        self.assertIn("INVALID_PACKAGE_LINE",self.check()["errors"][0])

    def test_same_run_post_build_manifest_allows_future_producer_without_prepinned_sha(self):
        self.contract["producer"]["run_id"]="same_run"
        self.contract["producer"]["artifact"]="PUBLIC-NEW-ARTIFACT"
        embedded=self.artifacts/"candidate-contract.json"
        embedded.write_text(json.dumps(self.contract))
        report=inspect(embedded,self.artifacts,"123456","PUBLIC-NEW-ARTIFACT","123456")
        self.assertEqual(report["status"],"QA_EXACT_BYTES_PASS_FIELD_AND_SOURCE_APPROVAL_BLOCKED",report)
        self.assertTrue(report["factory_validated_exact_bytes"])
        self.assertTrue(report["built_inside_factory"])
        self.assertFalse(report["source_approved"])
        self.assertFalse(report["production_authorized"])

    def test_same_run_manifest_from_other_run_fails_closed(self):
        self.contract["producer"]["run_id"]="same_run"
        embedded=self.artifacts/"candidate-contract.json"
        embedded.write_text(json.dumps(self.contract))
        report=inspect(embedded,self.artifacts,"123456","PUBLIC-NEW-ARTIFACT","78910")
        self.assertIn("PRODUCER_RUN_ID_NOT_PINNED_BY_CONTRACT",report["errors"][0])
        self.assertFalse(report["factory_validated_exact_bytes"])

    def test_embedded_fake_filename_cannot_bypass_extra_file_check(self):
        self.contract["producer"]["run_id"]="same_run"
        embedded=self.artifacts/"unregistered-contract.json"
        embedded.write_text(json.dumps(self.contract))
        report=inspect(embedded,self.artifacts,"123456","PUBLIC-NEW-ARTIFACT","123456")
        self.assertIn("UNTRUSTED_EMBEDDED_CONTRACT",report["errors"][0])

    def test_same_run_can_carry_sha_pinned_qa_evidence_manifest(self):
        self.contract["producer"]["run_id"]="same_run"
        evidence=self.artifacts/"qa-evidence.json"
        evidence.write_text('{"status":"QA_ONLY"}')
        self.contract["supporting_files"]=[{
            "file":evidence.name,"sha256":sha(evidence.read_bytes())
        }]
        embedded=self.artifacts/"candidate-contract.json"
        embedded.write_text(json.dumps(self.contract))
        result=inspect(embedded,self.artifacts,"123456","PUBLIC-NEW-ARTIFACT","123456")
        self.assertTrue(result["factory_validated_exact_bytes"],result)
        self.assertEqual(result["supporting_file_sha256"],[sha(evidence.read_bytes())])

    def test_modified_supporting_manifest_blocks_gate(self):
        evidence=self.artifacts/"qa-evidence.json"
        evidence.write_text('{"status":"QA_ONLY"}')
        self.contract["supporting_files"]=[{"file":evidence.name,"sha256":"0"*64}]
        self.write()
        self.assertIn("SUPPORTING_FILE_SHA_MISMATCH",self.check()["errors"][0])

    def test_undeclared_evidence_manifest_does_not_sneak_into_zip_artifact(self):
        (self.artifacts/"other-evidence.json").write_text('{"status":"FORGED_PASS"}')
        self.assertIn("ARTIFACT_CONTAINS_MISSING_OR_UNDECLARED_FILES",self.check()["errors"][0])

    def test_executable_cannot_be_rebranded_as_supporting_file(self):
        evidence=self.artifacts/"installer.exe"
        evidence.write_bytes(b"MZ")
        self.contract["supporting_files"]=[{"file":evidence.name,"sha256":sha(evidence.read_bytes())}]
        self.write()
        self.assertIn("SUPPORTING_FILES_MUST_BE_SHA_PINNED_SMALL_TEXT_ONLY",self.check()["errors"][0])

    def test_legacy_requires_actual_pinned_zip_and_cannot_fake_green(self):
        historic=pathlib.Path(__file__).resolve().parents[1]/"external-public-r268-provenance.json"
        r=inspect(historic,self.artifacts,"44025587468",
                  "R267-EMERGENCY-SCHEMA-QA-NO-HOST-DEPLOY","9999999")
        self.assertFalse(r["factory_validated_exact_bytes"])
        self.assertFalse(r["production_authorized"])


if __name__=="__main__":
    unittest.main(verbosity=2)
