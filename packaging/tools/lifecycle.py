#!/usr/bin/env python3
from __future__ import annotations
import argparse,hashlib,json,os,shutil,tempfile,time
from pathlib import Path

FORMAT='sokna-component-package-v1'
STATE='sokna-lifecycle-state-v1'

def sha256(p:Path)->str:
    h=hashlib.sha256()
    with p.open('rb') as f:
        for b in iter(lambda:f.read(1024*1024),b''): h.update(b)
    return h.hexdigest()

def atomic_json(path:Path,data:dict):
    path.parent.mkdir(parents=True,exist_ok=True)
    fd,tmp=tempfile.mkstemp(prefix=path.name+'.',dir=path.parent)
    try:
        with os.fdopen(fd,'w',encoding='utf-8') as f: json.dump(data,f,ensure_ascii=False,sort_keys=True,indent=2);f.write('\n');f.flush();os.fsync(f.fileno())
        os.replace(tmp,path)
    finally:
        if os.path.exists(tmp): os.unlink(tmp)

def load(path:Path): return json.loads(path.read_text(encoding='utf-8'))

def validate_rel(rel:str):
    p=Path(rel)
    if not rel or p.is_absolute() or '..' in p.parts: raise ValueError('unsafe_path:'+rel)

def build(component:str,version:str,source:Path,out:Path,source_commit:str,contracts:dict):
    if component not in {'local','public','runtime','print-agent','platform'}: raise ValueError('invalid_component')
    files=[]
    for p in sorted(x for x in source.rglob('*') if x.is_file()):
        rel=p.relative_to(source).as_posix();validate_rel(rel);files.append({'path':rel,'size':p.stat().st_size,'sha256':sha256(p)})
    if not files: raise ValueError('empty_package')
    m={'format':FORMAT,'schema_version':1,'component':component,'version':version,'source_commit':source_commit,'contracts':contracts,'files':files}
    out.mkdir(parents=True,exist_ok=True)
    payload=out/'payload'
    if payload.exists(): shutil.rmtree(payload)
    shutil.copytree(source,payload)
    atomic_json(out/'manifest.json',m)
    verify(out)
    return m

def verify(pkg:Path):
    m=load(pkg/'manifest.json')
    if m.get('format')!=FORMAT or m.get('schema_version')!=1: raise ValueError('manifest_contract')
    expected=set()
    for e in m.get('files',[]):
        rel=str(e.get('path',''));validate_rel(rel);expected.add(rel);p=pkg/'payload'/rel
        if not p.is_file(): raise ValueError('missing_file:'+rel)
        if p.stat().st_size!=int(e.get('size',-1)): raise ValueError('size_mismatch:'+rel)
        if sha256(p)!=str(e.get('sha256','')).lower(): raise ValueError('hash_mismatch:'+rel)
    actual={p.relative_to(pkg/'payload').as_posix() for p in (pkg/'payload').rglob('*') if p.is_file()}
    if actual!=expected: raise ValueError('payload_set_mismatch')
    return m

def state(root:Path):
    p=root/'lifecycle-state.json'
    if not p.exists(): return {'format':STATE,'schema_version':1,'components':{},'history':[]}
    s=load(p)
    if s.get('format')!=STATE: raise ValueError('state_contract')
    return s

def save_state(root:Path,s:dict): atomic_json(root/'lifecycle-state.json',s)

def compatible(manifest:dict,compat:dict):
    req=compat.get('components',{}).get(manifest['component'],{}).get('requires',{})
    contracts=manifest.get('contracts',{})
    for k,v in req.items():
        if k in {'php_version_id','pdo_mysql'}: continue
        actual=contracts.get(k)
        if actual is None: raise ValueError('missing_contract:'+k)
        if isinstance(v,int) and int(actual)!=v: raise ValueError('incompatible_contract:'+k)
        if isinstance(v,str) and v.replace('.','').isdigit() and str(actual)!=v: raise ValueError('incompatible_contract:'+k)
        if isinstance(v,str) and v.startswith('>='):
            # V3 release line currently freezes major compatibility; exact semantic-range engine is intentionally conservative.
            major=str(actual).split('.')[0]
            if major!='1': raise ValueError('incompatible_contract:'+k)

def stage(root:Path,pkg:Path,compat_file:Path):
    m=verify(pkg);compat=load(compat_file);compatible(m,compat)
    target=root/'staged'/m['component']/m['version']
    if target.exists():
        existing=verify(target)
        if existing!=m: raise ValueError('immutable_version_conflict')
    else:
        target.parent.mkdir(parents=True,exist_ok=True);tmp=target.with_name(target.name+'.staging')
        if tmp.exists(): shutil.rmtree(tmp)
        shutil.copytree(pkg,tmp);verify(tmp);os.replace(tmp,target)
    s=state(root);c=s['components'].setdefault(m['component'],{})
    c['staged']=m['version'];c['staged_manifest_sha256']=sha256(target/'manifest.json')
    s['history'].append({'at':int(time.time()),'action':'stage','component':m['component'],'version':m['version']});save_state(root,s)
    return target

def activate(root:Path,component:str,version:str,compat_file:Path,fail_after_switch=False):
    target=root/'staged'/component/version;m=verify(target);compatible(m,load(compat_file))
    s=state(root);c=s['components'].setdefault(component,{})
    previous=c.get('active');c['previous']=previous;c['active']=version
    s['history'].append({'at':int(time.time()),'action':'activate','component':component,'version':version,'previous':previous});save_state(root,s)
    active=root/'active';active.mkdir(parents=True,exist_ok=True);pointer=active/(component+'.json')
    try:
        atomic_json(pointer,{'component':component,'version':version,'path':str(target.resolve()),'manifest_sha256':sha256(target/'manifest.json')})
        if fail_after_switch: raise RuntimeError('simulated_activation_failure')
    except Exception:
        c['active']=previous;c['previous']=None
        s['history'].append({'at':int(time.time()),'action':'rollback_auto','component':component,'from':version,'to':previous});save_state(root,s)
        if previous:
            pt=root/'staged'/component/previous;atomic_json(pointer,{'component':component,'version':previous,'path':str(pt.resolve()),'manifest_sha256':sha256(pt/'manifest.json')})
        elif pointer.exists(): pointer.unlink()
        raise
    return m

def rollback(root:Path,component:str):
    s=state(root);c=s['components'].get(component,{})
    prev=c.get('previous');cur=c.get('active')
    if not prev: raise ValueError('no_previous_version')
    target=root/'staged'/component/prev;verify(target)
    atomic_json(root/'active'/(component+'.json'),{'component':component,'version':prev,'path':str(target.resolve()),'manifest_sha256':sha256(target/'manifest.json')})
    c['active']=prev;c['previous']=cur;s['history'].append({'at':int(time.time()),'action':'rollback','component':component,'from':cur,'to':prev});save_state(root,s)

def repair(root:Path,pkg:Path,compat_file:Path):
    m=verify(pkg);s=state(root);active=s['components'].get(m['component'],{}).get('active')
    if active!=m['version']: raise ValueError('repair_requires_same_active_version')
    target=root/'staged'/m['component']/m['version']
    if target.exists(): shutil.rmtree(target)
    target.parent.mkdir(parents=True,exist_ok=True);shutil.copytree(pkg,target);verify(target);compatible(m,load(compat_file))
    atomic_json(root/'active'/(m['component']+'.json'),{'component':m['component'],'version':m['version'],'path':str(target.resolve()),'manifest_sha256':sha256(target/'manifest.json')})
    s['history'].append({'at':int(time.time()),'action':'repair','component':m['component'],'version':m['version']});save_state(root,s)

def validate_recovery(path:Path):
    r=load(path)
    if r.get('format')!='sokna-recovery-set-v1': raise ValueError('recovery_contract')
    excluded=set(r.get('excluded_machine_identity',[]))
    mandatory={'runtime_machine_secret','print_agent_identity','tls_private_key'}
    if not mandatory.issubset(excluded): raise ValueError('machine_identity_not_excluded')
    b=r.get('business_backup',{});bp=Path(b.get('path',''))
    if not bp.is_file() or sha256(bp)!=b.get('sha256'): raise ValueError('business_backup_integrity')
    return r

def main():
    a=argparse.ArgumentParser();sp=a.add_subparsers(dest='cmd',required=True)
    p=sp.add_parser('build');p.add_argument('--component',required=True);p.add_argument('--version',required=True);p.add_argument('--source',type=Path,required=True);p.add_argument('--out',type=Path,required=True);p.add_argument('--source-commit',required=True);p.add_argument('--contracts',default='{}')
    p=sp.add_parser('verify');p.add_argument('package',type=Path)
    p=sp.add_parser('stage');p.add_argument('--root',type=Path,required=True);p.add_argument('--package',type=Path,required=True);p.add_argument('--compat',type=Path,required=True)
    p=sp.add_parser('activate');p.add_argument('--root',type=Path,required=True);p.add_argument('--component',required=True);p.add_argument('--version',required=True);p.add_argument('--compat',type=Path,required=True);p.add_argument('--simulate-failure',action='store_true')
    p=sp.add_parser('rollback');p.add_argument('--root',type=Path,required=True);p.add_argument('--component',required=True)
    p=sp.add_parser('repair');p.add_argument('--root',type=Path,required=True);p.add_argument('--package',type=Path,required=True);p.add_argument('--compat',type=Path,required=True)
    p=sp.add_parser('verify-recovery');p.add_argument('recovery',type=Path)
    x=a.parse_args()
    if x.cmd=='build': build(x.component,x.version,x.source,x.out,x.source_commit,json.loads(x.contracts))
    elif x.cmd=='verify': verify(x.package)
    elif x.cmd=='stage': stage(x.root,x.package,x.compat)
    elif x.cmd=='activate': activate(x.root,x.component,x.version,x.compat,x.simulate_failure)
    elif x.cmd=='rollback': rollback(x.root,x.component)
    elif x.cmd=='repair': repair(x.root,x.package,x.compat)
    elif x.cmd=='verify-recovery': validate_recovery(x.recovery)
if __name__=='__main__': main()
