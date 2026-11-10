#!/usr/bin/env python3
"""Real Windows process-based QA for disposable three-component coordination.
This is a behavioral *model*; application service/DB/HTTP integration remains a
separate release-blocking requirement.
"""
import hashlib
import json
import msvcrt
import os
from pathlib import Path
import subprocess
import sys
import uuid

ROOT=Path(os.environ["RUNNER_TEMP"]).resolve()
PY=Path(sys.executable)
SCRIPT=Path(__file__).with_name("coordinated-upgrade-qa.py")
COUNT=0

def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def state(p):return json.loads(p.read_text(encoding="utf-8"))
def call(root,action,*args,expect=0):
 global COUNT
 cmd=[str(PY),str(SCRIPT),action,str(root),*args]
 x=subprocess.run(cmd,capture_output=True,text=True,timeout=20)
 assert x.returncode==expect, f"unexpected exit {x.returncode} expected {expect} {cmd} stdout={x.stdout} stderr={x.stderr}"
 COUNT+=1
 return x.stdout
def new(profile):
 root=ROOT/("sokna-universal-qa-"+uuid.uuid4().hex)
 call(root,"bootstrap","--profile",profile)
 return root
def originals(root):
 return {p.name:sha(p) for p in (root/"live").glob("*.json")}
def same(root,snaps):
 actual=originals(root)
 assert actual==snaps, f"rollback changed original byte content: {actual} vs {snaps}"
def journal(root):return state(root/"global-transaction.json")
def write(path,obj):path.write_text(json.dumps(obj,sort_keys=True)+"\n",encoding="utf-8")

for profile in ("cafe","home"):
 root=new(profile)
 before=originals(root)
 call(root,"execute")
 j=journal(root)
 assert j["phase"]=="COMMITTED" and j["completed"]==["maintenance","local","public"]
 assert state(root/"live"/"database.json")["migration_head"]==80
 assert state(root/"live"/"database.json")["synthetic_order_quantity"]==(112 if profile=="cafe" else 140)
 assert state(root/"live"/"maintenance.json")["recovery"] in ("0.1.1-preserved","0.1.3-preserved")
 assert j["production_approved"] is False
 call(root,"resume") # committed transaction replay must be non-mutating
 call(root,"execute",expect=24) # no repeated install over existing global txn
 assert journal(root)["phase"]=="COMMITTED"

 for fault in ("after_maintenance","after_local","after_public","before_commit"):
  root=new(profile);before=originals(root)
  call(root,"execute","--fault",fault,expect=24)
  same(root,before)
  assert journal(root)["phase"]=="ROLLED_BACK_VERIFIED"
  call(root,"resume")
  same(root,before)
 for fault in ("crash_after_maintenance","crash_after_local","crash_after_public"):
  root=new(profile);before=originals(root)
  call(root,"execute","--fault",fault,expect=79)
  assert journal(root)["phase"].endswith("_DONE")
  call(root,"resume")
  same(root,before)
  assert journal(root)["phase"]=="ROLLED_BACK_VERIFIED"

 # A prior R125 recovery marker blocks the shared transaction at preflight.
 root=new(profile);before=originals(root)
 evidence=root/"host-evidence.json";j=state(evidence);j["r125_unresolved"]=True;write(evidence,j)
 call(root,"execute",expect=24)
 same(root,before)
 assert not (root/"global-transaction.json").exists()

 # Previously unknown source identity must not be silently downgraded/upgraded.
 root=new(profile);old=root/"live"/"local.json";j=state(old);j["version"]="UNRECOGNIZED";write(old,j)
 before=originals(root)
 call(root,"execute",expect=24);same(root,before)
 assert not (root/"global-transaction.json").exists()

 # The shared machine lease is exclusive even between separate processes.
 root=new(profile);before=originals(root)
 with open(root/"coordination.lock","r+b") as f:
  f.seek(0);msvcrt.locking(f.fileno(),msvcrt.LK_NBLCK,1)
  try:call(root,"execute",expect=24)
  finally:f.seek(0);msvcrt.locking(f.fileno(),msvcrt.LK_UNLCK,1)
 same(root,before)
 assert not (root/"global-transaction.json").exists()

 # A tampered durable backup must NEVER be overwritten to appear recovered.
 root=new(profile);call(root,"execute","--fault","crash_after_local",expect=79)
 backup=root/"backup"/"local.before";backup.write_text("QA_CORRUPT_BACKUP",encoding="utf-8")
 call(root,"resume",expect=24)
 assert journal(root)["phase"]=="RECOVERY_REQUIRED"
 call(root,"resume",expect=24)

 # A tampered staged receipt must also fail closed; do not remove marker.
 root=new(profile);call(root,"execute","--fault","crash_after_maintenance",expect=79)
 receipt=root/"stage"/"maintenance.receipt.json";j=state(receipt);j["sha256"]="0"*64;write(receipt,j)
 call(root,"resume",expect=24)
 assert journal(root)["phase"]=="RECOVERY_REQUIRED"

 # External drift while interrupted must not be obliterated by replay rollback.
 root=new(profile);call(root,"execute","--fault","crash_after_local",expect=79)
 external=root/"live"/"public.json";j=state(external);j["version"]="CONCURRENT_EXTERNAL_CHANGE";write(external,j)
 changed=sha(external)
 call(root,"resume",expect=24)
 assert journal(root)["phase"]=="RECOVERY_REQUIRED" and sha(external)==changed

print("PASS UNIVERSAL_QA_CROSS_COMPONENT_JOURNAL",COUNT,
      "process runs across Cafe R112 and Home R140; maint 011 and 013; "
      "success, rollback, hard crashes, forged receipts, tampered backup, "
      "R125 lock, unknown baseline, shared machine lease and foreign drift")
print("IMPORTANT: NOT REAL Local/Public/Maintenance FIELD-INSTALL VALIDATION")
