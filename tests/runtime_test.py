"""State-machine tests with simulated commands. These are not container/VM tests."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('runtime', Path(__file__).parents[1]/'runtime/tc-runtime.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class Simulated(module.Runtime):
    def __init__(self, root):
        super().__init__(root)
        self.bad = None
        self.public_bad = False
        self.current = None
        self.starts = 0

    def run(self, args, timeout=120):
        return ''

    def stage(self, app, operation, spec, secrets, files=None, registry_auth=None):
        self.starts += 1
        return dict(spec, operation=operation, port=1234, unit=operation+'.service', container=operation)

    def route(self, app, release):
        self.current = release

    def healthy(self, release):
        return release is not None and release['operation'] != self.bad

    def health(self, url, attempts=20):
        if self.public_bad:
            self.public_bad = False
            return False
        return True


class RuntimeTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.runtime = Simulated(self.temp.name)

    def tearDown(self):
        self.temp.cleanup()

    def request(self, n):
        return {'action': 'deploy', 'operation': f'{n:032x}', 'app': 'demo',
                'release': {'domain': 'demo.test', 'health': '/', 'migration': []}}

    def test_deploy_rollback_and_idempotent_retry(self):
        r = self.runtime
        self.assertEqual('succeeded', r.dispatch(self.request(1))['status'])
        self.assertEqual('succeeded', r.dispatch(self.request(2))['status'])
        self.assertEqual('succeeded', r.dispatch(self.request(2))['status'])
        self.assertEqual(2, r.starts)
        rollback = dict(self.request(3), action='rollback')
        self.assertEqual('succeeded', r.dispatch(rollback)['status'])
        self.assertEqual(f'{1:032x}', r.current['operation'])

    def test_failed_candidate_preserves_active(self):
        r = self.runtime
        r.dispatch(self.request(1))
        r.bad = f'{2:032x}'
        result = r.dispatch(self.request(2))
        self.assertEqual('failed', result['status'])
        self.assertEqual(f'{1:032x}', r.current['operation'])

    def test_failed_public_check_restores_route(self):
        r = self.runtime
        r.dispatch(self.request(1))
        r.public_bad = True
        self.assertEqual('failed', r.dispatch(self.request(2))['status'])
        self.assertEqual(f'{1:032x}', r.current['operation'])

    def test_crash_after_switch_restores_recorded_previous(self):
        r = self.runtime
        r.dispatch(self.request(1))
        before = r.read(r.root/'apps/demo/state.json')
        r.write(r.root/'operations'/f'{2:032x}.json', {'status': 'running', 'phase': 'switching', 'before': before})
        self.assertEqual('failed', r.dispatch(self.request(2))['status'])
        self.assertEqual(f'{1:032x}', r.current['operation'])
        self.assertEqual(1, r.starts)

    def test_unknown_migration_is_never_repeated(self):
        r = self.runtime
        r.dispatch(self.request(1))
        before = r.read(r.root/'apps/demo/state.json')
        r.write(r.root/'operations'/f'{2:032x}.json', {'status': 'running', 'phase': 'migration-started', 'before': before})
        self.assertEqual('uncertain', r.dispatch(self.request(2))['status'])
        self.assertEqual(1, r.starts)

    def test_host_reservation_blocks_application_even_after_worker_loss(self):
        r = self.runtime
        r.write(r.root/'host-reservation.json', {'operation': f'{9:032x}'})
        self.assertEqual('uncertain', r.dispatch(self.request(1))['status'])
        self.assertEqual(0, r.starts)

    def test_flock_blocks_concurrent_worker(self):
        r = self.runtime
        with open(r.root/'operation.lock', 'a') as lock:
            module.fcntl.flock(lock, module.fcntl.LOCK_EX)
            self.assertEqual('uncertain', r.dispatch(self.request(1))['status'])
            self.assertEqual(0, r.starts)

    def test_persistent_mounts_are_not_removed_during_rollback(self):
        r = self.runtime
        data = r.root/'apps/demo/data/uploads/example.txt'
        data.parent.mkdir(parents=True)
        data.write_text('keep me')
        r.dispatch(self.request(1))
        r.dispatch(self.request(2))
        r.dispatch(dict(self.request(3), action='rollback'))
        self.assertEqual('keep me', data.read_text())


if __name__ == '__main__':
    unittest.main()
