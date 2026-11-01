import pathlib,json,hashlib,zipfile,io
ROOT=pathlib.Path(__file__).resolve().parent
index=json.loads((pathlib.Path('app-baselines/release/r89/baseline-parts/index.json')).read_text())['cafe-local']
raw=b''.join((pathlib.Path('app-baselines/release/r89/baseline-parts')/n).read_bytes() for n in index['parts']);assert hashlib.sha256(raw).hexdigest()==index['sha256']
web=ROOT/'build/cafe-web';web.mkdir(parents=True,exist_ok=True)
with zipfile.ZipFile(io.BytesIO(raw)) as z:
 for n in z.namelist():
  if n.startswith('Web/') and not n.endswith('/'):
   p=web/n[4:];p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(z.read(n))
print('Exact original Cafe clean baseline reconstructed; actual trusted identity remains separate.')
