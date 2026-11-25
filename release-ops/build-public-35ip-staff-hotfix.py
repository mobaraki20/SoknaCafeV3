#!/usr/bin/env python3
"""Build exact 35ip hotfix from the published 35io artifact. CI only."""
import os,pathlib,hashlib,json,zipfile,shutil
r=pathlib.Path(os.environ['RUNNER_TEMP']);w=pathlib.Path('/var/www/sokna-public-host-gate')
h=lambda b:hashlib.sha256(b).hexdigest()
j=lambda x:(json.dumps(x,ensure_ascii=False,sort_keys=True,separators=(',',':'))+'\n').encode()
def locate(d,name,sha):
    hits=list((r/d).rglob(name));assert len(hits)==1
    p=hits[0];assert h(p.read_bytes())==sha,(name,h(p.read_bytes()));return p
def readz(p):
    with zipfile.ZipFile(p) as z:
        assert z.testzip() is None and len(z.namelist())==len(set(z.namelist()))
        return {n:z.read(n) for n in z.namelist()}
def payload(p):
    z=readz(p);m=json.loads(z.pop('manifest.json'));f={n.removeprefix('payload/'):v for n,v in z.items()}
    assert all(h(f[x['path']])==x['sha256'] and len(f[x['path']])==x['size'] for x in m['files'])
    assert tree(f)==m['target_managed_tree_sha256'];return f,m
def tree(f):
    return h(''.join(n+'\0'+h(v)+'\n' for n,v in sorted(f.items())).encode())
def canonical(f):
    return h(''.join(n+'\0'+str(len(v))+'\0'+h(v)+'\n' for n,v in sorted(f.items()) if n not in ('resources/build-provenance.json','resources/release-identity-v1.json')).encode())
def pack(path,files):
    with zipfile.ZipFile(path,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
        for n,b in sorted(files.items()):
            i=zipfile.ZipInfo(n,(2026,11,24,0,0,0));i.create_system=3;i.external_attr=0o100644<<16
            z.writestr(i,b,compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
p=locate('published35io','SOKNA-PUBLIC-35IO-UNIVERSAL-UPDATE.zip','6a8f0d024ce88f2dcd1591c04448083e75527979738ed86980bb721b30630a7e')
clean=locate('published35io','SOKNA-PUBLIC-35IO-CLEAN-WEB.zip','121122166c761af7124199c0c47f11921e65cde3b7668ef0e097a91e817609a1')
old,om=payload(p);full=readz(clean);protected={n:h(v) for n,v in full.items() if n not in old}
assert len(old)==251 and len(protected)==6 and all(full[n]==b for n,b in old.items())
prior,bm=payload(locate('baseline','SOKNA-R306-PUBLIC-35IN-EMERGENCY-PREFLIGHT.zip','e2d78030a7246103a1b4ae2b28409093ebf8e61d44abc3739d73d0ac10b9fe51'))
older,olderm=payload(locate('r304','SOKNA-R304-PUBLIC-35IM-EMERGENCY-CANDIDATE-NOT-FIELD.zip','d7f702322de0890e5483cd987b46860441aba2612d9f4fdc763735b68951721c'))
assert {bm['target_managed_tree_sha256'],olderm['target_managed_tree_sha256']}=={x['managed_tree_sha256'] for x in om['compatibility']['supported_baselines']}
f=dict(old)
s=f['bootstrap.php'].decode()
for cls in ('PublicNotificationToday','PublicDashboardOperationalActivity','PublicItemSalesArchive','PublicInvoiceHistoryArchive'):
    assert 'src/Remote/'+cls+'.php' in f and cls+'.php' not in s
    s=s.replace("require_once __DIR__ . '/src/Remote/RemoteStaffPageRenderer.php';","require_once __DIR__ . '/src/Remote/"+cls+".php';\nrequire_once __DIR__ . '/src/Remote/RemoteStaffPageRenderer.php';")
assert 'src/Auth/UserUiPreferenceService.php' in f and 'UserUiPreferenceService.php' not in s
s=s.replace("require_once __DIR__ . '/src/Auth/PublicAccountPasswordService.php';","require_once __DIR__ . '/src/Auth/PublicAccountPasswordService.php';\nrequire_once __DIR__ . '/src/Auth/UserUiPreferenceService.php';")
f['bootstrap.php']=s.encode()
n='src/Remote/RemoteStaffPageRenderer.php';s=f[n].decode()
a="""        ob_start();
        ProductShell::start($this->core,$user,$meta['title'],$meta['active'],$meta['subtitle'],$page==='operations');
        $this->content($session,$page);
        echo '<script src="'.ProductShell::asset('assets/staff-app/public-page-host.js').'" defer></script>';
        ProductShell::end();
        return $this->pageResponse((string)ob_get_clean());"""
b="""        $level=ob_get_level();
        ob_start();
        try {
            ProductShell::start($this->core,$user,$meta['title'],$meta['active'],$meta['subtitle'],$page==='operations');
            $this->content($session,$page);
            echo '<script src="'.ProductShell::asset('assets/staff-app/public-page-host.js').'" defer></script>';
            ProductShell::end();
            return $this->pageResponse((string)ob_get_clean());
        } catch (\\Throwable $error) {
            while (ob_get_level()>$level) ob_end_clean();
            throw $error;
        }"""
assert s.count(a)==1;f[n]=s.replace(a,b).encode()
n='src/Http/PublicHttpKernel.php';s=f[n].decode();a='            return $this->json(SafeErrors::fromThrowable($error, $supportId));'
b="""            if ($method === 'GET' && ($path === '/staff' || preg_match('#^/staff/(?:operator|preparation|operations|subscribers|reports|notifications|health|account)?/?$#D', $path) === 1)) {
                $ref=htmlspecialchars($supportId, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
                $retry=htmlspecialchars($this->url($path), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
                return $this->withSecurityHeaders($this->response(500,'<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>خطای موقت سامانه</title></head><body><main><h1>صفحه در دسترس نیست</h1><p>خطای داخلی رخ داده است. دوباره تلاش کنید.</p><p>شناسه پیگیری: <code dir="ltr">'.$ref.'</code></p><a href="'.$retry.'">تلاش دوباره</a></main></body></html>',['Content-Type'=>'text/html; charset=utf-8','Cache-Control'=>'no-store']));
            }
            return $this->json(SafeErrors::fromThrowable($error, $supportId));"""
assert s.count(a)==1;f[n]=s.replace(a,b).encode()
ver='3.0.0-g3.6-workspace.35ip-staff-login-hotfix-20261124';rel='sokna-public-35ip-staff-login-hotfix-20261124'
f['VERSION.txt']=(ver+'\n').encode()
o=json.loads(f['src/Update/emergency-owner-v1.json']);o['release_version']=ver;assert o['sha256']==h(f['src/Update/EmergencyOwnerCandidate.php']);f['src/Update/emergency-owner-v1.json']=j(o)
m=json.loads(f['resources/release-identity-v1.json']);m.update(version=ver,release_set_id=rel);m['baseline']['source_versions']=[x['version'] for x in om['compatibility']['supported_baselines']]+[om['version']];m['source'].update(canonical_tree_sha256=canonical(f),source_ref='Exact released 35io SHA '+h(p.read_bytes())+'; missing bootstrap requires and atomic Staff page rendering');m['qualification']={'tier':'EXACT_STAFF_HOTFIX_GATE_REQUIRED','claim_rule':'External receipt must prove exact operator bytes, real Staff login and complete HTML on clean and upgraded hosts.'};f['resources/release-identity-v1.json']=j(m)
q=json.loads(f['resources/build-provenance.json']);q.update(version=ver,release_set_id=rel,classification='CONTROLLED_STAFF_HOTFIX_PENDING_EXACT_GATE',operator_warning='External exact gate receipt required; no automatic deployment',staff_remote_login_qualification='EXTERNAL_EXACT_GATE_REQUIRED',source='sha256:'+h(p.read_bytes()),scope='Staff bootstrap dependencies and exception-safe page rendering only; no DB migrations or updater engine changes');f['resources/build-provenance.json']=j(q)
assert m['source']['canonical_tree_sha256']==canonical(f)
changed=sorted(n for n in old if old[n]!=f[n]);assert set(changed)=={'bootstrap.php','src/Remote/RemoteStaffPageRenderer.php','src/Http/PublicHttpKernel.php','VERSION.txt','src/Update/emergency-owner-v1.json','resources/release-identity-v1.json','resources/build-provenance.json'}
print('EXACT_FUNCTIONAL_CHANGE_FILES',changed)
manifest=json.loads(json.dumps(om));manifest['compatibility']['supported_baselines'].append({'version':om['version'],'managed_tree_sha256':om['target_managed_tree_sha256']})
manifest.update(version=ver,target_managed_tree_sha256=tree(f),files=[{'path':n,'size':len(b),'sha256':h(b)} for n,b in sorted(f.items())])
manifest['provenance'].update(build='35ip urgent Staff hotfix from exact published 35io',origin_package_sha256=h(p.read_bytes()),source_managed_tree_sha256=om['target_managed_tree_sha256'],modified_files=changed,qualification_qa_run='EXTERNAL_EXACT_GATE_RECEIPT_REQUIRED',release_qualification='CONTROLLED_OPERATOR_DISTRIBUTION_PENDING_EXACT_GATE')
candidate=w/'update-exact-qa.zip';pack(candidate,{'manifest.json':j(manifest)}|{'payload/'+n:b for n,b in f.items()})
pfiles={n:full[n] for n in protected};files=f|pfiles
out=r/'verified-clean';out.mkdir(exist_ok=True);cp=out/'SOKNA-PUBLIC-35IP-CLEAN-FROZEN.zip';pack(cp,files)
for name,data,sub in [('root',files,''),('sub',files,'public/menu'),('upgrade',prior|pfiles,''),('older',older|pfiles,'public/menu'),('browser306',prior|pfiles,''),('current',full,'public')]:
    root=w/name/'webroot'/sub;root.mkdir(parents=True)
    for n,b in data.items():
        pp=root/n;pp.parent.mkdir(parents=True,exist_ok=True);pp.write_bytes(b)
(w/'contract.json').write_text(json.dumps({'candidate_sha256':h(candidate.read_bytes()),'clean_sha256':h(cp.read_bytes()),'target':ver,'baseline':bm['version'],'older':olderm['version'],'older_tree':olderm['target_managed_tree_sha256'],'current':om['version'],'current_tree':om['target_managed_tree_sha256'],'target_tree':tree(f),'protected':protected}))
print('FROZEN_PUBLIC_HOTFIX_SHA',h(candidate.read_bytes()),h(cp.read_bytes()))
