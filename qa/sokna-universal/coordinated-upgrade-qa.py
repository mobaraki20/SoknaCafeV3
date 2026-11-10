#!/usr/bin/env python3
"""QA ONLY — fail-closed durable three-component coordination *model*.
Real Local/Public/Windows binaries are not invoked by this model.
Its purpose is to prove the shared lease, prepare/commit journal and crash
recovery policy before a separate real-host integration is ever authorized.
"""
import argparse
import contextlib
import hashlib
import json
import os
from pathlib import Path
import sys
import uuid

COMPONENTS=("maintenance","local","public")
TARGET={"maintenance":"0.3.26-qa","local":"R149B","public":"R149-owner-fixed-qa"}
EXACT_PAYLOAD_SHA={
 "maintenance":"69b524abb727b2066574c17d716ceea6cc49326ce7c4283b02df6871d9998b0b",
 "local":"968e11771ec85c02fe4284498ba8d857d2c740958b9103bc45d6f727e20f04d4",
 "public":"1131d89c98e05c9597f372ed1d480e5637c3ad1969bc8733d2a837b644e57584",
}
ALLOWED={
 "maintenance":{"0.3.22":("0.1.1","0.1.3")},
 "local":{"R112":("76",),"R140":("79",)},
 "public":{"R112":("R112",),"R126":("R126",)},
}
class Blocked(Exception):pass

def sha(data):return hashlib.sha256(data).hexdigest()
def canonical(obj):return (json.dumps(obj,sort_keys=True,separators=(",",":"),ensure_ascii=False)+"\n").encode()
def get(path):
 try:return json.loads(path.read_bytes())
 except (ValueError,OSError) as exc:raise Blocked("INVALID_OR_MISSING_JOURNAL_OR_HOST_STATE") from exc
def write(path,data):
 path.parent.mkdir(parents=True,exist_ok=True)
 tmp=path.with_name(path.name+".tmp-"+uuid.uuid4().hex)
 try:
  with open(tmp,"xb") as f:f.write(data);f.flush();os.fsync(f.fileno())
  os.replace(tmp,path)
 finally:
  if tmp.exists():tmp.unlink()
def jsonwrite(path,obj):write(path,canonical(obj))

def guard(root):
 if os.environ.get("GITHUB_ACTIONS")!="true" or os.environ.get("RUNNER_OS")!="Windows" or os.environ.get("SOKNA_DISPOSABLE_UNIVERSAL_QA")!="YES":
  raise Blocked("DISPOSABLE_WINDOWS_GITHUB_ACTIONS_ONLY")
 temp=os.environ.get("RUNNER_TEMP")
 if not temp:raise Blocked("RUNNER_TEMP_UNAVAILABLE")
 root=Path(root).resolve(strict=False)
 sandbox=Path(temp).resolve(strict=True)
 if root.parent!=sandbox or not root.name.startswith("sokna-universal-qa-"):
  raise Blocked("NOT_A_DISPOSABLE_ROOT")
 for p in [root,*root.parents]:
  if p.exists() and p.is_symlink():raise Blocked("SYMLINK_ROOT")
 return root

@contextlib.contextmanager
def lease(root):
 root.mkdir(parents=True,exist_ok=True)
 f=open(root/"coordination.lock","a+b")
 try:
  f.seek(0);f.write(b"0");f.flush();f.seek(0)
  import msvcrt
  try:msvcrt.locking(f.fileno(),msvcrt.LK_NBLCK,1)
  except OSError as exc:raise Blocked("GLOBAL_SHARED_LEASE_BUSY") from exc
  yield
 finally:
  try:
   f.seek(0);msvcrt.locking(f.fileno(),msvcrt.LK_UNLCK,1)
  except OSError:pass
  f.close()

def paths(root):
 return {name:root/"live"/(name+".json") for name in (*COMPONENTS,"database")}
def snapshots(root):return {name:root/"backup"/(name+".before") for name in paths(root)}
def record(root):return root/"global-transaction.json"
def expected(before):
 return {name:sha(p.read_bytes()) for name,p in before.items()}

def fixture(root,profile):
 if root.exists() and any(p.name!="coordination.lock" for p in root.iterdir()):
  raise Blocked("REFUSE_EXISTING_DISPOSABLE_STATE")
 root.mkdir(parents=True,exist_ok=True)
 if profile not in ("home","cafe"):raise Blocked("UNKNOWN_HOST_PROFILE")
 old={"maintenance":{"version":"0.3.22","recovery":"0.1.1" if profile=="cafe" else "0.1.3"},
      "local":{"version":"R112" if profile=="cafe" else "R140","migration_head":76 if profile=="cafe" else 79},
      "public":{"version":"R112" if profile=="cafe" else "R126"},
      "database":{"migration_head":76 if profile=="cafe" else 79,
                  "synthetic_order_quantity":112 if profile=="cafe" else 140,
                  "synthetic_receipt":"PRESERVE_EXISTING_DATA"}}
 for name,p in paths(root).items():jsonwrite(p,old[name])
 jsonwrite(root/"host-evidence.json",{"format":"sokna-universal-host-qa-v1","profile":profile,
  "ledger":"idle","r125_unresolved":False,"production_approved":False})
 return old

def validate(root):
 h=get(root/"host-evidence.json")
 if h.get("format")!="sokna-universal-host-qa-v1" or h.get("ledger")!="idle" or h.get("r125_unresolved") is not False:
  raise Blocked("HOST_NOT_IDLE_OR_R125_UNRESOLVED")
 if h.get("production_approved") is not False:raise Blocked("QA_CANNOT_AUTHORIZE_PRODUCTION")
 before={name:get(p) for name,p in paths(root).items()}
 if before["maintenance"].get("version") not in ALLOWED["maintenance"] or before["maintenance"].get("recovery") not in ALLOWED["maintenance"]["0.3.22"]:
  raise Blocked("UNSUPPORTED_MAINTENANCE_BASELINE")
 local=before["local"];public=before["public"]
 if local.get("version") not in ALLOWED["local"] or str(local.get("migration_head")) not in ALLOWED["local"][local["version"]]:
  raise Blocked("UNSUPPORTED_LOCAL_OR_SCHEMA_BASELINE")
 if public.get("version") not in ALLOWED["public"]:raise Blocked("UNSUPPORTED_PUBLIC_BASELINE")
 if before["database"].get("migration_head")!=local["migration_head"]:
  raise Blocked("LOCAL_DATABASE_DRIFT")
 if before["database"].get("synthetic_receipt")!="PRESERVE_EXISTING_DATA":
  raise Blocked("BUSINESS_DATA_UNPROVEN")
 return before

def prepare(root):
 if record(root).exists():raise Blocked("PREVIOUS_GLOBAL_TRANSACTION_NOT_RESOLVED")
 old=validate(root)
 b=snapshots(root)
 for name,p in paths(root).items():write(b[name],p.read_bytes())
 receipts={name:{"source":old[name]["version"],"target":TARGET[name],
  "sha256":EXACT_PAYLOAD_SHA[name],"staged":True,"activation_performed":False,
  "database_mutation_performed":False} for name in COMPONENTS}
 for name,receipt in receipts.items():jsonwrite(root/"stage"/(name+".receipt.json"),receipt)
 journal={"format":"sokna-universal-qa-journal-v1","phase":"PREPARED",
  "before_hashes":expected(b),"source":{name:old[name]["version"] for name in COMPONENTS},
  "target":TARGET,"package_sha256":EXACT_PAYLOAD_SHA,
  "completed":[],"after_hashes":{},"recovery_required":False,"production_approved":False}
 jsonwrite(record(root),journal)
 return journal

def snapshot_valid(root,j):
 for name,p in snapshots(root).items():
  if not p.exists() or sha(p.read_bytes())!=j["before_hashes"].get(name):
   raise Blocked("BACKUP_MISSING_OR_TAMPERED")
 for name in COMPONENTS:
  r=get(root/"stage"/(name+".receipt.json"))
  if r!={"source":j["source"][name],"target":TARGET[name],"sha256":EXACT_PAYLOAD_SHA[name],
         "staged":True,"activation_performed":False,"database_mutation_performed":False}:
   raise Blocked("STAGE_RECEIPT_NOT_QUALIFIED")

def apply(root,j,name):
 p=paths(root)[name];s=get(p)
 if s.get("version")!=j["source"][name]:raise Blocked("LIVE_COMPONENT_CHANGED_SINCE_PREPARE")
 s["version"]=TARGET[name]
 if name=="maintenance":s["recovery"]="0.1.1-preserved" if s["recovery"]=="0.1.1" else "0.1.3-preserved"
 if name=="local":
  s["migration_head"]=80
  db=get(paths(root)["database"])
  if db.get("migration_head") not in (76,79) or db.get("synthetic_receipt")!="PRESERVE_EXISTING_DATA":
   raise Blocked("DATABASE_FORWARD_PRECHECK_FAIL")
  db["migration_head"]=80
  jsonwrite(paths(root)["database"],db)
 jsonwrite(p,s)

def restore(root,j):
 snapshot_valid(root,j)
 # Never overwrite unknown changes that occurred after a process crash.
 # An in-flight intent with an unrecorded mutation must be manually reviewed.
 for name,p in paths(root).items():
  current=sha(p.read_bytes()) if p.exists() else ""
  prior=j["before_hashes"][name]
  if name=="database":
   expected=j.get("after_hashes",{}).get(name) if "local" in j["completed"] else prior
  elif name in j["completed"]:
   expected=j.get("after_hashes",{}).get(name)
  else:expected=prior
  if current!=expected or not expected:
   raise Blocked("LIVE_STATE_CHANGED_OR_INTENT_NOT_PROVED")
 for name,p in paths(root).items():write(p,snapshots(root)[name].read_bytes())
 for name,p in paths(root).items():
  if sha(p.read_bytes())!=j["before_hashes"][name]:raise Blocked("ROLLBACK_BYTE_PROOF_FAILED")
 j["phase"]="ROLLED_BACK_VERIFIED";j["recovery_required"]=False
 jsonwrite(record(root),j)
 return j

def reconcile(root):
 j=get(record(root))
 if j.get("format")!="sokna-universal-qa-journal-v1":raise Blocked("UNKNOWN_GLOBAL_JOURNAL")
 if j.get("phase")=="COMMITTED":
  for name in COMPONENTS:
   if get(paths(root)[name]).get("version")!=TARGET[name]:raise Blocked("COMMITTED_TARGET_DRIFT")
  if get(paths(root)["database"]).get("migration_head")!=80:raise Blocked("COMMITTED_DB_DRIFT")
  return j
 if j.get("phase")=="ROLLED_BACK_VERIFIED":
  for name,p in paths(root).items():
   if sha(p.read_bytes())!=j["before_hashes"].get(name):raise Blocked("ROLLED_BACK_TARGET_DRIFT")
  return j
 if j.get("phase")=="RECOVERY_REQUIRED":raise Blocked("MANUAL_RECOVERY_REQUIRED")
 if j.get("phase") not in ("PREPARED","MAINTENANCE_INTENT","MAINTENANCE_DONE",
   "LOCAL_INTENT","LOCAL_DONE","PUBLIC_INTENT","PUBLIC_DONE","COMMIT_INTENT"):
  raise Blocked("UNRECOGNIZED_NONTERMINAL_PHASE")
 try:return restore(root,j)
 except Blocked:
  j["phase"]="RECOVERY_REQUIRED";j["recovery_required"]=True
  jsonwrite(record(root),j);raise

def execute(root,fault="none"):
 j=prepare(root)
 snapshot_valid(root,j)
 try:
  for name in COMPONENTS:
   j["phase"]=name.upper()+"_INTENT";jsonwrite(record(root),j)
   apply(root,j,name)
   j["after_hashes"][name]=sha(paths(root)[name].read_bytes())
   if name=="local":j["after_hashes"]["database"]=sha(paths(root)["database"].read_bytes())
   j["completed"].append(name);j["phase"]=name.upper()+"_DONE";jsonwrite(record(root),j)
   if fault=="crash_after_"+name:
    os._exit(79)  # actual process termination, durable journal retained
   if fault=="after_"+name:raise Blocked("INJECTED_FAULT_"+name.upper())
  j["phase"]="COMMIT_INTENT";jsonwrite(record(root),j)
  if fault=="before_commit":raise Blocked("INJECTED_PRECOMMIT_FAULT")
  for name in COMPONENTS:
   if get(paths(root)[name]).get("version")!=TARGET[name]:raise Blocked("FINAL_COMPONENT_PROOF_FAILED")
  if get(paths(root)["database"]).get("migration_head")!=80:raise Blocked("FINAL_DATABASE_PROOF_FAILED")
  j["phase"]="COMMITTED";jsonwrite(record(root),j)
  return j
 except Blocked:
  reconcile(root)
  raise

def main():
 p=argparse.ArgumentParser()
 p.add_argument("mode",choices=("bootstrap","execute","resume","status"))
 p.add_argument("root")
 p.add_argument("--profile",choices=("home","cafe"),default="home")
 p.add_argument("--fault",choices=("none","after_maintenance","after_local","after_public",
                                      "before_commit","crash_after_maintenance","crash_after_local",
                                      "crash_after_public"),default="none")
 args=p.parse_args()
 try:
  root=guard(args.root)
  with lease(root):
   if args.mode=="bootstrap":result={"format":"sokna-universal-qa-fixture-v1","created":fixture(root,args.profile)}
   elif args.mode=="execute":result=execute(root,args.fault)
   elif args.mode=="resume":result=reconcile(root)
   else:result=get(record(root))
  print(json.dumps({"result":"PASS","phase":result.get("phase","CREATED"),"release_authorized":False},sort_keys=True))
 except Blocked as exc:
  print(json.dumps({"result":"BLOCKED","reason":str(exc),"release_authorized":False},sort_keys=True))
  return 24
 return 0
if __name__=="__main__":sys.exit(main())
