package main

import (
	"os"
	"strings"
	"testing"
)

func TestEngineVersionRefusesOldPhysicalFile(t *testing.T) {
	for _, v := range []string{"0.3.12.0", "", "0.3.14.0"} {
		if _, e := validateInstalledEngine("SOKNA Maintenance Update Engine 0.3.13", v); e == nil {
			t.Fatal("unsupported physical file accepted")
		}
	}
}
func TestActualEngineArtifactContract(t *testing.T) {
	for _, key := range []string{"SOKNA_TEST_ENGINE_OLD_BANNER", "SOKNA_TEST_ENGINE_FIXED"} {
		path := os.Getenv(key)
		if path == "" {
			t.Skip("actual native artifacts not supplied")
		}
		b := &bridge{updateEngine: path, evidenceDir: t.TempDir()}
		if e := b.verifyUpdateEngineVersion(); e != nil {
			t.Fatal(key, e)
		}
		if b.maintenanceEngineVersion != "0.3.13" {
			t.Fatal("physical engine version not retained")
		}
	}
}

func TestActualOldPhysicalEngineRejected(t *testing.T) {
	path := os.Getenv("SOKNA_TEST_ENGINE_PHYSICAL_OLD")
	if path == "" {
		t.Skip("actual negative artifact not supplied")
	}
	b := &bridge{updateEngine: path, evidenceDir: t.TempDir()}
	err := b.verifyUpdateEngineVersion()
	if err == nil || !strings.Contains(err.Error(), "ENGINE_PHYSICAL_VERSION_UNSUPPORTED") {
		t.Fatalf("old physical artifact admitted or not independently read: %v", err)
	}
}
