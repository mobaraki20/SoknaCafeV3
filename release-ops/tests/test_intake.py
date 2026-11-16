"""Agent source discovery: no guessed current source, no silent production release."""
import io
import json
import pathlib
import sys
import tempfile
import unittest
import zipfile

sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[1]))
import intake
import source_gate
from audit import sha


def pack(files):
    buf=io.BytesIO()
    with zipfile.ZipFile(buf,"w",zipfile.ZIP_DEFLATED) as z:
        for n,b in sorted(files.items()):
            z.writestr(n,b)
    return buf.getvalue()


class IntakeTest(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.p=pathlib.Path(self.tmp.name)
        files={
            "VERSION.txt":b"old-source-v1\n",
            "bootstrap.php":b"<?php\n",
            "database/migrations/0001_init.sql":b"CREATE TABLE example(id INT);\n",
            "resources/local-managed-files-v1.json":b"",
        }
        files["resources/local-managed-files-v1.json"]=(
            json.dumps({"format":"sokna-local-managed-files-v1",
                        "files":sorted(files)})+"\n").encode()
        self.source=self.p/"pinned-local-source.zip"
        self.source.write_bytes(pack(files))
        self.checkpoint="T"
        pin={
            "zip_sha256":sha(self.source.read_bytes()),
            "source_tree_sha256":source_gate.source_tree({n:(sha(b),len(b)) for n,b in files.items()}),
            "file_count":len(files),"migration_count":1,
            "original_version":"old-source-v1",
            "file":self.source.name
        }
        self.sr=self.p/"register.json"
        self.sr.write_text(json.dumps({"format":"sokna-release-source-register-v1",
            "checkpoints":{"T":{"status":"SOURCE_ONLY_NOT_INSTALLABLE","sources":{"local":pin}}}}))
        self.active=self.p/"active.json"
        self.active.write_text(json.dumps({
            "format":"sokna-release-active-source-v1","active_checkpoint":"T",
            "components":{"local":{"file":self.source.name,"sha256":pin["zip_sha256"],
                                   "files":len(files),"migrations":1}}}))
        self.vr=self.p/"versions.json"
        self.vr.write_text(json.dumps({"observed":[],"released":[],"quarantined_short_versions":["35gg"]}))

    def tearDown(self):
        self.tmp.cleanup()

    def run_intake(self,kind="local-update",target_version=""):
        return intake.inspect_active(self.source,"local",kind,self.active,self.sr,self.vr,target_version,"T")

    def test_valid_source_admission_does_not_publish(self):
        r=self.run_intake(target_version="3.0.0.231zz-qa")
        self.assertEqual(r["source_status"],"PASS",r)
        self.assertEqual(r["database_status"],"PASS",r)
        self.assertEqual(r["migration_count"],1)
        self.assertFalse(r["production_authorized"])
        self.assertFalse(r["package_constructed"])
        self.assertIn("PREVIOUS_INSTALLED_MIGRATIONS_NOT_COMPARED",r["not_proven"])

    def test_wrong_kind_refused(self):
        r=self.run_intake("public-update")
        self.assertEqual(r["source_status"],"FAIL")
        self.assertIn("UNSUPPORTED_COMPONENT_PACKAGE_OWNER_COMBINATION",r["errors"])

    def test_source_not_registered_is_refused(self):
        self.source.write_bytes(b"garbage")
        r=self.run_intake()
        self.assertEqual(r["source_status"],"FAIL")
        self.assertIn("ACTIVE_SOURCE_SHA256_MISMATCH",r["errors"])

    def test_pointer_conflict_refused(self):
        obj=json.loads(self.active.read_text())
        obj["components"]["local"]["migrations"]=12
        self.active.write_text(json.dumps(obj))
        r=self.run_intake()
        self.assertIn("ACTIVE_POINTER_CONFLICTS_WITH_PINNED_SOURCE_REGISTER",r["errors"])

    def test_wrong_checkpoint_refused(self):
        r=intake.inspect_active(self.source,"local","local-update",self.active,self.sr,self.vr,"","OTHER")
        self.assertIn("ACTIVE_POINTER_CHANGED_FROM_REQUEST",r["errors"])

    def test_duplicate_target_version_refused(self):
        self.vr.write_text(json.dumps({"observed":[{"component":"local","kind":"local-update",
            "short_code":"231zz","version":"3.0.0.231zz-old","sha256":"0"*64}],
            "released":[],"quarantined_short_versions":[]}))
        r=self.run_intake(target_version="3.0.0.231zz-new")
        self.assertIn("TARGET_VERSION_ALREADY_RESERVED",r["errors"])

    def test_unchanged_source_version_refused(self):
        r=self.run_intake(target_version="old-source-v1")
        self.assertIn("TARGET_VERSION_INVALID_OR_SAME_AS_SOURCE",r["errors"])


if __name__=="__main__":
    unittest.main(verbosity=2)
