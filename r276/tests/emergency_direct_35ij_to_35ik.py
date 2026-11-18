#!/usr/bin/env python3
"""Isolated host-like PHP HTTP Emergency integration with real MariaDB and immutable R249/R265/R266/R275 ZIPs.
No credentials or URLs from customer installations. No real-host network requests. Starts from R275 installed baseline.
"""
from pathlib import Path
import os, shutil, zipfile, json, hashlib, subprocess, urllib.request, urllib.parse, urllib.error, http.cookiejar, time, re, random, socket, signal

R=Path(os.environ['SOKNA_INPUT_ROOT']);W=Path(os.environ['SOKNA_FIX_ROOT']);root=W/'tests'/'fixture_http'
if root.exists():shutil.rmtree(root)
root.mkdir(parents=True)
def unpack(path,prefix='payload/',excluded=()):
    with zipfile.ZipFile(path) as z:
        for name in z.namelist():
            if name.startswith(prefix) and not name.endswith('/'):
                rel=name[len(prefix):]
                if rel.startswith(excluded):continue
                p=root/rel;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(z.read(name))
unpack(R/'SOKNA-R275-PUBLIC-35IJ-OWNER-DISCARD-CODE-ONLY-QA-DO-NOT-INSTALL.zip')
with zipfile.ZipFile(R/'SOKNA-R263-PUBLIC-35ID-SELF-CONTAINED-CLEAN-UAT-NO-HOST-DEPLOY.zip') as z:
    for n in z.namelist():
        if (n.startswith('src/Emergency/') or n.startswith('src/Recovery/') or n=='recovery.php' or n=='resources/update-trust-v1.json') and not n.endswith('/'):
            p=root/n;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(z.read(n))
# Byte-exact reference files from the R247C installed protected owner.
# Confirmed exact Git blob SHA; neither file is modified by either update ZIP.
frozen=W/'fixtures'
for name,target,digest in [
 ('emergency.php','emergency.php','b41a7deae19becca160586401a0c4bc33150c43f811d838c5973249bbeb2c6ce'),
 ('EmergencyAccessService.php','src/Emergency/EmergencyAccessService.php','65f5c6fc92f17a5baba3965a69c7f8f2cdabfc5f0d2467240889cc48c8d5a9bb')]:
 content=(frozen/name).read_bytes()
 assert hashlib.sha256(content).hexdigest()==digest, name
 dest=root/target;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(content)
runtime=root.parent/'fixture_http_runtime'
if runtime.exists():shutil.rmtree(runtime)
runtime.mkdir(parents=True)
store=root/'storage';(store/'routine-update-v2').mkdir(parents=True,exist_ok=True)
state={'format':'sokna-public-routine-update-state-v2','schema_version':2,'active_version':'3.0.0-g3.6-workspace.35ij-r275-operator-confirm-public-discard','active_managed_tree_sha256':'91d27d4ddb0fdd0640227b8efed461cde94c7efc3c578d52623056dcb859d57c','previous_version':'','lkg_recovery_id':'','staged':None,'pending_verification':None,'activation_journal':None,'db_forward_fence':None,'history':[]}
(store/'routine-update-v2/state.json').write_text(json.dumps(state))
name='sokna_public_r267_http_qa';dbhost=os.environ.get('SOKNA_QA_DB_HOST','127.0.0.1');user=os.environ.get('SOKNA_QA_DB_USER','root');pw=os.environ['SOKNA_QA_DB_PASSWORD'];password='qa-emergency-pw-do-not-use-in-production'
config={'db':{'host':dbhost,'port':'3306','name':name,'charset':'utf8mb4','user':user,'pass':pw},'app':{'storage_dir':str(store)},'relay':{'secret_encryption_key_base64':'eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHg='}}
# Restrict test fixture content (password test only); CI dir not artifacted.
import base64
(root/'config.php').write_text('<?php return json_decode(base64_decode(\''+base64.b64encode(json.dumps(config).encode()).decode()+'\'),true,512,JSON_THROW_ON_ERROR);\n')
script='''<?php
require $argv[1].'/src/Core/Config.php';require $argv[1].'/src/Core/Database.php';require $argv[1].'/src/Core/Migrations.php';require $argv[1].'/src/Emergency/PublicUpdateException.php';require $argv[1].'/src/Emergency/EmergencyAccessService.php';
$c=\\Sokna\\PublicEdge\\Core\\Config::fromArray(require $argv[1].'/config.php');
$d=\\Sokna\\PublicEdge\\Core\\Database::connect($c);
$d->exec('DROP TABLE IF EXISTS schema_migrations');
$m=new \\Sokna\\PublicEdge\\Core\\Migrations($d,$argv[1].'/database/migrations');
$a=$m->migrate();if(count($a)!==11)throw new RuntimeException('Expected R275 eleven migrations');
$access=new \\Sokna\\PublicEdge\\Emergency\\EmergencyAccessService($argv[1].'/storage');$access->provisionHash(password_hash($argv[2],PASSWORD_DEFAULT),'qa-only');
''' 
# Test database is dedicated and must be created before connecting.
prefix='''<?php
$d=new PDO('mysql:host=' . $argv[1] . ';port=3306;charset=utf8mb4', $argv[2], $argv[3], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$d->exec('CREATE DATABASE IF NOT EXISTS `sokna_public_r267_http_qa` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
'''
(runtime/'qa-db.php').write_text(prefix)
(runtime/'qa-seed.php').write_text(script)
def cmd(args):subprocess.run(args,check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
cmd(['php',str(runtime/'qa-db.php'),dbhost,user,pw]);cmd(['php',str(runtime/'qa-seed.php'),str(root),password]);
serverlog=runtime/'qa-http.log';out=open(serverlog,'wb');proc=subprocess.Popen(['php','-S','127.0.0.1:19867','-t',str(root)],stdout=out,stderr=subprocess.STDOUT)
baseurl='http://127.0.0.1:19867/emergency.php'
opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def request(action='',payload=None,package=None):
    url=baseurl+('?action='+action if action else '')
    if package is not None:
        b='----sokna-qa-'+str(random.randint(100000,999999));raw=b''
        for k,v in payload.items():
            raw+=(('--'+b+'\r\nContent-Disposition: form-data; name="'+k+'"\r\n\r\n'+v+'\r\n').encode())
        data=Path(package).read_bytes()
        raw+=('--'+b+'\r\nContent-Disposition: form-data; name="package"; filename="'+Path(package).name+'"\r\nContent-Type: application/zip\r\n\r\n').encode()+data+b'\r\n'
        raw+=('--'+b+'--\r\n').encode()
        req=urllib.request.Request(url,data=raw,headers={'Content-Type':'multipart/form-data; boundary='+b})
    elif payload is not None:req=urllib.request.Request(url,data=urllib.parse.urlencode(payload).encode(),headers={'Content-Type':'application/x-www-form-urlencoded'})
    else:req=urllib.request.Request(url)
    try:
        with opener.open(req,timeout=50) as response:return response.status,response.read().decode('utf-8','replace')
    except urllib.error.HTTPError as e:return e.code,e.read().decode('utf-8','replace')

def csrf(html):
    m=re.search(r'<input[^>]*name="csrf"[^>]*value="([a-f0-9]{48})"',html)
    if not m:raise RuntimeError('csrf not found: '+html[:500])
    return m.group(1)
def assertmsg(b,fragment):
    if fragment not in b:
        alerts=re.findall(r'<div[^>]*class="(?:alert|warning)"[^>]*>(.*?)</div>',b,re.S)
        import html as _h
        visible=re.sub(r'<script.*?</script>','',b,flags=re.S)
        visible=re.sub(r'<style.*?</style>','',visible,flags=re.S)
        visible=re.sub(r'<[^>]*>',' ',visible)
        visible=re.sub(r'\\s+',' ',_h.unescape(visible))
        status=json.loads((store/'routine-update-v2/state.json').read_text())
        safe={k:status.get(k) for k in ('active_version','staged','pending_verification','db_forward_fence','activation_journal')}
        print('R275_EMERGENCY_ACTIVATE_ERROR_VISIBLE',visible[-4000:])
        print('R275_EMERGENCY_ACTIVATE_ERROR_STATE',safe)
        raise RuntimeError('Expected '+repr(fragment)+' not in page; alerts='+repr(alerts[:4])+' last='+b[-600:])
def post(action,fields=None,pkg=None):
    _,p=request();token=csrf(p);d=dict(fields or {});d['csrf']=token
    status,body=request(action,d,pkg)
    if status!=200:raise RuntimeError(f'HTTP {action} status {status} '+body[:1500])
    return body
try:
    for i in range(50):
        if proc.poll() is not None:raise RuntimeError('PHP HTTP server terminated before test: '+serverlog.read_text()[-2000:])
        try:code,html=request();break
        except Exception:time.sleep(.1)
    else:raise RuntimeError('server did not start')
    assertmsg(html,'دسترسی اضطراری');post('login',{'emergency_password':password})
    code,html=request();assertmsg(html,'خروج امن')
    # The field baseline is ALREADY R275 35ij. Never replay previous releases.
    # Test exactly the real operator's next forward-only protected upgrade.
    current='3.0.0-g3.6-workspace.35ij-r275-operator-confirm-public-discard'
    target='3.0.0-g3.6-workspace.35ik-r276-owner-setup-access'
    assert (root/'VERSION.txt').read_text().strip()==current
    package=R/'SOKNA-R276-PUBLIC-35IK-OWNER-SETUP-QA-DO-NOT-INSTALL.zip'
    assert hashlib.sha256(package.read_bytes()).hexdigest()=='effbe0b41011807341c794b616c4a70c3a999bde730384d6fbee76680f1e2c8d'
    beforeHash=hashlib.sha256((root/'src/Update/emergency-owner-v1.json').read_bytes()).hexdigest()
    b=post('stage',pkg=package);assertmsg(b,'بسته با موفقیت اعتبارسنجی')
    assert (root/'VERSION.txt').read_text().strip()==current, 'STAGE_MUTATED_ACTIVE_VERSION'
    b=post('activate',{'confirmation':'activate'})
    assertmsg(b,'فایل‌ها فعال شدند')
    assert (root/'VERSION.txt').read_text().strip()==target, 'ACTIVATE_TARGET_VERSION_INCORRECT'
    b=post('commit',{'confirmation':'commit'});assertmsg(b,'نسخه جدید')
    assert (root/'VERSION.txt').read_text().strip()==target, 'COMMIT_TARGET_VERSION_INCORRECT'
    state=json.loads((store/'routine-update-v2/state.json').read_text())
    assert (state.get('active_version') or '')==target,state.get('active_version')
    assert all(state.get(k) is None for k in ('staged','pending_verification','db_forward_fence','activation_journal'))
    assert hashlib.sha256((root/'src/Update/emergency-owner-v1.json').read_bytes()).hexdigest()!=beforeHash
    print('R276_DIRECT_35IJ_TO_35IK_PROTECTED_STAGE_ACTIVATE_COMMIT_PASS')

    for name,rel,sha in [
     ('emergency.php','emergency.php','b41a7deae19becca160586401a0c4bc33150c43f811d838c5973249bbeb2c6ce'),
     ('EmergencyAccessService.php','src/Emergency/EmergencyAccessService.php','65f5c6fc92f17a5baba3965a69c7f8f2cdabfc5f0d2467240889cc48c8d5a9bb')]:
      assert hashlib.sha256((root/rel).read_bytes()).hexdigest()==sha, 'protected host file modified '+name
    st=json.loads((store/'routine-update-v2/state.json').read_text());print('R275_FINAL_ROUTINE_STATE',json.dumps({k:st.get(k) for k in ('active_version','previous_version','pending_verification','db_forward_fence','staged','activation_journal')},default=str));assert st['pending_verification'] is None and st['db_forward_fence'] is None and st['staged'] is None
    import textwrap
    verify='''<?php
$c=\\Sokna\\PublicEdge\\Core\\Config::fromArray(require $argv[1].'/config.php');
$p=\\Sokna\\PublicEdge\\Core\\Database::connect($c);
$r=$p->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
if ((int)$r!==11)throw new RuntimeException('Expected exactly eleven migrations, got '.$r);
'''
    (runtime/'qa-verify.php').write_text("<?php require $argv[1].'/src/Core/Config.php';require $argv[1].'/src/Core/Database.php';\n"+verify.removeprefix('<?php'))
    cmd(['php',str(runtime/'qa-verify.php'),str(root)])
    print(json.dumps({'status':'PASS','test':'byte-pinned protected Emergency HTTP auth/CSRF/file upload/stage/activate/commit R275 35ij -> R276 35ik, real MariaDB 11 preserved','database':'11','warning':'Actual production emergency.php host wrapper is external, not byte-pinned'}))
except Exception:
    print('PHP_SERVER_LOG_TAIL',serverlog.read_text(errors='replace')[-14000:])
    raise
finally:
    proc.terminate()
    try:proc.wait(timeout=4)
    except subprocess.TimeoutExpired:proc.kill()
    out.close()
    if proc.returncode not in (0,-signal.SIGTERM):
        print(serverlog.read_text()[-6000:])
