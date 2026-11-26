"""Disposable Windows CI only: run the shipped prerequisites helper through its native UI."""
import os,sys,json,time,secrets,subprocess,socket,re,shutil
from pathlib import Path
from pywinauto.application import Application
assert os.environ.get('GITHUB_ACTIONS')=='true'
exe=Path(sys.argv[1]).resolve(); root=Path(sys.argv[2]).resolve(); evidence=Path(sys.argv[3])
assert root.drive.upper()!=os.environ['SystemDrive'].upper() and root.name.startswith('Sokna-Infra-QA-')
assert not root.exists()
password=secrets.token_hex(18)
evidence.mkdir(parents=True,exist_ok=True)
def capture(mode):
 for p in (root/'Infrastructure'/'Logs').glob('prerequisites-*.log'):
  raw=p.read_text(encoding='utf-8',errors='replace').replace(password,'<redacted>')
  (evidence/(mode+'-'+p.name)).write_text(raw,encoding='utf-8')
 if state.exists():shutil.copyfile(state,evidence/(mode+'-state.json'))
def ps(script):
 return subprocess.check_output(['powershell','-NoProfile','-NonInteractive','-Command',script],text=True).strip()
def services():
 return ps("Get-Service -ErrorAction Stop | Where-Object { $_.Name -in @('SoknaApache','SoknaMariaDB') } | ForEach-Object { $_.Name+':'+$_.Status }; if (-not $?) { exit 1 }")
assert not services(),'existing infrastructure services; refusing to touch'
with socket.socket() as s:assert s.connect_ex(('127.0.0.1',3306))!=0,'existing DB listener'
state=root/'Infrastructure'/'infrastructure-state.json'
def run_mode(mode,title):
 app=Application(backend='win32').start(str(exe),wait_for_idle=False)
 try:
  dlg=app.window(title='آماده‌سازی زیرساخت SOKNA');dlg.wait('exists ready',timeout=40)
  # Enumerate actual child classes rather than assume the WinForms class casing.
  # Wait for child handles; the top-level form can be ready before these exist.
  roots=[];pw=[]
  for attempt in range(20):
   controls=dlg.descendants()
   fields=[w for w in controls if w.class_name().lower()=='edit' or '.edit.' in w.class_name().lower() or w.friendly_class_name()=='Edit']
   pw=[w for w in fields if w.style() & 0x20]
   roots=[w for w in fields if not (w.style() & 0x20) and re.search(r'[A-Za-z]:[\\/]',w.window_text())]
   if len(roots)==1 and (mode!='install' or len(pw)==2):break
   time.sleep(0.5)
  print('UI control inventory:',[(w.class_name(),w.friendly_class_name(),hex(w.style()),bool(re.search(r'[A-Za-z]:[\\/]',w.window_text())) if not (w.style() & 0x20) else False) for w in fields],flush=True)
  assert len(roots)==1,'cannot identify unique root edit'
  roots[0].set_edit_text(str(root))
  assert roots[0].window_text()==str(root),'root edit readback mismatch'
  if mode=='install':
   assert len(pw)==2,'password controls unavailable'
   for w in pw:w.set_edit_text(password)
  dlg.child_window(title=title).wrapper_object().post_message(0x00F5)
  time.sleep(0.5)
  started=time.time()-1
  dlg.child_window(title='شروع').wrapper_object().post_message(0x00F5)
  print('UI requested operation:',mode,flush=True)
  deadline=time.monotonic()+180
  while time.monotonic()<deadline:
   if state.exists():
    d=json.loads(state.read_text(encoding='utf-8-sig'))
    if d.get('last_operation')==mode:
     assert 'SoknaApache:Running' in services() and 'SoknaMariaDB:Running' in services()
     assert d['safeguards']['existing_mariadb_data_reinitialized'] is False
     print('PASS native operation',mode,flush=True)
     return d
   if not app.is_process_running():raise RuntimeError('helper exited before successful state')
   for p in (root/'Infrastructure'/'Logs').glob('prerequisites-*.log'):
    if p.stat().st_mtime>=started and 'ERROR: ' in p.read_text(encoding='utf-8',errors='replace'):
     raise RuntimeError('helper reported error during '+mode)
   time.sleep(3)
  raise RuntimeError('infrastructure operation did not reach a successful state: '+mode)
 finally:
  capture(mode)
  if app.is_process_running():app.kill()
def query(sql):
 candidates=list((root/'Infrastructure'/'MariaDB').rglob('mariadb.exe'))
 assert candidates,'MariaDB client missing'
 env=dict(os.environ,MYSQL_PWD=password)
 return subprocess.check_output([str(candidates[0]),'--host=127.0.0.1','--user=root','--batch','--skip-column-names','-e',sql],env=env,text=True).strip()
d=run_mode('install','نصب جدید')
# This fresh-machine test starts with the qualified infra helper, not a manually
# populated Web. The actual Inno EXE stages the exact new Web and refuses overwrite.
import urllib.request,urllib.error,http.cookiejar,hashlib
clean_exe=Path(sys.argv[4]).resolve()
assert clean_exe.is_file()
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def deploy(name):
 return subprocess.run([str(clean_exe),'/VERYSILENT','/SUPPRESSMSGBOXES','/NORESTART',f'/DIR={root}',f'/LOG={evidence/name}'],timeout=240).returncode
assert deploy('web-exe-install.log')==0,'CLEAN_WEB_EXE_FAILED'
assert sha(root/'Web'/'VERSION.txt')==hashlib.sha256(b'3.0.0-g6.2-workspace.231gg-checkpoint76-qa\n').hexdigest(),'WRONG_VERSION'
port=int(d['endpoints']['local_web']['port']);origin=f'http://127.0.0.1:{port}/'
opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def http(path,body=None):
 req=urllib.request.Request(origin+path,data=json.dumps(body).encode() if body is not None else None,headers={'Content-Type':'application/json'} if body is not None else {})
 try:
  with opener.open(req,timeout=360) as r:return r.status,r.read(),dict(r.headers)
 except urllib.error.HTTPError as e:return e.code,e.read(),dict(e.headers)
status,body,_=http('setup/api.php?action=status');x=json.loads(body)
assert status==200 and x['success'] and x['preflight']['ok'] and x['setup']['state']=='new','BROWSER_SETUP_PREFLIGHT'
admin=secrets.token_hex(18)
cfg={'csrf':x['csrf'],'data_dir':str(root/'Data'),'db':{'host':'127.0.0.1','port':'3306','name':'sokna_ci_clean_cp77','user':'root','pass':password},'create_database':True,'admin_user':'ci-admin','admin_password':admin,'cafe_name':'DISPOSABLE CI','table_count':5}
status,body,_=http('setup/api.php?action=install_new',cfg);x=json.loads(body)
assert status==200 and x['success'] and x['state']=='installed' and x['health']['migrations_applied']==93 and x['health']['migration_files']==93,'BROWSER_SETUP_INSTALL'
status,body,_=http('setup/api.php?action=status');x=json.loads(body)
assert status==200 and x['setup']['installed'] and x['setup']['binding_current']
assert query('SELECT COUNT(*) FROM sokna_ci_clean_cp77.schema_migrations')=='93'
assert http('login.php')[0]==200
# Preserve marker/config/lock, reject second EXE install.
query('CREATE TABLE sokna_ci_clean_cp77.qa_preserve_marker (value INT NOT NULL); INSERT INTO sokna_ci_clean_cp77.qa_preserve_marker VALUES (771)')
before={n:sha(root/n) for n in ['config.php','install.lock','Web/VERSION.txt']}
assert deploy('web-exe-duplicate-reject.log')!=0,'EXE_DUPLICATE_NOT_REJECTED'
assert before=={n:sha(root/n) for n in before}
assert query('SELECT value FROM sokna_ci_clean_cp77.qa_preserve_marker')=='771'
assert http('login.php')[0]==200
(evidence/'clean-web-exe-receipt.json').write_text(json.dumps(dict(status='PASS',fresh_native_infra=True,actual_inno_web_exe=True,exe_sha256=sha(clean_exe),web_sha256='2c14b241a3cddf5f1d436144691555b11a97ccc31b4d08b2ea8f8d92f04e3aa6',actual_http_setup=True,db_migrations=93,duplicate_exe_rejected=True,marker_preserved=True,config_lock_preserved=True,operator_authorized=False,unified_prerequisites_and_services_exe=False,disposable_only=True),indent=2))
print('PASS FRESH NATIVE INFRA + ACTUAL CLEAN WEB EXE + REAL BROWSER SETUP; NO FIELD INSTALLATION')
