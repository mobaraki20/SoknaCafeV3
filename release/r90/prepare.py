import pathlib,zipfile,hashlib,json,io,shutil
ROOT=pathlib.Path(__file__).resolve().parent;OUT=ROOT/'build';source=OUT/'source'
p=ROOT/'baseline/SOKNA-LOCAL-UNIVERSAL-UPDATE.zip'
assert hashlib.sha256(p.read_bytes()).hexdigest()=='e8bf1c516e606de3aaf712b85a584dc66d5dadcdae5517557d7c07dbf15daa8a'
with zipfile.ZipFile(p) as z:
 m=json.loads(z.read('release-set.json'));b=z.read(m['artifacts']['local-web']['path']);assert hashlib.sha256(b).hexdigest()==m['artifacts']['local-web']['sha256']
 with zipfile.ZipFile(io.BytesIO(b)) as web:
  assert web.testzip() is None
  for n in web.namelist():
   f=source/n;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(web.read(n))
checks=json.loads((ROOT/'baseline-file-shas.json').read_text())
for n,h in checks.items():assert hashlib.sha256((source/n).read_bytes()).hexdigest()==h,n
for n in checks:shutil.copyfile(ROOT/'changes/local'/n,source/n)
(OUT/'qa').mkdir(exist_ok=True)
print('Exact published R89 Local reconstructed; four source-only changes applied. NOT INSTALLABLE.')
