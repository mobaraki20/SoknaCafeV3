//go:build windows

package main

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"syscall"
	"unsafe"
)

func hideCommand(cmd *exec.Cmd) { cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true} }

func isAdmin() bool {
	shell32 := syscall.NewLazyDLL("shell32.dll")
	p := shell32.NewProc("IsUserAnAdmin")
	r, _, _ := p.Call()
	return r != 0
}
func elevateSelf() error {
	shell32 := syscall.NewLazyDLL("shell32.dll")
	p := shell32.NewProc("ShellExecuteW")
	verb, _ := syscall.UTF16PtrFromString("runas")
	exe, _ := os.Executable()
	file, _ := syscall.UTF16PtrFromString(exe)
	cwd, _ := os.Getwd()
	dir, _ := syscall.UTF16PtrFromString(cwd)
	r, _, e := p.Call(0, uintptr(unsafe.Pointer(verb)), uintptr(unsafe.Pointer(file)), 0, uintptr(unsafe.Pointer(dir)), 1)
	if r <= 32 {
		return fmt.Errorf("elevation failed: %v", e)
	}
	return nil
}
func msgBox(title, text string, flags uintptr) int {
	user32 := syscall.NewLazyDLL("user32.dll")
	p := user32.NewProc("MessageBoxW")
	t, _ := syscall.UTF16PtrFromString(title)
	x, _ := syscall.UTF16PtrFromString(text)
	r, _, _ := p.Call(0, uintptr(unsafe.Pointer(x)), uintptr(unsafe.Pointer(t)), flags)
	return int(r)
}
func confirmStart() bool {
	return msgBox("SOKNA Home | U01 Panel Bootstrap", "آخرین نصب مستقل Local 231au به U01، با چهار مهاجرت پایگاه داده.\n\nقبل از تغییر، هش ۵۱۰ فایل و سلامت MariaDB چک می‌شود. ابتدا بکاپ SQL تهیه و بررسی می‌شود، سپس Apache متوقف می‌شود.\n\nبعد از شروع مهاجرت SQL، بازگشت خودکار کد قدیمی ایمن نیست. فقط زمانی اجرا کنید که فعالیت کافه متوقف است.\n\nادامه می‌دهید؟", 0x00000004|0x00000030) == 6
}
func showSuccess(text string) { msgBox("سکنا | عملیات کامل شد", text, 0x00000040) }
func showError(text string)   { msgBox("سکنا | عملیات انجام نشد", text, 0x00000010) }

// Directory identity is established using native NTFS file IDs, not
// filepath.EvalSymlinks (which does not resolve Windows junctions correctly).
func samePhysicalDirectory(a, b string) (bool, error) {
	open := func(path string) (syscall.ByHandleFileInformation, error) {
		var info syscall.ByHandleFileInformation
		p, err := syscall.UTF16PtrFromString(path)
		if err != nil {
			return info, err
		}
		h, err := syscall.CreateFile(p, 0x80, syscall.FILE_SHARE_READ|syscall.FILE_SHARE_WRITE|syscall.FILE_SHARE_DELETE, nil, syscall.OPEN_EXISTING, syscall.FILE_FLAG_BACKUP_SEMANTICS, 0)
		if err != nil {
			return info, err
		}
		defer syscall.CloseHandle(h)
		err = syscall.GetFileInformationByHandle(h, &info)
		return info, err
	}
	x, err := open(a)
	if err != nil {
		return false, err
	}
	y, err := open(b)
	if err != nil {
		return false, err
	}
	return x.VolumeSerialNumber == y.VolumeSerialNumber && x.FileIndexHigh == y.FileIndexHigh && x.FileIndexLow == y.FileIndexLow, nil
}

// FileShare.Read matches C# MachineMutationLease's exclusive-writer semantics.
// The OS handle is held until the install/rollback has completed; no second
// Maintenance Supervisor or Update Engine writer can own the same lease.
func acquireSharedMutationLease() (*os.File, error) {
	pd := os.Getenv("ProgramData")
	if pd == "" {
		pd = `C:\ProgramData`
	}
	root := filepath.Join(pd, "SOKNA", "Maintenance", "update")
	if err := os.MkdirAll(root, 0700); err != nil {
		return nil, err
	}
	if isReparse(root) {
		return nil, fmt.Errorf("Maintenance update root is redirected")
	}
	path := filepath.Join(root, "machine-mutation.lock")
	if isReparse(path) {
		return nil, fmt.Errorf("Maintenance mutation lock path is redirected")
	}
	p, err := syscall.UTF16PtrFromString(path)
	if err != nil {
		return nil, err
	}
	h, e := syscall.CreateFile(p, syscall.GENERIC_READ|syscall.GENERIC_WRITE,
		syscall.FILE_SHARE_READ, nil, syscall.OPEN_ALWAYS, syscall.FILE_ATTRIBUTE_NORMAL|0x80000000, 0)
	if e != nil {
		return nil, e
	}
	file := os.NewFile(uintptr(h), path)
	if _, err = file.Seek(0, 0); err != nil {
		file.Close()
		return nil, err
	}
	if err = file.Truncate(0); err != nil {
		file.Close()
		return nil, err
	}
	_, err = file.WriteString("format=sokna-machine-mutation-lease-v1;owner=portable-local-231au-m06-last-oneoff;pid=" + fmt.Sprint(os.Getpid()) + "\n")
	if err != nil {
		file.Close()
		return nil, err
	}
	if err = file.Sync(); err != nil {
		file.Close()
		return nil, err
	}
	// Avoid accidental bypass if the installed engine acquires its own lease;
	// its FileShare.Read holder remains incompatible with our write handle.
	return file, nil
}
