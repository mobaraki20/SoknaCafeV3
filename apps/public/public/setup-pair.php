<?php
declare(strict_types=1);

if (function_exists('header_remove')) header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$root = dirname(__DIR__);
$configPath = $root . '/config.php';

function setup_pair_reply(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') setup_pair_reply(405, ['ok'=>false,'error'=>'method_not_allowed']);
if (!is_file($configPath) || !is_readable($configPath)) setup_pair_reply(409, ['ok'=>false,'error'=>'public_not_configured']);

try {
    require_once $root . '/bootstrap.php';
    $config = require $configPath;
    if (!is_array($config)) throw new RuntimeException('config_invalid');
    $core = sokna_public_bootstrap($config);
    $core->migrations()->migrate();
    $storage = trim((string)($config['app']['storage_dir'] ?? ''));
    if ($storage === '') $storage = $root . '/storage';

    $raw = (string)file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input)) setup_pair_reply(400, ['ok'=>false,'error'=>'invalid_json']);

    $service = new \Sokna\PublicEdge\Setup\PublicInitialPairingService(
        $core->database(),
        $core->pairingSecrets(),
        $storage,
        $configPath,
    );
    $result = $service->complete(
        (string)($input['installation_id'] ?? ''),
        (string)($input['pairing_code'] ?? ''),
        (string)($input['shared_secret'] ?? ''),
        (string)($input['display_name'] ?? ''),
    );
    setup_pair_reply(200, ['ok'=>true] + $result);
} catch (Throwable $e) {
    $code = $e->getMessage();
    $status = in_array($code, ['pairing_denied','pairing_expired','pairing_consumed','pairing_locked'], true) ? 401 :
        (in_array($code, ['already_paired','pairing_not_issued'], true) ? 409 : 422);
    error_log('public initial pairing: ' . preg_replace('/[^A-Za-z0-9_.:-]+/', '_', $code));
    setup_pair_reply($status, ['ok'=>false,'error'=>$code]);
}
