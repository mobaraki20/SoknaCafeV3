#!/usr/bin/env python3
"""Fail-closed, read-only Sokna Public Emergency release candidate gate.

Two disjoint modes:
  source --qa-archive PASS27.zip --canonical-archive canonical.zip --registry registry.json
  package --release-archive published.zip [--expected-source-archive QA.zip]

NO server calls, credentials, deployment, or automatic production authorization.
A successful gate proves only integrity/contracts on submitted bytes.
"""
from __future__ import annotations
import argparse, hashlib, io, json, pathlib, posixpath, re, stat, sys, zipfile

HEX64=re.compile(r'^[a-f0-9]{64}$')
BUILD_RE=re.compile(r'workspace\.[a-z0-9]+-r(\d+)(?:-|$)',re.I)
PROTECTED=('emergency.php','src/Emergency/','resources/update-trust-v1.json',
           'config.php','storage/')
PUBLIC_OWNER='src/Update/emergency-owner-v1.json'
PUBLIC_CANDIDATE='src/Update/EmergencyOwnerCandidate.php'
PUBLIC_VERSION='VERSION.txt'
# Stable Recovery is managed by the full-snapshot format but must keep its exact
# byte pins unless a separately qualified protected-recovery transition is approved.
RECOVERY_PINS={
 'recovery.php':'84093636728846bcd5f2480b2f8b90592518befdb1cba55a805d6472dda8381a',
 'src/Recovery/StableRecoveryService.php':'872c872d90a3146d5bc4286803a11410a57fd4ebfe6a9a80f919cffc8df72bed',
}


def digest(b):return hashlib.sha256(b).hexdigest()
def nozip_error(e):return f'{type(e).__name__}:{e}'

class Gate:
    def __init__(self):self.errors=[];self.checks=[]
    def check(self,condition,name,detail=''):
        passed=bool(condition);self.checks.append({'name':name,'pass':passed})
        if not passed:self.errors.append(name+((': '+str(detail)[:220]) if detail else ''))
        return passed
    def done(self,mode):return {'format':'sokna-emergency-release-gate-v1','mode':mode,'result':'PASS_STATIC_QA_ONLY' if not self.errors else 'FAIL_CLOSED', 'checks':len(self.checks),'passed':sum(v['pass'] for v in self.checks), 'failures':self.errors,'operator_release_authorized':False,'production_authorized':False,'mariadb_tested':False,'host_accepted':False}

def path_safe(name):
    if not name or name.startswith('/') or '\\' in name or '\x00' in name:return False
    parts=name.split('/')
    return not any(x in ('','.', '..') for x in parts)

def zip_file_map(raw,g):
    try:
        z=zipfile.ZipFile(io.BytesIO(raw)); items=z.infolist();names=[i.filename for i in items if not i.is_dir()]
        g.check(len(names)==len(set(names)),'zip_unique_entries')
        g.check(all(path_safe(n) for n in names),'zip_path_safety')
        g.check(all(not stat.S_ISLNK((i.external_attr>>16)&0xffff) for i in items),'zip_no_symlinks')
        g.check(z.testzip() is None,'zip_crc')
        # Never read unbounded archive blobs. Files should remain reasonably small.
        g.check(len(names)<=13000 and sum(i.file_size for i in items)<600_000_000,'zip_limits')
        return z,set(names)
    except (zipfile.BadZipFile,ValueError,KeyError) as e:
        g.check(False,'zip_open',nozip_error(e));return None,set()

def has_dotfile_policy(css):
    # Verify explicit rules are real rewrite rules with [F]; not comments.
    rules=[r.strip() for r in css.splitlines() if r.strip().startswith('RewriteRule') and '[F' in r.upper()]
    policies=['htaccess','htpasswd','env','user','git','svn']
    return bool(rules) and all(any(p in line.lower() for line in rules) for p in policies)

def validate_owner(read,g,label):
    try:
        own=json.loads(read(PUBLIC_OWNER));candidate=read(PUBLIC_CANDIDATE);ver=read(PUBLIC_VERSION).decode('utf-8').strip()
        g.check(own.get('format')=='sokna-emergency-owner-v1',f'{label}_owner_format')
        g.check(own.get('sha256')==digest(candidate),f'{label}_owner_sha_binding')
        g.check(own.get('release_version')==ver,f'{label}_owner_version_binding')
        g.check(BUILD_RE.search(ver) is not None,f'{label}_version_parse',ver)
    except (KeyError,ValueError,UnicodeDecodeError,TypeError) as e:
        g.check(False,f'{label}_owner_missing',nozip_error(e))

def validate_source(source,canonical,register):
    g=Gate(); a,asets=zip_file_map(pathlib.Path(source).read_bytes(),g);c,csets=zip_file_map(pathlib.Path(canonical).read_bytes(),g)
    try: reg=json.loads(pathlib.Path(register).read_text())
    except (ValueError,OSError) as e:reg={};g.check(False,'canonical_registry_read',str(e))
    if a and c:
        prefix='source-working/public/'
        data={n[len(prefix):] for n in asets if n.startswith(prefix)}
        g.check(len([n for n in asets if n.startswith('source-working/')])==875,'source_875_product_files')
        g.check(not any(n=='emergency.php' or n.startswith('src/Emergency/') for n in data),'source_no_protected_leaks')
        g.check('bootstrap.php' in data and 'emergency-next.php' in data,'source_bootstrap_presence')
        for p,pin in RECOVERY_PINS.items():
            g.check(prefix+p in asets and digest(a.read(prefix+p))==pin,'source_recovery_pin_'+p)
        try:
            pin=reg['file_inventory'];g.check(len(pin)==12,'canonical_12_components')
            for f in pin:
                p=f['path'];g.check(p in csets and digest(c.read(p))==f['sha256'] and len(c.read(p))==f['bytes'],'canonical_component_'+p)
                if f['role']=='managed_release':
                    rel=p.replace('managed-reference/','',1)
                    g.check(prefix+rel in asets and digest(a.read(prefix+rel))==f['sha256'],'source_managed_pin_'+rel)
                elif f['role']=='protected_stable':
                    rel=p.replace('protected-reference/','',1)
                    g.check(prefix+rel not in asets,'source_excludes_stable_'+rel)
            g.check(json.loads(c.read('MANIFEST.json'))==reg,'canonical_embedded_registry_match')
        except (KeyError,TypeError,ValueError) as e:g.check(False,'canonical_schema',nozip_error(e))
        def read(rel):return a.read(prefix+rel)
        validate_owner(read,g,'source')
        try:g.check(has_dotfile_policy(read('.htaccess').decode()),'source_dotfile_security_policy')
        except KeyError:g.check(False,'source_dotfile_security_policy','missing .htaccess')
        # This QA source still carries R306 VERSION and must never be published directly.
        g.check('source-working/public/VERSION.txt' in asets,'source_version_present')
    return g.done('source')

def validate_release(release,expected_source=None):
    g=Gate();z,names=zip_file_map(pathlib.Path(release).read_bytes(),g)
    if z:
        g.check('manifest.json' in names,'package_manifest_exists')
        try:m=json.loads(z.read('manifest.json'))
        except (KeyError,ValueError) as e: m={};g.check(False,'package_manifest_json',nozip_error(e))
        g.check(m.get('format')=='sokna-component-package-v1','package_format')
        g.check(m.get('component')=='public-edge','package_component')
        files=m.get('files',[])
        g.check(isinstance(files,list) and len(files)>0 and len(files)<=12000,'package_manifest_file_list')
        if not isinstance(files,list):files=[]
        mapping={};seen=set();good=True
        for f in files:
            if not isinstance(f,dict):good=False;continue
            p=f.get('path','');size=f.get('size');h=f.get('sha256')
            if not isinstance(p,str) or not path_safe(p) or p in seen or not isinstance(size,int) or size<0 or not isinstance(h,str) or not HEX64.fullmatch(h):good=False;continue
            seen.add(p);mapping[p]=h
            try:
                b=z.read('payload/'+p)
                if digest(b)!=h or len(b)!=size:good=False
            except KeyError:good=False
        g.check(good,'package_manifest_file_integrity')
        g.check(set('payload/'+p for p in mapping)==names-{'manifest.json'},'package_no_unlisted_payload')
        bad=[p for p in mapping if any(p==s or p.startswith(s) for s in PROTECTED)]
        g.check(not bad,'package_no_protected_paths',bad[:4])
        g.check(len(mapping)>=3,'package_required_files')
        for p,pin in RECOVERY_PINS.items():
            if p in mapping:g.check(mapping[p]==pin,'package_recovery_pin_'+p)
        for p in [PUBLIC_OWNER,PUBLIC_CANDIDATE,PUBLIC_VERSION,'.htaccess','emergency-next.php','bootstrap.php']:
            g.check(p in mapping,'package_contains_'+p)
        tree=hashlib.sha256()
        for p,h in sorted(mapping.items()):tree.update(p.encode()+b'\0'+h.encode()+b'\n')
        g.check(tree.hexdigest()==m.get('target_managed_tree_sha256'),'package_tree_digest_recomputed')
        def read(rel):return z.read('payload/'+rel)
        validate_owner(read,g,'package')
        try: version=read(PUBLIC_VERSION).decode().strip()
        except (KeyError,UnicodeDecodeError):version=''
        g.check(version==m.get('version'),'package_manifest_version_binding')
        bases=m.get('compatibility',{}).get('supported_baselines',[])
        g.check(isinstance(bases,list) and 1<=len(bases)<=64,'package_has_explicit_baselines')
        current=BUILD_RE.search(version)
        if isinstance(bases,list):
            g.check(all(isinstance(b,dict) and isinstance(b.get('version'),str) and HEX64.fullmatch(str(b.get('managed_tree_sha256',''))) and BUILD_RE.search(b['version']) is not None for b in bases),'package_baselines_have_hashes')
            g.check(bool(current) and all(int(BUILD_RE.search(b['version']).group(1))<int(current.group(1)) for b in bases if isinstance(b,dict) and isinstance(b.get('version'),str) and BUILD_RE.search(b['version'])),'package_forward_version_only')
            g.check(len(set((b.get('version'),b.get('managed_tree_sha256')) for b in bases if isinstance(b,dict)))==len(bases),'package_unique_baselines')
        try:g.check(has_dotfile_policy(read('.htaccess').decode()),'package_dotfile_security_policy')
        except (KeyError,UnicodeDecodeError):g.check(False,'package_dotfile_security_policy')
        # Only valid if the candidate truly corresponds to THIS exact source, not an old R306 package.
        if expected_source:
            with zipfile.ZipFile(expected_source) as src:
                prefix='source-working/public/'
                for p in mapping:
                    try:match=digest(src.read(prefix+p))==mapping[p]
                    except KeyError:match=False
                    if not match:g.check(False,'package_current_source_binding',p);break
                else:g.check(True,'package_current_source_binding')
    return g.done('release')

def main():
    ap=argparse.ArgumentParser();ap.add_argument('mode',choices=['source','release']);ap.add_argument('--qa-archive');ap.add_argument('--canonical-archive');ap.add_argument('--registry');ap.add_argument('--release-archive');ap.add_argument('--expected-source-archive');ap.add_argument('--output');args=ap.parse_args()
    try:
        if args.mode=='source':
            if not all([args.qa_archive,args.canonical_archive,args.registry]):raise ValueError('source mode requires three inputs')
            result=validate_source(args.qa_archive,args.canonical_archive,args.registry)
        else:
            if not args.release_archive:raise ValueError('release mode requires --release-archive')
            result=validate_release(args.release_archive,args.expected_source_archive)
    except (OSError,ValueError,zipfile.BadZipFile,KeyError) as e:result={'result':'FAIL_CLOSED','failures':[nozip_error(e)],'operator_release_authorized':False,'production_authorized':False}
    text=json.dumps(result,ensure_ascii=False,indent=2)+'\n'
    if args.output:pathlib.Path(args.output).write_text(text)
    print(text)
    return 0 if result['result'].startswith('PASS') else 1
if __name__=='__main__':sys.exit(main())