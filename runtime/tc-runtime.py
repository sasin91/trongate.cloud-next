#!/usr/bin/env python3
"""Root-owned host helper. JSON on stdin; never executes user-provided shell text.

All mutations share a host flock. Journals and a persistent host reservation survive
SSH/worker failure. A previously started migration is never automatically repeated.
"""
import contextlib
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time
import urllib.request


class Runtime:
    def __init__(self, root='/var/lib/tc/runtime'):
        self.root = Path(root)
        self.root.mkdir(parents=True, exist_ok=True, mode=0o700)
        for name in ['operations', 'apps', 'routes', 'roots']:
            (self.root / name).mkdir(exist_ok=True, mode=0o700)

    def run(self, args, timeout=120):
        # Customer process output can contain secrets. Only exit codes enter journals.
        p = subprocess.run(args, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                           stderr=subprocess.PIPE, timeout=timeout, check=False)
        if p.returncode:
            raise RuntimeError('controlled command failed: ' + args[0] + ' exit=' + str(p.returncode))
        return p.stdout.decode(errors='replace').strip()

    def write(self, path, value):
        path = Path(path)
        path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        temporary = path.with_suffix(path.suffix + '.tmp')
        with open(temporary, 'w', encoding='utf-8') as f:
            os.chmod(temporary, 0o600)
            f.write(json.dumps(value, sort_keys=True) if not isinstance(value, str) else value)
            f.flush()
            os.fsync(f.fileno())
        os.replace(temporary, path)
        fd = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)

    def read(self, path, default=None):
        return json.loads(Path(path).read_text()) if Path(path).exists() else default

    @staticmethod
    def match(value, expression):
        if not isinstance(value, str) or not re.fullmatch(expression, value):
            raise ValueError('invalid runtime input')
        return value

    def health(self, url, attempts=20):
        for _ in range(attempts):
            try:
                # curl has a hard timeout and validates TLS for public checks.
                self.run(['curl', '--fail', '--silent', '--max-time', '5', '--output', '/dev/null', url], 10)
                return True
            except (RuntimeError, subprocess.TimeoutExpired):
                time.sleep(1)
        return False

    def proxy(self):
        text = '{\n admin 127.0.0.1:2019\n}\n' + '\n'.join(p.read_text() for p in sorted((self.root/'routes').glob('*.caddy')))
        self.write(self.root/'Caddyfile', text)
        self.run(['caddy', 'validate', '--config', str(self.root/'Caddyfile'), '--adapter', 'caddyfile'])
        self.run(['caddy', 'reload', '--config', str(self.root/'Caddyfile'), '--adapter', 'caddyfile'])

    def route(self, app, release):
        route = self.root/'routes'/f'{app}.caddy'
        if release is None:
            route.unlink(missing_ok=True)
        else:
            domain = self.match(release['domain'], r'[a-z0-9]+(?:[.-][a-z0-9]+)+')
            self.write(route, f'{domain} {{\n reverse_proxy 127.0.0.1:{int(release["port"])}\n}}\n')
        self.proxy()

    def stop(self, release):
        if release:
            self.run(['systemctl', 'disable', '--now', release['unit']])

    def start(self, release):
        self.run(['systemctl', 'enable', '--now', release['unit']])

    def healthy(self, release):
        return release is not None and self.health(f'http://127.0.0.1:{release["port"]}{release["health"]}')

    def stage(self, app, operation, spec, secrets):
        image = self.match(spec['image'], r'[a-z0-9][a-z0-9./:_-]+@sha256:[a-f0-9]{64}')
        self.match(spec['health'], r'/[a-zA-Z0-9/_.-]*')
        name = 'tc-' + app + '-' + operation
        directory = self.root/'apps'/app/'releases'/operation
        directory.mkdir(parents=True, exist_ok=True, mode=0o700)
        env = ''
        for key, value in secrets.items():
            self.match(key, r'[A-Z_][A-Z0-9_]*')
            if not isinstance(value, str) or any(c in value for c in '\r\n\0'):
                raise ValueError('environment secrets must be single-line strings')
            env += key + '=' + value + '\n'
        self.write(directory/'environment', env)
        self.run(['podman', 'pull', image], 600)
        # Allocate a free port through Podman, never a racy application-side scan.
        args = ['podman', 'create', '--name', name, '--label', 'tc.operation='+operation,
                '--publish', '127.0.0.1::'+str(int(spec['container_port'])),
                '--memory', str(int(spec['memory_mb']))+'m', '--cpus', str(int(spec['cpus'])),
                '--pids-limit', '256', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges',
                '--env', 'PORT='+str(int(spec['container_port'])), '--env-file', str(directory/'environment')]
        for volume, target in spec.get('mounts', {}).items():
            self.match(volume, r'[a-z][a-z0-9-]{0,39}')
            self.match(target, r'/app/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+')
            data = self.root/'apps'/app/'data'/volume
            data.mkdir(parents=True, exist_ok=True, mode=0o750)
            args.extend(['--volume', str(data)+':'+target])
        args.append(image)
        self.run(args)
        unit = name+'.service'
        unit_path = Path('/etc/systemd/system')/unit
        podman = self.run(['which', 'podman'])
        self.write(unit_path, '[Unit]\nAfter=network-online.target\nWants=network-online.target\n'
                   '[Service]\nRestart=always\nRestartSec=3\nTimeoutStopSec=45\n'
                   f'ExecStart={podman} start --attach {name}\nExecStop={podman} stop --time 30 {name}\n'
                   '[Install]\nWantedBy=multi-user.target\n')
        self.run(['systemctl', 'daemon-reload'])
        self.start({'unit': unit})
        inspection = json.loads(self.run(['podman', 'inspect', name]))[0]
        port = int(inspection['NetworkSettings']['Ports'][str(spec['container_port'])+'/tcp'][0]['HostPort'])
        release = {k: v for k, v in spec.items() if k != 'secrets'}
        release.update({'operation': operation, 'unit': unit, 'container': name, 'port': port})
        self.write(directory/'release.json', release)
        return release

    def app_operation(self, request):
        operation = self.match(request['operation'], r'[a-f0-9]{32}')
        app = self.match(request['app'], r'[a-z][a-z0-9-]{0,39}')
        journal_path = self.root/'operations'/(operation+'.json')
        state_path = self.root/'apps'/app/'state.json'
        state = self.read(state_path, {'active': None, 'previous': None})
        journal = self.read(journal_path)
        if journal:
            if journal['status'] in ('succeeded', 'failed'):
                return journal
            # Never repeat a mutation after a broken session. Restore recorded route,
            # or report uncertainty if a migration/host event needs operator review.
            return self.recover(app, journal_path, state_path, journal)
        previous = state['active']
        journal = {'operation': operation, 'status': 'running', 'phase': 'intent', 'before': state}
        self.write(journal_path, journal)
        try:
            if request['action'] == 'rollback':
                candidate = state['previous']
                if not candidate:
                    raise RuntimeError('no retained previous release')
                self.start(candidate)
            else:
                spec = request['release']
                # A domain belongs to exactly one application on a host.
                for other in (self.root/'apps').glob('*/state.json'):
                    active = self.read(other, {}).get('active')
                    if other.parent.name != app and active and active['domain'] == spec['domain']:
                        raise RuntimeError('domain is already assigned')
                candidate = self.stage(app, operation, spec, request.get('secret_values', {}))
            journal.update({'candidate': candidate, 'phase': 'candidate'})
            self.write(journal_path, journal)
            if request['action'] != 'rollback' and request['release'].get('migration'):
                if request['release'].get('schema_compatible') is not True:
                    raise RuntimeError('migration must be backward compatible')
                journal['phase'] = 'migration-started'
                self.write(journal_path, journal)
                self.run(['podman', 'exec', candidate['container']] + request['release']['migration'], 600)
                journal['phase'] = 'migration-completed'
                journal['migration'] = 'completed; application rollback does not undo data changes'
                self.write(journal_path, journal)
            if not self.healthy(candidate):
                raise RuntimeError('candidate health check failed')
            journal['phase'] = 'switching'
            self.write(journal_path, journal)
            self.route(app, candidate)
            if not self.healthy(candidate):
                raise RuntimeError('health check failed after traffic switch')
            if request.get('public_check', True) and not self.health('https://'+candidate['domain']+candidate['health'], 3):
                raise RuntimeError('public health check failed')
            self.write(state_path, {'active': candidate, 'previous': previous})
            journal.update({'status': 'succeeded', 'phase': 'accepted', 'active': candidate['operation']})
            self.write(journal_path, journal)
            # Accepted release is durable before retirement. A retirement error must
            # not misreport the healthy active route as failed.
            try:
                if previous and previous['operation'] != candidate['operation']:
                    self.stop(previous)
            except Exception:
                journal['retirement'] = 'previous process still running; safe to retire later'
                self.write(journal_path, journal)
            return journal
        except Exception as e:
            journal['error'] = str(e) if isinstance(e, (RuntimeError, ValueError)) else type(e).__name__
            self.write(journal_path, journal)
            return self.recover(app, journal_path, state_path, journal)

    def recover(self, app, journal_path, state_path, journal):
        previous = journal['before']['active']
        try:
            if previous:
                self.start(previous)
                if not self.healthy(previous):
                    raise RuntimeError('previous release unhealthy')
            self.route(app, previous)
            # Check the restored public route when one previously existed.
            if previous and not self.health('https://'+previous['domain']+previous['health'], 3):
                raise RuntimeError('restored route not verified publicly')
            self.write(state_path, journal['before'])
            candidate = journal.get('candidate')
            if candidate and (not previous or candidate['operation'] != previous['operation']):
                self.stop(candidate)
            journal['status'] = 'uncertain' if journal['phase'] == 'migration-started' else 'failed'
            journal['restored'] = previous['operation'] if previous else None
        except Exception:
            journal['status'] = 'uncertain'
            journal['recovery'] = 'cannot verify restored route; operator reconciliation required'
        self.write(journal_path, journal)
        return journal

    def host_inspect(self):
        current = os.path.realpath('/run/current-system')
        healthy = True
        try:
            self.run(['systemctl', 'is-active', 'sshd', 'tc-proxy'])
        except Exception:
            healthy = False
        return {'generation': current, 'services_healthy': healthy,
                'reservation': self.read(self.root/'host-reservation.json')}

    def host(self, req):
        action = req['action']
        if action == 'host-inspect':
            return self.host_inspect()
        operation = self.match(req['operation'], r'[a-f0-9]{32}')
        reservation_path = self.root/'host-reservation.json'
        reservation = self.read(reservation_path)
        if reservation and reservation['operation'] != operation:
            return {'status': 'uncertain', 'reason': 'host reserved by another operation'}
        if action == 'host-begin':
            if reservation:
                return {'status': 'uncertain', 'reason': 'prior host activation requires reconciliation', **self.host_inspect()}
            before = self.host_inspect()['generation']
            self.match(before, r'/nix/store/[a-z0-9]{32}-nixos-system-[A-Za-z0-9._-]+')
            self.run(['nix-store', '--add-root', str(self.root/'roots'/operation), '--realise', before])
            self.write(reservation_path, {'operation': operation, 'previous_generation': before})
            return {'status': 'reserved', 'previous_generation': before}
        if action == 'host-rollback':
            generation = self.match(req['generation'], r'/nix/store/[a-z0-9]{32}-nixos-system-[A-Za-z0-9._-]+')
            # Rollback targets must be retained by this product, never arbitrary paths.
            if generation not in [os.path.realpath(p) for p in (self.root/'roots').iterdir()]:
                raise ValueError('generation is not retained')
            self.write(reservation_path, {'operation': operation, 'previous_generation': self.host_inspect()['generation']})
            self.run(['nix-env', '--profile', '/nix/var/nix/profiles/system', '--set', generation])
            self.run([generation+'/bin/switch-to-configuration', 'switch'], 600)
            req['expected'] = generation
        observed = self.host_inspect()
        expected = req.get('expected')
        if observed['generation'] == expected and observed['services_healthy']:
            reservation_path.unlink(missing_ok=True)
            return {'status': 'succeeded', **observed}
        return {'status': 'uncertain', **observed}

    def dispatch(self, request):
        with open(self.root/'operation.lock', 'a') as lock:
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                return {'status': 'uncertain', 'reason': 'remote operation still executing'}
            if request['action'].startswith('host-'):
                return self.host(request)
            if (self.root/'host-reservation.json').exists():
                return {'status': 'uncertain', 'reason': 'host activation reserved'}
            if request['action'] == 'inspect':
                return {'status': 'succeeded', 'applications': [self.read(p) for p in (self.root/'apps').glob('*/state.json')]}
            if request['action'] not in ('deploy', 'rollback'):
                raise ValueError('unknown operation')
            return self.app_operation(request)


if __name__ == '__main__':
    os.umask(0o077)
    try:
        data = sys.stdin.read(1048577)
        if len(data) > 1048576:
            raise ValueError('request too large')
        print(json.dumps(Runtime().dispatch(json.loads(data))))
    except Exception as exc:
        # Never print request bodies, credentials or customer output.
        print(json.dumps({'status': 'uncertain', 'error': type(exc).__name__}))
