from pathlib import Path
import json,sys
from playwright.sync_api import sync_playwright
f=json.loads(Path(sys.argv[1]).read_text());out=Path(sys.argv[2]);out.mkdir(exist_ok=True)
# Compare endpoint authorization against the explicit canonical capability map.
roles={'cashier':(True,False,False,False,True),'supervisor':(True,True,True,False,False),'stock':(False,False,True,False,False),'cost':(False,False,True,True,False),'stock-supervisor':(True,True,True,False,False),'cost-supervisor':(True,True,True,True,False),'staff-report':(True,False,False,False,False),'floor':(False,False,False,False,False),'prep':(False,False,True,False,False)}
cost_fields={'average_unit_cost','last_purchase_base_unit_cost','last_purchase_unit_price','last_base_unit_cost','last_receipt_total_cost','unit_cost_snapshot','actual_total_cost','total_cost','total_cost_delta','raw_total_cost','previous_total_cost','corrected_total_cost','normalized_unit_cost','last_unit_cost','weighted_average_before','weighted_average_after','delta_last_percent','delta_average_percent','metadata_json','purchase_price_change'}
def values(obj):
 if isinstance(obj,dict):
  for k,v in obj.items():
   if k in cost_fields:yield k,v
   else:yield from values(v)
 elif isinstance(obj,list):
  for v in obj:yield from values(v)
def req(c,host,path,allowed,allow_unavailable=False):
 r=c.request.get(f[host]+path,max_redirects=0)
 if allowed:
  assert r.status==200 or (allow_unavailable and r.status in [404,409]),(host,path,r.status)
 else:assert r.status==403,(host,path,r.status)
 return r.json() if r.status==200 else {}
results=[]
with sync_playwright() as p:
 b=p.chromium.launch(headless=True,args=['--no-sandbox'])
 for host in ['local','public']:
  for name,(report,audit,ops,cost,cashier) in roles.items():
   c=b.new_context(viewport={'width':390,'height':844});page=c.new_page()
   try:
    data={'username':f['roles'][name],'password':f['password']}
    if host=='public':data['installation_id']=f['installation_id']
    login=c.request.post(f[host]+('/login.php' if host=='local' else '/staff/login'),form=data,max_redirects=0);assert login.status in (302,303),login.status
    rp='/reports/api.php' if host=='local' else '/staff/reports-adapter'
    op='/operations/api.php' if host=='local' else '/staff/operations-adapter'
    req(c,host,rp+'?action=report',report)
    req(c,host,rp+'?action=activity',audit,host=='public')
    snap=req(c,host,op+'?action=snapshot',ops).get('snapshot',{})
    if ops:
     assert snap['permissions']['can_view_cost']==cost,(name,snap.get('permissions'))
     fields=list(values(snap))
     if not cost:assert all(v is None for k,v in fields),(name,[k for k,v in fields if v is not None])
     else:assert any(v is not None for k,v in fields if k!='metadata_json'),'positive cost fixture missing'
    if host=='local':
     req(c,host,rp+'?action=pricing',audit and cost)
    else:
     req(c,host,'/api/staff/read?model=inventory_cost',cost)
     raw=req(c,host,'/api/staff/read?model=operations_workspace',ops).get('payload',{})
     if ops and not cost:assert all(v is None for k,v in values(raw))
     report_raw=req(c,host,'/api/staff/read?model=reports',report).get('payload',{})
     if report and not cashier:assert 'invoice_history_archive' not in report_raw
     req(c,host,rp+'?action=invoice_history',cashier,True)
     # Activity must not leak through a generic report or raw-model read.
     if report and not audit and 'activity' in report_raw and report_raw['activity'] is not None:
      print('OBSERVATION non-supervisor raw reports carries activity',name,flush=True)
    if ops and name in ['stock','cost','stock-supervisor','cost-supervisor']:
     response=page.goto(f[host]+('/operations/' if host=='local' else '/staff/operations/'),wait_until='domcontentloaded');assert response.status==200
     page.locator('[data-operations-workspace]').wait_for(state='visible',timeout=10000)
     page.screenshot(path=str(out/(host+'-'+name+'.png')),animations='disabled')
    results.append({'host':host,'role':name,'status':'PASS'});print('PASS',host,name,flush=True)
   except Exception as e:
    results.append({'host':host,'role':name,'status':'FAIL','error':str(e)[:500]});print('FAIL',host,name,str(e)[:500],flush=True)
    try:page.screenshot(path=str(out/(host+'-'+name+'-FAIL.png')))
    except Exception:pass
   finally:c.close()
 b.close()
(out/'result.json').write_text(json.dumps(results,ensure_ascii=False,indent=2))
assert all(x['status']=='PASS' for x in results)
