package main

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"
)

// Non-mutating lineage check. The PHP script may read the installed DB but
// never calls the migration executor or changes any DB table.
func (b *bridge) phpMigrationTransitionPreflight(webRoot string) (int, error) {
	names := make([]string, 0, len(b.migrations.Files))
	for _, x := range b.migrations.Files {
		names = append(names, strings.TrimSuffix(x.File, ".sql"))
	}
	added := make([]string, 0, len(b.migrations.Added))
	for _, x := range b.migrations.Added {
		added = append(added, strings.TrimSuffix(x.File, ".sql"))
	}
	proof := map[string]any{"old": names, "new": added}
	raw, _ := json.Marshal(proof)
	params := filepath.Join(b.evidenceDir, "migration-lineage.json")
	if err := os.WriteFile(params, raw, 0600); err != nil {
		return 0, err
	}
	script := `<?php
 if($argc!==4)exit(64);$root=$argv[1];$web=$argv[2];$proof=json_decode(file_get_contents($argv[3]),true,512,JSON_THROW_ON_ERROR);
 $cfg=require $root.'/config.php';if(!is_array($cfg))exit(65);
 require_once $web.'/src/Core/Config.php';require_once $web.'/src/Core/Database.php';require_once $web.'/src/Core/MigrationExecutionException.php';require_once $web.'/src/Core/Migrations.php';
 $pdo=\Sokna\Local\Core\Database::connect(\Sokna\Local\Core\Config::fromArray($cfg));
 $m=new \Sokna\Local\Core\Migrations($pdo,$web.'/database/migrations');
 $old=$proof['old'];$added=$proof['new'];$catalog=array_keys($m->catalog());$applied=$m->appliedVersions();
 sort($old,SORT_STRING);sort($added,SORT_STRING);sort($catalog,SORT_STRING);sort($applied,SORT_STRING);
 $all=array_merge($old,$added);sort($all,SORT_STRING);
 $ok=count($old)===71&&count($added)===4&&count($catalog)===75&&$catalog===$all&&$applied===$old;
 echo json_encode(['ok'=>$ok,'applied'=>count($applied),'catalog'=>count($catalog),'pending'=>array_values(array_diff($catalog,$applied))],JSON_UNESCAPED_SLASHES),"\n";
 exit($ok?0:67);
 ?>`
	path := filepath.Join(b.evidenceDir, "migration-lineage-preflight.php")
	if err := os.WriteFile(path, []byte(script), 0600); err != nil {
		return 0, err
	}
	out, err := runHidden(b.phpPath, path, b.packageRoot, webRoot, params)
	if err != nil {
		return 0, fmt.Errorf("expected 71 applied, 75 catalog and four exact pending migrations: %v; %s", err, strings.TrimSpace(out))
	}
	var parsed struct {
		OK      bool     `json:"ok"`
		Applied int      `json:"applied"`
		Catalog int      `json:"catalog"`
		Pending []string `json:"pending"`
	}
	if er := json.Unmarshal([]byte(strings.TrimSpace(out)), &parsed); er != nil {
		return 0, er
	}
	if !parsed.OK || parsed.Applied != migrationCount || parsed.Catalog != targetMigrationCount || len(parsed.Pending) != targetMigrationCount-migrationCount {
		return 0, errors.New("database migration lineage rejected")
	}
	return parsed.Applied, nil
}

// This occurs ONLY after verified SQL backup and a durable DB_FORWARD_BOUNDARY
// journal. Never call on unverified hosts or before quiescing Apache.
func (b *bridge) phpApplyTargetMigrations(webRoot string) error {
	script := `<?php
 if($argc!==3)exit(64);$root=$argv[1];$web=$argv[2];$cfg=require $root.'/config.php';if(!is_array($cfg))exit(65);
 require_once $web.'/src/Core/Config.php';require_once $web.'/src/Core/Database.php';require_once $web.'/src/Core/MigrationExecutionException.php';require_once $web.'/src/Core/Migrations.php';
 $pdo=\Sokna\Local\Core\Database::connect(\Sokna\Local\Core\Config::fromArray($cfg));
 $m=new \Sokna\Local\Core\Migrations($pdo,$web.'/database/migrations');
 try {
   $before=$m->appliedVersions();if(count($before)!==71)throw new \RuntimeException('UNEXPECTED_MIGRATION_BASELINE');
   $new=$m->migrate();$catalog=array_keys($m->catalog());$after=$m->appliedVersions();
   sort($catalog,SORT_STRING);sort($after,SORT_STRING);
   if($catalog!==$after||count($catalog)!==75||count($new)!==4)throw new \RuntimeException('U01_MIGRATION_LEDGER_INCOMPLETE');
   echo json_encode(['ok'=>true,'applied'=>count($after),'new'=>count($new)],JSON_UNESCAPED_SLASHES),"\n";
 } catch(\Throwable $e){fwrite(STDERR,'U01_FORWARD_MIGRATION_NOT_COMPLETE:'.get_class($e).':'.substr(preg_replace('/[\r\n]+/',' ',(string)$e->getMessage()),0,240)."\n");exit(79);}
 ?>`
	path := filepath.Join(b.evidenceDir, "db-forward-target.php")
	if err := os.WriteFile(path, []byte(script), 0600); err != nil {
		return err
	}
	out, err := runHidden(b.phpPath, path, b.packageRoot, webRoot)
	if err != nil {
		return fmt.Errorf("PHP forward migrations unverified: %v / %s", err, strings.TrimSpace(out))
	}
	var p struct {
		OK      bool `json:"ok"`
		Applied int  `json:"applied"`
		New     int  `json:"new"`
	}
	if err := json.Unmarshal([]byte(strings.TrimSpace(out)), &p); err != nil {
		return err
	}
	if !p.OK || p.Applied != targetMigrationCount || p.New != targetMigrationCount-migrationCount {
		return errors.New("DB_FORWARD_LEDGER_MISMATCH")
	}
	return nil
}

// BrowserSetupService::normalizeDb persists db.port as a PHP *string*.
// Keep Go's port as a validated integer, but cast at the PHP JSON boundary.
// Read credentials only into transient process memory. Never include them in
// the diagnostic reports, command arguments, or logs.
const backupDBConfigReaderPHP = `<?php
 if($argc!==2)exit(64);$cfg=require $argv[1].'/config.php';
 if(!is_array($cfg)||!isset($cfg['db'])||!is_array($cfg['db']))exit(65);
 $db=$cfg['db'];echo json_encode(['user'=>$db['user']??'','pass'=>$db['pass']??'','host'=>$db['host']??'',
   'port'=>(int)($db['port']??3306),'name'=>$db['name']??''],JSON_UNESCAPED_SLASHES),"\n";
 ?>`

type backupDBCredentials struct {
	User string `json:"user"`
	Pass string `json:"pass"`
	Host string `json:"host"`
	Port int    `json:"port"`
	Name string `json:"name"`
}

func parseBackupDBConfig(raw string) (backupDBCredentials, error) {
	var db backupDBCredentials
	if err := json.Unmarshal([]byte(strings.TrimSpace(raw)), &db); err != nil {
		return db, errors.New("DB_BACKUP_CONFIG_INVALID")
	}
	if db.User == "" || db.Name == "" || !regexp.MustCompile(`^[A-Za-z0-9_]+$`).MatchString(db.Name) ||
		!regexp.MustCompile(`^[A-Za-z0-9_@.%-]+$`).MatchString(db.User) ||
		(db.Host != "localhost" && db.Host != "127.0.0.1") || db.Port < 1 || db.Port > 65535 {
		return backupDBCredentials{}, errors.New("DB_BACKUP_CONFIG_UNSAFE")
	}
	return db, nil
}

func (b *bridge) readBackupDBConfig() (backupDBCredentials, error) {
	path := filepath.Join(b.evidenceDir, "dump-config-read.php")
	if err := os.WriteFile(path, []byte(backupDBConfigReaderPHP), 0600); err != nil {
		return backupDBCredentials{}, err
	}
	defer os.Remove(path)
	out, err := runHidden(b.phpPath, path, b.packageRoot)
	if err != nil {
		return backupDBCredentials{}, errors.New("DB_BACKUP_CREDENTIAL_DISCOVERY_FAILED")
	}
	return parseBackupDBConfig(out)
}

// Non-mutating guard. Detect credential schema/port type mismatches *before*
// stopping Apache or performing any Local code, schema or service mutation.
func (b *bridge) preflightMariaDBBackupConfig() error {
	if _, err := b.readBackupDBConfig(); err != nil {
		return err
	}
	// An absent SQL dump client must never be discovered only after Apache stops.
	_, err := b.findMariaDBDumpClient()
	return err
}

func (b *bridge) findMariaDBDumpClient() (string, error) {
	root := filepath.Join(b.packageRoot, "Infrastructure")
	if path, err := findFile(root, "mariadb-dump.exe", 8); err == nil {
		return path, nil
	}
	if path, err := findFile(root, "mysqldump.exe", 8); err == nil {
		return path, nil
	}
	return "", errors.New("DB_BACKUP_CLIENT_NOT_INSTALLED; refuse DB mutation")
}

// Dump to a PRIVATE host-local folder before switching the live junction.
// Never include DB secrets or dump bytes in transport artifacts/reports.
func (b *bridge) backupMariaDB() error {
	db, err := b.readBackupDBConfig()
	if err != nil {
		return err
	}
	dump, err := b.findMariaDBDumpClient()
	if err != nil {
		return err
	}
	dir := filepath.Join(b.evidenceDir, "business-sql-backup")
	if err := os.MkdirAll(dir, 0700); err != nil {
		return err
	}
	file := filepath.Join(dir, "before-u01-"+time.Now().UTC().Format("20060102T150405.000000000")+".sql")
	_, statErr := os.Stat(file)
	if statErr == nil {
		return errors.New("DB_BACKUP_EXISTS; refuse overwrite")
	}
	if !os.IsNotExist(statErr) {
		return statErr
	}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Minute)
	defer cancel()
	cmd := exec.CommandContext(ctx, dump, "--single-transaction", "--quick", "--routines", "--triggers", "--events", "--hex-blob", "--add-drop-table", "--default-character-set=utf8mb4", "--host="+db.Host, "--port="+strconv.Itoa(db.Port), "--user="+db.User, "--databases", db.Name, "--result-file="+file)
	hideCommand(cmd)
	cmd.Env = append(os.Environ(), "MYSQL_PWD="+db.Pass)
	// Do not log CLI output, parameters or child environment: database secrets.
	_, err = cmd.CombinedOutput()
	if ctx.Err() != nil {
		return errors.New("DB_BACKUP_TIMEOUT")
	}
	if err != nil {
		return errors.New("DB_BACKUP_DUMP_FAILED; no database mutation")
	}
	info, err := os.Stat(file)
	if err != nil {
		return err
	}
	if info.Size() < 4096 {
		return errors.New("DB_BACKUP_TOO_SMALL; reject")
	}
	digest, _, err := shaFile(file)
	if err != nil {
		return err
	}
	// Check a normal mysqldump header/footer, not only a zero-byte/nonzero test.
	contents, err := os.Open(file)
	if err != nil {
		return err
	}
	defer contents.Close()
	header := make([]byte, 8192)
	n, _ := contents.Read(header)
	if !strings.Contains(strings.ToLower(string(header[:n])), "mariadb") && !strings.Contains(strings.ToLower(string(header[:n])), "mysql") {
		return errors.New("DB_BACKUP_FORMAT_UNVERIFIED")
	}
	if !strings.Contains(string(header[:n]), "CREATE DATABASE") && !strings.Contains(string(header[:n]), "Current Database") {
		return errors.New("DB_BACKUP_SCHEMA_NOT_PRESENT")
	}
	if _, err = contents.Seek(max(0, info.Size()-4096), 0); err != nil {
		return err
	}
	tail, err := io.ReadAll(contents)
	if err != nil || !strings.Contains(string(tail), "-- Dump completed on") {
		return errors.New("DB_BACKUP_INCOMPLETE")
	}
	b.ev.DatabaseBackupPath = file
	b.ev.DatabaseBackupSHA256 = digest
	b.writeEvidence()
	return nil
}

func (b *bridge) failAfterDbBoundary(cause error) error {
	b.ev.DatabaseBoundaryReached = true
	b.ev.DatabaseMutation = true
	b.ev.Phase = "DB_FORWARD_RECOVERY_REQUIRED"
	b.writeEvidence()
	if b.transaction != nil {
		_ = b.checkpointTransaction("DB_FORWARD_RECOVERY_REQUIRED")
	}
	return fmt.Errorf("DB_FORWARD_RECOVERY_REQUIRED; code rollback forbidden; backup retained at %s: %w", b.ev.DatabaseBackupPath, cause)
}

// Read a live Supervisor-published descriptor, not a guessed port or a key.
// It proves a matching 0.3.13 foundation is present before the last EXE ever
// modifies Apache, Local files or MariaDB. HMAC round-trip still requires UAT.
func (b *bridge) preflightSupervisorDescriptor() error {
	pd := os.Getenv("ProgramData")
	if pd == "" {
		pd = `C:\ProgramData`
	}
	path := filepath.Join(pd, "SOKNA", "Maintenance", "control-plane.json")
	data, err := os.ReadFile(path)
	if err != nil {
		return errors.New("MAINTENANCE_0313_DESCRIPTOR_MISSING: update Windows Services independently before Local")
	}
	var v struct {
		Format            string `json:"format"`
		SchemaVersion     int    `json:"schema_version"`
		Protocol          string `json:"protocol"`
		BaseURL           string `json:"base_url"`
		SupervisorVersion string `json:"supervisor_version"`
		Readiness         string `json:"readiness"`
		AuthScheme        string `json:"auth_scheme"`
	}
	if err := json.Unmarshal(data, &v); err != nil {
		return errors.New("MAINTENANCE_DESCRIPTOR_INVALID")
	}
	if v.Format != "sokna-maintenance-control-plane-v1" || v.SchemaVersion != 1 || v.Protocol != "1.0" || v.SupervisorVersion != "0.3.13" || v.Readiness != "ready" || v.AuthScheme != "sokna-hmac-sha256-v1" || !strings.HasPrefix(v.BaseURL, "http://127.0.0.1:") {
		return fmt.Errorf("MAINTENANCE_0313_REQUIRED: got descriptor protocol=%q supervisor_version=%q ready=%q", v.Protocol, v.SupervisorVersion, v.Readiness)
	}
	u, err := url.Parse(v.BaseURL)
	if err != nil || u.Scheme != "http" || u.Hostname() != "127.0.0.1" || u.Port() == "" || u.Path != "/" {
		return errors.New("MAINTENANCE_DESCRIPTOR_LOOPBACK_INVALID")
	}
	client := &http.Client{Timeout: 4 * time.Second, CheckRedirect: func(req *http.Request, via []*http.Request) error { return http.ErrUseLastResponse }}
	resp, err := client.Get(v.BaseURL + "v1/health")
	if err != nil {
		return errors.New("MAINTENANCE_0313_HEALTH_UNREACHABLE")
	}
	resp.Body.Close()
	if resp.StatusCode != 401 {
		return fmt.Errorf("MAINTENANCE_0313_UNSIGNED_HEALTH_UNEXPECTED_STATUS_%d", resp.StatusCode)
	}
	b.maintenanceSupervisorVersion = v.SupervisorVersion
	b.addStep("verified installed Maintenance Supervisor 0.3.13 Descriptor and live HMAC health gate (401 for unsigned probe)")
	return nil
}

// Verify the backed-up bytes did not change after Apache stopped.
// Its creation was deliberately moved ahead of any downtime.
func (b *bridge) verifyBaselineBackupBeforeBoundary() error {
	if b.ev.DatabaseBackupPath == "" || b.ev.DatabaseBackupSHA256 == "" {
		return errors.New("DB_BACKUP_MISSING")
	}
	actual, _, err := shaFile(b.ev.DatabaseBackupPath)
	if err != nil {
		return err
	}
	if !strings.EqualFold(actual, b.ev.DatabaseBackupSHA256) {
		return errors.New("DB_BACKUP_INTEGRITY_CHANGED")
	}
	return nil
}
