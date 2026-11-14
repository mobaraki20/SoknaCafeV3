#!/usr/bin/env python3
"""CI-only actual HTTP socket -> real Local guest-lan front controller -> atomic MariaDB receipt."""
import os, json, urllib.request, urllib.error, time
assert os.getenv('GITHUB_ACTIONS')=='true' and os.getenv('SOKNA_R225_DISPOSABLE_ONLY')=='YES'
fixture=json.load(open(os.environ['RUNNER_TEMP']+'/r225-fixture.json'))
base='http://127.0.0.1:18080'
passed=0

def check(p, label):
    global passed
    if not p: raise AssertionError('FAIL_R225_'+label)
    passed+=1; print('PASS_R225_'+label,flush=True)

def post(path, obj):
    raw=json.dumps(obj,ensure_ascii=False).encode()
    req=urllib.request.Request(base+path,method='POST',data=raw,headers={'Content-Type':'application/json','User-Agent':'Sokna-R225-Disposable-QA'})
    try:
        with urllib.request.urlopen(req,timeout=20) as r: status=r.status;data=r.read()
    except urllib.error.HTTPError as e: status=e.code;data=e.read()
    try: result=json.loads(data)
    except Exception: raise AssertionError('FAIL_R225_INVALID_JSON '+path+' '+str(status)+' '+repr(data[:400]))
    return status,result

for _ in range(30):
    try:
        status,ctx=post('/api/guest/table/context',{'table_token':fixture['table_token'],'device_token':'r225-http-qa-device'})
        break
    except OSError: time.sleep(1)
else:raise AssertionError('FAIL_R225_PHP_SERVER_UNAVAILABLE')
check(status==200 and ctx.get('success') is True,'TABLE_CONTEXT_REAL_HTTP')
client='r225-http-unique-client-000000001';device='r225-http-qa-device'
items=[{'id':fixture['parent'],'quantity':2,'expected_price':fixture['unit_price'],'bundle_choice_item_id':fixture['choice']}]
payload={'table_token':fixture['table_token'],'device_token':device,'client_token':client,'session_token':'','items':items,'customer_note':'R225 disposable HTTP bundle'}
st,quote=post('/api/guest/order/quote',payload)
check(st==200 and quote.get('success') is True,'QUOTE_HTTP_REAL_DB')
check(quote.get('financial_preview',{}).get('subtotal')==74000,'QUOTE_BUNDLE_PRICE_TWO_UNITS')
st,submitted=post('/api/guest/order',payload)
check(st==200 and submitted.get('success') is True and submitted.get('status')=='pending_approval','SUBMIT_PERSISTS_PENDING_REAL_HTTP')
code=submitted['order_code']
st,retry=post('/api/guest/order',payload)
check(st==200 and retry.get('success') is True and retry.get('order_code')==code,'RETRY_SAME_RESULT_NO_SECOND_ORDER')
st,listed=post('/api/guest/orders',{'table_token':fixture['table_token'],'device_token':device,'action':'list'})
check(st==200 and listed.get('success') is True and len(listed.get('orders',[]))==1,'LIST_ONE_PENDING_ORDER')
st,status=post('/api/guest/order/status',{'order_code':code,'client_token':client})
check(st==200 and status.get('status')=='pending_approval','STATUS_PENDING_BEFORE_STAFF_APPROVAL')
mutated=json.loads(json.dumps(payload));mutated['items'][0]['quantity']=3
st,bad=post('/api/guest/order',mutated)
check(st==409 and bad.get('success') is False and bad.get('code') in ('request_id_conflict','idempotency_conflict'),'REPLAY_MUTATED_PAYLOAD_REJECTED')
st,after=post('/api/guest/orders',{'table_token':fixture['table_token'],'device_token':device,'action':'list'})
check(st==200 and after.get('orders',[{}])[0].get('items',[{}])[0].get('quantity')==2,'REJECTED_REPLAY_NO_MUTATION')
with open(os.environ['RUNNER_TEMP']+'/r225-submission.json','w') as f:json.dump({'order_code':code,'client_token':client,'device_token':device},f)
print('PASS_R225_GUEST_QUOTE_SUBMIT_RETRY_HTTP_'+str(passed)+'_CHECKS',flush=True)