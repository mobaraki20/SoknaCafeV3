<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Operations;

use PDO;
use Sokna\Local\Domain\Supply\SupplyService;
use Sokna\Local\Domain\Expenses\ExpenseService;

final class OperationsWorkspaceService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SupplyService $supply,
        private readonly ExpenseService $expenses,
    ) {}

    public function snapshot(array $user): array
    {
        $isAdmin=(string)($user['role']??'')==='admin';
        return [
            'inventory'=>[
                'items'=>$this->inventoryItems(),
                'categories'=>$this->inventoryCategories(),
                'recent_movements'=>$this->recentMovements(),
                'open_count'=>$this->openCount(),
            ],
            'supply'=>[
                'groups'=>$this->supply->purchaseGroups(),
                'recent_receipts'=>$this->recentReceipts(),
            ],
            'expenses'=>$isAdmin?[
                'categories'=>$this->expenses->categories(true),
                'recent'=>$this->recentExpenses(),
            ]:null,
        ];
    }

    public function inventoryItems(): array
    {
        $sql="SELECT i.id,i.item_code,i.name,i.category,c.name category_name,i.base_unit,i.default_department,i.warning_threshold,
                    i.review_status,i.active,COALESCE(b.quantity_base,0) quantity_base,b.average_unit_cost,b.cost_status,b.updated_at balance_updated_at
             FROM inventory_items i JOIN inventory_categories c ON c.category_key=i.category
             LEFT JOIN inventory_balances b ON b.inventory_item_id=i.id
             ORDER BY i.active DESC,i.review_status DESC,c.sort_order,i.name,i.id";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function inventoryCategories(): array
    {
        return $this->pdo->query("SELECT category_key,name,active,sort_order FROM inventory_categories ORDER BY active DESC,sort_order,name")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function recentMovements(int $limit=50): array
    {
        $limit=max(1,min(100,$limit));
        $sql="SELECT m.id,m.movement_type,m.quantity_base,m.base_unit,m.department,m.total_cost_delta,m.cost_status,m.note,m.occurred_at,
                    i.name item_name,u.display_name actor_name
             FROM inventory_movements m JOIN inventory_items i ON i.id=m.inventory_item_id
             LEFT JOIN users u ON u.id=m.actor_user_id ORDER BY m.occurred_at DESC,m.id DESC LIMIT {$limit}";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function openCount(): ?array
    {
        $session=$this->pdo->query("SELECT * FROM inventory_count_sessions WHERE status='draft' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if(!is_array($session))return null;
        $stmt=$this->pdo->prepare(
            "SELECT l.id line_id,l.inventory_item_id,l.system_quantity_snapshot,l.actual_quantity,l.actual_total_cost,l.note,l.updated_at version,
                    i.name item_name,i.base_unit
             FROM inventory_count_lines l JOIN inventory_items i ON i.id=l.inventory_item_id WHERE l.session_id=? ORDER BY i.category,i.name,i.id"
        );
        $stmt->execute([(int)$session['id']]);
        $session['lines']=$stmt->fetchAll(PDO::FETCH_ASSOC);
        return $session;
    }

    public function recentReceipts(int $limit=40): array
    {
        $limit=max(1,min(100,$limit));
        $sql="SELECT r.id,r.received_quantity_base,r.remaining_quantity_after,r.total_cost,r.supplier,r.note,r.received_at,
                    i.name item_name,i.base_unit,u.display_name actor_name
             FROM inventory_supply_receipts r JOIN inventory_items i ON i.id=r.inventory_item_id
             LEFT JOIN users u ON u.id=r.actor_user_id ORDER BY r.id DESC LIMIT {$limit}";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function recentExpenses(int $limit=60): array
    {
        $limit=max(1,min(100,$limit));
        $sql="SELECT e.id,e.financial_period_id,e.category_key,c.name category_name,e.amount,e.description,e.occurred_at,e.status,e.reverses_expense_id,
                    u.display_name actor_name,fp.title period_title
             FROM expenses e JOIN expense_categories c ON c.category_key=e.category_key
             JOIN financial_periods fp ON fp.id=e.financial_period_id LEFT JOIN users u ON u.id=e.actor_user_id
             ORDER BY e.occurred_at DESC,e.id DESC LIMIT {$limit}";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}
