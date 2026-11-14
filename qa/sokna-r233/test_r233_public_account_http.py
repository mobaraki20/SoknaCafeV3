#!/usr/bin/env python3
"""Two separately running real PHP apps, disposable CI-only, authenticated Public mobile staff UX and password HTTP."""
import os, json, re, urllib.request, urllib.error, time, sys, pathlib
T=pathlib.Path(os.environ['RUNNER_TEMP'])
if os.environ.get('GITHUB_ACTIONS')!='true' or os.environ.get('SOKNA_R233_DISPOSABLE_ONLY')!='YES':raise RuntimeError('REFUSE_NON_CI_ENV')
login=json.loads((T/'r233-http-credentials.json').read_text()); session=json.loads((T/'r233-http-session.json').read_text());cookie='sokna_staff='+session['token']
def req(path,method='GET',body=None,csrf=None,header_cookie=True):
 h={'Accept':'text/html' if method=='GET' else 'application/json'}
 if header_cookie:h['Cookie']=cookie
 if body is not None:h['Content-Type']='application/json'
 if csrf is not None:h['X-Sokna-Account-CSRF']=csrf
 u=urllib.request.Request('http://127.0.0.1:18082'+path,data=json.dumps(body).encode() if body is not None else None,headers=h,method=method)
 try:
  with urllib.request.urlopen(u,timeout=7) as response:return response.status,response.read().decode('utf8','replace')
 except urllib.error.HTTPError as e:return e.code,e.read().decode('utf8','replace')
def ok(value,name):
 if not value:raise AssertionError('FAIL_R233_HTTP_'+name)
 print('PASS_R233_HTTP_'+name,flush=True)
for _ in range(25):
 try:status,page=req('/staff/account/');break
 except Exception:time.sleep(.15)
else:raise RuntimeError('PUBLIC_HTTP_NEVER_STARTED')
ok(status==200,'AUTHENTICATED_PUBLIC_ACCOUNT_PAGE_HTTP_200');ok('data-public-account-root' in page and 'data-public-password-form' in page,'PUBLIC_PROFILE_AND_PASSWORD_FORM_RENDER');ok('public-account.js' in page,'PUBLIC_ACCOUNT_JS_ATTACHED_TO_REAL_PAGE');ok('حساب کاربری' in page and 'sc-mobile-bottom-nav' in page,'PUBLIC_MOBILE_ACCOUNT_NAVIGATION_VISIBLE');
match=re.search(r'data-public-account-root\s+data-csrf="([^"]+)"',page)
if not match: match=re.search(r'data-csrf="([^"]+)"',page)
ok(bool(match),'PUBLIC_ACCOUNT_CSRF_PRESENT');csrf=match.group(1)
ok(len(csrf)==64,'PUBLIC_ACCOUNT_CSRF_NOT_EMPTY');
status,noAuth=req('/staff/account/',header_cookie=False);ok((status in (302,303,401,403)) or (status==200 and 'data-public-account-root' not in noAuth and 'login' in noAuth.lower()),'PUBLIC_ACCOUNT_REQUIRES_SESSION')
status,_=req('/api/staff/account/password','POST',{'current_password':login['old'],'new_password':login['new'],'confirm_password':login['new']});ok(status==403,'PUBLIC_PASSWORD_CHANGE_REJECTS_MISSING_CSRF')
status,_=req('/api/staff/account/password','POST',{'current_password':login['old'],'new_password':login['new'],'confirm_password':login['new']},csrf='0'*64);ok(status==403,'PUBLIC_PASSWORD_CHANGE_REJECTS_BAD_CSRF')
status,wrong=req('/api/staff/account/password','POST',{'current_password':'R233-wrong-old','new_password':login['new'],'confirm_password':login['new']},csrf=csrf);ok(status==422 and 'current_password_invalid' in wrong,'PUBLIC_PASSWORD_CHANGE_CHECKS_CURRENT_PASSWORD')
# A forged mutation via general realtime endpoint must not gain server-side validation/privilege.
forged={'kind':'account.password_change','request_id':'pwd-'+os.urandom(10).hex(),'created_at':'2026-11-15T00:00:00Z','expires_at':'2026-11-15T00:01:00Z','payload':{'nonce':'ABC','ciphertext':'DEF'}}
status,forgery=req('/api/staff/realtime','POST',forged);ok(status in (400,403),'GENERIC_REALTIME_PASSWORD_MUTATION_REJECTED')
# Offline reject with no queued password.
import subprocess
helper=[sys.executable]  # actual PHP executable used below
subprocess.run(['php','qa/sokna-r233/seed_r233_public_account.php','public-outage'],check=True)
status,offline=req('/api/staff/account/password','POST',{'current_password':login['old'],'new_password':login['new'],'confirm_password':login['new']},csrf=csrf)
ok(status==503 and 'local_unavailable' in offline,'PUBLIC_PASSWORD_CHANGE_FAILS_CLOSED_WHEN_LOCAL_OFFLINE')
subprocess.run(['php','qa/sokna-r233/seed_r233_public_account.php','public-online'],check=True)
status,queued=req('/api/staff/account/password','POST',{'current_password':login['old'],'new_password':login['new'],'confirm_password':login['new']},csrf=csrf)
ok(status==202,'PUBLIC_PASSWORD_CHANGE_ENQUEUED_OVER_HTTP')
response=json.loads(queued);rid=response.get('request_id','');ok(rid.startswith('pwd-'),'PUBLIC_PASSWORD_RELAY_REQUEST_ID_CREATED')
(T/'r233-http-command.json').write_text(json.dumps({'request_id':rid}))
status,publicPage=req('/staff/account/');ok(status==200,'PUBLIC_PROFILE_STILL_AVAILABLE_WHILE_PASSWORD_PENDING')
print('PASS_R233_HTTP_PRE_RELAY_13_CHECKS',flush=True)