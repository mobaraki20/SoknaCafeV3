import pathlib,zipfile,hashlib,io,json,shutil
R=pathlib.Path(__file__).resolve().parent;B=R/'build';B.mkdir(exist_ok=True)
expected={'local':'75286aafa79c29f70c7143fea69ac8635fe15a6040f5ae9581fadb5e81cd92f4','public':'227c49f92c79fc452cc025cdbb673104bfb920b8e1e77087ad9449e3e3d1c10d'}
for kind,h in expected.items():
 p=R/'baseline'/('SOKNA-'+kind.upper()+'-UNIVERSAL-UPDATE.zip');assert hashlib.sha256(p.read_bytes()).hexdigest()==h
 with zipfile.ZipFile(p) as z:
  if kind=='local':
   m=json.loads(z.read('release-set.json'));w=zipfile.ZipFile(io.BytesIO(z.read(m['artifacts']['local-web']['path'])));data={n:w.read(n) for n in w.namelist()}
  else:
   m=json.loads(z.read('manifest.json'));data={x['path']:z.read('payload/'+x['path']) for x in m['files']}
 for mode in ['before','after']:
  for n,b in data.items():
   p=B/mode/kind/n;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(b)
 checks=json.loads((R/'baseline-shas.json').read_text())
 for key,h in checks.items():
  k,n=key.split('/',1)
  if k!=kind:continue
  p=B/'after'/kind/n
  assert hashlib.sha256(p.read_bytes()).hexdigest()==h,key
  if n.endswith('/product-ui.css'):p.write_bytes(p.read_bytes()+(R/'chart-signed.css').read_bytes())
  else:p.write_bytes((R/'changes'/kind/n).read_bytes())
 print(kind,'exact R92 source verified and root fixes applied')
