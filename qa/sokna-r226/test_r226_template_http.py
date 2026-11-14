#!/usr/bin/env python3
"""Actual HTTP/CSRF/admin policy on an isolated R225 BrowserSetup installation.
No user host or production DB is contacted.
"""
import os,json,urllib.request,urllib.error,urllib.parse,http.cookiejar,re
assert os.getenv('GITHUB_ACTIONS')=='true' and os.getenv('SOKNA_R225_DISPOSABLE_ONLY')=='YES','DISPOSABLE_CI_ONLY'
fixture=json.load(open(os.environ['RUNNER_TEMP']+'/r226-template-http.json'))
base='http://127.0.0.1:18080';cookies=http.cookiejar.CookieJar()
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self, req, fp, code, msg, hdrs, newurl):return None
client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies),NoRedirect())
checks=0
def check(value,label):
 global checks
 if not value:raise AssertionError('FAIL_R226_HTTP_'+label)
 checks+=1;print('PASS_R226_HTTP_'+label,flush=True)
def req(path,method='GET',payload=None,content_type=None):
 raw=None;hdr={'User-Agent':'Sokna-R226-Disposable-Template-QA'}
 if payload is not None:
  raw=payload
  if content_type:hdr['Content-Type']=content_type
 request=urllib.request.Request(base+path,method=method,data=raw,headers=hdr)
 try:
  with client.open(request,timeout=20) as r:return r.status,dict(r.headers),r.read()
 except urllib.error.HTTPError as e:return e.code,dict(e.headers),e.read()
def post_json(obj):
 s,h,b=req('/printing/api.php','POST',json.dumps(obj,ensure_ascii=False).encode(),'application/json')
 try:return s,json.loads(b)
 except ValueError:
  if s in (301,302,303,401,403):return s,{}
  raise AssertionError('BAD_HTTP_JSON '+repr(b[:500]))
# Reject mutation without session, not just because of CSRF.
s,j=post_json({'action':'print_template_delete','package_id':fixture['inactive'],'csrf_token':'bad'})
check(s in (302,401,403,419) and j.get('success') is not True,'UNAUTHENTICATED_DELETE_DENIED')
st,h,b=req('/login.php')
check(st==200 and b'name="password"' in b,'LOGIN_PAGE_ACCESSIBLE')
login=urllib.parse.urlencode({'username':'qa_r213_admin','password':os.environ['SOKNA_R213_ADMIN_PASSWORD']}).encode()
s,h,b=req('/login.php','POST',login,'application/x-www-form-urlencoded')
check(s in (302,303),'ADMIN_LOGIN_SUCCEEDS')
s,h,b=req('/printing/index.php')
check(s==200 and b'data-printing-workspace' in b,'REAL_PRINTING_PAGE')
m=re.search(rb'data-printing-workspace[^>]*data-csrf="([0-9a-f]{64})"',b)
check(m is not None,'AUTHENTIC_CSRF_FROM_PRINTING_PAGE')
csrf=m.group(1).decode()
s,j=post_json({'action':'print_template_delete','package_id':fixture['inactive'],'csrf_token':'wrong'})
check(s==419 and j.get('success') is not True,'INVALID_CSRF_DENIED')
s,j=post_json({'action':'print_template_delete','package_id':fixture['active'],'csrf_token':csrf})
check(s==409 and j.get('success') is not True,'ACTIVE_TEMPLATE_DELETE_HTTP_DENIED')
s,j=post_json({'action':'print_template_delete','package_id':fixture['inactive'],'csrf_token':csrf})
check(s==200 and j.get('success') is True and j.get('result',{}).get('deleted') is True,'INACTIVE_TEMPLATE_DELETE_HTTP_SUCCEEDS')
s,j=post_json({'action':'print_template_delete','package_id':fixture['inactive'],'csrf_token':csrf})
check(s==404 and j.get('success') is not True,'REPEAT_DELETE_FAILS_CLOSED')
s,h,b=req('/printing/api.php')
check(s==200 and json.loads(b).get('success') is True,'PRINTING_SNAPSHOT_STILL_ACCESSIBLE')
print('PASS_R226_HTTP_TEMPLATE_DELETE_'+str(checks)+'_CHECKS',flush=True)