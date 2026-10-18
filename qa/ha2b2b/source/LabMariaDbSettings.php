<?php
declare(strict_types=1);
/** Disposable MariaDB lab settings only. Never a production database connector. */
final class LabMariaDbSettings {
    public static function validate(string $lab, string $host, string $port, bool $confirmed): array {
        if (!$confirmed) throw new RuntimeException('EXPLICIT_DISPOSABLE_CONFIRM_REQUIRED');
        if (!preg_match('/\Asokna_ha2_lab_[a-f0-9]{8,16}\z/D', $lab)) throw new RuntimeException('UNSAFE_DATABASE_NAME');
        if (!preg_match('/\A[a-zA-Z0-9.-]{1,253}\z/D', $host)) throw new RuntimeException('UNSAFE_DB_HOST');
        $parsed = filter_var($port, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1, 'max_range'=>65535]]);
        if ($parsed===false) throw new RuntimeException('UNSAFE_PORT');
        return ['database'=>$lab,'host'=>$host,'port'=>(int)$parsed];
    }
    public static function fromEnvironment(): array {
        return self::validate(
            getenv('SOKNA_HA2B_DB_NAME') ?: '',
            getenv('SOKNA_HA2B_DB_HOST') ?: '127.0.0.1',
            getenv('SOKNA_HA2B_DB_PORT') ?: '3306',
            getenv('SOKNA_HA2B_DISPOSABLE_CONFIRM') === 'YES_DISPOSABLE_NO_PRODUCTION'
        );
    }
    public static function connect(): PDO {
        $cfg=self::fromEnvironment();
        if (!in_array('mysql',PDO::getAvailableDrivers(),true)) throw new RuntimeException('PDO_MYSQL_UNAVAILABLE');
        $dsn='mysql:host='.$cfg['host'].';port='.$cfg['port'].';dbname='.$cfg['database'].';charset=utf8mb4';
        return new PDO($dsn,getenv('SOKNA_HA2B_DB_USER')?:'',getenv('SOKNA_HA2B_DB_PASS')?:'',[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES=>false,
            PDO::ATTR_TIMEOUT=>3,
        ]);
    }
}
