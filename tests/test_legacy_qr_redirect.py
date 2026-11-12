#!/usr/bin/env python3
"""Run host-only legacy-QR redirect tests using Apache 2.4, not simulated rewrite semantics."""
from __future__ import annotations
import argparse
import http.client
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
BUILDER = ROOT / 'hosting/legacy-qr/build_legacy_qr_fragment.py'


def check(condition, name):
    if not condition:
        raise AssertionError(name)
    print('PASS', name)


def reserve_port():
    with socket.socket() as s:
        s.bind(('127.0.0.1', 0))
        return s.getsockname()[1]


def request(port, host, path):
    connection = http.client.HTTPConnection('127.0.0.1', port, timeout=5)
    connection.request('GET', path, headers={'Host': host})
    response = connection.getresponse()
    status, location, body = response.status, response.getheader('Location'), response.read()
    connection.close()
    return status, location, body


def apache_run(status, runner, runner_path):
    root = runner / f'host-{status}'
    root.mkdir(parents=True)
    (root / 'health.txt').write_text('HELLO\n')
    subprocess.run(['python3', str(BUILDER), '--status', str(status), '--output', str(root / 'redirect.frag')], check=True)
    snippet = (root / 'redirect.frag').read_text()
    check('https://www.soknaspace.ir/menu' in snippet, f'target_exact_{status}')
    (root / '.htaccess').write_text(snippet)
    # Minimal temporary Apache, loopback-only, isolated from any café/production webroot.
    port = reserve_port()
    conf = runner / f'httpd-{status}.conf'
    conf.write_text('\n'.join([
        'ServerRoot "/etc/apache2"', 'ServerName localhost', f'Listen 127.0.0.1:{port}',
        f'PidFile "{runner / f"apache-{status}.pid"}"',
        f'ErrorLog "{runner / f"apache-{status}-error.log"}"',
        'LogLevel warn',
        'LoadModule mpm_event_module /usr/lib/apache2/modules/mod_mpm_event.so',
        'LoadModule authz_core_module /usr/lib/apache2/modules/mod_authz_core.so',
        'LoadModule rewrite_module /usr/lib/apache2/modules/mod_rewrite.so',
        'LoadModule dir_module /usr/lib/apache2/modules/mod_dir.so',
        f'DocumentRoot "{root}"',
        f'<Directory "{root}">',
        '    Options FollowSymLinks', '    AllowOverride FileInfo',
        '    Require all granted', '</Directory>',
        'DirectoryIndex index.html', ''
    ]))
    subprocess.run([runner_path, '-f', str(conf), '-t'], check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    p = subprocess.Popen([runner_path, '-f', str(conf), '-DFOREGROUND'], stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
    try:
        for _ in range(80):
            try:
                request(port, 'soknaspace.ir', '/health.txt')
                break
            except (ConnectionError, TimeoutError, OSError):
                if p.poll() is not None:
                    raise RuntimeError(f'Apache exited: {p.stderr.read().decode(errors="replace")}')
                time.sleep(.05)
        else:
            raise RuntimeError('Apache did not start')
        for path in ['/Menu/', '/Menu']:
            s, loc, _ = request(port, 'www.soknaspace.ir', path)
            check((s, loc) == (status, 'https://www.soknaspace.ir/menu'), f'{status}_www_{path}')
        for hostname in ['soknaspace.ir', 'www.soknaspace.ir']:
            s, loc, _ = request(port, hostname, '/Menu/?table=alpha&source=printed')
            check(s == status and loc == 'https://www.soknaspace.ir/menu?table=alpha&source=printed', f'{status}_preserve_query_{hostname}')
        for host in ['soknagroup.ir', 'www.soknagroup.ir', 'evil-soknaspace.ir']:
            s, loc, _ = request(port, host, '/Menu/')
            check(s != status and not loc, f'{status}_not_other_host_{host}')
        for path in ['/menu', '/menu/', '/MENU/', '/operations/', '/api/guest/order', '/public/menu']:
            s, loc, _ = request(port, 'www.soknaspace.ir', path)
            check(s != status and not loc, f'{status}_not_other_path_{path}')
        s, _, b = request(port, 'www.soknaspace.ir', '/health.txt')
        check(s == 200 and b == b'HELLO\n', f'{status}_other_files_undisturbed')
    finally:
        p.terminate()
        try:
            p.communicate(timeout=5)
        except subprocess.TimeoutExpired:
            p.kill()
            p.communicate()


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--apache', default=shutil.which('apache2') or shutil.which('httpd'))
    args = parser.parse_args()
    check(bool(args.apache), 'apache_binary_present')
    with tempfile.TemporaryDirectory(prefix='sokna-qr-apache-') as tmp:
        for code in (302, 301):
            apache_run(code, Path(tmp), args.apache)
    print('PASS Apache real HTTP redirect contract 302 and 301')
