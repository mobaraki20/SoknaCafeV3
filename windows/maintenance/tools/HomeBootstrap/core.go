package main

import (
	"archive/zip"
	"bufio"
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"
)

const (
	baselineVersion      = "3.0.0-g6.2-workspace.231au-maintenance-descriptor-panel-bridge-home-uat"
	baselineFiles        = 510
	baselineTree         = "aecfe030af4465fa9544041bf17cb58210f6f7f3592fb55c586de30264e01af8"
	targetVersion        = "3.0.0-g6.2-workspace.231bk-durable-updater-rc1"
	targetFiles          = 522
	targetTree           = "2b06467db7607606fde101ce419dfd35acae24868192ce33d848ba4e9cb18ebd"
	targetZipSHA         = "9c501e0652f9c0681cdaa7c67840061f56ac24e8b11f83bc1c0fe3005d8d8f80"
	migrationCount       = 71
	targetMigrationCount = 75
	apacheService        = "SoknaApache"
)

var machineLocalOverlay = []string{
	".sokna-installation.json",
	filepath.Join("public", "local-recovery.php"),
	filepath.Join("public", "local-update-runner.php"),
}

type fileEntry struct {
	Path   string `json:"path"`
	Size   int64  `json:"size"`
	SHA256 string `json:"sha256"`
}

type fileManifest struct {
	Format            string      `json:"format"`
	SchemaVersion     int         `json:"schema_version"`
	Name              string      `json:"name"`
	Version           string      `json:"version"`
	ManagedFiles      int         `json:"managed_files"`
	ManagedTreeSHA256 string      `json:"managed_tree_sha256"`
	Files             []fileEntry `json:"files"`
}

type migrationFile struct {
	File   string `json:"file"`
	SHA256 string `json:"sha256"`
}

type migrationManifest struct {
	Format        string          `json:"format"`
	SchemaVersion int             `json:"schema_version"`
	Count         int             `json:"count"`
	TargetCount   int             `json:"target_count"`
	Added         []migrationFile `json:"added"`
	Unchanged     bool            `json:"unchanged"`
	Files         []migrationFile `json:"files"`
}

type evidence struct {
	Format                  string   `json:"format"`
	AttemptID               string   `json:"attempt_id"`
	StartedAt               string   `json:"started_at"`
	FinishedAt              string   `json:"finished_at,omitempty"`
	OK                      bool     `json:"ok"`
	Phase                   string   `json:"phase"`
	Error                   string   `json:"error,omitempty"`
	PackageRoot             string   `json:"package_root,omitempty"`
	StableWeb               string   `json:"stable_web,omitempty"`
	TargetSlot              string   `json:"target_slot,omitempty"`
	BackupPath              string   `json:"backup_path,omitempty"`
	BaselineVersion         string   `json:"baseline_version"`
	BaselineFiles           int      `json:"baseline_files"`
	BaselineTree            string   `json:"baseline_tree_sha256"`
	TargetVersion           string   `json:"target_version"`
	TargetFiles             int      `json:"target_files"`
	TargetTree              string   `json:"target_tree_sha256"`
	MigrationsExpected      int      `json:"migrations_expected"`
	MigrationsApplied       int      `json:"migrations_applied,omitempty"`
	UpdateEnginePath        string   `json:"update_engine_path,omitempty"`
	UpdateEngineInspectOK   bool     `json:"update_engine_inspect_ok"`
	DatabaseBoundaryReached bool     `json:"database_boundary_reached"`
	DatabaseBackupPath      string   `json:"database_backup_path,omitempty"`
	DatabaseBackupSHA256    string   `json:"database_backup_sha256,omitempty"`
	DatabaseMutation        bool     `json:"database_mutation_performed"`
	PublicMutation          bool     `json:"public_mutation_performed"`
	WindowsServicesMutation bool     `json:"windows_services_mutation_performed"`
	WindowsReboot           bool     `json:"windows_reboot_required"`
	RollbackAttempted       bool     `json:"rollback_attempted"`
	RollbackOK              bool     `json:"rollback_ok"`
	Steps                   []string `json:"steps"`
}

type bridge struct {
	ev                           evidence
	evidenceDir                  string
	logPath                      string
	desktopLog                   string
	packageRoot                  string
	stableWeb                    string
	targetSlot                   string
	stageSlot                    string
	backupPath                   string
	persistentRoot               string
	phpPath                      string
	updateEngine                 string
	dataRoot                     string
	lifecycleRoot                string
	lifecycleStatePath           string
	lifecycleManagedPath         string
	lifecycleStateBackup         string
	lifecycleManagedBackup       string
	lifecycleManagedExisted      bool
	lifecycleMutated             bool
	baseline                     fileManifest
	target                       fileManifest
	migrations                   migrationManifest
	targetZip                    []byte
	oldServicePID                int
	switched                     bool
	dbBoundary                   bool
	readOnly                     bool
	transaction                  *transactionEvidence
	legacyPlan                   *legacyReconciliationPlan
	baselineAttested             bool
	maintenanceSupervisorVersion string
	maintenanceEngineVersion     string
}

func normalizeRel(p string) (string, error) {
	p = strings.ReplaceAll(strings.TrimSpace(p), "\\", "/")
	p = strings.TrimPrefix(p, "./")
	if p == "" || strings.HasPrefix(p, "/") || strings.Contains(p, ":") {
		return "", fmt.Errorf("unsafe relative path %q", p)
	}
	clean := filepath.ToSlash(filepath.Clean(filepath.FromSlash(p)))
	if clean == "." || clean == ".." || strings.HasPrefix(clean, "../") || strings.Contains(clean, "/../") {
		return "", fmt.Errorf("unsafe relative path %q", p)
	}
	return clean, nil
}

func shaFile(path string) (string, int64, error) {
	f, err := os.Open(path)
	if err != nil {
		return "", 0, err
	}
	defer f.Close()
	h := sha256.New()
	n, err := io.Copy(h, f)
	if err != nil {
		return "", 0, err
	}
	return hex.EncodeToString(h.Sum(nil)), n, nil
}

func managedTree(entries []fileEntry) string {
	cp := append([]fileEntry(nil), entries...)
	sort.Slice(cp, func(i, j int) bool { return cp[i].Path < cp[j].Path })
	h := sha256.New()
	for _, e := range cp {
		io.WriteString(h, filepath.ToSlash(e.Path))
		h.Write([]byte{0})
		io.WriteString(h, strings.ToLower(e.SHA256))
		h.Write([]byte{'\n'})
	}
	return hex.EncodeToString(h.Sum(nil))
}

func loadManifest(data []byte, expectedName, expectedVersion string, expectedCount int, expectedTree string) (fileManifest, error) {
	var m fileManifest
	if err := json.Unmarshal(data, &m); err != nil {
		return m, err
	}
	if m.Format != "sokna-cafe-local-bridge-file-manifest-v1" || m.SchemaVersion != 1 || m.Name != expectedName || m.Version != expectedVersion || m.ManagedFiles != expectedCount || !strings.EqualFold(m.ManagedTreeSHA256, expectedTree) || len(m.Files) != expectedCount {
		return m, fmt.Errorf("manifest identity mismatch for %s", expectedName)
	}
	seen := map[string]bool{}
	normalized := make([]fileEntry, 0, len(m.Files))
	for _, e := range m.Files {
		rel, err := normalizeRel(e.Path)
		if err != nil {
			return m, err
		}
		if seen[strings.ToLower(rel)] {
			return m, fmt.Errorf("duplicate manifest path %s", rel)
		}
		seen[strings.ToLower(rel)] = true
		if len(e.SHA256) != 64 {
			return m, fmt.Errorf("invalid sha for %s", rel)
		}
		e.Path = rel
		e.SHA256 = strings.ToLower(e.SHA256)
		normalized = append(normalized, e)
	}
	m.Files = normalized
	if !strings.EqualFold(managedTree(m.Files), expectedTree) {
		return m, fmt.Errorf("manifest tree formula mismatch for %s", expectedName)
	}
	return m, nil
}

func verifyTree(root string, m fileManifest) error {
	if len(m.Files) != m.ManagedFiles {
		return fmt.Errorf("manifest count mismatch")
	}
	for _, e := range m.Files {
		path := filepath.Join(root, filepath.FromSlash(e.Path))
		got, size, err := shaFile(path)
		if err != nil {
			return fmt.Errorf("%s: %w", e.Path, err)
		}
		if size != e.Size || !strings.EqualFold(got, e.SHA256) {
			return fmt.Errorf("managed file drift: %s", e.Path)
		}
	}
	return nil
}

func verifyExactSet(root string, m fileManifest) error {
	expected := map[string]bool{}
	for _, e := range m.Files {
		expected[strings.ToLower(filepath.Clean(filepath.FromSlash(e.Path)))] = true
	}
	actual := map[string]bool{}
	err := filepath.WalkDir(root, func(path string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		if d.IsDir() {
			return nil
		}
		rel, err := filepath.Rel(root, path)
		if err != nil {
			return err
		}
		actual[strings.ToLower(filepath.Clean(rel))] = true
		return nil
	})
	if err != nil {
		return err
	}
	if len(actual) != len(expected) {
		return fmt.Errorf("target file count mismatch: actual=%d expected=%d", len(actual), len(expected))
	}
	for p := range expected {
		if !actual[p] {
			return fmt.Errorf("target missing file %s", p)
		}
	}
	for p := range actual {
		if !expected[p] {
			return fmt.Errorf("target unexpected file %s", p)
		}
	}
	return verifyTree(root, m)
}

func extractZipExact(data []byte, dest string, manifest fileManifest) error {
	zr, err := zip.NewReader(bytes.NewReader(data), int64(len(data)))
	if err != nil {
		return err
	}
	expected := map[string]fileEntry{}
	for _, e := range manifest.Files {
		expected[strings.ToLower(filepath.ToSlash(e.Path))] = e
	}
	if len(zr.File) != len(expected) {
		return fmt.Errorf("zip entry count mismatch")
	}
	if err := os.MkdirAll(dest, 0755); err != nil {
		return err
	}
	seen := map[string]bool{}
	for _, zf := range zr.File {
		if zf.FileInfo().IsDir() {
			continue
		}
		rel, err := normalizeRel(zf.Name)
		if err != nil {
			return err
		}
		key := strings.ToLower(rel)
		e, ok := expected[key]
		if !ok {
			return fmt.Errorf("unexpected zip entry %s", rel)
		}
		if seen[key] {
			return fmt.Errorf("duplicate zip entry %s", rel)
		}
		seen[key] = true
		out := filepath.Join(dest, filepath.FromSlash(rel))
		absDest, _ := filepath.Abs(dest)
		absOut, _ := filepath.Abs(out)
		if absOut != absDest && !strings.HasPrefix(strings.ToLower(absOut), strings.ToLower(absDest+string(os.PathSeparator))) {
			return fmt.Errorf("zip path escapes destination")
		}
		if err := os.MkdirAll(filepath.Dir(out), 0755); err != nil {
			return err
		}
		r, err := zf.Open()
		if err != nil {
			return err
		}
		h := sha256.New()
		var buf bytes.Buffer
		n, copyErr := io.Copy(io.MultiWriter(&buf, h), r)
		r.Close()
		if copyErr != nil {
			return copyErr
		}
		if n != e.Size || !strings.EqualFold(hex.EncodeToString(h.Sum(nil)), e.SHA256) {
			return fmt.Errorf("zip payload mismatch %s", rel)
		}
		if err := os.WriteFile(out, buf.Bytes(), 0644); err != nil {
			return err
		}
	}
	if len(seen) != len(expected) {
		return fmt.Errorf("zip missing managed entries")
	}
	return verifyExactSet(dest, manifest)
}

func parseImagePathFromSC(out string) (string, error) {
	s := bufio.NewScanner(strings.NewReader(out))
	exeRE := regexp.MustCompile(`(?i)(?:\"([^\"]+\.exe)\"|([^\s\"]+\.exe))`)
	for s.Scan() {
		line := strings.TrimSpace(s.Text())
		if !strings.Contains(strings.ToLower(line), "infrastructure") || !strings.Contains(strings.ToLower(line), ".exe") {
			continue
		}
		m := exeRE.FindStringSubmatch(line)
		if len(m) > 0 {
			if m[1] != "" {
				return m[1], nil
			}
			if m[2] != "" {
				return m[2], nil
			}
		}
	}
	return "", errors.New("SoknaApache ImagePath not found")
}

func derivePackageRoot(exePath string) (string, error) {
	p := strings.ReplaceAll(strings.TrimSpace(exePath), "/", "\\")
	lower := strings.ToLower(p)
	marker := "\\infrastructure\\"
	idx := strings.Index(lower, marker)
	if idx <= 0 {
		return "", fmt.Errorf("Apache path is outside SOKNA Infrastructure: %s", exePath)
	}
	root := strings.TrimRight(p[:idx], "\\/")
	if root == "" {
		return "", fmt.Errorf("invalid package root from Apache path: %s", exePath)
	}
	return root, nil
}

func parseSCQueryPID(out string) (state int, pid int) {
	for _, line := range strings.Split(out, "\n") {
		up := strings.ToUpper(line)
		if strings.Contains(up, "STATE") {
			fields := strings.Fields(line)
			for _, f := range fields {
				if n, e := strconv.Atoi(f); e == nil && n >= 1 && n <= 7 {
					state = n
					break
				}
			}
		}
		if strings.Contains(up, "PID") {
			fields := strings.Fields(line)
			for i := len(fields) - 1; i >= 0; i-- {
				if n, e := strconv.Atoi(fields[i]); e == nil {
					pid = n
					break
				}
			}
		}
	}
	return
}

func (b *bridge) addStep(s string) {
	b.ev.Steps = append(b.ev.Steps, time.Now().UTC().Format(time.RFC3339)+" "+s)
	b.log(s)
	b.writeEvidence()
}
func (b *bridge) log(s string) {
	line := time.Now().Format("2006-01-02 15:04:05.000") + " " + s + "\r\n"
	_ = os.WriteFile(b.logPath, append(readIfExists(b.logPath), []byte(line)...), 0644)
	if b.desktopLog != "" {
		_ = os.WriteFile(b.desktopLog, append(readIfExists(b.desktopLog), []byte(line)...), 0644)
	}
}
func readIfExists(p string) []byte { d, _ := os.ReadFile(p); return d }
func (b *bridge) writeEvidence() {
	d, _ := json.MarshalIndent(b.ev, "", "  ")
	body := append(d, '\n')
	_ = atomicWrite(filepath.Join(b.evidenceDir, "result.json"), body)
	if b.desktopLog != "" {
		// Convenience evidence copy for a nontechnical operator. Failure to
		// write near executable never changes the host and never fabricates a PASS.
		_ = atomicWrite(strings.TrimSuffix(b.desktopLog, ".log")+".json", body)
	}
}

func runHidden(name string, args ...string) (string, error) {
	phpWorkingDirectory := ""
	if strings.EqualFold(filepath.Base(name), "php.exe") {
		ini := filepath.Join(filepath.Dir(name), "php.ini")
		if st, err := os.Stat(ini); err == nil && st.Mode().IsRegular() {
			args = append([]string{"-c", "php.ini"}, args...)
			phpWorkingDirectory = filepath.Dir(name)
		}
	}
	cmd := exec.Command(name, args...)
	if phpWorkingDirectory != "" {
		cmd.Dir = phpWorkingDirectory
	}
	hideCommand(cmd)
	var out bytes.Buffer
	cmd.Stdout = &out
	cmd.Stderr = &out
	err := cmd.Run()
	return out.String(), err
}

func httpHealth(expectedVersion string) error {
	c := &http.Client{Timeout: 8 * time.Second, CheckRedirect: func(req *http.Request, via []*http.Request) error { return http.ErrUseLastResponse }}
	req, _ := http.NewRequest("GET", "http://127.0.0.1:18080/login.php", nil)
	req.Header.Set("Cache-Control", "no-cache")
	resp, err := c.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	return validateHealthResponse(resp, expectedVersion)
}

func validateHealthResponse(resp *http.Response, expectedVersion string) error {
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		location := resp.Header.Get("Location")
		if len(location) > 160 {
			location = location[:160]
		}
		return fmt.Errorf("HTTP %d redirect=%q", resp.StatusCode, location)
	}
	if strings.TrimSpace(resp.Header.Get("X-Sokna-Build")) != expectedVersion {
		return fmt.Errorf("X-Sokna-Build mismatch: %q", resp.Header.Get("X-Sokna-Build"))
	}
	if strings.TrimSpace(resp.Header.Get("X-Sokna-Runtime")) != "php-ready" {
		return fmt.Errorf("X-Sokna-Runtime mismatch: %q", resp.Header.Get("X-Sokna-Runtime"))
	}
	return nil
}

func waitHealth(expected string, timeout time.Duration) error {
	deadline := time.Now().Add(timeout)
	var last error
	for time.Now().Before(deadline) {
		if e := httpHealth(expected); e == nil {
			return nil
		} else {
			last = e
		}
		time.Sleep(1200 * time.Millisecond)
	}
	return fmt.Errorf("health timeout: %v", last)
}

func (b *bridge) serviceQuery() (int, int, error) {
	out, err := runHidden("sc.exe", "queryex", apacheService)
	if err != nil {
		return 0, 0, fmt.Errorf("sc queryex: %v %s", err, out)
	}
	s, p := parseSCQueryPID(out)
	if s == 0 {
		return 0, p, fmt.Errorf("service state unavailable: %s", out)
	}
	return s, p, nil
}
func (b *bridge) stopApache() error {
	s, p, err := b.serviceQuery()
	if err != nil {
		return err
	}
	b.oldServicePID = p
	if s == 1 {
		return nil
	}
	_, _ = runHidden("sc.exe", "stop", apacheService)
	deadline := time.Now().Add(45 * time.Second)
	for time.Now().Before(deadline) {
		s, _, e := b.serviceQuery()
		if e == nil && s == 1 {
			if p > 0 {
				for i := 0; i < 20; i++ {
					o, _ := runHidden("tasklist.exe", "/FI", fmt.Sprintf("PID eq %d", p), "/NH")
					if !strings.Contains(o, strconv.Itoa(p)) {
						return nil
					}
					time.Sleep(250 * time.Millisecond)
				}
			}
			return nil
		}
		time.Sleep(600 * time.Millisecond)
	}
	return errors.New("SoknaApache did not stop")
}
func (b *bridge) startApache() error {
	_, err := runHidden("sc.exe", "start", apacheService)
	deadline := time.Now().Add(45 * time.Second)
	for time.Now().Before(deadline) {
		s, _, e := b.serviceQuery()
		if e == nil && s == 4 {
			return nil
		}
		time.Sleep(600 * time.Millisecond)
	}
	if err != nil {
		return err
	}
	return errors.New("SoknaApache did not start")
}

func findFile(root string, baseName string, maxDepth int) (string, error) {
	root = filepath.Clean(root)
	var found string
	err := filepath.WalkDir(root, func(path string, d fs.DirEntry, err error) error {
		if err != nil {
			return nil
		}
		rel, _ := filepath.Rel(root, path)
		depth := 0
		if rel != "." {
			depth = len(strings.Split(filepath.Clean(rel), string(os.PathSeparator)))
		}
		if d.IsDir() && depth > maxDepth {
			return filepath.SkipDir
		}
		if !d.IsDir() && strings.EqualFold(d.Name(), baseName) {
			found = path
			return io.EOF
		}
		return nil
	})
	if err != nil && !errors.Is(err, io.EOF) {
		return "", err
	}
	if found == "" {
		return "", fmt.Errorf("%s not found under %s", baseName, root)
	}
	return found, nil
}

func (b *bridge) phpMigrationPreflight(webRoot string) (int, error) {
	return b.phpMigrationPreflightExpected(webRoot, migrationCount)
}
func (b *bridge) phpMigrationPreflightExpected(webRoot string, expected int) (int, error) {
	script := `<?php
if($argc<4){fwrite(STDERR,"args\n");exit(64);} $root=$argv[1];$web=$argv[2];$expected=(int)$argv[3];
$config=require $root.DIRECTORY_SEPARATOR.'config.php'; if(!is_array($config)){fwrite(STDERR,"config\n");exit(65);}
require_once $web.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Core'.DIRECTORY_SEPARATOR.'Config.php';
require_once $web.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Core'.DIRECTORY_SEPARATOR.'Database.php';
require_once $web.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Core'.DIRECTORY_SEPARATOR.'Migrations.php';
$c=\Sokna\Local\Core\Config::fromArray($config); $pdo=\Sokna\Local\Core\Database::connect($c); $m=new \Sokna\Local\Core\Migrations($pdo,$web.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations');
$catalog=array_keys($m->catalog()); $applied=$m->appliedVersions(); sort($catalog,SORT_STRING);sort($applied,SORT_STRING);
$ok=count($catalog)===$expected && count($applied)===$expected && $catalog===$applied;
echo json_encode(['ok'=>$ok,'catalog_count'=>count($catalog),'applied_count'=>count($applied),'missing'=>array_values(array_diff($catalog,$applied)),'extra'=>array_values(array_diff($applied,$catalog))],JSON_UNESCAPED_SLASHES),"\n"; exit($ok?0:66);
?>`
	p := filepath.Join(b.evidenceDir, "migration-preflight.php")
	if err := os.WriteFile(p, []byte(script), 0600); err != nil {
		return 0, err
	}
	out, err := runHidden(b.phpPath, p, b.packageRoot, webRoot, strconv.Itoa(expected))
	b.log("migration-preflight output: " + strings.TrimSpace(out))
	if err != nil {
		return 0, fmt.Errorf("migration preflight failed: %v %s", err, out)
	}
	var r struct {
		OK      bool `json:"ok"`
		Catalog int  `json:"catalog_count"`
		Applied int  `json:"applied_count"`
	}
	if e := json.Unmarshal([]byte(strings.TrimSpace(out)), &r); e != nil {
		return 0, e
	}
	if !r.OK {
		return r.Applied, errors.New("migration catalog/applied mismatch")
	}
	return r.Applied, nil
}

// This read-only setup probe checks a sealed inactive Local candidate; migrations run only after the durable DB boundary.
func (b *bridge) phpTargetSetupPreflight(targetWeb string) error {
	// BrowserSetupService constructor only adopts old config if BOTH the
	// package-root config and install.lock are absent. They were required by
	// the earlier preflight, hence status() is read-only on this path.
	script := `<?php
if($argc<3){fwrite(STDERR,"arguments\n");exit(64);}
$root=$argv[1];$web=$argv[2];
if(!is_file($root.DIRECTORY_SEPARATOR.'config.php')||!is_file($root.DIRECTORY_SEPARATOR.'install.lock')){fwrite(STDERR,"installation lock missing\n");exit(65);}
require_once $web.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Setup'.DIRECTORY_SEPARATOR.'BrowserSetupService.php';
$status=(new \Sokna\Local\Setup\BrowserSetupService($root,$web))->status();
$ok=($status['state']??'')==='installed'&&($status['installed']??false)===true&&($status['binding_valid']??false)===true&&($status['binding_current']??false)===true&&($status['upgrade_required']??true)===false;
echo json_encode(['ok'=>$ok,'state'=>$status['state']??null,'binding_valid'=>$status['binding_valid']??null,'binding_current'=>$status['binding_current']??null],JSON_UNESCAPED_SLASHES),"\n";
exit($ok?0:66);
?>`
	p := filepath.Join(b.evidenceDir, "target-setup-preflight.php")
	if err := os.WriteFile(p, []byte(script), 0600); err != nil {
		return err
	}
	out, err := runHidden(b.phpPath, p, b.packageRoot, targetWeb)
	if err != nil {
		return fmt.Errorf("target BrowserSetupService preflight rejected binding: %v %s", err, strings.TrimSpace(out))
	}
	var proof struct {
		OK bool `json:"ok"`
	}
	if err := json.Unmarshal([]byte(strings.TrimSpace(out)), &proof); err != nil {
		return err
	}
	if !proof.OK {
		return errors.New("target BrowserSetupService preflight did not prove installed status")
	}
	return nil
}

func copyFile(src, dst string) error {
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()
	if err := os.MkdirAll(filepath.Dir(dst), 0755); err != nil {
		return err
	}
	tmp := dst + ".tmp-" + strconv.FormatInt(time.Now().UnixNano(), 10)
	out, err := os.OpenFile(tmp, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0644)
	if err != nil {
		return err
	}
	_, ce := io.Copy(out, in)
	se := out.Sync()
	cl := out.Close()
	if ce != nil {
		return ce
	}
	if se != nil {
		return se
	}
	if cl != nil {
		return cl
	}
	return os.Rename(tmp, dst)
}

// bindingIdentity validates the existing exact-version machine binding and its
// install lock before any target-version synthesis.  This is independent of
// the host name, install drive and customer identity.
func bindingIdentity(path, lockPath, expectedVersion string) (string, map[string]any, error) {
	var data map[string]any
	raw, err := os.ReadFile(path)
	if err != nil {
		return "", nil, err
	}
	if err := json.Unmarshal(raw, &data); err != nil {
		return "", nil, fmt.Errorf("binding JSON invalid: %w", err)
	}
	id, _ := data["installation_id"].(string)
	version, _ := data["web_version"].(string)
	if data["format"] != "sokna-local-installation-binding-v1" || id == "" || version != expectedVersion {
		return "", nil, errors.New("binding format, identity or web_version mismatch")
	}
	var lock map[string]any
	lockBytes, err := os.ReadFile(lockPath)
	if err != nil {
		return "", nil, err
	}
	if err := json.Unmarshal(lockBytes, &lock); err != nil {
		return "", nil, fmt.Errorf("install lock invalid: %w", err)
	}
	if lock["format"] != "sokna-install-lock-v3" || lock["installation_id"] != id {
		return "", nil, errors.New("binding installation_id does not match install.lock")
	}
	return id, data, nil
}

func synthesizeTargetBinding(persistentRoot, targetSlot, packageRoot string) error {
	src := filepath.Join(persistentRoot, ".sokna-installation.json")
	_, original, err := bindingIdentity(src, filepath.Join(packageRoot, "install.lock"), baselineVersion)
	if err != nil {
		return err
	}
	target := filepath.Join(targetSlot, ".sokna-installation.json")
	// Never mutate an existing symlink/reparse target or the old Web release.
	if info, e := os.Lstat(target); e != nil || info.Mode()&os.ModeSymlink != 0 {
		return errors.New("inactive target binding missing or redirected")
	}
	next := make(map[string]any, len(original)+3)
	for k, v := range original {
		next[k] = v
	}
	next["web_version"] = targetVersion
	next["source"] = "maintenance-portable-exact-baseline-bootstrap"
	next["bound_at"] = time.Now().UTC().Format(time.RFC3339Nano)
	body, err := json.MarshalIndent(next, "", "  ")
	if err != nil {
		return err
	}
	temp := target + ".new-" + strconv.FormatInt(time.Now().UnixNano(), 10)
	f, err := os.OpenFile(temp, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0600)
	if err != nil {
		return err
	}
	_, werr := f.Write(append(body, '\n'))
	serr := f.Sync()
	cerr := f.Close()
	if werr != nil || serr != nil || cerr != nil {
		_ = os.Remove(temp)
		return errors.New("target binding write not durable")
	}
	if err := os.Rename(temp, target); err != nil {
		_ = os.Remove(temp)
		return err
	}
	return verifyTargetBinding(persistentRoot, targetSlot, packageRoot)
}

func verifyTargetBinding(persistentRoot, targetSlot, packageRoot string) error {
	oldID, _, err := bindingIdentity(filepath.Join(persistentRoot, ".sokna-installation.json"), filepath.Join(packageRoot, "install.lock"), baselineVersion)
	if err != nil {
		return err
	}
	newID, _, err := bindingIdentity(filepath.Join(targetSlot, ".sokna-installation.json"), filepath.Join(packageRoot, "install.lock"), targetVersion)
	if err != nil {
		return err
	}
	if oldID != newID {
		return errors.New("target binding installation_id drift")
	}
	return nil
}

// readOnlyTargetPreflight verifies the candidate on the installed PHP/DB
// without touching stable Web, LocalWeb/persistent, junctions, services or
// durable lifecycle. All temporary payloads live only under evidenceDir.
func (b *bridge) readOnlyTargetPreflight() error {
	if b.evidenceDir == "" || b.packageRoot == "" || b.stableWeb == "" {
		return errors.New("read-only preflight context missing")
	}
	b.stageSlot = filepath.Join(b.evidenceDir, "read-only-target")
	b.persistentRoot = filepath.Join(b.evidenceDir, "read-only-binding-snapshot")
	if err := extractZipExact(b.targetZip, b.stageSlot, b.target); err != nil {
		return fmt.Errorf("read-only embedded target verification: %w", err)
	}
	for _, rel := range machineLocalOverlay {
		src := filepath.Join(b.stableWeb, rel)
		info, err := os.Lstat(src)
		if os.IsNotExist(err) {
			if rel == ".sokna-installation.json" {
				return errors.New("required installation binding is absent")
			}
			continue
		}
		if err != nil {
			return err
		}
		if !info.Mode().IsRegular() || info.Mode()&os.ModeSymlink != 0 || isReparse(src) {
			return fmt.Errorf("read-only overlay source is not a regular file: %s", rel)
		}
		for _, dir := range []string{b.persistentRoot, b.stageSlot} {
			if err := copyFile(src, filepath.Join(dir, rel)); err != nil {
				return err
			}
		}
	}
	if err := synthesizeTargetBinding(b.persistentRoot, b.stageSlot, b.packageRoot); err != nil {
		return err
	}
	if err := b.verifyOverlayAt(b.stageSlot); err != nil {
		return err
	}
	if err := b.phpTargetSetupPreflight(b.stageSlot); err != nil {
		return err
	}
	applied, err := b.phpMigrationTransitionPreflight(b.stageSlot)
	if err != nil || applied != migrationCount {
		return fmt.Errorf("read-only 71→75 migration lineage rejected: %v", err)
	}
	b.addStep("read-only target U01 setup and 71→75 migration lineage verified")
	return nil
}

func (b *bridge) preserveOverlays() error {
	b.persistentRoot = filepath.Join(b.packageRoot, "LocalWeb", "persistent")
	if err := os.MkdirAll(b.persistentRoot, 0755); err != nil {
		return err
	}
	for _, rel := range machineLocalOverlay {
		src := filepath.Join(b.stableWeb, rel)
		dst := filepath.Join(b.persistentRoot, rel)
		stage := filepath.Join(b.stageSlot, rel)
		_, srcErr := os.Stat(src)
		if srcErr != nil && !os.IsNotExist(srcErr) {
			return srcErr
		}
		if os.IsNotExist(srcErr) {
			_ = os.Remove(dst)
			_ = os.Remove(stage)
			continue
		}
		if err := copyFile(src, dst); err != nil {
			return err
		}
		if err := copyFile(src, stage); err != nil {
			return err
		}
	}
	if _, err := os.Stat(filepath.Join(b.persistentRoot, ".sokna-installation.json")); err != nil {
		return errors.New("machine-local installation binding is missing before slot transition")
	}
	return synthesizeTargetBinding(b.persistentRoot, b.stageSlot, b.packageRoot)
}

func (b *bridge) verifyOverlayAt(root string) error {
	for _, rel := range machineLocalOverlay {
		persistent := filepath.Join(b.persistentRoot, rel)
		target := filepath.Join(root, rel)
		_, pe := os.Stat(persistent)
		_, te := os.Stat(target)
		presentP := pe == nil
		presentT := te == nil
		if pe != nil && !os.IsNotExist(pe) {
			return pe
		}
		if te != nil && !os.IsNotExist(te) {
			return te
		}
		if presentP != presentT {
			return fmt.Errorf("machine-local overlay presence mismatch %s", rel)
		}
		if !presentP {
			continue
		}
		if rel == ".sokna-installation.json" {
			// The old and target binding intentionally differ by Web version.
			if e := verifyTargetBinding(b.persistentRoot, root, b.packageRoot); e != nil {
				return e
			}
			continue
		}
		a, _, e := shaFile(persistent)
		if e != nil {
			return e
		}
		c, _, e := shaFile(target)
		if e != nil {
			return e
		}
		if !strings.EqualFold(a, c) {
			return fmt.Errorf("machine-local overlay mismatch %s", rel)
		}
	}
	if _, err := os.Stat(filepath.Join(root, ".sokna-installation.json")); err != nil {
		return errors.New("production slot binding overlay is missing")
	}
	return nil
}

func (b *bridge) makeJunction(link, target string) error {
	out, err := runHidden("cmd.exe", "/d", "/s", "/c", "mklink", "/J", link, target)
	if err != nil {
		return fmt.Errorf("mklink failed: %v %s", err, out)
	}
	return nil
}
func (b *bridge) removeJunction(link string) error {
	out, err := runHidden("cmd.exe", "/d", "/s", "/c", "rmdir", link)
	if err != nil {
		return fmt.Errorf("rmdir junction failed: %v %s", err, out)
	}
	return nil
}
func isReparse(path string) bool {
	_, err := runHidden("fsutil.exe", "reparsepoint", "query", path)
	return err == nil
}

func (b *bridge) findUpdateEngine() error {
	pf := os.Getenv("ProgramFiles")
	if pf != "" {
		preferred := filepath.Join(pf, "SOKNA Windows Services", "Maintenance", "App", "SoknaMaintenanceUpdateEngine.exe")
		if _, err := os.Stat(preferred); err == nil {
			b.updateEngine = preferred
			return b.verifyUpdateEngineVersion()
		}
	}
	candidates := []string{}
	if pf != "" {
		candidates = append(candidates, filepath.Join(pf, "SOKNA Windows Services"))
	}
	candidates = append(candidates, filepath.Join(b.packageRoot, "Infrastructure"))
	for _, root := range candidates {
		if _, e := os.Stat(root); e == nil {
			if p, e := findFile(root, "SoknaMaintenanceUpdateEngine.exe", 8); e == nil {
				b.updateEngine = p
				return b.verifyUpdateEngineVersion()
			}
		}
	}
	return errors.New("Maintenance Update Engine with compatible local-slot-inspect not found")
}

// Check the documented read-only CLI capability instead of pinning a single
// Maintenance component patch version. The real host may have 0.3.6 after a
// successful independent Windows Services 1.1.13 upgrade. Reject any unknown engine version until its CLI contract is explicitly qualified.
func validateEngineHelp(out string) (string, error) {
	lines := strings.Split(strings.ReplaceAll(out, "\r\n", "\n"), "\n")
	if len(lines) == 0 {
		return "", errors.New("Maintenance Update Engine help is empty")
	}
	header := strings.TrimSpace(lines[0])
	prefix := "SOKNA Maintenance Update Engine "
	if !strings.HasPrefix(header, prefix) {
		return "", fmt.Errorf("unrecognized Maintenance Update Engine header %q", header)
	}
	rest := strings.TrimPrefix(header, prefix)
	version, _, ok := strings.Cut(rest, " ")
	if !ok || version != "0.3.13" {
		return "", fmt.Errorf("unsupported Maintenance Update Engine version %q", rest)
	}
	if !strings.Contains(out, "local-slot-inspect --expected-build <id>") ||
		!strings.Contains(out, "--expected-managed-tree-sha256 <sha256>") {
		return "", errors.New("installed Maintenance Update Engine lacks required read-only slot-inspect CLI")
	}
	return version, nil
}

func (b *bridge) verifyUpdateEngineVersion() error {
	out, err := runHidden(b.updateEngine, "--help")
	if err != nil {
		return fmt.Errorf("Maintenance Update Engine help failed: %v %s", err, out)
	}
	version, err := validateEngineHelp(out)
	if err != nil {
		return fmt.Errorf("Maintenance Update Engine contract mismatch: %w", err)
	}
	b.maintenanceEngineVersion = version
	b.log("Maintenance Update Engine read-only slot inspect contract verified: " + version)
	return nil
}

func (b *bridge) inspectSlot() error {
	args := []string{"local-slot-inspect", "--expected-build", targetVersion, "--expected-managed-files", strconv.Itoa(targetFiles), "--expected-managed-tree-sha256", targetTree}
	out, err := runHidden(b.updateEngine, args...)
	b.log("UpdateEngine inspect: " + strings.TrimSpace(out))
	if err != nil {
		return fmt.Errorf("UpdateEngine inspect failed: %v %s", err, out)
	}
	var r map[string]any
	if json.Unmarshal([]byte(strings.TrimSpace(out)), &r) != nil {
		return errors.New("UpdateEngine inspect output is not JSON")
	}
	if ok, _ := r["ok"].(bool); !ok {
		return errors.New("UpdateEngine inspect returned ok=false")
	}
	if phase, _ := r["phase"].(string); phase != "INSPECTED" {
		return fmt.Errorf("UpdateEngine inspect phase %q", phase)
	}
	return nil
}

func (b *bridge) discoverDataRootAndLifecycle() error {
	script := `<?php
$config=require $argv[1].DIRECTORY_SEPARATOR.'config.php';
if(!is_array($config)){fwrite(STDERR,"config\n");exit(65);} $data=rtrim(trim((string)($config['app']['data_dir']??'')),"\\/");
if($data===''){fwrite(STDERR,"data_dir\n");exit(66);} echo json_encode(['ok'=>true,'data_dir'=>$data],JSON_UNESCAPED_SLASHES),"\n";
?>`
	p := filepath.Join(b.evidenceDir, "data-root-preflight.php")
	if err := os.WriteFile(p, []byte(script), 0600); err != nil {
		return err
	}
	out, err := runHidden(b.phpPath, p, b.packageRoot)
	if err != nil {
		return fmt.Errorf("data-root preflight failed: %v %s", err, out)
	}
	var r struct {
		OK       bool   `json:"ok"`
		DataRoot string `json:"data_dir"`
	}
	if err := json.Unmarshal([]byte(strings.TrimSpace(out)), &r); err != nil || !r.OK || strings.TrimSpace(r.DataRoot) == "" {
		return fmt.Errorf("data-root preflight output invalid: %s", strings.TrimSpace(out))
	}
	b.dataRoot = filepath.Clean(r.DataRoot)
	b.lifecycleRoot = filepath.Join(b.dataRoot, "lifecycle", "local")
	b.lifecycleStatePath = filepath.Join(b.lifecycleRoot, "state.json")
	b.lifecycleManagedPath = filepath.Join(b.lifecycleRoot, "managed-files.json")
	b.lifecycleStateBackup = filepath.Join(b.evidenceDir, "lifecycle-state.before.json")
	b.lifecycleManagedBackup = filepath.Join(b.evidenceDir, "managed-files.before.json")
	return nil
}

func nilOrEmptyJSON(v any) bool {
	if v == nil {
		return true
	}
	switch x := v.(type) {
	case []any:
		return len(x) == 0
	case map[string]any:
		return len(x) == 0
	case string:
		return strings.TrimSpace(x) == ""
	}
	return false
}

func (b *bridge) preflightLifecycle() error {
	if b.lifecycleRoot == "" {
		return errors.New("lifecycle root is unresolved")
	}
	if _, err := os.Stat(filepath.Join(b.lifecycleRoot, "recovery-required.json")); err == nil {
		return errors.New("Local recovery-required marker is present; bridge refuses an unresolved recovery state")
	} else if !os.IsNotExist(err) {
		return err
	}
	raw, err := os.ReadFile(b.lifecycleStatePath)
	if err != nil {
		return fmt.Errorf("Local lifecycle state is unavailable: %w", err)
	}
	var st map[string]any
	if err = json.Unmarshal(raw, &st); err != nil {
		return fmt.Errorf("Local lifecycle state is invalid: %w", err)
	}
	if fmt.Sprint(st["format"]) != "sokna-local-update-state-v1" {
		return errors.New("Local lifecycle state format mismatch")
	}
	if strings.TrimSpace(fmt.Sprint(st["active_version"])) != baselineVersion {
		return fmt.Errorf("Local lifecycle active_version mismatch: %v", st["active_version"])
	}
	if !nilOrEmptyJSON(st["staged"]) {
		return errors.New("Local has a staged update; clear/resolve it before slot transition")
	}
	if !nilOrEmptyJSON(st["activation_journal"]) {
		return errors.New("Local has an activation journal; bridge refuses an in-progress/interrupted update")
	}
	if !nilOrEmptyJSON(st["recovery_mode"]) {
		if m, ok := st["recovery_mode"].(map[string]any); !ok || m["required"] != false {
			return errors.New("Local recovery mode is active; bridge refuses mutation")
		}
	}
	if err = copyFile(b.lifecycleStatePath, b.lifecycleStateBackup); err != nil {
		return err
	}
	if _, err = os.Stat(b.lifecycleManagedPath); err == nil {
		b.lifecycleManagedExisted = true
		if err = copyFile(b.lifecycleManagedPath, b.lifecycleManagedBackup); err != nil {
			return err
		}
	} else if !os.IsNotExist(err) {
		return err
	}
	return nil
}

func atomicWrite(path string, data []byte) error {
	if err := os.MkdirAll(filepath.Dir(path), 0755); err != nil {
		return err
	}
	tmp := path + ".tmp-" + strconv.FormatInt(time.Now().UnixNano(), 10)
	f, err := os.OpenFile(tmp, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0600)
	if err != nil {
		return err
	}
	if _, err = f.Write(data); err != nil {
		f.Close()
		_ = os.Remove(tmp)
		return err
	}
	if err = f.Sync(); err != nil {
		f.Close()
		_ = os.Remove(tmp)
		return err
	}
	if err = f.Close(); err != nil {
		_ = os.Remove(tmp)
		return err
	}
	if err = os.Rename(tmp, path); err != nil {
		_ = os.Remove(tmp)
		return err
	}
	return nil
}

func (b *bridge) reconcileLocalLifecycle() error {
	raw, err := os.ReadFile(b.lifecycleStatePath)
	if err != nil {
		return err
	}
	var st map[string]any
	if err = json.Unmarshal(raw, &st); err != nil {
		return err
	}
	if strings.TrimSpace(fmt.Sprint(st["active_version"])) != baselineVersion {
		return fmt.Errorf("lifecycle changed during bridge: %v", st["active_version"])
	}
	st["previous_version"] = baselineVersion
	st["active_version"] = targetVersion
	st["staged"] = nil
	st["last_failure"] = nil
	st["activation_journal"] = nil
	st["recovery_mode"] = nil
	st["lkg_recovery_id"] = ""
	hist, _ := st["history"].([]any)
	added := make([]string, 0, len(b.migrations.Added))
	for _, item := range b.migrations.Added {
		added = append(added, strings.TrimSuffix(item.File, ".sql"))
	}
	hist = append(hist, map[string]any{"at": time.Now().UTC().Format(time.RFC3339), "action": "last_oneoff_to_m06_db_forward", "version": targetVersion, "actor_user_id": 0, "migrations": added})
	if len(hist) > 100 {
		hist = hist[len(hist)-100:]
	}
	st["history"] = hist
	out, err := json.MarshalIndent(st, "", "  ")
	if err != nil {
		return err
	}
	out = append(out, '\n')
	// Mark the transaction dirty BEFORE its first durable write. If a later
	// step fails, rollback must restore the previous lifecycle even if only
	// state.json was replaced and managed-files.json was never written.
	b.lifecycleMutated = true
	if err = atomicWrite(b.lifecycleStatePath, out); err != nil {
		return err
	}
	invRaw, err := os.ReadFile(filepath.Join(b.targetSlot, "resources", "local-managed-files-v1.json"))
	if err != nil {
		return err
	}
	var inv map[string]any
	if err = json.Unmarshal(invRaw, &inv); err != nil {
		return err
	}
	files, ok := inv["files"].([]any)
	if !ok || len(files) != targetFiles {
		return errors.New("target managed inventory cannot be reconciled into Local lifecycle")
	}
	managed := map[string]any{"format": "sokna-local-managed-files-v1", "schema_version": 1, "updated_at": time.Now().UTC().Format(time.RFC3339), "files": files}
	md, err := json.MarshalIndent(managed, "", "  ")
	if err != nil {
		return err
	}
	md = append(md, '\n')
	if err = atomicWrite(b.lifecycleManagedPath, md); err != nil {
		return err
	}
	return b.verifyLifecycleTarget()
}

func (b *bridge) verifyLifecycleTarget() error {
	raw, err := os.ReadFile(b.lifecycleStatePath)
	if err != nil {
		return err
	}
	var st map[string]any
	if err = json.Unmarshal(raw, &st); err != nil {
		return err
	}
	if strings.TrimSpace(fmt.Sprint(st["active_version"])) != targetVersion {
		return errors.New("Local lifecycle did not record target active_version")
	}
	raw, err = os.ReadFile(b.lifecycleManagedPath)
	if err != nil {
		return err
	}
	var m map[string]any
	if err = json.Unmarshal(raw, &m); err != nil {
		return err
	}
	files, ok := m["files"].([]any)
	if !ok || len(files) != targetFiles {
		return errors.New("Local lifecycle managed-files count mismatch after reconcile")
	}
	return nil
}

func (b *bridge) restoreLocalLifecycle() error {
	if !b.lifecycleMutated {
		return nil
	}
	// Restore from protected snapshot using atomic replacement: a failure
	// leaves the evidence intact and must block a successful rollback claim.
	state, err := os.ReadFile(b.lifecycleStateBackup)
	if err != nil {
		return fmt.Errorf("baseline lifecycle snapshot missing: %w", err)
	}
	if err := atomicWrite(b.lifecycleStatePath, state); err != nil {
		return fmt.Errorf("baseline lifecycle state restore failed: %w", err)
	}
	if b.lifecycleManagedExisted {
		managed, err := os.ReadFile(b.lifecycleManagedBackup)
		if err != nil {
			return fmt.Errorf("baseline managed inventory snapshot missing: %w", err)
		}
		if err := atomicWrite(b.lifecycleManagedPath, managed); err != nil {
			return fmt.Errorf("baseline managed inventory restore failed: %w", err)
		}
	} else {
		if err := os.Remove(b.lifecycleManagedPath); err != nil && !os.IsNotExist(err) {
			return fmt.Errorf("remove newly-created managed inventory: %w", err)
		}
	}
	b.lifecycleMutated = false
	return nil
}

// archiveStaleInactiveSlot is deliberately recovery-only: it preserves a
// previous inactive directory, rejects symlinks/junctions and never deletes
// evidence. Existing active Web is never passed to this helper.
func archiveStaleInactiveSlot(existing, archiveDir, attemptSuffix string) (string, error) {
	info, err := os.Lstat(existing)
	if os.IsNotExist(err) {
		return "", nil
	}
	if err != nil {
		return "", err
	}
	if !info.IsDir() || info.Mode()&os.ModeSymlink != 0 || isReparse(existing) {
		return "", fmt.Errorf("unexpected inactive path type at %s", filepath.Base(existing))
	}
	dest := filepath.Join(archiveDir, filepath.Base(existing)+".stale-"+attemptSuffix)
	if _, err := os.Lstat(dest); err == nil {
		return "", errors.New("archive destination already exists")
	} else if !os.IsNotExist(err) {
		return "", err
	}
	if err := os.MkdirAll(archiveDir, 0755); err != nil {
		return "", err
	}
	if err := os.Rename(existing, dest); err != nil {
		return "", err
	}
	return dest, nil
}
