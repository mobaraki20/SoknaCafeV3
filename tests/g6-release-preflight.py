#!/usr/bin/env python3
from __future__ import annotations
import json
from pathlib import Path
R=Path(__file__).resolve().parents[1]
def fail(m):raise SystemExit('G6 RELEASE PREFLIGHT FAILED: '+m)
m=json.loads((R/'docs/product/MASTER_CAPABILITY_MATRIX.json').read_text(encoding='utf-8'))
if m.get('schema_version')!=4 or m.get('status')!='canonical_master_capability_matrix':fail('canonical matrix metadata invalid')
rows=m.get('rows',[]);open_ids={r['id'] for r in rows if r['required_for_product']=='yes' and r['completion_level']=='PRODUCT_OPEN'}
d=json.loads((R/'release/deferred-qualification-v1.json').read_text(encoding='utf-8'))
if d.get('format')!='sokna-deferred-qualification-v1':fail('deferred register format invalid')
covered={};ids=set()
for e in d.get('entries',[]):
 if e['id'] in ids:fail('duplicate qualification id '+e['id'])
 ids.add(e['id'])
 if e.get('status')!='DEFERRED':fail(e['id']+' must remain DEFERRED before real evidence')
 if not (R/e['runner']).is_file():fail(e['id']+' runner missing')
 if not e.get('terminal','').endswith('PASS'):fail(e['id']+' exact PASS terminal missing')
 for c in e.get('capabilities',[]):
  if c in covered:fail(f'{c} covered twice by {covered[c]} and {e["id"]}')
  covered[c]=e['id']
missing=open_ids-set(covered);extra=set(covered)-open_ids
if missing:fail('open product capabilities lack qualification mapping: '+','.join(sorted(missing)))
if extra:fail('qualification register maps non-open capabilities: '+','.join(sorted(extra)))
if covered.get('A44')!='DQ-G6' or covered.get('A50')!='DQ-G6':fail('A44/A50 must be owned by DQ-G6')
print(f'PASS G6 release preflight open={len(open_ids)} qualifications={len(ids)}')
