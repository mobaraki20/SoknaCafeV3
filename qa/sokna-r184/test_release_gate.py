#!/usr/bin/env python3
import copy
import io
import json
import pathlib
import sys
import tempfile
import unittest
import zipfile
import importlib.util

spec = importlib.util.spec_from_file_location('release_gate', pathlib.Path(__file__).with_name('release_gate.py'))
rg = importlib.util.module_from_spec(spec)
spec.loader.exec_module(rg)

class TestReleaseGate(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.test_dir = tempfile.TemporaryDirectory()
        cls.root = pathlib.Path(cls.test_dir.name)
        cls.identity = {k:'source-'+k for k in rg.IDENTITY}
        cls.identity['version']='3.0.0.test'
        cls.target = {k:'target-'+k for k in rg.IDENTITY}
        cls.target['version']='3.0.0.target'
        cls.manifest = {
            'format':'sokna-maintenance-release-set-v2.1','schema_version':'2.1',
            'release_type':'local_only','release_set_id':'synthetic-test','for_install':True,
            'production_pass':True,'status':'INSTALL_QUALIFIED',
            'targets':{'local':cls.target},
            'supported_baselines':[{'local':cls.identity}],
            'install_plan':[{'phase':'preflight','mutation':False},{'phase':'stage','mutation':False},{'phase':'activate','mutation':True}],
            'pre_update_backup_policy':{'required':True,'on_failure':'reject_pre_mutation','system_recovery_snapshot_required':True},
            'qualification':dict.fromkeys(rg.REQUIRED_LOCAL_QUAL,True)|{'failure_matrix_id':'test','failure_matrix_cases_required':80},
            'artifacts':{'local-web':{'path':'artifacts/local/web.zip','sha256':rg.hashlib.sha256(b'test-artifact').hexdigest(),'size_bytes':13}},
        }

    @classmethod
    def tearDownClass(cls):
        cls.test_dir.cleanup()

    def fixture(self, name='sokna-test.zip', edits=None, members=None):
        path=self.root / name
        doc=copy.deepcopy(self.manifest)
        if edits:
            edits(doc)
        with zipfile.ZipFile(path,'w') as z:
            z.writestr('release-set.json',json.dumps(doc))
            z.writestr('artifacts/local/web.zip',b'test-artifact')
            for filename,body in members or []:
                z.writestr(filename,body)
        return path

    def test_minimal_synthetic_static_pass_is_not_install_permission(self):
        r=rg.check(self.fixture(),self.identity,'3.0.0.target')
        self.assertEqual(r['static_gate'],'PASS_STATIC_ONLY')
        self.assertFalse(r['maintenance_stage_authorized'])

    def test_missing_manifest_wrapper_rejected(self):
        fn=self.root/'wrapper.zip'
        with zipfile.ZipFile(fn,'w') as z:z.writestr('nested/local.zip',b'not-release')
        r=rg.check(fn)
        self.assertIn('ROOT_RELEASE_SET_MISSING',r['reasons'])
        self.assertEqual(r['static_gate'],'DENY')

    def test_bad_or_corrupt_zip_rejected(self):
        fn=self.root/'garbage.zip'
        fn.write_bytes(b'not-a-zip')
        self.assertEqual(rg.check(fn)['static_gate'],'DENY')

    def test_zip_path_escape_rejected(self):
        self.assertIn('ZIP_UNSAFE_PATH', rg.check(self.fixture('bad-path.zip',members=[('../escape',b'test')]))['reasons'])

    def test_uat_markers_rejected(self):
        def edit(m):
            m['production_pass']=False
            m['status']='R181_HOME_UAT_PENDING_NATIVE_DB_AND_WINDOWS'
        r=rg.check(self.fixture('uat.zip',edits=edit))
        self.assertIn('PRODUCTION_PASS_NOT_TRUE',r['reasons'])
        self.assertIn('UAT_STATUS_PROHIBITED',r['reasons'])

    def test_quarantined_ci_stage_probe_is_not_deliverable(self):
        def edit(m):
            m['status']='R184_CI_QUARANTINED_CANARY_PENDING_FIELD'
            m['qa_notes']={'r184_quarantined_ci_only':True}
        result=rg.check(self.fixture('quarantined.zip',edits=edit))
        self.assertEqual(result['static_gate'],'DENY')
        self.assertIn('QUARANTINED_CI_PACKAGE_NOT_FOR_DELIVERY',result['reasons'])

    def test_unknown_installed_identity_rejected(self):
        row={k:'unsupported' for k in rg.IDENTITY}
        self.assertIn('INSTALLED_IDENTITY_NOT_IN_BASELINE_LIST',rg.check(self.fixture('identity.zip'),row)['reasons'])

    def test_artifact_digest_rejected(self):
        def edit(m):m['artifacts']['local-web']['sha256']='0'*64
        self.assertIn('ARTIFACT_DIGEST_OR_SIZE_MISMATCH',rg.check(self.fixture('tampered.zip',edits=edit))['reasons'])

    def test_backup_policy_required(self):
        def edit(m):m['pre_update_backup_policy']['required']=False
        self.assertIn('PRE_UPDATE_BACKUP_POLICY_UNSAFE',rg.check(self.fixture('no-backup.zip',edits=edit))['reasons'])

    def test_no_stage_even_after_static_pass(self):
        self.assertFalse(rg.check(self.fixture('passing.zip'))['operational_activation_verified'])


if __name__=='__main__':
    unittest.main(verbosity=2)
