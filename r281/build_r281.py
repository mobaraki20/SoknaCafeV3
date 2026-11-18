#!/usr/bin/env python3
"""R281 QA-only Local cumulative built from immutable R280 factory producer.

Only additive source path:
  src/Domain/Recovery/SafeRestoreStagingService.php
No database migrations, orders, finance, printing, Public, or rial persistence
asset changes. The service deliberately does not activate a restored machine.
"""
from __future__ import annotations
import copy, hashlib, io, json, os, zipfile
from pathlib import Path

SOURCE=Path(os.environ["R281_R280_EXACT_ZIP"])
OUT=Path(os.environ.get("R281_OUTPUT","/tmp/sokna-r281-candidate"))
ROOT=Path(__file__).resolve().parents[1]
PARENT_SHA="12bf30d977dfa82ef047381d71153f8f5149653edc706833779f6bb7daa7a277"
PARENT_TREE="dd9d1c936d7e99bf7d045c46e53a7937c88a4d49f9e98c9158f5f99b4f49fb68"
VERSION="3.0.0-g6.2-workspace.231fp-r281-restore-staging-qa"
RELEASE="sokna-r281-machine-recovery-stage-qa-20261118"
NAME="SOKNA-R281-LOCAL-231FP-RESTORE-STAGING-QA-DO-NOT-INSTALL.zip"
NEW_FILE="src/Domain/Recovery/SafeRestoreStagingService.php"
H=lambda b:hashlib.sha256(b).hexdigest()
J=lambda data:(json.dumps(data,sort_keys=True,separators=(",",":"),ensure_ascii=False)+"\n").encode()
EXCLUDE={"resources/build-provenance.json","resources/release-identity-v1.json"}
def managed(d):return H("".join(k+"\0"+H(v)+"\n" for k,v in sorted(d.items())).encode())
def canonical(d):return H("".join(k+"\0"+str(len(v))+"\0"+H(v)+"\n" for k,v in sorted(d.items()) if k not in EXCLUDE).encode())
def archive(files):
    b=io.BytesIO()
    with zipfile.ZipFile(b,"w") as z:
        for k,v in sorted(files.items()):
            i=zipfile.ZipInfo(k,(2026,11,18,0,0,0))
            i.compress_type=zipfile.ZIP_STORED;i.create_system=3;i.external_attr=0o100644<<16
            z.writestr(i,v,compress_type=zipfile.ZIP_STORED)
    return b.getvalue()

assert SOURCE.is_file() and H(SOURCE.read_bytes())==PARENT_SHA, "R281_PARENT_R280_ZIP_SHA_MISMATCH"
with zipfile.ZipFile(SOURCE) as z:
    assert z.testzip() is None
    r=json.loads(z.read("release-set.json"))
    with zipfile.ZipFile(io.BytesIO(z.read("artifacts/local/web.zip"))) as inner:
        assert inner.testzip() is None
        previous={n:inner.read(n) for n in inner.namelist()}
assert len(previous)==604
assert r["targets"]["local"]["version"]=="3.0.0-g6.2-workspace.231fo-r280-transfer-migration-guards"
# The input's own managed tree is the authoritative parent receipt; never
# assume a numeric version or a stale code checkout identifies its bytes.
assert managed(previous)==r["targets"]["local"]["managed_tree_sha256"]
assert NEW_FILE not in previous
assert len([n for n in previous if n.startswith("database/migrations/")])==82

source=ROOT/"r281/overlay/local"/NEW_FILE
assert source.is_file()
content=source.read_bytes()
assert b"final class SafeRestoreStagingService" in content
new=previous.copy()
new[NEW_FILE]=content
new["VERSION.txt"]=(VERSION+"\n").encode()
provenance=json.loads(new["resources/build-provenance.json"])
provenance.update(version=VERSION,release_set_id=RELEASE,
    classification="R281_SAFE_STAGING_QA_ONLY_NOT_AN_OPERATOR_CUTOVER",
    source="Exact original R280/231fo same-run factory producer + one added staged restore service",
    production_host_install_verified=False,installed_host_accepted=False,
    full_business_acceptance=False)
new["resources/build-provenance.json"]=J(provenance)
identity=json.loads(new["resources/release-identity-v1.json"])
identity.update(version=VERSION,release_set_id=RELEASE)
identity["source"].update(canonical_tree_sha256=canonical(new),
    managed_file_count=len(new),canonical_file_count=len(new)-len(EXCLUDE),
    source_ref="Immutable exact R280 231fo original producer + isolated R281 recovery helper QA")
identity["qualification"]["tier"]="R281_QA_NO_MACHINE_TAKEOVER_OR_OPERATOR_INSTALL"
new["resources/release-identity-v1.json"]=J(identity)
assert len(new)==605 and managed(new)!=managed(previous)
actual_changes={n for n in new if n not in previous or new[n]!=previous[n]}
assert actual_changes=={NEW_FILE,"VERSION.txt","resources/build-provenance.json","resources/release-identity-v1.json"},actual_changes
for name,value in previous.items():
    if name not in actual_changes:assert new[name]==value
web=archive(new)
package=copy.deepcopy(r)
package["supported_baselines"].append({"local":copy.deepcopy(r["targets"]["local"]),"public":None})
package.update(release_set_id=RELEASE,sequence=202611180281,
    status="R281_QA_ONLY_NO_MACHINE_TRANSFER_ACCEPTANCE",for_install=False,production_pass=False)
package["targets"]["local"].update(version=VERSION,release_id=RELEASE,
    managed_tree_sha256=managed(new),managed_files=605)
package["artifacts"]["local-web"].update(sha256=H(web),size_bytes=len(web))
package["qa_notes"].update(operator_install_allowed=False,
    r281="Isolated restore stage only; no operator activation, original DB untouched",
    r281_field="NOT_RUN",r281_windows_maintenance_print="NOT_RUN")
OUT.mkdir(parents=True,exist_ok=True)
output=OUT/NAME
output.write_bytes(archive({"release-set.json":J(package),"artifacts/local/web.zip":web}))
proof={
    "schema":"sokna-r281-qa-only-v1",
    "parent_exact_sha256":PARENT_SHA,
    "parent_managed_tree":r["targets"]["local"]["managed_tree_sha256"],
    "qa_candidate_file":NAME,"qa_candidate_sha256":H(output.read_bytes()),
    "target_tree":managed(new),"target_canonical_tree":canonical(new),
    "managed_files":605,"migrations":82,
    "added_source_files":[NEW_FILE],"changed_existing_source_files":[],
    "preserved_local_public_communication":True,
    "preserved_finance_print_recovery_base":True,
    "operator_install_allowed":False,"machine_cutover_complete":False,
}
(OUT/"R281-PREFACTORY-SOURCE-PROOF.json").write_bytes(J(proof))
contract={
  "schema":"sokna-candidate-contract-v1","component":"local",
  "producer":{"run_id":"same_run","artifact":"SOKNA-R281-EXACT-CUMULATIVE-QA-ORIGINAL"},
  "source_authority":{"approved_checkpoint":"R280_ORIGINAL_PRODUCER_PLUS_R281_QA_STAGE_SOURCE_FIELD_PENDING",
      "source_sha256":canonical(new),"sha_semantics":"CANONICAL_MANAGED_SOURCE_TREE"},
  "packages":[{"file":NAME,"sha256":proof["qa_candidate_sha256"],"component":"local",
       "kind":"local-update","version":VERSION}],
  "supporting_files":[{"file":"R281-PREFACTORY-SOURCE-PROOF.json",
       "sha256":H((OUT/"R281-PREFACTORY-SOURCE-PROOF.json").read_bytes())}],
  "producer_qa_context":{"staged_real_mariadb_82_migrations":"SAME_RUN_TEST",
      "physical_print":"NOT_RUN","new_machine_cutover":"BLOCKED","field_cafe":"NOT_RUN"},
  "production_authorized":False,
}
(OUT/"candidate-contract.json").write_bytes(J(contract))
print("R281_EXACT_R280_PARENT_SHA",PARENT_SHA)
print("R281_SOURCE_ONLY_ONE_NEW_FILE",NEW_FILE)
print("R281_MANAGED_FILES",len(new))
print("R281_QA_ZIP_SHA",proof["qa_candidate_sha256"])
print("R281_QA_OUTPUT",str(output))
