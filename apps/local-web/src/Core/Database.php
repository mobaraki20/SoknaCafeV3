<?php
declare(strict_types=1);

namespace Sokna\Local\Core;

use PDO;

final class Database
{
    public static function connect(Config $config): PDO
    {
        $host = $config->requiredString('db.host');
        $port = $config->string('db.port', '3306');
        $name = $config->requiredString('db.name');
        $charset = $config->string('db.charset', 'utf8mb4');
        $user = $config->requiredString('db.user');
        $pass = (string)$config->get('db.pass', '');

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $host,
            $port,
            $name,
            $charset
        );

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $pdo->exec('SET time_zone = ' . $pdo->quote(date('P')));
        return $pdo;
    }
}
