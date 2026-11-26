import pathlib,io,json,zipfile,hashlib,sys,base64,subprocess
P=pathlib.Path;H=lambda b:hashlib.sha256(b).hexdigest()
r=P(sys.argv[1]);r.mkdir(exist_ok=True);base=P(sys.argv[2]);transfer=P(sys.argv[3])
b=base64.b64decode(transfer.read_text().strip(),validate=True);assert H(b)=='4f782953960095c090efee3ea90d4fcdf9cc12285b4510f466cecc12d20d6d8d'
def read(b):
 with zipfile.ZipFile(io.BytesIO(b)) as z:
  assert z.testzip() is None and len(z.namelist())==len(set(z.namelist()))
  assert all(n and not n.startswith('/') and '..' not in n.split('/') and chr(92) not in n for n in z.namelist());return {n:z.read(n) for n in z.namelist()}
a=read(base.read_bytes());t=read(b);i=json.loads(t['factory-source-intake.json']);assert all(H(a[n])==h for n,h in i['base_artifacts'].items());l=read(a['LOCAL-FINAL-WEB-QA-DO-NOT-INSTALL.zip']);p=read(a['PUBLIC-FINAL-WEB-QA-DO-NOT-INSTALL.zip']);d={'local/'+n:v for n,v in l.items()}|{'public/'+n:v for n,v in p.items()}
for n,v in i['changes'].items():
 assert H(d[n])==v['before_sha256'] and H(t['delta/'+n])==v['after_sha256'];d[n]=t['delta/'+n]
assert set(d)==set(i['files']) and all(H(v)==i['files'][n] for n,v in d.items())
src=r/'input.zip'
with zipfile.ZipFile(src,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=6) as z:
 for n,v in sorted(({'source-working/'+n:v for n,v in d.items()}|{'factory-source-intake.json':t['factory-source-intake.json']}).items()):
  zi=zipfile.ZipInfo(n,(2026,11,26,0,0,0));zi.compress_type=zipfile.ZIP_DEFLATED;z.writestr(zi,v)
assert H(src.read_bytes())=='f7ba1f01adb6d322cc057d2b08851236d14ac85371f884d90d5c16389a3c5e43',H(src.read_bytes())
script=r/'factory_rebuild_checkpoint76.py';script.write_bytes(t['factory_rebuild_checkpoint76.py']);subprocess.run([sys.executable,str(script),str(base),str(src),str(r/'out')],check=True)
expected=json.loads(t['expected-output.json']);actual=json.loads((r/'out/factory-evidence-decision.json').read_bytes());assert actual==expected,'OUTPUT_DRIFT';print('PASS EXACT FOUR OUTPUTS REPRODUCED')
