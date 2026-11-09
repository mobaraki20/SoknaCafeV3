#!/usr/bin/env python3
"""Release regression cases; synthetic receipts are test data, never approvals."""
import copy
import importlib.util
import io
import json
import tempfile
import unittest
import zipfile
from pathlib import Path

HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('archive_fixture',HERE.parent/'sokna-r132/test-release-gate.py')
fixture=importlib.util.module_from_spec(spec);spec.loader.exec_module(fixture)
gate=fixture.gate

class FinalEvidence(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.path=Path(self.tmp.name)/'final.zip';fixture.archive(self.path,approved=True)
        self.manifest=self.read()['release-set.json']
        self.manifest=json.loads(self.manifest)
        self.manifest['signature']=None
        self.manifest['supported_baselines']=[{'local':{'version':'A','managed_tree_sha256':'a'*64}},
                                               {'local':{'version':'B','managed_tree_sha256':'b'*64}}]
        self.write_manifest()

    def read(self):
        with zipfile.ZipFile(self.path) as z:return {p:z.read(p) for p in z.namelist()}

    def write(self,files):
        with zipfile.ZipFile(self.path,'w') as z:
            for p,b in files.items():z.writestr(p,b)

    def write_manifest(self):
        files=self.read();files['release-set.json']=json.dumps(self.manifest).encode();self.write(files)

    def evidence(self):
        sha=gate.sha(self.path.read_bytes())
        return {'format':'sokna-release-evidence-v1','package_sha256':sha,'scope':'production',
                'physical_cafe_host_tested':True,'real_cafe_backup_restored':True,
                'pre_db_failure_recovery_verified':True,'post_db_forward_recovery_verified':True,
                'installs':[{'receipt_id':'synthetic-'+str(i),'observed_at':'2026-11-09T00:00:00Z',
                    'source_identity':b['local'],'target_identity':self.manifest['targets']['local'],
                    'package_sha256':sha,'committed':True,'readback_verified':True,
                    'business_invariants_verified':True}
                    for i,b in enumerate(self.manifest['supported_baselines'])]}

    def test_keyless_package_with_consistent_external_receipts(self):
        code,r=gate.examine(self.path,self.evidence())
        self.assertEqual(code,0,r)
        self.assertFalse(r['deployment_authorized'])

    def test_booleans_alone_cannot_qualify(self):
        code,r=gate.examine(self.path)
        self.assertEqual(code,3);self.assertTrue(r['structurally_valid'])

    def test_stage_receipt_without_commit_rejected(self):
        e=self.evidence();e['installs'][0]['committed']=False
        self.assertEqual(gate.examine(self.path,e)[0],3)

    def test_two_receipts_for_same_baseline_are_not_two_baselines(self):
        e=self.evidence();e['installs'][1]=copy.deepcopy(e['installs'][0])
        self.assertIn('TWO_BASELINE_INSTALL_RECEIPTS_MISSING',gate.examine(self.path,e)[1]['production_failures'])

    def test_fabricated_canary_flags_cannot_promote_home_scope(self):
        self.manifest['status']='R149B_LOCAL_PANEL_QUALIFIED_HOME_CANARY';self.write_manifest()
        self.assertIn('NON_PRODUCTION_SCOPE',gate.examine(self.path,self.evidence())[1]['production_failures'])

    def test_evidence_from_another_zip_rejected(self):
        e=self.evidence();e['package_sha256']='0'*64
        self.assertEqual(gate.examine(self.path,e)[0],3)

    def test_restore_and_both_failure_boundaries_required(self):
        for k in ['real_cafe_backup_restored','physical_cafe_host_tested',
                  'pre_db_failure_recovery_verified','post_db_forward_recovery_verified']:
            with self.subTest(k=k):
                e=self.evidence();e[k]=False;self.assertEqual(gate.examine(self.path,e)[0],3)

    def test_target_version_and_sql_identity_bound_to_bytes(self):
        for k in ['version','migration_head','schema_fingerprint']:
            with self.subTest(k=k):
                original=copy.deepcopy(self.manifest)
                self.manifest['targets']['local'][k]='WRONG';self.write_manifest()
                self.assertEqual(gate.examine(self.path,self.evidence())[0],2)
                self.manifest=original;self.write_manifest()

    def test_migration_dag_cannot_omit_new_sql(self):
        self.manifest['database']['local']['migration_dag']=[];self.write_manifest()
        self.assertIn('MIGRATION_DAG_MISMATCH',gate.examine(self.path)[1]['structural_failures'])

    def test_windows_case_collision_rejected(self):
        files=self.read();files['Release-set.json']=files['release-set.json'];self.write(files)
        self.assertEqual(gate.examine(self.path)[0],2)

    def test_receipt_for_unlisted_source_is_rejected(self):
        e=self.evidence();e['installs'][0]['source_identity']={'version':'UNKNOWN'}
        self.assertEqual(gate.examine(self.path,e)[0],3)

if __name__=='__main__':unittest.main(verbosity=2)
