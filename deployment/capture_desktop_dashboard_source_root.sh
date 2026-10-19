#!/usr/bin/env bash
# Preserve only missing source versions in an application-owned private ZIP.
# No deployment, environment export, business rows, or public download endpoint.
set -euo pipefail
umask 077
release=${1:?Pass the reviewed capture commit}
[[ $# == 1 && $(id -u) -eq 0 && $(hostname -s) == srv1836524 && $release =~ ^[a-f0-9]{40}$ ]] || {
  echo 'DESKTOP_SOURCE_BUNDLE_INVALID_INVOCATION' >&2; exit 1;
}
project=/home/fasakha/public_html
[[ -d "$project/.git" && ! -L "$project" && ! -L "$project/.git" && $(realpath "$project") == "$project" ]] || {
  echo 'DESKTOP_SOURCE_BUNDLE_PROJECT_UNSAFE' >&2; exit 1;
}
directory=$(mktemp -d /tmp/desktop-source-bundle.XXXXXXXX)
trap 'rm -rf -- "$directory"' EXIT
declare -A pins=(
  [inspect_desktop_dashboard_updates_root.sh]=ba436c5409dd3e535fa23ccd118bc49417e03c2b
  [capture_desktop_dashboard_source.py]=2a166bba36b0a63663803a714171fcf61a780e65
  [diagnose_desktop_dashboard_current_source.py]=e7a7a8271481013a1c16167d6f7a6ff429687ef5
  [desktop_dashboard_server_preflight.py]=4f3feb5b5c68ae06039c56758f6a72aebd3bdbc4
  [desktop_dashboard_known_source_20261019.json]=e056e021ecb9d6a53ef3eef729346b66ec456870
)
for name in "${!pins[@]}"; do
  runuser -u fasakha -- git --no-optional-locks -C "$project" show "$release:deployment/$name" > "$directory/$name"
  actual=$(/usr/bin/python3 -B -c 'import hashlib,pathlib,sys; data=pathlib.Path(sys.argv[1]).read_bytes(); print(hashlib.sha1(b"blob "+str(len(data)).encode()+b"\0"+data).hexdigest())' "$directory/$name")
  [[ $actual == "${pins[$name]}" ]] || { echo 'DESKTOP_SOURCE_BUNDLE_PIN_MISMATCH' >&2; exit 1; }
  chmod 0644 "$directory/$name"
done
bash "$directory/inspect_desktop_dashboard_updates_root.sh" "$release" > "$directory/report.txt"
chmod 0644 "$directory/report.txt"
chmod 0755 "$directory"
timeout --signal=TERM --kill-after=5s 180s \
  runuser -u fasakha -- /usr/bin/python3 -B "$directory/capture_desktop_dashboard_source.py" \
  --report "$directory/report.txt" --catalog "$directory/desktop_dashboard_known_source_20261019.json"
