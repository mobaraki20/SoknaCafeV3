from __future__ import annotations
import hashlib, json, re, shutil, zipfile, pathlib, subprocess, difflib, os, sys
BASE=pathlib.Path(os.environ.get('R262_BASE_ROOT','/mnt/data/r262/local-base'))
QA=pathlib.Path(os.environ.get('R262_OVERLAY_ROOT','/mnt/data/r262/overlay/apps/local-web'))
DEST=pathlib.Path(os.environ.get('R262_MERGED_ROOT','/mnt/data/r262/merged-local'))
OUT=pathlib.Path(os.environ.get('R262_OUTPUT_DIR','/mnt/data'));OUT.mkdir(parents=True,exist_ok=True)
if DEST.exists():shutil.rmtree(DEST)
shutil.copytree(BASE,DEST)
changes={}

def patch(path,old,new,tag):
    p=DEST/path
    raw=p.read_text(encoding='utf-8')
    if raw.count(old)!=1:
        raise ValueError(f'ANCHOR {tag} in {path}: count {raw.count(old)}')
    p.write_text(raw.replace(old,new,1),encoding='utf-8')
    changes[str(path)]=changes.get(str(path),[])+[tag]

def wrap(old,new,tag):patch(pathlib.Path('public/integrations/index.php'),old,new,tag)
# Add ONLY truly new self-contained Public move PHP classes.
for f in sorted((QA/'src/Domain/PublicEdge').glob('PublicMove*.php')):
    target=DEST/'src/Domain/PublicEdge'/f.name
    if target.exists():raise ValueError('UNEXPECTED_EXISTING_MOVE_CLASS '+str(target))
    shutil.copy2(f,target);changes[str(target.relative_to(DEST))]=['new_safe_file']
# Forward-only SQL after all original 81 immutable migrations.
from_file=pathlib.Path(os.environ.get('R262_SQL_SOURCE','/mnt/data/r262/overlay/r261/overlay/local/database/migrations/0082_public_move_owner_approval.sql'))
to_file=DEST/'database/migrations'/from_file.name
if to_file.exists():raise ValueError('0082 already exists')
shutil.copy2(from_file,to_file)
changes[str(to_file.relative_to(DEST))]=['new_forward_only_migration']
# Keep original bootstrap requires & simply add the ordered new classes.
bootstrap=DEST/'bootstrap.php'; s=bootstrap.read_text()
existing="require_once __DIR__ . '/src/Domain/PublicEdge/PublicOriginRebindService.php';"
classes=['PublicMoveDecisionEngine','PublicMoveApprovalMac','PublicMoveIdentityVerifier','PublicMoveReceiptVerifier','PublicMoveApprovalLedger','PublicMoveLocalKeyStore','PublicMoveLocalApprovalService','PublicMoveLocalFlow','PublicMoveUiInspectionService','PublicMoveSimpleReplacement']
insert='\n'.join("require_once __DIR__ . '/src/Domain/PublicEdge/"+cls+".php';" for cls in classes)
patch(pathlib.Path('bootstrap.php'),existing,existing+'\n'+insert,'require_move_classes')
# Reuse R241 existing initial pair method; extra payload flag for fresh empty Public.
client=pathlib.Path('src/Domain/PublicEdge/PublicEdgeSyncClient.php')
patch(client,"public function initialPair(string $baseUrl, string $code, string $newSecret, string $displayName = ''): array",
 "public function initialPair(string $baseUrl, string $code, string $newSecret, string $displayName = '', bool $replacementMode = false): array",'optional_replacement_mode')
raw=(DEST/client).read_text();start=raw.index('public function initialPair(');end=raw.index('public function reenroll(',start)
method=raw[start:end]
old="            'pairing_code' => $code,\n            'shared_secret' => $newSecret,"
if method.count(old)!=1:raise ValueError('missing pairing_code adjacent payload field')
method2=method.replace(old,"            'pairing_code' => $code,\n            'replacement_mode' => $replacementMode,\n            'shared_secret' => $newSecret,")
patch(client,method,method2,'fresh_target_intake_pause_parameter')
# Atomic config commit on 231ff has already-present temp staging functions; add only a short wrapper.
re=pathlib.Path('src/Domain/PublicEdge/PublicReenrollmentService.php')
method='''    /** R262: only after signed independent Public owner + Local admin approvals,
     * normal catalog/media publishing, and exactly-one claimed operation.
     * Existing authority-recovery and initial-pair methods stay unchanged. */
    public function commitMoveConfig(string $baseUrl,string $secret): void
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$secret)) {
            throw new PublicEdgeSyncException('replacement_secret_invalid','کلید اتصال جدید معتبر نیست.',409);
        }
        $canonical=$this->requiredCanonicalBase($baseUrl);
        $staged=$this->stagePublicConfig($canonical,$secret);
        $this->commitPublicConfig($staged);
    }

'''
patch(re,'    private function requiredCanonicalBase(string $baseUrl): string',method+'    private function requiredCanonicalBase(string $baseUrl): string','add_atomic_config_cutover')
# The new API actions from QA, no existing R241 action replaced. Keep Local admin + WebAction CSRF.
api=pathlib.Path('public/integrations/api.php')
lines=(QA/api).read_text().splitlines(keepends=True)
start=next(i for i,line in enumerate(lines) if "if(in_array($action,['public_move_begin'" in line)
end=next(i for i,line in enumerate(lines) if "if($action==='accommodation_config_save')" in line)
block=''.join(lines[start:end]);assert 'public_move_inspect' in block and 'public_move_finish' in block
anchor="    if($action==='accommodation_config_save')"
patch(api,anchor,block+anchor,'minimal_move_api_additive')
# Public panel has a single principal input and button. Keep repair and origin old behavior
# accessible as advanced disclosure, unchanged byte-for-byte inside.
page=pathlib.Path('public/integrations/index.php')
q=(QA/page).read_text();marker='<article class="sc-card" data-public-move-surface>'
start=q.index('    '+marker);end=q.index('    <article class="sc-card"><div class="sc-card__body sc-stack"><div class="sc-section-head"><div><h3>وضعیت همگام‌سازی',start)
card=q[start:end]
if 'data-public-move-form' not in card:raise ValueError('QA UI block absent')
anchor='    <article class="sc-card"><div class="sc-card__body sc-stack"><div class="sc-section-head"><div><h3>وضعیت همگام‌سازی'
patch(page,anchor,card+anchor,'add_one_url_button')
original=(DEST/page).read_text()
start="    <article class=\"sc-card\" data-public-replacement-panel>"
if original.count(start)!=1:raise ValueError('R241 recovery panel not unique')
patch(page,start,
      '    <details class="sc-connection-advanced" data-public-recovery-advanced><summary class="sc-disclosure__summary"><strong>ابزارهای بازیابی پیشرفته</strong></summary>\n'+start,
      'collapse_recovery_advanced')
anchor="    <article class=\"sc-card\"><form class=\"sc-card__body sc-form\" data-lan-staff-form"
patch(page,anchor,'    </details>\n'+anchor,'end_advanced_before_lan')
# R261 JS whole minimal move block is fully standalone under existing event loop,
# relying only on existing '$', feedback, post, refresh, SoknaUI utilities.
js=pathlib.Path('public/assets/connections-workspace.js')
qlines=(QA/js).read_text().splitlines(keepends=True)
start=next(i for i,line in enumerate(qlines) if line.startswith('// R260: replace an unreachable Public'))
end=next(i for i,line in enumerate(qlines) if line.startswith("$('[data-public-origin-target]')"))
block=''.join(qlines[start:end]);assert block.count('public_move_confirm')==1 and block.count('public_move_finish')==1
anchor="$('[data-public-origin-target]')?.addEventListener('input'"
patch(js,anchor,block+anchor,'add_one_button_owner_approval_and_resume')
# Mark unqualified candidate, never build installable release-set until native Windows gate.
patch(pathlib.Path('VERSION.txt'),(DEST/'VERSION.txt').read_text(),
      '3.0.0-g6.2-workspace.231fg-r262-public-move-qa\n','qa_version_not_installable')
# Byte preservation: EVERY old file unchanged unless in this explicit 7-file allowlist.
allowed={'bootstrap.php','src/Domain/PublicEdge/PublicEdgeSyncClient.php','src/Domain/PublicEdge/PublicReenrollmentService.php','public/integrations/api.php','public/integrations/index.php','public/assets/connections-workspace.js','VERSION.txt'}
old={p.relative_to(BASE).as_posix():hashlib.sha256(p.read_bytes()).hexdigest() for p in BASE.rglob('*') if p.is_file()}
new={p.relative_to(DEST).as_posix():hashlib.sha256(p.read_bytes()).hexdigest() for p in DEST.rglob('*') if p.is_file()}
changed=[p for p in old if new.get(p)!=old[p]]
added=[p for p in new if p not in old]
if set(changed)!=allowed:raise ValueError('MODIFIED_BASE_SET_NOT_EXACT expected='+str(sorted(allowed))+' actual='+str(sorted(changed)))
if any(p.endswith('.sql') for p in changed):raise ValueError('LEGACY_SQL_MODIFIED')
expectedAdd={'database/migrations/0082_public_move_owner_approval.sql'}|{'src/Domain/PublicEdge/'+f.name for f in (QA/'src/Domain/PublicEdge').glob('PublicMove*.php')}
if set(added)!=expectedAdd:raise ValueError('NEW_FILES_SET_MISMATCH '+str(set(added)^expectedAdd))
assert len([x for x in new if x.startswith('database/migrations/') and x.endswith('.sql')])==82
assert len(old)==593 and len(new)==len(old)+11
# QA full candidate ZIP source, explicitly not installable with Maintenance.
pkg=OUT/'SOKNA-R262-LOCAL-231FF-FULL-CUMULATIVE-QA-SOURCE-NOT-INSTALLABLE.zip'
with zipfile.ZipFile(pkg,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=6) as z:
 for path in sorted(new):
  info=zipfile.ZipInfo(path,date_time=(2026,11,16,0,0,0)); info.compress_type=zipfile.ZIP_DEFLATED; info.external_attr=0o644<<16
  z.writestr(info,(DEST/path).read_bytes(),compress_type=zipfile.ZIP_DEFLATED,compresslevel=6)
report={'phase':'SOURCE_MERGE_QA_ONLY_NO_INSTALL','base':'R241/231ff 593 managed files, 81 SQL migrations','base_version':(BASE/'VERSION.txt').read_text().strip(),
 'base_outer_sha256':hashlib.sha256(pathlib.Path(os.environ.get('R262_SOURCE_PACKAGE','/mnt/data/r261_inputs/SOKNA-R241-LOCAL-231FA-CUMULATIVE-CONNECTION-RECOVERY.zip')).read_bytes()).hexdigest(),
 'base_inner_sha256':'a692a9bc639516da48e0c4d798b97b14e514f2c103ec02bc083f912d1e70dc9b',
 'original_files':len(old),'new_files':len(new),'unchanged_original_files':len(old)-len(changed),
 'modified_original_paths':changed,'new_paths':sorted(added),'sql_existing_81_byte_identical':True,
 'migrations_after':82,'change_operations':changes,
 'zip_path':str(pkg),'zip_sha256':hashlib.sha256(pkg.read_bytes()).hexdigest(),
 'limitations':['source ZIP is NOT a Maintenance release-set, no native Windows install','Public fresh payload release not merged or signed','Local R241 engine actual runtime E2E pending']}
(OUT/'R262-LOCAL-MERGE-REPORT.json').write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
print(json.dumps({'ok':True,'original':len(old),'new':len(new),'changed_existing':len(changed),'added':len(added),'sql':82,'sha256':report['zip_sha256'],'path':str(pkg)},ensure_ascii=False,indent=2))