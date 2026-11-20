#!/usr/bin/env python3
"""Run as an unprivileged build user, never on the control plane.
No fleet/provider credentials or control-plane mounts are present on this machine.
Registry credentials are scoped to the builder, outside the build context.
"""
import fcntl
import json
import os
from pathlib import Path
import re
import shutil
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
            recorded = json.loads(result.read_text())
            if recorded.get('status') != 'succeeded':
                return {'status': 'uncertain', 'reason': 'interrupted build; inspect builder before issuing a new operation'}
            return recorded
        free = shutil.disk_usage(root).free
        if free < 5 * 1024**3:
            return {'status': 'failed', 'reason': 'Build needs at least 5 GB of free disk space. Free space or select a dedicated builder.'}
        local = p.get('output') == 'archive'
        # Prune only this isolated build user's old dangling cache, never app images.
        run(['podman', 'image', 'prune', '--force', '--filter', 'until=24h'])
        # A lost SSH client can leave build containers running. Persist intent so
        # retrying the same operation cannot start a second unobserved build.
        result.write_text(json.dumps({'status': 'running', 'operation': p['operation']}))
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
            run(['systemd-run', '--user', '--scope', '--quiet', '--property=MemoryMax=' + ('1G' if local else '2G'),
                 '--property=CPUQuota=' + ('100%' if local else '200%'), '--property=TasksMax=512',
                 'podman', 'build', '--memory=' + ('1g' if local else '2g'), '--cpu-quota=' + ('100000' if local else '200000'), '--force-rm',
                 '--security-opt=no-new-privileges', '--tag', p['tag'],
                 '--file', str(work/'Dockerfile'), str(source)])
            if local:
                image_id = run(['podman', 'image', 'inspect', '--format', '{{.Id}}', p['tag']])
                image_id = image_id.removeprefix('sha256:')
                if not re.fullmatch(r'[a-f0-9]{64}', image_id):
                    raise RuntimeError('invalid local image ID')
                run(['podman', 'save', '--format', 'oci-archive', '--output', str(root/(p['operation']+'.oci')), p['tag']])
                image = p['tag'].rsplit(':', 1)[0]+'@sha256:'+image_id
                run(['podman', 'rmi', p['tag']])
            else:
                digest_file = work/'digest'
                auth = Path.home()/'.config/containers/auth.json'
                auth_args = ['--authfile', str(auth)] if auth.exists() else []
                run(['podman', 'push'] + auth_args + ['--digestfile', str(digest_file), p['tag']])
                digest = digest_file.read_text().strip()
                if not re.fullmatch(r'sha256:[a-f0-9]{64}', digest):
                    raise RuntimeError('registry did not return a digest')
                image = p['tag'].rsplit(':', 1)[0]+'@'+digest
            output = {'status': 'succeeded', 'image': image, 'commit': p['commit']}
            temporary = result.with_suffix('.tmp')
            with open(temporary, 'w') as handle:
                json.dump(output, handle)
                handle.flush()
                os.fsync(handle.fileno())
            os.replace(temporary, result)
            return output


if __name__ == '__main__':
    os.umask(0o077)
    try:
        request = json.load(sys.stdin)
        if request.get('action') == 'metrics':
            from tc_metrics import collect
            print(json.dumps(collect(Path.home())))
        else:
            print(json.dumps(build(request)))
    except Exception as exc:
        print(json.dumps({'status': 'uncertain', 'error': type(exc).__name__}))
