#!/usr/bin/env bash
set -euo pipefail

[[ "$(id -u)" == 0 ]] || { echo RUN_AS_ROOT; exit 1; }
editor_feedback_root=/home/fasakha/public_html
editor_feedback_revision="${1:?Full reviewed release SHA required}"
[[ "$editor_feedback_revision" =~ ^[a-f0-9]{40}$ ]] || exit 1
shift
editor_feedback_flags=()
for editor_feedback_flag in "$@"; do
  case "$editor_feedback_flag" in
    --check)
      [[ ${#editor_feedback_flags[@]} == 0 ]] || { echo DUPLICATE_OPTION; exit 1; }
      editor_feedback_flags+=("$editor_feedback_flag")
      ;;
    *) echo INVALID_OPTION; exit 1 ;;
  esac
done

editor_feedback_tmp="$(mktemp -d /tmp/order-editor-feedback.XXXXXX)"
trap 'rm -rf -- "$editor_feedback_tmp"' EXIT
chmod 0755 "$editor_feedback_tmp"
editor_feedback_helper=install_order_editor_feedback.py
runuser -u fasakha -- git -C "$editor_feedback_root" show \
  "$editor_feedback_revision:deployment/$editor_feedback_helper" > "$editor_feedback_tmp/$editor_feedback_helper"
editor_feedback_blob="$(runuser -u fasakha -- git -C "$editor_feedback_root" rev-parse \
  "$editor_feedback_revision:deployment/$editor_feedback_helper")"
[[ "$(git hash-object --no-filters "$editor_feedback_tmp/$editor_feedback_helper")" == "$editor_feedback_blob" ]] || exit 1
chmod 0644 "$editor_feedback_tmp/$editor_feedback_helper"
python3 "$editor_feedback_tmp/$editor_feedback_helper" --root "$editor_feedback_root" \
  --release "$editor_feedback_revision" "${editor_feedback_flags[@]}"
