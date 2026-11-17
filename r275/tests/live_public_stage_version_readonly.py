#!/usr/bin/env python3
"""Read-only exact R275 Public artifact vs live Public health. No POST."""
import json,os,urllib.request,pathlib,hashlib,zipfile,re,datetime
base="https://www.soknagroup.ir/public"
def get_health():
    req=urllib.request.Request(base+"/health",headers={"Accept":"application/json","User-Agent":"Sokna-R275-ReadOnly-Stage-RootCause/1"})
    with urllib.request.urlopen(req,timeout=12) as r:
        if r.status!=200 or r.geturl()!=base+"/health":raise RuntimeError("bad response")
        return json.loads(r.read(80000))
folder=pathlib.Path(os.environ["PACKAGE_DIR"])
zips=list(folder.rglob("SOKNA-R275-PUBLIC-35IJ-OWNER-DISCARD-CODE-ONLY-QA-DO-NOT-INSTALL.zip"))
if len(zips)!=1:raise RuntimeError("archive count not one")
zp=zips[0];digest=hashlib.sha256(zp.read_bytes()).hexdigest()
assert digest=="63213185fcc037b620ea0dccf133d5c63672f01da7db7d3545eb54e617dd150f"
with zipfile.ZipFile(zp) as z:manifest=json.loads(z.read("manifest.json"))
h=get_health()
def rank(s):
  m=re.search(r"(?:^|-)workspace\.([0-9]{1,8})([a-z]{1,5})(?:-|$)",s)
  if not m: return None
  n=0
  for c in m[2]: n=n*26+(ord(c)-96)
  return [int(m[1]),n]
src=h.get("version")
source_pinned=manifest["compatibility"]["supported_baselines"][0]["version"]
out={"format":"sokna-r275-readonly-stage-version-evidence-v1",
     "checked_utc":datetime.datetime.now(datetime.timezone.utc).isoformat(),
     "http_read_only":True,"public_health_ok":h.get("ok"),
     "installed_version_live_health":src,
     "candidate_version_zip":manifest["version"],
     "candidate_sha256":digest,
     "candidate_rank":rank(manifest["version"]),
     "installed_rank":rank(src or ""),
     "candidate_newer_than_live_health":(rank(manifest["version"])>rank(src or "")) if rank(src or "") else None,
     "live_version_matches_pinned_baseline":src==source_pinned,
     "live_db_ready":h.get("database")=="ready",
     "live_applied_migrations":h.get("applied_migrations"),
     "diagnosis_limitation":"Cannot see which actual ZIP the operator uploaded nor Emergency POST-time currentVersion; no host modification",
     "operator_install_allowed":False}
print(json.dumps(out,ensure_ascii=False,indent=2))
pathlib.Path("R275-LIVE-STAGE-VERSION-READONLY-RESULT.json").write_text(json.dumps(out,indent=2))
if out["candidate_newer_than_live_health"] is not True or not out["live_version_matches_pinned_baseline"]:
 raise SystemExit("LIVE_PUBLIC_TARGET_OR_BASELINE_MISMATCH")
