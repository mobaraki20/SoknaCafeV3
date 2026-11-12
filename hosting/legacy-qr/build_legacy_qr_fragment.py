#!/usr/bin/env python3
"""Render a HOST-OWNED legacy QR redirect fragment; never mutate application webroots.

This tool emits a snippet to stdout or an explicitly designated output file.
It deliberately cannot edit a live .htaccess and cannot deploy to DirectAdmin.
"""
import argparse
import pathlib
import sys

def find_repo_root(start: pathlib.Path):
    for parent in (start, *start.parents):
        if (parent / 'apps' / 'local-web').is_dir() and (parent / 'apps' / 'public').is_dir():
            return parent
    return None

ROOT = find_repo_root(pathlib.Path(__file__).resolve())
TEMPLATE = pathlib.Path(__file__).with_name('.htaccess.fragment')


def render(status: int = 302) -> str:
    if status not in (301, 302):
        raise ValueError('Only 302 test or 301 permanent status is permitted')
    source = TEMPLATE.read_text(encoding='utf-8')
    assert 'R=302' in source and 'RewriteRule ^Menu/?$' in source
    if status == 301:
        source = source.replace('[R=302,L]', '[R=301,L]')
    return source


def main() -> int:
    p = argparse.ArgumentParser(description='Print host-only QR redirect snippet')
    p.add_argument('--status', type=int, choices=(301, 302), default=302)
    p.add_argument('--output', type=pathlib.Path)
    args = p.parse_args()
    snippet = render(args.status)
    if args.output is None:
        sys.stdout.write(snippet)
        return 0
    output = args.output.resolve()
    # Reject every file named .htaccess: do not replace/merge existing host or
    # release rules. Emit a fragment only; the operator/hosting control applies it.
    if output.name == '.htaccess':
        p.error('Refusing to write .htaccess; generate a fragment and merge only in host-owned configuration')
    if ROOT is not None:
        for managed in (ROOT / 'apps' / 'local-web', ROOT / 'apps' / 'public'):
            if output.is_relative_to(managed.resolve()):
                p.error('Refusing to write inside SOKNA-managed application tree')
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(snippet, encoding='utf-8')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
