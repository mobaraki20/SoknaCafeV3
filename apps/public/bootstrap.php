<?php
declare(strict_types=1);

use Sokna\PublicEdge\Core\Bootstrap;

require_once __DIR__ . '/src/Core/Config.php';
require_once __DIR__ . '/src/Core/Database.php';
require_once __DIR__ . '/src/Core/Migrations.php';
require_once __DIR__ . '/src/Core/Bootstrap.php';

function sokna_public_bootstrap(array $config): Bootstrap
{
    return Bootstrap::fromArray($config);
}
