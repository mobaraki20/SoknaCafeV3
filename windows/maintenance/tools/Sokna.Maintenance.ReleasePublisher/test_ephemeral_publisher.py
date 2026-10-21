#!/usr/bin/env python3
"""Disposable signing interoperability fixture. Never publish this test release."""
import base64,hashlib,io,json,os,pathlib,subprocess,sys,tempfile,zipfile
from cryptography.hazmat.primitives import hashes,serialization
from cryptography.hazmat.primitives.asymmetric import ec,utils

ROOT=pathlib.Path(__file__).resolve().parents[4]
PROJECT=ROOT/"windows/maintenance/tools/Sokna.Maintenance.ReleasePublisher/Sokna.Maintenance.ReleasePublisher.csproj"
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
                  "supported_baselines":[{"local":dict(identity,release_id="ci-source",version="3.0.0.ci-test-source"),"public":None}],
                  "targets":{"local":dict(identity,managed_files=len(files))},
                  "artifacts":{"local-web":{"path":"artifacts/local/web.zip","sha256":sha(payload),"size_bytes":len(payload),
                                             "component":"local-web","kind":"web-tree-zip-v1"}},
                  "signature":{"algorithm":"ecdsa-p256-sha256","key_id":"ci-ephemeral-"+sha(spki)[:20],"value":""}}
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
        out.unlink()
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
        print("5/5 source-only synthetic checks passed; real-host UAT NOT RUN")
if __name__=="__main__":main()
