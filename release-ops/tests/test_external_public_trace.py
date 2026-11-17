"""R265/R266 producer trace fails closed without claiming unified factory deployment."""
from __future__ import annotations
import copy
import json
import pathlib
import sys
import tempfile
import unittest

sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[1]))
from trace_external_public_candidate import trace


class PublicExternalProducerTraceTest(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.path=pathlib.Path(self.tmp.name)
        self.src=pathlib.Path(__file__).resolve().parents[1]/"external-public-r268-provenance.json"
        self.data=json.loads(self.src.read_text())
        self.contract=self.path/"contract.json"
        self.write(self.data)

    def tearDown(self):
        self.tmp.cleanup()

    def write(self,value):
        self.contract.write_text(json.dumps(value),encoding="utf-8")

    def test_historical_contract_does_not_claim_unified_factory(self):
        r=trace(self.contract,self.path)
        self.assertFalse(r["factory_used"])
        self.assertIsNone(r["factory_run_id"])
        self.assertFalse(r["release_authorized"])
        self.assertEqual(r["producer_run_id"],44025587468)
        self.assertEqual(r["report_status"],"FAIL")
        self.assertTrue(r["errors"]) # Exact ZIPs must actually be supplied.

    def test_fake_factory_claim_is_rejected(self):
        data=copy.deepcopy(self.data)
        data["factory"]["invoked_by_producer"]=True
        self.write(data)
        r=trace(self.contract,self.path)
        self.assertEqual(r["report_status"],"FAIL")
        self.assertTrue(any("FALSE_UNIFIED_FACTORY_USAGE" in x for x in r["errors"]))

    def test_fake_real_host_acceptance_is_rejected(self):
        data=copy.deepcopy(self.data)
        data["field_acceptance"]["actual_host_activation"]="PASS"
        self.write(data)
        r=trace(self.contract,self.path)
        self.assertEqual(r["report_status"],"FAIL")
        self.assertTrue(any("OPERATOR_AUTHORIZATION_NOT_SUPPORTED" in x for x in r["errors"]))

    def test_wrong_source_contract_is_rejected(self):
        data=copy.deepcopy(self.data)
        data["origin_type"]="UNIFIED_FACTORY"
        self.write(data)
        r=trace(self.contract,self.path)
        self.assertTrue(any("INVALID_EXTERNAL_PRODUCER_CONTRACT" in x for x in r["errors"]))


if __name__=="__main__":
    unittest.main(verbosity=2)
