#!/usr/bin/env python3
import sys,json,hashlib,zipfile,pathlib
src=pathlib.Path(sys.argv[1]);out=pathlib.Path(sys.argv[2]);files={str(f.relative_to(src)):f.read_bytes() for f in src.rglob('*') if f.is_file()}
assert len(files)==233,('R233_SOURCE_FILES_COUNT',len(files))
# The R233 pre-release changed only VERSION.txt from immutable workspace source.
files['VERSION.txt']=b'3.0.0-g3.6-workspace.35hz-r233-qa\n'
prot=[x for x in files if x.startswith('src/Emergency/')]
assert len(prot)==5,prot
managed={k:v for k,v in files.items() if k not in prot}
assert len(managed)==228
h=lambda b:hashlib.sha256(b).hexdigest()
tree=h(''.join(k+'\0'+h(managed[k])+'\n' for k in sorted(managed)).encode())
assert tree=='c54051cd32d83f9cfbfbbae6f51fc06b0479869bdd0ddb6a0cfaafd28843ffb2',tree
m={'format':'sokna-component-package-v1','component':'public-edge','schema_version':1,'version':'3.0.0-g3.6-workspace.35hz-r233-qa','files':[{'path':k,'size':len(managed[k]),'sha256':h(managed[k])} for k in sorted(managed)]}
out.parent.mkdir(parents=True,exist_ok=True)
with zipfile.ZipFile(out,'w') as z:
 for n,b in [('manifest.json',json.dumps(m,ensure_ascii=False).encode())]+[('payload/'+k,v) for k,v in sorted(managed.items())]:z.writestr(n,b,compress_type=zipfile.ZIP_DEFLATED,compresslevel=8)
print('PASS_R233_EXACT_IMMUTABLE_MANAGED_TREE',len(managed),tree)