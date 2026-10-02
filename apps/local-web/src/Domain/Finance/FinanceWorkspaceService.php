<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Finance;

use PDO;

final class FinanceWorkspaceService
{
    public function __construct(private readonly PDO $pdo) {}

    public function snapshot(): array
    {
        return [
            'open_accounts'=>$this->openAccounts(),
            'periods'=>$this->periods(),
            'recent_settlements'=>$this->recentSettlements(),
            'tax'=>$this->taxSnapshot(),
            'subscriber_summary'=>$this->subscriberSummary(),
        ];
    }

    public function openAccounts(): array
    {
        $sql="SELECT s.id session_id,s.status,s.opened_at,t.id table_id,t.name table_name,
                    COALESCE(SUM(CASE WHEN o.status='accounted' THEN o.total_amount ELSE 0 END),0) accounted_subtotal,
                    SUM(o.status='pending_approval') pending_orders
             FROM table_sessions s JOIN cafe_tables t ON t.id=s.table_id
             LEFT JOIN orders o ON o.session_id=s.id AND o.status IN('pending_approval','new','accounted')
             WHERE s.status IN('active','pending')
             GROUP BY s.id,s.status,s.opened_at,t.id,t.name ORDER BY t.name,s.id";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function periods(int $limit=18): array
    {
        $limit=max(1,min(60,$limit));
        $sql="SELECT fp.*,
                    COALESCE(sr.invoice_count,0) invoice_count,COALESCE(sr.reversal_count,0) reversal_count,
                    COALESCE(sr.sales_amount,0) sales_amount,COALESCE(sr.tax_amount,0) tax_amount,
                    COALESCE(sr.total_amount,0) total_amount,COALESCE(sr.discount_amount,0) discount_amount,
                    COALESCE(ex.expense_count,0) expense_count,COALESCE(ex.expense_reversal_count,0) expense_reversal_count,
                    COALESCE(ex.expense_net_amount,0) expense_net_amount
             FROM financial_periods fp
             LEFT JOIN (
               SELECT financial_period_id,SUM(status='completed') invoice_count,SUM(status='reversal') reversal_count,
                      SUM(CASE WHEN status='completed' THEN total-tax_amount WHEN status='reversal' THEN -(total-tax_amount) ELSE 0 END) sales_amount,
                      SUM(CASE WHEN status='completed' THEN tax_amount WHEN status='reversal' THEN -tax_amount ELSE 0 END) tax_amount,
                      SUM(CASE WHEN status='completed' THEN total WHEN status='reversal' THEN -total ELSE 0 END) total_amount,
                      SUM(CASE WHEN status='completed' THEN discount WHEN status='reversal' THEN -discount ELSE 0 END) discount_amount
               FROM settlement_records GROUP BY financial_period_id
             ) sr ON sr.financial_period_id=fp.id
             LEFT JOIN (
               SELECT financial_period_id,SUM(status='committed') expense_count,SUM(status='reversal') expense_reversal_count,
                      SUM(CASE WHEN status='committed' THEN amount WHEN status='reversal' THEN -amount ELSE 0 END) expense_net_amount
               FROM expenses GROUP BY financial_period_id
             ) ex ON ex.financial_period_id=fp.id
             ORDER BY fp.start_date DESC LIMIT {$limit}";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function recentSettlements(int $limit=100): array
    {
        $limit=max(1,min(100,$limit));
        $sql="SELECT sr.id,sr.invoice_number,sr.destination,sr.table_name_snapshot,sr.subtotal,sr.discount,sr.tax_amount,sr.total,
                    sr.status,sr.settlement_kind,sr.settled_at,sr.business_date,sr.reverses_settlement_id,
                    u.display_name actor_name
             FROM settlement_records sr LEFT JOIN users u ON u.id=sr.actor_user_id
             ORDER BY sr.id DESC LIMIT {$limit}";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function settlementDetail(int $settlementId): array
    {
        if($settlementId<1)throw new SettlementException('invalid_settlement','سند مالی معتبر نیست.',422);
        $stmt=$this->pdo->prepare(
            "SELECT sr.*,fp.title period_title,u.display_name actor_name,sl.subscriber_id,sub.name subscriber_name,
                    at.reservation_code,at.guest_name_snapshot accommodation_guest,at.room_name_snapshot accommodation_room,
                    original.invoice_number original_invoice_number,reversal.invoice_number reversal_invoice_number
             FROM settlement_records sr
             LEFT JOIN financial_periods fp ON fp.id=sr.financial_period_id
             LEFT JOIN users u ON u.id=sr.actor_user_id
             LEFT JOIN subscriber_ledger sl ON sl.id=sr.subscriber_ledger_entry_id
             LEFT JOIN subscribers sub ON sub.id=sl.subscriber_id
             LEFT JOIN accommodation_transfers at ON at.id=sr.accommodation_transfer_id
             LEFT JOIN settlement_records original ON original.id=sr.reverses_settlement_id
             LEFT JOIN settlement_records reversal ON reversal.reverses_settlement_id=sr.id AND reversal.status='reversal'
             WHERE sr.id=? LIMIT 1"
        );
        $stmt->execute([$settlementId]);$record=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($record))throw new SettlementException('settlement_not_found','سند مالی پیدا نشد.',404);
        $snapshot=json_decode((string)($record['invoice_snapshot_json']??''),true);
        if(!is_array($snapshot))$snapshot=[];
        $items=[];
        foreach((array)($snapshot['items']??[]) as $item){
            if(!is_array($item))continue;
            $items[]=[
                'name'=>(string)($item['name']??''),'note'=>(string)($item['note']??''),
                'quantity'=>(int)($item['quantity']??0),'unit_price'=>(int)($item['unit_price']??0),
                'line_total'=>(int)($item['line_total']??0),'line_discount'=>(int)($item['line_discount']??0),
                'tax_amount'=>(int)($item['tax_amount']??0),'line_final'=>(int)($item['line_final']??($item['line_total']??0)),
            ];
        }
        unset($record['invoice_snapshot_json'],$record['request_fingerprint']);
        return ['record'=>$record,'snapshot'=>$snapshot,'items'=>$items];
    }

    public function taxSnapshot(): array
    {
        $enabled=(string)($this->pdo->query("SELECT setting_value FROM settings WHERE setting_key='module.tax.enabled' LIMIT 1")->fetchColumn()?:'0');
        $rates=$this->pdo->query(
            "SELECT r.*,u.display_name actor_name FROM tax_rate_versions r LEFT JOIN users u ON u.id=r.created_by_user_id ORDER BY r.effective_from DESC,r.id DESC LIMIT 20"
        )->fetchAll(PDO::FETCH_ASSOC);
        $current=$this->pdo->query(
            "SELECT * FROM tax_rate_versions WHERE effective_from<=NOW() ORDER BY effective_from DESC,id DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        $items=$this->pdo->query("SELECT id,name,active,sellable_kind FROM items ORDER BY active DESC,name,id LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
        return ['enabled'=>in_array(strtolower(trim($enabled)),['1','true','yes','on'],true),'current_rate'=>$current?:null,'rate_history'=>$rates,'items'=>$items];
    }

    public function subscriberSummary(): array
    {
        $sql="SELECT COUNT(*) total_count,SUM(active=1) active_count,
                    COALESCE(SUM(GREATEST(COALESCE((SELECT l.balance_after FROM subscriber_ledger l WHERE l.subscriber_id=s.id ORDER BY l.id DESC LIMIT 1),0),0)),0) receivable_total
             FROM subscribers s";
        $row=$this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC)?:[];
        return ['total_count'=>(int)($row['total_count']??0),'active_count'=>(int)($row['active_count']??0),'receivable_total'=>(int)($row['receivable_total']??0)];
    }

    public function subscriberDirectory(string $query='',string $status='all',bool $debtOnly=false,int $limit=50): array
    {
        $limit=max(1,min(100,$limit));$query=trim($query);
        if(!in_array($status,['all','active','inactive'],true))$status='all';
        $where=[];$params=[];
        if($status==='active')$where[]='s.active=1';elseif($status==='inactive')$where[]='s.active=0';
        if($debtOnly)$where[]="COALESCE((SELECT l2.balance_after FROM subscriber_ledger l2 WHERE l2.subscriber_id=s.id ORDER BY l2.id DESC LIMIT 1),0)>0";
        if($query!==''){
            $normalized=preg_replace('/\D+/','',$query)??'';
            $where[]='(s.name LIKE ? OR s.mobile LIKE ? OR s.mobile_normalized LIKE ?)';
            $params[]='%'.$query.'%';$params[]='%'.$query.'%';$params[]='%'.$normalized.'%';
        }
        $sql="SELECT s.*,COALESCE((SELECT l.balance_after FROM subscriber_ledger l WHERE l.subscriber_id=s.id ORDER BY l.id DESC LIMIT 1),0) balance,
                    (SELECT MAX(l3.created_at) FROM subscriber_ledger l3 WHERE l3.subscriber_id=s.id) last_activity
             FROM subscribers s".($where?' WHERE '.implode(' AND ',$where):'')." ORDER BY balance DESC,s.active DESC,s.name,s.id LIMIT {$limit}";
        $stmt=$this->pdo->prepare($sql);$stmt->execute($params);return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function subscriberDetail(int $subscriberId,int $ledgerLimit=80): array
    {
        if($subscriberId<1)throw new SettlementException('invalid_subscriber','مشتری معتبر نیست.',422);
        $st=$this->pdo->prepare("SELECT s.*,COALESCE((SELECT l.balance_after FROM subscriber_ledger l WHERE l.subscriber_id=s.id ORDER BY l.id DESC LIMIT 1),0) balance FROM subscribers s WHERE s.id=?");
        $st->execute([$subscriberId]);$subscriber=$st->fetch(PDO::FETCH_ASSOC);
        if(!is_array($subscriber))throw new SettlementException('subscriber_not_found','مشتری پیدا نشد.',404);
        $ledgerLimit=max(1,min(200,$ledgerLimit));
        $lg=$this->pdo->prepare("SELECT l.*,u.display_name actor_name,fp.title period_title FROM subscriber_ledger l LEFT JOIN users u ON u.id=l.actor_user_id LEFT JOIN financial_periods fp ON fp.id=l.financial_period_id WHERE l.subscriber_id=? ORDER BY l.id DESC LIMIT {$ledgerLimit}");
        $lg->execute([$subscriberId]);
        return ['subscriber'=>$subscriber,'ledger'=>$lg->fetchAll(PDO::FETCH_ASSOC)];
    }
}
