namespace Sokna.Maintenance.Core;

public interface IProductionLocalSlotMigrationCoordinator
{
    LocalSlotOperationResult Migrate(
        string expectedBuild,
        int expectedManagedFiles,
        string expectedManagedTreeSha256,
        string operatorId,
        string idempotencyKey);

    LocalSlotOperationResult Recover(string operatorId, string idempotencyKey);
}
