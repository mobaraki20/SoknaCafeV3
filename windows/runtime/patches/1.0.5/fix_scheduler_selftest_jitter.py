from pathlib import Path
import argparse

OLD = '''        relay.CompleteAmbiguousFailure(t0.AddMilliseconds(1100),"httprequestexception");
        if(relay.TryStart(t0.AddMilliseconds(2000),out _))throw new InvalidOperationException("runtime_scheduler_backoff_invalid");
        if(!relay.TryStart(t0.AddMilliseconds(2100),out var retry)||retry!=second)throw new InvalidOperationException("runtime_scheduler_retry_identity_invalid");
        relay.CompleteDefinitiveFailure(t0.AddMilliseconds(2150),"local_trigger_failed_503");
        if(relay.NextRunAt!=DateTimeOffset.MinValue)throw new InvalidOperationException("runtime_scheduler_definitive_failure_identity_not_rotated");
        if(relay.TryStart(t0.AddMilliseconds(4149),out _))throw new InvalidOperationException("runtime_scheduler_definitive_backoff_invalid");
        if(relay.NextRunAt!=DateTimeOffset.MinValue)throw new InvalidOperationException("runtime_scheduler_definitive_identity_assigned_before_backoff");
        if(!relay.TryStart(t0.AddMilliseconds(4150),out var rotated)||rotated!=t0.AddMilliseconds(4150)||rotated.ToUnixTimeSeconds()==second.ToUnixTimeSeconds())throw new InvalidOperationException("runtime_scheduler_definitive_identity_rotation_invalid");
'''

NEW = '''        relay.CompleteAmbiguousFailure(t0.AddMilliseconds(1100),"httprequestexception");
        var ambiguousRetryAt=relay.RetryNotBefore??throw new InvalidOperationException("runtime_scheduler_backoff_missing");
        if(relay.TryStart(ambiguousRetryAt.AddMilliseconds(-1),out _))throw new InvalidOperationException("runtime_scheduler_backoff_invalid");
        if(!relay.TryStart(ambiguousRetryAt,out var retry)||retry!=second)throw new InvalidOperationException("runtime_scheduler_retry_identity_invalid");
        var definitiveFailedAt=ambiguousRetryAt.AddMilliseconds(50);
        relay.CompleteDefinitiveFailure(definitiveFailedAt,"local_trigger_failed_422");
        if(relay.NextRunAt!=DateTimeOffset.MinValue)throw new InvalidOperationException("runtime_scheduler_definitive_failure_identity_not_rotated");
        var definitiveRetryAt=relay.RetryNotBefore??throw new InvalidOperationException("runtime_scheduler_definitive_backoff_missing");
        if(relay.TryStart(definitiveRetryAt.AddMilliseconds(-1),out _))throw new InvalidOperationException("runtime_scheduler_definitive_backoff_invalid");
        if(relay.NextRunAt!=DateTimeOffset.MinValue)throw new InvalidOperationException("runtime_scheduler_definitive_identity_assigned_before_backoff");
        if(!relay.TryStart(definitiveRetryAt,out var rotated)||rotated!=definitiveRetryAt||rotated.ToUnixTimeMilliseconds()==second.ToUnixTimeMilliseconds())throw new InvalidOperationException("runtime_scheduler_definitive_identity_rotation_invalid");
'''

ap=argparse.ArgumentParser()
ap.add_argument('--source',required=True)
args=ap.parse_args()
p=Path(args.source)
s=p.read_text(encoding='utf-8').replace('\r\n','\n').replace('\r','\n')
if 'var ambiguousRetryAt=relay.RetryNotBefore' in s:
    print('Runtime 1.0.5 scheduler jitter self-test correction already materialized: PASS')
    raise SystemExit(0)
count=s.count(OLD)
if count!=1:
    raise SystemExit(f'expected exactly one legacy scheduler self-test block, found {count}')
s=s.replace(OLD,NEW,1)
p.write_text(s,encoding='utf-8',newline='\n')
print('Runtime 1.0.5 scheduler jitter self-test correction materialized: PASS')
