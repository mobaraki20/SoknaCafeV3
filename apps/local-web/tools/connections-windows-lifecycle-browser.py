from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path('/mnt/data/connections_fix_231ao/evidence');OUT.mkdir(parents=True,exist_ok=True)
src=(ROOT/'public/assets/connections-workspace.js').read_text(encoding='utf-8')
cut=src.index('function render(){')
test_js=src[:cut]+"window.__renderWindowsTest=s=>{snap=s;renderWindows()};})();"
html='''<!doctype html><html lang="fa" dir="rtl"><body><section data-connections-workspace data-api="/api" data-csrf="t">
<span data-windows-badge></span><strong data-windows-runtime-version></strong><small data-windows-runtime-state></small><strong data-windows-print-version></strong><small data-windows-print-state></small><strong data-windows-url></strong><strong data-windows-provisioning></strong><small data-windows-expiry></small>
<input data-windows-name value="دستگاه اصلی"><select data-windows-agent></select><button data-windows-initial>initial</button><button data-windows-reconnect hidden>reconnect</button><button data-windows-cancel>cancel</button><div data-windows-feedback hidden></div><div data-windows-connections></div><div data-windows-pairing-history></div>
</section></body></html>'''
connected={
 'windows_services':{'state':'confirmed','association_active':True,'local_base_url':'http://127.0.0.1:18080/','history':[{'state':'confirmed','mode':'initial','print_agent_id':7,'display_name':'دستگاه اصلی','created_at':'2026-10-17T18:00:00Z'}]},
 'windows_runtime':{'status':'running','runtime_version':'1.0.5','contract_version':1,'scheduler':{'cycle_age_seconds':4}},
 'print_agents':[
  {'id':7,'name':'دستگاه اصلی','active':1,'connection_status':'connected','hostname':'SOKNARESTAURANT','agent_version':'6.2.9','last_heartbeat_at':'2026-10-17T18:10:00Z'},
  {'id':6,'name':'قدیمی','active':1,'connection_status':'stale','hostname':'SOKNARESTAURANT','agent_version':'6.2.9','last_heartbeat_at':'2026-10-16T18:10:00Z'},
  {'id':5,'name':'بازنشسته','active':0,'connection_status':'inactive','hostname':'OLD','agent_version':'6.2.9','retired_at':'2026-10-15T18:10:00Z'}],
 'errors':{}
}
empty={'windows_services':{'state':'not_created','association_active':False,'local_base_url':'http://127.0.0.1:18080/','history':[]},'windows_runtime':{'status':'not_seen','runtime_version':'','contract_version':1},'print_agents':[],'errors':{}}
issues=[]
with sync_playwright() as p:
    b=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox'])
    page=b.new_page(viewport={'width':1280,'height':720});errs=[];page.on('pageerror',lambda e:errs.append(str(e)))
    page.set_content(html);page.evaluate("window.SoknaLocale={dateTime:v=>String(v),instantDateTime:v=>String(v),integer:v=>String(v)};window.SoknaUI={toast:()=>{},confirm:async()=>true,busy:async(_e,fn)=>await fn()}")
    page.add_script_tag(content=test_js)
    page.evaluate('(s)=>window.__renderWindowsTest(s)',connected)
    if page.locator('[data-windows-badge]').inner_text()!='متصل': issues.append('live badge not connected')
    if page.locator('[data-windows-print-state]').inner_text().find('۱ Agent')<0 and page.locator('[data-windows-print-state]').inner_text().find('1 Agent')<0: issues.append('connected agent count wrong')
    if page.locator('[data-windows-initial]').is_visible(): issues.append('initial pairing visible while active agents exist')
    if not page.locator('[data-windows-reconnect]').is_visible(): issues.append('reconnect hidden while active agent exists')
    if page.locator('[data-windows-connections] [data-windows-retire-agent]').count()!=2: issues.append('active/stale retire actions count wrong')
    txt=page.locator('[data-windows-connections]').inner_text()
    if 'ارتباط قدیمی' not in txt or 'بازنشسته' not in txt: issues.append('stale/retired states not rendered')
    if 'راه‌اندازی کامل' not in page.locator('[data-windows-pairing-history]').inner_text(): issues.append('pairing history not rendered')
    page.screenshot(path=str(OUT/'connections-windows-connected.png'),full_page=True)
    page.evaluate('(s)=>window.__renderWindowsTest(s)',empty)
    if page.locator('[data-windows-badge]').inner_text()!='متصل نیست': issues.append('empty badge not disconnected')
    if not page.locator('[data-windows-initial]').is_visible(): issues.append('initial pairing hidden on empty state')
    if page.locator('[data-windows-reconnect]').is_visible(): issues.append('reconnect visible on empty state')
    page.screenshot(path=str(OUT/'connections-windows-empty.png'),full_page=True)
    if errs: issues.extend('pageerror '+e for e in errs)
    b.close()
if issues:
    print('CONNECTIONS_WINDOWS_LIFECYCLE_BROWSER_FAIL')
    [print('-',x) for x in issues]
    raise SystemExit(1)
print('CONNECTIONS_WINDOWS_LIFECYCLE_BROWSER_PASS checks=10')
