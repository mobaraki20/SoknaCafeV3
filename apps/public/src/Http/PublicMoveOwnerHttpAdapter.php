<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Http;

use InvalidArgumentException;
use RuntimeException;
use Sokna\PublicEdge\Security\PublicMoveIndependentOwnerBridge;
use Sokna\PublicEdge\Security\PublicMoveOwnerBootstrapService;
use Throwable;

/**
 * R255 QA: minimal independent Public owner HTTP contract.
 *
 * NOT connected to PublicHttpKernel or advertised as a production route.
 * First setup token must ALREADY have been staged by a trusted installer
 * (never from an anonymous GET/POST). This class contains no stageClaim path.
 * No endpoint in this adapter grants a Local owner grant or switches traffic.
 */
final class PublicMoveOwnerHttpAdapter
{
    public function __construct(
        private readonly PublicMoveOwnerBootstrapService $owners,
        private readonly PublicMoveIndependentOwnerBridge $bridge,
        private readonly string $trustedPublicInstanceId,
        private readonly string $trustedBaseUrl,
    ) {}

    /**
     * Response uses status, headers, body as PublicHttpKernel does.
     * Caller must enforce HTTPS and should dispatch only under guarded setup.
     * @param array<string,string> $headers
     */
    public function handle(string $action, array $headers, string $rawBody, int $now): array
    {
        try {
            if ($now<=0 || strlen($rawBody)>16384 || !$this->sameOrigin($headers)) {
                return self::reply(403,['ok'=>false,'code'=>'request_not_allowed']);
            }
            $body=json_decode($rawBody,true);
            if (!is_array($body) || !str_starts_with(ltrim($rawBody), '{')) {
                return self::reply(400,['ok'=>false,'code'=>'invalid_request']);
            }
            $token=$this->cookieToken($headers);
            if ($action==='setup') {
                $result=$this->owners->initialize(
                    $this->trustedPublicInstanceId,
                    (string)($body['setup_token']??''),
                    (string)($body['password']??''),
                    $now,
                );
                return self::reply(200,['ok'=>true,'status'=>'owner_created','login_required'=>true,
                    'safe_to_cutover'=>false,'result'=>$result]);
            }
            if ($action==='login') {
                $result=$this->owners->login($this->trustedPublicInstanceId,(string)($body['password']??''),$now);
                if (($result['ok']??false)!==true) {
                    return self::reply(401,['ok'=>false,'code'=>'invalid_credentials']);
                }
                $res=self::reply(200,['ok'=>true,'status'=>'owner_signed_in','safe_to_cutover'=>false]);
                $res['headers']['Set-Cookie']=$this->cookie((string)$result['token'],1800);
                return $res;
            }
            if ($action==='logout') {
                $this->owners->logout($token);
                $res=self::reply(200,['ok'=>true,'status'=>'signed_out']);
                $res['headers']['Set-Cookie']=$this->cookie('',0);
                return $res;
            }
            if ($action==='consent_prepare') {
                if (!is_array($body['challenge']??null)) {
                    return self::reply(400,['ok'=>false,'code'=>'invalid_challenge']);
                }
                $csrf=$this->bridge->issueCsrf($token,$body['challenge'],$now);
                return self::reply(200,['ok'=>true,'csrf'=>$csrf,
                    'safe_to_cutover'=>false,'owner_grant_issued'=>false]);
            }
            if ($action==='consent_sign') {
                if (!is_array($body['challenge']??null) || !is_string($body['csrf']??null)) {
                    return self::reply(400,['ok'=>false,'code'=>'invalid_challenge']);
                }
                $proof=$this->bridge->attest(
                    $token,$body['challenge'],$body['csrf'],
                    $this->header($headers,'Origin'),
                    $this->header($headers,'Sec-Fetch-Site'),
                    $now,
                );
                return self::reply(200,['ok'=>true,'proof'=>$proof,
                    'safe_to_cutover'=>false,'owner_grant_issued'=>false]);
            }
            return self::reply(404,['ok'=>false,'code'=>'not_found']);
        } catch (InvalidArgumentException|RuntimeException) {
            // No internal identifiers, key material, setup token, credential or SQL error leaks.
            return self::reply(403,['ok'=>false,'code'=>'owner_action_denied']);
        } catch (Throwable) {
            return self::reply(500,['ok'=>false,'code'=>'owner_action_failed']);
        }
    }

    private function sameOrigin(array $headers): bool
    {
        if ($this->header($headers,'Sec-Fetch-Site')!=='same-origin') return false;
        $p=parse_url($this->trustedBaseUrl);
        if (!is_array($p)||($p['scheme']??null)!=='https'||!isset($p['host'])) return false;
        $expected='https://'.strtolower($p['host']).
            (isset($p['port'])&&(int)$p['port']!==443?':'.(int)$p['port']:'');
        return hash_equals($expected,strtolower($this->header($headers,'Origin')));
    }

    private function cookieToken(array $headers): string
    {
        foreach(explode(';',$this->header($headers,'Cookie')) as $part) {
            $pair=explode('=',trim($part),2);
            if (($pair[0]??'')==='sokna_public_owner') {
                $token=(string)($pair[1]??'');
                return preg_match('/^[a-f0-9]{64}$/D',$token)?$token:'';
            }
        }
        return '';
    }

    private function cookie(string $token,int $age): string
    {
        $p=parse_url($this->trustedBaseUrl);
        $path=is_array($p)?(string)($p['path']??''):'';
        $path='/'.trim($path,'/');
        if($path!=='/')$path.='/';
        return 'sokna_public_owner='.$token.'; Path='.$path.'; Max-Age='.$age.
            '; Secure; HttpOnly; SameSite=Strict';
    }

    private function header(array $headers,string $name): string
    {
        foreach($headers as $k=>$v)if(strcasecmp((string)$k,$name)===0)return trim((string)$v);
        return '';
    }

    private static function reply(int $status,array $payload): array
    {
        return [
            'status'=>$status,
            'headers'=>[
                'Content-Type'=>'application/json; charset=utf-8',
                'Cache-Control'=>'no-store',
                'Pragma'=>'no-cache',
                'X-Content-Type-Options'=>'nosniff',
                'Referrer-Policy'=>'no-referrer',
                'X-Frame-Options'=>'DENY',
                'Content-Security-Policy'=>"default-src 'none'; frame-ancestors 'none'",
            ],
            'body'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
        ];
    }
}
