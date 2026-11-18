#!/usr/bin/env python3
"""R283 exact original factory-source R282 -> additive private media tooling QA.
Never fabricate source; use original R282 ZIP bytes SHA and verify every retained
managed file. Does NOT authorize machine cutover or live cafe upgrade.
"""
import copy,hashlib,io,json,os,zipfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
INPUT=Path(os.environ["R283_EXACT_R282_PACKAGE"])
OUT=Path(os.environ.get("R283_OUTPUT","/tmp/sokna-r283"))
PARENT_SHA="21d0b209695462c4cfa2a567e2e0755cfe234f3788ac183c0d94a9ce9e3e7563"
VERSION="3.0.0-g6.2-workspace.231fr-r283-private-media-staging-qa"
RELEASE="sokna-r283-verified-private-media-stage-qa-20261118"
FILENAME="SOKNA-R283-LOCAL-231FR-PRIVATE-MEDIA-TRANSFER-QA-DO-NOT-INSTALL.zip"
ADDED=["src/Domain/Recovery/PrivateMediaTransferService.php","bin/sokna-media-transfer.php"]
EXCL={"resources/build-provenance.json","resources/release-identity-v1.json"}
H=lambda b:hashlib.sha256(b).hexdigest()
J=lambda x:(json.dumps(x,sort_keys=True,ensure_ascii=False,separators=(",",":"))+"\n").encode()
def managed(d):return H("".join(n+"\0"+H(b)+"\n" for n,b in sorted(d.items())).encode())
def canonical(d):return H("".join(n+"\0"+str(len(b))+"\0"+H(b)+"\n" for n,b in sorted(d.items()) if n not in EXCL).encode())
def zipped(files):
    b=io.BytesIO()
    with zipfile.ZipFile(b,"w") as z:
        for name,raw in sorted(files.items()):
            i=zipfile.ZipInfo(name,(2026,11,18,0,0,0))
            i.compress_type=zipfile.ZIP_STORED;i.create_system=3;i.external_attr=0o100644<<16
            z.writestr(i,raw,compress_type=zipfile.ZIP_STORED)
    return b.getvalue()

assert INPUT.is_file() and H(INPUT.read_bytes())==PARENT_SHA,"R283_EXACT_R282_INPUT_MISSING_OR_WRONG_SHA"
with zipfile.ZipFile(INPUT) as z:
    assert z.testzip() is None
    m=json.loads(z.read("release-set.json"))
    with zipfile.ZipFile(io.BytesIO(z.read("artifacts/local/web.zip"))) as web:
        assert web.testzip() is None
        parent={n:web.read(n) for n in web.namelist()}
assert len(parent)==605 and managed(parent)==m["targets"]["local"]["managed_tree_sha256"]
assert m["targets"]["local"]["version"]=="3.0.0-g6.2-workspace.231fq-r282-isolated-restore-ui"
assert sum(n.startswith("database/migrations/") for n in parent)==82
new=dict(parent)
for filename in ADDED:
    assert filename not in new
    path=ROOT/"r283/overlay"/filename
    assert path.is_file(),filename
    new[filename]=path.read_bytes()
inventory=json.loads(new["resources/local-managed-files-v1.json"])
assert sorted(inventory["files"])==sorted(parent)
inventory["files"]=sorted([*inventory["files"],*ADDED])
new["resources/local-managed-files-v1.json"]=J(inventory)
new["VERSION.txt"]=(VERSION+"\n").encode()
prov=json.loads(new["resources/build-provenance.json"])
prov.update(version=VERSION,release_set_id=RELEASE,
    classification="R283_QA_ONLY_NOT_OPERATOR_INSTALL",
    source="Exact original 231fq R282 producer plus two additive private-media files, no changes to existing business logic",
    production_host_install_verified=False,installed_host_accepted=False,
    full_business_acceptance=False)
new["resources/build-provenance.json"]=J(prov)
identity=json.loads(new["resources/release-identity-v1.json"])
identity.update(version=VERSION,release_set_id=RELEASE)
identity["source"].update(canonical_tree_sha256=canonical(new),
   managed_file_count=len(new),canonical_file_count=len(new)-len(EXCL),
   source_ref="Immutable original R282 231fq SHA + R283 encrypted media transfer")
identity["qualification"]["tier"]="R283_QA_NO_OPERATOR_INSTALL_OR_MACHINE_CUTOVER"
new["resources/release-identity-v1.json"]=J(identity)
assert len(new)==607
assert {n for n in new if n not in parent or new[n]!=parent[n]}=={
  *ADDED,"resources/local-managed-files-v1.json","VERSION.txt",
  "resources/build-provenance.json","resources/release-identity-v1.json"}
assert all(new[n]==b for n,b in parent.items() if n not in {
 "resources/local-managed-files-v1.json","VERSION.txt",
 "resources/build-provenance.json","resources/release-identity-v1.json"})
assert sum(n.startswith("database/migrations/") for n in new)==82
payload=zipped(new)
result=copy.deepcopy(m)
result["supported_baselines"].append({"local":copy.deepcopy(m["targets"]["local"]),"public":None})
versions=[x["local"]["version"] for x in result["supported_baselines"]]
assert len(versions)==len(set(versions))
result.update(release_set_id=RELEASE,sequence=202611180283,
  status="R283_QA_ONLY_EXTERNAL_CUTOVER_AND_MEDIA_FIELD_NOT_ACCEPTED",
  for_install=False,production_pass=False)
result["targets"]["local"].update(version=VERSION,release_id=RELEASE,
  managed_tree_sha256=managed(new),managed_files=len(new))
result["artifacts"]["local-web"].update(sha256=H(payload),size_bytes=len(payload))
result["qa_notes"].update(operator_install_allowed=False,
    r283="Self-contained authenticated media transfer CLI; machine activation, Public exclusive ownership and physical printing not validated",
    r283_native_linux_windows_crypto="REQUIRED_IN_SAME_RUN",
    r283_field_cafe="NOT_TESTED")
OUT.mkdir(parents=True,exist_ok=True)
file=OUT/FILENAME
file.write_bytes(zipped({"release-set.json":J(result),"artifacts/local/web.zip":payload}))
proof={"format":"sokna-r283-source-proof-v1",
    "parent_exact_sha256":PARENT_SHA,"file":FILENAME,
    "sha256":H(file.read_bytes()),"version":VERSION,
    "managed_files":len(new),"managed_tree":managed(new),"canonical_tree":canonical(new),
    "sql_migrations":82,"added_product_files":ADDED,
    "unmodified_business_and_public_logic":True,
    "machine_cutover_complete":False,"field_operator_install_allowed":False}
(OUT/"R283-SOURCE-PROOF.json").write_bytes(J(proof))
contract={
 "schema":"sokna-candidate-contract-v1","component":"local",
 "producer":{"run_id":"same_run","artifact":"SOKNA-R283-607-FILE-IMMUTABLE-PRODUCER-QA"},
 "source_authority":{"approved_checkpoint":"R282_ORIGINAL_SOURCE_PLUS_R283_MEDIA_CRYPTO_QA_FIELD_PENDING",
   "source_sha256":canonical(new),"sha_semantics":"CANONICAL_MANAGED_SOURCE_TREE"},
 "packages":[{"file":FILENAME,"sha256":proof["sha256"],"component":"local",
    "kind":"local-update","version":VERSION}],
 "supporting_files":[{"file":"R283-SOURCE-PROOF.json",
    "sha256":H((OUT/"R283-SOURCE-PROOF.json").read_bytes())}],
 "producer_qa_context":{"native_linux_and_windows_media_crypto":"SAME_RUN",
    "physical_print":"NOT_RUN","new_machine_cutover":"BLOCKED","field_cafe":"NOT_RUN"},
 "production_authorized":False,
}
(OUT/"candidate-contract.json").write_bytes(J(contract))
print("R283_EXACT_PARENT",PARENT_SHA)
print("R283_QA_CUMULATIVE_ZIP_SHA256",proof["sha256"])
print("R283_MANAGED_FILES",len(new))
print("R283_OUTPUT",file)
