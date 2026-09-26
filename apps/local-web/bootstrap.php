<?php
declare(strict_types=1);

use Sokna\Local\Core\Bootstrap;

require_once __DIR__ . '/src/Core/Config.php';
require_once __DIR__ . '/src/Core/Database.php';
require_once __DIR__ . '/src/Core/Observability.php';
require_once __DIR__ . '/src/Core/Bootstrap.php';

function sokna_local_bootstrap(array $config): Bootstrap
{
    return Bootstrap::fromArray($config);
}
