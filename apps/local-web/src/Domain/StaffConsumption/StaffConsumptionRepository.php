<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\StaffConsumption;

use PDO;

final class StaffConsumptionRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function findByClientTokenForUpdate(string $clientToken): ?array
    {
        $this->requireTx();
        $stmt=$this->pdo->prepare(
            'SELECT sc.*,o.public_code order_public_code,o.business_order_number,o.order_context,o.total_amount order_total_amount '.
            'FROM staff_consumptions sc JOIN orders o ON o.id=sc.order_id WHERE sc.client_token=? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$clientToken]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    public function insertDocumentTx(array $data): int
    {
        $this->requireTx();
        $stmt=$this->pdo->prepare(
            'INSERT INTO staff_consumptions(public_code,client_token,order_id,consumer_personnel_id,recorded_by_user_id,'.
            'benefit_policy_id,benefit_profile_id,benefit_override_id,status,menu_value_amount,benefit_amount,discount_amount,payable_amount,'.
            'known_cost_amount,consumer_name_snapshot,policy_snapshot_json,calculation_snapshot_json,business_date,business_shift_key) '.
            "VALUES(?,?,?,?,?,?,?,?, 'posted',?,?,?,?,0,?,?,?,?,?)"
        );
        $stmt->execute([
            $data['public_code'],$data['client_token'],$data['order_id'],$data['consumer_personnel_id'],$data['recorded_by_user_id'],
            $data['benefit_policy_id'],$data['benefit_profile_id'],$data['benefit_override_id'],
            $data['menu_value_amount'],$data['benefit_amount'],$data['discount_amount'],$data['payable_amount'],
            $data['consumer_name_snapshot'],$data['policy_snapshot_json'],$data['calculation_snapshot_json'],$data['business_date'],$data['business_shift_key'],
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function insertLineTx(array $data): int
    {
        $this->requireTx();
        $stmt=$this->pdo->prepare(
            'INSERT INTO staff_consumption_lines(consumption_id,order_item_id,item_id,item_name_snapshot,quantity,menu_unit_price,menu_line_amount,'.
            'benefit_amount,discount_amount,payable_amount,known_cost_amount,calculation_snapshot_json) VALUES(?,?,?,?,?,?,?,?,?,?,0,?)'
        );
        $stmt->execute([
            $data['consumption_id'],$data['order_item_id'],$data['item_id'],$data['item_name_snapshot'],$data['quantity'],
            $data['menu_unit_price'],$data['menu_line_amount'],$data['benefit_amount'],$data['discount_amount'],$data['payable_amount'],
            $data['calculation_snapshot_json'],
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function requireTx(): void
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Staff Consumption repository mutation requires an open transaction.');
    }
}
