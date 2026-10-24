package main

import (
	"archive/zip"
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"sort"
	"strings"
	"testing"
)

func TestExact231auToU01Payload(t *testing.T) {
	bs, e := embedded.ReadFile("payload/baseline-manifest.json")
	if e != nil {
		t.Fatal(e)
	}
	baseline, e := loadManifest(bs, "baseline", baselineVersion, 510, baselineTree)
	if e != nil {
		t.Fatal(e)
	}
	if !strings.Contains(baseline.Version, "231au-") {
		t.Fatal("expected Cafe 231au")
	}
	ts, e := embedded.ReadFile("payload/target-manifest.json")
	if e != nil {
		t.Fatal(e)
	}
	target, e := loadManifest(ts, "target", targetVersion, targetFiles, targetTree)
	if e != nil {
		t.Fatal(e)
	}
	if !strings.Contains(target.Version, "231bk-") {
		t.Fatal("U01 target not uniquely identified")
	}
	data, e := embedded.ReadFile("payload/local-m06-target.zip")
	if e != nil {
		t.Fatal(e)
	}
	h := sha256.Sum256(data)
	if hex.EncodeToString(h[:]) != targetZipSHA {
		t.Fatal("ZIP SHA changed")
	}
	z, e := zip.NewReader(bytes.NewReader(data), int64(len(data)))
	if e != nil {
		t.Fatal(e)
	}
	if len(z.File) != targetFiles {
		t.Fatalf("target ZIP files=%d", len(z.File))
	}
	expected := map[string]fileEntry{}
	for _, it := range target.Files {
		expected[it.Path] = it
	}
	for _, file := range z.File {
		exp, ok := expected[file.Name]
		if !ok {
			t.Fatalf("unexpected file %s", file.Name)
		}
		r, e := file.Open()
		if e != nil {
			t.Fatal(e)
		}
		buf, e := io.ReadAll(r)
		r.Close()
		if e != nil {
			t.Fatal(e)
		}
		sum := sha256.Sum256(buf)
		if exp.SHA256 != hex.EncodeToString(sum[:]) || exp.Size != int64(len(buf)) {
			t.Fatalf("target changed %s", file.Name)
		}
	}
}
func TestU01MigrationLineage(t *testing.T) {
	data, e := embedded.ReadFile("payload/migration-catalog.json")
	if e != nil {
		t.Fatal(e)
	}
	var m migrationManifest
	if e = json.Unmarshal(data, &m); e != nil {
		t.Fatal(e)
	}
	if m.Count != 71 || m.TargetCount != targetMigrationCount || m.Unchanged || len(m.Files) != 71 || len(m.Added) != 4 {
		t.Fatal("wrong DB forward plan")
	}
	observed := []string{}
	for _, f := range m.Added {
		observed = append(observed, f.File)
	}
	sort.Strings(observed)
	for i, lead := range []string{"0071_", "0072_", "0073_", "0074_"} {
		if !strings.HasPrefix(observed[i], lead) {
			t.Fatalf("unexpected added migration %q", observed[i])
		}
	}
	ts, _ := embedded.ReadFile("payload/target-manifest.json")
	bs, _ := embedded.ReadFile("payload/baseline-manifest.json")
	var target, base fileManifest
	_ = json.Unmarshal(ts, &target)
	_ = json.Unmarshal(bs, &base)
	old := map[string]string{}
	for _, f := range base.Files {
		old[f.Path] = f.SHA256
	}
	for _, f := range target.Files {
		if strings.HasPrefix(f.Path, "database/migrations/") && old[f.Path] != "" && old[f.Path] != f.SHA256 {
			t.Fatalf("changed existing migration %s", f.Path)
		}
	}
}
func TestFailClosedAfterDBForward(t *testing.T) {
	b := &bridge{ev: evidence{}, evidenceDir: t.TempDir()}
	b.ev.AttemptID = "test"
	b.ev.DatabaseBackupPath = "verified-local-backup.sql"
	err := b.failAfterDbBoundary(os.ErrPermission)
	if err == nil || !b.ev.DatabaseBoundaryReached || !b.ev.DatabaseMutation || b.ev.RollbackAttempted || !strings.Contains(err.Error(), "code rollback forbidden") {
		t.Fatal("post-boundary rollback safety violated")
	}
}
func TestEngineContractAndHostAgnostic(t *testing.T) {
	good := "SOKNA Maintenance Update Engine 0.3.13 (DB mutation disabled)\nlocal-slot-inspect --expected-build <id> --expected-managed-files <n> --expected-managed-tree-sha256 <sha256>\n"
	if _, err := validateEngineHelp(good); err != nil {
		t.Fatal(err)
	}
	for _, bad := range []string{strings.Replace(good, "0.3.13", "0.3.8", 1), strings.Replace(good, "--expected-managed-tree-sha256 <sha256>", "", 1)} {
		if _, e := validateEngineHelp(bad); e == nil {
			t.Fatal("unsafe update engine admitted")
		}
	}
	source, e := os.ReadFile("main.go")
	if e != nil {
		t.Fatal(e)
	}
	if strings.Contains(string(source), `strings.EqualFold(host, "SOKNARESTAURANT")`) {
		t.Fatal("machine-name gate")
	}
	if !strings.Contains(string(source), "b.backupMariaDB()") || !strings.Contains(string(source), "b.phpApplyTargetMigrations(b.targetSlot)") || !strings.Contains(string(source), "DB_FORWARD_BOUNDARY") {
		t.Fatal("DB forward boundaries not wired")
	}
}
func TestTransactionExclusivity(t *testing.T) {
	temp := t.TempDir()
	b := &bridge{evidenceDir: filepath.Join(temp, "attempt"), lifecycleStateBackup: filepath.Join(temp, "state"), packageRoot: filepath.Join(temp, "site"), stableWeb: filepath.Join(temp, "site", "Web"), targetSlot: filepath.Join(temp, "new"), stageSlot: filepath.Join(temp, "stage"), backupPath: filepath.Join(temp, "old"), lifecycleStatePath: filepath.Join(temp, "state"), lifecycleManagedPath: filepath.Join(temp, "managed")}
	if e := os.MkdirAll(b.evidenceDir, 0700); e != nil {
		t.Fatal(e)
	}
	if e := os.WriteFile(b.lifecycleStateBackup, []byte(`{"test":true}`), 0600); e != nil {
		t.Fatal(e)
	}
	b.ev.AttemptID = "test"
	if e := b.beginMutationTransaction(); e != nil {
		t.Fatal(e)
	}
	if e := b.beginMutationTransaction(); e == nil {
		t.Fatal("parallel transition admitted")
	}
	if e := b.checkpointTransaction("DB_FORWARD_BOUNDARY"); e != nil {
		t.Fatal(e)
	}
	if e := b.closeTransaction("COMMITTED"); e != nil {
		t.Fatal(e)
	}
}

// Reproduction of the Cafe failure: native BrowserSetupService writes db.port
// to config.php as a PHP string; JSON decoder must receive an integer.
func TestMariaDBBackupConfigStringPortRoundTrip(t *testing.T) {
	phpExe, err := exec.LookPath("php")
	if err != nil {
		t.Skip("PHP CLI not installed for integration assertion")
	}
	dir := t.TempDir()
	fixture := `<?php return ['db'=>['user'=>'sokna_test','pass'=>'fixture-only', 'host'=>'127.0.0.1','port'=>'3307','name'=>'sokna_test']];`
	if err := os.WriteFile(filepath.Join(dir, "config.php"), []byte(fixture), 0600); err != nil {
		t.Fatal(err)
	}
	script := filepath.Join(dir, "read.php")
	if err := os.WriteFile(script, []byte(backupDBConfigReaderPHP), 0600); err != nil {
		t.Fatal(err)
	}
	out, err := exec.Command(phpExe, script, dir).CombinedOutput()
	if err != nil {
		t.Fatalf("PHP config reader failed: %v", err)
	}
	got, err := parseBackupDBConfig(string(out))
	if err != nil {
		t.Fatalf("string-backed port was not supported: %v", err)
	}
	if got.Port != 3307 || got.Host != "127.0.0.1" || got.Name != "sokna_test" {
		t.Fatal("decoded DB config not equal")
	}
}
func TestMariaDBBackupConfigRejectsUnsafeEndpoints(t *testing.T) {
	for _, raw := range []string{
		`{"user":"sokna","pass":"x","host":"remote.example","port":3306,"name":"sokna"}`,
		`{"user":"sokna","pass":"x","host":"127.0.0.1","port":0,"name":"sokna"}`,
		`{"user":"sokna","pass":"x","host":"localhost","port":65536,"name":"sokna"}`,
		`{"user":"sokna","pass":"x","host":"localhost","port":3306,"name":"sokna;DROP"}`,
	} {
		if _, err := parseBackupDBConfig(raw); err == nil {
			t.Fatalf("unsafe DB endpoint accepted")
		}
	}
}
func TestMariaDBBackupConfigAcceptsNumericPort(t *testing.T) {
	if got, err := parseBackupDBConfig(`{"user":"sokna","pass":"x","host":"localhost","port":3306,"name":"sokna"}`); err != nil || got.Port != 3306 {
		t.Fatalf("valid DB endpoint rejected: %v", err)
	}
}
