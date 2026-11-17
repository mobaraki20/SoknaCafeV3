#!/usr/bin/env python3
"""Read-only public host/QR HTTP probe for the R273 cafe rollout.
Does not log in, send POST requests, mutate state, or infer database emptiness.
This is a FIELD CONTEXT probe; it NEVER authorizes deployment.
"""
import json, os, sys, ssl, urllib.request, urllib.error, datetime

def probe(url, expect_json=False):
    req=urllib.request.Request(url,headers={"Accept":"application/json" if expect_json else "text/html","User-Agent":"SOKNA-R273-ReadOnly-Host-Qualification/1.0"},method="GET")
    result={"url":url,"reachable":False,"http_status":None,"final_url":None,"https":False,"redirected":False}
    try:
        with urllib.request.urlopen(req,timeout=12,context=ssl.create_default_context()) as res:
            result.update(http_status=res.status,final_url=res.geturl(),https=res.geturl().startswith("https://"),redirected=res.geturl()!=url,reachable=True)
            if expect_json:
                try:
                    data=json.load(res)
                    if isinstance(data,dict):
                        result["health"]={k:data.get(k) for k in ("ok","component","database","version","pending_migrations","applied_migrations","catalog_migrations") if k in data}
                except (ValueError,UnicodeError) as e:result["parse_error"]=type(e).__name__
    except urllib.error.HTTPError as e:
        result["http_status"]=e.code
        result["final_url"]=e.geturl()
        result["error_type"]="HTTPError"
    except Exception as e:
        result["error_type"]=type(e).__name__
        result["error_brief"]=str(e)[:170]
    return result

def main():
    host=os.environ["R273_CAFE_TARGET_PUBLIC_HEALTH"].strip()
    qr=os.environ["R273_CAFE_OLD_QR_URL"].strip()
    menu=os.environ["R273_CAFE_DEST_MENU_URL"].strip()
    if not all(url.startswith("https://") for url in [host,qr,menu]):
        raise SystemExit("HTTPS_ONLY_READONLY_PROBE")
    health=probe(host,True)
    old_qr=probe(qr)
    new_menu=probe(menu)
    h=health.get("health",{})
    checks={
      "public_https_health_reachable":bool(health["http_status"]==200 and health["https"]),
      "public_db_ready":h.get("database")=="ready" and h.get("pending_migrations")==0,
      "target_still_r266_35ig":isinstance(h.get("version"),str) and ".35ig-" in h["version"],
      "destination_menu_reachable":bool(new_menu["http_status"]==200 and new_menu["https"]),
      "old_qr_reaches_expected_destination":bool(old_qr["http_status"]==200 and old_qr["https"] and old_qr.get("final_url","").rstrip("/")==menu.rstrip("/")),
    }
    result={
      "format":"sokna-r273-cafe-readonly-http-preflight-v1",
      "time_utc":datetime.datetime.now(datetime.timezone.utc).isoformat(),
      "method":"GET_ONLY_NO_AUTH_NO_MUTATION",
      "probe": {"health":health,"old_qr":old_qr,"new_menu":new_menu},
      "checks":checks,
      "pass":all(checks.values()),
      "never_proves":["live install/pairing identity","pending guest orders","backup integrity","protected Emergency wrapper bytes","owner browser consent","production deployment approval"],
      "operator_install_allowed":False
    }
    print(json.dumps(result,ensure_ascii=False,indent=2))
    with open(os.environ.get("R273_READONLY_PROBE_JSON","r273-readonly-http-preflight.json"),"w",encoding="utf-8") as f:json.dump(result,f,ensure_ascii=False,indent=2)
    return 0 if result["pass"] else 2
if __name__=="__main__":sys.exit(main())
