<?php
declare(strict_types=1);

use Sokna\Local\Core\Bootstrap;

require_once __DIR__ . '/src/Core/Config.php';
require_once __DIR__ . '/src/Core/Database.php';
require_once __DIR__ . '/src/Core/Observability.php';
require_once __DIR__ . '/src/Core/IdentityRepository.php';
require_once __DIR__ . '/src/Core/PdoIdentityRepository.php';
require_once __DIR__ . '/src/Core/Capabilities.php';
require_once __DIR__ . '/src/Core/Session.php';
require_once __DIR__ . '/src/Core/Auth.php';
require_once __DIR__ . '/src/Core/Migrations.php';
require_once __DIR__ . '/src/Domain/Sellables/SellableKind.php';
require_once __DIR__ . '/src/Domain/Sellables/SellableRepository.php';
require_once __DIR__ . '/src/Domain/Orders/BusinessClock.php';
require_once __DIR__ . '/src/Domain/Orders/OrderCommitException.php';
require_once __DIR__ . '/src/Domain/Orders/OrderCommitService.php';
require_once __DIR__ . '/src/Core/Bootstrap.php';

function sokna_local_bootstrap(array $config): Bootstrap
{
    return Bootstrap::fromArray($config);
}
