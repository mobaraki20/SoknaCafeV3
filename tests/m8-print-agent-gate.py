from pathlib import Path
import json,sys,re
R=Path(__file__).resolve().parents[1]
def need(x,m):
    if not x: print(m,file=sys.stderr); raise SystemExit(1)
for name in ['server-wire-v4.json','loopback-v1.json']:
    d=json.loads((R/'contracts/print-agent-api'/name).read_text(encoding='utf-8'))
    need(d.get('status')=='m8-implemented-retained-contract',name+' not promoted')
src=R/'windows/print-agent/source'
need((src/'src/Sokna.PrintAgent.Worker/WinspoolAdapter.cs').exists(),'Winspool adapter missing')
need((src/'src/Sokna.PrintAgent.Service/LoopbackBridgeServer.cs').exists(),'loopback bridge missing')
need((src/'src/Sokna.PrintAgent.Core/LocalQueueStore.cs').exists(),'durable local queue missing')
text='\n'.join(p.read_text(encoding='utf-8',errors='ignore') for p in src.rglob('*.cs'))
for token in ['local_receipt','recovery_hold','unknown','content_sha256','127.0.0.1']:
    need(token.lower() in text.lower(),'retained Print Agent missing '+token)
local=(R/'apps/local-web/src/Domain/Printing/PrintService.php').read_text(encoding='utf-8')
for token in ['claimTx','local_receipt_id','content_sha256',"'unknown'","'recovery_hold'",'resolveAmbiguous']:
    need(token in local,'Local Print owner missing '+token)
need('Winspool' not in local,'Local owns Winspool unexpectedly')
orders=(R/'apps/local-web/src/Domain/Orders/OrderCommitService.php').read_text(encoding='utf-8'); settle=(R/'apps/local-web/src/Domain/Finance/SettlementService.php').read_text(encoding='utf-8')
need('enqueueOrderTx' in orders and 'enqueueSettlementTx' in settle,'business owners do not emit secondary print intent')
need('INSERT INTO print_jobs' not in orders and 'INSERT INTO print_jobs' not in settle,'business owner duplicates print queue SQL')
print('M8 Print Agent/Local boundary gate passed.')
