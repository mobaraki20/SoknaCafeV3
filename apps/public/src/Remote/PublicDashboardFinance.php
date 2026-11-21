<?php
declare(strict_types=1);
namespace Sokna\PublicEdge\Remote;

/**
 * Display Local-authored settlement facts, never a Public-side financial calculation.
 * Report range.to is the snapshot date, not necessarily the current Tehran day.
 * Missing projection coverage differs from a confirmed day with zero sales.
 */
final class PublicDashboardFinance
{
    /** Apply the Local-authored cutoff to the current Tehran instant, never to
     * the snapshot's age. A missing/invalid policy remains unknown. */
    public static function businessDate(array $reports, ?\DateTimeInterface $now=null): ?string
    {
        $cutoff=trim((string)($reports['business_clock']['cutoff']??''));
        if(preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/D',$cutoff,$m)!==1)return null;
        $instant=$now===null?new \DateTimeImmutable('now',new \DateTimeZone('Asia/Tehran')):\DateTimeImmutable::createFromInterface($now)->setTimezone(new \DateTimeZone('Asia/Tehran'));
        $minutes=(int)$instant->format('G')*60+(int)$instant->format('i');
        if($minutes<(int)$m[1]*60+(int)$m[2])$instant=$instant->modify('-1 day');
        return $instant->format('Y-m-d');
    }

    public static function reconcile(array $reports, string $today): array
    {
        $dashboard = is_array($reports['dashboard'] ?? null) ? $reports['dashboard'] : [];
        $archive = is_array($reports['archive'] ?? null) ? $reports['archive'] : [];
        $snapshotDate = (string) ($dashboard['range']['to'] ?? '');
        $snapshotFrom = (string) ($dashboard['range']['from'] ?? '');
        $archiveFrom = (string) ($archive['from'] ?? '');
        $archiveTo = (string) ($archive['to'] ?? '');
        $valid = static function (string $date): bool {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m) !== 1) return false;
            return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
        };
        $dashboardCovers = static fn(string $date): bool => $valid($snapshotFrom) && $valid($snapshotDate) && $snapshotFrom <= $date && $date <= $snapshotDate;
        $archiveCovers = static fn(string $date): bool => $valid($archiveFrom) && $valid($archiveTo) && $archiveFrom <= $date && $date <= $archiveTo;
        $archiveSummary = [];
        $conflictDays = [];
        foreach ((array) ($archive['summary'] ?? []) as $row) {
            if (is_array($row) && $valid((string) ($row['date'] ?? '')) && $archiveCovers((string) $row['date'])) {
                $day = (string) $row['date'];
                // A duplicate day is not a trustworthy financial fact even
                // when repeated values happen to match.
                if (array_key_exists($day, $archiveSummary)) $conflictDays[$day] = true;
                else $archiveSummary[$day] = $row;
            }
        }
        $yesterday = (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
        $resolve = static function (string $day, string $dashboardField) use ($dashboard, $archiveSummary, $snapshotDate, $dashboardCovers, $archiveCovers, $valid): array {
            // Map a snapshot day to the correct named summary, regardless of
            // whether the caller labels the lookup today or yesterday.
            // In particular, an old snapshot's "yesterday" must not be
            // substituted for the current day's previous civil date.
            $snapshotYesterday = $valid($snapshotDate)
                ? (new \DateTimeImmutable($snapshotDate))->modify('-1 day')->format('Y-m-d') : '';
            if ($day === $snapshotDate && $dashboardCovers($day) && is_array($dashboard['today_summary'] ?? null)) {
                $raw = $dashboard['today_summary'];
            } elseif ($day === $snapshotYesterday && is_array($dashboard['yesterday_summary'] ?? null)) {
                $raw = $dashboard['yesterday_summary'];
            } else {
                $raw = [];
            }
            $fallback = $archiveCovers($day) ? (array) ($archiveSummary[$day] ?? []) : [];
            // A covered interval is not a receipt for an individual day.
            // Do not turn a missing day into a proven zero.
            $has = static fn(array $a, string $key): bool => array_key_exists($key, $a) && is_numeric($a[$key]);
            // A partial row with only an order count is not proof of zero sales.
            // Prefer a valid archive day if the dashboard summary is malformed.
            if (!$has($raw, 'net_sales') && $has($fallback, 'net_sales')) $raw = [];
            $known = $has($raw, 'net_sales') || $has($fallback, 'net_sales');
            $field = static fn(string $key): int => $has($raw, $key) ? (int) $raw[$key] : ($has($fallback, $key) ? (int) $fallback[$key] : 0);
            $net = $field('net_sales');
            $orders = $field('order_count');
            $closed = $has($raw, 'completed_settlements') ? max(0, (int) $raw['completed_settlements']) : ($has($fallback, 'completed_settlements') ? max(0, (int) $fallback['completed_settlements']) : 0);
            $receiptsKnown = $has($raw, 'completed_settlements') || $has($fallback, 'completed_settlements');
            $avg = $has($raw, 'avg_ticket') ? (int) $raw['avg_ticket'] : ($closed > 0 && $has($fallback, 'completed_sales') ? (int) round((int) $fallback['completed_sales'] / $closed) : 0);
            return ['summary' => ['net_sales' => $net, 'order_count' => $orders, 'avg_ticket' => $avg, 'completed_settlements' => $closed],
                'available' => $known, 'receipts_available' => $receiptsKnown, 'has_receipt' => $known && $receiptsKnown && $closed > 0,
                'avg_available'=>$has($raw,'avg_ticket')||($has($fallback,'completed_sales')&&$has($fallback,'completed_settlements')),
                'orders_available'=>$has($raw,'order_count')||$has($fallback,'order_count'),
                'conflict'=>($has($raw,'net_sales')&&$has($fallback,'net_sales')&&(int)$raw['net_sales']!==(int)$fallback['net_sales'])
                    ||($has($raw,'completed_settlements')&&$has($fallback,'completed_settlements')&&(int)$raw['completed_settlements']!==(int)$fallback['completed_settlements'])
                    ||($has($raw,'avg_ticket')&&$has($fallback,'completed_settlements')&&$has($fallback,'completed_sales')
                        &&(int)$raw['avg_ticket']!==((int)$fallback['completed_settlements']>0
                            ?(int)round((int)$fallback['completed_sales']/(int)$fallback['completed_settlements']):0))];
        };
        $todayView = $resolve($today, 'today_summary');
        $yesterdayView = $resolve($yesterday, 'yesterday_summary');
        $daily = [];
        foreach ((array) ($archive['sales'] ?? []) as $row) {
            if (is_array($row) && $valid((string) ($row['date'] ?? '')) && is_numeric($row['net_sales'] ?? null) && $archiveCovers((string) $row['date'])) {
                $day = (string) $row['date'];
                if (array_key_exists($day, $daily)) $conflictDays[$day] = true;
                else $daily[$day] = (int) $row['net_sales'];
            }
        }
        $dashboardSeen = [];
        foreach ((array) ($dashboard['daily'] ?? []) as $row) {
            if (is_array($row) && $valid((string) ($row['business_date'] ?? '')) && is_numeric($row['net_sales'] ?? null) && $dashboardCovers((string) $row['business_date'])) {
                $day = (string) $row['business_date'];
                $dashboardNet = (int) $row['net_sales'];
                if (isset($dashboardSeen[$day])) $conflictDays[$day] = true;
                $dashboardSeen[$day] = true;
                // Both facts are emitted from the same Local DB snapshot; disagreement
                // must never become a convincing-looking bar without a warning.
                if (array_key_exists($day, $daily) && $daily[$day] !== $dashboardNet) {
                    $conflictDays[$day] = true;
                }
                $daily[$day] = $dashboardNet;
            }
        }
        // Reconcile every archived day, not only the two named dashboard
        // summaries. Older bar values must not silently disagree with the
        // Local-authored daily archive summary.
        foreach ($archiveSummary as $day => $summary) {
            if (array_key_exists($day, $daily) && is_numeric($summary['net_sales'] ?? null)
                && (int) $summary['net_sales'] !== $daily[$day]) $conflictDays[$day] = true;
        }
        foreach([[$today,$todayView],[$yesterday,$yesterdayView]] as [$day,$view]){
            if($view['conflict']||($view['available']&&array_key_exists($day,$daily)&&$view['summary']['net_sales']!==$daily[$day]))$conflictDays[$day]=true;
        }
        $todayConflict=isset($conflictDays[$today]);$yesterdayConflict=isset($conflictDays[$yesterday]);
        return [
            'today' => $todayView['summary'], 'yesterday' => $yesterdayView['summary'],
            'daily' => $daily, 'daily_conflicts' => array_keys($conflictDays), 'today_available' => $todayView['available']&&!$todayConflict,
            'yesterday_available' => $yesterdayView['available']&&!$yesterdayConflict,
            'today_avg_available'=>$todayView['avg_available']&&!$todayConflict,'yesterday_avg_available'=>$yesterdayView['avg_available']&&!$yesterdayConflict,
            'today_orders_available'=>$todayView['orders_available']&&!$todayConflict,
            'today_receipts_available'=>$todayView['receipts_available']&&!$todayConflict,
            'yesterday_receipts_available'=>$yesterdayView['receipts_available']&&!$yesterdayConflict,
            'today_has_receipt' => $todayView['has_receipt']&&!$todayConflict,
            'yesterday_has_receipt' => $yesterdayView['has_receipt']&&!$yesterdayConflict,
            'dashboard_snapshot_date' => $snapshotDate,
            'archive_from' => $archiveFrom, 'archive_to' => $archiveTo,
            'daily_coverage' => ['archive_from' => $archiveFrom, 'archive_to' => $archiveTo,
                'dashboard_from' => $snapshotFrom, 'dashboard_to' => $snapshotDate],
        ];
    }
    /**
     * Historical fallback for a disconnected or day-shifted Staff dashboard.
     * This is NEVER a live "today" reading. A covered date without a valid
     * Local-authored sales fact is not a zero and is never displayed.
     */
    public static function lastKnownSnapshot(array $reports, array $meta, string $currentBusinessDay): ?array
    {
        $dashboard=is_array($reports['dashboard']??null)?$reports['dashboard']:[];
        $businessClock=is_array($reports['business_clock']??null)?$reports['business_clock']:[];
        $snapshotDay=(string)($dashboard['range']['to']??'');
        if(preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$snapshotDay,$m)!==1
            ||!checkdate((int)$m[2],(int)$m[3],(int)$m[1])
            ||(string)($businessClock['business_date']??'')!==$snapshotDay
            ||preg_match('/^\d{4}-\d{2}-\d{2}$/D',$currentBusinessDay)!==1)return null;
        $isStale=($meta['stale']??true)!==false||$snapshotDay!==$currentBusinessDay;
        if(!$isStale)return null;
        $lastSync=trim((string)($meta['last_sync_at']??''));
        if($lastSync==='')return null;
        try{$sync=new \DateTimeImmutable($lastSync);}catch(\Throwable){return null;}
        $freshlyGenerated=trim((string)($meta['generated_at']??''));
        $historical=self::reconcile($reports,$snapshotDay);
        if(($historical['today_available']??false)!==true
            ||in_array($snapshotDay,(array)($historical['daily_conflicts']??[]),true))return null;
        $summary=(array)$historical['today'];
        return ['business_date'=>$snapshotDay,'last_sync_at'=>$sync->format('c'),
            'model_generated_at'=>$freshlyGenerated,'net_sales'=>(int)$summary['net_sales'],
            'order_count'=>($historical['today_orders_available']??false)===true?(int)$summary['order_count']:null,
            'avg_ticket'=>($historical['today_avg_available']??false)===true
                &&($historical['today_has_receipt']??false)===true?(int)$summary['avg_ticket']:null,
            'source'=>'Local-authored cached report','live'=>false];
    }

    /**
     * Two business days from the Local-authored Public projection, never a live
     * Local ledger query. Missing rows/coverage or conflicts do not mean zero.
     * The output is deliberately allowlisted: no receipt, customer, order or actor IDs.
     */
    public static function supportEvidence(array $reports,array $meta): array
    {
        $captured=gmdate('c');
        $base=['format'=>'sokna-support-sales-reconciliation-v1',
            'source'=>'public_synced_local_reports_projection','status'=>'unavailable',
            'reason'=>'projection_unavailable','captured_at'=>$captured,
            'model_generated_at'=>(string)($meta['generated_at']??''),
            'last_sync_at'=>(string)($meta['last_sync_at']??''),
            'projection_stale'=>($meta['stale']??true)!==false,
            'query_completed'=>false,'days'=>[],
            'public_comparison'=>'not_a_live_local_database_read'];
        $today=self::businessDate($reports);
        if($today===null)return array_replace($base,['reason'=>'business_clock_unavailable']);
        $previous=(new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
        $archive=is_array($reports['archive']??null)?$reports['archive']:[];
        $from=(string)($archive['from']??'');$to=(string)($archive['to']??'');
        $valid=static function(string $day):bool{
            return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$day,$m)===1
                &&checkdate((int)$m[2],(int)$m[3],(int)$m[1]);
        };
        $base['business_date']=$today;
        $base['cutoff']=(string)($reports['business_clock']['cutoff']??'');
        $base['date_scope']='current_and_previous_business_date';
        $base['projection_business_date']=(string)($reports['business_clock']['business_date']??'');
        $base['coverage_from']=$from;$base['coverage_to']=$to;
        if(!$valid($from)||!$valid($to)||$from>$previous||$to<$today)
            return array_replace($base,['reason'=>'projection_date_coverage_missing']);
        if(!is_array($archive['sales']??null))return array_replace($base,['reason'=>'archive_sales_unavailable']);
        // Cross-check with the same dashboard/archive reconciliation used by Staff.
        $comparison=self::reconcile($reports,$today);
        if(in_array($today,(array)($comparison['daily_conflicts']??[]),true)
            ||in_array($previous,(array)($comparison['daily_conflicts']??[]),true))
            return array_replace($base,['status'=>'conflict','reason'=>'public_projection_sources_disagree']);
        $index=[];$wanted=[$previous,$today];
        foreach($archive['sales'] as $row){
            if(!is_array($row))continue;
            $day=(string)($row['date']??'');
            if(!in_array($day,$wanted,true))continue;
            if(array_key_exists($day,$index))return array_replace($base,['status'=>'conflict','reason'=>'duplicate_projected_business_day']);
            $index[$day]=$row;
        }
        $days=[];
        foreach($wanted as $day){
            $row=$index[$day]??null;
            if(!is_array($row))return array_replace($base,['reason'=>'projected_day_missing']);
            foreach(['net_sales','completed_sales','settlement_count','completed_settlements','reversals'] as $field){
                if(!array_key_exists($field,$row)||!is_int($row[$field])&&!is_string($row[$field])
                    ||!preg_match('/^-?\d+$/D',(string)$row[$field]))
                    return array_replace($base,['reason'=>'invalid_projected_amount']);
            }
            $net=(int)$row['net_sales'];$completed=(int)$row['completed_sales'];
            $count=(int)$row['settlement_count'];$completedCount=(int)$row['completed_settlements'];$reversals=(int)$row['reversals'];
            // A cached financial row can have well-formed amounts yet an internally
            // impossible document count. Reject it at the producer boundary, not
            // only later in the offline comparison tool. Zero-amount documents and
            // a negative net from valid reversals remain allowed.
            if($completed<0||$completed<$net||$count<0||$completedCount<0||$reversals<0
                ||$count!==$completedCount+$reversals
                ||($completedCount===0&&$completed!==0)
                ||($reversals===0&&$completed!==$net))
                return array_replace($base,['reason'=>'inconsistent_projected_amount']);
            if(!array_key_exists($day,$comparison['daily']??[])||$comparison['daily'][$day]!==$net)
                return array_replace($base,['status'=>'conflict','reason'=>'projection_daily_reconciliation_conflict']);
            $days[]=['business_date'=>$day,'settlement_count'=>$count,'net_sales'=>$net,
                'completed_amount'=>$completed,'reversed_amount'=>$completed-$net,
                'completed_settlements'=>$completedCount,'reversals'=>$reversals];
        }
        $outdated=$base['projection_stale']||$base['projection_business_date']!==$today;
        return array_replace($base,[
            'status'=>$outdated?'stale':'available',
            'reason'=>$outdated?'projection_snapshot_not_current':'',
            'query_completed'=>true,'days'=>$days,
            'reversed_amount_basis'=>'projected_completed_sales_minus_net_sales',
            'note'=>'Public values are a Local-authored cached projection, not an independent live financial ledger. Compare capture and last_sync_at.']);
    }

}
