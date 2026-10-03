from pathlib import Path
import json
import re

ROOT = Path(__file__).resolve().parents[1]
version = (ROOT / 'packaging/prerequisites/VERSION.txt').read_text(encoding='utf-8').strip()
assert version == '1.0.12', version

csproj = (ROOT / 'packaging/prerequisites/setup-ui/Sokna.Prerequisites.Setup.csproj').read_text(encoding='utf-8')
assert '<StartupObject>Sokna.Prerequisites.Setup.ProgramV2</StartupObject>' in csproj
assert '<Version>1.0.12</Version>' in csproj
assert '<IncludeSourceRevisionInInformationalVersion>false</IncludeSourceRevisionInInformationalVersion>' in csproj
assert 'prerequisites-self-service-v1.json' in csproj

v2 = (ROOT / 'packaging/prerequisites/setup-ui/PrerequisitesV2.cs').read_text(encoding='utf-8')
required = [
    'PRQ-ROOT-001', 'PRQ-STATE-001', 'PRQ-PHP-001', 'PRQ-APACHE-001',
    'PRQ-MARIA-001', 'PRQ-DATA-001', 'PRQ-PORT-001', 'PRQ-UAC-001',
    '--self-test-prerequisites-v2', '--render-prerequisites-ui', '--audit-prerequisites-ui',
    'RightToLeftLayout = true', 'SoknaDiagnosticsButton', 'PrerequisitesSupportBundle',
    'setup_version', 'release_lock_sha256', 'infrastructure_policy_sha256',
    'state_write_mode', 'atomic-replace'
]
for token in required:
    assert token in v2, token

# Keep this contract behavior-oriented. Do not pin exact pixel values or obsolete
# implementation details; screenshot/audit qualification owns geometry validation.
compat = (ROOT / 'packaging/prerequisites/setup-ui/UiCompatibilityPatch.cs').read_text(encoding='utf-8')
for token in [
    '_root', '_apachePort', '_password', '_password2',
    'RebuildPathAndPort', 'RebuildMariaDbCredentials',
    'SoknaResponsivePathLayout', 'SoknaResponsiveMariaLayout',
    'RightToLeft = RightToLeft.No', 'NormalizeVersionText',
    'Application.AddMessageFilter', 'ApplyWhenPumpingFilter', 'ApplyOpenForms'
]:
    assert token in compat, token
assert 'StabilizeMiddleColumn' not in compat

iss = (ROOT / 'packaging/prerequisites/installer/SOKNA-Prerequisites.iss').read_text(encoding='utf-8')
assert 'CompareSoknaVersion' in iss
assert 'Downgrade' in iss
assert re.search(r'if CompareResult > 0 then', iss)
# Silent mode must be checked only after the newer-installed guard.
assert iss.index('if CompareResult > 0 then') < iss.index('if WizardSilent then')

build = (ROOT / 'packaging/prerequisites/scripts/build-installer.ps1').read_text(encoding='utf-8')
for token in ['sokna-prerequisites-artifact-v2', 'source_commit', 'release_lock_sha256', 'infrastructure_policy_sha256', 'setup_ui_sha256']:
    assert token in build, token

contract = json.loads((ROOT / 'platform/windows/prerequisites-self-service-v1.json').read_text(encoding='utf-8'))
assert contract['format'] == 'sokna-prerequisites-self-service-v1'
assert contract['manager']['downgrade_policy'] == 'block'
assert contract['manager']['silent_downgrade_policy'] == 'block'
assert contract['support_bundle']['secrets_allowed'] is False
assert contract['state']['atomic_write_required'] is True
assert contract['ownership_boundaries']['local_web_payload_managed'] is False
assert contract['ownership_boundaries']['public_edge_managed'] is False

print('PREREQUISITES_V2_CONTRACT=PASS')
