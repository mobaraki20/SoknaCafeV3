package main

import (
	"encoding/json"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
)

// Exercise the actual embedded-PHP bridge, including its Go result parser.
// The caller supplies only a fresh disposable MariaDB process, never a user DB.
func TestDatabaseForwardMariaDB(t *testing.T) {
	php, port := os.Getenv("SOKNA_U01_TEST_PHP"), os.Getenv("SOKNA_U01_TEST_DB_PORT")
	if php == "" || port == "" {
		t.Skip("disposable MariaDB integration not requested")
	}
	t.Setenv("ProgramData", t.TempDir())
	b, err := newBridge()
	if err != nil {
		t.Fatal(err)
	}
	b.packageRoot = t.TempDir()
	b.targetSlot = filepath.Join(b.packageRoot, "target")
	b.phpPath = php
	if err = extractZipExact(b.targetZip, b.targetSlot, b.target); err != nil {
		t.Fatal(err)
	}
	base := filepath.Join(b.packageRoot, "baseline-migrations")
	if err = os.MkdirAll(base, 0700); err != nil {
		t.Fatal(err)
	}
	for _, m := range b.migrations.Files {
		if err = copyFile(filepath.Join(b.targetSlot, "database", "migrations", m.File), filepath.Join(base, m.File)); err != nil {
			t.Fatal(err)
		}
	}
	init := `<?php
[$u,$root,$web,$base,$port]=$argv;
foreach(['Config','Database','MigrationExecutionException','Migrations'] as $c)require_once $web.'/src/Core/'.$c.'.php';
$s=new PDO('mysql:host=127.0.0.1;port='.$port,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$s->exec('DROP DATABASE IF EXISTS sokna_u01_home_ci;CREATE DATABASE sokna_u01_home_ci CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$cfg=['app'=>['timezone'=>'Asia/Tehran','data_dir'=>$root.'/data'],'db'=>['host'=>'127.0.0.1','port'=>$port,'name'=>'sokna_u01_home_ci','charset'=>'utf8mb4','user'=>'root','pass'=>'']];
file_put_contents($root.'/config.php',"<?php return ".var_export($cfg,true).';');
$pdo=\Sokna\Local\Core\Database::connect(\Sokna\Local\Core\Config::fromArray($cfg));
// Reconstruct an EXISTING seeded installation, not a new clean install:
// historical 0059 deliberately requires the cafe catalog to exist already.
$pre=$root.'/fixture-before-0059';@mkdir($pre,0700,true);
foreach(glob($base.'/*.sql') as $f)if(strcmp(basename($f),'0059_')<0)copy($f,$pre.'/'.basename($f));
(new \Sokna\Local\Core\Migrations($pdo,$pre))->migrate();
$doc=json_decode(file_get_contents($web.'/resources/default-content/v1/catalog.json'),true,512,JSON_THROW_ON_ERROR);
foreach($doc['categories'] as $c){$pdo->prepare('INSERT IGNORE INTO categories(category_key,name,audience) VALUES(?,?,?)')->execute([$c['category_key'],$c['name'],$c['audience']??'guest_staff']);}
foreach($doc['items'] as $i){$q=$pdo->prepare('SELECT id FROM categories WHERE category_key=?');$q->execute([$i['category_key']]);$category=$q->fetchColumn();if(!$category)throw new RuntimeException('FIXTURE_CATEGORY_MISSING');$pdo->prepare('INSERT IGNORE INTO items(item_code,category_id,name,price) VALUES(?,?,?,?)')->execute([$i['item_code'],$category,$i['name'],$i['price']]);}
// Historical existing-host SKUs referenced by immutable 0059, absent from the current clean catalog.
foreach(['1019','1113'] as $code)$pdo->prepare('INSERT IGNORE INTO items(item_code,category_id,name,price) VALUES(?,?,?,?)')->execute([$code,$category,'Existing-host fixture '.$code,1000]);

(new \Sokna\Local\Core\Migrations($pdo,$base))->migrate();
$pdo->exec("CREATE TABLE u01_sentinel(id INT PRIMARY KEY,value VARCHAR(80)) ENGINE=InnoDB;INSERT INTO u01_sentinel VALUES(1,'home-data-preserved')");
echo 'INITIALIZED';`
	path := filepath.Join(b.packageRoot, "init.php")
	if err = os.WriteFile(path, []byte(init), 0600); err != nil {
		t.Fatal(err)
	}
	if out, e := runHidden(php, path, b.packageRoot, b.targetSlot, base, port); e != nil {
		t.Fatalf("fixture: %v %s", e, out)
	}
	if n, e := b.phpMigrationTransitionPreflight(b.targetSlot); e != nil || n != migrationCount {
		t.Fatalf("preflight: %d %v", n, e)
	}
	// The dump must actually exist, restore, and retain the baseline sentinel.
	// This catches a Go err-shadowing regression that returned nil before dump.
	dump := os.Getenv("SOKNA_HOME_TEST_DUMP")
	client := os.Getenv("SOKNA_HOME_TEST_DB_CLIENT")
	if dump == "" || client == "" {
		t.Fatal("real dump/restore clients required for requested DB qualification")
	}
	installedDump := filepath.Join(b.packageRoot, "Infrastructure", "MariaDB", "bin", "mariadb-dump.exe")
	if err = copyFile(dump, installedDump); err != nil {
		t.Fatal(err)
	}
	os.Chmod(installedDump, 0700)
	if err = b.preflightMariaDBBackupConfig(); err != nil {
		t.Fatal(err)
	}
	if err = b.backupMariaDB(); err != nil {
		t.Fatal(err)
	}
	if err = b.verifyBaselineBackupBeforeBoundary(); err != nil {
		t.Fatal(err)
	}
	firstPath, firstHash := b.ev.DatabaseBackupPath, b.ev.DatabaseBackupSHA256
	if err = b.backupMariaDB(); err != nil {
		t.Fatal(err)
	}
	if firstPath == b.ev.DatabaseBackupPath {
		t.Fatal("second backup overwrote baseline evidence")
	}
	if hash, _, e := shaFile(firstPath); e != nil || hash != firstHash {
		t.Fatal("first backup changed")
	}
	backup, err := os.ReadFile(b.ev.DatabaseBackupPath)
	if err != nil {
		t.Fatal(err)
	}
	restore := exec.Command(client, "--host=127.0.0.1", "--port="+port, "--user=root")
	restore.Stdin = strings.NewReader(strings.ReplaceAll(string(backup), "sokna_u01_home_ci", "sokna_home_restore_ci"))
	if out, e := restore.CombinedOutput(); e != nil {
		t.Fatalf("real SQL restore: %v %s", e, out)
	}
	checkRestore := exec.Command(client, "--host=127.0.0.1", "--port="+port, "--user=root", "--batch", "--skip-column-names", "-e", "SELECT COUNT(*) FROM sokna_home_restore_ci.schema_migrations; SELECT value FROM sokna_home_restore_ci.u01_sentinel WHERE id=1;")
	if out, e := checkRestore.CombinedOutput(); e != nil || strings.TrimSpace(string(out)) != "71\nhome-data-preserved" {
		t.Fatalf("restored baseline not proven: %v %s", e, out)
	}
	if err = os.WriteFile(b.ev.DatabaseBackupPath, append(backup, []byte("tamper")...), 0600); err != nil {
		t.Fatal(err)
	}
	if err = b.verifyBaselineBackupBeforeBoundary(); err == nil {
		t.Fatal("tampered SQL backup admitted")
	}
	if err = os.WriteFile(b.ev.DatabaseBackupPath, backup, 0600); err != nil {
		t.Fatal(err)
	}
	if err = b.phpApplyTargetMigrations(b.targetSlot); err != nil {
		t.Fatal(err)
	}
	proof, err := b.phpTargetSchemaAttestation()
	if err != nil {
		t.Fatal(err)
	}
	if proof.Count != targetMigrationCount || proof.Head != "0074_iran_time_notification_boundaries" {
		t.Fatalf("bad proof %+v", proof)
	}
	// Exercise the REAL first-bind writer, not a separately seeded identity.
	b.lifecycleRoot = filepath.Join(b.packageRoot, "data", "lifecycle", "local")
	if err = os.MkdirAll(b.lifecycleRoot, 0700); err != nil {
		t.Fatal(err)
	}
	b.lifecycleStatePath = filepath.Join(b.lifecycleRoot, "state.json")
	b.lifecycleManagedPath = filepath.Join(b.lifecycleRoot, "managed-files.json")
	raw, _ := json.Marshal(map[string]any{"format": "sokna-local-update-state-v1", "schema_version": 1, "active_version": baselineVersion, "staged": nil, "activation_journal": nil, "recovery_mode": nil})
	if err = os.WriteFile(b.lifecycleStatePath, raw, 0600); err != nil {
		t.Fatal(err)
	}
	if err = b.reconcileLocalLifecycle(); err != nil {
		t.Fatal(err)
	}
	b.dbBoundary = true
	b.ev.MigrationsApplied = proof.Count
	b.maintenanceSupervisorVersion = "0.3.13"
	b.maintenanceEngineVersion = "0.3.13"
	if err = os.MkdirAll(filepath.Dir(b.installedIdentityPath()), 0700); err != nil {
		t.Fatal(err)
	}
	if err = b.attestAndRegisterU01Identity(); err != nil {
		t.Fatal(err)
	}
	if export := os.Getenv("SOKNA_HOME_EXPORT_IDENTITY"); export != "" {
		if err = copyFile(b.installedIdentityPath(), export); err != nil {
			t.Fatal(err)
		}
	}
	// A repeated one-off must reject the changed baseline before reapplying.
	if _, err = b.phpMigrationTransitionPreflight(b.targetSlot); err == nil {
		t.Fatal("already upgraded DB admitted as old baseline")
	}
	check := `<?php $cfg=require $argv[1].'/config.php';$db=$cfg['db'];$p=new PDO('mysql:host=127.0.0.1;port='.$db['port'].';dbname='.$db['name'],'root','');echo json_encode(['value'=>$p->query('SELECT value FROM u01_sentinel WHERE id=1')->fetchColumn(),'pending'=>(int)$p->query("SELECT COUNT(*) FROM schema_migration_statements WHERE state<>'applied'")->fetchColumn()]);`
	if err = os.WriteFile(path, []byte(check), 0600); err != nil {
		t.Fatal(err)
	}
	out, err := runHidden(php, path, b.packageRoot)
	if err != nil {
		t.Fatal(err)
	}
	var got struct {
		Value   string `json:"value"`
		Pending int    `json:"pending"`
	}
	if err = json.Unmarshal([]byte(out), &got); err != nil {
		t.Fatal(err)
	}
	if got.Value != "home-data-preserved" || got.Pending != 0 {
		t.Fatalf("data/journal mismatch %+v", got)
	}
}
