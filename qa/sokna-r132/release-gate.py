#!/usr/bin/env python3
"""Mandatory OFFLINE archive gate for Local release candidates.
Exit 0 = structural/proof fields plausible (never installation permission);
2 = broken ZIP or inventory; 3 = unapproved/UAT for production.
"""
import argparse
import hashlib
import io
import json
import re
import sys
import zipfile
from pathlib import Path

def sha(data):
    return hashlib.sha256(data).hexdigest()

def safe_path(s):
    return (isinstance(s, str) and bool(s) and not s.startswith("/")
            and not re.match(r"^[A-Za-z]:", s)
            and chr(92) not in s and chr(0) not in s
            and all(c not in (".", "..", "") for c in s.split("/")))

def names(z):
    entries = [i for i in z.infolist() if not i.is_dir()]
    paths = [i.filename for i in entries]
    if (len(paths)>5000 or len(paths)!=len({p.casefold() for p in paths})
            or any(not safe_path(p) for p in paths)
            or sum(i.file_size for i in entries)>150*1024*1024
            or any(((i.external_attr>>16)&0o170000)==0o120000 for i in entries)
            or z.testzip() is not None):
        raise ValueError("INVALID_ZIP_PATH_OR_CRC")
    return paths

def examine(zip_path, evidence=None):
    result={"filename":Path(zip_path).name,"offline_only":True,
            "deployment_authorized":False,"structural_failures":[],
            "production_failures":[],"missing_inventory_files":[],
            "unexpected_inventory_files":[]}
    bad=result["structural_failures"]
    not_ready=result["production_failures"]
    try:
        with zipfile.ZipFile(zip_path) as outer:
            on=names(outer)
            if not {"release-set.json","artifacts/local/web.zip"}.issubset(on):
                raise ValueError("MISSING_LOCAL_RELEASE_SET")
            m=json.loads(outer.read("release-set.json"))
            result["status"]=str(m.get("status",""))
            if m.get("release_type")!="local_only":bad.append("WRONG_RELEASE_TYPE")
            payload=outer.read("artifacts/local/web.zip")
            if len(payload)>100*1024*1024:raise ValueError("WEB_ZIP_TOO_BIG")
            artifact=(m.get("artifacts") or {}).get("local-web") or {}
            if artifact.get("sha256")!=sha(payload) or artifact.get("size_bytes")!=len(payload):
                bad.append("ARTIFACT_HASH_OR_SIZE")
            with zipfile.ZipFile(io.BytesIO(payload)) as web:
                present=names(web)
                if "resources/local-managed-files-v1.json" not in present:
                    raise ValueError("NO_MANAGED_INVENTORY")
                inv=json.loads(web.read("resources/local-managed-files-v1.json"))
                if not isinstance(inv,dict) or inv.get("format")!="sokna-local-managed-files-v1":
                    raise ValueError("MANAGED_INVENTORY_FORMAT")
                listed=inv.get("files") if isinstance(inv,dict) else None
                if not isinstance(listed,list) or any(not safe_path(p) for p in listed):
                    raise ValueError("MANAGED_INVENTORY_INVALID")
                if len(listed)!=len(set(listed)):bad.append("INVENTORY_DUPLICATED")
                result["missing_inventory_files"]=sorted(set(present)-set(listed))
                result["unexpected_inventory_files"]=sorted(set(listed)-set(present))
                if result["missing_inventory_files"] or result["unexpected_inventory_files"]:
                    bad.append("INVENTORY_FILE_SET_MISMATCH")
                target=((m.get("targets") or {}).get("local") or {})
                result["actual_files"]=len(present)
                if target.get("managed_files")!=len(present) or len(listed)!=len(present):
                    bad.append("MANAGED_FILE_COUNT_MISMATCH")
                if not result["missing_inventory_files"] and not result["unexpected_inventory_files"]:
                    h=hashlib.sha256()
                    for p in sorted(listed):
                        h.update(p.encode()+bytes([0])+sha(web.read(p)).encode()+bytes([10]))
                    if h.hexdigest()!=target.get("managed_tree_sha256"):
                        bad.append("MANAGED_TREE_SHA256_MISMATCH")
                if web.read("VERSION.txt").decode("utf-8-sig").strip()!=target.get("version"):
                    bad.append("TARGET_VERSION_MISMATCH")
                sql={p.removeprefix("database/migrations/")[:-4]:sha(web.read(p))
                     for p in present if re.fullmatch(r"database/migrations/[0-9]{4}_[a-z0-9_]+\.sql",p)}
                if not sql:bad.append("MIGRATION_CATALOG_EMPTY")
                fingerprint="migration-ledger-sha256-v1:"+sha("".join(
                    n+chr(0)+sql[n]+chr(10) for n in sorted(sql)).encode())
                if target.get("schema_fingerprint")!=fingerprint or target.get("migration_head")!=max(sql,default=""):
                    bad.append("MIGRATION_IDENTITY_MISMATCH")
                dag=(((m.get("database") or {}).get("local") or {}).get("migration_dag"))
                if dag!=sorted(sql):bad.append("MIGRATION_DAG_MISMATCH")
            if m.get("for_install") is not True:not_ready.append("NOT_FOR_INSTALL")
            if m.get("production_pass") is not True:not_ready.append("NO_PRODUCTION_PASS")
            if re.search(r"(UAT|NOT[_ -]?PRODUCTION|NOT[_ -]?FIELD[_ -]?APPROVED|TEST[_ -]?ONLY|NOT[_ -]?FOR[_ -]?INSTALL)",result["status"],re.I):
                not_ready.append("TEST_OR_NOT_APPROVED_STATUS")
            # U-06: managed operator upload is keyless. Publisher signature
            # metadata is neither required nor proof of installation quality.
            if re.search(r"HOME[_ -]?(CANARY|UAT)|LAB[_ -]?ONLY|SOURCE[_ -]?ONLY|ENGINEERING",result["status"],re.I):
                not_ready.append("NON_PRODUCTION_SCOPE")
            q=m.get("qualification") or {}
            if not (isinstance(q,dict) and q.get("failure_matrix_id")
                    and type(q.get("failure_matrix_cases_required")) is int
                    and q.get("failure_matrix_cases_required")>=80
                    and all(q.get(k) is True for k in (
                      "real_windows","real_mariadb","canary",
                      "recovery_console_web_down","business_backup_consistency_real_mariadb"))):
                not_ready.append("RELEASE_QUALIFICATION_INCOMPLETE")
            backup=m.get("pre_update_backup_policy") or {}
            if not isinstance(backup,dict) or backup.get("required") is not True:
                not_ready.append("PRE_UPDATE_BACKUP_POLICY_MISSING")
            # Evidence is supplied OUTSIDE the uploaded ZIP by the release job.
            # Do not trust booleans from that ZIP as independent qualification.
            # This is an offline consistency gate, not a trust/signing service
            # and never a replacement for the installed host's safety checks.
            not_ready.extend(qualification_failures(evidence,sha(Path(zip_path).read_bytes()),m))
    except (ValueError,KeyError,TypeError,AttributeError,OSError,zipfile.BadZipFile,json.JSONDecodeError) as ex:
        bad.append("INSPECTION_ERROR:"+type(ex).__name__)
    result["structurally_valid"]=not bad
    result["offline_production_fields_valid"]=not bad and not not_ready
    return (2 if bad else 3 if not_ready else 0),result

def qualification_failures(evidence,package_sha,manifest):
    if not isinstance(evidence,dict):return ["INDEPENDENT_QUALIFICATION_EVIDENCE_MISSING"]
    failures=[]
    if evidence.get("format")!="sokna-release-evidence-v1" or evidence.get("package_sha256")!=package_sha:
        failures.append("QUALIFICATION_PACKAGE_BINDING_MISMATCH")
    if evidence.get("scope")!="production" or evidence.get("physical_cafe_host_tested") is not True:
        failures.append("FIELD_QUALIFICATION_MISSING")
    if evidence.get("real_cafe_backup_restored") is not True:
        failures.append("CAFE_BACKUP_RESTORE_NOT_VERIFIED")
    receipts=evidence.get("installs")
    if not isinstance(receipts,list):return failures+["TWO_BASELINE_INSTALL_RECEIPTS_MISSING"]
    baselines=manifest.get("supported_baselines") or []
    allowed={json.dumps(x.get("local"),sort_keys=True) for x in baselines if isinstance(x,dict)}
    target=(manifest.get("targets") or {}).get("local")
    seen=set()
    for receipt in receipts:
        if not isinstance(receipt,dict):
            failures.append("INSTALL_RECEIPT_INVALID");continue
        source=json.dumps(receipt.get("source_identity"),sort_keys=True)
        if (receipt.get("package_sha256")!=package_sha or source not in allowed or
            receipt.get("target_identity")!=target or
            any(receipt.get(k) is not True for k in ("committed","readback_verified","business_invariants_verified")) or
            not receipt.get("receipt_id") or not receipt.get("observed_at")):
            failures.append("INSTALL_RECEIPT_INVALID")
        else:seen.add(source)
    if len(seen)<2:failures.append("TWO_BASELINE_INSTALL_RECEIPTS_MISSING")
    if any(evidence.get(k) is not True for k in ("pre_db_failure_recovery_verified","post_db_forward_recovery_verified")):
        failures.append("FAILURE_RECOVERY_EVIDENCE_MISSING")
    return failures

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("zip")
    parser.add_argument("--json")
    parser.add_argument("--evidence",help="Release-controlled receipts outside the package; never inferred from manifest flags")
    args=parser.parse_args()
    evidence=json.loads(Path(args.evidence).read_text(encoding="utf-8")) if args.evidence else None
    code,report=examine(args.zip,evidence)
    data=json.dumps(report,ensure_ascii=False,indent=2)+"\n"
    if args.json:Path(args.json).write_text(data,encoding="utf-8")
    print(data)
    return code

if __name__=="__main__":sys.exit(main())

