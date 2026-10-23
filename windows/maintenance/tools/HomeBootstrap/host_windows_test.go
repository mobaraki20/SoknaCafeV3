//go:build windows

package main

import (
    "os"
    "os/exec"
    "path/filepath"
    "testing"
)

func TestNativeJunctionIdentityAndWrongTarget(t *testing.T) {
    root:=t.TempDir();target:=filepath.Join(root,"سامانه کافه With Spaces");foreign:=filepath.Join(root,"other")
    for _,p:=range []string{target,foreign}{if err:=os.Mkdir(p,0700);err!=nil{t.Fatal(err)}}
    link:=filepath.Join(root,"Web")
    if out,err:=exec.Command("cmd.exe","/d","/c","mklink","/J",link,target).CombinedOutput();err!=nil{t.Fatalf("junction: %v %s",err,out)}
    defer os.Remove(link)
    if same,err:=samePhysicalDirectory(target,link);err!=nil||!same{t.Fatalf("same NTFS identity not proven: %v %v",same,err)}
    if same,err:=samePhysicalDirectory(foreign,link);err!=nil||same{t.Fatalf("foreign NTFS identity accepted: %v %v",same,err)}
}
