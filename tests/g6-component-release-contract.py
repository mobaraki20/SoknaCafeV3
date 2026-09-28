#!/usr/bin/env python3
from __future__ import annotations
import json,re,subprocess,sys,tempfile,hashlib
from pathlib import Path
import xml.etree.ElementTree as ET
R=Path(__file__).resolve().parents[1]
def fail(m): raise SystemExit('G6 COMPONENT RELEASE CONTRACT FAILED: '+m)
def text(p): return (R/p).read_text(encoding='utf-8')
def version_sources():
 def xv(p,t):
  e=ET.fromstring(text(p)).find('.//'+t);return (e.text or '').strip()
 return {'local-web':text('apps/local-web/VERSION.txt').strip(),'public-edge':text('apps/public/VERSION.txt').strip(),'windows-runtime':xv('windows/runtime/source/Sokna.Runtime.Service.csproj','Version'),'print-agent':xv('windows/print-agent/source/Directory.Build.props','SoknaAgentVersion'),'windows-services-packaging':text('packaging/windows/WINDOWS_SERVICES_VERSION.txt').strip(),'shared-contracts':text('contracts/VERSION.txt').strip()}
pipes=json.loads(text('release/component-pipelines-v1.json')); expected=set(version_sources())
if pipes.get('format')!='sokna-component-release-pipelines-v1' or set(pipes.get('components',{}))!=expected: fail('pipeline registry is incomplete')
if any(v.get('version_source')=='VERSION.txt' for v in pipes['components'].values()): fail('root VERSION.txt may not own a component version')
for c,x in pipes['components'].items():
 for k in ('version_source','artifact'): 
  if not x.get(k): fail(f'{c}: missing {k}')
 builders=x.get('builders',[x.get('builder')]);
 for b in builders:
  if not b or not (R/b).is_file(): fail(f'{c}: missing builder {b}')
compat=json.loads(text('release/compatibility-v2.json')); actual=version_sources()
if {k:v['version'] for k,v in compat.get('components',{}).items()}!=actual: fail('compatibility-v2 does not bind exact owned versions')
reg=json.loads(text('COMPONENTS.json'))['components']
for c,v in actual.items():
 if reg[c].get('version_source')!=pipes['components'][c]['version_source'] or reg[c].get('current_version')!=v: fail(f'{c}: COMPONENTS version governance drift')
boot=text('apps/local-web/src/Core/Bootstrap.php')
if "dirname(__DIR__,4).'/VERSION.txt'" in boot: fail('Local still reads repository root version')
if "dirname(__DIR__,2).'/VERSION.txt'" not in boot: fail('Local owned version source is not wired')
for f in ('apps/public/tools/build-deploy-package.py','apps/public/tools/build-update-package.py'):
 s=text(f)
 if "does not match Public VERSION.txt" not in s: fail(f'{f}: explicit version mismatch fence missing')
if 'does not match Local VERSION.txt' not in text('apps/local-web/tools/build-update-package.py'): fail('Local version mismatch fence missing')
print('PASS G6 component release contract')
