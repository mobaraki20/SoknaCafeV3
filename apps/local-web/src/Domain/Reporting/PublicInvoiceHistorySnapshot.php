<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\Reporting;
use PDO;

/** R315. Bounded, read-only historical receipts; no customer identifiers or mutation tokens. */
final class PublicInvoiceHistorySnapshot
{
    private static function trimText(string $value,int $length):string
    {return function_exists('mb_substr')?mb_substr($value,0,$length,'UTF-8'):substr($value,0,$length*4);}
    public static function build(PDO $pdo,string $businessDate,int $days=90,int $limit=240): array
    {
        $dt=\DateTimeImmutable::createFromFormat('!Y-m-d',$businessDate,new \DateTimeZone('Asia/Tehran'));
        if(!$dt||$dt->format('Y-m-d')!==$businessDate)throw new \InvalidArgumentException('business_date');
        $days=max(1,min(90,$days));$limit=max(1,min(240,$limit));
        $from=$dt->modify('-'.($days-1).' days')->format('Y-m-d');
        // The receipt and sale business dates are distinct. NEVER overwrite the real
        // receipt date with the table opening date: tender/ledger reconciliation uses it.
        $q=$pdo->prepare("SELECT r.id,r.invoice_number,r.table_name_snapshot,r.status,r.destination,r.settlement_kind,
                  r.subtotal,r.discount,r.tax_amount,r.total,r.settled_at,
                  r.business_date receipt_business_date,ts.business_date sale_business_date,
                  r.reverses_settlement_id,r.invoice_snapshot_json
                  FROM settlement_records r LEFT JOIN table_sessions ts ON ts.id=r.session_id
                  WHERE r.business_date BETWEEN ? AND ? ORDER BY r.id DESC LIMIT ".($limit+1));
        $q->execute([$from,$businessDate]);$rows=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
        $truncated=count($rows)>$limit;
        $rows=array_slice($rows,0,$limit);$out=[];
        foreach($rows as $r){
            if(!in_array((string)($r['status']??''),['completed','reversal'],true))continue;
            $snapshot=json_decode((string)($r['invoice_snapshot_json']??''),true);
            $lines=[];
            if(is_array($snapshot)&&is_array($snapshot['items']??null)){
                foreach(array_slice($snapshot['items'],0,100) as $item){
                    if(!is_array($item))continue;
                    $lines[]=['name'=>self::trimText((string)($item['name']??'آیتم'),160),
                      'quantity'=>(float)($item['quantity']??0),'unit_price'=>(int)($item['unit_price']??0),
                      'line_final'=>(int)($item['line_final']??$item['line_net']??$item['line_total']??0)];
                }
            }
            $out[]=['id'=>(int)$r['id'],'invoice_number'=>substr((string)($r['invoice_number']??''),0,100),
                'table_name'=>self::trimText((string)($r['table_name_snapshot']??''),100),
                'status'=>(string)$r['status'],'destination'=>(string)($r['destination']??''),
                'settlement_kind'=>(string)($r['settlement_kind']??''),
                'subtotal'=>(int)$r['subtotal'],'discount'=>(int)$r['discount'],
                'tax_amount'=>(int)$r['tax_amount'],'total'=>(int)$r['total'],
                'settled_at'=>(string)($r['settled_at']??''),
                'receipt_business_date'=>(string)$r['receipt_business_date'],
                'sale_business_date'=>(string)($r['sale_business_date']??''),
                'reverses_settlement_id'=>(int)($r['reverses_settlement_id']??0),
                'items'=>$lines,'item_lines_complete'=>is_array($snapshot)&&is_array($snapshot['items']??null)&&count($snapshot['items'])<=100];
        }
        $result=['format'=>'sokna-public-invoice-history-v1','status'=>'available',
          'currency'=>'IRT','basis'=>'receipt_business_day_for_archive__session_open_business_day_for_sale',
          'range'=>['from'=>$from,'to'=>$businessDate],
          'rows'=>$out,'truncated'=>$truncated,'limit'=>$limit,'generated_at'=>gmdate('c')];
        // This projection shares the reports payload limit; overflow must not make
        // sales KPIs unavailable. Caller catches and substitutes unavailable status.
        if(strlen(json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))>1250000)
            throw new \OverflowException('invoice history projection too large');
        return $result;
    }
}
