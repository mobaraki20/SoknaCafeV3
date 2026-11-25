"""Reconstruct exact accepted 35it/231gf web payloads and apply a six-file delta.
This is QA source only; deliberately does not build an installable package."""
import pathlib as p,os,hashlib,zipfile,base64,zlib,json
h=lambda b:hashlib.sha256(b).hexdigest()
r=p.Path(os.environ['RUNNER_TEMP']);out=r/'prevention-source'
out.mkdir(exist_ok=False)
raw=base64.b64decode(p.Path('qa/checkpoint71/transfer.b64').read_text().strip(),validate=True)
assert h(raw)=='8ea7b335e7989cd443a58efada62c8c4d46fbbb969877e71816b66895e58fad4'
d=json.loads(raw);assert d['format']=='sokna-prevention-delta-v1' and len(d['files'])==6
for role,n,pin in [
 ('local','LOCAL-FINAL-WEB-QA-DO-NOT-INSTALL.zip','45b671f0d9f9689223f8d899a2f7c9af7de9493e7d44ad5a9ed21553227a03b4'),
 ('public','PUBLIC-FINAL-WEB-QA-DO-NOT-INSTALL.zip','3198a800e00fb97888dde61014aab24129b5c78e3f0c938468223fe8e2667c8f')]:
    z=r/'factory'/n;assert h(z.read_bytes())==pin
    with zipfile.ZipFile(z) as f:
        assert f.testzip() is None and len(f.namelist())==len(set(f.namelist()))
        assert all(n and not n.startswith('/') and '..' not in n.split('/') and '\\' not in n for n in f.namelist())
        f.extractall(out/role)
for n,a in d['files'].items():
    f=out/n;assert h(f.read_bytes())==a['before'],n
    b=zlib.decompress(base64.b64decode(a['content']));assert h(b)==a['after'],n;f.write_bytes(b)
    print('VERIFIED EXACT DELTA',n,a['after'])
for n,b in d['tests'].items():
    assert n in ('prevention-test.php','mariadb-test.php')
    (out/n).write_bytes(zlib.decompress(base64.b64decode(b)))
