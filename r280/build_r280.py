#!/usr/bin/env python3
"""R280: source-exact, QA-only cumulative R278 -> R279 transfer guardrails.
Inputs are the *original* R278 producer ZIP bytes, not the default branch tree.
No public, finance, printing, migration, Maintenance, or agent code is mutated.
"""
from __future__ import annotations
from pathlib import Path
import copy, hashlib, io, json, os, subprocess, tempfile, zipfile

PACKAGE=Path(os.environ["R280_R278_PACKAGE"])
OUT=Path(os.environ.get("R280_OUT","/tmp/sokna-r280"))
PATCH_ROOT=Path(__file__).resolve().parents[1]/"r279"/"patches"
PARENT_SHA="3a1070e18b14307fb2571634c961b20ca3e5d38e6c9a9dc1afad68be89fe6824"
PARENT_TREE="2e0d5febc7c8c023bcfe333648a2d83bea2013e7092c7269afb2cc17632d04c5"
VERSION="3.0.0-g6.2-workspace.231fo-r280-transfer-migration-guards"
RELEASE="sokna-r280-source-exact-transfer-qa-20261118"
NAME="SOKNA-R280-LOCAL-231FO-R278-TRANSFER-GUARDS-QA-DO-NOT-INSTALL.zip"
ALLOWED={
 "src/Domain/PublicEdge/PublicMoveUiInspectionService.php",
 "public/integrations/api.php",
 "public/assets/connections-workspace.js",
}
EXCLUDE={"resources/build-provenance.json","resources/release-identity-v1.json"}
H=lambda b:hashlib.sha256(b).hexdigest()
J=lambda x:(json.dumps(x,sort_keys=True,ensure_ascii=False,separators=(",",":"))+"\n").encode()
def managed(d):return H("".join(k+"\0"+H(b)+"\n" for k,b in sorted(d.items())).encode())
def canonical(d):return H("".join(k+"\0"+str(len(b))+"\0"+H(b)+"\n" for k,b in sorted(d.items()) if k not in EXCLUDE).encode())
def zipped(files):
    f=io.BytesIO()
    with zipfile.ZipFile(f,"w") as z:
        for n,b in sorted(files.items()):
            info=zipfile.ZipInfo(n,(2026,11,18,0,0,0))
            info.compress_type=zipfile.ZIP_STORED
            info.create_system=3
            info.external_attr=0o100644<<16
            z.writestr(info,b,compress_type=zipfile.ZIP_STORED)
    return f.getvalue()

assert PACKAGE.is_file(), "R278_IMMUTABLE_ORIGINAL_ZIP_MISSING"
assert H(PACKAGE.read_bytes())==PARENT_SHA, "R278_PARENT_BYTES_CHANGED"
with zipfile.ZipFile(PACKAGE) as source:
    assert source.testzip() is None
    manifest=json.loads(source.read("release-set.json"))
    with zipfile.ZipFile(io.BytesIO(source.read("artifacts/local/web.zip"))) as web:
        assert web.testzip() is None
        original={n:web.read(n) for n in web.namelist() if not n.endswith("/")}
assert len(original)==604
assert manifest["targets"]["local"]["managed_tree_sha256"]==PARENT_TREE
assert managed(original)==PARENT_TREE
assert manifest["targets"]["local"]["version"]=="3.0.0-g6.2-workspace.231fn-r278-rial-policy-persist"
assert len([p for p in original if p.startswith("database/migrations/")])==82
with tempfile.TemporaryDirectory(prefix="sokna-r280-exact-") as tmp:
    root=Path(tmp)
    for name,b in original.items():
        if name in ALLOWED:
            p=root/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(b)
    for patch_name in ["r275-local-transfer-guardrails.patch","r275-owner-lost-reply-ux.patch"]:
        result=subprocess.run(["patch","--batch","--forward","--fuzz=0","-p1","-i",str(PATCH_ROOT/patch_name)],
                              cwd=root,capture_output=True,text=True)
        assert result.returncode==0, "R280_EXACT_PATCH_FAIL "+patch_name+" "+result.stdout+" "+result.stderr
    changed={n for n in ALLOWED if (root/n).read_bytes()!=original[n]}
    assert changed==ALLOWED, "R280_PATCH_INCOMPLETE "+str(changed)
    payload=dict(original)
    for name in ALLOWED:payload[name]=(root/name).read_bytes()

# source-only change permissions: preserve the R277 finance and R278 rial fixes
assert all(payload[n]==b for n,b in original.items() if n not in ALLOWED)
for forbidden in ["src/Domain/Admin/AdminControlService.php","public/assets/admin-workspace.js",
                  "src/Domain/Recovery/BusinessBackupService.php"]:
    assert payload[forbidden]==original[forbidden], "R280_REGRESSION_ON_PROTECTED_SOURCE"
for n,b in original.items():
    if n.startswith(("src/Domain/Finance/","src/Domain/Orders/","src/Domain/Printing/",
                     "database/migrations/")):
        assert payload[n]==b, "R280_PROTECTED_BYTE_DIVERGENCE "+n

payload["VERSION.txt"]=(VERSION+"\n").encode()
prov=json.loads(payload["resources/build-provenance.json"])
prov.update(version=VERSION,release_set_id=RELEASE,
    classification="R280_QA_ONLY_FIELD_AND_MACHINE_TRANSFER_NOT_ACCEPTED",
    source="Exact R278 factory-producer ZIP SHA + R279 three-file safety changes; no DB, Windows, print or Public mutation",
    installed_host_accepted=False,production_host_install_verified=False,
    full_business_acceptance=False)
payload["resources/build-provenance.json"]=J(prov)
identity=json.loads(payload["resources/release-identity-v1.json"])
identity.update(version=VERSION,release_set_id=RELEASE)
identity["source"].update(canonical_tree_sha256=canonical(payload),
    managed_file_count=604,canonical_file_count=602,
    source_ref="R278 immutable producer ZIP 3a1070e... + R279 exact safety patches, REG-003 field pending")
identity["qualification"]["tier"]="R280_NO_OPERATOR_INSTALL_FIELD_AND_MACHINE_PENDING"
payload["resources/release-identity-v1.json"]=J(identity)
inner=zipped(payload)
assert managed(payload)!=PARENT_TREE
updated=copy.deepcopy(manifest)
updated["supported_baselines"].append({"local":copy.deepcopy(manifest["targets"]["local"]),"public":None})
versions=[x["local"]["version"] for x in updated["supported_baselines"]]
assert len(versions)==len(set(versions))==4
updated.update(release_set_id=RELEASE,sequence=202611180280,
    status="R280_QA_ONLY_NOT_OPERATOR_INSTALL",for_install=False,production_pass=False)
updated["targets"]["local"].update(version=VERSION,release_id=RELEASE,
    managed_tree_sha256=managed(payload),managed_files=604)
updated["artifacts"]["local-web"].update(sha256=H(inner),size_bytes=len(inner))
updated["qa_notes"].update(operator_install_allowed=False,r280_field="NOT_TESTED",
    r280_native_machine_transfer="PENDING",
    r280="Three-file exact R279 guardrails on R278 231fn; R277 finance and R278 Rial bytes preserved")
OUT.mkdir(parents=True,exist_ok=True)
result=zipped({"release-set.json":J(updated),"artifacts/local/web.zip":inner})
final=OUT/NAME;final.write_bytes(result)
with zipfile.ZipFile(final) as z:
    assert z.testzip() is None
    assert H(z.read("artifacts/local/web.zip"))==H(inner)
    assert json.loads(z.read("release-set.json"))["targets"]["local"]["managed_tree_sha256"]==managed(payload)
proof={
 "format":"sokna-r280-source-qa-v1","parent_original_sha256":PARENT_SHA,
 "candidate_sha256":H(result),"candidate_file":NAME,"version":VERSION,
 "old_tree":PARENT_TREE,"new_tree":managed(payload),
 "canonical_source_tree":canonical(payload),"managed_files":604,"migrations":82,
 "source_changes":sorted(ALLOWED),"code_metadata_changes":["VERSION.txt","resources/build-provenance.json","resources/release-identity-v1.json"],
 "protected_bytes_preserved":True,"source_prefix":"Exact R278/231fn factory producer artifact",
 "operator_install_allowed":False,"field_accepted":False,"native_windows_transfer_accepted":False,
}
(OUT/"R280-SOURCE-PROOF.json").write_bytes(J(proof))
contract={
 "schema":"sokna-candidate-contract-v1","component":"local",
 "producer":{"run_id":"same_run","artifact":"SOKNA-R280-EXACT-ORIGINAL-PRODUCER-QA-NO-INSTALL"},
 "source_authority":{"approved_checkpoint":"R278_VERIFIED_PARENT_PLUS_R279_QA_FIELD_PENDING_REG003",
     "source_sha256":canonical(payload),"sha_semantics":"CANONICAL_MANAGED_SOURCE_TREE"},
 "packages":[{"file":NAME,"sha256":H(result),"component":"local","kind":"local-update","version":VERSION}],
 "supporting_files":[{"file":"R280-SOURCE-PROOF.json","sha256":H((OUT/"R280-SOURCE-PROOF.json").read_bytes())}],
 "producer_qa_context":{"field_cafe":"NOT_RUN","windows_new_machine":"NOT_RUN",
      "windows_native_stage":"SEPARATE_REQUIRED","migration":"82_UNCHANGED",
      "public_http_existing_paired":"PRIOR_R279_EVIDENCE_REQUIRES_R280_REQUALIFICATION"},
 "production_authorized":False,
}
(OUT/"candidate-contract.json").write_bytes(J(contract))
print("R280_EXACT_PARENT",PARENT_SHA)
print("R280_SHA256",H(result))
print("R280_MANAGED_TREE",managed(payload))
print("R280_SOURCE_CHANGES",",".join(sorted(ALLOWED)))
print("R280_82_SQL_NO_FINANCE_NO_RIAL_NO_PRINT_CHANGES")
print("R280_OUT",str(final))
