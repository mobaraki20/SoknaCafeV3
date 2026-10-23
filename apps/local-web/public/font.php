<?php
declare(strict_types=1);

use Sokna\Local\UI\FontRuntime;

require_once dirname(__DIR__) . '/src/UI/FontRuntime.php';

if (strtolower(trim((string)($_GET['family'] ?? 'vazirmatn'))) !== 'vazirmatn') {
    http_response_code(404);
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    exit;
}

// public/ lives at <SOKNA_ROOT>/Web/public; keep the downloaded font outside
// the replaceable Web payload so upgrades cannot remove it.
$packageRoot = dirname(__DIR__, 2);
$path = FontRuntime::fontPath($packageRoot);
$guestSurface = strtolower(trim((string)($_GET['surface'] ?? ''))) === 'guest';
if (!FontRuntime::valid($path)) {
    // Guest LAN cold-open must never wait on a CDN while cafe WAN is down. The
    // Guest stylesheet has safe system-font fallbacks; installation/setup may
    // still prefetch Vazirmatn for normal Staff and later Guest requests.
    if ($guestSurface || !FontRuntime::ensure($packageRoot)) {
        http_response_code(404);
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        exit;
    }
}

$etag = '"'.hash_file('sha256', $path).'"';
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    header('ETag: '.$etag);
    header('Cache-Control: public, max-age=31536000, immutable');
    exit;
}

header('Content-Type: font/woff2');
header('Content-Length: '.(string)filesize($path));
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: '.$etag);
header('X-Content-Type-Options: nosniff');
readfile($path);
