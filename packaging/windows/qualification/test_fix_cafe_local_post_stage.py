#!/usr/bin/env python3
"""Regression coverage for stale readiness after signed Local V4 rebind."""
import importlib.util
import io
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest
from zipfile import ZipFile

script=Path(__file__).with_name("fix-cafe-local-post-stage.py")
spec=importlib.util.spec_from_file_location("patch",script)
p=importlib.util.module_from_spec(spec)
spec.loader.exec_module(p)

def fixture():
    return (p.OLD_RENDER+"\n"+p.OLD_STAGE+"\n"+p.OLD_CATCH).encode()

class PanelTests(unittest.TestCase):
    def test_signed_stage_must_refresh_before_enabling_activate(self):
        source=p.patch(fixture()).decode()
        self.assertLess(source.index("const fresh=await request"),source.index("disabled=!maintenanceReady"))
        self.assertIn("maintenanceReady=false;",source)
        self.assertIn("fresh.maintenance_panel_bound===true",source)
        self.assertIn("fresh.maintenance_verification_bound===true",source)
        self.assertIn("verifiedMaintenanceStage)",source)
    def test_no_unsigned_or_stale_activation(self):
        text=p.patch(fixture()).decode()
        self.assertIn("maintenanceReady&&/^[a-fA-F0-9]{32}$/.test(verifiedMaintenanceStage)",text)
        self.assertIn("maintenanceReady=false;\n   maintenanceUploadMessage",text)
    def test_wrong_baseline_is_rejected(self):
        with self.assertRaisesRegex(ValueError,"BASELINE_MISMATCH"):
            p.patch(b"arbitrary source")
    def test_second_application_rejected(self):
        with self.assertRaisesRegex(ValueError,"BASELINE_MISMATCH"):
            p.patch(p.patch(fixture()))
    def test_safe_archive_preserves_non_js_bytes(self):
        with TemporaryDirectory() as tmp:
            a=Path(tmp)/"in.zip";b=Path(tmp)/"out.zip"
            with ZipFile(a,"w") as z:
                z.writestr(p.JS,fixture())
                z.writestr("VERSION.txt",b"3.0.0.fixture\n")
            p.archive(a,b)
            with ZipFile(b) as z:
                self.assertEqual(z.read("VERSION.txt"),b"3.0.0.fixture\n")
                self.assertIn(b"const fresh=await request",z.read(p.JS))
    def test_archive_path_traversal_rejected(self):
        with TemporaryDirectory() as tmp:
            a=Path(tmp)/"in.zip";b=Path(tmp)/"out.zip"
            with ZipFile(a,"w") as z:
                z.writestr(p.JS,fixture())
                z.writestr("../not-safe",b"no")
            with self.assertRaisesRegex(ValueError,"UNSAFE_WEB_PATH"):
                p.archive(a,b)

if __name__=="__main__":
    unittest.main()
