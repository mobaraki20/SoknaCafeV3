<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Core;

use PDO;

final class Database
{
    public static function connect(Config $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config->requiredString('db.host'),
            $config->string('db.port', '3306'),
            $config->requiredString('db.name'),
            $config->string('db.charset', 'utf8mb4'),
        );

        $pdo = new PDO($dsn, $config->requiredString('db.user'), $config->string('db.pass'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }
}
