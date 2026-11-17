#!/bin/sh
# systemd passes the normal generator output directory as $1. The source and
# enabled markers are root-owned mutable state, independent of NixOS's /etc tree.
set -eu
output=$1
mkdir -p "$output/multi-user.target.wants"
for source in /var/lib/tc/runtime/units/tc-*.service; do
  test -f "$source" || continue
  unit=${source##*/}
  ln -sf "$source" "$output/$unit"
  if test -f "/var/lib/tc/runtime/enabled/$unit"; then
    ln -sf "../$unit" "$output/multi-user.target.wants/$unit"
  fi
done
