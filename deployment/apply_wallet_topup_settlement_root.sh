#!/usr/bin/env bash
set -euo pipefail
umask 077

wallet_revision=${1:?Full revision is required}
shift
[[ "$wallet_revision" =~ ^[0-9a-f]{40}$ ]]
[[ $(id -u) = 0 && $(hostname -s) = srv1836524 ]]
wallet_root=/home/fasakha/public_html
wallet_php=/opt/cpanel/ea-php82/root/usr/bin/php
[[ -x "$wallet_php" && -d "$wallet_root" && ! -L "$wallet_root" ]]
[[ $(realpath -- "$wallet_root") = "$wallet_root" ]]
[[ $(stat -c '%u' -- "$wallet_root") = $(id -u fasakha) ]]
command -v python3 >/dev/null
runuser -u fasakha -- git -C "$wallet_root" cat-file -e "$wallet_revision^{commit}"

wallet_stage=$(mktemp -d /tmp/wallet-topup-settlement.XXXXXXXX)
trap 'rm -rf -- "$wallet_stage"' EXIT
chmod 0755 "$wallet_stage"
wallet_paths=(
  app/Services/Payments/PaymobTransactionInquiry.php
  app/Services/Payments/WalletTopupSettlement.php
  app/Services/Payments/WalletTopupEffects.php
  app/Http/Controllers/Payment/WalletTopupController.php
  config/wallet_topups.php
  deployment/install_wallet_topup_settlement.py
  deployment/reconcile_wallet_topup.php
  deployment/check_wallet_topup_runtime.php
)
for wallet_path in "${wallet_paths[@]}"; do
  mkdir -p "$wallet_stage/$(dirname -- "$wallet_path")"
  chmod 0755 "$wallet_stage/$(dirname -- "$wallet_path")"
  wallet_blob=$(runuser -u fasakha -- git -C "$wallet_root" rev-parse "$wallet_revision:$wallet_path")
  [[ "$wallet_blob" =~ ^[0-9a-f]{40}$ ]]
  runuser -u fasakha -- git -C "$wallet_root" show "$wallet_revision:$wallet_path" > "$wallet_stage/$wallet_path"
  [[ $(git hash-object --no-filters "$wallet_stage/$wallet_path") = "$wallet_blob" ]]
  chmod 0644 "$wallet_stage/$wallet_path"
done
# mkdir -p under umask077 creates intermediate directories; make code traversable.
find "$wallet_stage" -type d -exec chmod 0755 {} +
runuser -u fasakha -- "$wallet_php" "$wallet_stage/deployment/check_wallet_topup_runtime.php" "$wallet_root" before
runuser -u fasakha -- python3 "$wallet_stage/deployment/install_wallet_topup_settlement.py" \
  --root "$wallet_root" --payload "$wallet_stage" --php "$wallet_php"
runuser -u fasakha -- "$wallet_php" "$wallet_root/artisan" route:clear --no-interaction
runuser -u fasakha -- "$wallet_php" "$wallet_stage/deployment/check_wallet_topup_runtime.php" "$wallet_root" after
if [[ $# -gt 0 ]]; then
  # Recovery arguments identify one previously verified payment; no new charge.
  runuser -u fasakha -- "$wallet_php" "$wallet_stage/deployment/reconcile_wallet_topup.php" --root "$wallet_root" "$@"
fi
