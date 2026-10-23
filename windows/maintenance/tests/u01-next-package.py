"""CI fixture: next no-SQL release, built from the first qualified payload.

Exercises the SECOND transaction against the identity/lifecycle produced by
the first. This package is synthetic qualification data, never a user release.
"""
from pathlib import Path
import hashlib, io, json, sys, zipfile

def sha(b): return hashlib.sha256(b).hexdigest()
def encoded(v): return (json.dumps(v, ensure_ascii=False, indent=2)+'\n').encode()
def zipped(files):
    b=io.BytesIO()
    with zipfile.ZipFile(b,'w',zipfile.ZIP_DEFLATED) as z:
        for n,data in sorted(files.items()): z.writestr(n,data)
    return b.getvalue()

first=Path(sys.argv[1]); out=Path(sys.argv[2]); out.mkdir(parents=True,exist_ok=True)
with zipfile.ZipFile(first/'SOKNA-Local-231bf-U01-Panel-Update.zip') as z:
    manifest=json.loads(z.read('release-set.json'))
    with zipfile.ZipFile(io.BytesIO(z.read('artifacts/local/web.zip'))) as inner:
        files={n:inner.read(n) for n in inner.namelist()}
baseline=manifest['targets']['local'].copy()
version=baseline['version']+'-ci-next'
release=manifest['release_set_id']+'-ci-next'
files['VERSION.txt']=(version+'\n').encode()
for n in ['resources/release-identity-v1.json','resources/build-provenance.json']:
    value=json.loads(files[n]); value.update(version=version,release_set_id=release)
    if 'source' in value and isinstance(value['source'],dict):
        value['source']['canonical_tree_sha256']=sha(''.join(n+'\0'+str(len(raw))+'\0'+sha(raw)+'\n' for n,raw in sorted(files.items()) if n not in ('resources/release-identity-v1.json','resources/build-provenance.json')).encode())
    files[n]=encoded(value)
target={**baseline,'version':version,'release_id':release,
        'managed_tree_sha256':sha(''.join(n+'\0'+sha(raw)+'\n' for n,raw in sorted(files.items())).encode())}
manifest.update(release_set_id=release,sequence=manifest['sequence']+1,
    supported_baselines=[{'local':baseline,'public':None}],targets={'local':target})
web=zipped(files); manifest['artifacts']['local-web'].update(size_bytes=len(web),sha256=sha(web))
(out/'SOKNA-Local-231bf-U01-Panel-Update.zip').write_bytes(zipped({'release-set.json':encoded(manifest),'artifacts/local/web.zip':web}))
(out/'target.json').write_bytes(encoded(target))
print(json.dumps({'qualification_only':True,'baseline':baseline['version'],'target':version}))
