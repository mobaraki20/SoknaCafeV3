<?php
declare(strict_types=1);

namespace Sokna\Local\Core;

use PDOException;
use RuntimeException;

final class MigrationExecutionException extends RuntimeException
{
    public readonly int $mysqlErrno;
    public readonly string $sqlState;
    public readonly string $databaseMessage;

    public function __construct(
        public readonly string $migrationVersion,
        public readonly int $statementOrdinal,
        PDOException $previous,
    ) {
        $info=is_array($previous->errorInfo??null)?$previous->errorInfo:[];
        $this->sqlState=trim((string)($info[0]??$previous->getCode()));
        $this->mysqlErrno=(int)($info[1]??0);
        $message=trim((string)($info[2]??$previous->getMessage()));
        $this->databaseMessage=$message!==''?$message:'Database statement failed.';
        parent::__construct("Migration '{$migrationVersion}' statement {$statementOrdinal} failed.",0,$previous);
    }
}
