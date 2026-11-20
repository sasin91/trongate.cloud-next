"""Host-authoritative application budgets. Called under Runtime's operation lock."""
import math
import os
import time
from pathlib import Path


MIB = 1024 ** 2
MIN_MEMORY = 256
MIN_CPU = 0.05


class Resources:
    def __init__(self, runtime):
        self.runtime = runtime
        self.path = runtime.root / 'resources.json'
        self.pending = runtime.root / 'resources-pending.json'

    def host(self):
        memory = {line.split(':')[0]: int(line.split()[1]) // 1024
                  for line in Path('/proc/meminfo').read_text().splitlines()}
        config = self.runtime.read('/etc/tc-resources.json')
        if config is None:
            raise RuntimeError('Upgrade this server runtime before allocating application resources.')
        return dict(memory_mb=memory['MemTotal'], available_mb=memory['MemAvailable'],
                    cpus=os.cpu_count(), **config)

    def containers(self):
        """Inspect only managed releases; never return environment or customer output."""
        import json
        result = []
        for path in sorted((self.runtime.root / 'apps').glob('*/releases/*/release.json')):
            release = self.runtime.read(path)
            name = release['container']
            self.runtime.match(name, r'tc-[a-z][a-z0-9-]{0,39}-[a-f0-9]{32}')
            info = json.loads(self.runtime.run(['podman', 'inspect', name]))[0]
            running = info['State']['Running']
            used = 0
            cpu_usec = None
            effective_memory = None
            effective_quota = None
            if running:
                group = Path('/sys/fs/cgroup') / info['State']['CgroupPath'].lstrip('/')
                if not group.resolve().is_relative_to(Path('/sys/fs/cgroup')):
                    raise RuntimeError('Could not verify container resource usage.')
                used = math.ceil(int((group / 'memory.current').read_text()) / MIB)
                cpu_usec = int(dict(line.split() for line in (group / 'cpu.stat').read_text().splitlines())['usage_usec'])
                memory_max = (group / 'memory.max').read_text().strip()
                effective_memory = int(memory_max) // MIB if memory_max != 'max' else 0
                quota, period = (group / 'cpu.max').read_text().split()
                effective_quota = int(quota) / int(period) if quota != 'max' else 0
            limits = info['HostConfig']
            result.append({'app': path.parents[2].name, 'container': name, 'running': running,
                           'used_mb': used, 'memory_mb': limits['Memory'] // MIB,
                           'cpu_usec': cpu_usec, 'sample_ns': time.monotonic_ns(),
                           'effective_memory_mb': effective_memory, 'effective_quota': effective_quota,
                           'shares': limits['CpuShares'],
                           'quota': max(0, limits['CpuQuota']) / (limits['CpuPeriod'] or 100000)})
        return result

    def apps(self, incoming=None, containers=None):
        apps = {item['app'] for item in (self.containers() if containers is None else containers) if item['running']}
        for directory in (self.runtime.root / 'apps').iterdir():
            state = self.runtime.read(directory / 'state.json', {})
            if state.get('active') or (directory / 'staged.json').exists():
                apps.add(directory.name)
        if incoming:
            apps.add(incoming)
        return sorted(apps)

    @staticmethod
    def plan(host, apps, policies):
        memory = host['memory_mb'] - host['system_memory_mb'] - host['database_memory_mb'] - host['build_memory_mb']
        cpu = round(host['cpus'] - host['system_cpus'] - host['database_cpus'] - host['build_cpus'], 2)
        manual = {app: policies[app] for app in apps if policies.get(app, {}).get('mode') == 'manual'}
        automatic = [app for app in apps if app not in manual]
        remaining_memory = memory - 2 * sum(item['memory_mb'] for item in manual.values())
        remaining_cpu = round(cpu - 2 * sum(item['cpus'] for item in manual.values()), 2)
        if remaining_memory < 2 * len(automatic) * MIN_MEMORY or remaining_cpu < 2 * len(automatic) * MIN_CPU:
            raise RuntimeError('Not enough capacity for two versions of every app. Reduce manual allocations, move builds to a dedicated server, or use a larger server.')
        count = max(1, len(automatic))
        default_memory = remaining_memory // (2 * count)
        default_cpu = math.floor((remaining_cpu + 1e-8) * 100 / (2 * count)) / 100
        allocations = {app: dict(manual[app]) if app in manual else
                       {'mode': 'automatic', 'memory_mb': default_memory, 'cpus': default_cpu} for app in apps}
        return {'memory_budget_mb': memory, 'cpu_budget': cpu, 'allocations': allocations,
                'reserved_memory_mb': 2 * sum(item['memory_mb'] for item in allocations.values()),
                'reserved_cpus': round(2 * sum(item['cpus'] for item in allocations.values()), 2)}

    @staticmethod
    def policy(request):
        mode = request.get('mode')
        if mode == 'automatic':
            return {'mode': mode}
        memory, cpu = request.get('memory_mb'), request.get('cpus')
        if (mode != 'manual' or type(memory) is not int or memory < MIN_MEMORY or
                type(cpu) not in (int, float) or not math.isfinite(cpu) or cpu < MIN_CPU or round(cpu, 2) != cpu):
            raise RuntimeError('Use automatic allocation, or at least 256 MiB and 0.05 CPU shares (two decimal places).')
        return {'mode': mode, 'memory_mb': memory, 'cpus': cpu}

    def snapshot(self):
        host = self.host()
        policies = self.runtime.read(self.path, {})
        containers = self.containers()
        apps = self.apps(containers=containers)
        cpu_usage = {}
        if any(item.get('cpu_usec') is not None for item in containers):
            time.sleep(0.2)
            second = {item['container']: item for item in self.containers()}
            for item in containers:
                after = second.get(item['container'], {})
                if item.get('cpu_usec') is not None and after.get('cpu_usec') is not None:
                    percent = max(0, (after['cpu_usec'] - item['cpu_usec']) * 100000 / (after['sample_ns'] - item['sample_ns']))
                    cpu_usage[item['app']] = cpu_usage.get(item['app'], 0) + percent
        error = None
        try:
            plan = self.plan(host, apps, policies)
        except RuntimeError as exception:
            plan = {'allocations': {},
                    'memory_budget_mb': host['memory_mb'] - host['system_memory_mb'] - host['database_memory_mb'] - host['build_memory_mb'],
                    'cpu_budget': round(host['cpus'] - host['system_cpus'] - host['database_cpus'] - host['build_cpus'], 2)}
            error = str(exception)
        rows = []
        for app in apps:
            running = [item for item in containers if item['app'] == app and item['running']]
            # A manual edit may redistribute automatic apps down to their minimum.
            others = [name for name in apps if name != app]
            other_memory = sum(policies.get(name, {}).get('memory_mb', MIN_MEMORY) if policies.get(name, {}).get('mode') == 'manual' else MIN_MEMORY for name in others)
            other_cpu = sum(policies.get(name, {}).get('cpus', MIN_CPU) if policies.get(name, {}).get('mode') == 'manual' else MIN_CPU for name in others)
            budget_memory = host['memory_mb'] - host['system_memory_mb'] - host['database_memory_mb'] - host['build_memory_mb']
            budget_cpu = host['cpus'] - host['system_cpus'] - host['database_cpus'] - host['build_cpus']
            rows.append({'app': app, 'mode': policies.get(app, {}).get('mode', 'automatic'),
                         'allocation': plan['allocations'].get(app), 'versions': len(running),
                         'used_mb': sum(item['used_mb'] for item in running),
                         'cpu_percent': round(cpu_usage[app], 1) if app in cpu_usage else None,
                         'limits_mb': [item.get('effective_memory_mb', item['memory_mb']) for item in running],
                         'cpu_shares': [round((item['shares'] or 1024) / 1024, 2) for item in running],
                         'cpu_caps': [item['quota'] if 0 < item['quota'] < os.cpu_count() else 0 for item in running],
                         'max_memory_mb': max(0, budget_memory // 2 - other_memory),
                         'max_cpus': max(0, math.floor((budget_cpu / 2 - other_cpu + 1e-8) * 100) / 100)})
        return {'status': 'succeeded', 'host': host, 'apps': rows, 'error': error,
                'pending_recovery': self.pending.exists(), **plan}

    def update(self, container, memory, shares, quota=0):
        # Podman can silently retain an existing quota when given 0 or -1.
        # A quota equal to the entire host allows full-host bursting and is
        # verifiably applied. New containers have no quota; shares govern both.
        self.runtime.run(['podman', 'update', '--memory', str(memory) + 'm',
                          '--memory-swap', str(memory * 2) + 'm',
                          '--cpus', str(quota or os.cpu_count()),
                          '--cpu-shares', str(max(2, shares or 1024)), container])
        observed = next(item for item in self.containers() if item['container'] == container)
        if observed['memory_mb'] != memory or observed['shares'] != max(2, shares or 1024) or observed['quota'] != (quota or os.cpu_count()):
            raise RuntimeError('The container runtime did not apply the requested limits. Resource recovery is required.')
        if observed['running'] and (observed['effective_memory_mb'] != memory or observed['effective_quota'] != (quota or os.cpu_count())):
            raise RuntimeError('Kernel limits differ from the requested allocation. Resource recovery is required.')

    def recover(self):
        pending = self.runtime.read(self.pending)
        if pending:
            # Safe retry after a lost SSH session. Undo increases before restoring reductions.
            for item in sorted(pending['before'], key=lambda item: item['memory_mb']):
                current = next(row for row in self.containers() if row['container'] == item['container'])
                if current['running'] and item['memory_mb'] < current['memory_mb'] and item['memory_mb'] < math.ceil(current['used_mb'] * 1.25) + 32:
                    raise RuntimeError('Resource recovery needs attention: current usage is too high to safely restore the previous limit. No further allocations can be applied until usage falls.')
                self.update(item['container'], item['memory_mb'], item['shares'], item['quota'])
            self.runtime.write(self.path, pending['policies'])
            self.pending.unlink()

    def apply(self, incoming=None, change=None, starting=False):
        self.recover()
        host = self.host()
        old = self.runtime.read(self.path, {})
        policies = dict(old)
        if change:
            if change['app'] not in self.apps():
                raise RuntimeError('Deploy this app before adjusting its resources.')
            policies[change['app']] = self.policy(change)
        observed = self.containers()
        apps = self.apps(incoming, observed)
        plan = self.plan(host, apps, policies)
        containers = [item for item in observed if item['app'] in apps]
        for app in apps:
            running = [item for item in containers if item['app'] == app and item['running']]
            if len(running) + (1 if starting and incoming == app else 0) > 2:
                raise RuntimeError('Two versions of ' + app + ' are already running. Finish or reconcile its deployment first.')
        for item in containers:
            target = plan['allocations'][item['app']]['memory_mb']
            if item['running'] and target < item['memory_mb'] and target < math.ceil(item['used_mb'] * 1.25) + 32:
                raise RuntimeError('Cannot safely shrink ' + item['app'] + ': it uses ' + str(item['used_mb']) + ' MiB. Leave 25% plus 32 MiB headroom, reduce usage, or add capacity.')
        growth = sum(max(0, plan['allocations'][item['app']]['memory_mb'] - item['used_mb']) for item in containers if item['running'])
        if starting:
            growth += plan['allocations'][incoming]['memory_mb']
        if growth + 128 > host['available_mb']:
            raise RuntimeError('Available memory is too low to safely apply these limits. Wait for other workloads to finish or add capacity.')
        before = [item for item in containers if item['memory_mb'] != plan['allocations'][item['app']]['memory_mb'] or
                  item['shares'] != max(2, round(plan['allocations'][item['app']]['cpus'] * 1024)) or 0 < item['quota'] < os.cpu_count()]
        self.runtime.write(self.pending, {'before': before, 'policies': old})
        try:
            # Reductions first, so increasing another app cannot oversubscribe the pool.
            for item in sorted(before, key=lambda item: plan['allocations'][item['app']]['memory_mb'] - item['memory_mb']):
                target = plan['allocations'][item['app']]
                if item['running'] and target['memory_mb'] < item['memory_mb']:
                    current = next(row for row in self.containers() if row['container'] == item['container'])
                    if target['memory_mb'] < math.ceil(current['used_mb'] * 1.25) + 32:
                        raise RuntimeError('Memory usage increased during adjustment. Previous limits were restored; try again after usage falls.')
                self.update(item['container'], target['memory_mb'], round(target['cpus'] * 1024))
            self.runtime.write(self.path, policies)
            self.pending.unlink()
        except Exception:
            self.recover()
            raise
        return plan['allocations'].get(incoming) if incoming else plan
