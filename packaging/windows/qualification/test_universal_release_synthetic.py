"""Dependency-free tests that also run on the restricted GitHub Actions branch."""
import hashlib, io, json, tempfile, unittest, zipfile
from pathlib import Path
from universal_release_prep import prepare, UnsafeRelease, canonical_tree, ledger, migration_catalog, safe_zip_entries

def archive(path, manifest, payload=None):
    with zipfile.ZipFile(path,'w') as z:
        z.writestr('release-set.json',json.dumps(manifest))
        if payload is not None:z.writestr('artifacts/local/web.zip',payload)

class Synthetic(unittest.TestCase):
    def test_one_target_three_source_builds_noninstallable_package(self):
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp)
            files={'VERSION.txt':b'3.0.0.synthetic.231bp',
                'database/migrations/0001_start.sql':b'CREATE TABLE foo (id int);',
                'database/migrations/0002_late.sql':b'ALTER TABLE foo ADD COLUMN bar int;'}
            with io.BytesIO() as b:
                with zipfile.ZipFile(b,'w') as z:
                    for k,v in files.items():z.writestr(k,v)
                payload=b.getvalue()
            cat=migration_catalog(files)
            base_fp=ledger(cat,'0001_start')
            dest={'release_id':'target','version':'3.0.0.synthetic.231bp','managed_tree_sha256':canonical_tree(files),
                'migration_head':'0002_late','authority_epoch_policy':'preserved','managed_files':len(files)}
            def baseline(i):return {'release_id':f'base{i}', 'version':f'3.0.0.synthetic.231b{chr(96+i)}',
                'managed_tree_sha256':hashlib.sha256(f'base-{i}'.encode()).hexdigest(),
                'schema_fingerprint':base_fp,'migration_head':'0001_start','authority_epoch_policy':'preserved'}
            m={'format':'sokna-maintenance-release-set-v2.1','release_type':'local_only',
               'targets':{'local':dest},'artifacts':{'local-web':{'path':'artifacts/local/web.zip','sha256':hashlib.sha256(payload).hexdigest(),
                'size_bytes':len(payload)}},'database':{'local':{'migration_dag':[]}},'recovery':{},
               'requirements':{},'install_plan':[{'phase':'preflight','mutation':False}], 'for_install':True}
            candidate=root/'candidate.zip';archive(candidate,m,payload)
            sources=[]
            for i in range(1,4):
                x=root/f'base-{i}.zip';archive(x,dict(m,targets={'local':baseline(i)}));sources.append(x)
            out=root/'output.zip';report=prepare(candidate,sources,out)
            self.assertEqual(report['baseline_count'],3)
            self.assertEqual(report['migrations_union'],['0002_late'])
            generated=safe_zip_entries(out.read_bytes());man=json.loads(generated['release-set.json'])
            self.assertFalse(man['for_install']);self.assertFalse(man['production_pass'])
            self.assertEqual(generated['artifacts/local/web.zip'],payload)
            self.assertEqual(len(man['supported_baselines']),3)
            self.assertEqual(man['targets']['local']['schema_fingerprint'],ledger(cat,'0002_late'))
            # Deliberately mutate first baseline without changing apparent version.
            corrupted=baseline(1);corrupted['schema_fingerprint']='migration-ledger-sha256-v1:'+'0'*64
            archive(sources[0],dict(m,targets={'local':corrupted}))
            with self.assertRaisesRegex(UnsafeRelease,'BASELINE_MIGRATION_LEDGER_MISMATCH'):
                prepare(candidate,sources,root/'bad.zip')
            future=baseline(1)
            future['version']='3.0.0.synthetic.231bz'
            archive(sources[0],dict(m,targets={'local':future}))
            with self.assertRaisesRegex(UnsafeRelease,'DOWNGRADE_OR_SAME_APP_VERSION_FORBIDDEN'):
                prepare(candidate,sources,root/'future.zip')

if __name__=='__main__':unittest.main(verbosity=2)