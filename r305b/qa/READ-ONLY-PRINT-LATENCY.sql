-- SOKNA Print Agent latency investigation — read-only, source-schema R304.
-- This query is NOT executed against the cafe; no SELECT privileges or connection are assumed here.
-- Use only in an approved read-only diagnostic run with privacy filters. Do not run UPDATE/DELETE/ALTER.
-- Times are server database timestamps (mostly second resolution). `submitted_at` is server acknowledgement,
-- NOT proof that paper physically emerged from the printer.
-- Reattempts/retries can conflate stages; this selects the LAST print_attempt per print job.
SELECT
    j.id AS print_job_id,
    j.job_type,
    j.status AS job_status,
    j.destination_key,
    j.attempt_count,
    j.created_at AS queued_at,
    a.id AS latest_attempt_id,
    a.state AS latest_attempt_state,
    a.leased_at AS claimed_at,
    a.accepted_at,
    a.started_at,
    a.submitted_at,
    ROUND(TIMESTAMPDIFF(MICROSECOND,j.created_at,a.leased_at)/1000000,2) AS queued_to_claim_sec,
    ROUND(TIMESTAMPDIFF(MICROSECOND,a.leased_at,a.started_at)/1000000,2) AS claim_to_start_sec,
    ROUND(TIMESTAMPDIFF(MICROSECOND,a.started_at,a.submitted_at)/1000000,2) AS start_to_report_sec,
    ROUND(TIMESTAMPDIFF(MICROSECOND,j.created_at,a.submitted_at)/1000000,2) AS queued_to_report_sec,
    a.error_code
FROM print_jobs AS j
LEFT JOIN print_attempts AS a
  ON a.id=(SELECT a2.id FROM print_attempts AS a2 WHERE a2.job_id=j.id ORDER BY a2.id DESC LIMIT 1)
WHERE j.created_at >= NOW() - INTERVAL 7 DAY
ORDER BY j.id DESC
LIMIT 80;

-- Optional aggregate for claimed work, by type and destination. Do not interpret as device speed.
SELECT
    j.job_type,
    j.destination_key,
    COUNT(*) AS sampled_jobs,
    ROUND(AVG(TIMESTAMPDIFF(SECOND,j.created_at,j.claimed_at)),1) AS avg_queue_to_claim_sec,
    MAX(TIMESTAMPDIFF(SECOND,j.created_at,j.claimed_at)) AS max_queue_to_claim_sec,
    ROUND(AVG(TIMESTAMPDIFF(SECOND,j.claimed_at,j.submitted_at)),1) AS avg_claim_to_report_sec,
    MAX(TIMESTAMPDIFF(SECOND,j.claimed_at,j.submitted_at)) AS max_claim_to_report_sec
FROM print_jobs AS j
WHERE j.created_at >= NOW() - INTERVAL 7 DAY
  AND j.claimed_at IS NOT NULL
GROUP BY j.job_type,j.destination_key
ORDER BY sampled_jobs DESC;
