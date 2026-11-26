"""Exact one-field correction, with pin and unchanged inner web."""
import sys,pathlib,hashlib,json,zipfile,io
p=pathlib.Path(sys.argv[1]);n='LOCAL-231GG-UPDATE-QA-DO-NOT-INSTALL.zip';f=p/n
H=lambda b:hashlib.sha256(b).hexdigest()
J=lambda x:(json.dumps(x,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
raw=f.read_bytes();assert H(raw)=='9f2859e4da6dd6d15ef64dae3b9d9ae24e64734be39a22349e914d6ed0707ac1'
with zipfile.ZipFile(io.BytesIO(raw)) as z:
 assert z.testzip() is None and set(z.namelist())=={'artifacts/local/web.zip','release-set.json'}
 w=z.read('artifacts/local/web.zip');m=json.loads(z.read('release-set.json'))
assert H(w)=='2c14b241a3cddf5f1d436144691555b11a97ccc31b4d08b2ea8f8d92f04e3aa6'
assert m['sequence']==202611260003 and m['for_install'] is False and m['production_pass'] is False
m['sequence']=202611260004
buf=io.BytesIO()
with zipfile.ZipFile(buf,'w') as z:
 for name,data in sorted({'release-set.json':J(m),'artifacts/local/web.zip':w}.items()):
  i=zipfile.ZipInfo(name,(2026,11,26,0,0,0));i.create_system=3;i.external_attr=0o100644<<16;i.compress_type=zipfile.ZIP_DEFLATED
  z.writestr(i,data,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
b=buf.getvalue();assert H(b)=='14cb9ac28637fcc1e9c83dc34ac40765c8a6d2a13ad37cf8c0d40e8e8b359e31'
with zipfile.ZipFile(io.BytesIO(b)) as z:assert z.read('artifacts/local/web.zip')==w and z.testzip() is None
f.write_bytes(b);r=p/'factory-evidence-decision.json';x=json.loads(r.read_bytes());x['files'][n]={'sha256':H(b),'bytes':len(b)};r.write_bytes(J(x))
print('PASS EXACT SEQUENCE 004 CORRECTION, INNER WEB UNCHANGED')
