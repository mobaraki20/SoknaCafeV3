#!/usr/bin/env python3
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def text(p): return (ROOT/p).read_text(encoding='utf-8')
def need(p,token,msg):
    if token not in text(p): raise SystemExit('FAIL: '+msg)

files=[
 'apps/public/src/Remote/RemoteStaffPageRenderer.php',
 'apps/public/src/Remote/InstallationProjectionService.php',
 'apps/public/src/Http/InstallationProjectionHttpAdapter.php',
 'apps/public/src/Http/PublicHttpKernel.php',
 'apps/local-web/src/Domain/PublicEdge/PublicEdgeSyncClient.php',
 'apps/local-web/src/Domain/PublicEdge/PublicProjectionBuilder.php',
 'apps/local-web/src/Domain/PublicEdge/PublicEdgePublisherService.php',
 'apps/local-web/database/migrations/0022_g3_public_edge_sync.sql',
]
for f in files:
    if not (ROOT/f).is_file(): raise SystemExit('FAIL missing '+f)
need('apps/public/src/Http/PublicHttpKernel.php',"'/staff/login'",'remote staff login route missing')
need('apps/public/src/Http/PublicHttpKernel.php',"'/api/staff/read'",'remote staff read API missing')
need('apps/public/src/Http/PublicHttpKernel.php',"'/api/v1/local/auth-projections'",'auth projection sync route missing')
need('apps/public/src/Http/PublicHttpKernel.php',"'/api/v1/local/read-models'",'read model sync route missing')
need('apps/public/src/Http/PublicHttpKernel.php',"'/api/v1/local/heartbeat'",'heartbeat route missing')
need('apps/public/src/Http/PublicHttpKernel.php',"'/api/v1/local/guest/publish'",'guest publish route missing')
need('apps/public/src/Auth/PublicSessionStore.php','function revoke','remote logout does not revoke token')
need('apps/local-web/src/Core/Capabilities.php',"'remote_access'",'explicit remote access capability missing')
need('apps/local-web/src/Core/Capabilities.php',"'remote_inventory_cost'",'remote cost capability missing')
need('apps/local-web/src/Domain/PublicEdge/PublicProjectionBuilder.php',"if(!$admin&&!in_array('remote_access',$caps,true))continue",'non-admin remote access is not explicit/fail-closed')
need('apps/local-web/src/Domain/PublicEdge/PublicProjectionBuilder.php',"'password_hash'=>(string)$u['password_hash']",'auth projection must use existing hash only')
if "'password'=>" in text('apps/local-web/src/Domain/PublicEdge/PublicProjectionBuilder.php'):
    raise SystemExit('FAIL plaintext password projection found')
need('apps/local-web/src/Domain/PublicEdge/PublicEdgeSyncClient.php',"'sokna-relay-v1'",'signed relay contract missing')
need('apps/local-web/src/Domain/PublicEdge/PublicEdgeSyncClient.php',"verify_peer'=>true",'TLS verification missing')
need('apps/local-web/src/Domain/PublicEdge/PublicEdgePublisherService.php',"'public.projection_sync'",'') if False else None
need('apps/local-web/src/Core/Bootstrap.php',"'public.projection_sync'=>fn():array=>$this->publicEdgePublisher()->syncAll()",'runtime publisher trigger missing')
need('apps/local-web/public/system/api.php',"$action==='public_sync'",'manual Public sync action missing')
need('apps/local-web/database/migrations/0022_g3_public_edge_sync.sql','public_sync_state','delivery evidence table missing')
need('apps/public/src/Remote/RemoteStaffPageRenderer.php','data-model','remote staff model navigation missing')
print('PASS G3.2 remote staff/publisher contract')
