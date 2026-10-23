package main

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

type v4OperationRecord struct {
	OperationID   string `json:"operation_id"`
	OperationType string `json:"operation_type"`
	State         string `json:"state"`
}

// Strictly read-only assessment of the existing V4 operation ledger.
// No per-machine exceptions, host names, legacy bypass, identity rewriting,
// or clearing recovery state. Any unknown unresolved operation blocks EXE.
func refusePendingSignedV4Updates() (bool, error) {
	pd := os.Getenv("ProgramData")
	if pd == "" {
		pd = `C:\ProgramData`
	}
	root := filepath.Join(pd, "SOKNA", "Maintenance")
	stageRoot := filepath.Join(root, "staging", "release-sets")
	if list, err := os.ReadDir(stageRoot); err == nil {
		if len(list) != 0 {
			return false, errors.New("V4_STAGED_RELEASE_PRESENT: resolve through Maintenance panel/recovery")
		}
	} else if !os.IsNotExist(err) {
		return false, err
	}
	seq := filepath.Join(root, "update", "release-sequence.json")
	if _, err := os.Lstat(seq); err == nil {
		return false, errors.New("V4_SEQUENCE_PRESENT: existing Maintenance stage requires readback before external update")
	} else if !os.IsNotExist(err) {
		return false, err
	}
	ops := filepath.Join(root, "operations")
	list, err := os.ReadDir(ops)
	if os.IsNotExist(err) {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	for _, f := range list {
		low := strings.ToLower(f.Name())
		if f.IsDir() || !strings.HasPrefix(low, "op-") || !strings.HasSuffix(low, ".json") {
			continue
		}
		data, err := os.ReadFile(filepath.Join(ops, f.Name()))
		if err != nil {
			return false, err
		}
		var rec v4OperationRecord
		if err = json.Unmarshal(data, &rec); err != nil || rec.OperationID == "" || rec.OperationType == "" || rec.State == "" || low != "op-"+strings.ToLower(rec.OperationID)+".json" {
			return false, errors.New("V4_OPERATION_LEDGER_CORRUPT")
		}
		switch rec.State {
		case "accepted", "running", "activating", "recovering", "recovery_required", "staged":
			return false, fmt.Errorf("V4_OPERATION_NOT_IDLE: %s (%s)", rec.OperationID, rec.State)
		}
	}
	return false, nil
}
