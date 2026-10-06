#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../../deployment/apply_dashboard_runtime_root.sh"
project=/home/fasakha/public_html
covering=(
    '* * * * * cd /home/fasakha/public_html && /usr/bin/php artisan schedule:run > /dev/null 2>&1'
    '*/1 * * * * /usr/bin/php /home/fasakha/public_html/artisan order-board:advance'
    '* * * * * fasakha cd /home/fasakha/public_html && php artisan schedule:run'
    "* * * * * cd '/home/fasakha/public_html' && /usr/local/bin/php artisan schedule:run"
    '* * * * * cd "/home/fasakha/public_html" && /opt/cpanel/ea-php83/root/usr/bin/php artisan order-board:advance'
    '* * * * * root "/usr/bin/php" "/home/fasakha/public_html/artisan" schedule:run'
    '* * * * * cd -- /home/fasakha/public_html && php8.3 artisan schedule:run --no-interaction'
    '* * * * * cd /home/fasakha/public_html&&/opt/alt/php83/usr/bin/php artisan schedule:run'
    '* * * * * /usr/local/bin/php /home/fasakha/public_html/artisan schedule:work'
    '*/1 */1 */1 */1 */1 php /home/fasakha/public_html/artisan order-board:advance # order clock'
)
noncovering=(
    '# * * * * * cd /home/fasakha/public_html && php artisan schedule:run' \
    '0 * * * * cd /home/fasakha/public_html && php artisan schedule:run' \
    '* * * * * cd /home/other/public_html && php artisan schedule:run' \
    '* * * * * cd /home/fasakha/public_html_backup && php artisan schedule:run' \
    '* * * * * cd /home/fasakha/public_html && php artisan go-services:dispatch' \
    '* * * * * cd /other && php artisan schedule:run # /home/fasakha/public_html'
    '* * * * * echo /home/fasakha/public_html artisan schedule:run'
    '* * * * * cd /srv/home/fasakha/public_html && php artisan schedule:run'
    '* * * * * cd /home/fasakha/public_html/subdir && php artisan schedule:run'
    '* * * * * cd /other && php /home/fasakha/public_html/tools/artisan schedule:run'
    '* * * * * cd /other && php artisan schedule:run /home/fasakha/public_html'
    '* * * * * nobody cd /home/fasakha/public_html && php artisan schedule:run'
    '* * * * * cd /home/fasakha/public_html && echo php artisan schedule:run'
    '* */5 * * * cd /home/fasakha/public_html && php artisan schedule:run'
    '* * * * * cd "/home/fasakha/public_html_backup" && php artisan schedule:run'
    '* * * * * cd /home/fasakha/public_html && php other/artisan schedule:run'
    '* * * * * echo ready && cd /home/fasakha/public_html && php artisan schedule:run'
    '* * * * * php /home/fasakha/public_html/artisan schedule:run_extra'
    '* * * * * cd /home/fasakha/public_html ; php artisan schedule:run'
    '* * * * * /usr/bin/php /home/fasakha/public_html_backup/artisan order-board:advance'
)
for line in "${covering[@]}"; do
    if ! printf '%s\n' "$line" | dashboard_minute_scheduler "$project"; then
        printf 'An exact checkout minute scheduler was rejected: %s\n' "$line" >&2
        exit 1
    fi
done
for line in "${noncovering[@]}"; do
    if printf '%s\n' "$line" | dashboard_minute_scheduler "$project"; then
        printf 'A non-covering scheduler was accepted: %s\n' "$line" >&2
        exit 1
    fi
done
printf '%s minute-scheduler coverage checks passed.\n' "$((${#covering[@]} + ${#noncovering[@]}))"

for command in schedule:run schedule:work dashboard-push:dispatch; do
    printf '* * * * * cd %s && php artisan %s\n' "$project" "$command" | dashboard_minute_scheduler "$project" dashboard-push:dispatch
done
if printf '* * * * * cd %s && php artisan order-board:advance\n' "$project" | dashboard_minute_scheduler "$project" dashboard-push:dispatch; then
    echo 'Order-only cron incorrectly considered notification dispatch coverage' >&2; exit 1
fi
printf 'Dedicated notification dispatcher coverage checks passed.\n'
