#!/usr/bin/env python3
from __future__ import annotations
import argparse,hashlib,json,subprocess
from pathlib import Path
R=Path(__file__).resolve().parents[1]
def main():
 ap=argparse.ArgumentParser();ap.add_argument('--id',required=True);ap.add_argument('--terminal',required=True);ap.add_argument('--log',required=True);ap.add_argument('--out',required=True);ap.add_argument('--capability',action='append',default=[]);ap.add_argument('--source-head');a=ap.parse_args()
 log=Path(a.log);raw=log.read_bytes();text=raw.decode('utf-8','replace')
 if a.terminal not in text: raise SystemExit(f'{a.id}: exact terminal not found in log')
 head=a.source_head or subprocess.check_output(['git','rev-parse','HEAD'],cwd=R,text=True).strip()
 if not head or len(head)!=40: raise SystemExit('invalid source head')
 d={'format':'sokna-qualification-evidence-v1','schema_version':1,'id':a.id,'status':'PASS','source_head':head,'terminal':a.terminal,'capabilities':a.capability,'log_sha256':hashlib.sha256(raw).hexdigest(),'log_file':log.name}
 out=Path(a.out);out.parent.mkdir(parents=True,exist_ok=True);out.write_text(json.dumps(d,indent=2,sort_keys=True)+'\n')
 print(json.dumps(d,sort_keys=True))
if __name__=='__main__':main()
