from pathlib import Path
import json,re,sys
R=Path(__file__).resolve().parents[1]
def need(c,m):
    if not c: print(m,file=sys.stderr); raise SystemExit(1)
contract=json.loads((R/'contracts/runtime-api/contract-v1.json').read_text(encoding='utf-8'))
need(contract['status']=='m7-implemented-contract','Runtime contract not promoted at M7')
need(contract['contract_version']=='1.0.0','Runtime contract not stable v1')
source=(R/'windows/runtime/source/Program.cs').read_text(encoding='utf-8')
for token in ['127.0.0.1','/v1/health','Authorization','X-Sokna-Runtime-Contract','FixedEquals','ServiceController','SendTrigger','SecretFile.Read','runtime-state.json']:
    need(token in source,'Runtime implementation missing '+token)
for forbidden in ['INSERT INTO','UPDATE orders','inventory_movements','settlement_records','ProcessStartInfo("powershell','cmd.exe']:
    need(forbidden not in source,'Runtime absorbed forbidden business/arbitrary-command behavior: '+forbidden)
need('LocalBaseUrl' in source and 'IsLoopback' in source,'Runtime Local endpoint is not loopback-enforced')
need('SafeLog("runtime_started")' in source and 'SafeCode(ex)' in source,'Runtime logging is not safe-code based')
local=(R/'apps/local-web/src/Runtime/RuntimeTriggerService.php').read_text(encoding='utf-8')
for token in ["'command'","'powershell'","unsupported_trigger","request_id_conflict","runtime_trigger_receipts"]: need(token in local,'Local Runtime trigger guard missing '+token)
need('business_payload' in local and 'payload' in local,'Runtime trigger forbidden business payload fence missing')
readme=(R/'windows/runtime/README.md').read_text(encoding='utf-8')
need('business' in readme.lower() and 'Print Agent' in readme,'Runtime ownership README drifted')
print('M7 Windows Runtime contract/implementation gate passed.')
