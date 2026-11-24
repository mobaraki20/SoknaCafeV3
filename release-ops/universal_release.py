#!/usr/bin/env python3
"""Universal distribution admission; distinct from per-installation acceptance.

Only the distribution layer is touched. No target host, support bundle, live
database, installer, Maintenance, or Emergency engine is contacted or changed.
The release decision never promotes or rewrites a QA ZIP. It copies a frozen
operator-format ZIP only after proof for that exact SHA and declared origins.
"""
from __future__ import annotations
import argparse, hashlib, io, json, os, pathlib, re, shutil, urllib.request, zipfile

HEX = re.compile(r"^[0-9a-f]{64}$")
KINDS = ("local-update", "public-update", "local-clean", "public-clean")
GATES = {
    "local-update": ("source", "structure", "native_windows", "db_forward", "backup_restore", "failure_recovery", "exact_operator_zip"),
    "public-update": ("source", "structure", "routine", "emergency", "failure_recovery", "exact_operator_zip"),
    "local-clean": ("source", "structure", "browser_setup", "db_seed"),
    "public-clean": ("source", "structure", "root_setup", "subpath_setup", "protected_emergency"),
}
INSTALL_ONLY = (
    "detect_installed_identity_and_engine_capabilities",
    "validate_compatible_origin_and_package_integrity",
    "verify_or_create_recoverable_backup_and_recovery_snapshot",
    "drain_or_queue_mutations_before_activation",
    "enforce_forward_migration_boundary_and_recovery_policy",
    "activate_readback_or_recover",
    "field_business_acceptance_after_install",
)
def digest(b): return hashlib.sha256(b).hexdigest()
def require(ok, reason):
    if not ok: raise ValueError(reason)
def load(p): return json.loads(p.read_text(encoding="utf-8"))
def safe_name(n): return isinstance(n,str) and bool(re.fullmatch(r"[A-Za-z0-9_.-]+\.zip",n))
def origins(m, kind):
    if kind == "local-update":
        return {r["local"]["managed_tree_sha256"] for r in m["supported_baselines"]}
    if kind == "public-update":
        return {r["managed_tree_sha256"] for r in m["compatibility"]["supported_baselines"]}
    return set()
def inspect_package(path, kind):
    with zipfile.ZipFile(path) as z:
        ns=z.namelist()
        require(len(ns)==len(set(ns)) and z.testzip() is None, "ZIP_INTEGRITY")
        require(all(not n.startswith("/") and "\\" not in n and ".." not in n.split("/") for n in ns), "UNSAFE_ZIP_PATH")
        if kind=="local-update":
            require(set(ns)=={"release-set.json","artifacts/local/web.zip"},"LOCAL_FORMAT")
            m=json.loads(z.read("release-set.json")); web=z.read("artifacts/local/web.zip")
            require(m["format"]=="sokna-maintenance-release-set-v2.1","LOCAL_MANIFEST")
            require(m["artifacts"]["local-web"]["sha256"]==digest(web),"LOCAL_INNER_SHA")
            require(m["pre_update_backup_policy"]["required"] is True and m["pre_update_backup_policy"]["system_recovery_snapshot_required"] is True and m["pre_update_backup_policy"]["on_failure"]=="reject_pre_mutation","UNSAFE_BACKUP")
            require(m["database"]["local"]["rollback_after_boundary"]=="forbidden","UNSAFE_DB_ROLLBACK")
            with zipfile.ZipFile(io.BytesIO(web)) as w:
                names=w.namelist(); require(len(names)==len(set(names)) and w.testzip() is None,"LOCAL_WEB_CRC")
                tree=digest("".join(n+"\0"+digest(w.read(n))+"\n" for n in sorted(names)).encode())
                require(tree==m["targets"]["local"]["managed_tree_sha256"],"LOCAL_TREE")
                require(len(names)==m["targets"]["local"]["managed_files"],"LOCAL_FILE_COUNT")
            q=m["qualification"]
            ready=(m["for_install"] is True and m["production_pass"] is True and all(q.get(k) is True for k in ("canary","real_windows","real_mariadb","recovery_console_web_down","business_backup_consistency_real_mariadb")) and not re.search(r"QA|PREFLIGHT|NOT.?INSTALL|TEST.?ONLY|SYNTHETIC",m["status"],re.I))
            return ready,origins(m,kind),m["targets"]["local"]["version"]
        if kind=="public-update":
            m=json.loads(z.read("manifest.json")); require(m["format"]=="sokna-component-package-v1","PUBLIC_MANIFEST")
            rows=m["files"]; names={x["path"] for x in rows}
            require(len(names)==len(rows) and set(ns)=={"manifest.json"}|{"payload/"+n for n in names},"PUBLIC_FILE_SET")
            require(all(digest(z.read("payload/"+x["path"]))==x["sha256"] and len(z.read("payload/"+x["path"]))==x["size"] for x in rows),"PUBLIC_FILE_HASH")
            require(not any(n=="emergency.php" or n.startswith("src/Emergency/") for n in names),"PROTECTED_EMERGENCY_MUTATION")
            require(digest("".join(n+"\0"+digest(z.read("payload/"+n))+"\n" for n in sorted(names)).encode())==m["target_managed_tree_sha256"],"PUBLIC_TREE")
            p=m.get("provenance",{})
            ready=p.get("installation_authorized") is True and not re.search(r"QA|PREFLIGHT|NOT.?INSTALL",p.get("release_qualification",""),re.I)
            return ready,origins(m,kind),m["version"]
        require("VERSION.txt" in ns and "bootstrap.php" in ns,"CLEAN_WEB_LAYOUT")
        require(not any(n in ns for n in ("config.php","install.lock",".env")),"PRIVATE_CONFIG_IN_CLEAN")
        require(not any(n.startswith("storage/") for n in ns),"RUNTIME_DATA_IN_CLEAN")
        if kind=="public-clean":
            require("emergency.php" in ns and "setup/index.php" in ns and "setup/api.php" in ns,"PUBLIC_CLEAN_EMERGENCY_OR_SETUP_MISSING")
        # The clean web ZIP has no update authorization flags. Exact clean
        # SHA, browser setup, migration and protected-source proofs govern it.
        return True,set(),z.read("VERSION.txt").decode().strip()

def decision(contract, directory, verified_runs):
    """verified_runs comes from GitHub API, not target-machine uploads."""
    errors=[]; details={}
    require(contract.get("schema")=="sokna-universal-distribution-contract-v1","CONTRACT_SCHEMA")
    require(HEX.fullmatch(contract.get("source_sha256","")),"SOURCE_SHA")
    packages=contract["packages"]
    require(set(packages)==set(KINDS),"FOUR_PACKAGE_KINDS_REQUIRED")
    for kind in KINDS:
        row=packages[kind]; p=directory/row["file"]
        require(safe_name(row["file"]) and HEX.fullmatch(row["sha256"]),"PACKAGE_NAME_OR_SHA")
        d={"sha256":row["sha256"],"gates":{},"declared_origins":[],"qualified_origins":[]}
        details[kind]=d
        if not p.is_file():
            errors.append(kind+":EXACT_ZIP_NOT_PRESENT")
            continue
        if digest(p.read_bytes())!=row["sha256"]:
            errors.append(kind+":EXACT_SHA_MISMATCH")
            continue
        try:
            ready,declared,version=inspect_package(p,kind)
            d["version"]=version
            d["declared_origins"]=sorted(declared)
            d["operator_format"]=ready
            if not ready: errors.append(kind+":OPERATOR_FORMAT_NOT_ISSUED")
        except (ValueError,KeyError,zipfile.BadZipFile) as exc:
            errors.append(kind+":INVALID_PACKAGE:"+str(exc));continue
        # A proof can only cover the exact package SHA it names, and must
        # originate from a completed successful, pinned GitHub workflow/job.
        # Payload-only rehearsals explicitly cannot count as exact ZIP tests.
        qualified=set()
        for gate in GATES[kind]:
            matches=[v for v in row.get("proofs",[]) if v["gate"]==gate and v.get("sha256")==row["sha256"] and v.get("scope")=="exact_zip" and v.get("run_id") in verified_runs and v.get("job") in verified_runs[v["run_id"]]]
            d["gates"][gate]="PASS" if matches else "MISSING_EXACT_PROOF"
            if not matches: errors.append(kind+":"+gate.upper()+"_MISSING")
        for v in row.get("proofs",[]):
            if v.get("gate")=="origin_upgrade" and v.get("scope")=="exact_zip" and v.get("sha256")==row["sha256"] and v.get("run_id") in verified_runs and v.get("job") in verified_runs[v["run_id"]]:
                qualified.add(v.get("origin_tree_sha256"))
        d["qualified_origins"]=sorted(qualified & declared)
        if declared-qualified: errors.append(kind+":UNQUALIFIED_DECLARED_ORIGINS:"+str(len(declared-qualified)))
        if kind.endswith("-update") and not declared: errors.append(kind+":NO_SUPPORTED_ORIGINS")
    return {"schema":"sokna-universal-release-decision-v1","release":contract["release"],"source_sha256":contract["source_sha256"],
            "release_status":"DISTRIBUTION_READY" if not errors else "BLOCKED_PACKAGE_QUALIFICATION",
            "distribution_authorized":not errors,"operator_package_created":False,
            "deployment_authorized":False,"field_acceptance":"DEFERRED_TO_EACH_INSTALLATION",
            "target_host_evidence_required_for_distribution":False,"support_bundle_required_for_distribution":False,
            "installation_only_checks":list(INSTALL_ONLY),"packages":details,"errors":errors}

def verify_runs(contract, repo, token):
    require(bool(re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+",repo)),"REPO")
    def get(path):
        req=urllib.request.Request("https://api.github.com/repos/"+repo+path,headers={"Authorization":"Bearer "+token,"Accept":"application/vnd.github+json","X-GitHub-Api-Version":"2022-11-28"})
        with urllib.request.urlopen(req,timeout=30) as r: return json.load(r)
    verified={}
    for row in contract.get("runs",[]):
        run=int(row["id"]); d=get("/actions/runs/"+str(run))
        require(d["head_sha"]==row["head_sha"] and d["path"]==row["workflow"],"WORKFLOW_IDENTITY_MISMATCH")
        require(d["status"]=="completed" and d["conclusion"]=="success","WORKFLOW_NOT_SUCCESS")
        jobs=get("/actions/runs/"+str(run)+"/jobs?per_page=100")["jobs"]
        found={j["name"] for j in jobs if j["status"]=="completed" and j["conclusion"]=="success"}
        require(set(row["jobs"])<=found,"REQUIRED_JOB_NOT_SUCCESS")
        verified[run]=set(row["jobs"])
    return verified

def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument("--contract",type=pathlib.Path,required=True)
    p.add_argument("--packages",type=pathlib.Path,required=True)
    p.add_argument("--out",type=pathlib.Path,required=True)
    p.add_argument("--repo",required=True)
    a=p.parse_args(); c=load(a.contract)
    a.out.mkdir(parents=True,exist_ok=True)
    d=decision(c,a.packages,verify_runs(c,a.repo,os.environ["GH_TOKEN"]))
    if d["distribution_authorized"]:
        target=a.out/"operator"
        target.mkdir(exist_ok=False)
        for kind,row in c["packages"].items():
            src=a.packages/row["file"]; dst=target/row["file"]
            shutil.copyfile(src,dst)
            require(digest(dst.read_bytes())==row["sha256"],"OUTPUT_SHA_DRIFT")
        d["operator_package_created"]=True
        (target/"SHA256SUMS.txt").write_text("".join(row["sha256"]+"  "+row["file"]+"\n" for row in c["packages"].values()),encoding="utf-8")
    (a.out/"release-decision.json").write_text(json.dumps(d,ensure_ascii=False,indent=2)+"\n",encoding="utf-8")
    print(json.dumps(d,ensure_ascii=False,indent=2))
    # A correctly rejected QA candidate is a successful policy evaluation,
    # but is never a successful distribution. Only the authorized output
    # directory may be uploaded by the factory as an operator artifact.
    with open(os.environ["GITHUB_OUTPUT"],"a") as f:
        f.write("authorized="+str(d["distribution_authorized"]).lower()+"\n")

if __name__=="__main__":main()
