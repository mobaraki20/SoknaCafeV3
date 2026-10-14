param(
  [Parameter(Mandatory=$true)][string]$RuntimeExe
)
$ErrorActionPreference='Stop'
if(-not(Test-Path -LiteralPath $RuntimeExe -PathType Leaf)){throw "Runtime executable not found: $RuntimeExe"}
$root=Join-Path $env:RUNNER_TEMP ('sokna-runtime-105-'+[guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $root -Force|Out-Null
$serverScript=Join-Path $root 'mock_local.py'
@'
import argparse,json,threading,time
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
p=argparse.ArgumentParser();p.add_argument('--port',type=int,required=True);p.add_argument('--events',required=True);p.add_argument('--mode',default='normal');a=p.parse_args()
lock=threading.Lock();active={};seen={}
def log(row):
    with lock:
        with open(a.events,'a',encoding='utf-8') as f:f.write(json.dumps(row,separators=(',',':'))+'\n')
class H(BaseHTTPRequestHandler):
    def log_message(self,*args): pass
    def do_POST(self):
        n=int(self.headers.get('Content-Length','0'));body=json.loads(self.rfile.read(n) or b'{}');key=body.get('trigger_key','')
        with lock:
            active[key]=active.get(key,0)+1;current=active[key];seen[key]=seen.get(key,0)+1;ordinal=seen[key]
        log({'ts':time.time(),'key':key,'request_id':body.get('request_id'),'scheduled':body.get('requested_at'),'budget_ms':body.get('budget_ms'),'deadline_at':body.get('deadline_at'),'active':current,'ordinal':ordinal,'mode':a.mode})
        try:
            if key=='maintenance.slow':time.sleep(2.2)
            if a.mode=='overlap' and key=='public.relay_sync':time.sleep(1.3)
            if a.mode=='domain' and key=='public.relay_sync' and ordinal==1:
                self.send_response(422);self.end_headers();return
            if a.mode=='overload' and key=='public.relay_sync' and ordinal==1:
                self.send_response(503);self.send_header('Retry-After','2');self.end_headers();return
            if a.mode=='ambiguous' and key=='public.relay_sync' and ordinal==1:
                self.close_connection=True;return
            if a.mode=='deadline' and key=='public.relay_sync':time.sleep(10)
            self.send_response(200);self.send_header('Content-Type','application/json');self.end_headers();self.wfile.write(b'{"success":true}')
        except (BrokenPipeError,ConnectionResetError):
            pass
        finally:
            with lock:active[key]=active.get(key,1)-1
ThreadingHTTPServer(('127.0.0.1',a.port),H).serve_forever()
'@|Set-Content -LiteralPath $serverScript -Encoding utf8
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
function Start-Mock($case,[string]$mode){Start-Process -FilePath 'python' -ArgumentList @($serverScript,'--port',[string]$case.Port,'--events',$case.Events,'--mode',$mode) -PassThru -WindowStyle Hidden}
function Start-Runtime($case){Start-Process -FilePath $RuntimeExe -ArgumentList @('--console','--config',$case.Config) -PassThru -WindowStyle Hidden}
function Stop-Proc($p){if($p-and-not$p.HasExited){Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue;try{$p.WaitForExit(5000)|Out-Null}catch{}}}
function Read-Events($case){if(-not(Test-Path $case.Events)){return @()};return @(Get-Content $case.Events|Where-Object{$_}|ForEach-Object{$_|ConvertFrom-Json})}
function Wait-Health($case){$deadline=[DateTime]::UtcNow.AddSeconds(8);do{try{return Invoke-RestMethod -Uri ("http://127.0.0.1:$($case.HealthPort)/v1/health") -Headers @{Authorization=('Bearer '+$case.Secret);'X-Sokna-Runtime-Contract'='1'} -TimeoutSec 2}catch{Start-Sleep -Milliseconds 150}}while([DateTime]::UtcNow-lt$deadline);throw "Runtime health unavailable for $($case.Name)"}
function Assert-Payload($events){$relay=@($events|Where-Object{$_.key-eq'public.relay_sync'});if($relay.Count-lt1){throw 'No relay payload captured'};if([int]$relay[0].budget_ms-ne4500-or[string]::IsNullOrWhiteSpace([string]$relay[0].deadline_at)){throw 'Runtime deadline payload missing'}}
$procs=@()
try{
  $c=New-Case 'cadence' 5 $true;$s=Start-Mock $c 'normal';$procs+=$s;Start-Sleep -Milliseconds 350;$r=Start-Runtime $c;$procs+=$r
  $health=Wait-Health $c;$relayHealth=$health.triggers.'public.relay_sync';if([int]$relayHealth.configured_interval_seconds-ne5-or[int]$relayHealth.effective_interval_seconds-ne1){throw 'Legacy cadence reconciliation failed'}
  Start-Sleep -Seconds 5.2;Stop-Proc $r;$events=Read-Events $c;Assert-Payload $events;$relay=@($events|Where-Object{$_.key-eq'public.relay_sync'}|Sort-Object ts);if($relay.Count-lt4){throw 'Relay cadence produced too few calls'};$gaps=@();for($i=1;$i-lt$relay.Count;$i++){$gaps+=[double]$relay[$i].ts-[double]$relay[$i-1].ts};$avg=($gaps|Measure-Object -Average).Average;$max=($gaps|Measure-Object -Maximum).Maximum;if($avg-gt1.30-or$max-gt1.55){throw "Relay cadence too slow avg=$avg max=$max"};if(($events|Where-Object{$_.key-eq'maintenance.slow'}).Count-lt1){throw 'Slow trigger isolation missing'};Stop-Proc $s

  $o=New-Case 'overlap' 1 $false;$s2=Start-Mock $o 'overlap';$procs+=$s2;Start-Sleep -Milliseconds 350;$r2=Start-Runtime $o;$procs+=$r2;Wait-Health $o|Out-Null;Start-Sleep -Seconds 4.8;Stop-Proc $r2;Stop-Proc $s2;$oe=@(Read-Events $o|Where-Object{$_.key-eq'public.relay_sync'}|Sort-Object ts);if($oe.Count-lt2){throw 'No-overlap case too few calls'};$maxActive=($oe|Measure-Object -Property active -Maximum).Maximum;if([int]$maxActive-ne1){throw 'Per-trigger overlap detected'};$ogaps=@();for($i=1;$i-lt$oe.Count;$i++){$ogaps+=[double]$oe[$i].ts-[double]$oe[$i-1].ts};$omin=($ogaps|Measure-Object -Minimum).Minimum;if([double]$omin-lt1.10){throw "Catch-up burst detected min_gap=$omin"};if($oe.Count-gt5){throw "Runaway sequential loop detected count=$($oe.Count)"}

  $d=New-Case 'domain' 1 $false;$s3=Start-Mock $d 'domain';$procs+=$s3;Start-Sleep -Milliseconds 350;$r3=Start-Runtime $d;$procs+=$r3;Wait-Health $d|Out-Null;Start-Sleep -Seconds 6.6;Stop-Proc $r3;Stop-Proc $s3;$de=@(Read-Events $d|Where-Object{$_.key-eq'public.relay_sync'}|Sort-Object ts);if($de.Count-lt2){throw 'Domain failure case too few calls'};if([string]$de[0].request_id-eq[string]$de[1].request_id){throw 'Definitive domain failure did not rotate identity'};if(([double]$de[1].ts-[double]$de[0].ts)-lt4.8){throw 'Definitive domain failure ignored safety backoff'}

  $v=New-Case 'overload503' 1 $false;$s4=Start-Mock $v 'overload';$procs+=$s4;Start-Sleep -Milliseconds 350;$r4=Start-Runtime $v;$procs+=$r4;Wait-Health $v|Out-Null;Start-Sleep -Seconds 3.6;Stop-Proc $r4;Stop-Proc $s4;$ve=@(Read-Events $v|Where-Object{$_.key-eq'public.relay_sync'}|Sort-Object ts);if($ve.Count-lt2){throw '503 overload case too few calls'};if([string]$ve[0].request_id-ne[string]$ve[1].request_id){throw '503 transient retry changed request identity'};if(([double]$ve[1].ts-[double]$ve[0].ts)-lt1.9){throw 'Retry-After was not respected'}

  $a=New-Case 'ambiguous' 1 $false;$s5=Start-Mock $a 'ambiguous';$procs+=$s5;Start-Sleep -Milliseconds 350;$r5=Start-Runtime $a;$procs+=$r5;Wait-Health $a|Out-Null;Start-Sleep -Seconds 3.2;Stop-Proc $r5;Stop-Proc $s5;$ae=@(Read-Events $a|Where-Object{$_.key-eq'public.relay_sync'}|Sort-Object ts);if($ae.Count-lt2){throw 'Ambiguous transport case too few calls'};if([string]$ae[0].request_id-ne[string]$ae[1].request_id){throw 'Ambiguous transport retry changed identity'}

  $x=New-Case 'deadline' 1 $false;$s6=Start-Mock $x 'deadline';$procs+=$s6;Start-Sleep -Milliseconds 350;$r6=Start-Runtime $x;$procs+=$r6;Wait-Health $x|Out-Null;Start-Sleep -Seconds 4.9;$xh=Wait-Health $x;$xe=Read-Events $x;Assert-Payload $xe;$last=[string]$xh.triggers.'public.relay_sync'.last_error_code;if($last-ne'trigger_deadline_exceeded'){throw "Deadline not enforced; last_error=$last"};Stop-Proc $r6;Stop-Proc $s6

  [ordered]@{result='PASS';runtime_version='1.0.5';legacy_config_effective_interval=1;cadence_average_gap_seconds=[Math]::Round($avg,3);cadence_max_gap_seconds=[Math]::Round($max,3);overlap_case_event_count=$oe.Count;overlap_case_min_start_gap_seconds=[Math]::Round([double]$omin,3);slow_trigger_isolation='PASS';no_overlap='PASS';no_catchup_burst='PASS';domain_4xx_identity_rotation='PASS';overload_503_identity_preservation='PASS';retry_after='PASS';ambiguous_transport_identity_preservation='PASS';trigger_deadline_4500ms='PASS';deadline_payload='PASS'}|ConvertTo-Json -Compress
}finally{foreach($p in $procs){Stop-Proc $p};try{Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue}catch{}}
