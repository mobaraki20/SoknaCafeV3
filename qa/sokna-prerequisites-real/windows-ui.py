"""Disposable Windows CI only: run the shipped prerequisites helper through its native UI."""
import os,sys,json,time,secrets,subprocess,socket
from pathlib import Path
from pywinauto.application import Application
assert os.environ.get('GITHUB_ACTIONS')=='true'
exe=Path(sys.argv[1]).resolve(); root=Path(sys.argv[2]).resolve(); evidence=Path(sys.argv[3])
assert root.drive.upper()!=os.environ['SystemDrive'].upper() and root.name.startswith('Sokna-Infra-QA-')
assert not root.exists()
password=secrets.token_hex(18)
def ps(script):
 return subprocess.check_output(['powershell','-NoProfile','-NonInteractive','-Command',script],text=True).strip()
def services():
 return ps("Get-Service -Name SoknaApache,SoknaMariaDB -ErrorAction SilentlyContinue | ForEach-Object { $_.Name+':'+$_.Status }")
assert not services(),'existing infrastructure services; refusing to touch'
with socket.socket() as s:assert s.connect_ex(('127.0.0.1',3306))!=0,'existing DB listener'
state=root/'Infrastructure'/'infrastructure-state.json'
def run_mode(mode,title):
 app=Application(backend='win32').start(str(exe),wait_for_idle=False)
 try:
  dlg=app.window(title='آماده‌سازی زیرساخت SOKNA');dlg.wait('exists ready',timeout=40)
  fields=dlg.descendants(class_name_re=r'WindowsForms10\.EDIT\..*')
  roots=[w for w in fields if ':\\' in w.window_text()]
  assert len(roots)==1,'cannot identify root edit'
  roots[0].set_edit_text(str(root))
  if mode=='install':
   pw=[w for w in fields if w.style() & 0x20]
   assert len(pw)==2,'password controls unavailable'
   for w in pw:w.set_edit_text(password)
  dlg.child_window(title=title).wrapper_object().post_message(0x00F5)
  dlg.child_window(title='شروع').wrapper_object().post_message(0x00F5)
  deadline=time.monotonic()+600
  while time.monotonic()<deadline:
   if state.exists():
    d=json.loads(state.read_text(encoding='utf-8-sig'))
    if d.get('last_operation')==mode:
     assert 'SoknaApache:Running' in services() and 'SoknaMariaDB:Running' in services()
     assert d['safeguards']['existing_mariadb_data_reinitialized'] is False
     return d
   if not app.is_process_running():raise RuntimeError('helper exited before successful state')
   time.sleep(3)
  raise RuntimeError('infrastructure operation did not reach a successful state: '+mode)
 finally:
  if app.is_process_running():app.kill()
def query(sql):
 candidates=list((root/'Infrastructure'/'MariaDB').rglob('mariadb.exe'))
 assert candidates,'MariaDB client missing'
 env=dict(os.environ,MYSQL_PWD=password)
 return subprocess.check_output([str(candidates[0]),'--host=127.0.0.1','--user=root','--batch','--skip-column-names','-e',sql],env=env,text=True).strip()
d=run_mode('install','نصب جدید')
query('CREATE DATABASE sokna_qa_preserve; CREATE TABLE sokna_qa_preserve.marker (value INT NOT NULL); INSERT INTO sokna_qa_preserve.marker VALUES (20261125)')
assert query('SELECT value FROM sokna_qa_preserve.marker')=='20261125'
d=run_mode('repair','تعمیر نصب موجود')
assert query('SELECT value FROM sokna_qa_preserve.marker')=='20261125'
# Recover is tested only for services created on this fresh disposable runner.
ps("Stop-Service SoknaApache,SoknaMariaDB -ErrorAction Stop; sc.exe delete SoknaApache | Out-Null; sc.exe delete SoknaMariaDB | Out-Null")
for _ in range(30):
 if not services():break
 time.sleep(1)
assert not services(),'service registration removal incomplete'
d=run_mode('recover','بازیابی بعد از نصب مجدد ویندوز')
assert query('SELECT value FROM sokna_qa_preserve.marker')=='20261125'
port=int(d['endpoints']['local_web']['port'])
import urllib.request
web=root/'Web'/'public'
probe=web/'sokna-qa-health.php'
try:
 probe.write_text('<?php echo "SOKNA-QA-PHP-OK";',encoding='utf-8')
 assert urllib.request.urlopen(f'http://127.0.0.1:{port}/sokna-qa-health.php',timeout=10).read()==b'SOKNA-QA-PHP-OK'
finally:probe.unlink(missing_ok=True)
evidence.mkdir(parents=True,exist_ok=True)
(evidence/'infra-receipt.json').write_text(json.dumps(dict(status='PASS',non_system_volume=root.drive,fresh=True,repair=True,recover_service_registration=True,real_db_marker_preserved=True,apache_php_http=True,local_web_installed=False,operator_authorized=False),indent=2))
