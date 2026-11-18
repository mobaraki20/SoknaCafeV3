#!/usr/bin/env python3
"""R284 exact immutable 231FR source + offline old machine safety gates.
No claims about Public's exclusive owner transfer or physical printer acceptance.
"""
from __future__ import annotations
from pathlib import Path
import copy,hashlib,io,json,os,subprocess,tempfile,zipfile
ROOT=Path(__file__).resolve().parents[1]
SOURCE=Path(os.environ["R284_ORIGINAL_R283_PACKAGE"])
OUT=Path(os.environ.get("R284_OUTPUT","/tmp/sokna-r284"))
SHA="94fe600e12f0736b778b341ba21d26085e8b5f1e7557c181b6b290ee91f9b097"
VERSION="3.0.0-g6.2-workspace.231fs-r284-old-local-write-fence-qa"
RELEASE="sokna-r284-local-offline-fence-qa-20261118"
NAME="SOKNA-R284-LOCAL-231FS-OFFLINE-CUTOVER-GUARDS-QA-DO-NOT-INSTALL.zip"
ADD={
"src/Domain/Recovery/LocalMachineTransferFence.php":
    ROOT/"r284/overlay/src/Domain/Recovery/LocalMachineTransferFence.php",
"tools/prepare-machine-transfer.php":
    ROOT/"r284/overlay/tools/prepare-machine-transfer.php",
}
PATCH=ROOT/"r284/patches/offline-source-guard-and-media-inspection.patch"
CHANGED={"src/Domain/Recovery/PrivateMediaTransferService.php",
         "bootstrap.php","public/_app.php","public/_guest_app.php"}
EXCLUDE={"resources/build-provenance.json","resources/release-identity-v1.json"}
H=lambda b:hashlib.sha256(b).hexdigest()
J=lambda x:(json.dumps(x,sort_keys=True,ensure_ascii=False,separators=(",",":"))+"\n").encode()
def managed(d):return H("".join(k+"\0"+H(v)+"\n" for k,v in sorted(d.items())).encode())
def canonical(d):return H("".join(k+"\0"+str(len(v))+"\0"+H(v)+"\n" for k,v in sorted(d.items()) if k not in EXCLUDE).encode())
def zipped(files):
    out=io.BytesIO()
    with zipfile.ZipFile(out,"w") as z:
        for name,content in sorted(files.items()):
            entry=zipfile.ZipInfo(name,(2026,11,18,0,0,0))
            entry.compress_type=zipfile.ZIP_STORED
            entry.create_system=3
            entry.external_attr=0o100644<<16
            z.writestr(entry,content,compress_type=zipfile.ZIP_STORED)
    return out.getvalue()
assert SOURCE.is_file() and H(SOURCE.read_bytes())==SHA,"R284_EXACT_R283_PARENT_SHA_WRONG"
with zipfile.ZipFile(SOURCE) as z:
    assert z.testzip() is None
    original=json.loads(z.read("release-set.json"))
    with zipfile.ZipFile(io.BytesIO(z.read("artifacts/local/web.zip"))) as web:
        assert web.testzip() is None
        parent={n:web.read(n) for n in web.namelist()}
assert original["targets"]["local"]["version"]=="3.0.0-g6.2-workspace.231fr-r283-private-media-staging-qa"
assert len(parent)==607
assert original["targets"]["local"]["managed_tree_sha256"]==managed(parent)
assert len([n for n in parent if n.startswith("database/migrations/")])==82
with tempfile.TemporaryDirectory(prefix="r284-source-exact-") as temporary:
    work=Path(temporary)
    for name in CHANGED:
        p=work/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(parent[name])
    p=subprocess.run(["patch","--batch","--forward","--fuzz=0","-p1","-i",str(PATCH)],cwd=work,capture_output=True,text=True)
    assert p.returncode==0,"R284_PATCH_REJECTED "+p.stdout+" "+p.stderr
    payload=parent.copy()
    for name in CHANGED:payload[name]=(work/name).read_bytes()
assert all(payload[n]!=parent[n] for n in CHANGED),"R284_UNCHANGED_REQUIRED_FILE"
for name,path in ADD.items():
    assert name not in payload and path.is_file()
    payload[name]=path.read_bytes()
ledger=json.loads(payload["resources/local-managed-files-v1.json"])
assert sorted(ledger["files"])==sorted(parent)
ledger["files"]=sorted([*ledger["files"],*ADD])
payload["resources/local-managed-files-v1.json"]=J(ledger)
payload["VERSION.txt"]=(VERSION+"\n").encode()
provenance=json.loads(payload["resources/build-provenance.json"])
provenance.update(version=VERSION,release_set_id=RELEASE,
  classification="R284_QA_ONLY_NO_PUBLIC_OWNER_TRANSFER",
  source="R283 exact SHA + offline source fence and authenticated handoff; 609 files, unchanged 82 SQL",
  production_host_install_verified=False,installed_host_accepted=False,
  full_business_acceptance=False)
payload["resources/build-provenance.json"]=J(provenance)
identity=json.loads(payload["resources/release-identity-v1.json"])
identity.update(version=VERSION,release_set_id=RELEASE)
identity["source"].update(canonical_tree_sha256=canonical(payload),
  managed_file_count=len(payload),canonical_file_count=len(payload)-len(EXCLUDE),
  source_ref="Immutable R283 231fr QA + R284 offline source fence; no Public code mutated")
identity["qualification"]["tier"]="R284_QA_ONLY_PHYSICAL_WINDOWS_AND_PUBLIC_OWNER_FIELD_PENDING"
payload["resources/release-identity-v1.json"]=J(identity)
modified={n for n in payload if n not in parent or payload[n]!=parent[n]}
expected=CHANGED|set(ADD)|{"VERSION.txt","resources/local-managed-files-v1.json",
                       "resources/build-provenance.json","resources/release-identity-v1.json"}
assert modified==expected,(sorted(modified-expected),sorted(expected-modified))
assert len(payload)==609
assert len([n for n in payload if n.startswith("database/migrations/")])==82
assert all(payload[n]==v for n,v in parent.items() if n not in modified)
binary=zipped(payload)
manifest=copy.deepcopy(original)
manifest["supported_baselines"].append({"local":copy.deepcopy(original["targets"]["local"]),"public":None})
assert len({x["local"]["version"] for x in manifest["supported_baselines"]})==len(manifest["supported_baselines"])
manifest.update(release_set_id=RELEASE,sequence=202611180284,
   status="R284_QA_ONLY_BLOCKED_REMOTE_AUTHORITY_AND_PHYSICAL_PRINT",
   for_install=False,production_pass=False)
manifest["targets"]["local"].update(version=VERSION,release_id=RELEASE,
   managed_tree_sha256=managed(payload),managed_files=len(payload))
manifest["artifacts"]["local-web"].update(sha256=H(binary),size_bytes=len(binary))
manifest["qa_notes"].update(operator_install_allowed=False,r284_field="NOT_RUN",
  r284="Offline web and LAN guest durable source fence, print drain and strong HMAC receipt; Public owner transfer not implemented",
  r284_windows_services_stopped="NEEDS_REAL_LOCAL_SERVICE_OWNER",
  r284_cafe_live="NOT_DEPLOYED")
OUT.mkdir(parents=True,exist_ok=True)
result=OUT/NAME;result.write_bytes(zipped({"release-set.json":J(manifest),"artifacts/local/web.zip":binary}))
proof={"format":"sokna-r284-source-qa-v1","parent_sha256":SHA,
 "candidate_sha256":H(result.read_bytes()),"candidate_file":NAME,
 "managed_files":len(payload),"sql_migrations":82,"version":VERSION,
 "canonical_source_sha256":canonical(payload),"managed_tree_sha256":managed(payload),
 "added_files":sorted(ADD),"patched_files":sorted(CHANGED),
 "remote_public_exclusivity_accepted":False,"new_machine_business_activation_accepted":False,
 "physical_windows_and_printer_accepted":False,"operator_install_allowed":False}
(OUT/"R284-SOURCE-PROOF.json").write_bytes(J(proof))
contract={"schema":"sokna-candidate-contract-v1","component":"local",
"producer":{"run_id":"same_run","artifact":"SOKNA-R284-EXACT-609-SOURCE-QA-ORIGINAL"},
"source_authority":{"approved_checkpoint":"R283_SOURCE_AUTHORITY_PLUS_R284_QA_ONLY",
"source_sha256":canonical(payload),"sha_semantics":"CANONICAL_MANAGED_SOURCE_TREE"},
"packages":[{"file":NAME,"sha256":proof["candidate_sha256"],"component":"local","kind":"local-update","version":VERSION}],
"supporting_files":[{"file":"R284-SOURCE-PROOF.json",
"sha256":H((OUT/"R284-SOURCE-PROOF.json").read_bytes())}],
"producer_qa_context":{"offline_source_fence":"SAME_RUN_CI",
"local_new_machine_cutover":"NOT_READY","public_owner_epoch":"NOT_READY",
"windows_service_field":"NOT_RUN","physical_print":"NOT_RUN"},
"production_authorized":False}
(OUT/"candidate-contract.json").write_bytes(J(contract))
print("R284_EXACT_SOURCE_FINAL",result)
print("R284_SHA256",proof["candidate_sha256"])
print("R284_MANAGED_FILES",len(payload))
print("R284_PRODUCTION_INSTALL_BLOCKED")
