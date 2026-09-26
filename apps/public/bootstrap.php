<?php
declare(strict_types=1);

use Sokna\PublicEdge\Core\Bootstrap;

require_once __DIR__ . '/src/Core/Config.php';
require_once __DIR__ . '/src/Core/Database.php';
require_once __DIR__ . '/src/Core/Migrations.php';
require_once __DIR__ . '/src/Auth/AuthProjectionService.php';
require_once __DIR__ . '/src/Auth/AuthThrottle.php';
require_once __DIR__ . '/src/Auth/AuthSecurityAudit.php';
require_once __DIR__ . '/src/Auth/PublicSessionStore.php';
require_once __DIR__ . '/src/Auth/PublicLoginService.php';
require_once __DIR__ . '/src/Core/Bootstrap.php';

function sokna_public_bootstrap(array $config): Bootstrap
{
    return Bootstrap::fromArray($config);
}
