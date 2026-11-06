#!/usr/bin/env bash
# REQ-TWIG-005 — fail when bundle or demo Twig uses raw <form> / <input> / <textarea> / <select>.
# No exceptions: the QR deny button is a Symfony Form too (QrLoginDenyType + form_start).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

paths=()
[[ -d src/Resources/views ]] && paths+=(src/Resources/views)
for dir in demo/*/templates; do
  [[ -d "$dir" ]] && paths+=("$dir")
done
if [[ ${#paths[@]} -eq 0 ]]; then
  echo "check-no-raw-html-form: no Twig directories — OK"
  exit 0
fi

# find + grep without GNU options: BusyBox grep refuses --include. Exit 1 means no match, anything else is a failure
# of the check itself, and must never read as a pass.
mapfile -t files < <(find "${paths[@]}" -type f -name '*.twig')
if [[ ${#files[@]} -eq 0 ]]; then
  echo "check-no-raw-html-form: no Twig files — OK"
  exit 0
fi

status=0
matches="$(grep -HnE '<(form|input|textarea|select)[[:space:]>]' "${files[@]}")" || status=$?
if [[ $status -gt 1 ]]; then
  echo "ERROR: check-no-raw-html-form could not run grep (exit $status)." >&2
  exit 2
fi
if [[ $status -eq 0 ]]; then
  echo "ERROR: raw <form> / <input> / <textarea> / <select> in Twig (REQ-TWIG-005):" >&2
  echo "$matches" >&2
  exit 1
fi

echo "check-no-raw-html-form: OK"
