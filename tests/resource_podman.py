"""Explicit integration check: python3 tests/resource_podman.py LOCAL_IMAGE_ID.

Uses disposable containers and a temporary runtime directory, never live app state.
The image must contain /bin/sh and sleep. No application entrypoint is executed.
"""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import uuid

sys.path.insert(0, str(Path(__file__).parents[1] / 'runtime'))
from tc_resources import Resources

spec = importlib.util.spec_from_file_location('runtime', Path(__file__).parents[1] / 'runtime/tc-runtime.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class FixtureResources(Resources):
    def host(self):
        return dict(memory_mb=4096, available_mb=3500, cpus=4, system_memory_mb=512,
                    database_memory_mb=512, build_memory_mb=1536,
                    system_cpus=0.25, database_cpus=0.25, build_cpus=1)


names = []
with tempfile.TemporaryDirectory(prefix='tc-resource-integration-') as directory:
    runtime = module.Runtime(directory)
    resources = FixtureResources(runtime)
    try:
        for app in ['resourcechecka', 'resourcecheckb']:
            operation = uuid.uuid4().hex
            name = 'tc-' + app + '-' + operation
            names.append(name)
            runtime.run(['podman', 'run', '--detach', '--name', name, '--memory', '256m',
                         '--cpus', '1', '--entrypoint', '/bin/sh', sys.argv[1], '-c', 'sleep 300'])
            runtime.write(runtime.root / 'apps' / app / 'state.json', {'active': {'operation': operation}})
            runtime.write(runtime.root / 'apps' / app / 'releases' / operation / 'release.json', {'container': name})
        resources.apply()
        for item in resources.containers():
            assert item['memory_mb'] == 384, item
            assert item['shares'] == round(0.62 * 1024), item
            info = json.loads(runtime.run(['podman', 'inspect', item['container']]))[0]
            group = Path('/sys/fs/cgroup') / info['State']['CgroupPath'].lstrip('/')
            quota, period = (group / 'cpu.max').read_text().split()
            assert quota == 'max' or int(quota) / int(period) >= os.cpu_count()
            assert int((group / 'memory.max').read_text()) == 384 * 1024 ** 2
        resources.apply(change={'app': 'resourcechecka', 'mode': 'manual', 'memory_mb': 512, 'cpus': 0.5})
        assert sorted(item['memory_mb'] for item in resources.containers()) == [256, 512]
        before = resources.containers()
        try:
            resources.apply(change={'app': 'resourcechecka', 'mode': 'manual', 'memory_mb': 999999, 'cpus': 0.5})
            raise AssertionError('Over-budget allocation was accepted')
        except RuntimeError:
            pass
        assert [item['memory_mb'] for item in resources.containers()] == [item['memory_mb'] for item in before]
        runtime.run(['podman', 'stop', '--time', '0', names[0]])
        resources.apply(change={'app': 'resourcechecka', 'mode': 'manual', 'memory_mb': 400, 'cpus': 0.2})
        runtime.run(['podman', 'start', names[0]])
        restarted = next(item for item in resources.containers() if item['container'] == names[0])
        assert restarted['effective_memory_mb'] == 400, restarted
        assert restarted['effective_quota'] >= os.cpu_count(), restarted
        assert restarted['shares'] == round(0.2 * 1024), restarted
        before = resources.containers()
        resources.runtime.write(resources.pending, {'before': before, 'policies': resources.runtime.read(resources.path)})
        resources.recover()
        assert not resources.pending.exists()
        snapshot = resources.snapshot()
        assert len(snapshot['apps']) == 2
        assert all(row['versions'] == 1 and row['used_mb'] > 0 and row['cpu_percent'] is not None for row in snapshot['apps'])
        release_path = next((runtime.root / 'apps/resourcechecka/releases').glob('*/release.json'))
        release_path.unlink()
        assert len(resources.containers()) == 2, 'Interrupted creation must still count'
        runtime.run(['podman', 'rm', '--force', '--time', '0', names[0]])
        assert len(resources.containers()) == 1, 'Removed containers must not break resource inspection'
        print('Real Podman limits, full-host CPU bursting, boundaries, usage and recovery passed')
    finally:
        for name in names:
            subprocess.run(['podman', 'rm', '--force', '--time', '0', '--ignore', name], check=True, stdout=subprocess.DEVNULL)
