#!/usr/bin/env python3
from __future__ import annotations
import json,re
from pathlib import Path

R=Path(__file__).resolve().parents[1]
def fail(msg:str)->None: raise SystemExit('P6 INTEGRATED REGRESSION CONTRACT FAILED: '+msg)

m=json.loads((R/'release/p6-integrated-regression-v1.json').read_text(encoding='utf-8'))
if m.get('format')!='sokna-p6-integrated-regression-v1' or m.get('schema_version')!=1: fail('manifest metadata invalid')
for section in ('linux_real_env','windows_real_env'):
    item=m.get(section,{})
    status=str(item.get('status',''))
    if status not in {'READY','PENDING'}: fail(section+' status invalid')
    if not str(item.get('terminal','')).endswith('PASS'): fail(section+' exact PASS terminal missing')
    runner=item.get('runner','')
    if status=='READY' and (not runner or not (R/runner).is_file()): fail(section+' READY runner missing')
manual=json.loads((R/m.get('manual_uat_source','')).read_text(encoding='utf-8'))
required=set(m.get('manual_uat_not_automated',[]))
checks={str(c.get('id')):str(c.get('status')) for c in manual.get('checks',[])}
if not required or not required <= set(checks): fail('manual UAT exclusions incomplete')
if any(checks[x] not in {'pending','passed','failed'} for x in required): fail('manual UAT status invalid')

components=json.loads((R/'COMPONENTS.json').read_text(encoding='utf-8'))['components']
compat=json.loads((R/'release/compatibility-v2.json').read_text(encoding='utf-8'))['components']
owned={
 'local-web':(R/'apps/local-web/VERSION.txt').read_text().strip(),
 'public-edge':(R/'apps/public/VERSION.txt').read_text().strip(),
 'windows-services-packaging':(R/'packaging/windows/WINDOWS_SERVICES_VERSION.txt').read_text().strip(),
}
runtime=(R/'windows/runtime/source/Sokna.Runtime.Service.csproj').read_text(encoding='utf-8')
rm=re.search(r'<Version>([^<]+)</Version>',runtime)
if not rm: fail('runtime version source unreadable')
owned['windows-runtime']=rm.group(1).strip()
props=(R/'windows/print-agent/source/Directory.Build.props').read_text(encoding='utf-8')
pm=re.search(r'<SoknaAgentVersion>([^<]+)</SoknaAgentVersion>',props)
if not pm: fail('print agent version source unreadable')
owned['print-agent']=pm.group(1).strip()
owned['shared-contracts']=(R/'contracts/VERSION.txt').read_text().strip()
for cid,version in owned.items():
    if str(components.get(cid,{}).get('current_version',''))!=version: fail(f'{cid} COMPONENTS version drift')
    if str(compat.get(cid,{}).get('version',''))!=version: fail(f'{cid} compatibility version drift')

print('PASS P6 integrated regression contract')
