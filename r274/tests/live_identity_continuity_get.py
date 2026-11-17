#!/usr/bin/env python3
"""GET-only Public historical identity continuity check.
Historical identifier is derived only from the user's 2026-11-17 Public
support history's 16 local-actor entries. Hash comparisons only.
This is NOT proof that the currently operating café Local still owns it.
Never echo installation IDs, tokens, or the complete HTTP response.
"""
from urllib import request
import json, hashlib, ssl, datetime

url='https://www.soknagroup.ir/public/setup/api.php?action=status'
historical_actor_hash='4a074e94e648f8755f06eef6132ac9ac5eabccb95392ff9ba9eb29b373dfec9e'
req=request.Request(url,headers={'Accept':'application/json',
   'User-Agent':'SOKNA-R274-Field-Identity-ReadOnly/1.0'},method='GET')
with request.urlopen(req,timeout=12,context=ssl.create_default_context()) as response:
   assert response.status==200 and response.geturl()==url, 'DESTINATION_UNEXPECTED_REDIRECT'
   obj=json.loads(response.read(20000))
s=obj.get('setup') or {}
identifier=s.get('installation_id') or ''
report={
 'checked_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
 'method':'GET_ONLY_NO_CREDENTIALS_NO_BUSINESS_DB_MUTATION',
 'public_setup_installed':s.get('installed') is True,
 'public_setup_paired':s.get('paired') is True,
 'public_base_url_exact':s.get('base_url')=='https://www.soknagroup.ir/public',
 'public_pairing_id_present':bool(identifier),
 'public_pairing_id_matches_historical_actor_in_public_support':(
    hashlib.sha256(identifier.encode()).hexdigest()==historical_actor_hash
    if isinstance(identifier,str) and identifier else False
 ),
 'local_current_231fg_identity_verified':False,
 'pending_guest_work_verified':False,
 'production_host_backup_verified':False,
 'operator_install_allowed':False
}
print(json.dumps(report,ensure_ascii=False,indent=2))
with open('r274-readonly-identity-continuity.json','w') as f:json.dump(report,f,indent=2)
if not (report['public_setup_installed'] and report['public_setup_paired']
  and report['public_base_url_exact'] and report['public_pairing_id_present']):
    raise SystemExit('PUBLIC_LIVE_PAIRING_BASIC_PRECHECK_FAILED')
