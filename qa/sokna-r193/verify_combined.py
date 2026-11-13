#!/usr/bin/env python3
import sys,hashlib,subprocess,json,pathlib
root=pathlib.Path(sys.argv[1]);want='fedec898ab8f42d29874bc3ab7c3dfbedec0f6747537dc3d3799260f48613461'
files=sorted((x for x in root.rglob('*') if x.is_file()),key=lambda p:p.relative_to(root).as_posix());assert len(files)==590,len(files)
for ext,cmd in [('php',['php','-l']),('js',['node','--check'])]:
 selected=[p for p in files if p.suffix=='.'+ext]
 expected=281 if ext=='php' else 49
 assert len(selected)==expected,(ext,len(selected),expected)
 for p in selected:
  check=subprocess.run(cmd+[str(p)],capture_output=True)
  if check.returncode:raise RuntimeError(str(p)+': '+check.stderr.decode()[:350])
 print('PASS_R193_SYNTAX_'+ext.upper()+'_'+str(len(selected)))
actual=hashlib.sha256(''.join(str(p.relative_to(root)).replace('\\','/')+'\0'+hashlib.sha256(p.read_bytes()).hexdigest()+'\n' for p in files).encode()).hexdigest()
if actual!=want:
 expected=json.loads((pathlib.Path(__file__).parent/'expected-source-hashes.json').read_text())
 got={p.relative_to(root).as_posix():hashlib.sha256(p.read_bytes()).hexdigest() for p in files}
 mismatches=[(k,expected.get(k),got.get(k)) for k in sorted(set(got)|set(expected)) if expected.get(k)!=got.get(k)]
 print('R193_BYTEMISMATCH_FILES', json.dumps(mismatches[:30],ensure_ascii=False))
 raise AssertionError((actual,want,len(mismatches)))
assert (root/'VERSION.txt').read_text().strip()=='3.0.0-g6.2-workspace.231ex-r189-login-modal-qa'
assert 'order_cancel_unsettled' in (root/'public/operator/api.php').read_text()
assert (root/'src/Core/RememberDeviceStore.php').exists()
assert (root/'public/assets/guest-content-workspace.js').exists()
print('PASS_R193_590_EXACT_MANAGED_FILES_AND_PRESERVED_R191')
