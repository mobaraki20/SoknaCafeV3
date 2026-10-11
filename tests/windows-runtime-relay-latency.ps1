param(
  [Parameter(Mandatory=$true)][string]$RuntimeExe
)
$ErrorActionPreference='Stop'
if(-not(Test-Path -LiteralPath $RuntimeExe -PathType Leaf)){throw "Runtime executable not found: $RuntimeExe"}
$root=Join-Path $env:RUNNER_TEMP ('sokna-runtime-latency-'+[guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $root -Force|Out-Null
$serverScript=Join-Path $root 'mock_local.py'
@'
import argparse,json,threading,time
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
p=argparse.ArgumentParser();p.add_argument('--port',type=int,required=True);p.add_argument('--events',required=True);p.add_argument('--mode',default='normal');a=p.parse_args()
lock=threading.Lock(); active={}; seen={}
def log(row):
    with lock:
        with open(a.events,'a',encoding='utf-8') as f:f.write(json.dumps(row,separators=(',',':'))+'\n')
class H(BaseHTTPRequestHandler):
    def log_message(self,*args): pass
    def do_POST(self):
        n=int(self.headers.get('Content-Length','0')); body=json.loads(self.rfile.read(n) or b'{}'); key=body.get('trigger_key','')
        with lock:
            active[key]=active.get(key,0)+1; current=active[key]; seen[key]=seen.get(key,0)+1; ordinal=seen[key]
        log({'ts':time.time(),'key':key,'request_id':body.get('request_id'),'scheduled':body.get('requested_at'),'active':current,'ordinal':ordinal,'mode':a.mode})
        try:
            if key=='maintenance.slow': time.sleep(2.2)
            if a.mode=='overlap' and key=='public.relay_sync': time.sleep(1.3)
            if a.mode=='definitive' and key=='public.relay_sync' and ordinal==1:
                self.send_response(503);self.end_headers();return
            if a.mode=='ambiguous' and key=='public.relay_sync' and ordinal==1:
                self.close_connection=True;return
            self.send_response(200);self.send_header('Content-Type','application/json');self.end_headers();self.wfile.write(b'{"success":true}')
        finally:
            with lock: active[key]=active.get(key,1)-1
ThreadingHTTPServer(('127.0.0.1',a.port),H).serve_forever()
'@ | Set-Content -LiteralPath $serverScript -Encoding utf8

function New-Case([string]$name,[int]$relayInterval,[bool]$includeSlow){
  $dir=Join-Path $root $name;New-Item -ItemType Directory -Path $dir -Force|Out-Null
  $runtimeToken=Join-Path $dir 'runtime.token';$localToken=Join-Path $dir 'local.token';$secret=('s'*48)
  [IO.File]::WriteAllText($runtimeToken,$secret,[Text.UTF8Encoding]::new($false));[IO.File]::WriteAllText($localToken,$secret,[Text.UTF8Encoding]::new($false))
  $port=Get-Random -Minimum 21000 -Maximum 39000;$health=$port+1
  $triggers=@([ordered]@{key='public.relay_sync';intervalSeconds=$relayInterval});if($includeSlow){$triggers+=([ordered]@{key='maintenance.slow';intervalSeconds=15})}
  $cfg=[ordered]@{contractVersion=1;instanceId=('runtime-test-'+$name);dataRoot=(Join-Path $dir 'state');healthPort=$health;runtimeTokenFile=$runtimeToken;localTokenFile=$localToken;localBaseUrl=("http://127.0.0.1:$port/");printAgentServiceName='SoknaPrintWorker';supervisePrintAgent=$false;triggers=$triggers}
  $cfgPath=Join-Path $dir 'runtime-config.json';$cfg|ConvertTo-Json -Depth 8|Set-Content -LiteralPath $cfgPath -Encoding utf8
  return [pscustomobject]@{Name=$name;Dir=$dir;Port=$port;HealthPort=$health;Config=$cfgPath;Events=(Join-Path $dir 'events.jsonl');Secret=$secret}
}
function Start-Mock($case,[string]$mode){
  return Start-Process -FilePath 'python' -ArgumentList @($serverScript,'--port',[string]$case.Port,'--events',$case.Events,'--mode',$mode) -PassThru -WindowStyle Hidden
}
function Start-Runtime($case){
  return Start-Process -FilePath $RuntimeExe -ArgumentList @('--console','--config',$case.Config) -PassThru -WindowStyle Hidden
}
function Stop-Proc($p){if($p-and-not$p.HasExited){Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue;try{$p.WaitForExit(5000)|Out-Null}catch{}}}
function Read-Events($case){if(-not(Test-Path $case.Events)){return @()};return @(Get-Content $case.Events|Where-Object{$_}|ForEach-Object{$_|ConvertFrom-Json})}
function Wait-Health($case){
  $deadline=[DateTime]::UtcNow.AddSeconds(8)
  do{try{return Invoke-RestMethod -Uri ("http://127.0.0.1:$($case.HealthPort)/v1/health") -Headers @{Authorization=('Bearer '+$case.Secret);'X-Sokna-Runtime-Contract'='1'} -TimeoutSec 2}catch{Start-Sleep -Milliseconds 150}}while([DateTime]::UtcNow-lt$deadline)
  throw "Runtime health did not become available for $($case.Name)"
}
function Assert-Cadence($events){
  $relay=@($events|Where-Object{$_.key-eq'public.relay_sync'}|Sort-Object ts)
  if($relay.Count-lt4){throw "Expected >=4 relay runs, got $($relay.Count)"}
  $gaps=@();for($i=1;$i-lt$relay.Count;$i++){$gaps+=[double]$relay[$i].ts-[double]$relay[$i-1].ts}
  $avg=($gaps|Measure-Object -Average).Average;$max=($gaps|Measure-Object -Maximum).Maximum
  if($avg-gt1.30-or$max-gt1.55){throw "Relay cadence too slow: avg=$avg max=$max gaps=$($gaps -join ',')"}
  if(($events|Where-Object{$_.key-eq'maintenance.slow'}).Count-lt1){throw 'Slow isolation trigger did not execute'}
  return [pscustomobject]@{count=$relay.Count;average_gap=$avg;max_gap=$max}
}

$procs=@()
try{
  # Legacy config reconciliation + cadence + slow-trigger isolation.
  $c=New-Case 'cadence' 5 $true;$s=Start-Mock $c 'normal';$procs+=$s;Start-Sleep -Milliseconds 350;$r=Start-Runtime $c;$procs+=$r
  $health=Wait-Health $c
  $relayHealth=$health.triggers.'public.relay_sync'
  if([int]$relayHealth.configured_interval_seconds-ne5-or[int]$relayHealth.effective_interval_seconds-ne1){throw "Legacy relay reconciliation health mismatch: $($relayHealth|ConvertTo-Json -Compress)"}
  Start-Sleep -Seconds 5.2;Stop-Proc $r;$stats=Assert-Cadence (Read-Events $c)
  $before=(Read-Events $c|Where-Object{$_.key-eq'public.relay_sync'}).Count
  $r2=Start-Runtime $c;$procs+=$r2;Wait-Health $c|Out-Null;Start-Sleep -Seconds 2.3;Stop-Proc $r2
  $after=(Read-Events $c|Where-Object{$_.key-eq'public.relay_sync'}).Count
  if(($after-$before)-lt2){throw "Restart recovery did not resume relay cadence: before=$before after=$after"}
  Stop-Proc $s

  # Per-trigger no-overlap under a handler slower than the configured cadence.
  $o=New-Case 'overlap' 1 $false;$s2=Start-Mock $o 'overlap';$procs+=$s2;Start-Sleep -Milliseconds 350;$r3=Start-Runtime $o;$procs+=$r3;Wait-Health $o|Out-Null;Start-Sleep -Seconds 4.8;Stop-Proc $r3;Stop-Proc $s2
  $oe=@(Read-Events $o|Where-Object{$_.key-eq'public.relay_sync'})
  if($oe.Count-lt2){throw 'No-overlap case produced too few relay calls'}
  $maxActive=($oe|Measure-Object -Property active -Maximum).Maximum;if([int]$maxActive-ne1){throw "Per-trigger overlap detected: max_active=$maxActive"}
  if($oe.Count-gt3){throw "Slow handler caused catch-up/runaway loop: calls=$($oe.Count)"}

  # Definitive HTTP failure rotates identity and uses backoff.
  $d=New-Case 'definitive' 1 $false;$s3=Start-Mock $d 'definitive';$procs+=$s3;Start-Sleep -Milliseconds 350;$r4=Start-Runtime $d;$procs+=$r4;Wait-Health $d|Out-Null;Start-Sleep -Seconds 3.4;Stop-Proc $r4;Stop-Proc $s3
  $de=@(Read-Events $d|Where-Object{$_.key-eq'public.relay_sync'}|Sort-Object ts);if($de.Count-lt2){throw 'Definitive failure case produced too few calls'}
  if([string]$de[0].request_id-eq[string]$de[1].request_id){throw 'Definitive failure did not rotate request identity'}
  if(([double]$de[1].ts-[double]$de[0].ts)-lt0.85){throw 'Definitive failure retry ignored backoff'}

  # Ambiguous transport failure retries the same scheduled/request identity.
  $a=New-Case 'ambiguous' 1 $false;$s4=Start-Mock $a 'ambiguous';$procs+=$s4;Start-Sleep -Milliseconds 350;$r5=Start-Runtime $a;$procs+=$r5;Wait-Health $a|Out-Null;Start-Sleep -Seconds 3.4;Stop-Proc $r5;Stop-Proc $s4
  $ae=@(Read-Events $a|Where-Object{$_.key-eq'public.relay_sync'}|Sort-Object ts);if($ae.Count-lt2){throw 'Ambiguous failure case produced too few calls'}
  if([string]$ae[0].request_id-ne[string]$ae[1].request_id){throw 'Ambiguous retry changed deterministic request identity'}
  if(([double]$ae[1].ts-[double]$ae[0].ts)-lt0.85){throw 'Ambiguous failure retry ignored backoff'}

  [ordered]@{result='PASS';legacy_config_effective_interval=1;cadence_runs=$stats.count;cadence_average_gap_seconds=[Math]::Round($stats.average_gap,3);cadence_max_gap_seconds=[Math]::Round($stats.max_gap,3);restart_recovery='PASS';slow_trigger_isolation='PASS';no_overlap='PASS';runaway_guard='PASS';definitive_identity_rotation='PASS';ambiguous_identity_preservation='PASS';failure_backoff='PASS'}|ConvertTo-Json -Compress
}finally{
  foreach($p in $procs){Stop-Proc $p}
  try{Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue}catch{}
}
