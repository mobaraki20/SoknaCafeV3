using Sokna.Maintenance.Core;
using System.Text.Json;

internal static class LocalCodeOnlyPreExecutionProbe
{
    internal static int Run()
    {
        const string stage = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";
        const string release = "local-code-only-231ap";
        const string actor = "maintenance-admin";
        const string hash = "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb";
        const string target = "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc";
        var receipt = new InactiveLocalSlotReceipt(
            "sokna-inactive-local-slot-v1", 1,
            stage, actor, "local-precheck-test-key", release,
            "3.0.0.231ap", target, 3, hash,
            hash, @"C:\fixture\Web.release.previous", @"C:\fixture\Web.release.target",
            DateTimeOffset.UtcNow, ActualHostHealthQualified: true,
            PromotionQualified: false, ActivationAllowed: false,
            ActivationPerformed: false, DatabaseMutationPerformed: false,
            PublicMutationPerformed: false);
        var accepted = 0;
        var denied = 0;
        static void Check(bool test, string label)
        {
            if (!test) throw new InvalidDataException("CODE_ONLY_BINDING_TEST_FAILED:" + label);
        }
        void Accept(InactiveLocalSlotReceipt item)
        {
            SupervisorLocalCodeOnlyPreExecution.RequireExactReceipt(
                item, stage, actor, release, hash);
            accepted++;
        }
        void Deny(InactiveLocalSlotReceipt? item, string expectedStage = stage,
            string expectedActor = actor, string expectedRelease = release,
            string expectedHash = hash)
        {
            var rejected = false;
            try
            {
                SupervisorLocalCodeOnlyPreExecution.RequireExactReceipt(
                    item, expectedStage, expectedActor, expectedRelease, expectedHash);
            }
            catch (InvalidDataException ex) when (
                ex.Message == "CODE_ONLY_INACTIVE_RECEIPT_MISMATCH")
            {
                rejected = true;
            }
            Check(rejected, "tampered or mismatched receipt was accepted");
            denied++;
        }

        // Signed target (not the locally rehashed sibling receipt) must bind
        // exact version, managed tree and file count before an Engine permit.
        JsonElement Signed(string version, string treeHash, int fileCount) =>
            JsonSerializer.SerializeToElement(new {
                local = new { version, managed_tree_sha256 = treeHash,
                    managed_files = fileCount }
            });
        var goodSigned = Signed(receipt.TargetVersion, receipt.TargetTreeSha256,
            receipt.TargetManagedFiles);
        SupervisorLocalCodeOnlyPreExecution.RequireExactSignedTarget(goodSigned, receipt);
        accepted++;
        void DenySigned(JsonElement candidate)
        {
            var rejected = false;
            try { SupervisorLocalCodeOnlyPreExecution.RequireExactSignedTarget(candidate, receipt); }
            catch (InvalidDataException ex) when
                (ex.Message == "CODE_ONLY_SIGNED_TARGET_MISMATCH") { rejected = true; }
            Check(rejected, "mismatched locally re-signed target accepted as release target");
            denied++;
        }
        DenySigned(Signed("3.0.0.231aq", target, 3));
        DenySigned(Signed(receipt.TargetVersion, hash, 3));
        DenySigned(Signed(receipt.TargetVersion, target, 4));
        DenySigned(Signed(receipt.TargetVersion, target, 0));
        DenySigned(JsonSerializer.SerializeToElement(new { @public = new { version = receipt.TargetVersion } }));
        DenySigned(JsonSerializer.SerializeToElement(new { local = new {
            version = receipt.TargetVersion, managed_tree_sha256 = target,
            managed_files = "3" } }));
        DenySigned(JsonSerializer.SerializeToElement(new { local = new {
            version = receipt.TargetVersion, managed_tree_sha256 = "wrong",
            managed_files = 3 } }));
        DenySigned(JsonSerializer.SerializeToElement(new { local = new {
            version = (string?)null, managed_tree_sha256 = target,
            managed_files = 3 } }));

        Accept(receipt);
        Deny(null);
        Deny(receipt with {StageOperationId = "dddddddddddddddddddddddddddddddd"});
        Deny(receipt with {OperatorId = "other-user"});
        Deny(receipt with {ReleaseSetId = "other-release"});
        Deny(receipt with {InstalledIdentitySha256 = target});
        Deny(receipt with {TargetTreeSha256 = "broken"});
        Deny(receipt with {TargetManagedFiles = 0});
        Deny(receipt with {ActivationAllowed = true});
        Deny(receipt with {PromotionQualified = true});
        Deny(receipt with {ActivationPerformed = true});
        Deny(receipt with {DatabaseMutationPerformed = true});
        Deny(receipt with {PublicMutationPerformed = true});
        Deny(receipt with {Format = "wrong-format"});
        Deny(receipt with {SchemaVersion = 2});
        Deny(receipt with {TargetVersion = ""});
        Deny(receipt, expectedStage: "eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee");
        Deny(receipt, expectedActor: "other-user");
        Deny(receipt, expectedRelease: "other-release");
        Deny(receipt, expectedHash: target);
        Console.WriteLine(JsonSerializer.Serialize(new {
            format = "sokna-maintenance-local-code-only-pre-execution-test-v1",
            result = "PASS", allowed_receipts = accepted, rejected_cases = denied,
            stage_operator_release_and_installed_hash_required = true,
            exact_signed_target_version_tree_filecount_required = true,
            activation_performed = false, database_mutation_performed = false,
            public_mutation_performed = false, real_host_uat = false,
            execution_authorized = false, panel_update_ready = false
        }));
        return 0;
    }
}
