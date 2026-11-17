#!/usr/bin/env python3
"""Run as an unprivileged user on a DEDICATED builder, never the control plane.
No fleet/provider credentials or control-plane mounts are present on this machine.
Registry credentials are scoped to the builder, outside the build context.
"""
import fcntl
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile


def run(args, timeout=1800):
    p = subprocess.run(args, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                       stderr=subprocess.PIPE, timeout=timeout, check=False)
    if p.returncode:
        raise RuntimeError('builder step failed: '+args[0])
    return p.stdout.decode().strip()


def build(p):
    for value, pattern in [(p['operation'], r'[a-f0-9]{32}'), (p['repository'], r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+'),
                           (p['commit'], r'[a-f0-9]{40}'), (p['tag'], r'[a-z0-9][a-z0-9.:/_-]+:[a-z0-9._-]+')]:
        if not re.fullmatch(pattern, value):
            raise ValueError('invalid builder input')
    root = Path.home()/'.local/state/tc-build'
    root.mkdir(parents=True, exist_ok=True, mode=0o700)
    result = root/(p['operation']+'.json')
    with open(root/'lock', 'a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        if result.exists():
            return json.loads(result.read_text())
        with tempfile.TemporaryDirectory(prefix='tc-build-') as directory:
            work = Path(directory)
            source = work/'src'
            run(['git', 'init', str(source)])
            run(['git', '-C', str(source), 'remote', 'add', 'origin', 'https://github.com/'+p['repository']+'.git'])
            run(['git', '-C', str(source), '-c', 'protocol.file.allow=never', 'fetch', '--depth=1', 'origin', p['commit']])
            run(['git', '-C', str(source), 'checkout', '--detach', 'FETCH_HEAD'])
            if run(['git', '-C', str(source), 'rev-parse', 'HEAD']) != p['commit']:
                raise RuntimeError('source commit mismatch')
            for path in source.rglob('*'):
                if path.is_symlink() and not path.resolve().is_relative_to(source):
                    raise RuntimeError('source symlink escapes context')
            if not (source/'public/index.php').is_file() or not (source/'config/config.php').is_file():
                raise RuntimeError('source must be a Trongate application')
            dockerfile = p['dockerfile']
            if (source/'Caddyfile').is_file():
                dockerfile += 'COPY Caddyfile /Caddyfile\n'
            (work/'Dockerfile').write_text(dockerfile)
            # Never use a repository Dockerfile. Rootless Podman owns an isolated namespace.
            run(['systemd-run', '--user', '--scope', '--quiet', '--property=MemoryMax=2G',
                 '--property=CPUQuota=200%', '--property=TasksMax=512',
                 'podman', 'build', '--memory=2g', '--cpu-quota=200000',
                 '--security-opt=no-new-privileges', '--tag', p['tag'],
                 '--file', str(work/'Dockerfile'), str(source)])
            digest_file = work/'digest'
            run(['podman', 'push', '--digestfile', str(digest_file), p['tag']])
            digest = digest_file.read_text().strip()
            if not re.fullmatch(r'sha256:[a-f0-9]{64}', digest):
                raise RuntimeError('registry did not return a digest')
            output = {'status': 'succeeded', 'image': p['tag'].rsplit(':', 1)[0]+'@'+digest, 'commit': p['commit']}
            result.write_text(json.dumps(output))
            return output


if __name__ == '__main__':
    os.umask(0o077)
    try:
        print(json.dumps(build(json.load(sys.stdin))))
    except Exception as exc:
        print(json.dumps({'status': 'uncertain', 'error': type(exc).__name__}))
