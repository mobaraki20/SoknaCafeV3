<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\Reporting;
use DateTimeImmutable;
use PDO;
use Sokna\Local\Core\TimeAuthority;
final class ReportingService
{
    public function __construct(private readonly PDO $pdo,private readonly TimeAuthority $time){}
    /** Immutable-id order lookup for authorized cashier/supervisor historical review.
     * Read-only: never resolve via a table's currently open session.
     */
    public function historicalOrder(int $orderId): array
    {
        if($orderId<=0)throw new ReportingException('invalid_order_id','شناسه سفارش معتبر نیست.',422);
        $q=$this->pdo->prepare('SELECT o.id,o.session_id,o.table_id,o.status,o.order_source,o.business_order_number,o.business_date,o.total_amount,o.customer_note,o.created_at,o.accepted_at,t.name table_name,s.status session_status,s.started_at session_started_at,s.ended_at session_ended_at FROM orders o LEFT JOIN cafe_tables t ON t.id=o.table_id LEFT JOIN table_sessions s ON s.id=o.session_id WHERE o.id=? LIMIT 1');
        $q->execute([$orderId]);$order=$q->fetch(PDO::FETCH_ASSOC);
        if(!$order)throw new ReportingException('order_not_found','این سفارش در سوابق پیدا نشد.',404);
        $q=$this->pdo->prepare('SELECT id,item_name,quantity,ordered_quantity,unit_price,line_total,item_note,fulfillment_mode FROM order_items WHERE order_id=? ORDER BY id');
        $q->execute([$orderId]);$items=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
        $q=$this->pdo->prepare('SELECT DISTINCT r.id,r.invoice_number,r.status,r.settlement_kind,r.settled_at,r.table_name_snapshot FROM settlement_record_lines l JOIN settlement_records r ON r.id=l.settlement_id WHERE l.order_id=? ORDER BY r.id');
        $q->execute([$orderId]);$invoices=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
        return ['order'=>$order,'items'=>$items,'invoices'=>$invoices,'read_only'=>true];
    }

    /** Read-only financial document for a supervisor following an audit event.
     * No raw invoice JSON, customer details, tokens, free text or mutation handles.
     * The caller must check shift_supervision; this method does not authorize users.
     */
    public function historicalSettlement(int $settlementId): array
    {
        if($settlementId<=0)throw new ReportingException('invalid_settlement_id','شناسه سند مالی معتبر نیست.',422);
        $q=$this->pdo->prepare('SELECT id,invoice_number,session_id,table_name_snapshot,status,settlement_kind,destination,subtotal,discount,tax_amount,total,reverses_settlement_id,settled_at,business_date FROM settlement_records WHERE id=? LIMIT 1');
        $q->execute([$settlementId]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new ReportingException('settlement_not_found','این سند مالی در سوابق پیدا نشد.',404);
        $q=$this->pdo->prepare('SELECT id,order_id,item_name_snapshot,quantity,unit_price_snapshot,discount_amount,tax_amount,final_amount FROM settlement_record_lines WHERE settlement_id=? ORDER BY id LIMIT 101');
        $q->execute([$settlementId]);$lines=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
        $complete=count($lines)<=100;
        $lines=array_slice($lines,0,100);
        return ['document'=>[
            'id'=>(int)$row['id'],'invoice_number'=>(string)$row['invoice_number'],
            'session_id'=>(int)$row['session_id'],'table_name'=>(string)$row['table_name_snapshot'],
            'status'=>(string)$row['status'],'settlement_kind'=>(string)$row['settlement_kind'],
            'destination'=>(string)$row['destination'],'subtotal'=>(int)$row['subtotal'],
            'discount'=>(int)$row['discount'],'tax_amount'=>(int)$row['tax_amount'],
            'total'=>(int)$row['total'],'reverses_settlement_id'=>$row['reverses_settlement_id']===null?null:(int)$row['reverses_settlement_id'],
            'settled_at'=>(string)$row['settled_at'],'business_date'=>(string)$row['business_date']],
            'lines'=>array_map(static fn(array $x):array=>['order_id'=>$x['order_id']===null?null:(int)$x['order_id'],
                'name'=>(string)$x['item_name_snapshot'],'quantity'=>(int)$x['quantity'],
                'unit_price'=>(int)$x['unit_price_snapshot'],'discount'=>(int)$x['discount_amount'],
                'tax'=>(int)$x['tax_amount'],'final_amount'=>(int)$x['final_amount']],$lines),
            'lines_complete'=>$complete,'read_only'=>true];
    }

    /** Read-only product picker; never infer a historical sale from product name alone. */
    public function itemSalesLookup(string $query): array
    {
        $query=trim($query);
        if($query==='')return [];
        $charCount=preg_match_all('/./us',$query);
        if($charCount===false||$charCount>80)throw new ReportingException('invalid_search','عبارت جست‌وجو معتبر نیست یا طولانی است.',422);
        $like='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$query).'%';
        return $this->all('SELECT id,name,item_code,active FROM items WHERE name LIKE ? OR item_code LIKE ? ORDER BY active DESC,name,id LIMIT 40',[$like,$like]);
    }

    /** The current catalog's hierarchy is the filter source, independent of search text.
     * Sales still use immutable item IDs in settled-line snapshots. A renamed item
     * remains in its current family; no historical category is silently inferred.
     */
    public function itemSalesGroups(): array
    {
        $categories=$this->all("SELECT c.id,c.name,COUNT(i.id) item_count FROM categories c
          JOIN items i ON i.category_id=c.id GROUP BY c.id,c.name ORDER BY c.name");
        $families=$this->all("SELECT f.id,f.name,f.category_id,COUNT(m.item_id) item_count FROM item_variant_families f
          JOIN item_variant_members m ON m.family_id=f.id GROUP BY f.id,f.name,f.category_id ORDER BY f.category_id,f.sort_order,f.id");
        return ['categories'=>$categories,'families'=>$families,'source'=>'current_catalog_membership'];
    }

    /** One click for a whole category or variant family; never search by name prefix. */
    public function itemSalesGroupReport(?string $from,?string $to,string $type,int $id): array
    {
        if($id<=0||!in_array($type,['category','family'],true))
            throw new ReportingException('invalid_sales_group','گروه گزارش معتبر نیست.',422);
        $field=$type==='category'?'category_id':'family_id';
        $sql=$type==='category'
          ?'SELECT id FROM items WHERE category_id=? ORDER BY id LIMIT 301'
          :'SELECT item_id id FROM item_variant_members WHERE family_id=? ORDER BY item_id LIMIT 301';
        $items=$this->all($sql,[$id]);
        if(count($items)>300)throw new ReportingException('sales_group_too_large','گروه برای یک گزارش بیش از ۳۰۰ کالا دارد.',422);
        if(!$items)throw new ReportingException('sales_group_empty','این گروه هیچ کالایی ندارد.',422);
        $groupRow=$this->one($type==='category'?'SELECT name FROM categories WHERE id=?':'SELECT name FROM item_variant_families WHERE id=?',[$id]);
        if(!$groupRow)throw new ReportingException('sales_group_not_found','گروه گزارش پیدا نشد.',404);
        $report=$this->itemSalesReport($from,$to,array_column($items,'id'),300);
        $report['group']=['type'=>$type,'id'=>$id,'name'=>(string)$groupRow['name'],'count'=>count($items)];
        $report['membership']='current_catalog';
        return $report;
    }

    /** Ad-hoc across-category name match. Not a permanently saved analytic family.
     * Enumerate matched IDs first to avoid name-based historical financial joins.
     */
    public function itemSalesKeywordReport(?string $from,?string $to,string $query): array
    {
        $query=trim($query);
        $characters=preg_match_all('/./us',$query);
        if($characters===false||$characters<2||$characters>60)
            throw new ReportingException('invalid_sales_keyword','عبارت نام گروه باید ۲ تا ۶۰ حرف باشد.',422);
        $like='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$query).'%';
        $rows=$this->all("SELECT id FROM items WHERE name LIKE ? ORDER BY id LIMIT 301",[$like]);
        if(count($rows)>300)throw new ReportingException('sales_keyword_too_large','این جست‌وجو بیش از ۳۰۰ محصول دارد؛ عبارت را دقیق‌تر کنید.',422);
        if(!$rows)throw new ReportingException('sales_keyword_empty','محصولی با این عبارت در نامش یافت نشد.',422);
        $report=$this->itemSalesReport($from,$to,array_column($rows,'id'),300);
        $report['group']=['type'=>'keyword','id'=>0,'name'=>'نام شامل «'.$query.'»','count'=>count($rows)];
        $report['membership']='current_catalog_name_match';
        return $report;
    }

    /** A product-selection sales report over settled lines, never open orders.
     * Stable item_id_snapshot keeps renamed products together; returns zeros for selected items without sales.
     * Quantities and amounts are net of reversal settlement documents.
     */
    public function itemSalesReport(?string $from,?string $to,array $rawIds,int $maxItems=30): array
    {
        [$from,$to]=$this->range($from,$to);
        $ids=array_values(array_unique(array_filter(array_map('intval',$rawIds),static fn(int $id):bool=>$id>0)));
        if(!$ids||count($ids)>$maxItems)throw new ReportingException('invalid_item_ids','تعداد محصولات انتخاب‌شده معتبر نیست.',422);
        $slots=implode(',',array_fill(0,count($ids),'?'));
        $products=$this->all("SELECT i.id,i.name,i.item_code,i.active,i.category_id,COALESCE(c.name,'') category_name FROM items i LEFT JOIN categories c ON c.id=i.category_id WHERE i.id IN ($slots)",$ids);
        $byId=[];foreach($products as $row)$byId[(int)$row['id']]=$row;
        // A product can belong to more than one variant family. Preserve ALL
        // memberships without duplicating sales (and without guessing historic
        // membership from the current display name).
        $membership=$this->all("SELECT m.item_id,m.family_id,f.name family_name FROM item_variant_members m JOIN item_variant_families f ON f.id=m.family_id WHERE m.item_id IN ($slots) ORDER BY m.item_id,m.family_id",$ids);
        $familiesByItem=[];
        foreach($membership as $m){$itemId=(int)$m['item_id'];$familiesByItem[$itemId][(int)$m['family_id']]=['id'=>(int)$m['family_id'],'name'=>(string)$m['family_name']];}
        $raw=$this->all("SELECT l.item_id_snapshot item_id,
          COALESCE(SUM(CASE WHEN r.status='completed' THEN l.quantity ELSE -l.quantity END),0) quantity,
          COALESCE(SUM(CASE WHEN r.status='completed' THEN l.gross_amount ELSE -l.gross_amount END),0) gross,
          COALESCE(SUM(CASE WHEN r.status='completed' THEN l.discount_amount ELSE -l.discount_amount END),0) discount,
          COALESCE(SUM(CASE WHEN r.status='completed' THEN l.final_amount ELSE -l.final_amount END),0) net_sales,
          COUNT(DISTINCT CASE WHEN r.status='completed' THEN r.id END) payment_documents
          FROM settlement_record_lines l JOIN settlement_records r ON r.id=l.settlement_id
          JOIN table_sessions ts ON ts.id=r.session_id
          WHERE ts.business_date BETWEEN ? AND ? AND r.status IN('completed','reversal') AND l.item_id_snapshot IN ($slots)
          GROUP BY l.item_id_snapshot",array_merge([$from,$to],$ids));
        $bySales=[];foreach($raw as $row)$bySales[(int)$row['item_id']]=$row;
        $rows=[];$totals=['quantity'=>0,'gross'=>0,'discount'=>0,'net_sales'=>0];
        foreach($ids as $id){$p=$byId[$id]??null;$m=$bySales[$id]??[];
            $row=['item_id'=>$id,'name'=>(string)($p['name']??('آیتم شماره '.$id)),
                 'item_code'=>(string)($p['item_code']??''),'available_in_catalog'=>$p!==null,
                 'category_id'=>$p!==null&&(int)($p['category_id']??0)>0?(int)$p['category_id']:null,
                 'category_name'=>$p!==null?(string)($p['category_name']??''):'',
                 'variant_families'=>array_values($familiesByItem[$id]??[]),
                 'quantity'=>(int)($m['quantity']??0),'gross'=>(int)($m['gross']??0),
                 'discount'=>(int)($m['discount']??0),'net_sales'=>(int)($m['net_sales']??0),
                 'payment_documents'=>(int)($m['payment_documents']??0)];
            foreach($totals as $key=>$_)$totals[$key]+=$row[$key];$rows[]=$row;
        }
        return ['range'=>['from'=>$from,'to'=>$to],'rows'=>$rows,'totals'=>$totals,
           'basis'=>'completed_and_reversal_settlement_lines_by_item_id_snapshot_session_open_day',
           'excludes'=>'unpaid_open_orders','currency'=>'IRT','hierarchy_basis'=>'current_catalog_category_and_variant_family_not_historical'];
    }

    /** Today's real operational events, not the user's notification inbox.
     * Restrict the source queries to the server's exact Tehran Business Day
     * BEFORE LIMIT. This prevents future-dated/clock-drifted or unbounded
     * historical activity from displacing legitimate current-day events.
     * Legacy callers without a window retain read-only bounded behavior.
     */
    public function dashboardOperationalActivity(?string $businessDate=null,?string $cutoff=null): array
    {
        $rows=[];
        $window=[];$auditWindow='';$ordersWindow='';$sessionsWindow='';
        if($businessDate!==null||$cutoff!==null){
            if(!is_string($businessDate)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$businessDate)
                ||!is_string($cutoff)||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$cutoff))
                throw new \InvalidArgumentException('invalid_dashboard_activity_window');
            $tz=new \DateTimeZone('Asia/Tehran');
            $from=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$businessDate.' '.$cutoff,$tz);
            if(!$from||$from->format('Y-m-d H:i')!==$businessDate.' '.$cutoff)
                throw new \InvalidArgumentException('invalid_dashboard_activity_date');
            $window=[$from->format('Y-m-d H:i:s'),$from->modify('+1 day')->format('Y-m-d H:i:s')];
            $auditWindow=' AND created_at>=? AND created_at<?';
            $ordersWindow=' AND o.created_at>=? AND o.created_at<?';
            $sessionsWindow=' WHERE s.started_at>=? AND s.started_at<?';
        }
        $audit=$this->all("SELECT id,action,entity_type,entity_id,details_json,created_at,
            COALESCE(actor_display_name_snapshot,'کاربر') actor_name FROM audit_log
            WHERE action IN ('table_draft.created','table_draft.updated','table_draft.cancelled',
              'order.status_changed','order.item_quantity_adjusted','order.item_preparation_adjusted',
              'order.cancelled_unsettled_staff','order.cancelled_after_confirmed_cash_refund',
              'order.whole_cancelled','order.pending_cancelled_by_selection',
              'order.selection_cancelled','order.preparation_reprint_requested',
              'settlement.completed','settlement.reversed','settlement.remaining_discount_granted'){$auditWindow}
            ORDER BY created_at DESC,id DESC LIMIT 180",$window);
        $labels=[
            'table_draft.created'=>'پیش‌نویس میز ثبت شد',
            'table_draft.updated'=>'پیش‌نویس میز ویرایش شد',
            'table_draft.cancelled'=>'پیش‌نویس میز لغو شد',
            'order.status_changed'=>'وضعیت سفارش تغییر کرد',
            'order.item_quantity_adjusted'=>'تعداد قلم سفارش اصلاح شد',
            'order.item_preparation_adjusted'=>'آماده‌سازی سفارش اصلاح شد',
            'order.cancelled_unsettled_staff'=>'سفارش لغو شد',
            'order.cancelled_after_confirmed_cash_refund'=>'سفارش پس از برگشت وجه لغو شد',
            'order.whole_cancelled'=>'لغو کامل سفارش ثبت شد',
            'order.pending_cancelled_by_selection'=>'سفارش در انتظار لغو شد',
            'order.selection_cancelled'=>'لغو انتخابی ثبت شد',
            'order.preparation_reprint_requested'=>'چاپ مجدد آماده‌سازی درخواست شد',
            'settlement.completed'=>'پرداخت حساب ثبت شد',
            'settlement.reversed'=>'سند پرداخت برگشت خورد',
            'settlement.remaining_discount_granted'=>'تخفیف مانده ثبت شد',
        ];
        foreach($audit as $a){
            $action=(string)($a['action']??'');$orderId=str_starts_with($action,'order.')?(int)($a['entity_id']??0):0;
            $details=json_decode((string)($a['details_json']??'{}'),true);
            if($action==='table_draft.cancelled')$details=is_array($details)?$details:[];
            $rows[]=['kind'=>'audit','event_key'=>'audit-'.$a['id'],'created_at'=>(string)$a['created_at'],
                'title'=>$labels[$action]??'فعالیت عملیاتی','body'=>(string)($a['actor_name']??'کاربر'),
                'target_url'=>$orderId>0?('/reports/?order_id='.$orderId.'#order-history'):'/operator/?view=open'];
        }
        // Orders can be saved without a separate audit action: read their source table.
        $orders=$this->all("SELECT o.id,o.created_at,o.order_source,t.name table_name
             FROM orders o LEFT JOIN cafe_tables t ON t.id=o.table_id
             WHERE COALESCE(o.order_context,'')<>'staff_consumption'{$ordersWindow}
             ORDER BY o.created_at DESC,o.id DESC LIMIT 100",$window);
        foreach($orders as $o)$rows[]=['kind'=>'order','event_key'=>'order-'.$o['id'],
            'created_at'=>(string)$o['created_at'],'title'=>'سفارش ثبت شد',
            'body'=>(string)($o['table_name']??'میز').' · '.((string)($o['order_source']??'')==='guest'?'مهمان':'پرسنل'),
            'target_url'=>'/reports/?order_id='.(int)$o['id'].'#order-history'];
        $sessions=$this->all("SELECT s.id,s.started_at,t.name table_name FROM table_sessions s
            JOIN cafe_tables t ON t.id=s.table_id{$sessionsWindow} ORDER BY s.started_at DESC,s.id DESC LIMIT 80",$window);
        foreach($sessions as $s)$rows[]=['kind'=>'table','event_key'=>'session-'.$s['id'],
            'created_at'=>(string)$s['started_at'],'title'=>'حساب میز باز شد',
            'body'=>(string)($s['table_name']??'میز'),'target_url'=>'/operator/?view=open'];
        usort($rows,static fn(array $a,array $b):int=>strcmp($b['created_at'],$a['created_at'])?:strcmp($b['event_key'],$a['event_key']));
        return $rows;
    }

    public function report(?string $from=null,?string $to=null): array
    {
        [$from,$to]=$this->range($from,$to);$p=[$from,$to];
        // Never change the immutable receipt's business_date. This is the CASH date.
        // Sales use the persisted opening Business Day on table_sessions.
        $summary=$this->one("SELECT
          COALESCE(SUM(CASE WHEN r.status='completed' THEN r.total ELSE -r.total END),0) net_sales,
          COALESCE(SUM(CASE WHEN r.status='completed' THEN r.subtotal ELSE -r.subtotal END),0) gross_sales,
          COALESCE(SUM(CASE WHEN r.status='completed' THEN r.discount ELSE -r.discount END),0) discount_total,
          COALESCE(SUM(CASE WHEN r.status='completed' THEN r.tax_amount ELSE -r.tax_amount END),0) net_tax_amount,
          COALESCE(SUM(CASE WHEN r.status='completed' THEN r.total-r.tax_amount ELSE -(r.total-r.tax_amount) END),0) sales_without_tax
          FROM settlement_records r JOIN table_sessions ts ON ts.id=r.session_id
          WHERE ts.business_date BETWEEN ? AND ? AND r.status IN('completed','reversal')",$p);
        $cashSummary=$this->one("SELECT SUM(status='completed') completed_settlements,
          SUM(status='reversal') reversals,
          COALESCE(SUM(CASE WHEN status='completed' THEN total ELSE 0 END),0) completed_amount,
          COALESCE(SUM(CASE WHEN status='reversal' THEN total ELSE 0 END),0) reversed_amount
          FROM settlement_records WHERE business_date BETWEEN ? AND ? AND status IN('completed','reversal')",$p);
        $invoiceAggregate=$this->one("SELECT COALESCE(AVG(x.net_total),0) avg_ticket,COUNT(*) invoice_count FROM (
          SELECT r.session_id, SUM(CASE WHEN r.status='completed' THEN r.total WHEN r.status='reversal' THEN -r.total ELSE 0 END) net_total
          FROM settlement_records r
          JOIN table_sessions ts ON ts.id=r.session_id AND ts.status='closed'
          WHERE ts.business_date BETWEEN ? AND ? AND r.status IN('completed','reversal')
            AND EXISTS (SELECT 1 FROM settlement_records c
                        WHERE c.session_id=r.session_id AND c.status='completed' AND c.closes_session=1)
          GROUP BY r.session_id
        ) x",$p);
        $orders=$this->one("SELECT SUM(CASE WHEN COALESCE(order_context,'')<>'staff_consumption' THEN 1 ELSE 0 END) order_count,COUNT(*) all_order_count,SUM(CASE WHEN order_context='staff_consumption' THEN 1 ELSE 0 END) staff_consumption_orders,SUM(status='cancelled' AND COALESCE(order_context,'')<>'staff_consumption') cancelled_orders,SUM(order_source='guest' AND COALESCE(order_context,'')<>'staff_consumption') guest_orders,SUM(order_source='staff' AND COALESCE(order_context,'')<>'staff_consumption') staff_orders FROM orders WHERE business_date BETWEEN ? AND ?",$p);
        $expenses=$this->one("SELECT COALESCE(SUM(CASE WHEN status='reversal' THEN -amount ELSE amount END),0) net_expenses FROM expenses WHERE DATE(occurred_at) BETWEEN ? AND ?",$p);
        $salesDaily=$this->all("SELECT ts.business_date business_date,
          COALESCE(SUM(CASE WHEN r.status='completed' THEN r.subtotal ELSE -r.subtotal END),0) gross_sales,
          COALESCE(SUM(CASE WHEN r.status='completed' THEN r.discount ELSE -r.discount END),0) discount_total,
          COALESCE(SUM(CASE WHEN r.status='completed' THEN r.total ELSE -r.total END),0) net_sales
          FROM settlement_records r JOIN table_sessions ts ON ts.id=r.session_id
          WHERE ts.business_date BETWEEN ? AND ? AND r.status IN('completed','reversal') GROUP BY ts.business_date ORDER BY ts.business_date",$p);
        $cashDaily=$this->all("SELECT business_date,COUNT(*) settlement_count FROM settlement_records
          WHERE business_date BETWEEN ? AND ? AND status IN('completed','reversal') GROUP BY business_date ORDER BY business_date",$p);
        // The two daily axes are independent, including days with payments but no sales.
        $byDay=[];
        foreach($salesDaily as $row)$byDay[(string)$row['business_date']]=array_replace(['settlement_count'=>0],$row);
        foreach($cashDaily as $row){$date=(string)$row['business_date'];
            if(!isset($byDay[$date]))$byDay[$date]=['business_date'=>$date,'gross_sales'=>0,'discount_total'=>0,'net_sales'=>0];
            $byDay[$date]['settlement_count']=(int)$row['settlement_count'];
        }
        ksort($byDay);$daily=array_values($byDay);
        $destinations=$this->all("SELECT r.destination,COUNT(*) settlement_count,
          COALESCE(SUM(CASE WHEN r.status='completed' THEN r.total ELSE -r.total END),0) net_sales
          FROM settlement_records r JOIN table_sessions ts ON ts.id=r.session_id
          WHERE ts.business_date BETWEEN ? AND ? AND r.status IN('completed','reversal') GROUP BY r.destination ORDER BY net_sales DESC",$p);
        $top=$this->all("SELECT l.item_name_snapshot item_name,
          SUM(CASE WHEN r.status='completed' THEN l.quantity ELSE -l.quantity END) quantity,
          COALESCE(SUM(CASE WHEN r.status='completed' THEN l.final_amount ELSE -l.final_amount END),0) net_sales
          FROM settlement_record_lines l JOIN settlement_records r ON r.id=l.settlement_id
          JOIN table_sessions ts ON ts.id=r.session_id
          WHERE ts.business_date BETWEEN ? AND ? AND r.status IN('completed','reversal') GROUP BY l.item_name_snapshot ORDER BY net_sales DESC LIMIT 20",$p);
        $waiter=$this->one("SELECT COUNT(*) waiter_calls,SUM(status='completed') completed_waiter_calls FROM waiter_calls WHERE business_date BETWEEN ? AND ?",$p);
        $tenders=$this->tenderReport($from,$to);
        $operations=$this->operationsReport($from,$to);
        $inventory=$this->inventoryReport($from,$to);
        $margin=$this->marginReport($from,$to);
        $financialContext=$this->financialContextReport($from,$to);
        $bundleUsage=$this->bundleComponentUsageReport($from,$to);
        return ['range'=>['from'=>$from,'to'=>$to],'summary'=>['net_sales'=>(int)($summary['net_sales']??0),'gross_sales'=>(int)($summary['gross_sales']??0),'discount_total'=>(int)($summary['discount_total']??0),'completed_settlements'=>(int)($cashSummary['completed_settlements']??0),'reversals'=>(int)($cashSummary['reversals']??0),'avg_ticket'=>(int)round((float)($invoiceAggregate['avg_ticket']??0)),'invoice_count'=>(int)($invoiceAggregate['invoice_count']??0),'avg_ticket_basis'=>'mean_net_closed_table_session_open_day','sales_date_basis'=>'table_session_open_business_date','cash_date_basis'=>'settlement_record_business_date','completed_amount'=>(int)($cashSummary['completed_amount']??0),'reversed_amount'=>(int)($cashSummary['reversed_amount']??0),'cash_net_payment_day'=>(int)($cashSummary['completed_amount']??0)-(int)($cashSummary['reversed_amount']??0),'sales_without_tax'=>(int)($summary['sales_without_tax']??0),'net_tax_amount'=>(int)($summary['net_tax_amount']??0),'order_count'=>(int)($orders['order_count']??0),'all_order_count'=>(int)($orders['all_order_count']??0),'staff_consumption_orders'=>(int)($orders['staff_consumption_orders']??0),'cancelled_orders'=>(int)($orders['cancelled_orders']??0),'guest_orders'=>(int)($orders['guest_orders']??0),'staff_orders'=>(int)($orders['staff_orders']??0),'net_expenses'=>(int)($expenses['net_expenses']??0),'waiter_calls'=>(int)($waiter['waiter_calls']??0),'completed_waiter_calls'=>(int)($waiter['completed_waiter_calls']??0)],'daily'=>$daily,'destinations'=>$destinations,'top_items'=>$top,'tenders'=>$tenders,'operations'=>$operations,'inventory'=>$inventory,'margin'=>$margin,'financial_context'=>$financialContext,'bundle_component_usage'=>$bundleUsage,'generated_at'=>$this->time->nowUtcIso(),'civil_date'=>$this->time->civilDate()];
    }


    /** Current price list vs current ingredient costs; distinct from historical realized margin. */
    public function catalogPricingReport(): array
    {
        // A separate manager-only endpoint calls this. No mutation, no inventory movement.
        $catalog=$this->all("SELECT i.id,i.item_code,i.name,i.price,i.available,i.active,i.sellable_kind,c.name category_name,vm.label variant_label,ib.bundle_item_id bundle_id
            FROM items i JOIN categories c ON c.id=i.category_id
            LEFT JOIN item_variant_members vm ON vm.item_id=i.id
            LEFT JOIN item_bundles ib ON ib.bundle_item_id=i.id
            WHERE i.active=1 ORDER BY c.name,i.name,i.id",[]);
        $recipeRows=$this->all("SELECT v.id recipe_id,v.menu_item_id,v.review_status,v.completeness_status,
            rc.inventory_item_id,rc.quantity_base,rc.quantity_status,ii.name inventory_item_name,
            ii.base_unit,ii.active inventory_active,ii.review_status inventory_review_status,b.average_unit_cost,b.cost_status,b.updated_at cost_updated_at
            FROM inventory_recipe_versions v
            JOIN inventory_recipe_components rc ON rc.recipe_version_id=v.id
            JOIN inventory_items ii ON ii.id=rc.inventory_item_id
            LEFT JOIN inventory_balances b ON b.inventory_item_id=ii.id
            WHERE v.status='active' ORDER BY v.menu_item_id,rc.id",[]);
        $grouped=[];
        foreach($recipeRows as $r){$id=(int)$r['menu_item_id'];
            if(!isset($grouped[$id]))$grouped[$id]=['recipe'=>['review_status'=>$r['review_status'],'completeness_status'=>$r['completeness_status']],'components'=>[]];
            $grouped[$id]['components'][]=$r;
        }
        $rows=[];
        foreach($catalog as $item){$id=(int)$item['id'];$recipe=$grouped[$id]??null;
            $calc=CatalogFoodCost::estimate($recipe['components']??[],$recipe['recipe']??null,(int)$item['price'],(int)($item['bundle_id']??0)>0);
            // Do not expose ingredient costs for a special non-food service as a fabricated 0.
            $rows[]=['item_id'=>$id,'sku'=>(string)($item['item_code']??''),'name'=>(string)$item['name'],
                'category_name'=>(string)$item['category_name'],'variant_label'=>(string)($item['variant_label']??''),
                'sellable_kind'=>(string)$item['sellable_kind'],'available'=>(int)$item['available']===1,
                'price'=>(int)$item['price'],'ingredient_cost'=>$calc['ingredient_cost'],
                'known_cost_subtotal'=>$calc['known_cost_subtotal'],'price_minus_ingredients'=>$calc['price_minus_ingredients'],
                'food_cost_percent'=>$calc['food_cost_percent'],'cost_status'=>$calc['status'],
                'ingredient_count'=>$calc['ingredient_count'],'oldest_cost_updated_at'=>$calc['oldest_cost_updated_at']];
        }
        return ['generated_at'=>$this->time->nowUtcIso(),'items'=>$rows,'total_items'=>count($rows),
            'explanation'=>'هزینه جاری مواد بر پایه بهای میانگین ثبت‌شده است؛ سود خالص، هزینه بسته‌بندی و دستمزد محسوب نشده‌اند.'];
    }

    /** Daily facts are associative, so arbitrary date ranges aggregate exactly on Public. */
    public function remoteArchive(): array
    {
        $to=$this->time->civilDate();$from=(new DateTimeImmutable($to))->modify('-365 days')->format('Y-m-d');$p=[$from,$to];$days=[];
        $merge=function(array $rows)use(&$days):void{foreach($rows as $r){$day=$r['date'];$days[$day]=array_merge($days[$day]??['date'=>$day],$r);}};
        $sales=$this->all("SELECT ts.business_date date,COUNT(*) settlement_count,
          COALESCE(SUM(CASE WHEN r.status='completed' THEN r.total ELSE -r.total END),0) net_sales,
          COALESCE(SUM(CASE WHEN r.status='completed' THEN r.subtotal ELSE -r.subtotal END),0) gross_sales,
          COALESCE(SUM(CASE WHEN r.status='completed' THEN r.discount ELSE -r.discount END),0) discount_total,
          SUM(r.status='completed') completed_settlements,SUM(r.status='reversal') reversals,
          SUM(CASE WHEN r.status='completed' THEN r.total ELSE 0 END) completed_sales
          FROM settlement_records r JOIN table_sessions ts ON ts.id=r.session_id
          WHERE ts.business_date BETWEEN ? AND ? AND r.status IN('completed','reversal') GROUP BY ts.business_date ORDER BY ts.business_date",$p);
        // Local alone knows the full ledger coverage. Emit *explicit* zero-sale rows
        // so Public can distinguish a genuine zero from an omitted/missing read model.
        $sales=self::completeDailySales($from,$to,$sales);$merge($sales);
        // CASH facts are independent of the sale-date projection above. A table
        // opened yesterday may receive two itemized payments today, then a
        // reversal tomorrow; none of these events re-date its sale.
        $cash=$this->all("SELECT business_date date,COUNT(*) settlement_count,
          SUM(status='completed') completed_settlements,SUM(status='reversal') reversals,
          COALESCE(SUM(CASE WHEN status='completed' THEN total ELSE 0 END),0) completed_amount,
          COALESCE(SUM(CASE WHEN status='reversal' THEN total ELSE 0 END),0) reversed_amount
          FROM settlement_records
          WHERE business_date BETWEEN ? AND ? AND status IN('completed','reversal')
          GROUP BY business_date ORDER BY business_date",$p);
        $cash=self::completeDailyCash($from,$to,$cash);

        $merge($this->all("SELECT business_date date,SUM(CASE WHEN COALESCE(order_context,'')<>'staff_consumption' THEN 1 ELSE 0 END) order_count,COUNT(*) all_order_count,SUM(CASE WHEN order_context='staff_consumption' THEN 1 ELSE 0 END) staff_consumption_orders,SUM(status='cancelled' AND COALESCE(order_context,'')<>'staff_consumption') cancelled_orders,SUM(order_source='guest' AND COALESCE(order_context,'')<>'staff_consumption') guest_orders,SUM(order_source='staff' AND COALESCE(order_context,'')<>'staff_consumption') staff_orders FROM orders WHERE business_date BETWEEN ? AND ? GROUP BY business_date",$p));
        $merge($this->all("SELECT DATE(occurred_at) date,COALESCE(SUM(CASE WHEN status='reversal' THEN -amount ELSE amount END),0) net_expenses FROM expenses WHERE DATE(occurred_at) BETWEEN ? AND ? GROUP BY DATE(occurred_at)",$p));
        $merge($this->all("SELECT business_date date,COUNT(*) waiter_calls,SUM(status='completed') completed_waiter_calls FROM waiter_calls WHERE business_date BETWEEN ? AND ? GROUP BY business_date",$p));
        // Invoice != payment. A single closed table session may have many
        // completed settlement records (itemized/mixed tenders). Publish the
        // full immutable closed-invoice numerator and denominator by opening day.
        $merge($this->all("SELECT x.date,COUNT(*) invoice_count,
          COALESCE(SUM(x.net_total),0) closed_invoice_total FROM (
            SELECT ts.business_date date,r.session_id,
              SUM(CASE WHEN r.status='completed' THEN r.total ELSE -r.total END) net_total
            FROM settlement_records r JOIN table_sessions ts ON ts.id=r.session_id AND ts.status='closed'
            WHERE ts.business_date BETWEEN ? AND ? AND r.status IN('completed','reversal')
              AND EXISTS (SELECT 1 FROM settlement_records c
                          WHERE c.session_id=r.session_id AND c.status='completed' AND c.closes_session=1)
            GROUP BY ts.business_date,r.session_id
          ) x GROUP BY x.date ORDER BY x.date",$p));
        foreach($days as &$day){
            // Explicit zeros are safe only because this Local query completed
            // successfully and sales coverage was explicitly filled above.
            $day['invoice_count']=(int)($day['invoice_count']??0);
            $day['closed_invoice_total']=(int)($day['closed_invoice_total']??0);
        }unset($day);
        return ['from'=>$from,'to'=>$to,'invoice_basis'=>'closed_table_session_open_business_day_v1',
            'cash_basis'=>'settlement_record_business_date_v1','cash_daily'=>$cash,
            'summary'=>array_values($days),'sales'=>$sales,
            'destinations'=>$this->all("SELECT ts.business_date date,r.destination,COUNT(*) settlement_count,
            COALESCE(SUM(CASE WHEN r.status='completed' THEN r.total ELSE -r.total END),0) net_sales
            FROM settlement_records r JOIN table_sessions ts ON ts.id=r.session_id
            WHERE ts.business_date BETWEEN ? AND ? AND r.status IN('completed','reversal') GROUP BY ts.business_date,r.destination",$p),
            'items'=>$this->all("SELECT ts.business_date date,l.item_id_snapshot item_id,l.item_name_snapshot item_name,
             SUM(CASE WHEN r.status='completed' THEN l.quantity ELSE -l.quantity END) quantity,
             COALESCE(SUM(CASE WHEN r.status='completed' THEN l.final_amount ELSE -l.final_amount END),0) net_sales
             FROM settlement_record_lines l JOIN settlement_records r ON r.id=l.settlement_id
             JOIN table_sessions ts ON ts.id=r.session_id
             WHERE ts.business_date BETWEEN ? AND ? AND r.status IN('completed','reversal') GROUP BY ts.business_date,l.item_id_snapshot,l.item_name_snapshot",$p)];
    }

    /** Cash projection is independently complete, including verified zero-event days.
     * Return no fabricated events for an incomplete or malformed query result.
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function completeDailyCash(string $from,string $to,array $rows): array
    {
        $a=DateTimeImmutable::createFromFormat('!Y-m-d',$from);
        $b=DateTimeImmutable::createFromFormat('!Y-m-d',$to);
        if(!$a||!$b||$a->format('Y-m-d')!==$from||$b->format('Y-m-d')!==$to||$b<$a||$a->diff($b)->days>366)
            throw new \InvalidArgumentException('invalid_cash_coverage');
        $byDay=[];
        foreach($rows as $row){
            $day=(string)($row['date']??'');
            if($day<$from||$day>$to||isset($byDay[$day])||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$day))
                throw new \UnexpectedValueException('invalid_daily_cash_row');
            foreach(['settlement_count','completed_settlements','reversals','completed_amount','reversed_amount'] as $key){
                if(!isset($row[$key])||!is_numeric($row[$key])||(int)$row[$key]<0)
                    throw new \UnexpectedValueException('invalid_daily_cash_value');
            }
            if((int)$row['settlement_count']!==(int)$row['completed_settlements']+(int)$row['reversals'])
                throw new \UnexpectedValueException('invalid_daily_cash_count');
            $byDay[$day]=$row;
        }
        $days=[];
        for($day=$a;$day<=$b;$day=$day->modify('+1 day')){
            $key=$day->format('Y-m-d');
            $days[]=array_replace(['date'=>$key,'settlement_count'=>0,'completed_settlements'=>0,
                'reversals'=>0,'completed_amount'=>0,'reversed_amount'=>0],$byDay[$key]??[]);
        }
        return $days;
    }

    /** Fill gaps only within a *locally queried, complete* ledger date range.
     * Public must never extrapolate these zeros from a partial or stale response.
     * @param list<array<string,mixed>> $sales
     * @return list<array<string,mixed>>
     */
    public static function completeDailySales(string $from,string $to,array $sales): array
    {
        $start=DateTimeImmutable::createFromFormat('!Y-m-d',$from);
        $end=DateTimeImmutable::createFromFormat('!Y-m-d',$to);
        if(!$start||!$end||$start->format('Y-m-d')!==$from||$end->format('Y-m-d')!==$to
            ||$end<$start||$start->diff($end)->days>366)throw new \InvalidArgumentException('invalid_sales_coverage');
        $byDay=[];
        foreach($sales as $row){
            $date=(string)($row['date']??'');
            if($date<$from||$date>$to||isset($byDay[$date])||!is_numeric($row['net_sales']??null))
                throw new \UnexpectedValueException('invalid_daily_sales_row');
            $byDay[$date]=$row;
        }
        $complete=[];
        for($day=$start;$day<=$end;$day=$day->modify('+1 day')){
            $key=$day->format('Y-m-d');
            $complete[]=array_replace([
                'date'=>$key,'settlement_count'=>0,'net_sales'=>0,'gross_sales'=>0,'discount_total'=>0,
                'completed_settlements'=>0,'reversals'=>0,'completed_sales'=>0,
            ],$byDay[$key]??[]);
        }
        return $complete;
    }

    /** Lightweight dashboard projection used by the shared Public Staff dashboard. */
    public function dashboardSnapshot(?string $date=null): array
    {
        $owns=!$this->pdo->inTransaction();
        if($owns){
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        }
        try{
            $snapshot=$this->dashboardSnapshotTx($date);
            if($owns)$this->pdo->commit();
            return $snapshot;
        }catch(\Throwable $e){
            if($owns&&$this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    /** One view of the ledger for every dashboard metric and trend point. */
    private function dashboardSnapshotTx(?string $date): array
    {
        $today=$this->date($date?:$this->time->civilDate());$d=new DateTimeImmutable($today);$yesterday=$d->modify('-1 day')->format('Y-m-d');$weekFrom=$d->modify('-6 days')->format('Y-m-d');
        $summary=function(string $day):array{
            $sales=$this->one("SELECT COALESCE(SUM(CASE WHEN r.status='completed' THEN r.total ELSE -r.total END),0) net_sales,
              SUM(r.status='completed') completed_settlements FROM settlement_records r
              JOIN table_sessions ts ON ts.id=r.session_id WHERE ts.business_date=?",[$day]);
            $invoice=$this->one("SELECT COALESCE(AVG(x.net_total),0) avg_ticket,COUNT(*) invoice_count FROM (
              SELECT r.session_id,SUM(CASE WHEN r.status='completed' THEN r.total WHEN r.status='reversal' THEN -r.total ELSE 0 END) net_total
              FROM settlement_records r JOIN table_sessions ts ON ts.id=r.session_id AND ts.status='closed'
              WHERE ts.business_date=?
                AND EXISTS (SELECT 1 FROM settlement_records c
                            WHERE c.session_id=r.session_id AND c.status='completed' AND c.closes_session=1)
              GROUP BY r.session_id
            ) x",[$day]);
            $orders=$this->one("SELECT COUNT(*) order_count FROM orders WHERE business_date=? AND COALESCE(order_context,'')<>'staff_consumption'",[$day]);
            return ['net_sales'=>(int)($sales['net_sales']??0),'avg_ticket'=>(int)round((float)($invoice['avg_ticket']??0)),'invoice_count'=>(int)($invoice['invoice_count']??0),'completed_settlements'=>(int)($sales['completed_settlements']??0),'order_count'=>(int)($orders['order_count']??0)];
        };
        $daily=$this->all("SELECT ts.business_date business_date,
          COALESCE(SUM(CASE WHEN r.status='completed' THEN r.total ELSE -r.total END),0) net_sales
          FROM settlement_records r JOIN table_sessions ts ON ts.id=r.session_id
          WHERE ts.business_date BETWEEN ? AND ? AND r.status IN('completed','reversal') GROUP BY ts.business_date ORDER BY ts.business_date",[$weekFrom,$today]);
        // Authoritative Local query coverage includes inactive days. Emit those zeros
        // explicitly so Public never has to infer them from a missing projection row.
        $byDay=[];foreach($daily as $row)$byDay[(string)$row['business_date']]=(int)$row['net_sales'];
        $daily=[];for($day=new DateTimeImmutable($weekFrom);$day<=$d;$day=$day->modify('+1 day')){
            $key=$day->format('Y-m-d');$daily[]=['business_date'=>$key,'net_sales'=>$byDay[$key]??0];
        }
        $top=$this->all("SELECT l.item_name_snapshot item_name,SUM(CASE WHEN r.status='completed' THEN l.quantity ELSE -l.quantity END) quantity,COALESCE(SUM(CASE WHEN r.status='completed' THEN l.final_amount ELSE -l.final_amount END),0) net_sales FROM settlement_record_lines l JOIN settlement_records r ON r.id=l.settlement_id JOIN table_sessions ts ON ts.id=r.session_id WHERE ts.business_date=? GROUP BY l.item_name_snapshot ORDER BY net_sales DESC LIMIT 5",[$today]);
        return ['range'=>['from'=>$weekFrom,'to'=>$today],'today_summary'=>$summary($today),'yesterday_summary'=>$summary($yesterday),'daily'=>$daily,'top_items'=>$top,'generated_at'=>$this->time->nowUtcIso()];
    }

    /** Human-readable audit activity report. Raw details_json/source_ip/user_agent are intentionally not exposed here. */
    public function activityReport(?string $from=null,?string $to=null,?int $actorId=null,?string $family=null,?string $query=null,int $limit=40,int $offset=0): array
    {
        [$from,$to]=$this->range($from,$to);
        $fromAt=$from.' 00:00:00';
        $toAt=(new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d').' 00:00:00';
        $limit=max(1,min(100,$limit));$offset=max(0,min(10000,$offset));
        $family=preg_replace('/[^a-z0-9_\-]/','',strtolower(trim((string)$family)))?:'';
        $query=trim((string)$query);if(strlen($query)>120){preg_match('/^.{0,120}/us',$query,$m);$query=(string)($m[0]??'');}
        $where=['a.created_at>=?','a.created_at<?'];$params=[$fromAt,$toAt];
        if($actorId!==null&&$actorId>0){$where[]='a.actor_user_id=?';$params[]=$actorId;}
        if($family!==''){$where[]='a.action LIKE ?';$params[]=$family.'.%';}
        if($query!==''){$like='%'.$query.'%';$where[]='(COALESCE(a.actor_display_name_snapshot,u.display_name,\'\') LIKE ? OR a.action LIKE ? OR a.entity_type LIKE ? OR COALESCE(a.entity_id,\'\') LIKE ?)';array_push($params,$like,$like,$like,$like);}
        $sqlWhere=implode(' AND ',$where);
        $summary=$this->one("SELECT COUNT(*) event_count,COUNT(DISTINCT a.actor_user_id) actor_count,SUM(a.actor_user_id IS NULL) system_events FROM audit_log a LEFT JOIN users u ON u.id=a.actor_user_id WHERE {$sqlWhere}",$params);
        $count=(int)($summary['event_count']??0);
        $q=$this->pdo->prepare("SELECT a.id,a.actor_user_id,COALESCE(NULLIF(a.actor_display_name_snapshot,''),u.display_name,'سامانه') actor_name,a.action,a.entity_type,a.entity_id,a.created_at FROM audit_log a LEFT JOIN users u ON u.id=a.actor_user_id WHERE {$sqlWhere} ORDER BY a.created_at DESC,a.id DESC LIMIT {$limit} OFFSET {$offset}");$q->execute($params);$raw=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
        $rows=[];foreach($raw as $r){$action=(string)($r['action']??'');$dot=strpos($action,'.');$fam=$dot===false?$action:substr($action,0,$dot);$rows[]=['id'=>(int)$r['id'],'actor_user_id'=>$r['actor_user_id']===null?null:(int)$r['actor_user_id'],'actor_name'=>(string)$r['actor_name'],'action'=>$action,'family'=>$fam,'entity_type'=>(string)$r['entity_type'],'entity_id'=>$r['entity_id']===null?null:(string)$r['entity_id'],'created_at_local'=>(string)$r['created_at']];}
        $aq=$this->pdo->prepare('SELECT a.actor_user_id,COALESCE(MAX(NULLIF(a.actor_display_name_snapshot,\'\')),MAX(u.display_name),\'کاربر\') actor_name,COUNT(*) event_count FROM audit_log a LEFT JOIN users u ON u.id=a.actor_user_id WHERE a.created_at>=? AND a.created_at<? AND a.actor_user_id IS NOT NULL GROUP BY a.actor_user_id ORDER BY event_count DESC,actor_name');$aq->execute([$fromAt,$toAt]);$actors=array_map(static fn(array $r):array=>['id'=>(int)$r['actor_user_id'],'name'=>(string)$r['actor_name'],'event_count'=>(int)$r['event_count']],$aq->fetchAll(PDO::FETCH_ASSOC)?:[]);
        $fq=$this->pdo->prepare('SELECT action,COUNT(*) event_count FROM audit_log WHERE created_at>=? AND created_at<? GROUP BY action');$fq->execute([$fromAt,$toAt]);$families=[];foreach($fq->fetchAll(PDO::FETCH_ASSOC)?:[] as $r){$action=(string)$r['action'];$dot=strpos($action,'.');$fam=$dot===false?$action:substr($action,0,$dot);if($fam==='')continue;$families[$fam]=($families[$fam]??0)+(int)$r['event_count'];}arsort($families);$familyRows=[];foreach($families as $key=>$events)$familyRows[]=['key'=>$key,'event_count'=>$events];
        return ['range'=>['from'=>$from,'to'=>$to],'filters'=>['actor_id'=>$actorId&&$actorId>0?$actorId:null,'family'=>$family,'query'=>$query],'summary'=>['events'=>$count,'actors'=>(int)($summary['actor_count']??0),'system_events'=>(int)($summary['system_events']??0)],'actors'=>$actors,'families'=>$familyRows,'rows'=>$rows,'pagination'=>['limit'=>$limit,'offset'=>$offset,'has_more'=>$offset+count($rows)<$count,'next_offset'=>$offset+count($rows)]];
    }


    /** Build current filtered report workbook using current_view_v1 schema. */
    public function currentViewWorkbook(?string $from=null,?string $to=null,bool $includeActivity=false,?int $actorId=null,?string $family=null,?string $query=null): string
    {
        $r=$this->report($from,$to);$range=$r['range'];$summary=$r['summary']??[];$ops=$r['operations']??[];$inventory=$r['inventory']??[];$margin=$r['margin']??[];$financial=$r['financial_context']??[];$tenders=$r['tenders']??[];$bundleUsage=$r['bundle_component_usage']??[];
        $version='unknown';$vf=dirname(__DIR__,3).'/VERSION.txt';if(is_file($vf))$version=trim((string)file_get_contents($vf));
        $readme=[
            ['export_schema','current_view_v1'],['local_version',$version],['generated_at_utc',(string)($r['generated_at']??$this->time->nowUtcIso())],['application_timezone',$this->time->timezone()],['from',(string)($range['from']??'')],['to',(string)($range['to']??'')],
            ['business_day_note','Business Day uses the configured operational cutoff.'],['margin_axis_note','Contribution margin uses the opening table session Business Day, with order Business Day fallback for non-table/legacy orders; revenue is lifetime net settlement revenue for each order_item and cost is its net physical recipe cost.'],['cash_axis_note','Sales are assigned to opening session Business Day; payments, refunds and tender activity use each settlement document Business Day.'],['margin_quality_note','Exact contribution margin requires known inventory cost plus explicit reviewed recipe provenance and known recipe quantity. Provisional, estimated or legacy-unclassified recipe evidence is reported as estimated, never exact.'],['profit_note','Contribution margin is not accounting net profit. Purchases are not treated as COGS; full profit requires opening/closing inventory and complete operating expenses.'],['civil_day_note','Civil/Bank Day is Iran local calendar day 00:00–23:59:59.'],['privacy_note','No secrets, raw audit details, IP addresses, user-agent strings, guest device tokens or authentication tokens are exported.'],
        ];
        $overview=[];$addMetric=static function(string $key,string $label,int|float $value,string $unit='',string $note='')use(&$overview):void{$overview[]=[$key,$label,$value,$unit,$note];};
        $addMetric('net_sales','فروش خالص',(int)($summary['net_sales']??0),'toman','پس از برگشت');$addMetric('avg_ticket','میانگین فاکتور',(int)($summary['avg_ticket']??0),'toman');$addMetric('completed_settlements','تسویه کامل',(int)($summary['completed_settlements']??0),'count');$addMetric('reversals','برگشت سند',(int)($summary['reversals']??0),'count');$addMetric('order_count','سفارش',(int)($summary['order_count']??0),'count');$addMetric('cancelled_orders','سفارش لغوشده',(int)($summary['cancelled_orders']??0),'count');$addMetric('guest_orders','سفارش مهمان',(int)($summary['guest_orders']??0),'count');$addMetric('staff_orders','سفارش پرسنل',(int)($summary['staff_orders']??0),'count');$addMetric('net_expenses','هزینه خالص',(int)($summary['net_expenses']??0),'toman');$addMetric('waiter_calls','فراخوان گارسون',(int)($summary['waiter_calls']??0),'count');$addMetric('completed_waiter_calls','فراخوان تکمیل‌شده',(int)($summary['completed_waiter_calls']??0),'count');
        $sheets=[
            ['name'=>'README','headers'=>['key','value'],'rows'=>$readme],
            ['name'=>'Overview','headers'=>['metric_key','label','raw_value','unit','note'],'rows'=>$overview],
            ['name'=>'Daily','headers'=>['business_date','settlement_count','net_sales_toman'],'rows'=>array_map(static fn(array $x):array=>[(string)$x['business_date'],(int)$x['settlement_count'],(int)$x['net_sales']],$r['daily']??[])],
            ['name'=>'Top_Items','headers'=>['rank','item_name','quantity','net_sales_toman'],'rows'=>array_map(static fn(int $i,array $x):array=>[$i+1,(string)$x['item_name'],(float)$x['quantity'],(int)$x['net_sales']],array_keys($r['top_items']??[]),$r['top_items']??[])],
            ['name'=>'Destinations','headers'=>['destination','settlement_count','net_sales_toman'],'rows'=>array_map(static fn(array $x):array=>[(string)$x['destination'],(int)$x['settlement_count'],(int)$x['net_sales']],$r['destinations']??[])],
        ];
        $orders=$ops['orders']??[];$sessions=$ops['sessions']??[];$waiter=$ops['waiter']??[];
        $sheets[]=['name'=>'Operations','headers'=>['metric_key','raw_value','sample_count','population_count','unit'],'rows'=>[
            ['avg_guest_accept_seconds',(int)($orders['avg_guest_accept_seconds']??0),(int)($orders['acceptance_samples']??0),(int)($orders['guest_non_cancelled']??0),'seconds'],['avg_session_seconds',(int)($sessions['avg_duration_seconds']??0),(int)($sessions['duration_samples']??0),(int)($sessions['total']??0),'seconds'],['avg_waiter_response_seconds',(int)($waiter['avg_response_seconds']??0),(int)($waiter['response_samples']??0),(int)($waiter['total']??0),'seconds'],['avg_waiter_completion_seconds',(int)($waiter['avg_completion_seconds']??0),(int)($waiter['completion_samples']??0),(int)($waiter['total']??0),'seconds'],
        ]];
        foreach($orders['statuses']??[] as $x)$sheets[count($sheets)-1]['rows'][]=['order_status:'.(string)$x['status'],(int)$x['count'],null,null,'count'];
        $sheets[]=['name'=>'Operation_Shifts','headers'=>['business_date','shift_key','shift_label','orders','guest_orders','staff_orders','cancelled_orders','sessions','closed_sessions','waiter_calls','completed_waiter_calls'],'rows'=>array_map(static fn(array $x):array=>[(string)$x['date'],(string)$x['shift_key'],(string)$x['shift_label'],(int)$x['orders'],(int)$x['guest_orders'],(int)$x['staff_orders'],(int)$x['cancelled_orders'],(int)$x['sessions'],(int)$x['closed_sessions'],(int)$x['waiter_calls'],(int)$x['completed_waiter_calls']],$ops['shifts']??[])];
        $biz=[];foreach($tenders['business']['totals']??[] as $x)$biz[]=['total','',(string)$x['type'],(int)$x['document_count'],(int)$x['net_amount']];foreach($tenders['business']['daily']??[] as $x)$biz[]=['daily',(string)$x['date'],(string)$x['type'],null,(int)$x['net_amount']];
        $civ=[];foreach($tenders['civil']['totals']??[] as $x)$civ[]=['total','',(string)$x['type'],(int)$x['document_count'],(int)$x['net_amount']];foreach($tenders['civil']['daily']??[] as $x)$civ[]=['daily',(string)$x['date'],(string)$x['type'],null,(int)$x['net_amount']];
        $sheets[]=['name'=>'Tenders_Business','headers'=>['level','business_date','tender_type','document_count','net_amount_toman'],'rows'=>$biz];
        $sheets[]=['name'=>'Tenders_Civil','headers'=>['level','civil_date','tender_type','document_count','net_amount_toman'],'rows'=>$civ];
        $sheets[]=['name'=>'Tender_Shifts','headers'=>['business_date','shift_key','shift_label','tender_type','net_amount_toman'],'rows'=>array_map(static fn(array $x):array=>[(string)$x['date'],(string)$x['shift_key'],(string)$x['shift_label'],(string)$x['type'],(int)$x['net_amount']],$tenders['business']['shifts']??[])];
        $recipe=$inventory['recipe_consumption']??[];$waste=$inventory['waste']??[];$purchase=$inventory['purchases']??[];$count=$inventory['count_variance']??[];$stock=$inventory['current_stock']??[];$recipeReady=$inventory['recipe_readiness']??[];$countContext=$inventory['count_context']??[];$recon=$inventory['count_reconciliation']??[];
        $sheets[]=['name'=>'Inventory_Cost','headers'=>['metric_key','covered_value_toman','known_samples','estimated_samples','unknown_samples','population_count','note'],'rows'=>[
            ['recipe_consumption',(int)($recipe['covered_cost']??0),(int)($recipe['exact_basis_samples']??0),(int)($recipe['estimated_basis_samples']??0),(int)($recipe['unknown_samples']??0),(int)($recipe['movements']??0),'net recipe consumption/reversal; exact basis requires known cost + reviewed recipe + known quantity'],
            ['waste',(int)($waste['covered_cost']??0),(int)($waste['known_samples']??0),(int)($waste['estimated_samples']??0),(int)($waste['unknown_samples']??0),(int)($waste['movements']??0),'unknown cost is excluded'],
            ['purchases',(int)($purchase['recorded_spend']??0),(int)($purchase['costed_receipts']??0),(int)(($purchase['over_plan_receipts']??0)+($purchase['under_plan_receipts']??0)),(int)($purchase['missing_cost_receipts']??0),(int)($purchase['receipts']??0),'recorded receipt spend; purchase-plan variance is non-blocking'],
            ['count_variance',(int)($count['net_value_delta']??0),(int)($count['known_samples']??0),(int)($count['estimated_samples']??0),(int)($count['unknown_samples']??0),(int)($count['movements']??0),'net variance; absolute value available in UI'],
            ['current_stock',(int)($stock['covered_value']??0),(int)($stock['known_items']??0),(int)($stock['estimated_items']??0),(int)(($stock['unknown_items']??0)+($stock['partial_items']??0)),(int)($stock['items']??0),'current balance snapshot, not date-range flow'],
            ['recipe_readiness',0,(int)($recipeReady['reviewed_recipes']??0),(int)($recipeReady['partial_recipes']??0),(int)($recipeReady['without_recipe']??0),(int)($recipeReady['menu_items']??0),'current active catalog: reviewed / partial / no active recipe'],
            ['count_context',0,(int)($countContext['full_counts_in_range']??0),0,!empty($countContext['two_sided_full_count_context'])?0:1,0,'full physical counts; two-sided count context is required before count-bounded reconciliation'],
            ['count_bounded_cogs',(int)($recon['covered_formula_value']??0),(string)($recon['status']??'not_anchored')==='exact'?1:0,(string)($recon['status']??'')==='estimated'?1:0,(string)($recon['status']??'not_anchored')==='incomplete'?1:0,0,'opening inventory + costed purchases - closing inventory; interval is count-to-count, not necessarily requested report range'],
        ]];
        $ms=$margin['summary']??[];
        $sheets[]=['name'=>'Contribution_Margin','headers'=>['metric_key','raw_value','unit','note'],'rows'=>[
            ['order_items',(int)($ms['order_items']??0),'count','order Business Day cohort'],['margin_available',(int)($ms['margin_available']??0),'count','financially complete + no unknown cost'],['coverage_percent',(int)($ms['coverage_percent']??0),'percent','margin-available order items / cohort'],['exact_margin_rows',(int)($ms['exact_margin_rows']??0),'count','known cost + reviewed recipe + known recipe quantity'],['estimated_margin_rows',(int)($ms['estimated_margin_rows']??0),'count','covered but recipe/cost provenance is estimated or legacy-unclassified'],['covered_revenue',(int)($ms['covered_revenue']??0),'toman','net revenue for margin-covered order items'],['covered_cost',(int)($ms['covered_cost']??0),'toman','covered physical recipe cost; unknown cost excluded'],['gross_margin',(int)($ms['gross_margin']??0),'toman','covered contribution margin; not accounting net profit'],['exact_gross_margin',(int)($ms['exact_gross_margin']??0),'toman','contribution margin with exact basis'],['estimated_gross_margin',(int)($ms['estimated_gross_margin']??0),'toman','contribution margin with estimated/provisional basis'],['provisional_recipe_rows',(int)($ms['provisional_recipe_rows']??0),'count','recipe review_status=needs_review in physical movement provenance'],['estimated_quantity_rows',(int)($ms['estimated_quantity_rows']??0),'count','recipe component quantity_status=estimated'],['incomplete_recipe_rows',(int)($ms['incomplete_recipe_rows']??0),'count','recipe completeness is partial/unknown and margin is excluded'],['unclassified_provenance_rows',(int)($ms['unclassified_provenance_rows']??0),'count','legacy movement lacks explicit recipe provenance'],['incomplete_cost_rows',(int)($ms['incomplete_cost_rows']??0),'count','excluded from contribution margin'],['migrated_category_rows',(int)($ms['migrated_category_rows']??0),'count','category backfilled from current catalog, not historical-exact'],
        ]];
        $sheets[]=['name'=>'Margin_Items','headers'=>['item_id','item_name','ordered_quantity','margin_rows','incomplete_rows','net_revenue_toman','covered_cost_toman','gross_margin_toman','cost_quality'],'rows'=>array_map(static fn(array $x):array=>[$x['item_id'],(string)$x['item_name'],(int)$x['quantity'],(int)$x['margin_rows'],(int)$x['incomplete_rows'],(int)$x['net_revenue'],(int)$x['covered_cost'],(int)$x['gross_margin'],(string)$x['cost_quality']],$margin['items']??[])];
        $sheets[]=['name'=>'Margin_Categories','headers'=>['category_id','category_name','margin_rows','incomplete_rows','net_revenue_toman','covered_cost_toman','gross_margin_toman','exact_classification_rows','migrated_classification_rows','unknown_classification_rows'],'rows'=>array_map(static fn(array $x):array=>[$x['category_id'],(string)$x['category_name'],(int)$x['margin_rows'],(int)$x['incomplete_rows'],(int)$x['net_revenue'],(int)$x['covered_cost'],(int)$x['gross_margin'],(int)$x['exact_classification_rows'],(int)$x['migrated_classification_rows'],(int)$x['unknown_classification_rows']],$margin['categories']??[])];
        $sheets[]=['name'=>'Bundle_Component_Usage','headers'=>['component_item_id','component_name','component_quantity','bundle_line_count','parent_bundle_count'],'rows'=>array_map(static fn(array $x):array=>[(int)$x['component_item_id'],(string)$x['component_name'],(int)$x['component_quantity'],(int)$x['bundle_line_count'],(int)$x['parent_bundle_count']],$bundleUsage)];
        $sub=$financial['subscriber']??[];$acc=$financial['accommodation']??[];
        $sheets[]=['name'=>'Financial_Context','headers'=>['context','documents','entities','transfers','net_charged_toman','posted','pending','failed','voided','attention'],'rows'=>[
            ['subscriber',(int)($sub['documents']??0),(int)($sub['customers']??0),null,(int)($sub['net_charged']??0),null,null,null,null,null],
            ['accommodation',(int)($acc['documents']??0),(int)($acc['reservations']??0),(int)($acc['transfers']??0),(int)($acc['net_charged']??0),(int)($acc['posted']??0),(int)($acc['pending']??0),(int)($acc['failed']??0),(int)($acc['voided']??0),(int)($acc['attention']??0)],
        ]];
        if($includeActivity){$first=$this->activityReport((string)$range['from'],(string)$range['to'],$actorId,$family,$query,100,0);$total=(int)($first['summary']['events']??0);if($total>2000)throw new ReportingException('export_too_large','رخدادهای این بازه برای خروجی فعلی بیش از حد زیاد است؛ بازه یا فیلتر فعالیت را محدود کنید.');$activityRows=$first['rows']??[];$offset=count($activityRows);while($offset<$total){$page=$this->activityReport((string)$range['from'],(string)$range['to'],$actorId,$family,$query,100,$offset);$rows=$page['rows']??[];if($rows===[])break;array_push($activityRows,...$rows);$offset+=count($rows);} $sheets[]=['name'=>'Audit_Activity','headers'=>['audit_id','actor_user_id','actor_name','action','family','entity_type','entity_id','created_at_local'],'rows'=>array_map(static fn(array $x):array=>[(int)$x['id'],$x['actor_user_id'],(string)$x['actor_name'],(string)$x['action'],(string)$x['family'],(string)$x['entity_type'],$x['entity_id'],(string)$x['created_at_local']],$activityRows)];}
        $sheets=array_merge($sheets,PersianReportExport::current($r));
        return XlsxWorkbook::build($sheets,['created_at_utc'=>(string)($r['generated_at']??$this->time->nowUtcIso()),'title'=>'SOKNA current report']);
    }

    /**
     * Build the privacy-bounded analytical_v1 workbook for AI/offline analysis.
     * No sheet is silently truncated: counts are checked before any detailed rows are loaded.
     */
    public function analyticalWorkbook(?string $from=null,?string $to=null): string
    {
        [$from,$to]=$this->range($from,$to);
        $fromAt=$from.' 00:00:00';$toAt=(new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d').' 00:00:00';
        $businessParams=[$from,$to];$localParams=[$fromAt,$toAt];
        $sheetLimit=10000;$totalLimit=25000;
        $specs=[
            [
                'name'=>'Settlements',
                'headers'=>['settlement_id','session_id','financial_period_id','invoice_number','destination','table_name_snapshot','subtotal_toman','discount_toman','taxable_amount_toman','tax_amount_toman','total_toman','status','reverses_settlement_id','actor_user_id','subscriber_ledger_entry_id','accommodation_transfer_id','final_print_job_id','settlement_kind','tender_capture_status','closes_session','remaining_subtotal_toman','remaining_discount_toman','remaining_tax_toman','remaining_total_toman','allocation_version','settled_at_local','settled_at_utc','civil_date','business_date','business_shift_key','business_shift_label','business_cutoff_snapshot','voided_by_user_id','voided_at_local','created_at_local','updated_at_local','partial_reversal_source_id','partial_reversal_order_id'],
                'count'=>'SELECT COUNT(*) c FROM settlement_records WHERE business_date BETWEEN ? AND ?',
                'sql'=>'SELECT id,session_id,financial_period_id,invoice_number,destination,table_name_snapshot,subtotal,discount,taxable_amount,tax_amount,total,status,reverses_settlement_id,actor_user_id,subscriber_ledger_entry_id,accommodation_transfer_id,final_print_job_id,settlement_kind,tender_capture_status,closes_session,remaining_subtotal,remaining_discount,remaining_tax,remaining_total,allocation_version,settled_at,settled_at_utc,civil_date,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot,voided_by_user_id,voided_at,created_at,updated_at,partial_reversal_source_id,partial_reversal_order_id FROM settlement_records WHERE business_date BETWEEN ? AND ? ORDER BY business_date,id',
                'params'=>$businessParams,'ints'=>[0,1,2,6,7,8,9,10,12,13,14,15,16,19,20,21,22,23,24,32,36,37],'floats'=>[],'bools'=>[],
            ],
            [
                'name'=>'Settlement_Lines',
                'headers'=>['line_id','settlement_id','order_item_id','order_id','item_id_snapshot','item_name_snapshot','unit_price_snapshot_toman','quantity','gross_amount_toman','discount_amount_toman','net_amount_toman','taxable_amount_toman','tax_rate_bps','tax_amount_toman','final_amount_toman','created_at_local'],
                'count'=>'SELECT COUNT(*) c FROM settlement_record_lines l JOIN settlement_records r ON r.id=l.settlement_id WHERE r.business_date BETWEEN ? AND ?',
                'sql'=>'SELECT l.id,l.settlement_id,l.order_item_id,l.order_id,l.item_id_snapshot,l.item_name_snapshot,l.unit_price_snapshot,l.quantity,l.gross_amount,l.discount_amount,l.net_amount,l.taxable_amount,l.tax_rate_bps,l.tax_amount,l.final_amount,l.created_at FROM settlement_record_lines l JOIN settlement_records r ON r.id=l.settlement_id WHERE r.business_date BETWEEN ? AND ? ORDER BY l.settlement_id,l.id',
                'params'=>$businessParams,'ints'=>[0,1,2,3,4,6,7,8,9,10,11,12,13,14],'floats'=>[],'bools'=>[],
            ],
            [
                'name'=>'Tenders',
                'headers'=>['tender_id','settlement_id','tender_type','amount_toman','created_at_local'],
                'count'=>'SELECT COUNT(*) c FROM settlement_tender_allocations t JOIN settlement_records r ON r.id=t.settlement_id WHERE r.business_date BETWEEN ? AND ?',
                'sql'=>'SELECT t.id,t.settlement_id,t.tender_type,t.amount,t.created_at FROM settlement_tender_allocations t JOIN settlement_records r ON r.id=t.settlement_id WHERE r.business_date BETWEEN ? AND ? ORDER BY t.settlement_id,t.id',
                'params'=>$businessParams,'ints'=>[0,1,3],'floats'=>[],'bools'=>[],
            ],
            [
                'name'=>'Orders',
                'headers'=>['order_id','table_id','session_id','order_source','status','total_amount_toman','accepted_at_local','accepted_by_user_id','created_by_user_id','business_order_number','business_date','business_shift_key','business_shift_label','business_cutoff_snapshot','created_at_local','updated_at_local'],
                'count'=>'SELECT COUNT(*) c FROM orders WHERE business_date BETWEEN ? AND ?',
                'sql'=>'SELECT id,table_id,session_id,order_source,status,total_amount,accepted_at,accepted_by_user_id,created_by_user_id,business_order_number,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot,created_at,updated_at FROM orders WHERE business_date BETWEEN ? AND ? ORDER BY business_date,business_order_number,id',
                'params'=>$businessParams,'ints'=>[0,1,2,5,7,8,9],'floats'=>[],'bools'=>[],
            ],
            [
                'name'=>'Order_Lines',
                'headers'=>['order_item_id','order_id','item_id','category_id_snapshot','category_name_snapshot','category_snapshot_quality','item_name','sellable_kind_snapshot','unit_price_toman','quantity','ordered_quantity','fulfillment_mode','preparation_station','line_total_toman','created_at_local'],
                'count'=>'SELECT COUNT(*) c FROM order_items i JOIN orders o ON o.id=i.order_id WHERE o.business_date BETWEEN ? AND ?',
                'sql'=>'SELECT i.id,i.order_id,i.item_id,i.category_id_snapshot,i.category_name_snapshot,i.category_snapshot_quality,i.item_name,i.sellable_kind_snapshot,i.unit_price,i.quantity,i.ordered_quantity,i.fulfillment_mode,i.preparation_station,i.line_total,i.created_at FROM order_items i JOIN orders o ON o.id=i.order_id WHERE o.business_date BETWEEN ? AND ? ORDER BY i.order_id,i.id',
                'params'=>$businessParams,'ints'=>[0,1,2,3,8,9,10,13],'floats'=>[],'bools'=>[],
            ],
            [
                'name'=>'Bundle_Components',
                'headers'=>['bundle_component_id','order_item_id','order_id','parent_item_id','parent_item_name','component_item_id','component_item_name','quantity','preparation_station','component_mode','choice_label','business_date','created_at_local'],
                'count'=>'SELECT COUNT(*) c FROM order_item_bundle_components c JOIN order_items i ON i.id=c.order_item_id JOIN orders o ON o.id=i.order_id WHERE o.business_date BETWEEN ? AND ?',
                'sql'=>'SELECT c.id,c.order_item_id,i.order_id,i.item_id,i.item_name,c.component_item_id,c.item_name_snapshot,c.quantity,c.preparation_station_snapshot,c.component_mode,c.choice_label_snapshot,o.business_date,c.created_at FROM order_item_bundle_components c JOIN order_items i ON i.id=c.order_item_id JOIN orders o ON o.id=i.order_id WHERE o.business_date BETWEEN ? AND ? ORDER BY o.business_date,i.order_id,i.id,c.id',
                'params'=>$businessParams,'ints'=>[0,1,2,3,5,7],'floats'=>[],'bools'=>[],
            ],
            [
                'name'=>'Expenses',
                'headers'=>['expense_id','financial_period_id','category_key','amount_toman','occurred_at_local','committed_at_local','actor_user_id','status','reverses_expense_id','created_at_local'],
                'count'=>'SELECT COUNT(*) c FROM expenses WHERE occurred_at>=? AND occurred_at<?',
                'sql'=>'SELECT id,financial_period_id,category_key,amount,occurred_at,committed_at,actor_user_id,status,reverses_expense_id,created_at FROM expenses WHERE occurred_at>=? AND occurred_at<? ORDER BY occurred_at,id',
                'params'=>$localParams,'ints'=>[0,1,3,6,8],'floats'=>[],'bools'=>[],
            ],
            [
                'name'=>'Inventory_Movements',
                'headers'=>['movement_id','inventory_item_id','movement_type','quantity_base','base_unit','department','purchase_unit_id','purchase_unit_name_snapshot','purchase_unit_count','conversion_base_quantity_snapshot','unit_cost_snapshot','total_cost_delta_toman','cost_status','source_type','source_id','correction_of_id','reversal_of_id','actor_user_id','occurred_at_local','created_at_local'],
                'count'=>'SELECT COUNT(*) c FROM inventory_movements WHERE occurred_at>=? AND occurred_at<?',
                'sql'=>'SELECT id,inventory_item_id,movement_type,quantity_base,base_unit,department,purchase_unit_id,purchase_unit_name_snapshot,purchase_unit_count,conversion_base_quantity_snapshot,unit_cost_snapshot,total_cost_delta,cost_status,source_type,source_id,correction_of_id,reversal_of_id,actor_user_id,occurred_at,created_at FROM inventory_movements WHERE occurred_at>=? AND occurred_at<? ORDER BY occurred_at,id',
                'params'=>$localParams,'ints'=>[0,1,3,6,9,11,15,16,17],'floats'=>[8,10],'bools'=>[],
            ],
            [
                'name'=>'Customers',
                'headers'=>['customer_id','active','current_balance_toman','created_by_user_id','updated_by_user_id','created_at_local','updated_at_local'],
                'count'=>"SELECT COUNT(DISTINCT sl.subscriber_id) c FROM settlement_records r JOIN subscriber_ledger sl ON sl.id=r.subscriber_ledger_entry_id WHERE r.business_date BETWEEN ? AND ? AND r.subscriber_ledger_entry_id IS NOT NULL",
                'sql'=>"SELECT s.id,s.active,(SELECT sl2.balance_after FROM subscriber_ledger sl2 WHERE sl2.subscriber_id=s.id ORDER BY sl2.created_at DESC,sl2.id DESC LIMIT 1) current_balance,s.created_by_user_id,s.updated_by_user_id,s.created_at,s.updated_at FROM subscribers s JOIN (SELECT DISTINCT sl.subscriber_id FROM settlement_records r JOIN subscriber_ledger sl ON sl.id=r.subscriber_ledger_entry_id WHERE r.business_date BETWEEN ? AND ? AND r.subscriber_ledger_entry_id IS NOT NULL) x ON x.subscriber_id=s.id ORDER BY s.id",
                'params'=>$businessParams,'ints'=>[0,2,3,4],'floats'=>[],'bools'=>[1],
            ],
            [
                'name'=>'Accommodation_Transfers',
                'headers'=>['transfer_id','session_id','financial_period_id','reservation_code','room_name_snapshot','amount_toman','invoice_number','status','operator_user_id','void_requested_by_user_id','voided_by_user_id','attempt_count','last_attempt_at_local','posted_at_local','voided_at_local','last_error_code','suspicious_response','local_finalize_pending','local_reversal_pending','resolved_at_local','resolved_by_user_id','resolution_method','created_at_local','updated_at_local'],
                'count'=>'SELECT COUNT(DISTINCT t.id) c FROM accommodation_transfers t JOIN settlement_records r ON r.accommodation_transfer_id=t.id WHERE r.business_date BETWEEN ? AND ?',
                'sql'=>'SELECT DISTINCT t.id,t.session_id,t.financial_period_id,t.reservation_code,t.room_name_snapshot,t.amount,t.invoice_number,t.status,t.operator_user_id,t.void_requested_by_user_id,t.voided_by_user_id,t.attempt_count,t.last_attempt_at,t.posted_at,t.voided_at,t.last_error_code,t.suspicious_response,t.local_finalize_pending,t.local_reversal_pending,t.resolved_at,t.resolved_by_user_id,t.resolution_method,t.created_at,t.updated_at FROM accommodation_transfers t JOIN settlement_records r ON r.accommodation_transfer_id=t.id WHERE r.business_date BETWEEN ? AND ? ORDER BY t.id',
                'params'=>$businessParams,'ints'=>[0,1,2,5,8,9,10,11,20],'floats'=>[],'bools'=>[16,17,18],
            ],
            [
                'name'=>'Waiter_Calls',
                'headers'=>['waiter_call_id','table_id','session_id','status','accepted_by_user_id','accepted_at_local','completed_at_local','cancelled_at_local','cancel_reason_code','cancelled_by_user_id','business_date','business_shift_key','business_shift_label','business_cutoff_snapshot','created_at_local','updated_at_local'],
                'count'=>'SELECT COUNT(*) c FROM waiter_calls WHERE business_date BETWEEN ? AND ?',
                'sql'=>'SELECT id,table_id,session_id,status,accepted_by_user_id,accepted_at,completed_at,cancelled_at,cancel_reason,cancelled_by_user_id,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot,created_at,updated_at FROM waiter_calls WHERE business_date BETWEEN ? AND ? ORDER BY business_date,id',
                'params'=>$businessParams,'ints'=>[0,1,2,4,9],'floats'=>[],'bools'=>[],
            ],
            [
                'name'=>'Audit_Activity',
                'headers'=>['audit_id','actor_user_id','actor_name','action','entity_type','entity_id','created_at_local'],
                'count'=>'SELECT COUNT(*) c FROM audit_log WHERE created_at>=? AND created_at<?',
                'sql'=>"SELECT a.id,a.actor_user_id,COALESCE(NULLIF(a.actor_display_name_snapshot,''),u.display_name,'سامانه') actor_name,a.action,a.entity_type,a.entity_id,a.created_at FROM audit_log a LEFT JOIN users u ON u.id=a.actor_user_id WHERE a.created_at>=? AND a.created_at<? ORDER BY a.created_at,a.id",
                'params'=>$localParams,'ints'=>[0,1],'floats'=>[],'bools'=>[],
            ],
        ];
        $counts=[];$total=0;
        foreach($specs as $spec){$c=(int)($this->one($spec['count'],$spec['params'])['c']??0);if($c>$sheetLimit)throw new ReportingException('export_too_large','دادهٔ خام این بازه برای فایل تحلیلی زیاد است؛ بازه را کوچک‌تر کنید (حداکثر ۱۰٬۰۰۰ ردیف در هر شیت).');$counts[$spec['name']]=$c;$total+=$c;}
        if($total>$totalLimit)throw new ReportingException('export_too_large','حجم کل دادهٔ خام این بازه برای یک فایل تحلیلی زیاد است؛ بازه را کوچک‌تر کنید (حداکثر ۲۵٬۰۰۰ ردیف در کل شیت‌های جزئی).');
        $version='unknown';$vf=dirname(__DIR__,3).'/VERSION.txt';if(is_file($vf))$version=trim((string)file_get_contents($vf));
        $readme=[
            ['export_schema','analytical_v1'],['local_version',$version],['generated_at_utc',$this->time->nowUtcIso()],['application_timezone',$this->time->timezone()],['from',$from],['to',$to],['row_limit_per_sheet',$sheetLimit],['row_limit_total',$totalLimit],['row_count_total',$total],
            ['range_note_business','Settlements, lines, tenders, orders, order lines, customers, accommodation transfers and waiter calls follow Business Day.'],['range_note_local','Expenses, inventory movements and audit activity use Iran local wall-clock occurrence/creation time.'],['timestamp_note','Columns ending _utc are canonical UTC snapshots. Columns ending _local are database/application local wall-clock values and must not be reinterpreted as UTC.'],['cost_note','Inventory cost_status is preserved; unknown/estimated evidence must not be treated as known zero cost.'],['customer_privacy','Customer names and mobile numbers are intentionally omitted; stable customer IDs are exported for relational analysis.'],['privacy_note','No passwords, auth/device/client tokens, request IDs/fingerprints, free-text order notes, raw payload JSON, IP/user-agent, guest identity/phone, remote transaction IDs or raw integration errors are exported.'],['completeness_note','No sheet is silently truncated. If safety limits are exceeded the export fails and asks for a smaller range.'],
        ];
        foreach($counts as $name=>$count)$readme[]=['rows.'.$name,$count];
        $sheets=[['name'=>'README','headers'=>['key','value'],'rows'=>$readme]];
        foreach($specs as $spec){$raw=$this->allNumeric($spec['sql'],$spec['params']);$rows=[];foreach($raw as $row)$rows[]=$this->typedAnalyticalRow($row,$spec['ints'],$spec['floats'],$spec['bools']);$sheets[]=['name'=>$spec['name'],'headers'=>$spec['headers'],'rows'=>$rows];}
        $sheets=array_merge($sheets,PersianReportExport::analytical($sheets,$from,$to));
        return XlsxWorkbook::build($sheets,['created_at_utc'=>$this->time->nowUtcIso(),'title'=>'SOKNA analytical export']);
    }

    /** @return list<list<mixed>> */
    private function allNumeric(string $sql,array $params): array
    {
        $q=$this->pdo->prepare($sql);$q->execute($params);$rows=$q->fetchAll(PDO::FETCH_NUM)?:[];return array_values(array_filter($rows,'is_array'));
    }

    /** @param list<mixed> $row @param list<int> $ints @param list<int> $floats @param list<int> $bools @return list<mixed> */
    private function typedAnalyticalRow(array $row,array $ints,array $floats,array $bools): array
    {
        foreach($ints as $i)if(array_key_exists($i,$row)&&$row[$i]!==null&&$row[$i]!=='')$row[$i]=(int)$row[$i];
        foreach($floats as $i)if(array_key_exists($i,$row)&&$row[$i]!==null&&$row[$i]!=='')$row[$i]=(float)$row[$i];
        foreach($bools as $i)if(array_key_exists($i,$row)&&$row[$i]!==null&&$row[$i]!=='')$row[$i]=((int)$row[$i])===1;
        return array_values($row);
    }

    public function remoteSummary(): array{$to=$this->time->civilDate();$from=(new DateTimeImmutable($to))->modify('-6 days')->format('Y-m-d');$r=$this->report($from,$to);return ['range'=>$r['range'],'summary'=>$r['summary'],'daily'=>$r['daily'],'top_items'=>array_slice($r['top_items'],0,8),'generated_at'=>$r['generated_at'],'civil_date'=>$r['civil_date']];}

    /**
     * Contribution margin follows the SALE day, not the receipt or individual order day.
     * For a table account lasting multiple days the sale belongs to the session-opening
     * business date. Each separately placed order keeps its own immutable business_date
     * for operational numbering/audit; non-table and legacy orders retain that date.
     * Revenue remains lifetime completed-minus-reversed financial lines by order_item_id;
     * COGS remains the net recipe cost linked to that same line, never payment-day cost.
     */
    private function marginReport(string $from,string $to): array
    {
        $sql="SELECT oi.id order_item_id,oi.item_id,oi.item_name,oi.sellable_kind_snapshot,oi.quantity ordered_quantity,
                     oi.category_id_snapshot,oi.category_name_snapshot,oi.category_snapshot_quality,COALESCE(ts.business_date,o.business_date) business_date,o.status order_status,
                     COALESCE(fin.settled_quantity,0) settled_quantity,COALESCE(fin.net_revenue,0) net_revenue,
                     COALESCE(cost.cost_movements,0) cost_movements,COALESCE(cost.known_samples,0) known_samples,
                     COALESCE(cost.estimated_samples,0) estimated_samples,COALESCE(cost.unknown_samples,0) unknown_samples,
                     COALESCE(cost.exact_basis_samples,0) exact_basis_samples,COALESCE(cost.estimated_basis_samples,0) estimated_basis_samples,
                     COALESCE(cost.provisional_recipe_samples,0) provisional_recipe_samples,
                     COALESCE(cost.estimated_quantity_samples,0) estimated_quantity_samples,
                     COALESCE(cost.unclassified_provenance_samples,0) unclassified_provenance_samples,
                     COALESCE(cost.incomplete_recipe_samples,0) incomplete_recipe_samples,
                     COALESCE(cost.known_cost,0) known_cost,COALESCE(cost.estimated_cost,0) estimated_cost,
                     COALESCE(cost.exact_basis_cost,0) exact_basis_cost,COALESCE(cost.estimated_basis_cost,0) estimated_basis_cost
              FROM order_items oi JOIN orders o ON o.id=oi.order_id
              LEFT JOIN table_sessions ts ON ts.id=o.session_id AND o.order_context='table_service'
              LEFT JOIN (
                  SELECT l.order_item_id,
                         COALESCE(SUM(CASE WHEN r.status='completed' THEN l.quantity ELSE -l.quantity END),0) settled_quantity,
                         COALESCE(SUM(CASE WHEN r.status='completed' THEN l.net_amount ELSE -l.net_amount END),0) net_revenue
                  FROM settlement_record_lines l JOIN settlement_records r ON r.id=l.settlement_id
                  WHERE r.status IN('completed','reversal') GROUP BY l.order_item_id
              ) fin ON fin.order_item_id=oi.id
              LEFT JOIN (
                  SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.order_item_id')) AS UNSIGNED) order_item_id,
                         COUNT(*) cost_movements,
                         SUM(cost_status='known' AND total_cost_delta IS NOT NULL) known_samples,
                         SUM(cost_status='estimated' AND total_cost_delta IS NOT NULL) estimated_samples,
                         SUM(cost_status='unknown' OR total_cost_delta IS NULL) unknown_samples,
                         SUM(CASE WHEN cost_status='known' AND total_cost_delta IS NOT NULL
                                      AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_review_status')),'')='ready'
                                      AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_completeness_status')),'unknown')='complete'
                                      AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_quantity_status')),'')='known'
                                  THEN 1 ELSE 0 END) exact_basis_samples,
                         SUM(CASE WHEN cost_status IN('known','estimated') AND total_cost_delta IS NOT NULL
                                      AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_completeness_status')),'unknown')='complete'
                                      AND NOT(cost_status='known'
                                          AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_review_status')),'')='ready'
                                          AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_completeness_status')),'unknown')='complete'
                                          AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_quantity_status')),'')='known')
                                  THEN 1 ELSE 0 END) estimated_basis_samples,
                         SUM(CASE WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_review_status')),'')='needs_review' THEN 1 ELSE 0 END) provisional_recipe_samples,
                         SUM(CASE WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_quantity_status')),'')='estimated' THEN 1 ELSE 0 END) estimated_quantity_samples,
                         SUM(CASE WHEN JSON_EXTRACT(metadata_json,'$.recipe_review_status') IS NULL
                                       OR JSON_EXTRACT(metadata_json,'$.recipe_quantity_status') IS NULL THEN 1 ELSE 0 END) unclassified_provenance_samples,
                         SUM(CASE WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_completeness_status')),'unknown')<>'complete' THEN 1 ELSE 0 END) incomplete_recipe_samples,
                         COALESCE(SUM(CASE WHEN cost_status='known' AND total_cost_delta IS NOT NULL THEN -total_cost_delta ELSE 0 END),0) known_cost,
                         COALESCE(SUM(CASE WHEN cost_status='estimated' AND total_cost_delta IS NOT NULL THEN -total_cost_delta ELSE 0 END),0) estimated_cost,
                         COALESCE(SUM(CASE WHEN cost_status='known' AND total_cost_delta IS NOT NULL
                                               AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_review_status')),'')='ready'
                                               AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_completeness_status')),'unknown')='complete'
                                               AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_quantity_status')),'')='known'
                                           THEN -total_cost_delta ELSE 0 END),0) exact_basis_cost,
                         COALESCE(SUM(CASE WHEN cost_status IN('known','estimated') AND total_cost_delta IS NOT NULL
                                               AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_completeness_status')),'unknown')='complete'
                                               AND NOT(cost_status='known'
                                                   AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_review_status')),'')='ready'
                                                   AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_completeness_status')),'unknown')='complete'
                                                   AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_quantity_status')),'')='known')
                                           THEN -total_cost_delta ELSE 0 END),0) estimated_basis_cost
                  FROM inventory_movements
                  WHERE movement_type IN('recipe_consumption','reversal')
                    AND source_type IN('order_item','order_item_adjustment')
                    AND JSON_EXTRACT(metadata_json,'$.order_item_id') IS NOT NULL
                  GROUP BY CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.order_item_id')) AS UNSIGNED)
              ) cost ON cost.order_item_id=oi.id
              WHERE COALESCE(ts.business_date,o.business_date) BETWEEN ? AND ? AND o.status<>'cancelled'
              ORDER BY COALESCE(ts.business_date,o.business_date),oi.id";
        $q=$this->pdo->prepare($sql);$q->execute([$from,$to]);$raw=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
        $summary=['order_items'=>0,'financially_complete'=>0,'margin_available'=>0,'exact_margin_rows'=>0,'estimated_margin_rows'=>0,
                  'exact_cost_rows'=>0,'estimated_cost_rows'=>0,'incomplete_cost_rows'=>0,'provisional_recipe_rows'=>0,
                  'estimated_quantity_rows'=>0,'unclassified_provenance_rows'=>0,'incomplete_recipe_rows'=>0,'exact_category_rows'=>0,'migrated_category_rows'=>0,
                  'unknown_category_rows'=>0,'covered_revenue'=>0,'covered_cost'=>0,'gross_margin'=>0,
                  'exact_revenue'=>0,'exact_cost'=>0,'exact_gross_margin'=>0,'estimated_revenue'=>0,'estimated_basis_cost'=>0,'estimated_gross_margin'=>0];
        $rows=[];$items=[];$categories=[];
        foreach($raw as $r){
            $ordered=max(0,(int)$r['ordered_quantity']);$settled=(int)$r['settled_quantity'];$movements=(int)$r['cost_movements'];
            $known=(int)$r['known_samples'];$estimated=(int)$r['estimated_samples'];$unknown=(int)$r['unknown_samples'];
            $exactBasis=(int)$r['exact_basis_samples'];$estimatedBasis=(int)$r['estimated_basis_samples'];
            $provisional=(int)$r['provisional_recipe_samples'];$estimatedQty=(int)$r['estimated_quantity_samples'];$unclassified=(int)$r['unclassified_provenance_samples'];$incompleteRecipe=(int)$r['incomplete_recipe_samples'];
            $service=(string)($r['sellable_kind_snapshot']??'menu_item')==='service_item';
            if(!$service&&$movements===0)$unknown=max(1,$unknown);
            $financiallyComplete=$ordered>0&&$settled===$ordered;$costComplete=$unknown===0&&$incompleteRecipe===0;
            $quality=$costComplete?($service||($movements>0&&$estimatedBasis===0&&$exactBasis>0)?'known':'estimated'):'incomplete';
            if(!$service&&$movements===0)$quality='incomplete';
            $available=$financiallyComplete&&$costComplete&&($service||$movements>0);
            $revenue=(int)$r['net_revenue'];$cost=(int)$r['known_cost']+(int)$r['estimated_cost'];$margin=$available?$revenue-$cost:null;
            $categoryQuality=(string)($r['category_snapshot_quality']??'unknown');
            $row=['order_item_id'=>(int)$r['order_item_id'],'item_id'=>$r['item_id']===null?null:(int)$r['item_id'],'item_name'=>(string)$r['item_name'],
                  'business_date'=>(string)$r['business_date'],'ordered_quantity'=>$ordered,'settled_quantity'=>$settled,'financially_complete'=>$financiallyComplete,
                  'net_revenue'=>$revenue,'covered_cost'=>$cost,'cost_quality'=>$quality,'known_samples'=>$known,'estimated_samples'=>$estimated,'unknown_samples'=>$unknown,
                  'exact_basis_samples'=>$exactBasis,'estimated_basis_samples'=>$estimatedBasis,'provisional_recipe_samples'=>$provisional,
                  'estimated_quantity_samples'=>$estimatedQty,'unclassified_provenance_samples'=>$unclassified,'incomplete_recipe_samples'=>$incompleteRecipe,'gross_margin'=>$margin,
                  'category_id'=>$r['category_id_snapshot']===null?null:(int)$r['category_id_snapshot'],'category_name'=>(string)($r['category_name_snapshot']??''),
                  'category_snapshot_quality'=>$categoryQuality];$rows[]=$row;
            $summary['order_items']++;if($financiallyComplete)$summary['financially_complete']++;
            if($available){
                $summary['margin_available']++;$summary['covered_revenue']+=$revenue;$summary['covered_cost']+=$cost;$summary['gross_margin']+=(int)$margin;
                if($quality==='known'){$summary['exact_margin_rows']++;$summary['exact_revenue']+=$revenue;$summary['exact_cost']+=$cost;$summary['exact_gross_margin']+=(int)$margin;}
                else{$summary['estimated_margin_rows']++;$summary['estimated_revenue']+=$revenue;$summary['estimated_basis_cost']+=$cost;$summary['estimated_gross_margin']+=(int)$margin;}
            }
            if($quality==='known')$summary['exact_cost_rows']++;elseif($quality==='estimated')$summary['estimated_cost_rows']++;else $summary['incomplete_cost_rows']++;
            if($provisional>0)$summary['provisional_recipe_rows']++;if($estimatedQty>0)$summary['estimated_quantity_rows']++;if($unclassified>0)$summary['unclassified_provenance_rows']++;if($incompleteRecipe>0)$summary['incomplete_recipe_rows']++;
            if($categoryQuality==='exact_order')$summary['exact_category_rows']++;elseif($categoryQuality==='migrated_current')$summary['migrated_category_rows']++;else $summary['unknown_category_rows']++;

            $ik=(string)($r['item_id']??0).'|'.(string)$r['item_name'];
            if(!isset($items[$ik]))$items[$ik]=['item_id'=>$r['item_id']===null?null:(int)$r['item_id'],'item_name'=>(string)$r['item_name'],'quantity'=>0,'margin_rows'=>0,'exact_margin_rows'=>0,'estimated_margin_rows'=>0,'incomplete_rows'=>0,'net_revenue'=>0,'covered_cost'=>0,'gross_margin'=>0,'cost_quality'=>'known'];
            $items[$ik]['quantity']+=$ordered;
            if($available){$items[$ik]['margin_rows']++;$items[$ik]['net_revenue']+=$revenue;$items[$ik]['covered_cost']+=$cost;$items[$ik]['gross_margin']+=(int)$margin;if($quality==='known')$items[$ik]['exact_margin_rows']++;else $items[$ik]['estimated_margin_rows']++;}
            else $items[$ik]['incomplete_rows']++;
            if($quality==='incomplete')$items[$ik]['cost_quality']='incomplete';elseif($quality==='estimated'&&$items[$ik]['cost_quality']==='known')$items[$ik]['cost_quality']='estimated';

            $ck=($r['category_id_snapshot']??'null').'|'.(string)($r['category_name_snapshot']??'');
            if(!isset($categories[$ck]))$categories[$ck]=['category_id'=>$r['category_id_snapshot']===null?null:(int)$r['category_id_snapshot'],'category_name'=>(string)($r['category_name_snapshot']??'نامشخص'),'margin_rows'=>0,'exact_margin_rows'=>0,'estimated_margin_rows'=>0,'incomplete_rows'=>0,'net_revenue'=>0,'covered_cost'=>0,'gross_margin'=>0,'exact_classification_rows'=>0,'migrated_classification_rows'=>0,'unknown_classification_rows'=>0];
            if($available){$categories[$ck]['margin_rows']++;$categories[$ck]['net_revenue']+=$revenue;$categories[$ck]['covered_cost']+=$cost;$categories[$ck]['gross_margin']+=(int)$margin;if($quality==='known')$categories[$ck]['exact_margin_rows']++;else $categories[$ck]['estimated_margin_rows']++;}
            else $categories[$ck]['incomplete_rows']++;
            if($categoryQuality==='exact_order')$categories[$ck]['exact_classification_rows']++;elseif($categoryQuality==='migrated_current')$categories[$ck]['migrated_classification_rows']++;else $categories[$ck]['unknown_classification_rows']++;
        }
        $itemRows=array_values($items);usort($itemRows,static fn(array $a,array $b):int=>$b['gross_margin']<=>$a['gross_margin']);$itemRows=array_slice($itemRows,0,50);
        $categoryRows=array_values($categories);usort($categoryRows,static fn(array $a,array $b):int=>$b['gross_margin']<=>$a['gross_margin']);
        $summary['coverage_percent']=$summary['order_items']>0?(int)round($summary['margin_available']*100/$summary['order_items']):0;
        $summary['exact_coverage_percent']=$summary['order_items']>0?(int)round($summary['exact_margin_rows']*100/$summary['order_items']):0;
        return ['axis'=>'table_session_open_sale_day__order_day_fallback','summary'=>$summary,'items'=>$itemRows,'categories'=>$categoryRows,'rows'=>$rows,
                'policy'=>['revenue'=>'lifetime completed minus reversal settlement-line net amount keyed by order_item_id',
                           'cost'=>'net recipe consumption/reversal cost keyed by order_item_id; inventory cost and recipe provenance are separate quality dimensions',
                           'margin'=>'covered contribution margin is reported only when financial quantity is complete and cost is not unknown; exact requires explicit ready recipe + known component quantity + known inventory cost',
                           'profit'=>'this is contribution margin, not accounting net profit',
                           'category'=>'exact_order for new snapshots; migrated_current is disclosed and never described as historical-exact']];
    }

    private function financialContextReport(string $from,string $to): array
    {
        $p=[$from,$to];
        $subscriber=$this->one("SELECT COUNT(*) document_count,COUNT(DISTINCT l.subscriber_id) customer_count,COALESCE(SUM(CASE WHEN r.status='completed' THEN r.total ELSE -r.total END),0) net_charged FROM settlement_records r LEFT JOIN subscriber_ledger l ON l.id=r.subscriber_ledger_entry_id WHERE r.destination='subscriber' AND r.status IN('completed','reversal') AND r.business_date BETWEEN ? AND ?",$p);
        $accommodation=$this->one("SELECT COUNT(*) document_count,COUNT(DISTINCT t.id) transfer_count,COUNT(DISTINCT t.reservation_code) reservation_count,COALESCE(SUM(CASE WHEN r.status='completed' THEN r.total ELSE -r.total END),0) net_charged,COUNT(DISTINCT CASE WHEN t.status='posted' THEN t.id END) posted_transfers,COUNT(DISTINCT CASE WHEN t.status IN('pending','void_pending') THEN t.id END) pending_transfers,COUNT(DISTINCT CASE WHEN t.status IN('failed','void_failed') THEN t.id END) failed_transfers,COUNT(DISTINCT CASE WHEN t.status='voided' THEN t.id END) voided_transfers,COUNT(DISTINCT CASE WHEN t.suspicious_response=1 OR t.local_finalize_pending=1 OR t.local_reversal_pending=1 THEN t.id END) attention_transfers FROM settlement_records r LEFT JOIN accommodation_transfers t ON t.id=r.accommodation_transfer_id WHERE r.destination='accommodation' AND r.status IN('completed','reversal') AND r.business_date BETWEEN ? AND ?",$p);
        return [
            'axis'=>'settlement_record_business_date',
            'subscriber'=>['documents'=>(int)($subscriber['document_count']??0),'customers'=>(int)($subscriber['customer_count']??0),'net_charged'=>(int)($subscriber['net_charged']??0)],
            'accommodation'=>['documents'=>(int)($accommodation['document_count']??0),'transfers'=>(int)($accommodation['transfer_count']??0),'reservations'=>(int)($accommodation['reservation_count']??0),'net_charged'=>(int)($accommodation['net_charged']??0),'posted'=>(int)($accommodation['posted_transfers']??0),'pending'=>(int)($accommodation['pending_transfers']??0),'failed'=>(int)($accommodation['failed_transfers']??0),'voided'=>(int)($accommodation['voided_transfers']??0),'attention'=>(int)($accommodation['attention_transfers']??0)],
        ];
    }

    /** Inventory/cost reporting uses local occurred/received timestamps, independent of Business Day cutoff. */
    private function inventoryReport(string $from,string $to): array
    {
        $fromAt=$from.' 00:00:00';$toAt=(new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d').' 00:00:00';$p=[$fromAt,$toAt];
        $movementSummary=function(string $type)use($p):array{
            $r=$this->one("SELECT COUNT(*) movement_count,
                    SUM(cost_status='known' AND total_cost_delta IS NOT NULL) known_samples,
                    SUM(cost_status='estimated' AND total_cost_delta IS NOT NULL) estimated_samples,
                    SUM(cost_status='unknown' OR total_cost_delta IS NULL) unknown_samples,
                    COALESCE(SUM(CASE WHEN cost_status='known' AND total_cost_delta IS NOT NULL THEN -total_cost_delta ELSE 0 END),0) known_cost,
                    COALESCE(SUM(CASE WHEN cost_status='estimated' AND total_cost_delta IS NOT NULL THEN -total_cost_delta ELSE 0 END),0) estimated_cost
                FROM inventory_movements WHERE movement_type=? AND occurred_at>=? AND occurred_at<?",[$type,...$p]);
            return ['movements'=>(int)($r['movement_count']??0),'known_samples'=>(int)($r['known_samples']??0),'estimated_samples'=>(int)($r['estimated_samples']??0),'unknown_samples'=>(int)($r['unknown_samples']??0),
                    'known_cost'=>(int)($r['known_cost']??0),'estimated_cost'=>(int)($r['estimated_cost']??0),'covered_cost'=>(int)($r['known_cost']??0)+(int)($r['estimated_cost']??0)];
        };
        $rr=$this->one("SELECT COUNT(*) movement_count,
                    SUM(cost_status='known' AND total_cost_delta IS NOT NULL) known_samples,
                    SUM(cost_status='estimated' AND total_cost_delta IS NOT NULL) estimated_samples,
                    SUM(cost_status='unknown' OR total_cost_delta IS NULL) unknown_samples,
                    SUM(CASE WHEN cost_status='known' AND total_cost_delta IS NOT NULL
                                  AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_review_status')),'')='ready'
                                  AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_quantity_status')),'')='known' THEN 1 ELSE 0 END) exact_basis_samples,
                    SUM(CASE WHEN cost_status IN('known','estimated') AND total_cost_delta IS NOT NULL
                                  AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_completeness_status')),'unknown')='complete'
                                  AND NOT(cost_status='known'
                                      AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_review_status')),'')='ready'
                                      AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_completeness_status')),'unknown')='complete'
                                      AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_quantity_status')),'')='known') THEN 1 ELSE 0 END) estimated_basis_samples,
                    SUM(CASE WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_review_status')),'')='needs_review' THEN 1 ELSE 0 END) provisional_recipe_samples,
                    SUM(CASE WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_quantity_status')),'')='estimated' THEN 1 ELSE 0 END) estimated_quantity_samples,
                    SUM(CASE WHEN JSON_EXTRACT(metadata_json,'$.recipe_review_status') IS NULL OR JSON_EXTRACT(metadata_json,'$.recipe_quantity_status') IS NULL THEN 1 ELSE 0 END) unclassified_provenance_samples,
                    SUM(CASE WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_completeness_status')),'unknown')<>'complete' THEN 1 ELSE 0 END) incomplete_recipe_samples,
                    COALESCE(SUM(CASE WHEN cost_status IN('known','estimated') AND total_cost_delta IS NOT NULL THEN -total_cost_delta ELSE 0 END),0) covered_cost,
                    COALESCE(SUM(CASE WHEN cost_status='known' AND total_cost_delta IS NOT NULL
                                           AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_review_status')),'')='ready'
                                           AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_quantity_status')),'')='known'
                                      THEN -total_cost_delta ELSE 0 END),0) exact_basis_cost,
                    COALESCE(SUM(CASE WHEN cost_status IN('known','estimated') AND total_cost_delta IS NOT NULL
                                           AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_completeness_status')),'unknown')='complete'
                                           AND NOT(cost_status='known'
                                               AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_review_status')),'')='ready'
                                               AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_completeness_status')),'unknown')='complete'
                                               AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.recipe_quantity_status')),'')='known')
                                      THEN -total_cost_delta ELSE 0 END),0) estimated_basis_cost
                FROM inventory_movements
                WHERE movement_type IN('recipe_consumption','reversal')
                  AND source_type IN('order_item','order_item_adjustment')
                  AND occurred_at>=? AND occurred_at<?",$p);
        $recipe=['movements'=>(int)($rr['movement_count']??0),'known_samples'=>(int)($rr['known_samples']??0),'estimated_samples'=>(int)($rr['estimated_samples']??0),'unknown_samples'=>(int)($rr['unknown_samples']??0),
                 'exact_basis_samples'=>(int)($rr['exact_basis_samples']??0),'estimated_basis_samples'=>(int)($rr['estimated_basis_samples']??0),
                 'provisional_recipe_samples'=>(int)($rr['provisional_recipe_samples']??0),'estimated_quantity_samples'=>(int)($rr['estimated_quantity_samples']??0),
                 'unclassified_provenance_samples'=>(int)($rr['unclassified_provenance_samples']??0),'incomplete_recipe_samples'=>(int)($rr['incomplete_recipe_samples']??0),'covered_cost'=>(int)($rr['covered_cost']??0),
                 'exact_basis_cost'=>(int)($rr['exact_basis_cost']??0),'estimated_basis_cost'=>(int)($rr['estimated_basis_cost']??0)];
        $waste=$movementSummary('waste');
        $purchase=$this->one("SELECT COUNT(*) receipt_count,SUM(total_cost IS NOT NULL) costed_receipts,SUM(total_cost IS NULL) missing_cost_receipts,
                    SUM(received_quantity_base=requested_quantity_snapshot) on_plan_receipts,
                    SUM(received_quantity_base>requested_quantity_snapshot) over_plan_receipts,
                    SUM(received_quantity_base<requested_quantity_snapshot) under_plan_receipts,
                    COALESCE(SUM(total_cost),0) recorded_spend
                FROM inventory_supply_receipts WHERE received_at>=? AND received_at<?",$p);
        $count=$this->one("SELECT COUNT(*) movement_count,SUM(cost_status='known' AND total_cost_delta IS NOT NULL) known_samples,
                    SUM(cost_status='estimated' AND total_cost_delta IS NOT NULL) estimated_samples,
                    SUM(cost_status='unknown' OR total_cost_delta IS NULL) unknown_samples,
                    COALESCE(SUM(CASE WHEN cost_status IN('known','estimated') AND total_cost_delta IS NOT NULL THEN total_cost_delta ELSE 0 END),0) net_value_delta,
                    COALESCE(SUM(CASE WHEN cost_status IN('known','estimated') AND total_cost_delta IS NOT NULL THEN ABS(total_cost_delta) ELSE 0 END),0) absolute_value_delta
                FROM inventory_movements WHERE movement_type='count_adjustment' AND occurred_at>=? AND occurred_at<?",$p);
        $balance=$this->one("SELECT COUNT(*) item_count,SUM(cost_status='known') known_items,SUM(cost_status='estimated') estimated_items,
                    SUM(cost_status='partial') partial_items,SUM(cost_status='unknown' OR average_unit_cost IS NULL) unknown_items,
                    SUM(quantity_base<0) negative_items,
                    COALESCE(SUM(CASE WHEN average_unit_cost IS NOT NULL AND quantity_base>0 THEN ROUND(quantity_base*average_unit_cost) ELSE 0 END),0) covered_stock_value
                FROM inventory_balances",[]);
        $supply=$this->one("SELECT COUNT(*) open_needs,SUM(preparing_quantity_base>0) preparing_needs,
                    SUM(GREATEST(requested_quantity_base-fulfilled_quantity_base-preparing_quantity_base,0)>0) uncommitted_needs
                FROM inventory_supply_needs WHERE status='open'",[]);
        $catalog=$this->one("SELECT COUNT(*) menu_items,
                    SUM(rv.id IS NOT NULL) with_recipe,
                    SUM(rv.id IS NULL) without_recipe,
                    SUM(rv.review_status='ready') reviewed_recipes,
                    SUM(rv.review_status='needs_review') provisional_recipes,
                    SUM(rv.id IS NOT NULL AND COALESCE(rv.completeness_status,'complete')='complete') complete_recipes,
                    SUM(rv.id IS NOT NULL AND COALESCE(rv.completeness_status,'complete')<>'complete') partial_recipes,
                    SUM(rv.id IS NOT NULL AND COALESCE(comp.estimated_components,0)>0) recipes_with_estimated_components,
                    SUM(rv.id IS NOT NULL AND COALESCE(comp.component_count,0)=0) empty_recipes
                FROM items i
                LEFT JOIN inventory_recipe_versions rv ON rv.id=(
                    SELECT rv2.id FROM inventory_recipe_versions rv2
                    WHERE rv2.menu_item_id=i.id AND rv2.status='active' ORDER BY rv2.version_no DESC,rv2.id DESC LIMIT 1
                )
                LEFT JOIN (
                    SELECT recipe_version_id,COUNT(*) component_count,SUM(quantity_status='estimated') estimated_components
                    FROM inventory_recipe_components GROUP BY recipe_version_id
                ) comp ON comp.recipe_version_id=rv.id
                WHERE i.active=1 AND i.sellable_kind='menu_item'",[]);
        $master=$this->one("SELECT COUNT(*) active_items,SUM(review_status='needs_review') items_needing_review FROM inventory_items WHERE active=1",[]);
        $units=$this->one("SELECT COUNT(*) active_units,SUM(review_status='needs_review') units_needing_review FROM inventory_purchase_units WHERE active=1",[]);
        $counts=$this->one("SELECT
                    (SELECT COUNT(*) FROM inventory_count_sessions WHERE status='finalized' AND scope_type='full' AND NOT EXISTS (SELECT 1 FROM inventory_count_lines incomplete WHERE incomplete.session_id=inventory_count_sessions.id AND incomplete.actual_quantity IS NULL) AND session_type='periodic' AND snapshot_at>=? AND snapshot_at<?) full_counts_in_range,
                    (SELECT MAX(snapshot_at) FROM inventory_count_sessions WHERE status='finalized' AND scope_type='full' AND NOT EXISTS (SELECT 1 FROM inventory_count_lines incomplete WHERE incomplete.session_id=inventory_count_sessions.id AND incomplete.actual_quantity IS NULL) AND session_type='periodic' AND snapshot_at<=?) previous_full_count_at,
                    (SELECT MIN(snapshot_at) FROM inventory_count_sessions WHERE status='finalized' AND scope_type='full' AND NOT EXISTS (SELECT 1 FROM inventory_count_lines incomplete WHERE incomplete.session_id=inventory_count_sessions.id AND incomplete.actual_quantity IS NULL) AND session_type='periodic' AND snapshot_at>=?) next_full_count_at",
                [$fromAt,$toAt,$fromAt,$toAt]);
        $reconciliation=$this->countBoundedReconciliation($fromAt,$toAt);
        $menuItems=(int)($catalog['menu_items']??0);$withRecipe=(int)($catalog['with_recipe']??0);$reviewed=(int)($catalog['reviewed_recipes']??0);
        $recipeCoverage=$menuItems>0?(int)round($withRecipe*100/$menuItems):0;$reviewedCoverage=$menuItems>0?(int)round($reviewed*100/$menuItems):0;
        $stockItems=(int)($balance['item_count']??0);$knownStock=(int)($balance['known_items']??0);$stockCoverage=$stockItems>0?(int)round($knownStock*100/$stockItems):0;
        $previous=(string)($counts['previous_full_count_at']??'');$next=(string)($counts['next_full_count_at']??'');
        $priceChanges=$this->purchasePriceChangeReport($fromAt,$toAt);
        return [
            'axis'=>'civil_local','recipe_consumption'=>$recipe,'waste'=>$waste,
            'purchases'=>['receipts'=>(int)($purchase['receipt_count']??0),'costed_receipts'=>(int)($purchase['costed_receipts']??0),'missing_cost_receipts'=>(int)($purchase['missing_cost_receipts']??0),
                         'on_plan_receipts'=>(int)($purchase['on_plan_receipts']??0),'over_plan_receipts'=>(int)($purchase['over_plan_receipts']??0),'under_plan_receipts'=>(int)($purchase['under_plan_receipts']??0),
                         'recorded_spend'=>(int)($purchase['recorded_spend']??0),'variance_policy'=>'non_blocking'],
            'count_variance'=>['movements'=>(int)($count['movement_count']??0),'known_samples'=>(int)($count['known_samples']??0),'estimated_samples'=>(int)($count['estimated_samples']??0),'unknown_samples'=>(int)($count['unknown_samples']??0),
                              'net_value_delta'=>(int)($count['net_value_delta']??0),'absolute_value_delta'=>(int)($count['absolute_value_delta']??0)],
            'current_stock'=>['items'=>$stockItems,'known_items'=>$knownStock,'estimated_items'=>(int)($balance['estimated_items']??0),'partial_items'=>(int)($balance['partial_items']??0),
                             'unknown_items'=>(int)($balance['unknown_items']??0),'negative_items'=>(int)($balance['negative_items']??0),
                             'covered_value'=>(int)($balance['covered_stock_value']??0),'known_cost_percent'=>$stockCoverage,'snapshot'=>'current'],
            'supply_status'=>['open_needs'=>(int)($supply['open_needs']??0),'preparing_needs'=>(int)($supply['preparing_needs']??0),'uncommitted_needs'=>(int)($supply['uncommitted_needs']??0),'variance_policy'=>'non_blocking'],
            'recipe_readiness'=>['menu_items'=>$menuItems,'with_recipe'=>$withRecipe,'without_recipe'=>(int)($catalog['without_recipe']??0),'reviewed_recipes'=>$reviewed,
                                'provisional_recipes'=>(int)($catalog['provisional_recipes']??0),'complete_recipes'=>(int)($catalog['complete_recipes']??0),'partial_recipes'=>(int)($catalog['partial_recipes']??0),'recipes_with_estimated_components'=>(int)($catalog['recipes_with_estimated_components']??0),
                                'empty_recipes'=>(int)($catalog['empty_recipes']??0),'coverage_percent'=>$recipeCoverage,'reviewed_coverage_percent'=>$reviewedCoverage],
            'masterdata_readiness'=>['inventory_items'=>(int)($master['active_items']??0),'items_needing_review'=>(int)($master['items_needing_review']??0),