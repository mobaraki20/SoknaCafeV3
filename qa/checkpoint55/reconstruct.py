import base64,hashlib,io,json,pathlib,subprocess,sys,zipfile,zlib
H=lambda b:hashlib.sha256(b).hexdigest()
base=pathlib.Path(sys.argv[1]);out=pathlib.Path(sys.argv[2]);out.mkdir()
enc=pathlib.Path('qa/checkpoint55/transfer.b64').read_bytes()
assert H(enc)== '317bf265732d7fe81975a87e3e57ab82b06424da449c2de00622bf8d3e9fe944'
d=json.loads(zlib.decompress(base64.b64decode(enc)))
src=out/'source';src.mkdir()
for component,name,pin in [('local','LOCAL-FINAL-WEB.zip','25eb71b6ae92065f6ca85891ea8c8481a42f1ff04afd83bc18729a4a8d111818'),('public','PUBLIC-FINAL-WEB.zip','610ad1b56aab3abd975a8cf33b4771bfd0a820bc0bf08780ffb4628c03a4b065')]:
    raw=(base/name).read_bytes();assert H(raw)==pin
    with zipfile.ZipFile(io.BytesIO(raw)) as z:
        assert z.testzip() is None
        for n in z.namelist():
            assert not n.startswith('/') and '..' not in n.split('/')
        z.extractall(src/component)
for n,v in d['changed'].items():assert H((src/n).read_bytes())==v['before']
patch=out/'delta.patch';patch.write_bytes(d['patch'].encode())
subprocess.run(['git','apply','--check',str(patch)],cwd=src,check=True)
subprocess.run(['git','apply',str(patch)],cwd=src,check=True)
for n,v in d['changed'].items():assert H((src/n).read_bytes())==v['after']
receipt=base64.b64decode(d['receipt']);(src/'qa-receipt.json').write_bytes(receipt)
r=json.loads(receipt);assert len(r['file_hashes'])==882
assert {str(p.relative_to(src)):H(p.read_bytes()) for p in src.rglob('*') if p.is_file() and p.name!='qa-receipt.json'}==r['file_hashes']
assert not r['distribution_authorized'] and not r['deployment_authorized']
archive=out/'source.zip'
with zipfile.ZipFile(archive,'w') as z:
    for m in d['meta']:
        i=zipfile.ZipInfo(m['n'],tuple(m['t']));i.external_attr=m['a'];i.create_system=m['c'];i.compress_type=zipfile.ZIP_DEFLATED
        z.writestr(i,(src/m['n']).read_bytes(),compresslevel=6)
assert H(archive.read_bytes())=='f54d05e3606852bf61ac25fc5cb8fb3b93b4a84794c791dcc90cbbd1196379bb'
baseline=out/'baseline.zip'
with zipfile.ZipFile(baseline,'w') as z:
    for name in ('LOCAL-FINAL-WEB.zip','PUBLIC-FINAL-WEB.zip','LOCAL-FINAL-FROZEN.zip','PUBLIC-FINAL-FROZEN.zip'):z.write(base/name,name)
builder=out/'build.py';builder.write_bytes((d['build'][:d['build'].index('# Additional exact origin')] + d['build'][d['build'].index('mp=sorted(n for n in P'):]).encode())
subprocess.run([sys.executable,str(builder),str(archive),str(baseline),str(out/'packages')],check=True)
pins={'LOCAL-FINAL-FROZEN-QA-DO-NOT-INSTALL.zip':'63f7db98d092fc7f40b785fbae0864aab810e3d277598de0794bff0bc92809a5','LOCAL-FINAL-WEB-QA-DO-NOT-INSTALL.zip':'45b671f0d9f9689223f8d899a2f7c9af7de9493e7d44ad5a9ed21553227a03b4','PUBLIC-FINAL-FROZEN-QA-DO-NOT-INSTALL.zip':'fbf446d0e29c876bf72e01535cb8e252f21de1a2df888e76239ba5338a9170f5','PUBLIC-FINAL-WEB-QA-DO-NOT-INSTALL.zip':'f65f3ca6d478a2d851b169f79583e66845f86ec9cbd93f1881420d51a6e16d85'}
# Reproduce checkpoint51 four packages; checkpoint52 additional Home Public origin is deliberately excluded, not a release candidate.
for n,h in pins.items():assert H((out/'packages'/n).read_bytes())==h,n
print('PASS EXACT SOURCE 882 FILES AND FOUR BYTE-IDENTICAL QA PACKAGES; NO DISTRIBUTION')
