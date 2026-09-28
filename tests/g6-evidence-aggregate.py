#!/usr/bin/env python3
from __future__ import annotations
import argparse,json,re
from pathlib import Path
R=Path(__file__).resolve().parents[1]
def fail(m):raise SystemExit('G6 EVIDENCE AGGREGATE FAILED: '+m)
def main():
 ap=argparse.ArgumentParser();ap.add_argument('--evidence-dir',required=True);ap.add_argument('--source-head',required=True);a=ap.parse_args()
 if re.fullmatch(r'[a-f0-9]{40}',a.source_head) is None:fail('invalid source head')
 reg=json.loads((R/'release/deferred-qualification-v1.json').read_text())
 evdir=Path(a.evidence_dir);covered=set();seen=[]
 for q in reg['entries']:
  p=evdir/(q['id']+'.json')
  if not p.is_file():fail('missing evidence '+q['id'])
  e=json.loads(p.read_text())
  if e.get('format')!='sokna-qualification-evidence-v1' or e.get('id')!=q['id'] or e.get('status')!='PASS':fail(q['id']+' evidence contract/status invalid')
  if e.get('source_head')!=a.source_head:fail(q['id']+' evidence is from a different source head')
  if e.get('terminal')!=q['terminal']:fail(q['id']+' terminal mismatch')
  if set(e.get('capabilities',[]))!=set(q.get('capabilities',[])):fail(q['id']+' capability evidence mismatch')
  covered.update(e['capabilities']);seen.append(q['id'])
 matrix=json.loads((R/'docs/product/MASTER_CAPABILITY_MATRIX.json').read_text())
 open_ids={r['id'] for r in matrix['rows'] if r['required_for_product']=='yes' and r['completion_level']=='PRODUCT_OPEN'}
 if covered!=open_ids:fail(f'evidence capability coverage differs from open product set: covered={sorted(covered)} open={sorted(open_ids)}')
 print(json.dumps({'format':'sokna-final-qualification-evidence-v1','source_head':a.source_head,'qualifications':seen,'capabilities':sorted(covered),'status':'PASS'},sort_keys=True))
 print('G6 Final Product Qualification: PASS')
if __name__=='__main__':main()
