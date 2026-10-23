<?php
declare(strict_types=1);

namespace Sokna\Local\Core;

use Sokna\Local\Domain\PublicEdge\RemoteCredentialService;
use Throwable;

final class Auth
{
    private bool $validated = false;
    private ?array $validatedUser = null;

    public function __construct(
        private readonly IdentityRepository $repository,
        private readonly Capabilities $capabilities,
        private readonly int $sessionLifetime = Session::DEFAULT_LIFETIME,
        private readonly ?LoginAccessService $loginAccess = null,
        private readonly ?RemoteCredentialService $remoteCredentials = null,
    ) {
    }

    public function currentUser(): ?array
    {
        if ($this->validated) return $this->validatedUser;

        $user = $_SESSION['user'] ?? null;
        if (!is_array($user)) {
            $this->validated = true;
            return null;
        }

        $lastActivity = (int)($_SESSION['user_last_activity'] ?? time());
        if (time() - $lastActivity > $this->sessionLifetime) {
            $this->logout();
            $this->validated = true;
            return null;
        }

        try {
            $fresh = $this->repository->findActiveById((int)($user['id'] ?? 0));
            if ($fresh === null) {
                $this->logout();
                $this->validated = true;
                return null;
            }
            $user = $this->sessionUserShape($fresh);
            $_SESSION['user'] = $user;
        } catch (Throwable) {
            // Compatibility with the proven Local behavior: a transient DB read failure
            // does not immediately eject an already authenticated shift session.
        }

        $_SESSION['user_last_activity'] = time();
        Session::refreshCookie($this->sessionLifetime);
        $this->validatedUser = $user;
        $this->validated = true;
        return $this->validatedUser;
    }

    /** @return list<array{id:int,username:string,display_name:string,role:string}> */
    public function activeLoginUsers(): array
    {
        if ($this->loginAccess === null) return [];
        try { return $this->loginAccess->activeUsers(); } catch (Throwable) { return []; }
    }

    /**
     * @return array{ok:bool,code:string,retry_after:int,failures:int}
     */
    public function loginAttempt(string $username, string $password): array
    {
        $username = trim($username);
        if ($username === '' || $password === '') return ['ok'=>false,'code'=>'invalid','retry_after'=>0,'failures'=>0];

        $user = $this->repository->findActiveByUsername($username);
        if ($user === null) return ['ok'=>false,'code'=>'invalid','retry_after'=>0,'failures'=>0];
        $userId = (int)($user['id'] ?? 0);

        if ($this->loginAccess !== null) {
            $state = $this->loginAccess->status($userId);
            if ($state['locked']) return ['ok'=>false,'code'=>'locked','retry_after'=>$state['retry_after'],'failures'=>$state['failures']];
        }

        if (!password_verify($password, (string)($user['password_hash'] ?? ''))) {
            $state = $this->loginAccess?->fail($userId) ?? ['locked'=>false,'retry_after'=>0,'failures'=>0];
            return ['ok'=>false,'code'=>$state['locked']?'locked':'invalid','retry_after'=>(int)$state['retry_after'],'failures'=>(int)$state['failures']];
        }

        $this->loginAccess?->clear($userId);
        $this->remoteCredentials?->ensureMatchesVerifiedPassword($userId,$password);
        $this->loginUser($user);
        return ['ok'=>true,'code'=>'ok','retry_after'=>0,'failures'=>0];
    }

    public function login(string $username, string $password): bool
    {
        return $this->loginAttempt($username, $password)['ok'];
    }

    public function loginUser(array $user): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
        $_SESSION['user_last_activity'] = time();
        $_SESSION['user'] = $this->sessionUserShape($user);
        $this->validatedUser = $_SESSION['user'];
        $this->validated = true;
    }

    public function logout(): void
    {
        Session::destroy();
        $this->validatedUser = null;
        $this->validated = true;
    }

    /** @return list<string> */
    public function userCapabilities(?array $user = null): array
    {
        $user ??= $this->currentUser();
        return is_array($user) ? $this->capabilities->forUser($user) : [];
    }

    public function hasCapability(string $capability, ?array $user = null): bool
    {
        $user ??= $this->currentUser();
        return is_array($user) && $this->capabilities->has($capability, $user);
    }

    /** @return list<string> */
    public function preparationAreas(?array $user = null): array
    {
        $user ??= $this->currentUser();
        return is_array($user) ? $this->capabilities->preparationAreas($user) : [];
    }

    private function sessionUserShape(array $user): array
    {
        return [
            'id' => (int)($user['id'] ?? 0),
            'username' => (string)($user['username'] ?? ''),
            'display_name' => (string)($user['display_name'] ?? ''),
            'role' => (string)($user['role'] ?? ''),
        ];
    }
}
