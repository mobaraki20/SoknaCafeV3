#!/usr/bin/env python3
from pathlib import Path
import json,re,sys

R=Path(__file__).resolve().parents[1]
def need(v,m):
    if not v:
        print("LOCAL ENDPOINT CONTRACT FAILED:",m,file=sys.stderr)
        raise SystemExit(1)

contract=json.loads((R/"contracts/local-endpoint-v1.json").read_text(encoding="utf-8"))
need(contract["contract_id"]=="sokna-local-endpoint-v1","contract id drift")
need(contract["network_scope"]=="loopback-only","endpoint must remain loopback-only")
need(contract["canonical_host"]=="127.0.0.1","canonical host drift")
need(contract["port"]["configurable"] is True and contract["port"]["must_not_be_hardcoded_by_consumers"] is True,"port configurability weakened")

infra=json.loads((R/"platform/windows/infrastructure-prerequisites.json").read_text(encoding="utf-8"))
need(infra["ports"]["apache"]>=1024 and infra["ports"]["apache"]!=80,"Prerequisites default must not depend on port 80")
need(infra["local_endpoint"]["canonical_host"]=="127.0.0.1","Prerequisites canonical host drift")

setup=(R/"packaging/prerequisites/setup-ui/Program.cs").read_text(encoding="utf-8")
for token in ["LocalWebUrl()","WebPublicPath()","EnsureApachePortAvailableWithFallback()","ServerName 127.0.0.1:{ApachePort()}","apache_document_root","base_url = LocalWebUrl()"]:
    need(token in setup,f"Prerequisites endpoint implementation missing: {token}")
need('var w = Slash(WebPublicPath())' in setup and r'$"DocumentRoot \"{w}\""' in setup,"Apache must serve Local Web public/ directory")

browser=(R/"apps/local-web/src/Setup/BrowserSetupService.php").read_text(encoding="utf-8")
for token in ["normalizeLocalBaseUrl","local_base_url","local_bridge_allowed_origin","windows-services-pairing.json","print_agent_token"]:
    need(token in browser,f"Browser Setup endpoint/pairing implementation missing: {token}")
need("https://127.0.0.1" not in browser,"Browser Setup retains hardcoded HTTPS endpoint")

helper=(R/"apps/local-web/src/Core/LocalEndpoint.php").read_text(encoding="utf-8")
for token in ["CANONICAL_HOST = '127.0.0.1'","MIN_PORT = 1024","fromServer","canonicalRedirectTarget"]:
    need(token in helper,f"Local endpoint helper missing: {token}")
api=(R/"apps/local-web/public/setup/api.php").read_text(encoding="utf-8")
need("setup_local_base_url" in api and "LocalEndpoint::fromServer" in api,"Setup API must derive actual Apache port through canonical helper")
js=(R/"apps/local-web/public/assets/setup-wizard.js").read_text(encoding="utf-8")
need("location.replace(canonical.origin+'/setup/')" in js,"Browser Setup must canonicalize browser origin")

app=(R/"apps/local-web/public/_app.php").read_text(encoding="utf-8")
recovery=(R/"apps/local-web/public/local-recovery.php").read_text(encoding="utf-8")
need("dirname(__DIR__,3)" not in app and "$root=dirname(__DIR__);" in app,"Local app still assumes monorepo root")
need("dirname(__DIR__,3)" not in recovery and "$packageRoot=dirname(__DIR__);" in recovery,"Recovery still assumes monorepo root")

runtime=(R/"windows/runtime/source/Program.cs").read_text(encoding="utf-8")
need('LocalBaseUrl { get; init; } = ""' in runtime,"Runtime still has a hardcoded Local URL default")
need("runtime_local_endpoint_must_be_origin" in runtime and "runtime_local_endpoint_port_invalid" in runtime,"Runtime must validate exact high-port loopback origin")
need("https://127.0.0.1" not in runtime,"Runtime source retains hardcoded old endpoint")

life=(R/"packaging/windows/scripts/setup-windows-services.ps1").read_text(encoding="utf-8")
need("Local Web URL and bridge origin must be the same origin" in life,"Windows pairing must reject endpoint drift")
need("$uri.IsLoopback" in life and "$originUri.IsLoopback" in life,"Windows pairing must remain loopback-only")
need("port must be between 1024 and 65535" in life,"Windows pairing must reject privileged Local Web ports")

agent=(R/"windows/print-agent/source/src/Sokna.PrintAgent.Core/AgentOptions.cs").read_text(encoding="utf-8")
need("uri.IsLoopback" in agent and 'uri.Scheme is not ("https" or "http")' in agent,"Print Agent must accept HTTP loopback with configurable port")
bridge=(R/"windows/print-agent/source/src/Sokna.PrintAgent.Service/LocalBridgeService.cs").read_text(encoding="utf-8")
need("GetLeftPart(UriPartial.Authority)" in bridge,"Print bridge origin must be authority-based")

bootstrap=(R/"apps/local-web/src/Core/Bootstrap.php").read_text(encoding="utf-8")
need("dirname(__DIR__,4)" not in bootstrap,"Local Bootstrap retains monorepo-root assumptions")

builder=(R/"apps/local-web/tools/build-clean-install-package.py").read_text(encoding="utf-8")
need('"document_root":"public"' in builder and '"public/index.php"' in builder,"clean-install package contract missing")

print("Local endpoint cross-component contract: PASS")
