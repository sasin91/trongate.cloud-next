"""Allocation admission, rebalancing and interrupted-update recovery tests."""
import copy
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('resources', Path(__file__).parents[1] / 'runtime/tc_resources.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
Resources = module.Resources


class Store:
    def __init__(self, root):
        self.root = Path(root)
        (self.root / 'apps').mkdir()

    def read(self, path, default=None):
        return json.loads(Path(path).read_text()) if Path(path).exists() else default

    def write(self, path, value):
        Path(path).parent.mkdir(parents=True, exist_ok=True)
        Path(path).write_text(json.dumps(value))


class Fake(Resources):
    def __init__(self, runtime):
        super().__init__(runtime)
        self.limits = []
        self.calls = []
        self.fail = None
        self.hardware = dict(memory_mb=4096, available_mb=3500, cpus=4,
                             system_memory_mb=512, database_memory_mb=512, build_memory_mb=1536,
                             system_cpus=0.25, database_cpus=0.25, build_cpus=1)

    def host(self):
        return self.hardware

    def containers(self):
        return copy.deepcopy(self.limits)

    def update(self, name, memory, shares, quota=0):
        self.calls.append((name, memory, shares, quota))
        if self.fail == name:
            self.fail = None
            raise RuntimeError('simulated update failure')
        item = next(item for item in self.limits if item['container'] == name)
        item.update(memory_mb=memory, shares=shares, quota=quota)

    def app(self, name, memory=256, used=40, versions=1, staged=False):
        if staged:
            self.runtime.write(self.runtime.root / 'apps' / name / 'staged.json', {'operation': name})
        else:
            self.runtime.write(self.runtime.root / 'apps' / name / 'state.json', {'active': {'operation': name}})
        for index in range(versions):
            self.limits.append(dict(app=name, container=f'{name}-{index}', running=True,
                                    used_mb=used, memory_mb=memory, shares=1024, quota=1))


class ResourceTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.resources = Fake(Store(self.temp.name))

    def tearDown(self):
        self.temp.cleanup()

    def test_two_slots_and_all_reserves(self):
        r = self.resources
        self.assertEqual({'mode': 'automatic', 'memory_mb': 768, 'cpus': 1.25}, r.apply(incoming='one', starting=True))
        r.app('one')
        self.assertEqual(384, r.apply(incoming='two', starting=True)['memory_mb'])
        self.assertEqual(384, r.limits[0]['memory_mb'])
        self.assertEqual(0, r.limits[0]['quota'], 'CPU must be a share, not a hard cap')

    def test_manual_app_stays_fixed_and_automatic_apps_share_remainder(self):
        r = self.resources
        r.app('one')
        r.app('two')
        r.apply(change={'app': 'one', 'mode': 'manual', 'memory_mb': 512, 'cpus': 0.5})
        self.assertEqual([512, 256], [item['memory_mb'] for item in r.limits])
        self.assertEqual(0.5, r.snapshot()['allocations']['one']['cpus'])
        r.apply(change={'app': 'one', 'mode': 'automatic'})
        self.assertEqual([384, 384], [item['memory_mb'] for item in r.limits])

    def test_boundary_rejection_does_not_mutate(self):
        r = self.resources
        r.app('one')
        r.app('two')
        for memory, cpu in [(513, 0.5), (512, 1.21), (255, 0.5), (256, float('nan')), (256, 0.051)]:
            with self.assertRaises(RuntimeError):
                r.apply(change={'app': 'one', 'mode': 'manual', 'memory_mb': memory, 'cpus': cpu})
        self.assertEqual([], r.calls)
        self.assertFalse(r.path.exists())

    def test_live_usage_prevents_unsafe_automatic_shrink(self):
        r = self.resources
        r.app('one', memory=768, used=400)
        with self.assertRaisesRegex(RuntimeError, 'Cannot safely shrink one'):
            r.apply(incoming='two', starting=True)
        self.assertEqual([], r.calls)

    def test_both_active_and_staged_versions_updated(self):
        r = self.resources
        r.app('one', versions=2)
        r.app('two', staged=True)
        r.apply(change={'app': 'one', 'mode': 'automatic'})
        self.assertEqual([384, 384, 384], [item['memory_mb'] for item in r.limits])
        self.assertEqual(2, r.snapshot()['apps'][0]['versions'])
        self.assertEqual(80, r.snapshot()['apps'][0]['used_mb'])
        with self.assertRaisesRegex(RuntimeError, 'Two versions'):
            r.apply(incoming='one', starting=True)

    def test_rollbacks_retained_containers_also_receive_limits(self):
        r = self.resources
        r.app('one', versions=2)
        r.limits[1]['running'] = False
        r.apply(incoming='one', starting=True)
        self.assertEqual([768, 768], [item['memory_mb'] for item in r.limits])

    def test_reduction_before_increase(self):
        r = self.resources
        r.app('one', memory=256)
        r.app('two', memory=512)
        r.apply()
        self.assertEqual(['two-0', 'one-0'], [call[0] for call in r.calls])

    def test_failed_partial_update_restores_limits_and_policy(self):
        r = self.resources
        r.app('one')
        r.app('two')
        before = copy.deepcopy(r.limits)
        r.fail = 'two-0'
        with self.assertRaisesRegex(RuntimeError, 'simulated'):
            r.apply(change={'app': 'one', 'mode': 'manual', 'memory_mb': 512, 'cpus': 0.5})
        self.assertEqual(before, r.limits)
        self.assertEqual({}, r.runtime.read(r.path))
        self.assertFalse(r.pending.exists())

    def test_interrupted_update_recovered_before_next_mutation(self):
        r = self.resources
        r.app('one')
        before = copy.deepcopy(r.limits)
        r.runtime.write(r.pending, {'before': before, 'policies': {}})
        r.limits[0]['memory_mb'] = 768
        r.apply(change={'app': 'one', 'mode': 'manual', 'memory_mb': 512, 'cpus': 0.5})
        self.assertEqual(256, r.calls[0][1])
        self.assertEqual(512, r.limits[0]['memory_mb'])
        self.assertFalse(r.pending.exists())

    def test_host_pressure_and_minimum_capacity_fail_closed(self):
        r = self.resources
        r.hardware['available_mb'] = 200
        with self.assertRaisesRegex(RuntimeError, 'Available memory'):
            r.apply(incoming='one', starting=True)
        r.hardware['available_mb'] = 3500
        r.hardware['memory_mb'] = 3000
        with self.assertRaisesRegex(RuntimeError, 'Not enough capacity'):
            r.apply(incoming='one', starting=True)
        self.assertEqual([], r.calls)

    def test_recovery_never_forces_an_unsafe_shrink(self):
        r = self.resources
        r.app('one', memory=256)
        r.runtime.write(r.pending, {'before': copy.deepcopy(r.limits), 'policies': {}})
        r.limits[0].update(memory_mb=768, used_mb=500)
        with self.assertRaisesRegex(RuntimeError, 'recovery needs attention'):
            r.apply()
        self.assertTrue(r.pending.exists())
        self.assertEqual([], r.calls)

    def test_running_release_without_active_journal_still_counts(self):
        r = self.resources
        r.app('one')
        r.runtime.write(r.runtime.root / 'apps/one/state.json', {'active': None})
        self.assertEqual(384, r.apply(incoming='two', starting=True)['memory_mb'])

    def test_removing_an_app_returns_its_budget_to_remaining_apps(self):
        r = self.resources
        r.app('one')
        r.app('two')
        r.apply()
        r.runtime.write(r.runtime.root / 'apps/two/state.json', {'active': None})
        r.limits[1]['running'] = False
        r.apply()
        self.assertEqual(768, r.limits[0]['memory_mb'])


if __name__ == '__main__':
    unittest.main()
