//go:build !windows

package main

import (
	"fmt"
	"os"
	"os/exec"
)

func hideCommand(cmd *exec.Cmd) {}
func isAdmin() bool             { return true }
func elevateSelf() error        { return nil }
func confirmStart() bool        { return true }
func showSuccess(text string)   {}
func showError(text string)     {}
func samePhysicalDirectory(a, b string) (bool, error) {
	return false, fmt.Errorf("Windows NTFS identity is required")
}

func acquireSharedMutationLease() (*os.File, error) {
	return os.CreateTemp("", "sokna-manual-mutation-lock-")
}
