package main

import (
	"archive/zip"
	"encoding/json"
	"io"
	"os"
	"testing"
)

func TestBothQualifiedHomeBaselineManifests(t *testing.T) {
	for name, tree := range map[string]string{"payload/baseline-manifest.json": baselineTree, "payload/baseline-canonical-manifest.json": canonicalBaselineTree} {
		raw, e := embedded.ReadFile(name)
		if e != nil {
			t.Fatal(e)
		}
		m, e := loadManifest(raw, "baseline", baselineVersion, 510, tree)
		if e != nil {
			t.Fatal(e)
		}
		if len(m.Files) != 510 {
			t.Fatal("incomplete baseline")
		}
	}
}
func TestActualHomePreserveR2BytesMatchCompiledBaseline(t *testing.T) {
	path := os.Getenv("SOKNA_HOME_TEST_BASELINE_ZIP")
	if path == "" {
		t.Skip("private actual Home baseline ZIP not supplied")
	}
	z, e := zip.OpenReader(path)
	if e != nil {
		t.Fatal(e)
	}
	defer z.Close()
	raw, e := embedded.ReadFile("payload/baseline-manifest.json")
	if e != nil {
		t.Fatal(e)
	}
	var m fileManifest
	if e = json.Unmarshal(raw, &m); e != nil {
		t.Fatal(e)
	}
	expected := map[string]fileEntry{}
	for _, f := range m.Files {
		expected[f.Path] = f
	}
	if len(z.File) != 510 {
		t.Fatal("actual archive count changed")
	}
	for _, f := range z.File {
		want, ok := expected[f.Name]
		if !ok {
			t.Fatal("unknown actual baseline file", f.Name)
		}
		r, e := f.Open()
		if e != nil {
			t.Fatal(e)
		}
		b, e := io.ReadAll(r)
		r.Close()
		if e != nil || proofSHA(b) != want.SHA256 || int64(len(b)) != want.Size {
			t.Fatal("actual Home baseline mismatch", f.Name)
		}
	}
}
