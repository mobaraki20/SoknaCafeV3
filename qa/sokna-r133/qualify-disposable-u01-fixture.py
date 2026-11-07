#!/usr/bin/env python3
"""CI-ONLY: alter an already-built U01 fixture in RUNNER_TEMP, NEVER a release.
No source files, signing keys, actual customer data or publishing destination.
"""
from __future__ import annotations
import io, json, os, pathlib, sys, zipfile

def abort(code:str)->None:
    raise SystemExit("R133_LAB_ONLY:"+code)

if os.environ.get("GITHUB_ACTIONS")!="true" or os.environ.get("RUNNER_OS")!="Windows" or os.environ.get("SOKNA_R133_LAB_ONLY")!="1":
    abort("REFUSE_NON_WINDOWS_EPHEMERAL_CI")
root=pathlib.Path(os.environ.get("RUNNER_TEMP","/invalid")).resolve(strict=True)
p=pathlib.Path(sys.argv[1]).resolve(strict=True) if len(sys.argv)==2 else abort("EXACTLY_ONE_ZIP_REQUIRED")
if not p.is_relative_to(root) or not p.is_file() or p.suffix.lower()!=".zip":
    abort("NOT_IN_TEMP_ZIP")
with zipfile.ZipFile(p) as z:
    if z.testzip() is not None:abort("BAD_INPUT_CRC")
    files={n:z.read(n) for n in z.namelist()}
if set(files)!={"release-set.json","artifacts/local/web.zip"}:abort("UNEXPECTED_ARTIFACT_LAYOUT")
manifest=json.loads(files["release-set.json"])
if (manifest.get("for_install") is not False or manifest.get("production_pass") is not False
    or manifest.get("release_type")!="local_only" or manifest.get("qualification")!={}):
    abort("REQUIRES_ORIGINAL_TEST_ONLY_MANIFEST")
manifest["for_install"]=True
manifest["production_pass"]=True
manifest["status"]="QUALIFIED_DISPOSABLE_CI_FIXTURE_NOT_FOR_CUSTOMER_USE"
# This status is deliberately not an install prohibition token only because this
# ephemeral test must cross Stage. It is *not* proof of real production readiness.
manifest["qualification"]={
    "failure_matrix_id":"synthetic-ci-only-not-a-witness",
    "failure_matrix_cases_required":80,
    "real_windows":True,"real_mariadb":True,"real_public_host":False,
    "canary":True,"recovery_console_web_down":True,
    "business_backup_consistency_real_mariadb":True,
    "remote_vault_real_host":False,"clean_machine_remote_restore":False
}
files["release-set.json"]=(json.dumps(manifest,ensure_ascii=False,indent=2)+"\n").encode("utf-8")
output=io.BytesIO()
with zipfile.ZipFile(output,"w",zipfile.ZIP_DEFLATED) as z:
    for name,body in sorted(files.items()):
        z.writestr(name,body)
p.write_bytes(output.getvalue())
print("R133_DISPOSABLE_U01_FIXTURE_CONVERTED_WITH_SYNTHETIC_CI_PROOFS_ONLY")
