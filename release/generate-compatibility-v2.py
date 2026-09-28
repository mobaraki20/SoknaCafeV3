#!/usr/bin/env python3
from __future__ import annotations
import json,re
from pathlib import Path
import xml.etree.ElementTree as ET
R=Path(__file__).resolve().parents[1]
def txt(p): return (R/p).read_text(encoding='utf-8').strip()
def xml_value(p,tag):
 root=ET.fromstring((R/p).read_text(encoding='utf-8')); e=root.find('.//'+tag)
 if e is None or not (e.text or '').strip(): raise SystemExit(f'missing {tag} in {p}')
 return e.text.strip()
def valid(v):
 if re.fullmatch(r'[0-9A-Za-z][0-9A-Za-z._+-]{0,63}',v) is None: raise SystemExit('invalid component version '+v)
 return v
components={
 'local-web':valid(txt('apps/local-web/VERSION.txt')),
 'public-edge':valid(txt('apps/public/VERSION.txt')),
 'windows-runtime':valid(xml_value('windows/runtime/source/Sokna.Runtime.Service.csproj','Version')),
 'print-agent':valid(xml_value('windows/print-agent/source/Directory.Build.props','SoknaAgentVersion')),
 'windows-services-packaging':valid(txt('packaging/windows/WINDOWS_SERVICES_VERSION.txt')),
 'shared-contracts':valid(txt('contracts/VERSION.txt')),
}
contracts=json.loads((R/'contracts/manifest.json').read_text(encoding='utf-8'))
manifest={
 'format':'sokna-release-compatibility-v2','schema_version':2,
 'components':{k:{'version':v} for k,v in components.items()},
 'contracts':{x['id']:x['version'] for x in contracts.get('contracts',[])},
 'rules':[
  'Each component version comes only from its component-owned version source.',
  'Compatibility binds versions and contracts but never creates a monolithic product version owner.',
  'A component artifact must reject an explicit release version that differs from its owned version source.'
 ]
}
out=R/'release/compatibility-v2.json';out.write_text(json.dumps(manifest,ensure_ascii=False,indent=2,sort_keys=True)+'\n',encoding='utf-8')
print(out)
