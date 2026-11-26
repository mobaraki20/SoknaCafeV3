#!/usr/bin/env python3
"""Read-only factory pre-admission for existing Public installations.

A package's own allowlist cannot qualify a source archive. Independently pinned
factory origins are mandatory; no rewriting of manifests or host files.
"""
import argparse, hashlib, io, json, pathlib, re, stat, zipfile
P=pathlib.Path
H=lambda b:hashlib.sha256(b).hexdigest()
PROTECTED=('recovery.php','src/Recovery/','emergency.php','src/Emergency/','resources/update-trust-v1.json')
def require(ok,code):
    if not ok:raise ValueError(code)
def protect(n):return any(n==p or (p.endswith('/') and n.startswith(p)) for p in PROTECTED)
def strict(b):
    def pairs(rows):
        r={}
        for k,v in rows:
            require(k not in r,'DUPLICATE_JSON_KEY');r[k]=v
        return r
    return json.loads(b,object_pairs_hook=pairs)
def tree(d):return H(''.join(n+'\0'+H(b)+'\n' for n,b in sorted(d.items())).encode())
def read(raw):
    require(len(raw)<=67_108_864,'ZIP_TOO_LARGE')
    with zipfile.ZipFile(io.BytesIO(raw)) as z:
        names=z.namelist();require(len(names)<=12001 and len(set(names))==len(names),'ZIP_MEMBERS')
        require(sum(i.file_size for i in z.infolist())<=536870912,'EXPANDED_SIZE')
        for i in z.infolist():
            n=i.filename;require(n and not n.startswith('/') and '\\' not in n and '\x00' not in n and all(p not in ('','.','..') for p in n.split('/')),'UNSAFE_PATH')
            require(not stat.S_ISLNK(i.external_attr>>16),'SYMLINK')
        require(z.testzip() is None,'CRC')
        require('manifest.json' in names and all(n=='manifest.json' or n.startswith('payload/') for n in names),'ZIP_SHAPE')
        m=strict(z.read('manifest.json'));d={n[8:]:z.read(n) for n in names if n.startswith('payload/')}
    require(m.get('format')=='sokna-component-package-v1' and m.get('schema_version')==1 and m.get('component')=='public-edge','FORMAT')
    rows=m['files'];require(len(rows)==len(d) and len({r['path'] for r in rows})==len(rows),'FILE_SET')
    require({r['path']:(r['size'],r['sha256']) for r in rows}=={n:(len(b),H(b)) for n,b in d.items()},'FILE_HASH')
    require(tree(d)==m['target_managed_tree_sha256'],'MANAGED_TREE')
    require(d['VERSION.txt'].decode().strip()==m['version'],'VERSION')
    require(not any(n in ('config.php','install.lock','emergency.php','resources/update-trust-v1.json') or n.startswith(('storage/','src/Emergency/','tools/','Q3_P1_')) for n in d),'RUNTIME_OR_STABLE_IN_PAYLOAD')
    return m,d

def rank(s):
    a=re.search(r'(?:^|-)workspace\.([0-9]{1,8})([a-z]{1,5})(?:-|$)',s);require(a is not None,'VERSION_RANK')
    return int(a[1]),sum((ord(c)-96)*26**(len(a[2])-i-1) for i,c in enumerate(a[2]))

def admit(candidate, origins, contract):
    require(contract['format']=='sokna-factory-public-origin-contract-v1','CONTRACT_FORMAT')
    require(H(candidate)==contract['candidate_sha256'],'CANDIDATE_PIN')
    m,d=read(candidate);require(m['version']==contract['target_version'],'TARGET_VERSION')
    require(len(origins)==len(contract['origins'])>0,'ORIGIN_COUNT')
    a=m.get('compatibility',{}).get('supported_baselines',[])
    require(isinstance(a,list) and 1<=len(a)<=64 and all(isinstance(x,dict) and isinstance(x.get('version'),str) and isinstance(x.get('managed_tree_sha256'),str) and re.fullmatch('[0-9a-f]{64}',x['managed_tree_sha256']) for x in a) and len({(x['version'],x['managed_tree_sha256']) for x in a})==len(a),'ALLOWLIST')
    require(not any(k in m.get('compatibility',{}) for k in ('from_version','from_managed_tree_sha256')),'AMBIGUOUS_BASELINE')
    require(all(rank(m['version'])>rank(x['version']) for x in a),'ALLOWLIST_NOT_FORWARD')
    allow={(x['version'],x['managed_tree_sha256']) for x in a}
    results=[];seen=set()
    for raw in origins:
        h=H(raw);require(h in contract['origins'] and h not in seen,'ORIGIN_PIN');seen.add(h)
        old,b=read(raw);pin=contract['origins'][h]
        require((old['version'],tree(b))==(pin['version'],pin['tree']),'ORIGIN_IDENTITY')
        require(rank(m['version'])>rank(old['version']),'NOT_FORWARD')
        changes=[{'path':n,'old':H(b[n]) if n in b else None,'new':H(d[n]) if n in d else None} for n in sorted(set(d)|set(b)) if protect(n) and b.get(n)!=d.get(n)]
        oldsql={n:H(v) for n,v in b.items() if n.startswith('database/migrations/') and n.endswith('.sql')}
        newsql={n:H(v) for n,v in d.items() if n.startswith('database/migrations/') and n.endswith('.sql')}
        reasons=[]
        if (old['version'],tree(b)) not in allow:reasons.append('ORIGINAL_SOURCE_NOT_ADMITTED')
        if changes:reasons.append('PROTECTED_OWNER_TRANSITION_REQUIRED')
        if not all(newsql.get(n)==sha for n,sha in oldsql.items()):reasons.append('HISTORICAL_MIGRATION_DRIFT')
        results.append({'version':old['version'],'original_tree':tree(b),'protected_changes':changes,'original_source_admitted':(old['version'],tree(b)) in allow,'migration_additions':sorted(set(newsql)-set(oldsql)),'blockers':reasons,'routine_package_eligible':not reasons})
    require(seen==set(contract['origins']),'MISSING_ORIGIN')
    originals={(x['version'],x['original_tree']) for x in results}
    extra=sorted(allow-originals)
    complete=not extra and allow==originals
    ready=complete and all(x['routine_package_eligible'] for x in results)
    return {'status':'STATIC_ROUTINE_COMPATIBILITY_PASS_NOT_RELEASE_AUTHORIZATION' if ready else 'BLOCKED_PROTECTED_OR_UNADMITTED_ORIGIN','candidate_sha256':H(candidate),'target':m['version'],'target_tree':tree(d),'origins':results,'unverified_allowlist_entries':[{'version':v,'managed_tree_sha256':t} for v,t in extra],'all_advertised_origins_verified':complete,'static_routine_gate_pass':ready,'stable_emergency_live_owner_verified':False,'installation_authorized':False,'distribution_authorized':False,'deployment_authorized':False}

def main():
    a=argparse.ArgumentParser();a.add_argument('candidate');a.add_argument('contract');a.add_argument('origins',nargs='+');a.add_argument('--out',required=True);x=a.parse_args()
    r=admit(P(x.candidate).read_bytes(),[P(n).read_bytes() for n in x.origins],strict(P(x.contract).read_bytes()))
    P(x.out).write_text(json.dumps(r,indent=2)+'\n');print(json.dumps(r,indent=2));return 0 if r['static_routine_gate_pass'] else 2
if __name__=='__main__':raise SystemExit(main())
