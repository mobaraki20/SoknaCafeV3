#!/usr/bin/env python3
"""Real ephemeral Apache/mod_php/MariaDB 11.4 clean setup and legacy-QR regression QA.
No traffic to the real domain: Host headers are sent to 127.0.0.1 in CI.
"""
import http.client,json,urllib.parse,sys,os
HOST='www.soknaspace.ir';APEX='soknaspace.ir';PORT=int(os.getenv('R188_APACHE_PORT','80'))
COOKIE=''
results=[]

def request(path,host=HOST,body=None,expected=None,token=False):
 global COOKIE
 c=http.client.HTTPConnection('127.0.0.1',PORT,timeout=40)
 headers={'Host':host,'X-Forwarded-Proto':'https','User-Agent':'SOKNA-R188-CI-Pinned-Localhost'}
 if COOKIE:headers['Cookie']=COOKIE
 if body is not None:headers['Content-Type']='application/json'
 c.request('POST' if body is not None else 'GET',path,body=json.dumps(body).encode() if body is not None else None,headers=headers)
 r=c.getresponse();data=r.read();header={k.lower():v for k,v in r.getheaders()};c.close()
 if 'set-cookie' in header:COOKIE=header['set-cookie'].split(';',1)[0]
 if expected is not None:assert r.status==expected,(path,host,r.status,header.get('location'),data[:500])
 row={'path':path,'host':host,'status':r.status,'location':header.get('location')}
 results.append(row)
 return r.status,header,data

def main():
 target='https://www.soknaspace.ir/menu'
 for path,host in [('/Menu/',HOST),('/Menu',HOST),('/Menu/',APEX),('/Menu',APEX)]:
  status,h,_=request(path,host,expected=302);assert h['location']==target,(path,h['location'])
 status,h,_=request('/Menu/?table=42&x=a%2Bb',HOST,expected=302);assert h['location']==target+'?table=42&x=a%2Bb'
 status,h,_=request('/Menu?from=qr',APEX,expected=302);assert h['location']==target+'?from=qr'
 status,h,_=request('/Menu/','another.example.org');assert h.get('location')!=target
 for path in ['/menu','/menu/','/staff/login','/api/guest/orders','/setup/']:
  status,h,_=request(path);assert h.get('location')!=target,(path,h)
 status,h,doc=request('/setup/',expected=200);assert b'<!doctype html>' in doc.lower()
 status,h,body=request('/setup/api.php?action=status',expected=200);j=json.loads(body)
 pre=j['preflight'];assert pre['ok'],[(a['id'],a['current']) for a in pre['checks'] if not a['ok']]
 csrf=j['csrf'];assert csrf
 _,_,body=request('/setup/api.php?action=install',body={'csrf':csrf,'routing_probe_ok':True,'storage_dir':'storage','db':{'host':'127.0.0.1','port':'3306','name':'sokna_r188_qa','user':'root','pass':os.environ['SOKNA_R188_TEST_DB_PASSWORD']},'create_database':True,'emergency_password':'R188-ci-Strong-Password!2026'},expected=200)
 installed=json.loads(body)
 assert installed['success'] and installed['installed'] and not installed['paired'],installed
 assert installed['base_url']=='https://www.soknaspace.ir',installed['base_url']
 pairing_code=installed['pairing_code'];assert pairing_code
 _,_,body=request('/setup/api.php?action=pair',body={'pairing_code':pairing_code,'installation_id':'r188-qa-installation','shared_secret':'6d'*32,'display_name':'R188 CI-only'},expected=200)
 paired=json.loads(body);assert paired.get('paired'),paired
 for path in ['/menu','/menu/']:
  status,h,content=request(path,expected=200)
  assert h.get('location') is None
  assert len(content)>200,(path,len(content))
 for path in ['/api/guest/orders','/staff/login']:
  status,h,_=request(path);assert h.get('location')!=target
 for path in ['/Menu','/Menu/?table=42']:
  status,h,_=request(path,expected=302);assert h['location'].startswith(target)
 print('PASS_R188_ACTUAL_APACHE_MARIADB_CLEAN_INSTALL_PAIRING_GUEST_MENU_LEGACY_QR',len(results),'HTTP_CASES',flush=True)
 print(json.dumps({'case_count':len(results),'status_summary':[(x['path'],x['status']) for x in results],'created_db':'sokna_r188_qa','root_base_path':'/','real_domain_called':False,'response_bodies_and_secrets_not_logged':True},indent=2))
if __name__=='__main__':main()
