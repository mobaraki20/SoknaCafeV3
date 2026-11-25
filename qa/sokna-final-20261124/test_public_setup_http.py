#!/usr/bin/env python3
"""Public clean-install HTTP setup root/subfolder dry run with no DB or host mutation.
Includes URL/path and sensitive preflight checks; not full Apache/MariaDB UAT.
"""
from __future__ import annotations
import argparse,subprocess,urllib.request,json,os,sys,time,pathlib,tempfile,shutil,socket

def get(url):
 with urllib.request.urlopen(url,timeout=7) as r:return r.status,r.read(),dict(r.headers)

def run(root):
 root=pathlib.Path(root).resolve()
 assert (root/'setup/api.php').is_file() and not (root/'config.php').exists(), 'MUST_USE_CLEAN_SOURCE'
 results=[]
 for base in ('','/public/menu'):
  with tempfile.TemporaryDirectory(prefix='sokna-public-setup-') as tmp:
   document=pathlib.Path(tmp)/'www';document.mkdir()
   dest=document/base.lstrip('/') if base else document
   if base:dest.parent.mkdir(parents=True,exist_ok=True)
   shutil.copytree(root,dest,dirs_exist_ok=True)
   port=socket.socket();port.bind(('127.0.0.1',0));n=port.getsockname()[1];port.close()
   p=subprocess.Popen(['php','-S',f'127.0.0.1:{n}','-t',str(document)],stdout=subprocess.DEVNULL,stderr=subprocess.PIPE)
   try:
    for i in range(60):
     try:page=get(f'http://127.0.0.1:{n}{base}/setup/')[0];break
     except Exception:time.sleep(.1)
    else:raise RuntimeError('PHP_SERVER_NOT_READY')
    code,html,headers=get(f'http://127.0.0.1:{n}{base}/setup/index.php')
    assert code==200 and b'<!doctype html>' in html.lower(), 'CLEAN_SETUP_PAGE_BAD'
    code,data,h=get(f'http://127.0.0.1:{n}{base}/setup/api.php?action=status')
    status=json.loads(data)
    assert code==200 and status['success'] is True and status['setup']['installed'] is False
    assert status['setup']['base_path']==base,(status['setup']['base_path'],base)
    assert status['setup']['base_url']==f'http://127.0.0.1:{n}{base}'
    assert status['preflight']['base_path']==base
    assert not (dest/'install.lock').exists() and not (dest/'config.php').exists()
    results.append({'base_path':base or '/','status_code':200,'setup':'new','url_detection':'PASS','no_config_mutation':'PASS'})
   finally:
    p.terminate();p.wait(timeout=5)
 return {'schema':'sokna-public-clean-setup-http-dry-run-v1','tests':results,'real_apache_rewrite':'NOT_RUN','real_mariadb':'NOT_RUN','root_subdir_status':'PASS'}

if __name__=='__main__':
 ap=argparse.ArgumentParser();ap.add_argument('public_root');a=ap.parse_args()
 print(json.dumps(run(a.public_root),ensure_ascii=False,indent=2))