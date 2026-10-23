#!/usr/bin/env python3
"""Bind the existing exact-231au Windows bridge to the same panel web payload."""
import argparse, hashlib, io, json, re, zipfile
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
def sha(b):return hashlib.sha256(b).hexdigest()
def prepare(panel_dir):
    r=ROOT/'windows/maintenance/tools/HomeBootstrap'
    target=json.loads((panel_dir/'target.json').read_text())
    with zipfile.ZipFile(panel_dir/'SOKNA-Local-231bf-U01-Panel-Update.zip') as outer:raw=outer.read('artifacts/local/web.zip')
    z=zipfile.ZipFile(io.BytesIO(raw))
    files=[{'path':n,'size':len(z.read(n)),'sha256':sha(z.read(n))} for n in sorted(z.namelist())]
    tree=sha(''.join(f['path']+'\0'+f['sha256']+'\n' for f in files).encode())
    assert tree==target['managed_tree_sha256'] and len(files)==target['managed_files']
    base=json.loads((r/'payload/baseline-manifest.json').read_text())
    old={f['path'].split('/')[-1]:f['sha256'] for f in base['files'] if f['path'].startswith('database/migrations/')}
    current={f['path'].split('/')[-1]:f['sha256'] for f in files if f['path'].startswith('database/migrations/')}
    assert len(old)==71 and len(current)==75 and all(current[k]==v for k,v in old.items())
    catalog={'format':'sokna-cafe-local-bridge-migrations-v1','schema_version':1,'count':71,'target_count':75,'unchanged':False,
        'files':[{'file':k,'sha256':v} for k,v in sorted(old.items())],
        'added':[{'file':k,'sha256':v} for k,v in sorted(current.items()) if k not in old]}
    manifest={'format':'sokna-cafe-local-bridge-files-v1','schema_version':1,'name':'target','version':target['version'],
        'managed_files':len(files),'managed_tree_sha256':tree,'files':files}
    # Keep baseline format owned by the original tested bridge.
    manifest['format']=base['format']
    (r/'payload/local-m06-target.zip').write_bytes(raw)
    for name,value in [('target-manifest.json',manifest),('migration-catalog.json',catalog)]:
        (r/'payload'/name).write_text(json.dumps(value,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
    p=r/'core.go';s=p.read_text();s=re.sub(r'targetZipSHA\s*=\s*"[a-f0-9]{64}"','targetZipSHA = "'+sha(raw)+'"',s);p.write_text(s,encoding='utf-8')
    print(json.dumps({'target_version':target['version'],'target_tree':tree,'target_zip_sha256':sha(raw),'migrations':len(current)}))
if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--panel-directory',type=Path,required=True);a=p.parse_args();prepare(a.panel_directory)
