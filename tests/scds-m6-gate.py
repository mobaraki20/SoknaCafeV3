from pathlib import Path
import json,re,sys
ROOT=Path(__file__).resolve().parents[1]
def fail(m): print(m,file=sys.stderr); raise SystemExit(1)
registry=json.loads((ROOT/'docs/ui-design-system/COMPONENT_REGISTRY.json').read_text())
if registry.get('status')!='m6_shared_foundation_canonical': fail('SCDS registry not promoted to M6 canonical foundation')
for c in registry['components']:
    if c['id'] in {'guest_menu_shell','guest_order_flow'}: continue
    if not str(c.get('status','')).startswith('canonical_'): fail(f"SCDS component is not canonical: {c['id']}")
    if str(c.get('v3_owner','')).startswith('planned:'): fail(f"planned owner survived M6: {c['id']}")
tokens=(ROOT/'apps/local-web/assets/scds/tokens.css').read_text(); components=(ROOT/'apps/local-web/assets/scds/components.css').read_text(); js=(ROOT/'apps/local-web/assets/scds/scds.js').read_text()
css=tokens+'\n'+components
for needle in [':focus-visible','min-block-size:44px','@media (prefers-reduced-motion:reduce)','border-inline-end','text-align:start']:
    if needle not in css: fail('SCDS accessibility/logical-direction contract missing: '+needle)
if '!important' in css: fail('V3-native SCDS introduced !important debt')
if re.search(r'(^|[}\s])\.(btn|card|alert|form-control)(?:[\s,{.:#]|$)',css,re.M): fail('SCDS recreated legacy generic selector owner')
if 'innerHTML' in js: fail('SCDS JS introduced innerHTML injection surface')
for needle in ['aria-selected','ArrowRight','scds:quantity','HTMLDialogElement']:
    if needle not in js: fail('SCDS behavior contract missing: '+needle)
helper=(ROOT/'apps/local-web/src/UI/SCDS.php').read_text()
if 'htmlspecialchars' not in helper or 'ENT_SUBSTITUTE' not in helper: fail('SCDS server renderer lacks canonical escaping')
base=json.loads((ROOT/'docs/ui-design-system/LEGACY_UI_DEBT_BASELINE.json').read_text())
if base.get('policy')!='ratchet_down_only_during_legacy_migration': fail('UI debt ratchet policy drifted')
print('M6 SCDS shared foundation gate passed.')
