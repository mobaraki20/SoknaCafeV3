#!/usr/bin/env python3
import urllib.request,urllib.parse,urllib.error,sys,pathlib,json,re,time
phase=sys.argv[1];w=pathlib.Path('/var/www/sokna-public-host-gate');c=json.loads((w/'contract.json').read_text())
hosts={'root':(18080,''),'sub':(18081,'/public/menu'),'upgrade':(18082,''),'older':(18083,'/public/menu'),'browser306':(18084,''),'current':(18085,'/public'),'latest':(18086,'/public')}
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs):return None
op=urllib.request.build_opener(NoRedirect())
def req(base,path,data=None,cookie=''):
    headers={'Accept':'text/html,application/json'}
    if cookie:headers['Cookie']=cookie
    if data is not None:headers['Content-Type']='application/x-www-form-urlencoded';data=urllib.parse.urlencode(data).encode()
    try:
        with op.open(urllib.request.Request(base+path,data,headers),timeout=20) as r:return r.status,r.read().decode(),r.headers
    except urllib.error.HTTPError as e:return e.code,e.read().decode(),e.headers
def test(name,expect_error=False):
    port,sub=hosts[name];base=f'http://127.0.0.1:{port}{sub}'
    status,body,headers=req(base,'/staff/login?installation_id=sokna-ci-'+name)
    assert status==200 and 'user:1' in body, (name,'login-page',status,body[-300:]) 
    status,body,h=req(base,'/staff/login',{'installation_id':'sokna-ci-'+name,'projection_id':'user:1','password':'disposable-staff-ci-20261124'})
    assert status==303 and 'sokna_staff=' in h.get('Set-Cookie',''),(name,'login',status,body[-500:])
    cookie=h.get('Set-Cookie').split(';')[0]
    if expect_error:
        status,body,h=req(base,'/staff/',cookie=cookie)
        assert 'public_internal_error' in body and '<!doctype html>' in body,(name,'expected original failure',status,body[-400:])
        print('REPRODUCED_35IO_MIXED_HTML_JSON',name);return
    if phase=='upgraded':
        # PHP OPcache can retain the previous compiled bootstrap for its
        # normal timestamp revalidation interval. Never restart Apache or
        # ignore a persistent 500. Verify actual Staff readiness on the
        # existing worker and record any transient rejection.
        start=time.monotonic(); retries=0
        while True:
            code,body,headers=req(base,'/api/staff/ui-preferences',cookie=cookie)
            if code==200 and json.loads(body).get('success') is True:break
            if code!=500 or time.monotonic()-start>=8:
                raise AssertionError((name,'post-upgrade runtime did not converge',code,body[-800:]))
            retries+=1
            print('POST_UPGRADE_TRANSIENT_RUNTIME_READBACK',name,retries,body[-180:],flush=True)
            time.sleep(.5)
        print('PASS_POST_UPGRADE_RUNTIME_READY',name,'retry_count',retries,'elapsed',round(time.monotonic()-start,3))
    for path in ('/staff/','/staff/operator/','/staff/preparation/','/staff/operations/','/staff/subscribers/','/staff/reports/','/staff/notifications/','/staff/health/','/staff/account/'):
        status,body,h=req(base,path,cookie=cookie)
        assert status==200 and 'text/html' in h.get('Content-Type','') and body.lstrip().startswith('<!doctype html>') and body.rstrip().endswith('</html>') and 'public_internal_error' not in body,(name,path,status,h.get('Content-Type'),body[-600:])
        if path=='/staff/':assert 'sc-dashboard--converged' in body,(name,'dashboard missing')
        print('PASS_STAFF_COMPLETE_HTML',phase,name,path)
    if phase in ('clean-populated','upgraded'):
        for path in ('/staff/notifications-adapter','/staff/reports-adapter?action=item_groups','/staff/reports-adapter?action=invoice_history','/api/staff/ui-preferences'):
            status,body,h=req(base,path,cookie=cookie)
            assert status in (200,409,422,403) and 'public_internal_error' not in body,(name,path,status,body[-600:])
            json.loads(body);print('PASS_STAFF_JSON',phase,name,path,status)
    status,body,h=req(base,'/staff/logout',cookie=cookie);assert status==303
    status,body,h=req(base,'/staff/',cookie=cookie);assert status==302
if phase=='regression':test('current',True)
elif phase.startswith('clean-'):
    for n in ('root','sub'):test(n)
elif phase=='upgraded':
    for n in ('latest','current','older','browser306','upgrade'):test(n)
else:raise ValueError(phase)
