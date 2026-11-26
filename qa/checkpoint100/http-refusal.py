# Disposable CI: authenticate through the actual console, exercise ordinary bridge,
# then verify refusal of protected-owner mutation without trusted drain.
import os,pathlib,json,urllib.request as u,urllib.error as e,http.cookiejar as c,urllib.parse,re,hashlib,subprocess
p=pathlib.Path(os.environ['RUNNER_TEMP'])/'cp100'
root=pathlib.Path('/var/www/sokna-cp100/webroot/public')
contract=json.loads((p/'contract.json').read_text())
plan=json.loads((p/'plan.json').read_text())
base='http://127.0.0.1:18080/public'
op=u.build_opener(u.HTTPCookieProcessor(c.CookieJar()))
def req(path,body=None,headers=None):
 try:
  with op.open(u.Request(base+path,body,headers or {}),timeout=60) as r:return r.status,r.read()
 except e.HTTPError as x:return x.code,x.read()
def api(action,data=None):
 code,b=req('/setup/api.php?action='+action,json.dumps(data).encode() if data is not None else None,{'Content-Type':'application/json'})
 assert code==200,(action,code,b[:250])
 return json.loads(b)
def console(action='',form=None,package=None):
 url='/emergency.php'+('?action='+action if action else '')
 if package is None:
  code,b=req(url,urllib.parse.urlencode(form).encode() if form is not None else None,{'Content-Type':'application/x-www-form-urlencoded'})
 else:
  boundary='cp100TestBoundary'
  body=b''.join(('--'+boundary+'\r\nContent-Disposition: form-data; name="'+k+'"\r\n\r\n'+v+'\r\n').encode() for k,v in form.items())
  body+=('--'+boundary+'\r\nContent-Disposition: form-data; name="package"; filename="bridge.zip"\r\nContent-Type: application/zip\r\n\r\n').encode()+package.read_bytes()+('\r\n--'+boundary+'--\r\n').encode()
  code,b=req(url,body,{'Content-Type':'multipart/form-data; boundary='+boundary})
 assert code==200,(action,code,b[:250])
 return b.decode()
def token(h):return re.search(r'name="csrf" value="([^"]+)"',h)[1]
def read(path):return subprocess.check_output(['sudo','-u','www-data','cat',str(path)])
def sha(b):return hashlib.sha256(b).hexdigest()
def state():return json.loads(read(root/'storage/routine-update-v2/state.json'))
def pinmap(paths):return {n:sha(read(root/n)) for n in paths}
def noerror(h):
 m=re.search(r'<div class="error" role="alert">(.*?)</div>',h,re.S)
 assert m is None,(m[1][:500] if m else '')
s=api('status');assert not s['setup']['installed']
pw='disposable-emergency-CI-only-20261126'
x=api('install',{'csrf':s['csrf'],'db':{'host':'127.0.0.1','port':'3306','name':'sokna_cp100','user':'root','pass':os.environ['SOKNA_CI_DB_PASS']},'create_database':True,'routing_probe_ok':True,'owner_password':'disposable-owner-CI-only-20261126','emergency_password':pw,'storage_dir':'storage'})
assert x['success']
assert api('pair',{'pairing_code':x['pairing_code'],'installation_id':'cp100-fixture','shared_secret':'disposable-CI-pair-secret-only-0123456789','display_name':'QA only'})['success']
# Explicit synthetic historical ledger state, on a fresh installed exact archived tree.
for rel,data in [('routine-update-v2/state.json',{'format':'sokna-public-routine-update-state-v2','schema_version':2,'active_version':contract['origin'],'active_managed_tree_sha256':contract['origin_tree'],'previous_version':'','lkg_recovery_id':'','staged':None,'pending_verification':None,'activation_journal':None,'db_forward_fence':None,'history':[]}),('emergency/update/state.json',{'format':'sokna-public-update-state-v1','schema_version':1,'active_version':'3.0.0-g3.6-workspace.35dx-emergency-routine-reconcile-final','previous_version':'','lkg_recovery_id':'','staged':None,'recovery_required':None,'history':[]})]:
 f=root/'storage'/rel
 subprocess.run(['sudo','-u','www-data','mkdir','-p',str(f.parent)],check=True)
 subprocess.run(['sudo','-u','www-data','tee',str(f)],input=json.dumps(data).encode(),stdout=subprocess.DEVNULL,check=True)
h=console();h=console('login',{'csrf':token(h),'emergency_password':pw});assert 'name="package"' in h
assert req('/emergency-next.php')[0]==403
h=console('stage',{'csrf':token(h)},p/'bridge.zip');noerror(h);assert state()['staged']['package_sha256']==contract['bridge_sha']
h=console('activate',{'csrf':token(h),'confirmation':'activate'});noerror(h)
s=state();assert s['pending_verification']['version']==contract['bridge'] and s['pending_verification']['target_tree_sha256']==contract['bridge_tree']
assert pinmap(contract['stable_before'])==contract['stable_before']
print('PASS actual Apache authenticated stage and activate; protected bytes unchanged')
before=read(root/'storage/routine-update-v2/state.json');pins=pinmap(plan['patch']);h=console()
h=console('commit',{'csrf':token(h),'confirmation':'commit'})
assert 'legacy_recovery_drain_unavailable' in h
assert read(root/'storage/routine-update-v2/state.json')==before and pinmap(plan['patch'])==pins
print('PASS real Emergency rejects missing trusted drain with genuine pending state retained')
(p/'http-refusal-receipt.json').write_text(json.dumps({'status':'PASS_DISPOSABLE_HTTP_REFUSAL_ONLY','bridge_sha':contract['bridge_sha'],'actual_apache':True,'actual_mariadb':True,'stage_activate':True,'missing_drain_refused':True,'field_install_authorized':False}))
