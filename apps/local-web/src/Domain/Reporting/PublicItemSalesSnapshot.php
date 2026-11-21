<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\Reporting;
use PDO;

/** Read-only bounded SKU-by-business-day projection; never a payment ledger. */
final class PublicItemSalesSnapshot
{
    public static function build(PDO $pdo,string $businessDate,int $days=60): array
    {
        $tz=new \DateTimeZone('Asia/Tehran');
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$businessDate,$tz);
        if($date===false||$date->format('Y-m-d')!==$businessDate)throw new \InvalidArgumentException('business_date');
        $days=max(1,min(90,$days));$from=$date->modify('-'.($days-1).' days')->format('Y-m-d');
        $catalog=$pdo->query('SELECT i.id,i.name,i.item_code,i.category_id,c.name category_name FROM items i LEFT JOIN categories c ON c.id=i.category_id ORDER BY i.id LIMIT 3001')->fetchAll(PDO::FETCH_ASSOC)?:[];
        if(count($catalog)>3000)throw new \OverflowException('catalog too large');
        $groups=$pdo->query('SELECT id,name,category_id FROM item_variant_families ORDER BY id LIMIT 1001')->fetchAll(PDO::FETCH_ASSOC)?:[];
        if(count($groups)>1000)throw new \OverflowException('families too large');
        $members=$pdo->query('SELECT family_id,item_id FROM item_variant_members ORDER BY family_id,item_id LIMIT 5001')->fetchAll(PDO::FETCH_ASSOC)?:[];
        if(count($members)>5000)throw new \OverflowException('members too large');
        $stmt=$pdo->prepare("SELECT ts.business_date day,l.item_id_snapshot item_id,
          COALESCE(SUM(CASE WHEN r.status='completed' THEN l.quantity ELSE -l.quantity END),0) quantity,
          COALESCE(SUM(CASE WHEN r.status='completed' THEN l.gross_amount ELSE -l.gross_amount END),0) gross,
          COALESCE(SUM(CASE WHEN r.status='completed' THEN l.discount_amount ELSE -l.discount_amount END),0) discount,
          COALESCE(SUM(CASE WHEN r.status='completed' THEN l.final_amount ELSE -l.final_amount END),0) net_sales
          FROM settlement_record_lines l JOIN settlement_records r ON r.id=l.settlement_id
          JOIN table_sessions ts ON ts.id=r.session_id
          WHERE ts.business_date BETWEEN ? AND ?
          GROUP BY ts.business_date,l.item_id_snapshot
          ORDER BY ts.business_date,l.item_id_snapshot LIMIT 12001");
        $stmt->execute([$from,$businessDate]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
        if(count($rows)>12000)throw new \OverflowException('sales archive too large');
        return ['format'=>'sokna-item-sales-daily-archive-v1','status'=>'available',
          'basis'=>'completed_and_reversal_settlement_lines_by_item_id_snapshot_session_open_day',
          'range'=>['from'=>$from,'to'=>$businessDate],
          'catalog'=>array_map(static fn(array $r):array=>['id'=>(int)$r['id'],'name'=>(string)$r['name'],
              'item_code'=>(string)($r['item_code']??''),'category_id'=>(int)($r['category_id']??0),
              'category_name'=>(string)($r['category_name']??'')],$catalog),
          'families'=>array_map(static fn(array $r):array=>['id'=>(int)$r['id'],'name'=>(string)$r['name'],'category_id'=>(int)$r['category_id']],$groups),
          'members'=>array_map(static fn(array $r):array=>['family_id'=>(int)$r['family_id'],'item_id'=>(int)$r['item_id']],$members),
          'daily'=>array_map(static fn(array $r):array=>['day'=>(string)$r['day'],'item_id'=>(int)$r['item_id'],
             'quantity'=>(float)$r['quantity'],'gross'=>(int)$r['gross'],'discount'=>(int)$r['discount'],'net_sales'=>(int)$r['net_sales']],$rows),
          'currency'=>'IRT','excludes'=>'unpaid_open_orders','max_days'=>$days];
    }
}
