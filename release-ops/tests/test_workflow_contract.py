"""Publication workflow must be reusable and NEVER silently skip a called candidate."""
import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github/workflows/sokna-release-assurance.yml"


class WorkflowContract(unittest.TestCase):
    def setUp(self):
        self.data = WORKFLOW.read_text(encoding="utf-8")

    def test_not_eventname_gated_for_workflow_call(self):
        self.assertIn("if: ${{ inputs.artifact_name != '' && inputs.expected_sha256 != '' }}", self.data)
        self.assertNotIn("github.event_name == 'workflow_call'", self.data)

    def test_source_and_candidate_both_pinned(self):
        self.assertIn("source_artifact_name:", self.data)
        self.assertIn("source_checkpoint:", self.data)
        self.assertIn("source-register.json", self.data)
        self.assertIn("--expected-sha256", self.data)
        self.assertIn("--require-output-ready", self.data)

    def test_no_release_deploy_or_prod_secrets(self):
        self.assertIn("contents: read", self.data)
        self.assertIn("actions: read", self.data)
        self.assertNotIn("contents: write", self.data)
        for forbidden in ["create-release@", "gh release create", "actions/deploy", "SOKNA_V4_PUBLISHER_P256_PEM"]:
            self.assertNotIn(forbidden, self.data)


if __name__ == "__main__":
    unittest.main()
