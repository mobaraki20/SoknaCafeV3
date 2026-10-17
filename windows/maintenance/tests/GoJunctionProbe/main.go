package main

import (
    "fmt"
    "os"
    "path/filepath"
    "strings"
)
func main() {
    if len(os.Args) != 3 { panic("usage Web target") }
    web, target := os.Args[1], os.Args[2]
    info, err := os.Lstat(web)
    fmt.Printf("lstat=%v mode=%v\n", err, func() string {if info==nil{return "<nil>"};return info.Mode().String()}())
    if err != nil {panic(err)}
    resolved, err := filepath.EvalSymlinks(web)
    fmt.Printf("web=%q target=%q EvalSymlinks=%q err=%v\n",web,target,resolved,err)
    if err!=nil {panic(err)}
    if !strings.EqualFold(filepath.Clean(resolved), filepath.Clean(target)) {
        panic("Go junction resolution differs from known Windows physical release target")
    }
}