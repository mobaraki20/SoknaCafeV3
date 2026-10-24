//go:build windows

package main

import (
	"encoding/json"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
)

func TestNativeJunctionIdentityAndWrongTarget(t *testing.T) {
	root := t.TempDir()
	target := filepath.Join(root, "سامانه کافه With Spaces")
	foreign := filepath.Join(root, "other")
	for _, p := range []string{target, foreign} {
		if err := os.Mkdir(p, 0700); err != nil {
			t.Fatal(err)
		}
	}
	link := filepath.Join(root, "Web")
	if out, err := exec.Command("cmd.exe", "/d", "/c", "mklink", "/J", link, target).CombinedOutput(); err != nil {
		t.Fatalf("junction: %v %s", err, out)
	}
	defer os.Remove(link)
	if same, err := samePhysicalDirectory(target, link); err != nil || !same {
		t.Fatalf("same NTFS identity not proven: %v %v", same, err)
	}
	if same, err := samePhysicalDirectory(foreign, link); err != nil || same {
		t.Fatalf("foreign NTFS identity accepted: %v %v", same, err)
	}
}

func TestNativeLegacyEvidenceRejectsJunctionParent(t *testing.T) {
	root := t.TempDir()
	physical := filepath.Join(root, "physical")
	os.Mkdir(physical, 0700)
	os.WriteFile(filepath.Join(physical, "proof.json"), []byte(`{"ok":true}`), 0600)
	link := filepath.Join(root, "redirected")
	if out, err := exec.Command("cmd.exe", "/d", "/c", "mklink", "/J", link, physical).CombinedOutput(); err != nil {
		t.Fatalf("junction: %v %s", err, out)
	}
	defer os.Remove(link)
	if _, _, err := regularProof(filepath.Join(link, "proof.json")); err == nil {
		t.Fatal("redirected historical proof admitted")
	}
}

func TestNativeHomeLeaseRejectsConcurrentWriter(t *testing.T) {
	t.Setenv("ProgramData", t.TempDir())
	first, err := acquireSharedMutationLease()
	if err != nil {
		t.Fatal(err)
	}
	second, err := acquireSharedMutationLease()
	if err == nil {
		second.Close()
		first.Close()
		t.Fatal("parallel machine writer admitted")
	}
	first.Close()
	third, err := acquireSharedMutationLease()
	if err != nil {
		t.Fatal(err)
	}
	third.Close()
}

func nativeDiscoveryFixture(t *testing.T) (*bridge, string) {
	return nativeDiscoveryFixtureAt(t, t.TempDir())
}

func nativeDiscoveryFixtureAt(t *testing.T, parent string) (*bridge, string) {
	t.Helper()
	root := filepath.Join(parent, "خانه With Spaces")
	slot := filepath.Join(root, "Web.release.231au-label-without-hash")
	os.MkdirAll(slot, 0700)
	files := []fileEntry{}
	for n, raw := range map[string][]byte{"VERSION.txt": []byte(baselineVersion + "\n"), "managed.txt": []byte("known qualified content")} {
		path := filepath.Join(slot, n)
		os.WriteFile(path, raw, 0600)
		files = append(files, fileEntry{Path: n, Size: int64(len(raw)), SHA256: proofSHA(raw)})
	}
	manifest := fileManifest{ManagedFiles: len(files), Files: files, ManagedTreeSHA256: managedTree(files), Version: baselineVersion}
	lock, _ := json.Marshal(map[string]any{"format": "sokna-install-lock-v3", "installation_id": "same-site"})
	os.WriteFile(filepath.Join(root, "install.lock"), lock, 0600)
	binding, _ := json.Marshal(map[string]any{"format": "sokna-local-installation-binding-v1", "installation_id": "same-site", "web_version": baselineVersion})
	os.WriteFile(filepath.Join(slot, ".sokna-installation.json"), binding, 0600)
	web := filepath.Join(root, "Web")
	if out, e := exec.Command("cmd.exe", "/d", "/c", "mklink", "/J", web, slot).CombinedOutput(); e != nil {
		t.Fatalf("junction: %v %s", e, out)
	}
	t.Cleanup(func() { os.Remove(web) })
	b := &bridge{packageRoot: root, stableWeb: web, baselines: []fileManifest{manifest}, evidenceDir: t.TempDir()}
	return b, slot
}
func TestNativeHomeDiscoveryUsesActualJunctionNotHashLabel(t *testing.T) {
	b, slot := nativeDiscoveryFixture(t)
	good := b.baselines[0]
	wrong := good
	wrong.Files = append([]fileEntry(nil), good.Files...)
	wrong.Files[0].SHA256 = strings.Repeat("0", 64)
	wrong.ManagedTreeSHA256 = managedTree(wrong.Files)
	b.baselines = []fileManifest{wrong, good}
	// A plausible old guessed path exists but contains the wrong installation.
	guessed := filepath.Join(b.packageRoot, "Web.release.231au-"+baselineTree[:12])
	os.Mkdir(guessed, 0700)
	os.WriteFile(filepath.Join(guessed, "VERSION.txt"), []byte("wrong"), 0600)
	got, e := b.discoverOriginalSlot()
	if e != nil {
		t.Fatal(e)
	}
	if !proofEqualPath(got, slot) {
		t.Fatalf("resolved %s instead of actual %s", got, slot)
	}
	if e = b.verifyOriginalSlot(got); e != nil {
		t.Fatal(e)
	}
}

func TestNativeHomeDiscoverySecondaryNTFSVolume(t *testing.T) {
	if _, err := os.Stat(`D:\`); err != nil {
		t.Skip("secondary NTFS volume unavailable")
	}
	parent, err := os.MkdirTemp(`D:\`, "sokna-home-discovery-")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { os.RemoveAll(parent) })
	b, slot := nativeDiscoveryFixtureAt(t, parent)
	actual, err := b.discoverOriginalSlot()
	if err != nil {
		t.Fatal(err)
	}
	if !proofEqualPath(actual, slot) || !b.ev.BaselineTreeVerified {
		t.Fatal("secondary-volume actual slot not proven")
	}
}
func TestNativeHomeDiscoveryRejectsCurrentFileDrift(t *testing.T) {
	b, slot := nativeDiscoveryFixture(t)
	os.WriteFile(filepath.Join(slot, "managed.txt"), []byte("changed"), 0600)
	if _, e := b.discoverOriginalSlot(); e == nil || !strings.Contains(e.Error(), "BASELINE_MANAGED_TREE_UNRECOGNIZED") {
		t.Fatalf("drift admitted: %v", e)
	}
	if b.ev.BaselineTreeVerified || b.ev.DatabaseMutation || b.ev.ObservedManagedTree == "" {
		t.Fatal("failed discovery report lost truth or observed digest")
	}
}
func TestNativeHomeDiscoveryRejectsOutsideAndNestedSlots(t *testing.T) {
	for _, nested := range []bool{false, true} {
		t.Run(map[bool]string{false: "outside", true: "nested"}[nested], func(t *testing.T) {
			b, slot := nativeDiscoveryFixture(t)
			os.Remove(b.stableWeb)
			other := filepath.Join(t.TempDir(), "Web.release.231au-foreign")
			if nested {
				other = filepath.Join(b.packageRoot, "LocalWeb", "releases", "Web.release.231au-nested")
			}
			os.MkdirAll(other, 0700)
			if out, e := exec.Command("cmd.exe", "/d", "/c", "mklink", "/J", b.stableWeb, other).CombinedOutput(); e != nil {
				t.Fatalf("junction: %v %s", e, out)
			}
			if _, e := b.discoverOriginalSlot(); e == nil {
				t.Fatal("foreign or nested target admitted")
			}
			if _, e := os.Stat(slot); e != nil {
				t.Fatal("old physical slot changed")
			}
		})
	}
}
