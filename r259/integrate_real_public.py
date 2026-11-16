#!/usr/bin/env python3
"""R259 QA source overlay on the immutable R249 -> R256 -> R257 Public.
Add only owner approval page plus static assets and route. No installer, deploy,
new tenant default, legacy migration rewrite, order routing or cutover changes.
"""
from __future__ import annotations
import argparse,hashlib,json,pathlib,subprocess,sys

def digest(path:pathlib.Path)->str:return hashlib.sha256(path.read_bytes()).hexdigest()
def replace_one(path:pathlib.Path,old:str,new:str)->None:
    content=path.read_text(encoding='utf-8')
    if content.count(old)!=1:raise RuntimeError('R259_ANCHOR_NOT_UNIQUE: '+str(path))
    path.write_text(content.replace(old,new,1),encoding='utf-8')

arg=argparse.ArgumentParser()
arg.add_argument('--r249',type=pathlib.Path,required=True)
arg.add_argument('--repo',type=pathlib.Path,required=True)
arg.add_argument('--out',type=pathlib.Path,required=True)
a=arg.parse_args()
subprocess.run([sys.executable,str(a.repo/'r257/integrate_real_public.py'),
    '--r249',str(a.r249),'--repo',str(a.repo),'--out',str(a.out)],check=True)
root=a.out/'qa-public-payload'
before={p.relative_to(root).as_posix():digest(p) for p in root.rglob('*') if p.is_file()}
if len(before)!=239:raise RuntimeError('R259_BASELINE_R257_FILE_COUNT_CHANGED')
for path in ['src/Http/PublicMoveConsentPage.php','assets/sokna-public-move.js','assets/sokna-public-move.css']:
    source=a.repo/'apps/public'/path
    if not source.is_file():raise RuntimeError('R259_SOURCE_MISSING_'+path)
    target=root/path
    target.parent.mkdir(parents=True,exist_ok=True)
    target.write_bytes(source.read_bytes())
bootstrap=root/'bootstrap.php'
anchor="require_once __DIR__ . '/src/Http/PublicMoveOwnerHttpAdapter.php';"
replace_one(bootstrap,anchor,
    "require_once __DIR__ . '/src/Http/PublicMoveConsentPage.php';\n"+anchor)

kernel=root/'src/Http/PublicHttpKernel.php'
anchor="            if ($method === 'POST' && preg_match('#^/api/v1/owner/(upgrade_preflight|upgrade_activate)$#D', $path, $upgradeMatch) === 1) {"
route="""            if ($method === 'GET' && $path === '/move/approve') {
                return \\Sokna\\PublicEdge\\Http\\PublicMoveConsentPage::render(
                    $this->core->config()->string('app.base_url')
                );
            }
            if ($method === 'GET' && $path === '/assets/sokna-public-move.js') {
                return $this->staticFile($this->componentRoot.'/assets/sokna-public-move.js','application/javascript; charset=utf-8',300);
            }
            if ($method === 'GET' && $path === '/assets/sokna-public-move.css') {
                return $this->staticFile($this->componentRoot.'/assets/sokna-public-move.css','text/css; charset=utf-8',300);
            }
"""
replace_one(kernel,anchor,route+anchor)

after={p.relative_to(root).as_posix():digest(p) for p in root.rglob('*') if p.is_file()}
added=sorted(set(after)-set(before))
changed=sorted(k for k in before if after[k]!=before[k])
expected_added=['assets/sokna-public-move.css','assets/sokna-public-move.js','src/Http/PublicMoveConsentPage.php']
if added!=expected_added or changed!=['bootstrap.php','src/Http/PublicHttpKernel.php']:
    raise RuntimeError('R259_UNEXPECTED_SOURCE_CHANGE')
if any(k.startswith('database/migrations/') for k in changed):
    raise RuntimeError('R259_UNEXPECTED_MIGRATION_CHANGE')
for name in [k for k in before if k.startswith('database/migrations/')]:
    if before[name]!=after[name]:raise RuntimeError('R259_MIGRATION_BYTES_CHANGED')
proof={'format':'sokna-r259-owner-approval-page-audit-v1',
       'base':'pinned-R249-via-R257','prior_files':len(before),
       'qa_files':len(after),'prior_migrations_unchanged':True,
       'added_files':added,'changed_files':changed,
       'operator_zip_created':False,'cutover_allowed':False,'production_modified':False}
(a.out/'R259-REAL-PUBLIC-QA-PROOF.json').write_text(json.dumps(proof,indent=2)+'\n',encoding='utf-8')
print('R259_REAL_PUBLIC_PAGE_OVERLAY_PASS files='+str(len(after)))
