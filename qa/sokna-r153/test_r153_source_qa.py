import zipfile,io,json,hashlib,subprocess,tempfile,pathlib,concurrent.futures,collections,os
root=pathlib.Path(os.environ.get('SOKNA_R153_SOURCE_DIR','/mnt/data/sokna-current-r152'))/'r153-source-qa'
pub=root/'SOKNA-R153-PUBLIC-35HK-31BASE-SOURCE-QA-NOT-INSTALL.zip';loc=root/'SOKNA-R153-LOCAL-231ED-44BASE-SOURCE-QA-NOT-INSTALL.zip'
sha=lambda b:hashlib.sha256(b).hexdigest()
with zipfile.ZipFile(pub) as z:
 manifest=json.loads(z.read('manifest.json'))
 public={p[8:]:z.read(p) for p in z.namelist() if p.startswith('payload/')}
with zipfile.ZipFile(loc) as z:
 lm=json.loads(z.read('release-set.json'))
 with zipfile.ZipFile(io.BytesIO(z.read('artifacts/local/web.zip'))) as nested:
  local={p:nested.read(p) for p in nested.namelist()}
assert lm['for_install'] is False and lm['production_pass'] is False and lm['install_plan']==[] and lm['signature'] is None
assert manifest['provenance']['ready_for_production'] is False
assert len(local)==583 and len(public)==222
assert manifest['target_managed_tree_sha256']==sha(''.join(f'{p}\0{sha(v)}\n' for p,v in sorted(public.items())).encode())
assert lm['targets']['local']['managed_tree_sha256']==sha(''.join(f'{p}\0{sha(v)}\n' for p,v in sorted(local.items())).encode())
assert manifest['files']==[{'path':p,'size':len(b),'sha256':sha(b)} for p,b in sorted(public.items())]
owner=json.loads(public['src/Update/emergency-owner-v1.json']); assert owner['release_version']==public['VERSION.txt'].decode().strip()
assert owner['sha256']==sha(public['src/Update/EmergencyOwnerCandidate.php'])
print('PASS EXACT PUBLIC MANIFEST / OWNER SHA / TARGET TREE / QA SAFE BLOCK')
with tempfile.TemporaryDirectory(prefix='sokna-r153-qa-') as t:
 tmp=pathlib.Path(t)
 for side,files in [('local',local),('public',public)]:
  for p,b in files.items():
   f=tmp/side/p;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(b)
  x=json.loads(files['resources/release-identity-v1.json']);expected=x['source']['canonical_tree_sha256']
  tree=sha(''.join(f'{p}\0{len(b)}\0{sha(b)}\n' for p,b in sorted(files.items()) if p not in ['resources/build-provenance.json','resources/release-identity-v1.json']).encode())
  assert tree==expected and x['version']==files['VERSION.txt'].decode().strip()
  print('PASS',side.upper(),'CANONICAL_VERSION_AND_TREE_MATCH')
 (tmp/'public/manifest.json').write_text(json.dumps(manifest,ensure_ascii=False))
 source='''<?php
require_once $argv[1].'/src/Maintenance/PublicSignedReleaseAttestation.php';
$o=new Sokna\\PublicEdge\\Maintenance\\PublicSignedReleaseAttestation($argv[1],$argv[1].'/nonexistent-storage');
$r=new ReflectionMethod(Sokna\\PublicEdge\\Maintenance\\PublicSignedReleaseAttestation::class,'sourceIdentity');
$id=$r->invoke($o);
if($id['release_set_id']!==$argv[2]||$id['tree']!==$argv[3]) throw new Exception('SOURCE_IDENTITY_NOT_MATCHING');
$m=new ReflectionMethod(Sokna\\PublicEdge\\Maintenance\\PublicSignedReleaseAttestation::class,'verifyManagedManifest');
$m->invoke($o,$id['tree']);
echo "PASS NATIVE PUBLIC SIGNED ATTESTATION MANIFEST READBACK\\n";
'''.replace('Sokna\\\\PublicEdge\\\\Maintenance\\\\','Sokna\\PublicEdge\\Maintenance\\')
 php=tmp/'run.php';php.write_text(source)
 x=json.loads(public['resources/release-identity-v1.json'])
 p=subprocess.run(['php',str(php),str(tmp/'public'),x['release_set_id'],x['source']['canonical_tree_sha256']],capture_output=True,text=True)
 assert p.returncode==0, (p.stdout,p.stderr)
 print(p.stdout.strip())
 # Static compiler gate for every PHP and JS (not just changed ones).
 entries=[f for side in ['local','public'] for f in (tmp/side).rglob('*') if f.suffix in ['.php','.js']]
 def lint(f):
  cmd=['php','-l',str(f)] if f.suffix=='.php' else ['node','--check',str(f)];p=subprocess.run(cmd,capture_output=True,text=True);return f,p.returncode,p.stderr
 with concurrent.futures.ThreadPoolExecutor(max_workers=10) as ex:checks=list(ex.map(lint,entries))
 fail=[(str(f),err) for f,rc,err in checks if rc]
 assert not fail, fail[:20]
 print('PASS R153 ALL LANGUAGE SYNTAX',dict(collections.Counter(f.suffix for f in entries)))
 print('PASS NO RELEASE AUTHORIZATION',lm['for_install'],lm['production_pass'],manifest['provenance']['production_status'])