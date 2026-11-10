#!/usr/bin/env python3
"""Regression test for ANY Local install ZIP: source tests cannot substitute for final release gate."""
import sys, pathlib, tempfile, zipfile, io, json, importlib.util
if len(sys.argv)!=4:raise SystemExit("usage: test_final_release_gate.py original-R153-QA.zip fixed-R153-QA.zip R149B-qualified.zip")
path=pathlib.Path("packaging/windows/release-gates/check_local_release_set.py").resolve()
spec=importlib.util.spec_from_file_location("sokna_release_gate",path)
g=importlib.util.module_from_spec(spec);spec.loader.exec_module(g)
original,corrected,prior=sys.argv[1:]
def need(condition,label):
    if not condition:raise RuntimeError("PACKAGE_GATE_REGRESSION_"+label)
    print("PASS "+label)
def reject(p,installable,expected):
    try:g.audit(p,ready=installable)
    except ValueError as e:
        need(expected in str(e),"REJECT_"+expected);return
    raise RuntimeError("FALSELY_ACCEPTED_"+expected)
g.audit(corrected)
reject(original,False,"RELEASE_SET_INVALID_INSTALL_PLAN")
reject(corrected,True,"RELEASE_SET_QA_NOT_INSTALLABLE")
g.audit(prior,ready=True)
with zipfile.ZipFile(corrected) as z:
    manifest=json.loads(z.read("release-set.json"))
    web=z.read("artifacts/local/web.zip")
with tempfile.TemporaryDirectory(prefix="sokna-gate-test-") as temp:
    f=pathlib.Path(temp)/"malformed.zip"
    def trial(m,code):
        with zipfile.ZipFile(f,"w") as z:
            z.writestr("release-set.json",json.dumps(m,ensure_ascii=False))
            z.writestr("artifacts/local/web.zip",web)
        reject(f,False,code)
    a=json.loads(json.dumps(manifest));a["install_plan"][0]["mutation"]=True
    trial(a,"RELEASE_SET_INVALID_INSTALL_PLAN")
    a=json.loads(json.dumps(manifest));a["supported_baselines"].append(a["supported_baselines"][0])
    trial(a,"RELEASE_SET_DUPLICATE_BASELINE")
    a=json.loads(json.dumps(manifest));a["pre_update_backup_policy"]["required"]=False
    trial(a,"RELEASE_SET_INSECURE_BACKUP_POLICY")
    a=json.loads(json.dumps(manifest));a["artifacts"]["local-web"]["sha256"]="a"*64
    trial(a,"RELEASE_SET_ARTIFACT_TAMPER")
    a=json.loads(json.dumps(manifest));a["for_install"]=True;a["production_pass"]=True
    with zipfile.ZipFile(f,"w") as z:
        z.writestr("release-set.json",json.dumps(a,ensure_ascii=False))
        z.writestr("artifacts/local/web.zip",web)
    reject(f,True,"RELEASE_SET_PRODUCTION_STATUS_UNSAFE")
print("PASS UNIVERSAL RELEASE FINAL ZIP STRUCTURAL AND PRODUCTION GATE NEGATIVE MATRIX")
