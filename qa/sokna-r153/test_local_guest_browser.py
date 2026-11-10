import json, os
from pathlib import Path
from playwright.sync_api import sync_playwright
p=Path(os.environ.get('SOKNA_R153_SOURCE_DIR','/mnt/data/sokna-current-r152'))/'working-local/public/assets/scds/guest.js'
js=p.read_text()
items=[{'id':10,'name':'چای زعفرانی','description':'نمونه آزمایش','price':100000,'available':True,'order_available':True,'variant_family_id':3,'variant_members':[{'item_id':11,'label':'تک نفره'},{'item_id':12,'label':'دو نفره'},{'item_id':13,'label':'بزرگ'}]},
{'id':11,'name':'تک نفره','price':100000,'available':True,'order_available':True},
{'id':12,'name':'دو نفره','price':170000,'available':True,'order_available':True},
{'id':13,'name':'بزرگ','price':230000,'available':True,'order_available':True}]
html='''<!doctype html><html lang="fa"><body class="sg-body {mode}"><div data-sg-app data-table-token="{token}" data-transport-mode="local_lan" data-ordering-enabled="1" data-connectivity-state="fresh">
 <script type="application/json" data-sg-items-json>{catalog}</script><button data-sg-open-detail="10">مشاهده</button>
 <section data-sg-item-modal hidden><div class="sg-item-detail-card"><div data-sg-detail-media></div><h2 data-sg-detail-title></h2><span data-sg-detail-price></span><p data-sg-detail-description></p><p data-sg-detail-state></p><div data-sg-detail-options hidden></div><label data-sg-detail-note-wrap><input data-sg-detail-note></label><div data-sg-suggestions></div><footer class="sg-item-detail-footer"><div data-sg-detail-controls><button data-sg-detail-dec>-</button><output data-sg-detail-qty>۱</output><button data-sg-detail-inc>+</button></div><button data-sg-detail-add>افزودن</button></footer><button data-sg-close-layer>بستن</button></div></section>
</div></body></html>'''
with sync_playwright() as pl:
 browser=pl.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage'])
 for context,mode,token in [('public-browse','sg-mode-public',''),('table-order','sg-mode-table','qa-token')]:
  pg=browser.new_page(viewport={'width':390,'height':844});errors=[];pg.on('pageerror',lambda e:errors.append(str(e)))
  pg.set_content(html.format(mode=mode,token=token,catalog=json.dumps(items,ensure_ascii=False)))
  pg.evaluate(js)
  pg.locator('[data-sg-open-detail]').click()
  data=pg.evaluate('''() => ({tags:[...document.querySelectorAll('.sg-detail-option')].map(x=>x.tagName),labels:[...document.querySelectorAll('.sg-detail-option span')].map(x=>x.textContent),prices:[...document.querySelectorAll('.sg-detail-option small')].map(x=>x.textContent),visibleControls: !document.querySelector('[data-sg-detail-controls]').hidden, visibleFooter:!document.querySelector('.sg-item-detail-footer').hidden, visibleNote:!document.querySelector('[data-sg-detail-note-wrap]').hidden, addHidden: document.querySelector('[data-sg-detail-add]').hidden, selectableCount:document.querySelectorAll('[data-sg-variant-item]').length})''')
  assert not errors, (context,errors)
  assert len(data['tags'])==3,(context,data)
  assert data['labels']==['تک نفره','دو نفره','بزرگ'], (context,data)
  assert len(set(data['prices']))==3, (context,data)
  if not token:
   assert data['tags']==['DIV']*3 and not data['visibleControls'] and not data['visibleFooter'] and not data['visibleNote'] and data['addHidden'] and data['selectableCount']==0,(context,data)
  else:
   assert data['tags']==['BUTTON']*3 and data['visibleControls'] and data['visibleFooter'] and data['selectableCount']==3,(context,data)
  print('PASS '+context,json.dumps(data,ensure_ascii=False))
  pg.close()
 browser.close()