import json,pathlib,tempfile,unittest,zipfile,sys
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[1]))
from universal_release import decision,digest,KINDS,GATES,INSTALL_ONLY
class UniversalPolicyTests(unittest.TestCase):
    def test_no_host_proof_is_distribution_gate(self):
        self.assertIn("field_business_acceptance_after_install", INSTALL_ONLY)
        self.assertTrue(all("host_support" not in v and "field_acceptance" not in v for v in GATES.values()))
    def test_missing_and_wrong_bytes_fail_without_requesting_support(self):
        with tempfile.TemporaryDirectory() as t:
            p=pathlib.Path(t)
            c={"schema":"sokna-universal-distribution-contract-v1","release":"test","source_sha256":"a"*64,"packages":{k:{"file":k+".zip","sha256":"b"*64} for k in KINDS}}
            (p/"local-update.zip").write_bytes(b"wrong")
            d=decision(c,p,{})
            self.assertFalse(d["distribution_authorized"])
            self.assertFalse(d["target_host_evidence_required_for_distribution"])
            self.assertFalse(d["support_bundle_required_for_distribution"])
            self.assertIn("local-update:EXACT_SHA_MISMATCH",d["errors"])
    def test_valid_clean_proof_must_bind_exact_zip(self):
        with tempfile.TemporaryDirectory() as t:
            p=pathlib.Path(t)
            for k in ("local-clean","public-clean"):
                with zipfile.ZipFile(p/(k+".zip"),"w") as z:
                    for n in ["VERSION.txt","bootstrap.php","emergency.php","setup/index.php","setup/api.php"]: z.writestr(n,"fixture")
            c={"schema":"sokna-universal-distribution-contract-v1","release":"test","source_sha256":"a"*64,"packages":{k:{"file":k+".zip","sha256":digest((p/(k+".zip")).read_bytes()) if (p/(k+".zip")).exists() else "b"*64} for k in KINDS}}
            c["packages"]["local-clean"]["proofs"]=[{"gate":g,"scope":"exact_zip","sha256":c["packages"]["local-clean"]["sha256"],"run_id":1,"job":"test"} for g in GATES["local-clean"]]
            d=decision(c,p,{1:{"test"}})
            self.assertTrue(all(v=="PASS" for v in d["packages"]["local-clean"]["gates"].values()))
            c["packages"]["local-clean"]["proofs"][0]["scope"]="payload_only"
            d=decision(c,p,{1:{"test"}})
            self.assertEqual(d["packages"]["local-clean"]["gates"]["source"],"MISSING_EXACT_PROOF")
if __name__=="__main__":unittest.main()
