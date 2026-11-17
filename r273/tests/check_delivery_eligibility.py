#!/usr/bin/env python3
"""R274 QA-specific fail-closed operator-delivery guard; does not change package bytes.

The native Maintenance structural test may require 'for_install' for staging in CI,
but a structurally installable QA archive is NOT a production authorization.
"""
import sys, json, zipfile, hashlib, pathlib

def main():
    if len(sys.argv)!=3:raise SystemExit("usage: check_delivery_eligibility.py local.zip public.zip")
    lp,pp=map(pathlib.Path,sys.argv[1:])
    with zipfile.ZipFile(lp) as z: local=json.loads(z.read("release-set.json"))
    with zipfile.ZipFile(pp) as z: public=json.loads(z.read("manifest.json"))
    if hashlib.sha256(lp.read_bytes()).hexdigest()!="9038abd4757ed1ed3dc3ab92e54f9266096fe73d5919d7667a1f195540991ec4":
        raise SystemExit("UNPINNED_LOCAL_PACKAGE")
    if hashlib.sha256(pp.read_bytes()).hexdigest()!="68725567e7336e0b43691ff4adae8b494b4a9c793a50a30d4564a2dab11daa92":
        raise SystemExit("UNPINNED_PUBLIC_PACKAGE")
    qualification=local.get("qualification") or {}
    provenance=public.get("provenance") or {}
    reasons=[]
    if local.get("status")!="PRODUCTION_HOST_QUALIFIED":reasons.append("local_release_status_is_not_production_host_qualified")
    if not local.get("signature"):reasons.append("local_release_signature_missing")
    if qualification.get("real_public_host") is not True:reasons.append("real_public_host_not_qualified")
    if qualification.get("remote_vault_real_host") is not True:reasons.append("real_host_remote_backup_not_verified")
    if qualification.get("clean_machine_remote_restore") is not True:reasons.append("clean_machine_restore_not_verified")
    if provenance.get("offline_qualified") is not True:reasons.append("public_offline_qualification_false")
    if local.get("for_install") is True and local.get("production_pass") is True and reasons:
        reasons.append("DANGER_local_structural_install_flags_contradict_field_NO_GO")
    output={
       "format":"sokna-r273-qa-operator-delivery-guard-v1",
       "local_sha256":hashlib.sha256(lp.read_bytes()).hexdigest(),
       "public_sha256":hashlib.sha256(pp.read_bytes()).hexdigest(),
       "operator_install_allowed":not bool(reasons),
       "blocking_reasons":reasons,
       "not_a_production_release_factory_certificate":True,
    }
    out=lp.parent/"R273-OPERATOR-DELIVERY-FAIL-CLOSED.json"
    out.write_text(json.dumps(output,indent=2,ensure_ascii=False)+"\n")
    print(json.dumps(output,indent=2))
    if output["operator_install_allowed"]:raise SystemExit("UNEXPECTED_QA_PACKAGE_PRODUCTION_ELIGIBILITY")
    print("R273_QA_OPERATOR_DELIVERY_BLOCKED_CORRECTLY_PASS")
    return 0
if __name__=="__main__":sys.exit(main())
