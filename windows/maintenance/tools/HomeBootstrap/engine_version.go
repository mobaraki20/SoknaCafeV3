package main

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

// Physical PE version is independent evidence. A stale 0.3.12 CLI banner was
// shipped in the qualified 0.3.13 assembly; do not accept a real 0.3.12 file.
func validateInstalledEngine(help, physical string) (string, error) {
	if strings.TrimSpace(physical) != "0.3.13.0" {
		return "", fmt.Errorf("ENGINE_PHYSICAL_VERSION_UNSUPPORTED: %q", physical)
	}
	if v, e := validateEngineHelp(help); e == nil {
		return v, nil
	}
	old := "SOKNA Maintenance Update Engine 0.3.12 (R8 sibling-release activation; verified append-only database forward recovery)"
	normalized := strings.ReplaceAll(help, "\r\n", "\n")
	if strings.Split(normalized, "\n")[0] != old {
		return "", errors.New("ENGINE_CLI_CONTRACT_UNRECOGNIZED")
	}
	return validateEngineHelp(strings.Replace(normalized, old, strings.Replace(old, "0.3.12", "0.3.13", 1), 1))
}
func readEngineFileVersion(path, evidenceDir string) (string, error) {
	script := filepath.Join(evidenceDir, "read-engine-file-version.ps1")
	body := `param([Parameter(Mandatory=$true)][string]$EnginePath)
$ErrorActionPreference='Stop'
[Diagnostics.FileVersionInfo]::GetVersionInfo($EnginePath).FileVersion
`
	if err := os.WriteFile(script, []byte(body), 0600); err != nil {
		return "", err
	}
	defer os.Remove(script)
	out, err := runHidden("powershell.exe", "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-File", script, "-EnginePath", path)
	if err != nil {
		return "", errors.New("ENGINE_PHYSICAL_VERSION_UNREADABLE")
	}
	return strings.TrimSpace(out), nil
}
