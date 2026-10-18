#!/usr/bin/env python3
"""F1-S7E2A: real disposable Apache/HTTP Linux CI lab, not Windows/live updater."""
import hashlib
import http.client
import json
import os
from pathlib import Path
import pwd
import grp
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import time


def check(ok, code):
    if not ok:
        raise RuntimeError("S7E2A_" + code)


def sha(data):
    return hashlib.sha256(data).hexdigest()


def slot(path, version):
    path.mkdir(parents=True)
    blob = json.dumps({"version": version, "build": sha(version.encode()), "kind": "sokna-apache-ci-only"}, separators=(",", ":")).encode()
    (path / "__sokna_health.json").write_bytes(blob)
    return sha(blob)


def pointer(dst, src):
    tmp = dst.with_name(dst.name + ".next")
    if tmp.is_symlink():
        tmp.unlink()
    tmp.symlink_to(src, target_is_directory=True)
    os.replace(tmp, dst)


def health(port, expected, expected_hash):
    try:
        conn = http.client.HTTPConnection("127.0.0.1", port, timeout=0.6)
        conn.request("GET", "/__sokna_health.json", headers={"Cache-Control": "no-store"})
        resp = conn.getresponse()
        body = resp.read(32768)
        conn.close()
        if resp.status != 200 or sha(body) != expected_hash:
            return False
        doc = json.loads(body)
        return doc == {"version": expected, "build": sha(expected.encode()), "kind": "sokna-apache-ci-only"}
    except (OSError, ValueError, json.JSONDecodeError, http.client.HTTPException):
        return False


def wait_http(port, version, digest, proc, timeout=8):
    start = time.monotonic()
    while time.monotonic() - start < timeout:
        if health(port, version, digest):
            return
        if proc.poll() is not None:
            raise RuntimeError("S7E2A_APACHE_EXITED: " + str(proc.returncode))
        time.sleep(0.08)
    raise RuntimeError("S7E2A_HTTP_NOT_READY")


def choose_port():
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


def conf(root, port, document):
    # Apache runs without root privileges and never listens on external interfaces.
    # Explicit modules avoid the distro site config and any existing Apache instance.
    uid = pwd.getpwuid(os.getuid()).pw_name
    group = grp.getgrgid(os.getgid()).gr_name
    path = root / ("httpd-" + str(port) + ".conf")
    path.write_text(f'''ServerRoot "{root}"
PidFile "{root}/apache-{port}.pid"
Listen 127.0.0.1:{port}
ServerName 127.0.0.1
ServerTokens Prod
ServerSignature Off
ErrorLog "{root}/apache-{port}.error.log"
LogLevel warn
LoadModule mpm_event_module /usr/lib/apache2/modules/mod_mpm_event.so
LoadModule authz_core_module /usr/lib/apache2/modules/mod_authz_core.so
User {uid}
Group {group}
DocumentRoot "{document}"
<Directory "{document}">
  Options FollowSymLinks
  AllowOverride None
  Require all granted
</Directory>
''')
    return path


def start_httpd(root, path):
    verify = subprocess.run(["apache2", "-t", "-f", str(path)], capture_output=True, text=True, timeout=12)
    check(verify.returncode == 0, "INVALID_CONFIG:" + verify.stderr[-500:])
    # start_new_session prevents group kill from reaching unrelated CI processes.
    return subprocess.Popen(["apache2", "-f", str(path), "-DFOREGROUND"],
                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                            start_new_session=True)


def hard_stop(p):
    if p.poll() is None:
        os.killpg(p.pid, signal.SIGKILL)
    try:
        p.wait(timeout=6)
    except subprocess.TimeoutExpired:
        raise RuntimeError("S7E2A_APACHE_KILL_TIMEOUT")


def run():
    env = os.environ
    check(env.get("GITHUB_ACTIONS") == "true" and env.get("RUNNER_OS") == "Linux" and
          env.get("GITHUB_WORKFLOW") == "M10 Release Qualification" and
          env.get("SOKNA_APACHE_CI_ONLY") == "1", "GITHUB_LINUX_M10_ONLY")
    runner = Path(env.get("RUNNER_TEMP", "/does-not-exist")).resolve(strict=True)
    check(shutil.which("apache2") is not None, "APACHE_MISSING")
    root = Path(tempfile.mkdtemp(prefix="sokna-s7e2a-", dir=runner))
    check(root.parent == runner and root.name.startswith("sokna-s7e2a-"), "OUT_OF_BOUND_ROOT")
    children = []
    try:
        old = root / "Web.release.old"
        new = root / "Web.release.new"
        old_hash = slot(old, "old")
        new_hash = slot(new, "new")
        active = root / "Web"
        pointer(active, old)
        port = choose_port()
        config = conf(root, port, active)
        a = start_httpd(root, config)
        children.append(a)
        wait_http(port, "old", old_hash, a)
        check(not health(port, "new", new_hash), "WRONG_RELEASE_ACCEPTED")
        # Candidate is served by a separate, independently started real Apache.
        candidate_port = choose_port()
        candidate_conf = conf(root, candidate_port, new)
        candidate = start_httpd(root, candidate_conf)
        children.append(candidate)
        wait_http(candidate_port, "new", new_hash, candidate)
        hard_stop(candidate)
        check(not health(candidate_port, "new", new_hash), "DEAD_SERVER_STILL_HEALTHY")
        # Wrong/corrupted release must not pass health as an install-ready target.
        new_health = new / "__sokna_health.json"
        original = new_health.read_bytes()
        new_health.write_bytes(original + b" ")
        check(not health(port, "new", new_hash), "PRE_SWITCH_CORRUPTION_ACCEPTED")
        new_health.write_bytes(original)
        # A real pointer change impacts Apache HTTP without fake status flags.
        pointer(active, new)
        wait_http(port, "new", new_hash, a)
        check(not health(port, "old", old_hash), "STALE_VERSION_HEALTH_ACCEPTED")
        hard_stop(a)
        check(not health(port, "new", new_hash), "CRASHED_SERVER_MARKED_READY")
        a = start_httpd(root, config)
        children.append(a)
        wait_http(port, "new", new_hash, a)
        # Simulate an interrupted two-rename cutover: no valid HTTP/physical pointer.
        active.unlink()
        check(not health(port, "new", new_hash) and not active.exists(), "GAP_TREATED_HEALTHY")
        hard_stop(a)
        # Apache must refuse restart with a missing DocumentRoot. This is a
        # desirable fail-closed startup failure, not an HTTP-readiness PASS.
        try:
            a = start_httpd(root, config)
        except RuntimeError as exc:
            check("DocumentRoot" in str(exc), "UNEXPECTED_GAP_STARTUP_ERROR")
        else:
            children.append(a)
            check(not health(port, "new", new_hash), "RESTART_IN_GAP_MARKED_HEALTHY")
            hard_stop(a)
        # Explicit recovery, never automatic: restore old, confirm old HTTP.
        pointer(active, old)
        a = start_httpd(root, config)
        children.append(a)
        wait_http(port, "old", old_hash, a)
        # Hard-crash Apache before switching; health must fail, then restore manually.
        hard_stop(a)
        check(not health(port, "old", old_hash), "HARD_CRASH_MARKED_HEALTHY")
        a = start_httpd(root, config)
        children.append(a)
        wait_http(port, "old", old_hash, a)
        check(sha((old / "__sokna_health.json").read_bytes()) == old_hash and
              sha((new / "__sokna_health.json").read_bytes()) == new_hash,
              "IMMUTABLE_SLOT_CHANGED")
        print(json.dumps({"format": "sokna-f1-s7e2a-real-apache-http-ci-v1", "result": "PASS",
                          "apache_binary_real": True, "loopback_http_real": True,
                          "candidate_http_independently_checked": True,
                          "hard_kill_and_restart_http_checked": True,
                          "active_pointer_switch_http_checked": True,
                          "missing_link_fail_closed": True,
                          "stale_version_and_digest_rejected": True,
                          "immutable_slots_unchanged": True,
                          "platform": "Linux CI", "windows_apache_tested": False,
                          "windows_scm_tested": False, "windows_ntfs_junction_tested": False,
                          "mariadb_schema_cutover_tested": False,
                          "real_host_uat": False, "public_mutation": False,
                          "production_activation_authorized": False,
                          "panel_update_ready": False}, separators=(",", ":")))
    finally:
        for child in reversed(children):
            hard_stop(child)
        shutil.rmtree(root)


if __name__ == "__main__":
    try:
        run()
    except Exception as e:
        print("S7E2A_FAILED: " + str(e), file=sys.stderr)
        sys.exit(1)