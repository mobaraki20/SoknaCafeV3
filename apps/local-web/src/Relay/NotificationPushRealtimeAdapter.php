<?php
declare(strict_types=1);

namespace Sokna\Local\Relay;

use Sokna\Local\Core\Capabilities;
use Sokna\Local\Core\IdentityRepository;
use Sokna\Local\Domain\Notifications\NotificationException;
use Sokna\Local\Domain\Notifications\NotificationService;

final class NotificationPushRealtimeAdapter
{
    public function __construct(
        private readonly IdentityRepository $identity,
        private readonly Capabilities $capabilities,
        private readonly NotificationService $notifications,
    ) {}

    public function dispatch(array $envelope): array
    {
        $kind=trim((string)($envelope['kind']??''));
        $projection=trim((string)($envelope['actor_projection_id']??''));
        if(preg_match('/^user:(\d+)$/D',$projection,$m)!==1)
            throw new NotificationException('actor_invalid','هویت کاربر راه‌دور معتبر نیست.',403);
        $user=$this->identity->findActiveById((int)$m[1]);
        if($user===null)
            throw new NotificationException('actor_invalid','حساب کاربری راه‌دور فعال نیست.',403);
        $isAdmin=(string)($user['role']??'')==='admin';
        if(!$isAdmin&&(!$this->capabilities->has('remote_access',$user)||!$this->capabilities->has('remote_notifications',$user)))
            throw new NotificationException('forbidden','دسترسی اعلان راه‌دور برای این حساب فعال نیست.',403);

        $payload=is_array($envelope['payload']??null)?$envelope['payload']:[];
        if($kind==='notification.push.subscribe'){
            $subscription=is_array($payload['subscription']??null)?$payload['subscription']:[];
            return ['ok'=>true,'action'=>'subscribe']+$this->notifications->registerPush($subscription,$user);
        }
        if($kind==='notification.push.unsubscribe'){
            $endpoint=trim((string)($payload['endpoint']??''));
            if($endpoint==='')throw new NotificationException('invalid_subscription','نشانی Push معتبر نیست.',422);
            return ['ok'=>true,'action'=>'unsubscribe']+$this->notifications->unregisterPush($endpoint,$user);
        }
        throw new NotificationException('unsupported_kind','عملیات اعلان راه‌دور معتبر نیست.',422);
    }
}
