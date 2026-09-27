<?php
declare(strict_types=1);

namespace Sokna\Local\Search;

interface SearchProvider
{
    /** @return list<array<string,mixed>> */
    public function search(string $query,array $user,int $limit): array;
}
