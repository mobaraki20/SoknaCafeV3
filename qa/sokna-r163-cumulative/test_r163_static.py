#!/usr/bin/env python3
"""Only inspect source trees, do not mutate user data. CI assertions for R163"""
import json,sys,hashlib
from pathlib import Path
l,p,lo,po=[Path(x) for x in sys.argv[1:]]
H=lambda b:hashlib.sha256(b).hexdigest()
def files(root):return {x.relative_to(root).as_posix():H(x.read_bytes()) for x in root.rglob('*') if x.is_file()}
L,P,O,Q=map(files,[l,p,lo,po])
allowed_l={'VERSION.txt','public/assets/catalog-workspace.js','public/assets/reports-workspace.js','public/assets/scds/guest.css','resources/build-provenance.json','resources/release-identity-v1.json'}
allowed_p={'VERSION.txt','assets/reports-workspace.js','assets/scds/guest.css','resources/build-provenance.json','resources/release-identity-v1.json'}
assert set(L)==set(O) and set(P)==set(Q)
assert {name for name in L if L[name]!=O[name]}==allowed_l
assert {name for name in P if P[name]!=Q[name]}==allowed_p
for path in ['src/Domain/System/GoLiveResetCoordinator.php','src/Domain/System/GoLiveResetEngine.php','resources/golive-clearance-policy.json','public/system/go-live-api.php']:
 assert L[path]==O[path],path
for path in ['src/Maintenance/GoLiveResetEngine.php','src/Http/GoLiveResetHttpAdapter.php','resources/golive-clearance-policy.json']:
 assert P[path]==Q[path],path
assert {name:L[name] for name in L if name.startswith('database/migrations/')}=={name:O[name] for name in O if name.startswith('database/migrations/')}
assert {name:P[name] for name in P if name.startswith('database/migrations/')}=={name:Q[name] for name in Q if name.startswith('database/migrations/')}
cat=(l/'public/assets/catalog-workspace.js').read_text();assert '// CHAT14-076: Enter in the catalog search' in cat
for f in [l/'public/assets/reports-workspace.js',p/'assets/reports-workspace.js']:
 text=f.read_text();assert '// CHAT14-105' in text and "document.addEventListener('visibilitychange',refreshReportOnReturn)" in text
for f in [l/'public/assets/scds/guest.css',p/'assets/scds/guest.css']:
 assert '/* CHAT14-058:' in f.read_text()
print(json.dumps({'pass':True,'scope':'UI-only','changed_local':sorted(allowed_l),'changed_public':sorted(allowed_p),'go_live_clearance_unchanged':True,'migration_files_unchanged':True},ensure_ascii=False))