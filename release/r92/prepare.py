import pathlib,zipfile,hashlib,io,json,shutil
R=pathlib.Path(__file__).resolve().parent;B=R/'build';B.mkdir(exist_ok=True)
expected={'local':'6dfc75e609591758404a40bf251c0fde200c6a0a594c4974ff3c0ea633d33408','public':'553fa5c9d210b50c8cda28508cdd1dc9ead7c6724f4b2e8e15a9d753b3362632'}
for kind,h in expected.items():
 p=R/'baseline'/('SOKNA-'+kind.upper()+'-UNIVERSAL-UPDATE.zip');assert hashlib.sha256(p.read_bytes()).hexdigest()==h
 with zipfile.ZipFile(p) as z:
  if kind=='local':
   m=json.loads(z.read('release-set.json'));w=zipfile.ZipFile(io.BytesIO(z.read(m['artifacts']['local-web']['path'])));data={n:w.read(n) for n in w.namelist()}
  else:
   m=json.loads(z.read('manifest.json'));data={x['path']:z.read('payload/'+x['path']) for x in m['files']}
 for mode in ['before','after']:
  for n,b in data.items():
   p=B/mode/kind/n;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(b)
 p=B/'after'/kind/'resources/shared-staff-ui/dashboard-workspace.php';s=p.read_text();old='<i class="<?=$h===0?\'is-zero\':\'\'?>" style="height:<?=$h?>%"></i>';assert s.count(old)==1
 new='''<svg class="sc-dashboard-column <?=$h===0?'is-zero':''?>" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true"><defs><linearGradient id="sc-trend-<?=$trendIndex?>" x1="0" x2="0" y1="0" y2="1"><stop class="sc-dashboard-column__start" offset="0"/><stop class="sc-dashboard-column__end" offset="1"/></linearGradient></defs><rect x="0" y="<?=100-$h?>" width="100" height="<?=$h?>" rx="20" ry="4" fill="url(#sc-trend-<?=$trendIndex?>)"/></svg>'''
 s=s.replace('<?php foreach($weekDaily as $row):','<?php $trendIndex=0; foreach($weekDaily as $row): ++$trendIndex;',1).replace(old,new);p.write_text(s)
 p=B/'after'/kind/('public/assets/product-ui.css' if kind=='local' else 'assets/product-ui.css');s=p.read_text()
 old='.sc-dashboard-bar__track i{z-index:1;inline-size:min(38px,72%);min-block-size:4px;border-radius:8px 8px 2px 2px;background:linear-gradient(180deg,color-mix(in srgb,var(--sc-accent) 50%,white),var(--sc-accent));opacity:.82}.sc-dashboard-bar:last-child .sc-dashboard-bar__track i{opacity:1;background:linear-gradient(180deg,color-mix(in srgb,var(--sc-accent) 26%,white),var(--sc-accent-strong))}'
 new='.sc-dashboard-column{z-index:1;inline-size:min(38px,72%);block-size:100%;display:block;opacity:.82}.sc-dashboard-column__start{stop-color:color-mix(in srgb,var(--sc-accent) 50%,white)}.sc-dashboard-column__end{stop-color:var(--sc-accent)}.sc-dashboard-bar:last-child .sc-dashboard-column{opacity:1}.sc-dashboard-bar:last-child .sc-dashboard-column__start{stop-color:color-mix(in srgb,var(--sc-accent) 26%,white)}.sc-dashboard-bar:last-child .sc-dashboard-column__end{stop-color:var(--sc-accent-strong)}'
 assert s.count(old)==1;s=s.replace(old,new);old='.sc-dashboard-bar__track i.is-zero{min-block-size:0;block-size:0;opacity:0}';assert s.count(old)==1;s=s.replace(old,'.sc-dashboard-column.is-zero{opacity:0}');p.write_text(s)
 print(kind,'exact published source reconstructed and two existing presentation files patched')
