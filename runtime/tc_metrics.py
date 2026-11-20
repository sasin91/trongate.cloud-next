"""Read-only host pressure snapshots. Never returns environment or process arguments."""
import json
import os
from pathlib import Path
import shutil
import time


def cpu_ticks():
    values = [int(value) for value in Path('/proc/stat').read_text().splitlines()[0].split()[1:9]]
    return sum(values), values[3] + values[4]


def collect(root):
    before = cpu_ticks()
    time.sleep(1)
    after = cpu_ticks()
    total = after[0] - before[0]
    cpu = round(100 * (1 - (after[1] - before[1]) / total), 1) if total > 0 else None
    memory = {line.split(':')[0]: int(line.split()[1]) * 1024 for line in Path('/proc/meminfo').read_text().splitlines()}
    disk = shutil.disk_usage(root)
    apps = []
    reserved_memory = 0
    reserved_cpus = 0
    for path in sorted((root/'apps').glob('*/state.json')):
        state = json.loads(path.read_text())
        active = state.get('active')
        if active:
            reserved_memory += active.get('memory_mb', 0) * 1024**2
            reserved_cpus += active.get('cpus', 0)
            apps.append(path.parent.name)
    return {'at': int(time.time()), 'cpu_percent': cpu, 'cpus': os.cpu_count(),
            'load_1m': os.getloadavg()[0], 'memory_total': memory['MemTotal'],
            'memory_used': memory['MemTotal'] - memory.get('MemAvailable', memory['MemFree']),
            'disk_total': disk.total, 'disk_used': disk.used, 'disk_free': disk.free,
            'app_memory_limit': reserved_memory, 'app_cpu_limit': reserved_cpus, 'apps': len(apps)}
