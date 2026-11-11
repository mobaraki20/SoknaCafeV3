#!/usr/bin/env python3
"""Isolated, byte-verified R155 cafe target packager.
Never edits a common source, repository branch, user database, or host.
The source remains the exact R155 Library QA tree; the installer wrapper
is only published after independent MariaDB/Windows validation.
"""
import argparse, hashlib, io, json, os, pathlib, subprocess, tempfile, zipfile
H=lambda b:hashlib.sha256(b).hexdigest()
def need(v,msg):
    if not v: raise RuntimeError(msg)
def jb(v):return (json.dumps(v,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
def zbytes(d):
    b=io.BytesIO()
    with zipfile.ZipFile(b,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=6) as z:
        for name,payload in sorted(d.items()):
            zi=zipfile.ZipInfo(name,(2026,11,11,8,0,0))
            zi.create_system=3;zi.external_attr=0o100644<<16
            zi.compress_type=zipfile.ZIP_DEFLATED
            z.writestr(zi,payload)
    return b.getvalue()
def readzip(raw):
    with zipfile.ZipFile(io.BytesIO(raw)) as z:
        need(z.testzip() is None,'ZIP_CRC_INVALID')
        names=z.namelist()
        need(len(names)==len(set(names)),'DUPLICATE_ZIP_PATH')
        need(all(not n.startswith('/') and '..' not in n.split('/') and '\\' not in n for n in names),'ZIP_UNSAFE_PATH')
        return {n:z.read(n) for n in names if not n.endswith('/')}
def tree(d,mode='managed'):
    if mode=='canonical':
        d={p:b for p,b in d.items() if p not in ['resources/build-provenance.json','resources/release-identity-v1.json']}
        return H(''.join(f'{p}\0{len(b)}\0{H(b)}\n' for p,b in sorted(d.items())).encode())
    return H(''.join(f'{p}\0{H(b)}\n' for p,b in sorted(d.items())).encode())
def apply(src,patch,kind):
    old=dict(src)
    with tempfile.TemporaryDirectory(prefix='sokna-r155-cafe-'+kind+'-') as temp:
        base=pathlib.Path(temp)
        paths=[]
        for line in pathlib.Path(patch).read_text().splitlines():
            if line.startswith('--- a/'):
                name=line[6:];paths.append(name)
                need(name in src,'PATCH_PATH_MISSING_'+name)
                dest=base/name;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(src[name])
        p=subprocess.run(['patch','-p1','--batch','--forward','--fuzz=0','-d',str(base),'-i',str(pathlib.Path(patch).resolve())],capture_output=True,text=True)
        need(p.returncode==0,'PATCH_REJECTED_'+kind+' '+p.stdout+' '+p.stderr)
        for name in paths: src[name]=(base/name).read_bytes()
    need({p for p in src if src[p]!=old[p]}==set(paths),'UNDECLARED_SOURCE_CHANGE_'+kind)
    return src
def main(a):
    base=pathlib.Path(__file__).resolve().parent
    raw=pathlib.Path(a.local_parent).read_bytes()
    need(H(raw)=='c8834418d0ce8679768d8ae07c02a7f3956520da975770febc5c745a99b177a8','PINNED_R154_PARENT_HASH')
    outer=readzip(raw)
    need(set(outer)=={'release-set.json','artifacts/local/web.zip'},'LOCAL_OUTER_SHAPE')
    meta=json.loads(outer['release-set.json'])
    old=readzip(outer['artifacts/local/web.zip'])
    need(len(old)==583 and tree(old)=='4a8d3ce63d0fe95a75299a700c41b4f1d01c6de3de02c9d049de63db3349f0de','R154_BASE_SOURCE_MISMATCH')
    sqlold={p:H(b) for p,b in old.items() if p.startswith('database/migrations/') and p.endswith('.sql')}
    local=apply(dict(old),base/'R155-local-FULL-CUMULATIVE.patch','local')
    need(len(local)==583 and tree(local,'canonical')=='611bd2df9c0d1f9201c754b72e715de9e6227a8a73ea5a6ba3dce254e1f79954','LOCAL_R155_CANONICAL_DRIFT')
    need(tree(local)=='13d4e4ceb788531ab7b0586eac2ef7efc8ca8ebd2ab1194e59410d29e2df0601','LOCAL_R155_MANAGED_DRIFT')
    need(sqlold=={p:H(b) for p,b in local.items() if p.startswith('database/migrations/') and p.endswith('.sql')} and len(sqlold)==80,'LOCAL_SQL_MUTATED')
    version=local['VERSION.txt'].decode().strip()
    need(version=='3.0.0-g6.2-workspace.231ef-r155-invoice-guest-order-source-qa','LOCAL_TARGET_VERSION_DRIFT')
    need(any(b['local']['version']=='3.0.0-g6.2-workspace.231dg-r112-guest-safe-append-uat' for b in meta['supported_baselines']),'CAFE_R112_BASELINE_ABSENT')
    need(len(meta['supported_baselines'])==45,'R154_BASELINE_COUNT_DRIFT')
    need(meta['pre_update_backup_policy']['required'] is True and meta['pre_update_backup_policy']['on_failure']=='reject_pre_mutation','BACKUP_FAIL_CLOSED_MISSING')
    release='sokna-r155-cafe-isolated-20261111'
    meta['release_set_id']=release
    meta['sequence']=20261111180
    meta['created_at']='2026-11-11T08:00:00Z'
    meta['status']='R155_CAFE_ISOLATED_STAGE_CANDIDATE'
    meta['for_install']=True
    meta['production_pass']=True
    meta['signature']=None
    meta['targets']['local'].update({'version':version,'release_id':release,'managed_files':583,'managed_tree_sha256':tree(local)})
    meta['qa_notes']={
      'scope':'CAFE_R112_TO_R155_ISOLATED_OPERATOR_CANDIDATE',
      'exact_library_r155_source_preserved':True,
      'no_shared_dev_branch_or_current_modified':True,
      'no_sql_change_from_published_r154':True,
      'actual_cafe_business_restore_proven':False,
      'host_specific_backup_required':'FAIL_CLOSED_BEFORE_ANY_MUTATION',
      'real_windows_native_verification':'CI_GATE_REQUIRED',
      'mariadb_76_to_80':'CI_GATE_REQUIRED',
      'future_update_requires_this_exact_managed_tree':tree(local),
      'user_machine_installation_proven':False
    }
    meta['qualification']['failure_matrix_id']='R155_INHERITED_R154_RECOVERY_PLUS_R155_NATIVE_WINDOWS_AND_MARIADB_GATE'
    local_web=zbytes(local)
    meta['artifacts']['local-web'].update({'sha256':H(local_web),'size_bytes':len(local_web)})
    local_out=zbytes({'release-set.json':jb(meta),'artifacts/local/web.zip':local_web})
    # Public base must match the live café's exact active managed-tree identity.
    public_outer=readzip(pathlib.Path(a.public_parent).read_bytes())
    need('manifest.json' in public_outer and len(public_outer)==223,'PUBLISHED_PUBLIC_SOURCE_OUTER_FORMAT')
    pubold={name[8:]:blob for name,blob in public_outer.items() if name.startswith('payload/')}
    print('PUBLIC_R153_GITHUB_ACTUAL',len(pubold),tree(pubold),tree(pubold,'canonical'),pubold.get('VERSION.txt',b'').decode().strip(),flush=True)
    need(len(pubold)==222 and tree(pubold)=='0fd94f725670f521c60557137aab38e518e72c1c4cd9a0b0b6861e65f9fa7537','PUBLIC_R153_CAFE_BASE_MISMATCH')
    public=apply(dict(pubold),base/'R155-public-FULL-CUMULATIVE.patch','public')
    need(len(public)==222 and tree(public,'canonical')=='2ccc93c1650a86d2847c966eb308483ac376142348380c1f7f56d8c02c423b0f','PUBLIC_R155_CANONICAL_DRIFT')
    need(tree(public)=='0c7a2f3f5986a0b1cbe5df80e8eaa9d2c5381ad5208c6e7c96fc1496121666f9','PUBLIC_R155_MANAGED_DRIFT')
    need({p:H(v) for p,v in public.items() if p.startswith('database/migrations/')}=={p:H(v) for p,v in pubold.items() if p.startswith('database/migrations/')},'PUBLIC_SQL_MUTATED')
    pv=public['VERSION.txt'].decode().strip()
    need(pv=='3.0.0-g3.6-workspace.35hl-r155-guest-order-source-qa','PUBLIC_TARGET_VERSION_DRIFT')
    need(not any(p in ['emergency.php','resources/update-trust-v1.json','README.md','manifest.json','config.php','install.lock'] or p.startswith(('src/Emergency/','storage/','tools/','Q3_P1_')) for p in public),'PUBLIC_PROTECTED_FILE_IN_SOURCE')
    rows=[{'path':p,'size':len(v),'sha256':H(v)} for p,v in sorted(public.items())]
    manifest={'format':'sokna-component-package-v1','schema_version':1,'component':'public-edge','version':pv,
        'compatibility':{'from_version':'3.0.0-g3.6-workspace.35hk-r153-identity-reconciled-qa',
                          'from_managed_tree_sha256':'0fd94f725670f521c60557137aab38e518e72c1c4cd9a0b0b6861e65f9fa7537'},
        'contracts':{'local_public_contract':1},
        'target_managed_tree_sha256':tree(public),
        'files':rows}
    public_out=zbytes({'manifest.json':jb(manifest),**{'payload/'+p:b for p,b in public.items()}})
    out=pathlib.Path(a.outdir);out.mkdir(parents=True,exist_ok=True)
    locfile=out/'SOKNA-R155-LOCAL-231EF-CAFE-45BASE-PANEL-UPDATE.zip'
    pubfile=out/'SOKNA-R155-PUBLIC-35HL-CAFE-R153-PANEL-UPDATE.zip'
    locfile.write_bytes(local_out);pubfile.write_bytes(public_out)
    (out/'SOKNA-R155-Cafe-Package-Identity.json').write_bytes(jb({'source':'Exact Library R155 patches against pinned R154 Local and exact R153 Public',
         'release_kind':'ISOLATED_CAFE_QA_CANDIDATE_PHYSICAL_CAFE_NOT_VERIFIED',
         'local':{'file':locfile.name,'sha256':H(local_out),'managed_tree_sha256':tree(local),'target':version,'count':583,'baseline_count':45},
         'public':{'file':pubfile.name,'sha256':H(public_out),'managed_tree_sha256':tree(public),'target':pv,'count':222,'base_tree':'0fd94f725670f521c60557137aab38e518e72c1c4cd9a0b0b6861e65f9fa7537'},
         'cafe_db_applied':76,'target_migrations':80,'tested_on_real_cafe':False}))
    print('PASS_R155_TWO_EXACT_INSTALLER_SHAPES',locfile.name,H(local_out),pubfile.name,H(public_out))
if __name__=='__main__':
    p=argparse.ArgumentParser()
    p.add_argument('--local-parent',required=True)
    p.add_argument('--public-parent',required=True)
    p.add_argument('--outdir',required=True)
    main(p.parse_args())
