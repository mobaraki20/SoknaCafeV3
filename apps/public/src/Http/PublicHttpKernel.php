<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Http;

use Sokna\PublicEdge\Core\Bootstrap;
use Sokna\PublicEdge\Core\SafeErrors;
use Throwable;

final class PublicHttpKernel
{
    public function __construct(
        private readonly Bootstrap $core,
        private readonly string $componentRoot,
    ) {
    }

    /** @return array{status:int,headers:array<string,string>,body?:string,file_path?:string} */
    public function handle(string $method, string $path, array $query = [], string $rawBody = '', array $headers = []): array
    {
        $method = strtoupper(trim($method));
        $path = $this->normalizePath($path);
        if ($path === '') return $this->json(SafeErrors::response(400, 'invalid_path'));
        $path = PublicPath::strip($this->basePath(), $path);
        if ($path === '') return $this->json(SafeErrors::response(404, 'route_not_found'));

        try {
            if ($method === 'GET' && $path === '/control.php') {
                return $this->response(302, '', [
                    'Location' => $this->url('/staff'),
                    'Cache-Control' => 'no-store',
                    'Content-Type' => 'text/plain; charset=utf-8',
                ]);
            }
            if ($method === 'GET' && $path === '/') {
                $suffix = $query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
                return $this->response(302, '', ['Location' => $this->url('/menu') . $suffix, 'Cache-Control' => 'no-store', 'Content-Type' => 'text/plain; charset=utf-8']);
            }
            // Legacy notification link; resolve to the authenticated Public Staff health page.
            if ($method === 'GET' && in_array($path,['/system','/system/'],true) && (string)($query['tab']??'') === 'health') {
                return $this->response(302,'',['Location'=>$this->url('/staff/health/'),'Cache-Control'=>'no-store','Content-Type'=>'text/plain; charset=utf-8']);
            }
            if ($method === 'GET' && $path === '/robots.txt') {
                return $this->response(200, "User-agent: *\nDisallow: ".$this->url("/api/")."\nDisallow: ".$this->url("/emergency/")."\n", ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
            }
            if ($method === 'GET' && $path === '/move/approve') {
                return \Sokna\PublicEdge\Http\PublicMoveConsentPage::render(
                    $this->core->config()->string('app.base_url')
                );
            }
            if ($method === 'GET' && $path === '/assets/sokna-public-move.js') {
                return $this->staticFile($this->componentRoot.'/assets/sokna-public-move.js','application/javascript; charset=utf-8',300);
            }
            if ($method === 'GET' && $path === '/assets/sokna-public-move.css') {
                return $this->staticFile($this->componentRoot.'/assets/sokna-public-move.css','text/css; charset=utf-8',300);
            }
            if ($method === 'POST' && preg_match('#^/api/v1/owner/(upgrade_preflight|upgrade_activate)$#D', $path, $upgradeMatch) === 1) {
                return $this->publicOwnerUpgradeAction($upgradeMatch[1], $headers, $rawBody);
            }
            if ($method === 'POST' && preg_match('#^/api/v1/owner/(login|logout|consent_prepare|consent_sign|offer_existing)$#D', $path, $moveMatch) === 1) {
                return $this->publicOwnerAction($moveMatch[1], $headers, $rawBody);
            }
            if ($method === 'GET' && $path === '/health') {
                $result = (new HealthHttpAdapter($this->core))->status($this->correlationId($headers));
                return $this->json($result);
            }
            if ($method === 'GET' && $path === '/assets/scds/guest.css') {
                $versioned = preg_match('/^[a-f0-9]{12,64}$/D', (string)($query['v'] ?? '')) === 1;
                return $this->staticFile($this->componentRoot . '/assets/scds/guest.css', 'text/css; charset=utf-8', $versioned ? 31536000 : 300, $versioned);
            }
            if ($method === 'GET' && $path === '/assets/scds/guest.js') {
                $versioned = preg_match('/^[a-f0-9]{12,64}$/D', (string)($query['v'] ?? '')) === 1;
                return $this->staticFile($this->componentRoot . '/assets/scds/guest.js', 'application/javascript; charset=utf-8', $versioned ? 31536000 : 300, $versioned);
            }
            // Protected guest UI contract: the renderer loads this after imported theme CSS.
            // It must be served by the Public kernel in routed deployments, not only by Apache static-file fallthrough.
            if ($method === 'GET' && $path === '/assets/scds/guest-contract.css') {
                $versioned = preg_match('/^[a-f0-9]{12,64}$/D', (string)($query['sv'] ?? $query['v'] ?? '')) === 1;
                return $this->staticFile($this->componentRoot . '/assets/scds/guest-contract.css', 'text/css; charset=utf-8', $versioned ? 31536000 : 300, $versioned);
            }
            if ($method === 'GET' && preg_match('#^/assets/(product-ui\.css|product-boot\.js|product-theme\.js|user-ui-preferences\.js|locale\.js|settlement-tender\.js|settlement-display-currency\.js|ui-dialogs\.js|product-shell\.js|product-interactions\.js|jalali-fields\.js|global-search\.js|login\.js|ui-sprite\.svg|hall-settlement-v32\.js|order-line-ui\.js|operations-workspace\.js|subscribers-workspace\.js|reports-workspace\.js|notifications-workspace\.js)$#D',$path,$m)===1) {
                $name=$m[1];$mime=str_ends_with($name,'.css')?'text/css; charset=utf-8':(str_ends_with($name,'.svg')?'image/svg+xml':'application/javascript; charset=utf-8');
                return $this->staticFile($this->componentRoot . '/assets/' . $name,$mime,300);
            }
            if ($method === 'GET' && preg_match('#^/assets/scds/(tokens\.css|components\.css|scds\.js)$#D',$path,$m)===1) {
                $mime=str_ends_with($m[1],'.css')?'text/css; charset=utf-8':'application/javascript; charset=utf-8';
                return $this->staticFile($this->componentRoot . '/assets/scds/' . $m[1],$mime,300);
            }
            if ($method === 'GET' && $path === '/assets/staff-app/public-invoice-history.js') {
                return $this->staticFile($this->componentRoot . '/assets/staff-app/public-invoice-history.js','application/javascript; charset=utf-8',300);
            }
            if ($method === 'GET' && $path === '/assets/staff-app/public-account.js') {
                return $this->staticFile($this->componentRoot . '/assets/staff-app/public-account.js','application/javascript; charset=utf-8',300);
            }
            if ($method === 'GET' && $path === '/assets/staff-app/public-page-host.js') {
                return $this->staticFile($this->componentRoot . '/assets/staff-app/public-page-host.js','application/javascript; charset=utf-8',300);
            }
            if ($method === 'GET' && $path === '/assets/staff-app/public-device.js') {
                return $this->staticFile($this->componentRoot . '/assets/staff-app/public-device.js','application/javascript; charset=utf-8',300);
            }
            if ($method === 'GET' && $path === '/assets/staff-app/offline-bootstrap.js') {
                return $this->staticFile($this->componentRoot . '/assets/staff-app/offline-bootstrap.js','application/javascript; charset=utf-8',300);
            }
            if ($method === 'GET' && $path === '/brand-asset.php') {
                $variant=(string)($query['variant']??'');$file=in_array($variant,['icon-16','icon-32','icon-48','icon-180','icon-192','logo-256'],true)?'/assets/brand/Sokna-AppIcon-256.png':'/assets/brand/Sokna-AppIcon-256.png';
                return $this->staticFile($this->componentRoot.$file,'image/png',31536000,true);
            }
            if ($method === 'GET' && $path === '/manifest.php') {
                return $this->staffManifest();
            }
            if ($method === 'GET' && $path === '/assets/scds/remote-staff.css') {
                return $this->staticFile($this->componentRoot . '/assets/scds/remote-staff.css', 'text/css; charset=utf-8', 300);
            }
            if ($method === 'GET' && $path === '/assets/scds/remote-staff.js') {
                return $this->staticFile($this->componentRoot . '/assets/scds/remote-staff.js', 'application/javascript; charset=utf-8', 300);
            }
            if ($method === 'GET' && $path === '/assets/staff-app/runtime.js') {
                return $this->staticFile($this->componentRoot . '/assets/staff-app/runtime.js', 'application/javascript; charset=utf-8', 300);
            }
            if ($method === 'GET' && $path === '/assets/staff-app/sellable-picker.js') {
                return $this->staticFile($this->componentRoot . '/assets/staff-app/sellable-picker.js', 'application/javascript; charset=utf-8', 300);
            }
            if ($method === 'GET' && $path === '/assets/staff-app/order-draft-workspace.js') {
                return $this->staticFile($this->componentRoot . '/assets/staff-app/order-draft-workspace.js', 'application/javascript; charset=utf-8', 300);
            }
            if ($method === 'GET' && $path === '/assets/staff-app/public-hall-transport.js') {
                return $this->staticFile($this->componentRoot . '/assets/staff-app/public-hall-transport.js', 'application/javascript; charset=utf-8', 300);
            }
            if ($method === 'GET' && $path === '/assets/staff-app/hall-workspace.js') {
                return $this->staticFile($this->componentRoot . '/assets/staff-app/hall-workspace.js', 'application/javascript; charset=utf-8', 300);
            }
            if ($method === 'GET' && $path === '/assets/staff-app/data-panels.js') {
                return $this->staticFile($this->componentRoot . '/assets/staff-app/data-panels.js', 'application/javascript; charset=utf-8', 300);
            }
            if ($method === 'GET' && $path === '/assets/staff-app/preparation-workspace.js') {
                return $this->staticFile($this->componentRoot . '/assets/staff-app/preparation-workspace.js', 'application/javascript; charset=utf-8', 300);
            }
            if ($method === 'GET' && $path === '/assets/staff-app/public-health.js') {
                return $this->staticFile($this->componentRoot . '/assets/staff-app/public-health.js', 'application/javascript; charset=utf-8', 300);
            }
            if ($method === 'GET' && $path === '/assets/scds/staff-ui-dialogs.js') {
                return $this->staticFile($this->componentRoot . '/assets/scds/staff-ui-dialogs.js', 'application/javascript; charset=utf-8', 300);
            }
            if ($method === 'GET' && $path === '/staff/manifest.webmanifest') {
                return $this->staffManifest();
            }
            if ($method === 'GET' && $path === '/staff/sw.js') {
                $response=$this->staticFile($this->componentRoot . '/assets/scds/staff-sw.js', 'application/javascript; charset=utf-8', 0);
                $response['headers']['Cache-Control']='no-cache, max-age=0';
                $response['headers']['Service-Worker-Allowed']=$this->url('/staff/');
                return $response;
            }
            if ($method === 'GET' && $path === '/staff/icon-192.png') {
                return $this->staticFile($this->componentRoot . '/assets/favicon-192.png', 'image/png', 31536000, true);
            }
            if ($method === 'GET' && $path === '/staff/icon-512.png') {
                return $this->staticFile($this->componentRoot . '/assets/brand/Sokna-AppIcon-512.png', 'image/png', 31536000, true);
            }
            if ($method === 'GET' && $path === '/staff/icon-maskable-192.png') {
                return $this->staticFile($this->componentRoot . '/assets/brand/Sokna-AppIcon-Maskable-192.png', 'image/png', 31536000, true);
            }
            if ($method === 'GET' && $path === '/staff/icon-maskable-512.png') {
                return $this->staticFile($this->componentRoot . '/assets/brand/Sokna-AppIcon-Maskable-512.png', 'image/png', 31536000, true);
            }
            if ($method === 'GET' && $path === '/menu/manifest.webmanifest') {
                $installationId=$this->installationId($query);if($installationId==='')return $this->json(SafeErrors::response(404,'installation_not_found',$this->correlationId($headers)));
                return $this->withSecurityHeaders($this->core->guestRenderer()->manifest($installationId,['media_base'=>$this->url('/media')]));
            }
            if ($method === 'GET' && preg_match('#^/media/([A-Za-z0-9._-]{1,96})/([a-f0-9]{64})\.(jpg|png|webp|gif)$#D', $path, $m) === 1) {
                $file = $this->core->guestMedia()->publicFile($m[1], $m[2], $m[3]);
                if ($file === null) return $this->json(SafeErrors::response(404, 'media_not_found', $this->correlationId($headers)));
                $response = $this->staticFile($file['path'], $file['mime'], 31536000, true);
                return $response;
            }
            if ($method === 'GET' && preg_match('#^/theme/([A-Za-z0-9._:-]{1,96})\.css$#D', $path, $m) === 1) {
                $css=$this->core->guestThemeCss()->css($m[1]);
                return $this->withSecurityHeaders($this->response(200,$css,['Content-Type'=>'text/css; charset=utf-8','Cache-Control'=>'no-store, max-age=0']));
            }
            if ($method === 'GET' && $path === '/menu/about') {
                $installationId = $this->installationId($query);
                if ($installationId === '') return $this->json(SafeErrors::response(404, 'installation_not_found', $this->correlationId($headers)));
                return $this->withSecurityHeaders($this->core->guestRenderer()->about($installationId, $query, [
                    'css' => $this->versionedAssetUrl('/assets/scds/guest.css'),
                    'theme_css' => $this->url('/theme/' . rawurlencode($installationId) . '.css'),
                    'media_base' => $this->url('/media'),
                ]));
            }
            if ($method === 'GET' && $path === '/menu') {
                $installationId = $this->installationId($query);
                if ($installationId === '') return $this->json(SafeErrors::response(404, 'installation_not_found', $this->correlationId($headers)));
                return $this->withSecurityHeaders($this->core->guestRenderer()->render($installationId, $query, [
                    'css' => $this->versionedAssetUrl('/assets/scds/guest.css'),
                    'js' => $this->versionedAssetUrl('/assets/scds/guest.js'),
                    'theme_css' => $this->url('/theme/' . rawurlencode($installationId) . '.css'),
                    'create_order' => $this->url('/api/guest/order'),
                    'orders' => $this->url('/api/guest/orders'),
                    'order_quote' => $this->url('/api/guest/order/quote'),
                    'order_status' => $this->url('/api/guest/order/status'),
                    'table_context' => $this->url('/api/guest/table/context'),
                    'waiter_call' => $this->url('/api/guest/waiter'),
                    'changes' => $this->url('/api/guest/changes'),
                    'metric' => $this->url('/api/guest/metric'),
                    'media_base' => $this->url('/media'),
                    'transport_mode' => 'public_edge',
                ]));
            }

            if ($method === 'GET' && $path === '/staff/login') {
                $installationId=$this->installationId($query);
                return $this->withSecurityHeaders($this->core->remoteStaffRenderer()->login($installationId,'','','',(string)($query['origin_transition']??'')));
            }
            if ($method === 'POST' && $path === '/staff/login') {
                $form=$this->formPayload($rawBody,$headers);
                $installationId=trim((string)($form['installation_id']??''));
                if($installationId==='')$installationId=trim($this->core->config()->string('app.default_installation_id'));
                $selectedProjectionId=trim((string)($form['projection_id']??''));$manualUsername=trim((string)($form['username']??''));$originTransition=preg_match('/^rebind-[a-f0-9]{24}$/D',trim((string)($form['origin_transition']??'')))===1?trim((string)$form['origin_transition']):'';$username=$manualUsername;
                if($username===''&&$selectedProjectionId!==''){
                    try{$q=$this->core->database()->prepare('SELECT username FROM auth_projections WHERE installation_id=? AND projection_id=? AND active=1 LIMIT 1');$q->execute([$installationId,$selectedProjectionId]);$username=trim((string)($q->fetchColumn()?:''));}catch(\Throwable){}
                }
                $result=$this->core->loginService()->login($installationId,$username,(string)($form['password']??''),$this->header($headers,'X-Forwarded-For')?:$this->header($headers,'X-Real-IP'),$this->correlationId($headers)??'',!empty($form['remember_device']));
                if(($result['ok']??false)!==true){
                    $msg=($result['error']??'')==='too_many_attempts'?'تلاش‌های ورود بیش از حد بوده؛ کمی بعد دوباره امتحان کنید.':'حساب یا رمز عبور درست نیست.';
                    $response=$this->core->remoteStaffRenderer()->login($installationId,$msg,$selectedProjectionId,$manualUsername,$originTransition);$response['status']=(int)($result['status']??401);return $this->withSecurityHeaders($response);
                }
                $cookie=$this->sessionCookie((string)$result['token'],(int)($result['expires_in']??28800));
                return $this->withSecurityHeaders($this->response(303,'',['Location'=>$this->url('/staff/').($originTransition!==''?'?origin_transition='.rawurlencode($originTransition):''),'Set-Cookie'=>$cookie,'Cache-Control'=>'no-store','Content-Type'=>'text/plain; charset=utf-8']));
            }
            if (($method === 'POST'||$method === 'GET') && $path === '/staff/logout') {
                $token=$this->sessionToken($headers);if($token!=='')$this->core->publicSessions()->revoke($token);
                return $this->withSecurityHeaders($this->response(303,'',['Location'=>$this->url('/staff/login'),'Set-Cookie'=>$this->sessionCookie('',0),'Cache-Control'=>'no-store','Content-Type'=>'text/plain; charset=utf-8']));
            }
            if ($method === 'GET' && $path === '/staff/offline-bootstrap') {
                $session=$this->core->publicSessions()->resolve($this->sessionToken($headers));
                if(!is_array($session))return $this->withSecurityHeaders($this->response(401,'Unauthorized',['Cache-Control'=>'no-store','Content-Type'=>'text/plain; charset=utf-8']));
                $view=trim((string)($query['view']??'home'));
                return $this->withSecurityHeaders($this->core->remoteStaffRenderer()->offlineBootstrap($session,$view),[(string)($session['lan_base_url']??'')]);
            }
            if ($method === 'GET' && ($path === '/staff'||$path === '/staff/'||preg_match('#^/staff/(operator|preparation|operations|subscribers|reports|notifications|health|account)/?$#D',$path,$staffMatch)===1)) {
                $session=$this->core->publicSessions()->resolve($this->sessionToken($headers));
                $originTransition=preg_match('/^rebind-[a-f0-9]{24}$/D',trim((string)($query['origin_transition']??'')))===1?trim((string)$query['origin_transition']):'';
                if(!is_array($session))return $this->withSecurityHeaders($this->response(302,'',['Location'=>$this->url('/staff/login').($originTransition!==''?'?origin_transition='.rawurlencode($originTransition):''),'Cache-Control'=>'no-store','Content-Type'=>'text/plain; charset=utf-8']));
                if($originTransition!=='')$session['_origin_transition_id']=$originTransition;
                $session['_account_csrf']=hash_hmac('sha256','public-account-password-change-v1',$this->sessionToken($headers));
                $page=($path==='/staff'||$path==='/staff/')?'home':(string)$staffMatch[1];
                return $this->withSecurityHeaders($this->core->remoteStaffRenderer()->page($session,$page),[(string)($session['lan_base_url']??'')]);
            }
            if (($method === 'GET'||$method === 'POST') && preg_match('#^/staff/(operations|subscribers|reports|notifications)-adapter$#D',$path,$workspaceMatch)===1) {
                $result=(new PublicStaffWorkspaceHttpAdapter($this->core))->handle($this->sessionToken($headers),(string)$workspaceMatch[1],$method,$query,$rawBody);
                return $this->json($result);
            }
            if (($method === 'POST'||$method === 'GET') && $path === '/api/staff/health/probe') {
                $session=$this->core->publicSessions()->resolve($this->sessionToken($headers));
                if(!is_array($session))return $this->json(['status'=>401,'body'=>['ok'=>false,'error'=>'unauthorized']]);
                if((string)($session['role']??'')!=='admin')return $this->json(['status'=>403,'body'=>['ok'=>false,'error'=>'forbidden']]);
                $installationId=trim((string)($session['installation_id']??''));
                if($method==='POST'){
                    $requestId='phealth-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(6));$correlationId='ph-'.bin2hex(random_bytes(12));
                    $result=$this->core->realtime()->enqueueDiagnosticProbe($installationId,$requestId,$correlationId);
                    if((int)($result['status']??500)>=400)return $this->json($result);
                    return $this->json(['status'=>202,'body'=>['ok'=>true,'request_id'=>$requestId,'correlation_id'=>$correlationId,'state'=>(string)($result['body']['state']??'queued')]]);
                }
                return $this->json($this->core->realtime()->diagnosticProbeResult($installationId,(string)($query['request_id']??'')));
            }
            if ($method === 'GET' && $path === '/api/staff/health/system') {
                $session=$this->core->publicSessions()->resolve($this->sessionToken($headers));
                if(!is_array($session))return $this->json(['status'=>401,'body'=>['ok'=>false,'error'=>'unauthorized']]);
                if((string)($session['role']??'')!=='admin')return $this->json(['status'=>403,'body'=>['ok'=>false,'error'=>'forbidden']]);
                $health=$this->core->health()->status();$healthBody=is_array($health['body']??null)?$health['body']:[];
                $routine=$this->core->routinePublicUpdates()->snapshot();
                return $this->json(['status'=>200,'body'=>['ok'=>true,'format'=>'sokna-public-self-diagnostics-v1','generated_at'=>gmdate('c'),'health'=>$healthBody,'routine_update'=>$routine,'managed_inventory'=>$this->core->routinePublicUpdates()->managedInventory()]]);
            }
            if ($method === 'GET' && $path === '/api/staff/deployment-transition') {
                $session=$this->core->publicSessions()->resolve($this->sessionToken($headers));
                if(!is_array($session))return $this->json(['status'=>401,'body'=>['ok'=>false,'error'=>'unauthorized']]);
                $rebindId=trim((string)($query['rebind_id']??''));
                if(preg_match('/^rebind-[a-f0-9]{24}$/D',$rebindId)!==1)return $this->json(['status'=>422,'body'=>['ok'=>false,'error'=>'invalid_rebind_id']]);
                try{$state=$this->core->deploymentRebind()->status((string)($session['installation_id']??''),$rebindId);return $this->json(['status'=>200,'body'=>$state]);}
                catch(\Sokna\PublicEdge\Emergency\PublicUpdateException $e){return $this->json(['status'=>$e->httpStatus,'body'=>['ok'=>false,'error'=>$e->errorCode]]);}
            }
            if (($method === 'GET'||$method === 'POST') && $path === '/api/staff/ui-preferences') {
                $session=$this->core->publicSessions()->resolve($this->sessionToken($headers));
                if(!is_array($session))return $this->json(['status'=>401,'body'=>['success'=>false,'error'=>'unauthorized']]);
                $installationId=(string)($session['installation_id']??'');$projectionId=(string)($session['projection_id']??'');
                if($method==='GET')return $this->json(['status'=>200,'body'=>['success'=>true,'preferences'=>$this->core->uiPreferences()->forIdentity($installationId,$projectionId)]]);
                $body=json_decode($rawBody,true);if(!is_array($body))$body=[];$patch=is_array($body['preferences']??null)?$body['preferences']:[];
                return $this->json(['status'=>200,'body'=>['success'=>true,'preferences'=>$this->core->uiPreferences()->savePatch($installationId,$projectionId,$patch)]]);
            }
            if ($method === 'GET' && $path === '/api/staff/connectivity') {
                $session=$this->core->publicSessions()->resolve($this->sessionToken($headers));
                if(!is_array($session))return $this->json(['status'=>401,'body'=>['ok'=>false,'error'=>'unauthorized']]);
                $installationId=trim((string)($session['installation_id']??''));
                $connectivity=$this->core->connectivity()->status($installationId,45);
                $relay=$this->core->realtime()->diagnostics($installationId);
                $lastProbe=is_array($relay['last_terminal_probe']??null)?$relay['last_terminal_probe']:(is_array($relay['last_probe']??null)?$relay['last_probe']:null);
                $probeAt=(string)($lastProbe['updated_at']??'');$probeEpoch=$probeAt!==''?(\Sokna\Shared\IranTime::epoch($probeAt,'UTC')?:0):0;
                $probeAge=$probeEpoch>0?max(0,time()-$probeEpoch):null;
                $probeFresh=is_array($lastProbe)&&($lastProbe['state']??'')==='committed'&&$probeAge!==null&&$probeAge<=120;
                $probeHistorical=is_array($lastProbe)&&($lastProbe['state']??'')==='committed'&&$probeAge!==null&&$probeAge<=86400;
                $localFresh=($connectivity['local_fresh']??false)===true;
                $heartbeatAt=(string)($connectivity['last_seen_at']??'');$heartbeatEpoch=$heartbeatAt!==''?(\Sokna\Shared\IranTime::epoch($heartbeatAt,'UTC')?:0):0;
                $heartbeatAge=$heartbeatEpoch>0?max(0,time()-$heartbeatEpoch):null;
                $oldestQueued=(string)($relay['oldest_queued_at']??'');$oldestEpoch=$oldestQueued!==''?(\Sokna\Shared\IranTime::epoch($oldestQueued,'UTC')?:0):0;
                $oldestQueuedAge=$oldestEpoch>0?max(0,time()-$oldestEpoch):null;
                $relayStalled=(int)($relay['claimed_stale']??0)>0||(int)($relay['expired_due']??0)>0||((int)($relay['queued']??0)>0&&$oldestQueuedAge!==null&&$oldestQueuedAge>45);
                $recentHeartbeatGap=!$localFresh&&$heartbeatAge!==null&&$heartbeatAge<=180;
                $businessFresh=false;$businessAge=null;
                foreach((array)($relay['recent_terminal']??[]) as $terminal){
                    if(!is_array($terminal)||($terminal['state']??'')!=='committed')continue;
                    $at=\Sokna\Shared\IranTime::epoch((string)($terminal['updated_at']??''),'UTC')?:0;
                    if($at<=0||$at>time()+5)continue;
                    $age=max(0,time()-$at);if($businessAge===null||$age<$businessAge)$businessAge=$age;
                    if($age<=120)$businessFresh=true;
                }
                $roundTripFresh=$probeFresh||$businessFresh;
                $state=$localFresh?($roundTripFresh&&!$relayStalled?'ok':'heartbeat_only'):($recentHeartbeatGap?'degraded':'offline');
                $routeAvailable=$localFresh&&!$relayStalled&&($connectivity['remote_enabled']??false)===true&&($connectivity['active']??false)===true;
                return $this->json(['status'=>200,'body'=>[
                    'ok'=>true,'state'=>$state,'connectivity'=>$connectivity,'relay'=>$relay,
                    'heartbeat_age_seconds'=>$heartbeatAge,'relay_probe_age_seconds'=>$probeAge,'relay_probe_fresh'=>$probeFresh,
                    'relay_business_receipt_fresh'=>$businessFresh,'relay_business_receipt_age_seconds'=>$businessAge,'relay_round_trip_fresh'=>$roundTripFresh,'relay_probe_historical'=>$probeHistorical,'operation_route_available'=>$routeAvailable,
                    'relay_stalled'=>$relayStalled,'relay_oldest_queued_age_seconds'=>$oldestQueuedAge,
                ]]);
            }
            if ($method === 'GET' && $path === '/api/staff/read') {
                $result=(new RemoteReadModelHttpAdapter($this->core))->read($this->sessionToken($headers),(string)($query['model']??''));
                return $this->json($result);
            }
            if ($method === 'GET' && $path === '/api/staff/read/watch') {
                $models=array_values(array_filter(array_map('trim',explode(',',(string)($query['models']??'operations')))));
                $result=(new RemoteReadModelHttpAdapter($this->core))->watch($this->sessionToken($headers),$models,(string)($query['cursor']??''),(int)($query['wait_ms']??9000));
                return $this->json($result);
            }
            if ($method === 'POST' && $path === '/api/staff/account/password') {
                return $this->json((new \Sokna\PublicEdge\Auth\PublicAccountPasswordService($this->core))->change($this->sessionToken($headers),$rawBody,$this->header($headers,'X-Sokna-Account-CSRF')));
            }
            if ($method === 'POST' && $path === '/api/staff/realtime') {
                return $this->json((new RealtimeHttpAdapter($this->core))->enqueue($this->sessionToken($headers),$rawBody));
            }
            if ($method === 'GET' && $path === '/api/staff/realtime/result') {
                return $this->json((new RealtimeHttpAdapter($this->core))->result($this->sessionToken($headers),(string)($query['request_id']??'')));
            }
            if ($method === 'POST' && $path === '/api/staff/deferred') {
                return $this->json((new DeferredHttpAdapter($this->core))->enqueue($this->sessionToken($headers),$rawBody));
            }
            if ($method === 'GET' && $path === '/api/staff/deferred') {
                return $this->json((new DeferredHttpAdapter($this->core))->list($this->sessionToken($headers),(int)($query['limit']??100)));
            }
            if ($method === 'GET' && $path === '/api/staff/deferred/result') {
                return $this->json((new DeferredHttpAdapter($this->core))->result($this->sessionToken($headers),(string)($query['request_id']??'')));
            }
            if ($method === 'POST' && str_starts_with($path, '/api/v1/local/')) {
                return $this->localProjectionApi($path,$rawBody,$headers);
            }

            if ($method === 'POST' && str_starts_with($path, '/api/guest/')) {
                return $this->guestApi($path, $rawBody, $headers);
            }

            return $this->json(SafeErrors::response(404, 'route_not_found', $this->correlationId($headers)));
        } catch (Throwable $error) {
            // Server-only exception evidence for failed Public API requests.
            // Never log the raw URL query, request body, cookies, tokens, or exception message.
            $supportId = SafeErrors::correlationId($this->correlationId($headers));
            $safeRoute = substr((string)preg_replace('/[^A-Za-z0-9_\/.-]/', '_', $path), 0, 120);
            $safeMethod = substr((string)preg_replace('/[^A-Za-z]/', '', $method), 0, 12);
            $safeType = substr((string)preg_replace('/[^A-Za-z0-9_]/', '', str_replace('\\', '_', $error::class)), 0, 120);
            $safeFile = substr((string)preg_replace('/[^A-Za-z0-9_.-]/', '_', basename(str_replace('\\', '/', $error->getFile()))), 0, 120);
            $safeCode = substr((string)preg_replace('/[^A-Za-z0-9_]/', '', (string)$error->getCode()), 0, 20);
            try {
                error_log(sprintf('[SOKNA_PUBLIC_EXCEPTION] id=%s method=%s route=%s type=%s location=%s:%d code=%s',
                    $supportId, $safeMethod, $safeRoute, $safeType, $safeFile, $error->getLine(), $safeCode));
            } catch (Throwable) {
                // Evidence logging must never replace the safe API error response.
            }
            return $this->json(SafeErrors::fromThrowable($error, $supportId));
        }
    }

    private function localProjectionApi(string $path,string $rawBody,array $headers): array
    {
        $installationId=trim($this->header($headers,'X-Sokna-Installation'));
        $timestamp=$this->header($headers,'X-Sokna-Timestamp');$nonce=$this->header($headers,'X-Sokna-Nonce');$signature=$this->header($headers,'X-Sokna-Signature');
        if($installationId==='')return $this->json(SafeErrors::response(400,'missing_installation',$this->correlationId($headers)));
        $result=match($path){
'/api/v1/local/move/activate'=>(new \Sokna\PublicEdge\Security\PublicMoveReplacementGate($this->core->database()))->activateAfterVerifiedSync($this->core,$installationId,'POST',$path,$timestamp,$nonce,$signature,$rawBody),
                        '/api/v1/local/installation'=>(new InstallationProjectionHttpAdapter($this->core))->sync($installationId,'POST',$path,$timestamp,$nonce,$signature,$rawBody),
            '/api/v1/local/auth-projections'=>(new AuthHttpAdapter($this->core))->projectionSync($installationId,'POST',$path,$timestamp,$nonce,$signature,$rawBody),
            '/api/v1/local/read-models'=>(new RemoteReadModelHttpAdapter($this->core))->sync($installationId,'POST',$path,$timestamp,$nonce,$signature,$rawBody),
            '/api/v1/local/read-model-events'=>(new RemoteReadModelHttpAdapter($this->core))->syncEvents($installationId,'POST',$path,$timestamp,$nonce,$signature,$rawBody),
            '/api/v1/local/read-model-v2'=>(new RemoteReadModelHttpAdapter($this->core))->syncModelV2($installationId,'POST',$path,$timestamp,$nonce,$signature,$rawBody),
            '/api/v1/local/heartbeat'=>(new ConnectivityHttpAdapter($this->core))->heartbeat($installationId,'POST',$path,$timestamp,$nonce,$signature,$rawBody),
            '/api/v1/local/guest/media'=>(new GuestSyncHttpAdapter($this->core))->media($installationId,'POST',$path,$timestamp,$nonce,$signature,$rawBody),
            '/api/v1/local/guest/media/status'=>(new GuestSyncHttpAdapter($this->core))->mediaStatus($installationId,'POST',$path,$timestamp,$nonce,$signature,$rawBody),
            '/api/v1/local/guest/publish'=>(new GuestSyncHttpAdapter($this->core))->publish($installationId,'POST',$path,$timestamp,$nonce,$signature,$rawBody),
            '/api/v1/local/guest/availability'=>(new GuestSyncHttpAdapter($this->core))->availability($installationId,'POST',$path,$timestamp,$nonce,$signature,$rawBody),
            '/api/v1/local/go-live/inspect',
            '/api/v1/local/go-live/execute',
            '/api/v1/local/go-live/status'=>(new GoLiveResetHttpAdapter($this->core))->handle($installationId,$path,$timestamp,$nonce,$signature,$rawBody),
            '/api/v1/local/authority/status'=>(new AuthorityEpochHttpAdapter($this->core))->status($installationId,'POST',$path,$timestamp,$nonce,$signature,$rawBody),
            '/api/v1/local/authority/fence'=>(new AuthorityEpochHttpAdapter($this->core))->fence($installationId,'POST',$path,$timestamp,$nonce,$signature,$rawBody),
            '/api/v1/local/authority/activate'=>(new AuthorityEpochHttpAdapter($this->core))->activate($installationId,'POST',$path,$timestamp,$nonce,$signature,$rawBody),
            '/api/v1/local/realtime/exchange'=>(new RealtimeHttpAdapter($this->core))->exchange($installationId,'POST',$path,$timestamp,$nonce,$signature,$rawBody),
            '/api/v1/local/realtime/claim'=>(new RealtimeHttpAdapter($this->core))->claim($installationId,'POST',$path,$timestamp,$nonce,$signature,$rawBody),
            '/api/v1/local/realtime/ack'=>(new RealtimeHttpAdapter($this->core))->ack($installationId,'POST',$path,$timestamp,$nonce,$signature,$rawBody),
            '/api/v1/local/deferred/claim'=>(new DeferredHttpAdapter($this->core))->claim($installationId,'POST',$path,$timestamp,$nonce,$signature,$rawBody),
            '/api/v1/local/deferred/ack'=>(new DeferredHttpAdapter($this->core))->ack($installationId,'POST',$path,$timestamp,$nonce,$signature,$rawBody),
            '/api/v1/local/deferred/reconcile'=>(new DeferredHttpAdapter($this->core))->reconcile($installationId,'POST',$path,$timestamp,$nonce,$signature,$rawBody),
            '/api/v1/local/deferred/period-status'=>(new DeferredHttpAdapter($this->core))->periodStatus($installationId,'POST',$path,$timestamp,$nonce,$signature,$rawBody),
            '/api/v1/local/emergency/access',
            '/api/v1/local/diagnostics',
            '/api/v1/local/diagnostics/relay-probe/start',
            '/api/v1/local/diagnostics/relay-probe/result',
            '/api/v1/local/update/status',
            '/api/v1/local/update/stage',
            '/api/v1/local/update/activate',
            '/api/v1/local/update/repair',
            '/api/v1/local/update/rollback',
            '/api/v1/local/update/v2/upload/start',
            '/api/v1/local/update/v2/upload/chunk',
            '/api/v1/local/update/v2/upload/finish',
            '/api/v1/local/update/v2/activate',
            '/api/v1/local/update/v2/commit',
            '/api/v1/local/update/v2/rollback',
            '/api/v1/local/update/v3/upload/start',
            '/api/v1/local/update/v3/upload/chunk',
            '/api/v1/local/update/v3/upload/finish',
            '/api/v1/local/update/v3/activate',
            '/api/v1/local/update/v3/commit',
            '/api/v1/local/update/v3/rollback'=>(new EmergencyLocalHttpAdapter($this->core))->handle($installationId,'POST',$path,$timestamp,$nonce,$signature,$rawBody),
            default=>SafeErrors::response(404,'route_not_found',$this->correlationId($headers)),
        };
        return $this->json($result);
    }

    private function formPayload(string $rawBody,array $headers): array
    {
        $contentType=strtolower($this->header($headers,'Content-Type'));if(str_contains($contentType,'application/json')){$v=json_decode($rawBody,true);return is_array($v)?$v:[];}
        parse_str($rawBody,$form);return is_array($form)?$form:[];
    }
    private function header(array $headers,string $name): string{foreach($headers as $k=>$v)if(strcasecmp((string)$k,$name)===0)return trim((string)$v);return '';}


    /** Site-owner creation for already-installed Public is limited to an existing,
     * verified admin of the sole active Local installation in THIS Public DB.
     * There is no anonymous setup-claim endpoint and no Emergency dependency. */
    private function publicOwnerUpgradeAction(string $action,array $headers,string $rawBody): array
    {
        try {
            $record=json_decode((string)@file_get_contents($this->componentRoot.'/install.lock'),true);
            if (!is_array($record) || ($record['format']??'')!=='sokna-public-install-lock-v1')
                throw new \RuntimeException('upgrade_owner_install_lock_required');
            $id=(string)($record['public_instance_id']??'');
            $cfg=$this->core->config();
            $seed=base64_decode($cfg->string('relay.secret_encryption_key_base64'),true);
            if (!is_string($seed)||strlen($seed)<32)throw new \RuntimeException('upgrade_owner_csrf_seed_missing');
            $key=hash_hkdf('sha256',$seed,32,'sokna-public-move-owner-consent-v1');
            $owners=new \Sokna\PublicEdge\Security\PublicMoveOwnerBootstrapService($this->core->database());
            $gate=new \Sokna\PublicEdge\Security\PublicMoveExistingOwnerActivation(
                $this->core->database(),$this->core->publicSessions(),$owners,
                $id,$cfg->string('app.base_url'),$key
            );
            $now=time();
            $staffToken=$this->sessionToken($headers);
            $origin=$this->header($headers,'Origin');
            $fetch=$this->header($headers,'Sec-Fetch-Site');
            if ($action==='upgrade_preflight')
                $result=$gate->preflight($staffToken,$origin,$fetch,$now);
            elseif ($action==='upgrade_activate') {
                $req=json_decode($rawBody,true);
                if (!is_array($req)) throw new \InvalidArgumentException('invalid_json');
                $root=$this->componentRoot;
                $prepare=function()use($root,$id):void {
                    $protected=\Sokna\PublicEdge\Setup\PublicMoveOwnerPrivatePath::prepare(
                        $root,(string)($_SERVER['DOCUMENT_ROOT']??$root)
                    );
                    (new \Sokna\PublicEdge\Security\PublicMoveInstanceKeyStore($protected,$root))->provision($id,true);
                };
                $result=$gate->activate($staffToken,$origin,$fetch,
                    (string)($req['csrf']??''),(string)($req['new_owner_password']??''),$now,$prepare);
            } else throw new \InvalidArgumentException('unknown_action');
            return $this->json(['status'=>200,'body'=>['ok'=>true,'result'=>$result]]);
        }catch(\InvalidArgumentException|\RuntimeException $e){
            return $this->json(SafeErrors::response(403,'owner_upgrade_not_permitted'));
        }catch(\Throwable $e){
            return $this->json(SafeErrors::response(503,'owner_upgrade_not_ready'));
        }
    }

    /** Server-side only: challenge/owner scope must never be taken from client identity fields. */
    private function publicOwnerAction(string $action, array $headers, string $rawBody): array
    {
        $lock=$this->componentRoot.'/install.lock';
        $record=json_decode((string)@file_get_contents($lock),true);
        if (!is_array($record) || ($record['format']??'')!=='sokna-public-install-lock-v1') {
            return $this->json(SafeErrors::response(503,'public_owner_not_initialized'));
        }
        $instanceId=(string)($record['public_instance_id']??'');
        $config=$this->core->config();
        $private=$config->string('security.move_owner_private_dir');
        $csrfB64=$config->string('security.move_owner_csrf_key_base64');
        $csrf=base64_decode($csrfB64,true);
        $owners=new \Sokna\PublicEdge\Security\PublicMoveOwnerBootstrapService($this->core->database());
        if (($private==='' || !is_string($csrf) || strlen($csrf)!==32) &&
            $owners->initialized($instanceId)) {
            // Existing pre-R256 installation: no configuration overwrite or
            // forced clean install. The seeded key path was prepared only after
            // authenticated admin activation. Derive a purpose-bound CSRF key
            // from already protected relay encryption material.
            $seed=base64_decode($config->string('relay.secret_encryption_key_base64'),true);
            if (!is_string($seed)||strlen($seed)<32)
                return $this->json(SafeErrors::response(503,'public_owner_not_initialized'));
            $csrf=hash_hkdf('sha256',$seed,32,'sokna-public-move-owner-consent-v1');
            $private=\Sokna\PublicEdge\Setup\PublicMoveOwnerPrivatePath::prepare(
                $this->componentRoot,(string)($_SERVER['DOCUMENT_ROOT']??$this->componentRoot)
            );
        }
        if ($private==='' || !is_string($csrf) || strlen($csrf)!==32)
            return $this->json(SafeErrors::response(503,'public_owner_not_initialized'));
        $base=$config->string('app.base_url');
        $keys=new \Sokna\PublicEdge\Security\PublicMoveInstanceKeyStore($private,$this->componentRoot);
        $bridge=new \Sokna\PublicEdge\Security\PublicMoveIndependentOwnerBridge($owners,$keys,$base,$instanceId,$csrf);
        if($action==='offer_existing'){
            $req=json_decode($rawBody,true);
            if(!is_array($req)||!is_array($req['challenge']??null)||
               !is_string($req['csrf']??null))
                return $this->json(SafeErrors::response(400,'invalid_challenge'));
            try{
                // Same independently authenticated Public owner and full
                // same-origin CSRF/origin-bound consent as consent_sign.
                $bridge->attest((string)$this->ownerMoveCookieToken($headers),
                    $req['challenge'],(string)$req['csrf'],
                    $this->header($headers,'Origin'),
                    $this->header($headers,'Sec-Fetch-Site'),time());
                $saved=(new \Sokna\PublicEdge\Security\PublicMoveConsentReceiptStore(
                    $this->core->database(),$keys
                ))->status((string)($req['challenge']['operation_id']??''),time());
                if(($saved['status']??null)!=='owner_approved')
                    return $this->json(SafeErrors::response(409,'owner_receipt_missing'));
                $setup=(new \Sokna\PublicEdge\Setup\PublicSetupService($this->componentRoot))->status();
                $installationId=(string)($setup['installation_id']??'');
                if(($setup['paired']??false)!==true||$installationId===''||
                   !hash_equals($installationId,(string)($req['challenge']['local_installation_id']??'')))
                    return $this->json(SafeErrors::response(409,'existing_move_other_installation'));
                $minimum=(int)($req['minimum_next_epoch']??0);
                $offer=$this->core->authorityEpoch()->createOffer(
                    $installationId,900,'authenticated_target_owner_move',$minimum,true,
                    ($req['discard_pending_guest_work']??null)===true
                );
                return $this->json(['status'=>200,'body'=>[
                    'ok'=>true,'move_kind'=>'existing_paired',
                    'owner_authority_code'=>(string)$offer['enrollment_code'],
                    'owner_next_epoch'=>(int)$offer['authority_epoch'],
                    'safe_to_cutover'=>false,
                ]]);
            }catch(\RuntimeException $e){
                // Only reveal safe decision codes to an owner who passed the
                // independent challenge + CSRF verification above.
                if($e->getMessage()==='destination_pending_guest_work')
                    return $this->json(SafeErrors::response(409,'destination_pending_guest_work'));
                if($e->getMessage()==='destination_claim_active')
                    return $this->json(SafeErrors::response(409,'destination_claim_active'));
                return $this->json(SafeErrors::response(409,'existing_move_pending_or_unavailable'));
            }catch(\Throwable){
                return $this->json(SafeErrors::response(409,'existing_move_pending_or_unavailable'));
            }
        }
        $result=(new \Sokna\PublicEdge\Http\PublicMoveOwnerHttpAdapter(
            $owners,$bridge,$instanceId,$base
        ))->handle($action,$headers,$rawBody,time());
        if ($action!=='consent_sign'||(int)($result['status']??0)!==200) return $result;
        $req=json_decode($rawBody,true);
        $body=json_decode((string)($result['body']??''),true);
        if (!is_array($req)||!is_array($req['challenge']??null)||!is_array($body)||
            !is_array($body['proof']??null))
            return $this->json(SafeErrors::response(400,'invalid_consent_payload'));
        // Never trust client-provided owner proof. It is produced above by
        // the authenticated, same-origin Public owner HTTP adapter.
        $saved=(new \Sokna\PublicEdge\Security\PublicMoveConsentReceiptStore(
            $this->core->database(),$keys
        ))->create($req['challenge'],$body['proof'],$instanceId,time());
        $setup=new \Sokna\PublicEdge\Setup\PublicSetupService($this->componentRoot);
        $pairingCode='';
        $setupState=$setup->status();
        $kind='fresh_unpaired';
        $ownerAuthorityCode='';
        $newEpoch=0;
        if (($setupState['paired']??true)===false) {
            (new \Sokna\PublicEdge\Security\PublicMoveReplacementGate(
                $this->core->database()
            ))->requireEmpty();
            $pairingCode=$setup->issueOwnerPairingCode();
        } else {
            $kind='existing_paired';
            $installedId=(string)($setupState['installation_id']??'');
            $requestedId=(string)($req['challenge']['local_installation_id']??'');
            if ($installedId==='' || !hash_equals($installedId,$requestedId)) {
                return $this->json(\Sokna\PublicEdge\Http\SafeErrors::response(409,
                    'existing_move_other_installation'));
            }
            // Owner consent only, NO authority mutation at this stage.
            // The normal Local administrator must independently confirm first.
        }
        return $this->json(['status'=>200,'body'=>[
            'ok'=>true,'proof'=>$body['proof'],
            'receipt_envelope'=>$saved['receipt_envelope'],
            'pairing_code'=>$pairingCode,
            'move_kind'=>$kind,
            'owner_authority_code'=>$ownerAuthorityCode,
            'owner_next_epoch'=>$newEpoch,
            'safe_to_cutover'=>false,
        ]]);
    }

    /** Owner session is a separate HttpOnly cookie, never the Staff session. */
    private function ownerMoveCookieToken(array $headers): string
    {
        $cookie=$this->header($headers,'Cookie');
        foreach(explode(';',$cookie) as $part){
            [$key,$value]=array_pad(explode('=',trim($part),2),2,'');
            if($key==='sokna_public_owner')return rawurldecode($value);
        }
        return '';
    }

    private function sessionToken(array $headers): string
    {
        $cookie=$this->header($headers,'Cookie');foreach(explode(';',$cookie) as $part){[$k,$v]=array_pad(explode('=',trim($part),2),2,'');if($k==='sokna_staff')return rawurldecode($v);}return '';
    }
    private function sessionCookie(string $token,int $ttl): string
    {
        $parts=['sokna_staff='.rawurlencode($token),'Path='.$this->cookiePath(),'HttpOnly','SameSite=Strict'];if($ttl<=0)$parts[]='Max-Age=0';else $parts[]='Max-Age='.$ttl;
        if(strtolower((string)$this->core->config()->get('app.cookie_secure','1'))!=='0')$parts[]='Secure';return implode('; ',$parts);
    }

    private function guestApi(string $path, string $rawBody, array $headers): array
    {
        $payload = json_decode($rawBody, true);
        $installationId = is_array($payload) ? trim((string)($payload['installation_id'] ?? '')) : '';
        if ($installationId === '') $installationId = trim($this->core->config()->string('app.default_installation_id'));
        if ($installationId === '' || preg_match('/^[A-Za-z0-9._:-]{1,96}$/D', $installationId) !== 1) {
            return $this->json(SafeErrors::response(400, 'invalid_installation', $this->correlationId($headers)));
        }

        $adapter = new GuestCompatibilityHttpAdapter($this->core);
        $result = match ($path) {
            '/api/guest/order' => $adapter->createOrder($installationId, $rawBody),
            '/api/guest/orders' => $adapter->guestOrders($installationId, $rawBody),
            '/api/guest/order/quote' => $adapter->orderQuote($installationId, $rawBody),
            '/api/guest/order/status' => $adapter->orderStatus($installationId, $rawBody),
            '/api/guest/table/context' => $adapter->tableContext($installationId, $rawBody),
            '/api/guest/waiter' => $adapter->waiterCall($installationId, $rawBody),
            '/api/guest/changes' => $adapter->changes($installationId, $rawBody),
            '/api/guest/metric' => $adapter->metric($installationId, $rawBody),
            default => SafeErrors::response(404, 'route_not_found', $this->correlationId($headers)),
        };
        return $this->json($result);
    }

    private function installationId(array $query): string
    {
        $candidate = trim((string)($query['installation'] ?? ''));
        if ($candidate === '') $candidate = trim($this->core->config()->string('app.default_installation_id'));
        if ($candidate === '' || preg_match('/^[A-Za-z0-9._:-]{1,96}$/D', $candidate) !== 1) return '';
        return $candidate;
    }

    private function normalizePath(string $path): string
    {
        $path = parse_url($path, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || str_contains($path, "\0")) return '';
        $decoded = rawurldecode($path);
        if (str_contains($decoded, '..') || str_contains($decoded, '\\')) return '';
        $decoded = '/' . ltrim($decoded, '/');
        return $decoded !== '/' ? rtrim($decoded, '/') : '/';
    }

    private function basePath(): string
    {
        return $this->core->deploymentIdentity()->basePath();
    }

    private function url(string $path): string
    {
        return PublicPath::prefix($this->basePath(), $path);
    }

    private function cookiePath(): string
    {
        $base=$this->basePath();return $base===''?'/':$base.'/';
    }

    private function staffManifest(): array
    {
        $manifest=[
            // Manifest id is intentionally root-relative and base-path independent.
            // Cross-origin moves are a new browser app identity by platform design;
            // same-origin base-path rebinds keep this logical identity unchanged.
            'id'=>$this->core->pwaIdentity()->manifestIdPath(),
            'name'=>'سکنا — پنل همکار',
            'short_name'=>'سکنا',
            'description'=>'دسترسی امن همکاران سکنا به عملیات و اعلان‌های مجاز',
            'lang'=>'fa',
            'dir'=>'rtl',
            'start_url'=>$this->url('/staff/'),
            'scope'=>$this->url('/staff/'),
            'display'=>'standalone',
            'categories'=>['business','productivity'],
            'background_color'=>\Sokna\PublicEdge\Deployment\PwaIdentity::BACKGROUND_COLOR,
            'theme_color'=>\Sokna\PublicEdge\Deployment\PwaIdentity::THEME_COLOR,
            'icons'=>[
                ['src'=>$this->url('/staff/icon-192.png'),'sizes'=>'192x192','type'=>'image/png','purpose'=>'any'],
                ['src'=>$this->url('/staff/icon-512.png'),'sizes'=>'512x512','type'=>'image/png','purpose'=>'any'],
                ['src'=>$this->url('/staff/icon-maskable-192.png'),'sizes'=>'192x192','type'=>'image/png','purpose'=>'maskable'],
                ['src'=>$this->url('/staff/icon-maskable-512.png'),'sizes'=>'512x512','type'=>'image/png','purpose'=>'maskable'],
            ],
            'shortcuts'=>[
                ['name'=>'سالن و میزها','short_name'=>'سالن','url'=>$this->url('/staff/operator/')],
                ['name'=>'آماده‌سازی','short_name'=>'آماده‌سازی','url'=>$this->url('/staff/preparation/')],
                ['name'=>'اعلان‌ها','short_name'=>'اعلان‌ها','url'=>$this->url('/staff/notifications/')],
            ],
        ];
        $body=json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!is_string($body))$body='{}';
        return $this->withSecurityHeaders($this->response(200,$body,['Content-Type'=>'application/manifest+json; charset=utf-8','Cache-Control'=>'no-cache, max-age=0']));
    }

    private function correlationId(array $headers): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string)$key, 'X-Correlation-ID') === 0) return (string)$value;
        }
        return null;
    }

    private function json(array $result): array
    {
        $body = json_encode(\Sokna\Shared\IranTime::transport($result['body'] ?? [],'UTC'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) $body = '{"ok":false,"error":"encoding_failed"}';
        return $this->withSecurityHeaders($this->response(
            (int)($result['status'] ?? 500),
            $body,
            ['Content-Type' => 'application/json; charset=utf-8', 'X-Sokna-Time-Zone'=>'Asia/Tehran', 'Cache-Control' => 'no-store, max-age=0'],
        ));
    }

    private function versionedAssetUrl(string $publicPath): string
    {
        $file = $this->componentRoot . '/' . ltrim($publicPath, '/');
        if (!is_file($file) || !is_readable($file)) return $this->url($publicPath);
        $hash = hash_file('sha256', $file);
        if (!is_string($hash) || $hash === '') return $this->url($publicPath);
        return $this->url($publicPath) . '?v=' . substr($hash, 0, 16);
    }

    private function staticFile(string $path, string $contentType, int $maxAge, bool $immutable = false): array
    {
        if (!is_file($path) || !is_readable($path)) return $this->json(SafeErrors::response(404, 'asset_not_found'));
        return $this->withSecurityHeaders([
            'status' => 200,
            'headers' => [
                'Content-Type' => $contentType,
                'Cache-Control' => 'public, max-age=' . $maxAge . ($immutable ? ', immutable' : ''),
                'Content-Length' => (string)filesize($path),
            ],
            'file_path' => $path,
        ]);
    }

    private function response(int $status, string $body, array $headers = []): array
    {
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    /** @param list<string> $connectUrls */
    private function withSecurityHeaders(array $response,array $connectUrls=[]): array
    {
        $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
        $connect=["'self'"];
        foreach($connectUrls as $value){$origin=$this->cspConnectOrigin((string)$value);if($origin!==''&&!in_array($origin,$connect,true))$connect[]=$origin;}
        $headers += [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src ".implode(' ',$connect)."; base-uri 'self'; frame-ancestors 'none'; form-action 'self'",
        ];
        $response['headers'] = $headers;
        return $response;
    }

    private function cspConnectOrigin(string $value): string
    {
        $value=trim($value);if($value==='')return '';
        $parts=parse_url($value);if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||empty($parts['host'])||!empty($parts['user'])||!empty($parts['pass']))return '';
        $host=strtolower(trim((string)$parts['host'],'[]'));if($host==='')return '';
        $authority=str_contains($host,':')?'['.$host.']':$host;$port=isset($parts['port'])?(int)$parts['port']:null;if($port!==null&&$port!==443)$authority.=':'.$port;
        return 'https://'.$authority;
    }
}
