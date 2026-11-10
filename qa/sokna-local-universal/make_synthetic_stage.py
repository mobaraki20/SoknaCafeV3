#!/usr/bin/env python3
"""An ephemeral CI-only installable fixture for genuine Windows Apache/MariaDB U01 tests.

NEVER distribute, publish or copy this artifact out of RUNNER_TEMP.
Production qualification and a real user's backup cannot be inferred from it.
"""
from pathlib import Path
import zipfile,json,hashlib,os,sys,io
from universal_local_release_contract import load,deterministic_archive,EXPECTED_PLAN,sha
if len(sys.argv)!=4:raise SystemExit("usage: make_synthetic_stage.py R153-CONTRACT-QA.zip PINNED-R149B-QUALIFIED.zip disposable-lab-root")
qa,old,root=(Path(x).resolve() for x in sys.argv[1:])
tmp=Path(os.environ.get("RUNNER_TEMP","/nonexistent")).resolve()
if os.environ.get("GITHUB_ACTIONS")!="true" or os.environ.get("RUNNER_OS")!="Windows" or not root.is_relative_to(tmp):
 raise RuntimeError("SYNTHETIC_PRODUCTION_METADATA_MUST_STAY_ON_DISPOSABLE_WINDOWS_CI")
d,m,web=load(qa);_,ref,_=load(old)
if m["for_install"] or m["production_pass"] or m["signature"] is not None or m["install_plan"]!=EXPECTED_PLAN:
 raise RuntimeError("INPUT_MUST_BE_CORRECT_NONINSTALLABLE_QA_WITH_COMPLETE_INSTALL_CONTRACT")
if sha(d)!="4f98e7eb948e78c49b09f4f41299ec7b4c68b562cfac8dbe72e0c827a69ef613":
 raise RuntimeError("SOURCE_MUST_MATCH_PINNED_UNIVERSAL_44_QA_ARTIFACT")
if ref["qualification"]["failure_matrix_cases_required"]<80 or len(m["supported_baselines"])!=44:
 raise RuntimeError("ORIGINAL_QA_TEMPLATE_INVALID")
m["for_install"]=True;m["production_pass"]=True
m["status"]="R153_DISPOSABLE_CI_SYNTHETIC_WINDOWS_FIXTURE"
m["qualification"]=ref["qualification"]
m["qa_notes"]["synthetic"]=("FOR_DISPOSABLE_WINDOWS_TEST_ONLY; NOT REAL PRODUCTION APPROVAL; "
  "NO LIVE HOST BACKUP WAS TESTED; NEVER PUBLISH THIS MANIFEST")
m["qa_notes"]["all_requests_complete"]="UNVERIFIED"
root.mkdir(parents=True,exist_ok=True)
out=root/"r153-synthetic-qualified.zip"
out.write_bytes(deterministic_archive({
 "release-set.json":json.dumps(m,sort_keys=True,ensure_ascii=False,separators=(",",":")).encode(),
 "artifacts/local/web.zip":web
}))
with zipfile.ZipFile(out) as z:
 mm=json.loads(z.read("release-set.json"))
 if mm["status"]!="R153_DISPOSABLE_CI_SYNTHETIC_WINDOWS_FIXTURE" or sha(z.read("artifacts/local/web.zip"))!=sha(web):
  raise RuntimeError("SYNTHETIC_WINDOWS_FIXTURE_REPACK_FAILED")
print("PASS R153 DISPOSABLE WINDOWS INSTALL/ROLLBACK FIXTURE",sha(out.read_bytes()),flush=True)
