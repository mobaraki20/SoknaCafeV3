#!/usr/bin/env python3
"""R164 source-only Public QA: apply one verified compatibility patch to exact R162 35hr Public payload."""
from __future__ import annotations
import argparse, hashlib, json, pathlib, subprocess, tempfile, zipfile
SHA=lambda b: hashlib.sha256(b).hexdigest()
PARENT_SHA='96e976c43644f62e1246a29752af3fab5fff62978421f93db8065d8460a67503'
PARENT_TREE='2434850306c0c43f801674a4a8d624a9c80e16a9ef3ba3d772f7f3ec5766c5e5'
PATH='src/Guest/GuestCompatibilityService.php'
OLD_FILE='fdd2ca39d0bebeb74245010e12a36c3793205cb6b3d1676edc039154b77f4dff'
NEW_FILE='bc3b560a106c34128a399438e7668f1d235d7a6f624c6f0d11320d8d6ff5a69a'

def tree(items):
    return SHA(''.join(f'{p}\0{SHA(b)}\n' for p,b in sorted(items.items())).encode())

def main():
    a=argparse.ArgumentParser()
    a.add_argument('--release',required=True)
    a.add_argument('--patch',required=True)
    a.add_argument('--outdir',required=True)
    o=a.parse_args()
    origin=pathlib.Path(o.release)
    assert SHA(origin.read_bytes())==PARENT_SHA,'Published Public parent ZIP SHA changed'
    with zipfile.ZipFile(origin) as z:
        names=[name for name in z.namelist() if name.startswith('payload/') and not name.endswith('/')]
        original={name[8:]:z.read(name) for name in names}
        m=json.loads(z.read('manifest.json'))
    assert len(original)==225
    assert m['target_managed_tree_sha256']==PARENT_TREE
    assert tree(original)==PARENT_TREE, 'Public payload != release managed-tree'
    assert SHA(original[PATH])==OLD_FILE
    patch=pathlib.Path(o.patch).resolve()
    assert patch.is_file() and SHA(patch.read_bytes())=='78aa21783ec49a3a529cf5442a08a0a92b9bfd0a18f98d848c2a05606cb732e6','Unpinned R164 Public patch'
    with tempfile.TemporaryDirectory() as tmp:
        path=pathlib.Path(tmp)/PATH
        path.parent.mkdir(parents=True)
        path.write_bytes(original[PATH])
        done=subprocess.run(['patch','--batch','--forward','--fuzz=0','-p1','-d',tmp,'-i',str(patch)],capture_output=True,text=True)
        assert done.returncode==0,done.stdout+done.stderr
        fixed=path.read_bytes()
        assert SHA(fixed)==NEW_FILE,'Candidate Public file bytes differ'
    changed=dict(original)
    changed[PATH]=fixed
    diffs=[key for key in original if original[key]!=changed[key]]
    assert diffs==[PATH],'Unexpected change outside guest compatibility'
    migrations=[k for k in original if k.startswith('database/migrations/')]
    assert len(migrations)==8
    assert all(original[k]==changed[k] for k in migrations)
    target=pathlib.Path(o.outdir)
    target.mkdir(parents=True,exist_ok=True)
    with zipfile.ZipFile(target/'SOKNA-R164-PUBLIC-FULL-SOURCE-QA-NOT-INSTALL.zip','w',compression=zipfile.ZIP_DEFLATED) as z:
        for name,bytes_ in sorted(changed.items()):z.writestr(name,bytes_)
    (target/'public-parent').mkdir(exist_ok=True)
    (target/'public-candidate').mkdir(exist_ok=True)
    for sub, data in [('public-parent',original),('public-candidate',changed)]:
        for n,b in data.items():
            f=target/sub/n;f.parent.mkdir(exist_ok=True,parents=True);f.write_bytes(b)
    report={'base_release_zip_sha256':PARENT_SHA,'base_tree':PARENT_TREE,'target_tree':tree(changed),'changed_paths':diffs,
            'source_files':len(changed),'migration_files_untouched':len(migrations),'database_changes':False,
            'release_ready':False,'installable':False,'production_verified':False}
    (target/'SOKNA-R164-PUBLIC-SOURCE-QA.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
    print(json.dumps(report,ensure_ascii=False,indent=2))

if __name__=='__main__':main()
