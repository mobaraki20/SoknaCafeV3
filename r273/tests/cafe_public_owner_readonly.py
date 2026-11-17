#!/usr/bin/env python3
"""GET-only field evidence for actual paired destination; no credentials.
Sanitize setup's session CSRF and installation ID: never print or persist them.
The GET setup endpoint creates a disposable web session; no business mutation.
"""
import json, urllib.request, urllib.error, hashlib, os, ssl, datetime
BASE="https://www.soknagroup.ir/public"
R266_OWNER_JS_SHA="4fbf205611ab5652ecc4550311119a5e144e81662debaf23ca931bee01e65154"

def get(url,limit=100000):
  out={"url":url,"http_status":None,"final_url":None}
  try:
    request=urllib.request.Request(url,method="GET",headers={"User-Agent":"SOKNA-R273-owner-diagnostic/1.0","Cache-Control":"no-cache","Accept":"application/json, text/javascript, text/html"})
    with urllib.request.urlopen(request,timeout=14,context=ssl.create_default_context()) as resp:
      out["http_status"]=resp.status
      out["final_url"]=resp.geturl()
      body=resp.read(limit+1)
      out["truncated"]=len(body)>limit
      return out,body[:limit]
  except urllib.error.HTTPError as e:
    out["http_status"]=e.code
    out["final_url"]=e.geturl()
    return out,b""
  except Exception as e:
    out["error"]=type(e).__name__
    return out,b""

def main():
  setup,body=get(BASE+"/setup/api.php?action=status")
  if setup.get("http_status")==200 and body and not setup.get("truncated"):
    try:
      obj=json.loads(body)
      s=obj.get("setup") or {}
      setup["reported"]={
        "success":obj.get("success") is True,
        "installed":s.get("installed") is True,
        "paired":s.get("paired") is True,
        "installation_id_present":bool(str(s.get("installation_id") or "")),
        "base_url":s.get("base_url"),
        "base_path":s.get("base_path"),
        "version":s.get("version")
      }
      # Never log or persist installation_id, full setup, or session CSRF.
      setup["base_url_matches_requested"]=str(s.get("base_url","")).rstrip("/")==BASE
    except (ValueError,TypeError):setup["parse"]="not_json"
  js,jsbody=get(BASE+"/assets/sokna-public-move.js")
  if js.get("http_status")==200 and jsbody and not js.get("truncated"):
    js["sha256"]=hashlib.sha256(jsbody).hexdigest()
    js["matches_exact_r266_owner_js"]=js["sha256"]==R266_OWNER_JS_SHA
    try:
      contents=jsbody.decode("utf-8")
      js["supports_existing_paired_owner_offer"]="offer_existing" in contents and "existing_paired" in contents
    except UnicodeError:js["parse"]="not_utf8"
  owner_page,_=get(BASE+"/move/approve")
  out={
    "format":"sokna-r273-live-public-owner-readonly-v1",
    "checked_utc":datetime.datetime.now(datetime.timezone.utc).isoformat(),
    "method":"HTTPS_GET_ONLY_NO_LOGIN_NO_BUSINESS_DB_MUTATION",
    "setup_status":setup,
    "owner_browser_script":js,
    "owner_approval_page":owner_page,
    "conclusion":"LIVE_PAIRING_CAN_ONLY_BE_COMPARED_TO_LOCAL_WITH_AUTHORIZED_LOCAL_ID",
    "never_proves":["pairing equals cafe Local","pending guest orders","owner authorized session","real Emergency wrapper bytes","backup/restore","production installation authorization"],
    "operator_install_allowed":False
  }
  print(json.dumps(out,ensure_ascii=False,indent=2))
  dest=os.environ.get("R273_DIAGNOSTIC_JSON","r273-live-owner-connection-diagnostic.json")
  with open(dest,"w",encoding="utf-8") as f:json.dump(out,f,ensure_ascii=False,indent=2)
  return 0 if setup.get("http_status")==200 and js.get("http_status")==200 else 2
if __name__=="__main__":
  raise SystemExit(main())
