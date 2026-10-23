from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path('/mnt/data/connections_fix_231ao/evidence');OUT.mkdir(parents=True,exist_ok=True)
idx=(ROOT/'public/integrations/index.php').read_text(encoding='utf-8')
start=idx.index('  <section class="sc-work-panel" data-panel="windows" hidden>')
end=idx.index('  </section>\n</section>',start)+len('  </section>')
section=idx[start:end].replace('data-panel="windows" hidden','data-panel="windows"')
css=(ROOT/'public/assets/product-ui.css').read_text(encoding='utf-8')+'\n'+(ROOT/'public/assets/product-redesign.css').read_text(encoding='utf-8')
src=(ROOT/'public/assets/connections-workspace.js').read_text(encoding='utf-8');cut=src.index('function render(){');test_js=src[:cut]+"window.__renderWindowsTest=s=>{snap=s;renderWindows()};})();"
html=f'''<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><style>{css}\nbody{{margin:0;padding:24px;background:#f7f7f5}}.sc-workspace{{max-width:1400px;margin:auto}}</style></head><body><section class="sc-workspace" data-connections-workspace data-api="/api" data-csrf="t">{section}</section></body></html>'''
snap={'windows_services':{'state':'confirmed','association_active':True,'local_base_url':'http://127.0.0.1:18080/','history':[{'state':'confirmed','mode':'initial','print_agent_id':7,'display_name':'دستگاه اصلی سکنا','created_at':'2026-10-17T18:00:00Z'},{'state':'retired','mode':'initial','print_agent_id':5,'display_name':'دستگاه قدیمی','created_at':'2026-10-10T18:00:00Z','retired_at':'2026-10-17T17:00:00Z'}]},'windows_runtime':{'status':'running','runtime_version':'1.0.5','contract_version':1,'scheduler':{'cycle_age_seconds':4}},'print_agents':[{'id':7,'name':'دستگاه اصلی سکنا','active':1,'connection_status':'connected','hostname':'SOKNARESTAURANT','agent_version':'6.2.9','last_heartbeat_at':'2026-10-17T18:10:00Z'},{'id':6,'name':'اتصال قدیمی','active':1,'connection_status':'stale','hostname':'SOKNARESTAURANT','agent_version':'6.2.9','last_heartbeat_at':'2026-10-16T18:10:00Z'},{'id':5,'name':'دستگاه بازنشسته','active':0,'connection_status':'inactive','hostname':'OLD','agent_version':'6.2.9','retired_at':'2026-10-17T17:00:00Z'}],'errors':{}}
issues=[]
with sync_playwright() as p:
 b=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
 for name,w,h in [('desktop',1440,1000),('mobile',390,1100)]:
  page=b.new_page(viewport={'width':w,'height':h}); errs=[]; page.on('pageerror',lambda e,errs=errs:errs.append(str(e)))
  page.set_content(html);page.evaluate("window.SoknaLocale={dateTime:v=>String(v),instantDateTime:v=>String(v),integer:v=>String(v)};window.SoknaUI={toast:()=>{},confirm:async()=>true,busy:async(_e,fn)=>await fn()}");page.add_script_tag(content=test_js);page.evaluate('(s)=>window.__renderWindowsTest(s)',snap)
  overflow=page.evaluate('document.documentElement.scrollWidth>document.documentElement.clientWidth+2')
  if overflow: issues.append(f'{name} horizontal overflow')
  if page.locator('[data-windows-badge]').inner_text()!='متصل': issues.append(f'{name} connected badge wrong')
  page.screenshot(path=str(OUT/f'connections-windows-{name}-styled.png'),full_page=True)
  if errs: issues += [f'{name} pageerror {e}' for e in errs]
  page.close()
 b.close()
if issues:
 print('CONNECTIONS_WINDOWS_LIFECYCLE_VISUAL_FAIL');[print('-',x) for x in issues];raise SystemExit(1)
print('CONNECTIONS_WINDOWS_LIFECYCLE_VISUAL_PASS desktop=1440 mobile=390 no-overflow')
