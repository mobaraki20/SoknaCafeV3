<?php
declare(strict_types=1);

use Sokna\PublicEdge\Core\Bootstrap;

require_once __DIR__ . '/src/Core/Config.php';
require_once __DIR__ . '/src/Setup/SetupException.php';
require_once __DIR__ . '/src/Setup/PublicSetupService.php';
require_once __DIR__ . '/src/Core/Database.php';
require_once __DIR__ . '/src/Core/Migrations.php';
require_once __DIR__ . '/src/Core/SafeErrors.php';
require_once __DIR__ . '/src/Core/CanonicalJson.php';
require_once __DIR__ . '/src/Auth/AuthProjectionService.php';
require_once __DIR__ . '/src/Auth/AuthThrottle.php';
require_once __DIR__ . '/src/Auth/AuthSecurityAudit.php';
require_once __DIR__ . '/src/Auth/PublicSessionStore.php';
require_once __DIR__ . '/src/Auth/PublicLoginService.php';
require_once __DIR__ . '/src/Security/SignedLocalRequestVerifier.php';
require_once __DIR__ . '/src/Emergency/PublicUpdateException.php';
require_once __DIR__ . '/src/Emergency/EmergencyAccessService.php';
require_once __DIR__ . '/src/Emergency/PairingSecretStore.php';
require_once __DIR__ . '/src/Emergency/PublicInitialPairingService.php';
require_once __DIR__ . '/src/Emergency/PublicTakeoverService.php';
require_once __DIR__ . '/src/Emergency/PublicUpdateService.php';
require_once __DIR__ . '/src/Connectivity/ConnectivityService.php';
require_once __DIR__ . '/src/Realtime/RealtimeService.php';
require_once __DIR__ . '/src/Deferred/DeferredService.php';
require_once __DIR__ . '/src/Health/PublicHealthService.php';
require_once __DIR__ . '/src/Guest/GuestMediaStore.php';
require_once __DIR__ . '/src/Guest/GuestPublishService.php';
require_once __DIR__ . '/src/Guest/GuestAvailabilityService.php';
require_once __DIR__ . '/src/Guest/GuestRuntimeService.php';
require_once __DIR__ . '/src/Guest/GuestThemeCssService.php';
require_once __DIR__ . '/src/Guest/GuestCompatibilityService.php';
require_once __DIR__ . '/src/Guest/GuestPageRenderer.php';
require_once __DIR__ . '/src/Remote/RemoteReadModelService.php';
require_once __DIR__ . '/src/Remote/InstallationProjectionService.php';
require_once __DIR__ . '/src/Remote/RemoteStaffPageRenderer.php';
require_once __DIR__ . '/src/Http/RemoteReadModelHttpAdapter.php';
require_once __DIR__ . '/src/Http/AuthHttpAdapter.php';
require_once __DIR__ . '/src/Http/ConnectivityHttpAdapter.php';
require_once __DIR__ . '/src/Http/RealtimeHttpAdapter.php';
require_once __DIR__ . '/src/Http/DeferredHttpAdapter.php';
require_once __DIR__ . '/src/Http/GuestSyncHttpAdapter.php';
require_once __DIR__ . '/src/Http/InstallationProjectionHttpAdapter.php';
require_once __DIR__ . '/src/Http/HealthHttpAdapter.php';
require_once __DIR__ . '/src/Http/GuestCompatibilityHttpAdapter.php';
require_once __DIR__ . '/src/Http/EmergencyLocalHttpAdapter.php';
require_once __DIR__ . '/src/Http/PublicHttpKernel.php';
require_once __DIR__ . '/src/Core/Bootstrap.php';

function sokna_public_bootstrap(array $config): Bootstrap
{
    return Bootstrap::fromArray($config);
}
