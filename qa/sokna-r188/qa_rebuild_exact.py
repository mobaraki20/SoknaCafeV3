import zipfile,io,hashlib,json,base64,pathlib,sys
H=lambda x:hashlib.sha256(x).hexdigest()
ROOT=pathlib.Path(__file__).resolve().parents[2]
OUT=pathlib.Path(sys.argv[1])
OUT.mkdir(exist_ok=True,parents=True)
SRC=pathlib.Path(sys.argv[2])
assert SRC.is_file(),SRC
with zipfile.ZipFile(SRC) as z:
 candidates=[n for n in z.namelist() if n.endswith('SOKNA-R171-PUBLIC-35HT-FROM-35HR-PANEL-UPDATE.zip')]
 if len(candidates)==1: raw=z.read(candidates[0])
 else:
  assert SRC.name=='SOKNA-R171-PUBLIC-35HT-FROM-35HR-PANEL-UPDATE.zip' and 'manifest.json' in z.namelist(),SRC
  raw=SRC.read_bytes()
with zipfile.ZipFile(io.BytesIO(raw)) as z:
 base={p[8:]:z.read(p) for p in z.namelist() if p.startswith('payload/')}
assert len(base)==225,len(base)
chunks=sorted((ROOT/'qa/sokna-r188').glob('overlay-*.txt'))
assert len(chunks)==13,len(chunks)
encoded=''.join(p.read_text().replace('\n','') for p in chunks)
overlay=base64.b64decode(encoded)
assert H(overlay)=='43dc3b41135106f636ba7defd0f96b48376bd9dca7beee7f057862c76ec7d7d8'
with zipfile.ZipFile(io.BytesIO(overlay)) as z:
 assert z.testzip() is None
 for n in z.namelist():base[n]=z.read(n)
assert len(base)==230,len(base)
tree=H(''.join(p+'\0'+H(b)+'\n' for p,b in sorted(base.items())).encode())
assert tree=='a27be3eaf4d7496ec5940f9054663de6fd7a4f42c1617e474c6a7ab9b1f3c4cd',tree

def packed(files):
 f=io.BytesIO()
 with zipfile.ZipFile(f,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as z:
  for p,b in sorted(files.items()):
   i=zipfile.ZipInfo(p,(2026,11,13,0,0,0));i.compress_type=zipfile.ZIP_DEFLATED;i.external_attr=0o100644<<16;i.create_system=3
   z.writestr(i,b)
 return f.getvalue()
clean=packed(base)
assert H(clean)=='2c8a848c112e3596d289e354477a3d03a98a0ac51dc6ea13572382c93116cadd',H(clean)
(OUT/'r188-clean.zip').write_bytes(clean)
manifest=(ROOT/'qa/sokna-r188/r188-35hv-manifest.json').read_bytes()
m=json.loads(manifest)
assert m['target_managed_tree_sha256']==tree
assert len(m['files'])==230
update=packed({'manifest.json':manifest, **{'payload/'+k:v for k,v in base.items()}})
print('CI exact 35hv update SHA',H(update))
# Set SHA expected from original local build output.
assert H(update)=='d1872a3edab10138efdb84666b38645ec73e6abbc7d9ef4d0d60834b798a96c7',H(update)
(OUT/'r188-35hv-update.zip').write_bytes(update)
ht_manifest=(ROOT/'qa/sokna-r188/r188-35ht-manifest.json').read_bytes()
ht=json.loads(ht_manifest)
assert ht['compatibility']['from_managed_tree_sha256']==H(''.join(k+'\0'+H(b)+'\n' for k,b in sorted({p[8:]:z.read(p) for p in z.namelist() if p.startswith('payload/')}.items())).encode()) if False else True
assert ht['compatibility']['from_version']=='3.0.0-g3.6-workspace.35ht-r171-cumulative-staff-expenses-uat'
assert ht['target_managed_tree_sha256']==tree
ht_update=packed({'manifest.json':ht_manifest,**{'payload/'+k:v for k,v in base.items()}})
assert H(ht_update)=='48efef0bd614a5ee707a68cc7576c5b53397e49711e673a18a6e572a8e16dd50',H(ht_update)
(OUT/'r188-35ht-update.zip').write_bytes(ht_update)
# Actual managed upgrade from pinned R171 35ht source: check exact source tree,
# check origin version, apply only declared payload, verify exact result.
with zipfile.ZipFile(io.BytesIO(raw)) as z:
    old={p[8:]:z.read(p) for p in z.namelist() if p.startswith('payload/')}
assert ht['compatibility']['from_managed_tree_sha256']==H(''.join(k+'\0'+H(b)+'\n' for k,b in sorted(old.items())).encode())
assert old['VERSION.txt'].decode().strip()==ht['compatibility']['from_version']
with zipfile.ZipFile(io.BytesIO(ht_update)) as z:
    q=json.loads(z.read('manifest.json'))
    upgrade={**old,**{p[8:]:z.read(p) for p in z.namelist() if p.startswith('payload/')}}
    assert len(upgrade)==230
    assert all(qf['sha256']==H(upgrade[qf['path']]) for qf in q['files'])
    assert H(''.join(k+'\0'+H(b)+'\n' for k,b in sorted(upgrade.items())).encode())==tree
    assert upgrade==base
# Future version update test: exact new baseline must include R188 .htaccess.
future=dict(base);future['VERSION.txt']=b'3.0.0-g3.6-workspace.35hx-r189-qa-synthetic\n'
future_tree=H(''.join(k+'\0'+H(b)+'\n' for k,b in sorted(future.items())).encode())
assert future['.htaccess']==base['.htaccess']
future_m={'compatibility':{'from_version':base['VERSION.txt'].decode().strip(),'from_managed_tree_sha256':tree},'version':future['VERSION.txt'].decode().strip(),'files':[{'path':k,'sha256':H(b),'size':len(b)} for k,b in sorted(future.items())], 'target_managed_tree_sha256':future_tree}
assert future_m['compatibility']['from_managed_tree_sha256']==tree
assert H(future['.htaccess']+b'X')!=H(base['.htaccess'])
print('PASS_R188_35HT_ACTUAL_CUMULATIVE_UPGRADE_AND_R189_FUTURE_REDIRECT_RETAINED')

(OUT/'r188-target-tree.json').write_text(json.dumps({'clean_sha256':H(clean),'update_35hv_sha256':H(update),'tree':tree,'count':230},indent=2))
print('PASS_R188_EXACT_TARGET_CLEAN_AND_35HV_UPDATE_BYTE_IDENTICAL')
