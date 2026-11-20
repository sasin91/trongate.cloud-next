"""Builder handoff contracts with mocked container execution."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
from types import SimpleNamespace

spec = importlib.util.spec_from_file_location('builder', Path(__file__).parents[1]/'runtime/tc-build.py')
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)


class BuilderTests(unittest.TestCase):
    def test_local_archive_does_not_push_to_registry_and_retries_are_idempotent(self):
        with tempfile.TemporaryDirectory() as directory:
            calls = []
            def run(args, timeout=1800):
                calls.append(args)
                if args[:2] == ['git', 'init']:
                    source = Path(args[2])
                    (source/'public').mkdir(parents=True)
                    (source/'config').mkdir()
                    (source/'public/index.php').touch()
                    (source/'config/config.php').touch()
                if 'rev-parse' in args:
                    return 'a'*40
                if args[:3] == ['podman', 'image', 'inspect']:
                    return 'sha256:'+'b'*64
                if args[:2] == ['podman', 'save']:
                    Path(args[args.index('--output')+1]).write_text('fixture archive')
                return ''
            request = {'operation': 'c'*32, 'repository': 'owner/repo', 'commit': 'a'*40,
                       'tag': 'localhost/tc/demo:build-'+'d'*24, 'dockerfile': 'FROM example', 'output': 'archive'}
            with patch.object(builder.Path, 'home', return_value=Path(directory)), patch.object(builder, 'run', side_effect=run), patch.object(builder.shutil, 'disk_usage', return_value=SimpleNamespace(free=10 * 1024**3)):
                result = builder.build(request)
                self.assertEqual('succeeded', result['status'])
                self.assertEqual('localhost/tc/demo@sha256:'+'b'*64, result['image'])
                self.assertFalse(any(args[:2] == ['podman', 'push'] for args in calls))
                self.assertIn(['podman', 'rmi', '--no-prune', request['tag']], calls)
                self.assertTrue(any('--property=MemoryMax=1G' in args for args in calls))
                self.assertTrue(any('--property=CPUQuota=100%' in args for args in calls))
                count = len(calls)
                self.assertEqual(result, builder.build(request))
                self.assertEqual(count, len(calls))

    def test_interrupted_build_is_not_repeated(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)/'.local/state/tc-build'
            root.mkdir(parents=True)
            (root/('c'*32+'.json')).write_text(json.dumps({'status': 'running'}))
            request = {'operation': 'c'*32, 'repository': 'owner/repo', 'commit': 'a'*40, 'tag': 'localhost/tc/demo:build-test'}
            with patch.object(builder.Path, 'home', return_value=Path(directory)), patch.object(builder, 'run') as run:
                self.assertEqual('uncertain', builder.build(request)['status'])
                run.assert_not_called()


if __name__ == '__main__':
    unittest.main()
