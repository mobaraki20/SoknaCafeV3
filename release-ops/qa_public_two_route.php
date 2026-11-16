<?php
declare(strict_types=1);
/**
 * QA-only same-exact-ZIP Public Routine vs independent Emergency test.
 * Instantiates unchanged R171 PHP services in disposable temp directories.
 * NO host install, real database, browser, credentials, or production writes.
 */
use Sokna\PublicEdge\Update\RoutinePublicUpdateService;
use Sokna\PublicEdge\Update\IndependentEmergencyRoutineBridge;
use Sokna\PublicEdge\Emergency\PublicUpdateException;

function need(bool $ok, string $code): void {
    if (!$ok) { throw new RuntimeException("QA_FAIL_".$code); }
    echo "PASS ".$code."\n";
}
function removeTree(string $path): void {
    if (!is_dir($path)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
    rmdir($path);
}
function copyTree(string $a, string $b): void {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $dest = $b.'/'.substr($f->getPathname(), strlen($a)+1);
        if ($f->isDir()) { if (!is_dir($dest)) mkdir($dest, 0700, true); }
        else { if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0700, true); copy($f->getPathname(), $dest); }
    }
}
$mode = getenv("SOKNA_QA_PUBLIC_ROUTE") ?: "";
$fixture = getenv("SOKNA_QA_PUBLIC_FIXTURE") ?: "";
$package = getenv("SOKNA_QA_PUBLIC_ZIP") ?: "";
$expectedSha = strtolower(getenv("SOKNA_QA_PUBLIC_SHA") ?: "");
need(in_array($mode, ["routine", "emergency"], true), "route_declared");
need(is_dir($fixture) && is_file($package), "fixture_and_exact_package");
need((bool)preg_match('/^[a-f0-9]{64}$/', $expectedSha), "sha_declared");
need(hash_file("sha256", $package) === $expectedSha, "identical_qa_zip_sha");

$baseId = getenv("SOKNA_QA_PUBLIC_BASE") ?: "35ht";
need(in_array($baseId, ["35hk","35ht"], true), "baseline_supported");
$source = $fixture."/".($baseId === "35hk" ? "r153" : "r171");
$root = $fixture."/run-public-".$baseId."-".$mode;
$storage = $fixture."/run-storage-".$baseId."-".$mode;
need(is_dir($source) && is_file($source."/VERSION.txt"), "exact_historical_baseline");
require_once $fixture."/public-update-exception.php";
require_once $fixture."/r171/src/Update/RoutinePublicUpdateService.php";
require_once $fixture."/r171/src/Update/IndependentEmergencyRoutineBridge.php";

$zip = new ZipArchive();
need($zip->open($package) === true, "zip_open");
$m = json_decode((string)$zip->getFromName("manifest.json"), true, 512, JSON_THROW_ON_ERROR);
$zip->close();
need(($m["provenance"]["strict_multibaseline_v1"] ?? null) === true, "strict_multi_baseline_protocol");
need(($m["provenance"]["production_host_qualified"] ?? null) === false, "no_host_claim");
need(str_contains((string)($m["version"] ?? ""), "do-not-install"), "qa_do_not_install_stamp");
need(count($m["compatibility"]["supported_baselines"] ?? []) === 2, "two_exact_historical_baselines_declared");
$startVersion = trim((string)file_get_contents($source."/VERSION.txt"));
$approved = array_values(array_filter($m["compatibility"]["supported_baselines"],
    static fn($x) => ($x["version"] ?? "") === $startVersion));
need(count($approved) === 1, "exact_baseline_registered_once");
$baseTree = $approved[0]["managed_tree_sha256"];
removeTree($root); removeTree($storage);
mkdir($root, 0700, true); mkdir($storage, 0700, true);
copyTree($source, $root);
if (!is_dir($root."/src/Emergency")) mkdir($root."/src/Emergency", 0700, true);
copy($fixture."/public-update-exception.php", $root."/src/Emergency/PublicUpdateException.php");
file_put_contents($root."/emergency.php", "<?php // stable CI fixture only\n");
file_put_contents($root."/config.php", "<?php return ['app'=>['base_path'=>'/public']];\n");
file_put_contents($root."/resources/update-trust-v1.json", "{}");
if (!is_dir($root."/storage")) mkdir($root."/storage", 0700, true);
$protected = [
    "emergency.php" => hash_file("sha256", $root."/emergency.php"),
    "config.php" => hash_file("sha256", $root."/config.php"),
    "resources/update-trust-v1.json" => hash_file("sha256", $root."/resources/update-trust-v1.json"),
];
if (is_file($root."/recovery.php")) $protected["recovery.php"] = hash_file("sha256", $root."/recovery.php");
if ($mode === "emergency") {
    mkdir($storage."/routine-update-v2", 0700, true);
    $state = [
        "format"=>"sokna-public-routine-update-state-v2",
        "schema_version"=>2,
        "active_version"=>$startVersion,
        "active_managed_tree_sha256"=>$baseTree,
        "previous_version"=>"", "lkg_recovery_id"=>"",
        "staged"=>null, "pending_verification"=>null,
        "activation_journal"=>null, "db_forward_fence"=>null, "history"=>[],
    ];
    file_put_contents($storage."/routine-update-v2/state.json", json_encode($state, JSON_THROW_ON_ERROR));
}
$service = new RoutinePublicUpdateService($root, $storage,
    $root."/resources/update-trust-v1.json",
    static fn():bool => true, static fn():array => [], null, static fn():array => []);
$bridge = new IndependentEmergencyRoutineBridge($service, $storage,
    static fn():array => [],
    static function() use ($root): array {
        return ["ok"=>true,"database"=>"ready","emergency_ready"=>true,
                "pending_migrations"=>0,"version"=>trim((string)file_get_contents($root."/VERSION.txt"))];
    });
need(($service->snapshot()["managed_tree_sha256"] ?? "") === $baseTree, "real_php_installed_tree");
$tx = "qa-r233-".$mode;
if ($mode === "emergency") {
    $stage = $bridge->stageUploadedZip($package, $tx);
    need(($stage["strict_multibaseline_v1"] ?? null) === true, "emergency_strict_stage");
    need(trim(file_get_contents($root."/VERSION.txt")) === $startVersion, "emergency_stage_isolated");
    $act = $bridge->activateStaged($tx);
    need(is_array($act["pending_verification"] ?? null), "emergency_pending_readback");
    $end = $bridge->commitPending($tx);
} else {
    $stage = $service->stageUploadedDeltaZip($package, $tx);
    need(($stage["version"] ?? null) === $m["version"], "routine_stage_same_zip");
    need(trim(file_get_contents($root."/VERSION.txt")) === $startVersion, "routine_stage_isolated");
    $act = $service->activateStaged($tx);
    need(($act["version"] ?? $m["version"]) === $m["version"], "routine_activated_target");
    $end = $service->commitActivation($tx);
}
need(trim(file_get_contents($root."/VERSION.txt")) === $m["version"], "target_version_exact");
need(($service->snapshot()["managed_tree_sha256"] ?? "") === $m["target_managed_tree_sha256"], "target_managed_tree_exact");
foreach ($protected as $path => $digest) {
    need(hash_file("sha256", $root."/".$path) === $digest, "protected_".str_replace("/", "_", $path));
}
need((require $root."/config.php")["app"]["base_path"] === "/public", "subpath_preserved");
need(count(glob($root."/database/migrations/*.sql")) === 8, "eight_migrations_retained");
$owner = json_decode((string)file_get_contents($root."/src/Update/emergency-owner-v1.json"), true, 512, JSON_THROW_ON_ERROR);
need(($owner["release_version"] ?? null) === $m["version"], "owner_version_matches");
need(($owner["sha256"] ?? null) === hash_file("sha256", $root."/src/Update/EmergencyOwnerCandidate.php"), "owner_code_sha_matches");
need(($end["active_version"] ?? $service->snapshot()["active_version"] ?? "") === $m["version"], "commit_confirmed");
echo "SOKNA_QA_PUBLIC_".strtoupper($baseId)."_".strtoupper($mode)."_ONE_EXACT_ZIP_NATIVE_PHP_PASS\n";
