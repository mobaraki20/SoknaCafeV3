#!/usr/bin/env python3
from pathlib import Path
import re,sys
ROOT=Path(__file__).resolve().parents[3]
def read(p): return (ROOT/p).read_text(encoding='utf-8')
def check(v,m):
    if not v: print('FAIL',m,file=sys.stderr); raise SystemExit(1)
for f in ['apps/local-web/src/UI/LocalPage.php','apps/local-web/src/UI/ProductShell.php']:
    check((ROOT/f).is_file(),f+' missing')
shell=read('apps/local-web/src/UI/ProductShell.php'); page=read('apps/local-web/src/UI/LocalPage.php')
for token in ['aria-current','sc-shell__nav-link','/operator/','/staff/','/waiter/','/admin/']:
    check(token in shell,'shell missing '+token)
for token in ['requireAdmin','requireAny','hasCapability','http_response_code(403)']:
    check(token in page,'page guard missing '+token)
for f in ['apps/local-web/public/index.php','apps/local-web/public/login.php','apps/local-web/public/admin/index.php','apps/local-web/public/operator/index.php','apps/local-web/public/staff/index.php','apps/local-web/public/waiter/index.php']:
    s=read(f)
    check('style=' not in s,f+' introduced inline style island')
    check('scds-' not in s,f+' retained rejected scds-* selectors')
css=read('apps/local-web/assets/scds/components.css')
for token in ['.sc-shell__nav-list','.sc-shell__nav-link','.sc-page-head','.sc-auth','.sc-launcher']:
    check(token in css,'canonical shell CSS missing '+token)
check('!important' not in css,'shell introduced !important')
print('PASS G1 Local shell/admin base contract')
