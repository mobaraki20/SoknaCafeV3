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
    if (len(paths)>5000 or len(paths)!=len(set(paths))
            or any(not safe_path(p) for p in paths)
            or sum(i.file_size for i in entries)>150*1024*1024
            or any(((i.external_attr>>16)&0o170000)==0o120000 for i in entries)
            or z.testzip() is not None):
        raise ValueError("INVALID_ZIP_PATH_OR_CRC")
    return paths

def examine(zip_path):
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
            if m.get("for_install") is not True:not_ready.append("NOT_FOR_INSTALL")
            if m.get("production_pass") is not True:not_ready.append("NO_PRODUCTION_PASS")
            if re.search(r"(UAT|NOT[_ -]?PRODUCTION|NOT[_ -]?FIELD[_ -]?APPROVED|TEST[_ -]?ONLY|NOT[_ -]?FOR[_ -]?INSTALL)",result["status"],re.I):
                not_ready.append("TEST_OR_NOT_APPROVED_STATUS")
            sig=m.get("signature")
            if not (isinstance(sig,dict) and sig.get("algorithm")=="ecdsa-p256-sha256"
                    and sig.get("key_id") and sig.get("value")):
                not_ready.append("PUBLISHER_SIGNATURE_METADATA_MISSING")
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
    except (ValueError,KeyError,TypeError,OSError,zipfile.BadZipFile,json.JSONDecodeError) as ex:
        bad.append("INSPECTION_ERROR:"+type(ex).__name__)
    result["structurally_valid"]=not bad
    result["offline_production_fields_valid"]=not bad and not not_ready
    return (2 if bad else 3 if not_ready else 0),result

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("zip")
    parser.add_argument("--json")
    args=parser.parse_args()
    code,report=examine(args.zip)
    data=json.dumps(report,ensure_ascii=False,indent=2)+"\n"
    if args.json:Path(args.json).write_text(data,encoding="utf-8")
    print(data)
    return code

if __name__=="__main__":sys.exit(main())
