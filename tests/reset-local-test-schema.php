<?php
declare(strict_types=1);

$host=(string)(getenv('SOKNA_TEST_DB_HOST')?:'127.0.0.1');
$port=(string)(getenv('SOKNA_TEST_DB_PORT')?:'3306');
$name=(string)(getenv('SOKNA_TEST_DB_NAME')?:'sokna_m2');
$user=(string)(getenv('SOKNA_TEST_DB_USER')?:'sokna');
$pass=(string)(getenv('SOKNA_TEST_DB_PASS')?:'sokna');

$pdo=new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",$user,$pass,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
try{
    $stmt=$pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE=?');
    $stmt->execute([$name,'BASE TABLE']);
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $table){
        $safe=str_replace('`','``',(string)$table);
        $pdo->exec('DROP TABLE IF EXISTS `'.$safe.'`');
    }
}finally{
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}
$remaining=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE=?');
$remaining->execute([$name,'BASE TABLE']);
if((int)$remaining->fetchColumn()!==0){
    fwrite(STDERR,"Local test database reset failed.\n");
    exit(1);
}
fwrite(STDOUT,"Local test database reset: OK\n");
