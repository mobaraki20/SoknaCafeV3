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
        $valid = static fn(string $date): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date);
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
            $avg = $has($raw, 'avg_ticket') ? (int) $raw['avg_ticket'] : ($closed > 0 && $has($fallback, 'completed_sales') ? (int) round((int) $fallback['completed_sales'] / $closed) : 0);
            return ['summary' => ['net_sales' => $net, 'order_count' => $orders, 'avg_ticket' => $avg, 'completed_settlements' => $closed],
                'available' => $known, 'has_receipt' => $known && $closed > 0,
                'avg_available'=>$has($raw,'avg_ticket')||($has($fallback,'completed_sales')&&$has($fallback,'completed_settlements')),
                'orders_available'=>$has($raw,'order_count')||$has($fallback,'order_count'),
                'conflict'=>$has($raw,'net_sales')&&$has($fallback,'net_sales')&&(int)$raw['net_sales']!==(int)$fallback['net_sales']];
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
            'today_has_receipt' => $todayView['has_receipt']&&!$todayConflict,
            'yesterday_has_receipt' => $yesterdayView['has_receipt']&&!$yesterdayConflict,
            'dashboard_snapshot_date' => $snapshotDate,
            'archive_from' => $archiveFrom, 'archive_to' => $archiveTo,
            'daily_coverage' => ['archive_from' => $archiveFrom, 'archive_to' => $archiveTo,
                'dashboard_from' => $snapshotFrom, 'dashboard_to' => $snapshotDate],
        ];
    }
}