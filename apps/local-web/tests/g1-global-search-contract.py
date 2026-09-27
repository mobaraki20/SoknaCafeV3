#!/usr/bin/env python3
from pathlib import Path
import re, sys
root=Path(__file__).resolve().parents[1]
errors=[]
def need(path, text, msg):
    data=(root/path).read_text(encoding='utf-8')
    if text not in data: errors.append(msg)
    return data

gs=need('src/Search/GlobalSearchService.php','SearchNormalizer::normalize','global search must normalize input')
need('src/Search/GlobalSearchService.php','SearchNormalizer::length($query)<2','global search must enforce min query length')
providers=['CatalogSearchProvider.php','InventorySearchProvider.php','FinanceSearchProvider.php','SubscriberSearchProvider.php','AdminSearchProvider.php']
for f in providers:
    d=need('src/Search/'+f,'LIKE ?','provider must use bounded prefix LIKE: '+f)
    if re.search(r"LIKE\s+['\"]%", d, re.I): errors.append('leading wildcard query forbidden: '+f)
normalizer=need('src/Search/SearchNormalizer.php',"'ي'=>'ی'",'Persian yeh normalization missing')
need('src/Search/SearchNormalizer.php',"'ك'=>'ک'",'Persian kaf normalization missing')
need('src/Search/SearchNormalizer.php',"'۰'=>'0'",'Persian digit normalization missing')
need('src/Search/SearchNormalizer.php',".'%'",'prefix helper must append wildcard at end')
inv=need('src/Search/InventorySearchProvider.php','تأمین / خرید','inventory search must expose supply/purchase contextual action')
need('src/Search/FinanceSearchProvider.php','cashier_accounts','finance search must be capability-aware')
need('src/Search/SubscriberSearchProvider.php','cashier_accounts','subscriber search must be capability-aware')
need('src/Search/AdminSearchProvider.php',"role']??'')!=='admin'",'admin search must be admin-only')
need('src/Search/CatalogSearchProvider.php','orders_floor','catalog search must respect ordering capability')
endpoint=need('public/search/api.php','currentUser()','search endpoint must require authenticated user')
need('public/search/api.php',"REQUEST_METHOD",'search endpoint must be read-only GET')
shell=need('src/UI/ProductShell.php','data-global-search','global search must live in product shell')
need('src/UI/ProductShell.php','/assets/global-search.js','shell must load global search JS')
js=need('public/assets/global-search.js','AbortController','search JS must cancel stale requests')
need('public/assets/global-search.js','260','search JS must debounce keystrokes')
need('public/assets/global-search.js',"ctrlKey||e.metaKey",'Ctrl/Cmd+K shortcut missing')
if 'innerHTML' in js: errors.append('global search JS must not use innerHTML')
migration=need('database/migrations/0018_g1_global_search.sql','idx_g16c_items_active_name','catalog search index missing')
for idx in ['idx_g16c_categories_active_name','idx_g16c_menus_name','idx_g16c_users_active_display','idx_g16c_tables_active_name']:
    if idx not in migration: errors.append('search index missing: '+idx)
for target in ['catalog-workspace.js','admin-workspace.js','operations-workspace.js','finance-workspace.js']:
    need('public/assets/'+target,'URLSearchParams(location.search)','deep-link handling missing: '+target)
if errors:
    print('G1 global search contract FAILED')
    for e in errors: print('- '+e)
    sys.exit(1)
print('G1 global search contract PASS')
