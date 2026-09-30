#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../../.."
state=tests/erp_runtime/demo/state
test -f "$state/ready.json" || bash tests/erp_runtime/demo/install.sh
if curl --fail --silent http://127.0.0.1:8080/demo/health | grep -q 'fasakhansta-erp-trial'; then
  exit 0
fi
ERP_DEMO=1 nohup php -S 0.0.0.0:8080 -t tests/erp_runtime/demo/public tests/erp_runtime/demo/router.php > "$state/server.log" 2>&1 < /dev/null &
for attempt in $(seq 1 30); do
  if curl --fail --silent http://127.0.0.1:8080/demo/health | grep -q 'fasakhansta-erp-trial'; then
    echo 'Fasakhansta ERP trial: http://localhost:8080'
    exit 0
  fi
  sleep 1
done
echo 'Trial server did not start; inspect tests/erp_runtime/demo/state/server.log' >&2
exit 1
