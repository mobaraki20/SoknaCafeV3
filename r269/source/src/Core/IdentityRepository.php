<?php
declare(strict_types=1);

namespace Sokna\Local\Core;

interface IdentityRepository
{
    public function findActiveById(int $userId): ?array;

    public function findActiveByUsername(string $username): ?array;

    /** @return list<string> */
    public function capabilitiesForUser(int $userId): array;

    /** @return list<string> */
    public function preparationAreasForUser(int $userId): array;
}
