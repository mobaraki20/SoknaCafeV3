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

        try {
            if ($method === 'GET' && $path === '/') {
                $suffix = $query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
                return $this->response(302, '', ['Location' => '/menu' . $suffix, 'Cache-Control' => 'no-store', 'Content-Type' => 'text/plain; charset=utf-8']);
            }
            if ($method === 'GET' && $path === '/robots.txt') {
                return $this->response(200, "User-agent: *\nDisallow: /api/\nDisallow: /emergency/\n", ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
            }
            if ($method === 'GET' && $path === '/health') {
                $result = (new HealthHttpAdapter($this->core))->status($this->correlationId($headers));
                return $this->json($result);
            }
            if ($method === 'GET' && $path === '/assets/scds/guest.css') {
                return $this->staticFile($this->componentRoot . '/assets/scds/guest.css', 'text/css; charset=utf-8', 300);
            }
            if ($method === 'GET' && $path === '/assets/scds/guest.js') {
                return $this->staticFile($this->componentRoot . '/assets/scds/guest.js', 'application/javascript; charset=utf-8', 300);
            }
            if ($method === 'GET' && $path === '/assets/scds/remote-staff.css') {
                return $this->staticFile($this->componentRoot . '/assets/scds/remote-staff.css', 'text/css; charset=utf-8', 300);
            }
            if ($method === 'GET' && $path === '/assets/scds/remote-staff.js') {
                return $this->staticFile($this->componentRoot . '/assets/scds/remote-staff.js', 'application/javascript; charset=utf-8', 300);
            }
            if ($method === 'GET' && preg_match('#^/media/([A-Za-z0-9._-]{1,96})/([a-f0-9]{64})\.(jpg|png|webp|gif|svg)$#D', $path, $m) === 1) {
                $file = $this->core->guestMedia()->publicFile($m[1], $m[2], $m[3]);
                if ($file === null) return $this->json(SafeErrors::response(404, 'media_not_found', $this->correlationId($headers)));
                $response = $this->staticFile($file['path'], $file['mime'], 31536000, true);
                if ($m[3] === 'svg') $response['headers']['Content-Security-Policy'] = "default-src 'none'; sandbox";
                return $response;
            }
            if ($method === 'GET' && $path === '/menu') {
                $installationId = $this->installationId($query);
                if ($installationId === '') return $this->json(SafeErrors::response(404, 'installation_not_found', $this->correlationId($headers)));
                return $this->withSecurityHeaders($this->core->guestRenderer()->render($installationId, $query, [
                    'css' => '/assets/scds/guest.css',
                    'js' => '/assets/scds/guest.js',
                    'create_order' => '/api/guest/order',
                    'waiter_call' => '/api/guest/waiter',
                    'media_base' => '/media',
                ]));
            }

            if ($method === 'GET' && $path === '/staff/login') {
                $installationId=$this->installationId($query);
                return $this->withSecurityHeaders($this->core->remoteStaffRenderer()->login($installationId));
            }
            if ($method === 'POST' && $path === '/staff/login') {
                $form=$this->formPayload($rawBody,$headers);
                $installationId=trim((string)($form['installation_id']??''));
                if($installationId==='')$installationId=trim($this->core->config()->string('app.default_installation_id'));
                $result=$this->core->loginService()->login($installationId,(string)($form['username']??''),(string)($form['password']??''),$this->header($headers,'X-Forwarded-For')?:$this->header($headers,'X-Real-IP'),$this->correlationId($headers)??'');
                if(($result['ok']??false)!==true){
                    $msg=($result['error']??'')==='too_many_attempts'?'تلاش‌های ورود بیش از حد بوده؛ کمی بعد دوباره امتحان کنید.':'نام کاربری یا رمز عبور درست نیست.';
                    $response=$this->core->remoteStaffRenderer()->login($installationId,$msg);$response['status']=(int)($result['status']??401);return $this->withSecurityHeaders($response);
                }
                $cookie=$this->sessionCookie((string)$result['token'],(int)($result['expires_in']??28800));
                return $this->withSecurityHeaders($this->response(303,'',['Location'=>'/staff','Set-Cookie'=>$cookie,'Cache-Control'=>'no-store','Content-Type'=>'text/plain; charset=utf-8']));
            }
            if ($method === 'POST' && $path === '/staff/logout') {
                $token=$this->sessionToken($headers);$this->core->publicSessions()->revoke($token);
                return $this->withSecurityHeaders($this->response(303,'',['Location'=>'/staff/login','Set-Cookie'=>$this->sessionCookie('',0),'Cache-Control'=>'no-store','Content-Type'=>'text/plain; charset=utf-8']));
            }
            if ($method === 'GET' && $path === '/staff') {
                $session=$this->core->publicSessions()->resolve($this->sessionToken($headers));
                if(!is_array($session))return $this->withSecurityHeaders($this->response(302,'',['Location'=>'/staff/login','Cache-Control'=>'no-store','Content-Type'=>'text/plain; charset=utf-8']));
                return $this->withSecurityHeaders($this->core->remoteStaffRenderer()->dashboard($session));
            }
            if ($method === 'GET' && $path === '/api/staff/read') {
                $result=(new RemoteReadModelHttpAdapter($this->core))->read($this->sessionToken($headers),(string)($query['model']??''));
                return $this->json($result);
            }
            if ($method === 'POST' && str_starts_with($path, '/api/v1/local/')) {
                return $this->localProjectionApi($path,$rawBody,$headers);
            }

            if ($method === 'POST' && str_starts_with($path, '/api/guest/')) {
                return $this->guestApi($path, $rawBody, $headers);
            }

            return $this->json(SafeErrors::response(404, 'route_not_found', $this->correlationId($headers)));
        } catch (Throwable $error) {
            return $this->json(SafeErrors::fromThrowable($error, $this->correlationId($headers)));
        }
    }

    private function localProjectionApi(string $path,string $rawBody,array $headers): array
    {
        $installationId=trim($this->header($headers,'X-Sokna-Installation'));
        $timestamp=$this->header($headers,'X-Sokna-Timestamp');$nonce=$this->header($headers,'X-Sokna-Nonce');$signature=$this->header($headers,'X-Sokna-Signature');
        if($installationId==='')return $this->json(SafeErrors::response(400,'missing_installation',$this->correlationId($headers)));
        $result=match($path){
            '/api/v1/local/installation'=>(new InstallationProjectionHttpAdapter($this->core))->sync($installationId,'POST',$path,$timestamp,$nonce,$signature,$rawBody),
            '/api/v1/local/auth-projections'=>(new AuthHttpAdapter($this->core))->projectionSync($installationId,'POST',$path,$timestamp,$nonce,$signature,$rawBody),
            '/api/v1/local/read-models'=>(new RemoteReadModelHttpAdapter($this->core))->sync($installationId,'POST',$path,$timestamp,$nonce,$signature,$rawBody),
            '/api/v1/local/heartbeat'=>(new ConnectivityHttpAdapter($this->core))->heartbeat($installationId,'POST',$path,$timestamp,$nonce,$signature,$rawBody),
            '/api/v1/local/guest/publish'=>(new GuestSyncHttpAdapter($this->core))->publish($installationId,'POST',$path,$timestamp,$nonce,$signature,$rawBody),
            '/api/v1/local/guest/availability'=>(new GuestSyncHttpAdapter($this->core))->availability($installationId,'POST',$path,$timestamp,$nonce,$signature,$rawBody),
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
    private function sessionToken(array $headers): string
    {
        $cookie=$this->header($headers,'Cookie');foreach(explode(';',$cookie) as $part){[$k,$v]=array_pad(explode('=',trim($part),2),2,'');if($k==='sokna_staff')return rawurldecode($v);}return '';
    }
    private function sessionCookie(string $token,int $ttl): string
    {
        $parts=['sokna_staff='.rawurlencode($token),'Path=/','HttpOnly','SameSite=Strict'];if($ttl<=0)$parts[]='Max-Age=0';else $parts[]='Max-Age='.$ttl;
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

    private function correlationId(array $headers): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string)$key, 'X-Correlation-ID') === 0) return (string)$value;
        }
        return null;
    }

    private function json(array $result): array
    {
        $body = json_encode($result['body'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) $body = '{"ok":false,"error":"encoding_failed"}';
        return $this->withSecurityHeaders($this->response(
            (int)($result['status'] ?? 500),
            $body,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store, max-age=0'],
        ));
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

    private function withSecurityHeaders(array $response): array
    {
        $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
        $headers += [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'",
        ];
        $response['headers'] = $headers;
        return $response;
    }
}
