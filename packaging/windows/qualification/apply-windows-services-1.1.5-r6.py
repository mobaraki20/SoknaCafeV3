from pathlib import Path
import json

ROOT = Path(__file__).resolve().parents[3]

(ROOT / 'packaging/windows/WINDOWS_SERVICES_VERSION.txt').write_text('1.1.5\n', encoding='utf-8')

p = ROOT / 'packaging/windows/windows-services-component-manifest-v2.json'
data = json.loads(p.read_text(encoding='utf-8'))
data['package_version'] = '1.1.5'
for c in data['components']:
    if c['component_id'] == 'maintenance':
        c['version'] = '0.3.3'
        for d in c.get('dependencies', []):
            if d['component_id'] == 'maintenance-update-engine':
                d['version'] = '0.3.3'
    elif c['component_id'] == 'maintenance-update-engine':
        c['version'] = '0.3.3'
p.write_text(json.dumps(data, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')

p = ROOT / 'packaging/windows/setup-ui/Sokna.SetupUi.csproj'
t = p.read_text(encoding='utf-8')
for old, new in [
    ('<Version>1.1.4</Version>', '<Version>1.1.5</Version>'),
    ('<FileVersion>1.1.4.0</FileVersion>', '<FileVersion>1.1.5.0</FileVersion>'),
    ('<AssemblyVersion>1.1.4.0</AssemblyVersion>', '<AssemblyVersion>1.1.5.0</AssemblyVersion>')
]:
    if old not in t:
        raise SystemExit(f'missing setup-ui version token: {old}')
    t = t.replace(old, new, 1)
p.write_text(t, encoding='utf-8')

print('Windows Services 1.1.5 R6 metadata prepared')
