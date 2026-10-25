#!/usr/bin/env python3
"""Disposable signing interoperability fixture. Never publish this test release."""
import base64,hashlib,io,json,os,pathlib,subprocess,sys,tempfile,zipfile,datetime
from cryptography.hazmat.primitives import hashes,serialization
from cryptography.hazmat.primitives.asymmetric import ec,utils

ROOT=pathlib.Path(__file__).resolve().parents[4]
PROJECT=ROOT/"windows/maintenance/tools/Sokna.Maintenance.ReleasePublisher/Sokna.Maintenance.ReleasePublisher.csproj"
HOST_HARNESS=ROOT/"windows/maintenance/tools/Sokna.Maintenance.HostPortabilityHarness/Sokna.Maintenance.HostPortabilityHarness.csproj"
sha=lambda b:hashlib.sha256(b).hexdigest()
def invoke(man,web,proof,out,pem):
    env=dict(os.environ)
    env.pop("SOKNA_V4_PUBLISHER_P256_PEM",None)
    if pem is not None: env["SOKNA_V4_PUBLISHER_P256_PEM"]=pem.decode()
    return subprocess.run(["dotnet","run","--no-build","--project",str(PROJECT),"--",
        str(man),str(web),str(proof),str(out)],capture_output=True,text=True,env=env)
def main():
    with tempfile.TemporaryDirectory() as folder:
        tmp=pathlib.Path(folder)
        files={"VERSION.txt":b"3.0.0.ci-test-target\n","database/migrations/001.sql":b"-- synthetic\n"}
        tree=sha(b"".join((p+"\0"+sha(files[p])+"\n").encode() for p in sorted(files)))
        web=tmp/"web.zip"
        with zipfile.ZipFile(web,"w") as z:
            for k,v in files.items():z.writestr(k,v)
        payload=web.read_bytes()
        signer=ec.generate_private_key(ec.SECP256R1())
        pem=signer.private_bytes(serialization.Encoding.PEM,serialization.PrivateFormat.PKCS8,serialization.NoEncryption())
        spki=signer.public_key().public_bytes(serialization.Encoding.DER,serialization.PublicFormat.SubjectPublicKeyInfo)
        identity={"release_id":"ci-target","version":"3.0.0.ci-test-target","managed_tree_sha256":tree,
                  "schema_fingerprint":"ci-synthetic","migration_head":"001","authority_epoch_policy":"preserved"}
        manifest={"format":"sokna-maintenance-release-set-v2.1","schema_version":"2.1","release_type":"local_only",
                  "release_set_id":"ci-ephemeral","sequence":1,"status":"CI_SYNTHETIC_QUALIFIED",
                  "for_install":True,"production_pass":True,
                  "qualification":{"failure_matrix_id":"CI-FAKE-FIXTURE","failure_matrix_cases_required":80,
                      "real_windows":True,"real_mariadb":True,"canary":True,"recovery_console_web_down":True,
                      "business_backup_consistency_real_mariadb":True},
                  "supported_baselines":[
                      {"local":dict(identity,release_id="ci-source-channel-a",version="3.0.0.ci-test-source"),"public":None},
                      {"local":dict(identity,release_id="ci-source-channel-b",version="3.0.0.ci-test-source"),"public":None}],
                  "targets":{"local":dict(identity,managed_files=len(files))},
                  "artifacts":{"local-web":{"path":"artifacts/local/web.zip","sha256":sha(payload),"size_bytes":len(payload),
                                             "component":"local-web","kind":"web-tree-zip-v1"}},
                  "signature":{"algorithm":"ecdsa-p256-sha256","key_id":"ci-ephemeral-"+sha(spki)[:20],"value":""}}
        now=datetime.datetime.now(datetime.timezone.utc)
        manifest.update({
            "created_at":now.isoformat().replace("+00:00","Z"),
            "expires_at":(now+datetime.timedelta(days=7)).isoformat().replace("+00:00","Z"),
            "requirements":{"maintenance_supervisor_min":"0.3.10","update_engine_min":"0.3.10",
                            "backup_recovery_engine_min":None,"public_maintenance_kernel_min":None},
            "dependency_graph":[],
            "install_plan":[{"phase":"preflight","component":"local-web","mutation":False}],
            "compatibility":{"allowed_intermediate_pairs":[],"max_stagger_seconds":0,
                             "client_build_window":{},"protocol_window":{}},
            "mutation_policy":{"guest":"queue","staff_public":"queue","local":"drain_then_block"},
            "database":{"local":{"migration_dag":[],"forward_boundary_phase":"db_forward_boundary",
                                  "rollback_after_boundary":"forbidden"},
                        "public":{"migration_dag":[],"forward_boundary_phase":"db_forward_boundary",
                                  "rollback_after_boundary":"forbidden"}},
            "recovery":{"pre_db":"rollback_or_discard_allowed","post_db":"retain_target_updater_forward_recovery_only",
                        "disaster_restore":"authority_epoch_reenrollment_required",
                        "business_backup_restore":"clean_target_only","machine_identity":"fresh_reprovision_required",
                        "public_reenrollment":"credential_rotation_and_authority_epoch_required"},
            "pre_update_backup_policy":{"required":True,"recent_local_verified_max_age_seconds":86400,
                                        "require_remote_verified_before_db_boundary":False,
                                        "system_recovery_snapshot_required":True,
                                        "on_failure":"reject_pre_mutation"}
        })
        receipt={"release_set_id":"ci-ephemeral","artifact_sha256":sha(payload),"result":"QUALIFIED",
                 "failure_matrix_id":"CI-FAKE-FIXTURE","real_windows_uat_sha256":"1"*64,
                 "mariadb_backup_restore_sha256":"2"*64,"negative_matrix_sha256":"3"*64}
        mf=tmp/"manifest.json";rf=tmp/"receipt.json";out=tmp/"signed.zip"
        def save():mf.write_text(json.dumps(manifest));rf.write_text(json.dumps(receipt))
        save()
        yes=invoke(mf,web,rf,out,pem)
        assert yes.returncode==0 and out.exists(),yes.stdout+"\n"+yes.stderr
        with zipfile.ZipFile(out) as z:
            assert sorted(z.namelist())==["artifacts/local/web.zip","release-set.json"]
            assert z.read("artifacts/local/web.zip")==payload
            signed=json.loads(z.read("release-set.json"))
        import base64
        sig=base64.b64decode(signed["signature"]["value"])
        assert len(sig)==64
        # ASCII fixture canonicalization matches existing C# Core and is cross-checked.
        def canonical(o):
            return json.dumps(o,sort_keys=True,ensure_ascii=True,separators=(",",":"))
        signed["signature"]["value"]=""
        signer.public_key().verify(utils.encode_dss_signature(int.from_bytes(sig[:32],"big"),int.from_bytes(sig[32:],"big")),
            canonical(signed).encode("ascii"),ec.ECDSA(hashes.SHA256()))
        print("POSITIVE_EPHEMERAL_SIGN_AND_CSHARP_CANONICAL_READBACK_PASS")
        public_file=tmp/"publisher.spki.pem"
        public_file.write_bytes(signer.public_key().public_bytes(
            serialization.Encoding.PEM,serialization.PublicFormat.SubjectPublicKeyInfo))
        portability=subprocess.run(["dotnet","run","--no-build","--project",
            str(HOST_HARNESS),"--",str(out),str(public_file)],
            capture_output=True,text=True)
        assert portability.returncode==0,portability.stdout+"\\n"+portability.stderr
        assert "INDEPENDENT_HOST_BASELINE_0_REPLICA_0_PASS" in portability.stdout
        assert "INDEPENDENT_HOST_BASELINE_1_REPLICA_1_PASS" in portability.stdout
        assert "INCOMPATIBLE_HOST_FAIL_CLOSED_PASS" in portability.stdout
        assert "NO_PREPROVISIONED_TRUST_FAIL_CLOSED_PASS" in portability.stdout
        print("POSITIVE_SAME_ZIP_INDEPENDENT_HOSTS_AND_NEGATIVE_HOST_GATES_PASS")
        out.unlink()
        manifest["supported_baselines"].append(manifest["supported_baselines"][0].copy())
        save()
        duplicate=invoke(mf,web,rf,out,pem)
        assert duplicate.returncode!=0 and not out.exists()
        print("NEGATIVE_DUPLICATE_BASELINE_PASS")
        manifest["supported_baselines"].pop()
        missing=invoke(mf,web,rf,out,None)
        assert missing.returncode!=0 and not out.exists()
        print("NEGATIVE_MISSING_PROTECTED_CREDENTIAL_PASS")
        manifest["supported_baselines"][0]["local"]["schema_fingerprint"]="__READ_TRUSTED_CAFE_IDENTITY__";save()
        invalid=invoke(mf,web,rf,out,pem)
        assert invalid.returncode!=0 and not out.exists()
        print("NEGATIVE_UNRESOLVED_BASELINE_PASS")
        manifest["supported_baselines"][0]["local"]["schema_fingerprint"]="ci-synthetic"
        manifest["production_pass"]=False;save()
        invalid=invoke(mf,web,rf,out,pem)
        assert invalid.returncode!=0 and not out.exists()
        print("NEGATIVE_UNQUALIFIED_RELEASE_PASS")
        manifest["production_pass"]=True
        receipt["artifact_sha256"]="0"*64;save()
        invalid=invoke(mf,web,rf,out,pem)
        assert invalid.returncode!=0 and not out.exists()
        print("NEGATIVE_UNBOUND_RECEIPT_PASS")
        print("7/7 synthetic release/signature/portability checks passed; live Windows UAT NOT RUN")
if __name__=="__main__":main()
