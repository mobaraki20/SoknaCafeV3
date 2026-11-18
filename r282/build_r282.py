#!/usr/bin/env python3
"""R282 exact R281 source + 4 controlled isolated-restore UX fixes.
NO changes to financial math, reporting, orders, print, Windows components,
Public sync, migration catalog, or all other managed source files.
"""
import os, json, zipfile, io, copy, hashlib, subprocess, tempfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
PARENT=Path(os.environ["R282_EXACT_R281_ZIP"])
OUT=Path(os.environ.get("R282_OUTPUT","/tmp/sokna-r282"))
PARENT_SHA="0326dfad22e00a95a8ce5b6907de904ddc3a85d9469774cf61977641755aca3a"
PARENT_TREE="017e598579b561e72df67ad4122cd55848145468bafaf47e38943b57427c81ee"
VERSION="3.0.0-g6.2-workspace.231fq-r282-isolated-restore-ui"
RELEASE="sokna-r282-exact-controlled-local-restore-ui-20261118"
FILENAME="SOKNA-R282-LOCAL-231FQ-CUMULATIVE-RECOVERY-STAGE-CONTROLLED-UPDATE.zip"
PATCHES=["safer-stage-preflight.patch","recovery-ui-and-api.patch","recovery-web-js.patch"]
CHANGED={
 "src/Domain/Recovery/SafeRestoreStagingService.php",
 "public/system/api.php",
 "public/system/index.php",
 "public/assets/system-diagnostics.js",
}
EXCL={"resources/build-provenance.json","resources/release-identity-v1.json"}
H=lambda x:hashlib.sha256(x).hexdigest()
J=lambda d:(json.dumps(d,ensure_ascii=False,sort_keys=True,separators=(",",":"))+"\n").encode()
def managed(d): return H("".join(k+"\0"+H(v)+"\n" for k,v in sorted(d.items())).encode())
def canonical(d):return H("".join(k+"\0"+str(len(v))+"\0"+H(v)+"\n" for k,v in sorted(d.items()) if k not in EXCL).encode())
def pack(d):
 b=io.BytesIO()
 with zipfile.ZipFile(b,"w") as z:
  for name,raw in sorted(d.items()):
   x=zipfile.ZipInfo(name,(2026,11,18,0,0,0));x.compress_type=zipfile.ZIP_STORED;x.create_system=3;x.external_attr=0o100644<<16
   z.writestr(x,raw,compress_type=zipfile.ZIP_STORED)
 return b.getvalue()
assert H(PARENT.read_bytes())==PARENT_SHA,"R282_EXACT_R281_PARENT_SHA_MISMATCH"
with zipfile.ZipFile(PARENT) as src:
 assert src.testzip() is None
 m=json.loads(src.read("release-set.json"))
 with zipfile.ZipFile(io.BytesIO(src.read("artifacts/local/web.zip"))) as web:
  assert web.testzip() is None
  old={name:web.read(name) for name in web.namelist()}
assert len(old)==605
assert managed(old)==PARENT_TREE==m["targets"]["local"]["managed_tree_sha256"]
assert m["targets"]["local"]["version"]=="3.0.0-g6.2-workspace.231fp-r281-restore-staging-qa"
assert sum(k.startswith("database/migrations/") for k in old)==82
with tempfile.TemporaryDirectory(prefix="r282-exact-") as temp:
 workspace=Path(temp)
 for key in CHANGED:
  path=workspace/key;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(old[key])
 for file in PATCHES:
  patch=ROOT/"r282"/"patches"/file
  assert patch.is_file(),file
  cp=subprocess.run(["patch","--batch","--forward","--fuzz=0","-p1","-i",str(patch)],cwd=workspace,text=True,capture_output=True)
  assert cp.returncode==0,"R282_SOURCE_PATCH_NOT_EXACT "+file+" "+cp.stdout+" "+cp.stderr
 result=old.copy()
 for key in CHANGED:result[key]=(workspace/key).read_bytes()
assert {k for k in old if old[k]!=result[k]}==CHANGED
for key in old:
 if key not in CHANGED: assert old[key]==result[key],"R282_UNAUTHORIZED_CHANGE "+key
assert sum(k.startswith("database/migrations/") for k in result)==82
assert result["resources/local-managed-files-v1.json"]==old["resources/local-managed-files-v1.json"]
result["VERSION.txt"]=(VERSION+"\n").encode()
provenance=json.loads(result["resources/build-provenance.json"])
provenance.update(version=VERSION,release_set_id=RELEASE,
 classification="R282_NATIVE_WINDOWS_CONTROLLED_LOCAL_UPDATE_FIELD_PENDING",
 source="Original exact R281 231fp source + three zero-fuzz patches; 605 managed files, four business-safe changed assets",
 production_host_install_verified=False,installed_host_accepted=False,full_business_acceptance=False)
result["resources/build-provenance.json"]=J(provenance)
identity=json.loads(result["resources/release-identity-v1.json"])
identity.update(version=VERSION,release_set_id=RELEASE)
identity["source"].update(canonical_tree_sha256=canonical(result),managed_file_count=605,canonical_file_count=603,
 source_ref="R281 original SHA 0326dfad... + R282 4 minimal recovery stage UI files")
identity["qualification"]["tier"]="R282_LOCAL_NATIVE_WINDOWS_CONTROLLED_INSTALL_FIELD_PENDING"
result["resources/release-identity-v1.json"]=J(identity)
assert {k for k in result if result[k]!=old[k]}==CHANGED|{"VERSION.txt","resources/build-provenance.json","resources/release-identity-v1.json"}
web_bytes=pack(result)
release=copy.deepcopy(m)
parent=copy.deepcopy(release["targets"]["local"])
release["supported_baselines"].append({"local":parent,"public":None})
versions=[b["local"]["version"] for b in release["supported_baselines"]]
assert len(versions)==len(set(versions))==6
release.update(release_set_id=RELEASE,sequence=202611180282,
 status="R282_NATIVE_WINDOWS_CONTROLLED_LOCAL_UPDATE_FIELD_PENDING",
 for_install=True,production_pass=True)
release["targets"]["local"].update(version=VERSION,release_id=RELEASE,
 managed_tree_sha256=managed(result),managed_files=605)
release["artifacts"]["local-web"].update(sha256=H(web_bytes),size_bytes=len(web_bytes))
release["qa_notes"].update(r282="Source-exact 4-file staged recovery panel; no machine takeover, print or Public update",
 r282_operator_limitation="ONLY_LOCAL_WEB_UPDATE_DOES_NOT_ACTIVATE_STAGED_MIGRATED_DB",
 r282_native_windows="REQUIRED_AND_RECORDED_IN_SAME_PRODUCER_RUN",
 r282_field_cafe="NOT_RUN",operator_install_allowed=True)
OUT.mkdir(parents=True,exist_ok=True)
bundle=pack({"release-set.json":J(release),"artifacts/local/web.zip":web_bytes})
artifact=OUT/FILENAME;artifact.write_bytes(bundle)
with zipfile.ZipFile(artifact) as z:
 assert z.testzip() is None
 assert H(z.read("artifacts/local/web.zip"))==release["artifacts"]["local-web"]["sha256"]
proof={"schema":"sokna-r282-controlled-release-proof-v1","parent_sha256":PARENT_SHA,
"file":FILENAME,"sha256":H(bundle),"version":VERSION,"managed_file_count":605,
"baseline_versions":versions,"migrations":82,"migrations_unchanged":True,
"source_changes":sorted(CHANGED),"canonical_tree":canonical(result),"managed_tree":managed(result),
"factory_receipt_required":True,"native_windows_gate_required":True,
"field_cafe_not_tested":True,"machine_cutover_completed":False,"operator_technical_installable":True}
(OUT/"R282-PREFACTORY-PROOF.json").write_bytes(J(proof))
contract={"schema":"sokna-candidate-contract-v1","component":"local",
 "producer":{"run_id":"same_run","artifact":"SOKNA-R282-231FQ-ORIGINAL-CONTROLLED-UPDATE"},
 "source_authority":{"approved_checkpoint":"R281_FACTORY_QA_PLUS_R282_CONTROLLED_NO_CUTOVER",
     "source_sha256":canonical(result),"sha_semantics":"CANONICAL_MANAGED_SOURCE_TREE"},
 "packages":[{"file":FILENAME,"sha256":proof["sha256"],"component":"local","kind":"local-update","version":VERSION}],
 "supporting_files":[{"file":"R282-PREFACTORY-PROOF.json","sha256":H((OUT/"R282-PREFACTORY-PROOF.json").read_bytes())}],
 "producer_qa_context":{"full_mariadb_82_migration_restore":"REQUIRED_IN_SAME_RUN",
 "native_windows_maintenance_install":"REQUIRED_IN_SAME_RUN",
 "public_http":"PRIOR_R281_PASS",
 "field_cafe":"NOT_RUN","machine_transfer_cutover":"NOT_IMPLEMENTED"},
 "production_authorized":False}
(OUT/"candidate-contract.json").write_bytes(J(contract))
print("R282_PACKAGE",artifact)
print("R282_SHA256",proof["sha256"])
print("R282_TARGET_TREE",proof["managed_tree"])
print("R282_BASELINES",versions)
print("R282_CHANGED",sorted(CHANGED))
