#!/usr/bin/env bash
set -euo pipefail

[[ "$(id -u)" == 0 ]] || { echo RUN_AS_ROOT; exit 1; }
comment_ai_root=/home/fasakha/public_html
comment_ai_php=/opt/cpanel/ea-php82/root/usr/bin/php
comment_ai_revision="${1:?Full reviewed release SHA required}"
[[ "$comment_ai_revision" =~ ^[a-f0-9]{40}$ && -x "$comment_ai_php" ]] || exit 1
shift
comment_ai_flags=()
for comment_ai_flag in "$@"; do
  case "$comment_ai_flag" in
    --check|--repair-permissions|--install-timer)
      for comment_ai_existing_flag in "${comment_ai_flags[@]}"; do
        [[ "$comment_ai_existing_flag" != "$comment_ai_flag" ]] || { echo DUPLICATE_OPTION; exit 1; }
      done
      comment_ai_flags+=("$comment_ai_flag")
      ;;
    *) echo INVALID_OPTION; exit 1 ;;
  esac
done

comment_ai_tmp="$(mktemp -d /tmp/meta-comment-ai.XXXXXX)"
trap 'rm -rf -- "$comment_ai_tmp"' EXIT
chmod 0755 "$comment_ai_tmp"
for comment_ai_file in update_meta_comment_ai.py meta_comment_ai_manifest.json; do
  runuser -u fasakha -- git -C "$comment_ai_root" show \
    "$comment_ai_revision:deployment/$comment_ai_file" > "$comment_ai_tmp/$comment_ai_file"
  comment_ai_blob="$(runuser -u fasakha -- git -C "$comment_ai_root" rev-parse \
    "$comment_ai_revision:deployment/$comment_ai_file")"
  [[ "$(git hash-object --no-filters "$comment_ai_tmp/$comment_ai_file")" == "$comment_ai_blob" ]] || exit 1
  chmod 0644 "$comment_ai_tmp/$comment_ai_file"
done
python3 "$comment_ai_tmp/update_meta_comment_ai.py" --root "$comment_ai_root" \
  --release "$comment_ai_revision" --php "$comment_ai_php" \
  --manifest "$comment_ai_tmp/meta_comment_ai_manifest.json" "${comment_ai_flags[@]}"
