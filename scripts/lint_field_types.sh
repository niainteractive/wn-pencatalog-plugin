#!/usr/bin/env bash
# lint_field_types.sh — fail if the plugin declares a backend form field type this Winter
# build cannot render.
#
# Why: the Form widget renders each field by loading
# modules/backend/widgets/form/partials/_field_<type>.php. On 1.3 the widget-backed types
# (richeditor, markdowneditor, mediafinder, fileupload, datepicker, codeeditor, relation)
# have no partial, and ONE of them 500s the entire form it sits on.
#
# Only *form field* definitions are linted (models/*/fields.yaml). A type: in columns.yaml or a
# filter scope is a different renderer, and flagging those is noise that gets a lint switched off.
# The supported set is derived from the installed core rather than hardcoded, so when
# upstream adds the missing partials this lint starts allowing them on its own.
#
# Usage: bash scripts/lint_field_types.sh     (any cwd)
# Exit:  0 clean · 1 unsupported types found · 2 core partials not found

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"
PARTIALS="$ROOT/modules/backend/widgets/form/partials"

if [ ! -d "$PARTIALS" ]; then
    echo "lint_field_types: cannot find $PARTIALS" >&2
    exit 2
fi

SUPPORTED="$(ls "$PARTIALS" | sed -n 's/^_field_\(.*\)\.php$/\1/p' | grep -v '^container$' | sort -u | tr '\n' ' ')"
CORE_ONLY="widget"

supported() {
    case " $SUPPORTED $CORE_ONLY " in *" $1 "*) return 0 ;; esac
    return 1
}

fail=0
while IFS= read -r line; do
    [ -z "$line" ] && continue
    file="${line%%:*}"
    rest="${line#*:}"
    type="$(printf '%s' "$rest" | sed -n 's/.*type:[[:space:]]*\([A-Za-z0-9_-]*\).*/\1/p')"
    [ -z "$type" ] && continue
    supported "$type" && continue
    echo "UNSUPPORTED  ${file#$PLUGIN_DIR/}:${rest%%:*}  ->  type: $type"
    fail=1
done < <(grep -rnE '^[[:space:]]+type:[[:space:]]*[A-Za-z0-9_-]+' "$PLUGIN_DIR" --include='fields.yaml' || true)

if [ "$fail" -eq 0 ]; then
    echo "lint_field_types: OK — every declared field type renders on this build."
    echo "supported: $SUPPORTED"
fi
exit $fail
