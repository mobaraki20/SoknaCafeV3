#!/usr/bin/env python3
"""Pre-admission route selector. Compares Update AND full clean owner bytes.
An archive pin is not a live-host attestation. This tool never authorizes install.
"""
import io,json,zipfile,pathlib,stat,sys
from check_owner_admission import H,read,tree,require,protect,strict
P=pathlib.Path
STABLE=('emergency.php','src/Emergency/EmergencyAccessService.php','src/Emergency/PairingSecretStore.php','src/Emergency/PublicTakeoverService.php','src/Emergency/PublicUpdateException.php','src/Emergency/PublicUpdateService.php')
def clean(raw):
    require(len(raw)<=67108864,'CLEAN_SIZE')
    with zipfile.ZipFile(io.BytesIO(raw)) as z:
        rows=z.infolist();names=z.namelist()
        require(len(names)<=12006 and len(set(names))==len(names) and sum(i.file_size for i in rows)<=536870912 and z.testzip() is None,'CLEAN_INTEGRITY')
        require(all(n and not n.startswith('/') and '\\' not in n and '\0' not in n and all(p not in ('','.','..') for p in n.split('/')) for n in names),'CLEAN_PATH')
        require(all(not stat.S_ISLNK(i.external_attr>>16) for i in rows),'CLEAN_SYMLINK')
        return {n:z.read(n) for n in names}
def route(source,old_stable_clean,target,target_clean,contract):
    require(contract['format']=='sokna-factory-public-owner-route-v1','CONTRACT')
    require(H(source)==contract['origin_update_sha256'] and H(old_stable_clean)==contract['origin_stable_archive_sha256'] and H(target)==contract['target_update_sha256'] and H(target_clean)==contract['target_clean_sha256'],'ARCHIVE_PIN')
    om,old=read(source);nm,new=read(target);oc=clean(old_stable_clean);nc=clean(target_clean)
    require(om['version']==contract['origin_version'] and tree(old)==contract['origin_tree'],'ORIGIN')
    require(set(nc)-set(new)==set(STABLE) and set(new)<=set(nc) and all(nc[n]==v for n,v in new.items()),'TARGET_CLEAN_UPDATE_DIVERGE')
    require(set(STABLE)<=set(oc),'ORIGIN_STABLE_MISSING')
    before={n:H(oc[n]) for n in STABLE};require(before==contract['origin_stable_pins'],'ORIGIN_STABLE_PIN')
    after={n:H(nc[n]) for n in STABLE}
    changes=[{'path':n,'before':H(old[n]) if n in old else None,'after':H(new[n]) if n in new else None} for n in sorted(set(old)|set(new)) if protect(n) and old.get(n)!=new.get(n)]
    changes.extend({'path':n,'before':before[n],'after':after[n]} for n in STABLE if before[n]!=after[n])
    allow={(r['version'],r['managed_tree_sha256']) for r in nm.get('compatibility',{}).get('supported_baselines',[])}
    oldsql={n:H(v) for n,v in old.items() if n.startswith('database/migrations/') and n.endswith('.sql')};newsql={n:H(v) for n,v in new.items() if n.startswith('database/migrations/') and n.endswith('.sql')}
    blockers=[]
    if (om['version'],tree(old)) not in allow:blockers.append('ORIGINAL_SOURCE_NOT_ADMITTED')
    if not all(newsql.get(n)==h for n,h in oldsql.items()):blockers.append('HISTORICAL_SQL_DRIFT')
    route='OWNER_TRANSITION_REQUIRED' if changes else ('BLOCKED_BASELINE_OR_SQL' if blockers else 'ROUTINE_STATIC_PER_ORIGIN_PASS')
    return {'route':route,'origin_version':om['version'],'origin_tree':tree(old),'target_version':nm['version'],'target_tree':tree(new),'protected_changes':changes,'blockers':blockers,'stable_evidence_scope':'PINNED_ARCHIVE_NOT_LIVE_HOST','installation_authorized':False,'distribution_authorized':False}
if __name__=='__main__':
    args=sys.argv[1:];assert len(args)==5
    r=route(*(P(n).read_bytes() for n in args[:4]),strict(P(args[4]).read_bytes()));print(json.dumps(r,ensure_ascii=False,indent=2));raise SystemExit(0 if r['route']=='ROUTINE_STATIC_PER_ORIGIN_PASS' else 2)
