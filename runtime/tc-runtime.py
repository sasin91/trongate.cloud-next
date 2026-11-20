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
import shutil
import subprocess
import sys
import time
import urllib.request


class Runtime:
    def __init__(self, root='/var/lib/tc/runtime'):
        self.root = Path(root)
        self.root.mkdir(parents=True, exist_ok=True, mode=0o700)
        for name in ['operations', 'apps', 'routes', 'roots', 'units', 'enabled']:
            (self.root / name).mkdir(exist_ok=True, mode=0o700)

    def run(self, args, timeout=120):
        # Customer process output can contain secrets. Only exit codes enter journals.
        p = subprocess.run(args, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                           stderr=subprocess.PIPE, timeout=timeout, check=False)
        if p.returncode:
            raise RuntimeError('controlled command failed: ' + args[0] + ' exit=' + str(p.returncode))
        return p.stdout.decode(errors='replace').strip()

    def checkpoint(self, phase):
        callback = getattr(self, 'progress', None)
        if callback:
            callback(phase)

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
            (self.root/'enabled'/release['unit']).unlink(missing_ok=True)
            self.run(['systemctl', 'daemon-reload'])
            self.run(['systemctl', 'stop', release['unit']])

    def start(self, release):
        self.write(self.root/'enabled'/release['unit'], '')
        self.run(['systemctl', 'daemon-reload'])
        self.run(['systemctl', 'start', release['unit']])

    def healthy(self, release):
        return release is not None and self.health(f'http://127.0.0.1:{release["port"]}{release["health"]}')

    def stage(self, app, operation, spec, secrets, files=None, registry_auth=None):
        image = self.match(spec['image'], r'[a-z0-9][a-z0-9./:_-]+@sha256:[a-f0-9]{64}')
        self.match(spec['health'], r'/[a-zA-Z0-9/_.-]*')
        name = 'tc-' + app + '-' + operation
        directory = self.root/'apps'/app/'releases'/operation
        directory.mkdir(parents=True, exist_ok=True, mode=0o700)
        env = ''
        for key, value in (secrets or {}).items():
            self.match(key, r'[A-Z_][A-Z0-9_]*')
            if not isinstance(value, str) or any(c in value for c in '\r\n\0'):
                raise ValueError('environment secrets must be single-line strings')
            env += key + '=' + value + '\n'
        self.write(directory/'environment', env)
        auth_args = []
        auth_file = directory/'registry-auth'
        if registry_auth is not None:
            json.loads(registry_auth)
            self.write(auth_file, registry_auth)
            auth_args = ['--authfile', str(auth_file)]
        try:
            self.checkpoint('pulling-image')
            if image.startswith('localhost/tc/'):
                image_id = image.rsplit('@', 1)[1]
                observed = self.run(['podman', 'image', 'inspect', '--format', '{{.Id}}', image_id]).removeprefix('sha256:')
                if observed != image_id.removeprefix('sha256:'):
                    raise RuntimeError('local build image is missing; rebuild on this server')
                image = image_id
            else:
                self.run(['podman', 'pull']+auth_args+[image], 600)
        finally:
            auth_file.unlink(missing_ok=True)
        # Allocate a free port through Podman, never a racy application-side scan.
        args = ['podman', 'create', '--name', name, '--label', 'tc.operation='+operation,
                '--publish', '127.0.0.1::'+str(int(spec['container_port'])),
                '--memory', str(int(spec['memory_mb']))+'m',
                '--memory-swap', str(int(spec['memory_mb']) * 2)+'m',
                '--cpu-shares', str(max(2, round(spec['cpus'] * 1024))),
                # The inherited FrankenPHP binary carries cap_net_bind_service.
                # Retain just that capability so existing OCI images can execute it.
                '--pids-limit', '256', '--cap-drop', 'ALL', '--cap-add', 'NET_BIND_SERVICE', '--security-opt', 'no-new-privileges',
                '--env', 'PORT='+str(int(spec['container_port'])), '--env-file', str(directory/'environment')]
        for volume, target in (spec.get('mounts') or {}).items():
            self.match(volume, r'[a-z][a-z0-9-]{0,39}')
            self.match(target, r'/app/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+')
            data = self.root/'apps'/app/'data'/volume
            data.mkdir(parents=True, exist_ok=True, mode=0o750)
            args.extend(['--volume', str(data)+':'+target])
        for index, (target, content) in enumerate((files or {}).items()):
            self.match(target, r'/app/config/[A-Za-z0-9_-]+\.php')
            source = directory/('config-'+str(index))
            self.write(source, content)
            args.extend(['--volume', str(source)+':'+target+':ro'])
        args.append(image)
        self.checkpoint('creating-container')
        self.run(args)
        unit = name+'.service'
        unit_path = self.root/'units'/unit
        podman = self.run(['which', 'podman'])
        self.checkpoint('writing-unit')
        self.write(unit_path, '[Unit]\nAfter=network-online.target\nWants=network-online.target\n'
                   '[Service]\nRestart=always\nRestartSec=3\nTimeoutStopSec=45\n'
                   f'ExecStart={podman} start --attach {name}\nExecStop={podman} stop --time 30 {name}\n'
                   '[Install]\nWantedBy=multi-user.target\n')
        self.run(['systemctl', 'daemon-reload'])
        self.checkpoint('starting-candidate')
        self.start({'unit': unit})
        port = None
        for _ in range(25):
            inspection = json.loads(self.run(['podman', 'inspect', name]))[0]
            mapping = inspection.get('NetworkSettings', {}).get('Ports', {}).get(str(spec['container_port'])+'/tcp')
            if mapping:
                port = int(mapping[0]['HostPort'])
                break
            time.sleep(0.2)
        if port is None:
            raise RuntimeError('candidate port did not become available')
        release = {k: v for k, v in spec.items() if k != 'secrets'}
        release.update({'operation': operation, 'unit': unit, 'container': name, 'port': port})
        self.write(directory/'release.json', release)
        return release

    def allocate(self, app):
        from tc_resources import Resources
        return Resources(self).apply(incoming=app, starting=True)

    def app_operation(self, request):
        operation = self.match(request['operation'], r'[a-f0-9]{32}')
        app = self.match(request['app'], r'[a-z][a-z0-9-]{0,39}')
        journal_path = self.root/'operations'/(operation+'.json')
        state_path = self.root/'apps'/app/'state.json'
        state = self.read(state_path, {'active': None, 'previous': None})
        journal = self.read(journal_path)
        def progress(phase):
            if journal:
                journal['phase'] = phase
                journal.setdefault('events', []).append({'step': phase, 'at': int(time.time())})
                self.write(journal_path, journal)
        self.progress = progress
        if journal and journal['status'] == 'waiting':
            if not request.get('go_live'):
                return journal
            if state != journal['before']:
                raise RuntimeError('The active release changed while waiting to go live')
            try:
                return self.complete_candidate(app, state_path, journal_path, journal, request)
            except Exception as error:
                journal['error'] = str(error) if isinstance(error, RuntimeError) else type(error).__name__
                return self.recover(app, journal_path, state_path, journal)
        if journal:
            if journal['status'] in ('succeeded', 'failed'):
                return journal
            # Never repeat a mutation after a broken session. Restore recorded route,
            # or report uncertainty if a migration/host event needs operator review.
            return self.recover(app, journal_path, state_path, journal)
        staged = self.read(self.root/'apps'/app/'staged.json')
        if staged and staged['operation'] != operation:
            raise RuntimeError('Finish the staged deployment before starting another one')
        previous = state['active']
        journal = {'operation': operation, 'status': 'running', 'phase': 'intent', 'before': state,
                   'public_check': request.get('public_check', True), 'events': []}
        self.write(journal_path, journal)
        def progress(phase):
            journal['phase'] = phase
            journal['events'].append({'step': phase, 'at': int(time.time())})
            self.write(journal_path, journal)
        self.progress = progress
        try:
            if request['action'] == 'rollback':
                candidate = state['previous']
                if not candidate:
                    raise RuntimeError('no retained previous release')
                self.checkpoint('allocating-resources')
                self.allocate(app)
                self.start(candidate)
            else:
                spec = dict(request['release'])
                # A domain belongs to exactly one application on a host.
                for other in (self.root/'apps').iterdir():
                    if other.name == app:
                        continue
                    active = self.read(other/'state.json', {}).get('active')
                    staged_other = self.read(other/'staged.json', {})
                    pending = self.read(self.root/'operations'/(staged_other.get('operation', '')+'.json'), {}).get('candidate')
                    if any(item and item['domain'] == spec['domain'] for item in [active, pending]):
                        raise RuntimeError('domain is already assigned')
                self.checkpoint('allocating-resources')
                allocation = self.allocate(app)
                spec.update(memory_mb=allocation['memory_mb'], cpus=allocation['cpus'])
                candidate = self.stage(app, operation, spec, request.get('secret_values', {}), request.get('file_values', {}), request.get('registry_auth'))
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
            return self.complete_candidate(app, state_path, journal_path, journal, request)
        except Exception as e:
            journal['error'] = str(e) if isinstance(e, (RuntimeError, ValueError)) else type(e).__name__
            if isinstance(e, OSError):
                journal['errno'] = e.errno
            self.write(journal_path, journal)
            return self.recover(app, journal_path, state_path, journal)

    def complete_candidate(self, app, state_path, journal_path, journal, request):
        candidate = journal['candidate']
        previous = journal['before']['active']
        self.checkpoint('checking-local')
        if not self.healthy(candidate):
            raise RuntimeError('candidate health check failed')
        if request.get('defer_public') and request.get('public_check', True) and not previous and not request.get('go_live'):
            journal.update(status='waiting', phase='awaiting-go-live')
            self.write(self.root/'apps'/app/'staged.json', {'operation': journal['operation']})
            self.write(journal_path, journal)
            return journal
        journal['phase'] = 'switching'
        self.write(journal_path, journal)
        self.route(app, candidate)
        if not self.healthy(candidate):
            raise RuntimeError('health check failed after traffic switch')
        self.checkpoint('checking-public')
        if request.get('public_check', True) and not self.health('https://'+candidate['domain']+candidate['health'], 30):
            if request.get('defer_public'):
                # Removing a first route cancels Caddy's in-flight ACME issuance.
                # Keep it available after explicit Go live; a replacement still
                # restores the existing app while public verification is pending.
                if previous:
                    self.route(app, previous)
                journal.update(status='waiting', phase='awaiting-go-live', message='Public HTTPS verification is still pending. Certificate issuance may take a little longer; try again shortly.')
                self.write(self.root/'apps'/app/'staged.json', {'operation': journal['operation']})
                self.write(journal_path, journal)
                return journal
            raise RuntimeError('public health check failed')
        (self.root/'apps'/app/'staged.json').unlink(missing_ok=True)
        self.write(state_path, {'active': candidate, 'previous': previous})
        journal.pop('message', None)
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

    def recover(self, app, journal_path, state_path, journal):
        previous = journal['before']['active']
        try:
            if previous:
                self.start(previous)
                if not self.healthy(previous):
                    raise RuntimeError('previous release unhealthy')
            self.route(app, previous)
            # Check the restored public route when one previously existed.
            if previous and journal.get('public_check', True) and not self.health('https://'+previous['domain']+previous['health'], 3):
                raise RuntimeError('restored route not verified publicly')
            self.write(state_path, journal['before'])
            candidate = journal.get('candidate')
            if candidate and (not previous or candidate['operation'] != previous['operation']):
                self.stop(candidate)
            elif not candidate and journal.get('operation'):
                name = 'tc-'+app+'-'+journal['operation']
                marker = self.root/'enabled'/(name+'.service')
                marker.unlink(missing_ok=True)
                self.run(['systemctl', 'daemon-reload'])
                if (self.root/'units'/(name+'.service')).exists():
                    self.run(['systemctl', 'stop', name+'.service'])
                self.run(['podman', 'rm', '--force', '--ignore', name])
            journal['status'] = 'uncertain' if journal['phase'] == 'migration-started' else 'failed'
            journal['restored'] = previous['operation'] if previous else None
            if journal['status'] == 'failed':
                (self.root/'apps'/app/'staged.json').unlink(missing_ok=True)
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
        for state in (self.root/'apps').glob('*/state.json'):
            active = self.read(state, {}).get('active')
            if active and not self.healthy(active):
                healthy = False
        return {'generation': current, 'services_healthy': healthy,
                'reservation': self.read(self.root/'host-reservation.json')}

    def host(self, req):
        action = req['action']
        if action == 'host-inspect':
            return self.host_inspect()
        operation = self.match(req['operation'], r'[a-f0-9]{32}')
        journal_path = self.root/'operations'/('host-'+operation+'.json')
        terminal = self.read(journal_path)
        if terminal and terminal.get('status') in ('succeeded', 'failed'):
            observed = self.host_inspect()
            if observed['generation'] == terminal['generation'] and observed['services_healthy']:
                return terminal
            return {'status': 'uncertain', 'reason': 'host changed after recorded completion', **observed}
        reservation_path = self.root/'host-reservation.json'
        reservation = self.read(reservation_path)
        if reservation and reservation['operation'] != operation:
            return {'status': 'uncertain', 'reason': 'host reserved by another operation'}
        if action == 'host-reconcile':
            if not reservation or req.get('workers_stopped') is not True:
                return {'status': 'uncertain', 'reason': 'stop all workers before explicit host recovery'}
            for process in Path('/proc').glob('[0-9]*/cmdline'):
                try:
                    command = process.read_bytes()
                except OSError:
                    continue
                if b'activate-rs' in command or b'/bin/switch-to-configuration' in command:
                    return {'status': 'uncertain', 'reason': 'remote activation process still exists'}
            observed = self.host_inspect()
            if not observed['services_healthy']:
                return {'status': 'uncertain', **observed}
            if observed['generation'] == reservation.get('expected'):
                result = {'status': 'succeeded', **observed}
            elif observed['generation'] == reservation['previous_generation']:
                result = {'status': 'failed', 'restored_generation': observed['generation'], **observed}
            else:
                return {'status': 'uncertain', **observed}
            self.write(journal_path, result)
            reservation_path.unlink()
            return result
        if action == 'host-begin':
            if reservation:
                return {'status': 'uncertain', 'reason': 'prior host activation requires reconciliation', **self.host_inspect()}
            before = self.host_inspect()['generation']
            self.match(before, r'/nix/store/[a-z0-9]{32}-nixos-system-[A-Za-z0-9._-]+')
            self.run(['nix-store', '--add-root', str(self.root/'roots'/operation), '--realise', before])
            self.write(reservation_path, {'operation': operation, 'previous_generation': before,
                                         'expected': req.get('expected'), 'revision': req.get('revision')})
            return {'status': 'reserved', 'previous_generation': before}
        if action == 'host-rollback':
            if reservation:
                return {'status': 'uncertain', 'reason': 'interrupted rollback requires explicit reconciliation', **self.host_inspect()}
            generation = self.match(req['generation'], r'/nix/store/[a-z0-9]{32}-nixos-system-[A-Za-z0-9._-]+')
            # Rollback targets must be retained by this product, never arbitrary paths.
            if generation not in [os.path.realpath(p) for p in (self.root/'roots').iterdir()]:
                raise ValueError('generation is not retained')
            previous = self.host_inspect()['generation']
            self.run(['nix-store', '--add-root', str(self.root/'roots'/operation), '--realise', previous])
            self.write(reservation_path, {'operation': operation, 'previous_generation': previous, 'expected': generation})
            self.run(['nix-env', '--profile', '/nix/var/nix/profiles/system', '--set', generation])
            self.run([generation+'/bin/switch-to-configuration', 'switch'], 600)
            req['expected'] = generation
        observed = self.host_inspect()
        expected = req.get('expected')
        if observed['generation'] == expected and observed['services_healthy']:
            result = {'status': 'succeeded', **observed}
            self.write(journal_path, result)
            reservation_path.unlink(missing_ok=True)
            return result
        return {'status': 'uncertain', **observed}

    def local_build(self, request):
        operation = self.match(request['operation'], r'[a-f0-9]{32}')
        self.match(request['tag'], r'localhost/tc/[a-z][a-z0-9-]{0,39}:build-[a-f0-9]{24}')
        journal_path = self.root/'operations'/(operation+'.json')
        with open(self.root/'build.lock', 'a') as lock:
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                return {'status': 'pending', 'reason': 'Another local build is running.'}
            recorded = self.read(journal_path)
            if recorded and recorded.get('status') == 'succeeded':
                return recorded
            if shutil.disk_usage(self.root).free < 5 * 1024**3:
                return {'status': 'failed', 'reason': 'Free at least 5 GB on this server or use a dedicated builder.'}
            memory = {line.split(':')[0]: int(line.split()[1]) for line in Path('/proc/meminfo').read_text().splitlines()}
            if memory.get('MemAvailable', 0) < 1536 * 1024:
                return {'status': 'failed', 'reason': 'Local builds need 1.5 GB available memory. Use a dedicated builder or a larger server.'}
            plan = {key: request[key] for key in ['operation', 'repository', 'commit', 'tag', 'dockerfile']}
            plan['output'] = 'archive'
            # Build code executes in rootless containers with no runtime or app mounts.
            process = subprocess.run(['runuser', '-u', 'tc-builder', '--', 'env', '-i', 'HOME=/home/tc-builder',
                                      'USER=tc-builder', 'LOGNAME=tc-builder', 'PATH=/run/current-system/sw/bin',
                                      'XDG_RUNTIME_DIR=/run/user/1100', 'DBUS_SESSION_BUS_ADDRESS=unix:path=/run/user/1100/bus', 'tc-build'],
                                     input=json.dumps(plan), text=True, capture_output=True, timeout=3500, cwd='/home/tc-builder')
            if process.returncode:
                raise RuntimeError('Local builder interrupted')
            result = json.loads(process.stdout)
            if result.get('status') != 'succeeded':
                return result
            image = self.match(result['image'], r'localhost/tc/[a-z][a-z0-9-]{0,39}@sha256:[a-f0-9]{64}')
            archive = Path('/home/tc-builder/.local/state/tc-build')/(operation+'.oci')
            if not archive.is_file() or archive.is_symlink():
                raise RuntimeError('Local build archive is missing')
            self.run(['podman', 'load', '--input', str(archive)], 600)
            image_id = image.rsplit('@', 1)[1]
            actual = self.run(['podman', 'image', 'inspect', '--format', '{{.Id}}', image_id]).removeprefix('sha256:')
            if actual != image_id.removeprefix('sha256:'):
                raise RuntimeError('Imported image does not match build output')
            self.write(journal_path, result)
            archive.unlink()
            return result

    def cleanup(self):
        removed = 0
        for app_dir in (self.root/'apps').iterdir():
            state = self.read(app_dir/'state.json', {})
            keep = {release['operation'] for release in state.values() if isinstance(release, dict) and 'operation' in release}
            staged = self.read(app_dir/'staged.json', {})
            if staged:
                keep.add(staged['operation'])
            for path in (app_dir/'releases').glob('*/release.json'):
                release = self.read(path)
                operation = release['operation']
                journal = self.read(self.root/'operations'/(operation+'.json'), {})
                if operation in keep or journal.get('status') not in ('succeeded', 'failed') or path.stat().st_mtime > time.time() - 86400:
                    continue
                self.stop(release)
                self.run(['podman', 'rm', '--ignore', release['container']])
                (self.root/'units'/release['unit']).unlink(missing_ok=True)
                # No --force: Podman refuses removal while another retained container uses it.
                if release['image'].startswith('localhost/tc/'):
                    with contextlib.suppress(RuntimeError):
                        self.run(['podman', 'rmi', release['image'].rsplit('@', 1)[1]])
                shutil.rmtree(path.parent)
                removed += 1
        return {'status': 'succeeded', 'removed_releases': removed}

    def dispatch(self, request):
        if request['action'] == 'build':
            return self.local_build(request)
        if request['action'] == 'metrics':
            from tc_metrics import collect
            return collect(self.root, self)
        if request['action'] == 'status':
            operation = self.match(request['operation'], r'[a-f0-9]{32}')
            journal = self.read(self.root/'operations'/(operation+'.json'), {})
            return {key: journal[key] for key in ['status', 'phase', 'events'] if key in journal}
        with open(self.root/'operation.lock', 'a') as lock:
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                return {'status': 'uncertain', 'reason': 'remote operation still executing'}
            if request['action'].startswith('host-'):
                return self.host(request)
            if (self.root/'host-reservation.json').exists():
                return {'status': 'uncertain', 'reason': 'host activation reserved'}
            if request['action'] in ('resources', 'resources-update'):
                from tc_resources import Resources
                resources = Resources(self)
                if request['action'] == 'resources-update':
                    try:
                        resources.apply(change=request)
                    except RuntimeError as error:
                        return {'status': 'failed', 'error': str(error)}
                return resources.snapshot()
            if request['action'] == 'cleanup':
                return self.cleanup()
            if request['action'] == 'inspect':
                return {'status': 'succeeded', 'applications': [self.read(p) for p in (self.root/'apps').glob('*/state.json')]}
            if request['action'] not in ('deploy', 'rollback'):
                raise ValueError('unknown operation')
            return self.app_operation(request)


if __name__ == '__main__':
    os.umask(0o077)
    if sys.argv[1:] == ['--version']:
        print('1')
        sys.exit(0)
    try:
        data = sys.stdin.read(1048577)
        if len(data) > 1048576:
            raise ValueError('request too large')
        print(json.dumps(Runtime().dispatch(json.loads(data))))
    except Exception as exc:
        # Never print request bodies, credentials or customer output.
        print(json.dumps({'status': 'uncertain', 'error': type(exc).__name__}))
