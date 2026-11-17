#!/usr/bin/env bash
set -euo pipefail
# Explicitly destructive, separate from tc server-confirm. Only a disposable
# Hetzner x86 bootstrap VM in Rescue may be selected. No keys are auto-trusted.
if [ "$#" -ne 5 ] || [ "$5" != "--overwrite-bootstrap-disk" ]; then
  echo 'Usage: install-rescue-image.sh HOST PRIVATE_KEY KNOWN_HOSTS RAW_IMAGE --overwrite-bootstrap-disk' >&2
  exit 2
fi
host=$1
if [[ ! "$host" =~ ^[a-zA-Z0-9][a-zA-Z0-9.:-]*$ ]]; then exit 2; fi
test -f "$2" && test -f "$3" && test -f "$4"
ssh -i "$2" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes \
  -o UserKnownHostsFile="$3" -o GlobalKnownHostsFile=/dev/null "root@$host" \
  'test -b /dev/sda && ! lsblk -nr -o MOUNTPOINTS /dev/sda | grep -q "[^[:space:]]" && dd of=/dev/sda bs=4M conv=fsync status=progress' < "$4"
echo 'Disk written. Reboot the selected VM, independently verify its new host identity, then inspect it before snapshotting.'
