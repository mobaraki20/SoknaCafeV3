from pathlib import Path
import json,re,sys
R=Path(__file__).resolve().parents[1]
def need(cond,msg):
    if not cond:
        print('FAIL:',msg,file=sys.stderr)
        raise SystemExit(1)
source=(R/'windows/runtime/source/Program.cs').read_text(encoding='utf-8')
proj=(R/'windows/runtime/source/Sokna.Runtime.Service.csproj').read_text(encoding='utf-8')
compat=json.loads((R/'packaging/windows/windows-services-compatibility-v1.json').read_text(encoding='utf-8'))
pkg=(R/'packaging/windows/WINDOWS_SERVICES_VERSION.txt').read_text(encoding='utf-8').strip()
need(pkg=='1.0.17','Windows Services package must be 1.0.17')
need('<Version>1.0.5</Version>' in proj,'Runtime csproj must be 1.0.5')
need(compat['package_version']=='1.0.17','compat package version mismatch')
need(compat['components']['runtime']['version']=='1.0.5','compat Runtime version mismatch')
need(compat['components']['print-agent']['version']=='6.2.9','Print Agent must remain 6.2.9')
for token in [
    'TriggerBudgetMilliseconds',
    'public.relay_sync"=>4500',
    'public.projection_sync"=>15000',
    'Timeout=Timeout.InfiniteTimeSpan',
    'CancellationTokenSource.CreateLinkedTokenSource(stop.Token)',
    'deadlineCts.CancelAfter(budgetMs)',
    'trigger_deadline_exceeded',
    'budget_ms=budgetMs',
    'deadline_at=deadlineAt',
    'RetryBudgetMaxAttempts=6',
    'RetryBudgetWindowSeconds=60',
    'RuntimeTriggerHttpException',
    'LocalFailureClass',
    '429 or 502 or 503 or 504 or 408=>"overload"',
    '401 or 403=>"authentication"',
    '426=>"compatibility"',
    'RetryAfterSeconds(HttpResponseMessage response)',
    'CompletePolicyBlockedFailure',
    'class=transport',
    'retry_after=',
    'scheduledAt.ToUnixTimeMilliseconds()',
]: need(token in source,'Runtime 1.0.5 source missing '+token)
need('HttpClient http=new(new HttpClientHandler' in source,'Runtime HttpClient missing')
need('){Timeout=Timeout.InfiniteTimeSpan};' in source,'Runtime global HttpClient timeout must not race trigger deadline')
need('CompleteSuccess(DateTimeOffset completedAt,DateTimeOffset scheduledAt)' in source,'cadence anchoring missing')
need('var cadenceNext=scheduledAt.AddSeconds(EffectiveIntervalSeconds)' in source,'success cadence must anchor to scheduled identity')
need('Math.Min(MaxBackoffSeconds*1000,seconds*1000+jitterMs)' in source,'bounded jitter/backoff missing')
need('RetryWindowStartedAt' in source and 'RetryAttemptsInWindow' in source,'retry budget state missing')
need('if(!response.IsSuccessStatusCode)' in source,'HTTP policy classification entry missing')
need('if((int)response.StatusCode==426)throw new InvalidOperationException("local_contract_upgrade_required")' not in source,'legacy 426 special-case must be replaced by typed policy classification')
print('Runtime 1.0.5 resilience source gate PASS')
