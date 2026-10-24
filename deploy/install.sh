#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -euo pipefail
deploy_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
args=()
install_deps=false
for arg in "$@"; do
  if [[ "$arg" == --install-deps ]]; then
    install_deps=true
  else
    args+=("$arg")
  fi
done
# Help and plan never install packages, even if --install-deps was supplied.
for arg in "${args[@]}"; do
  case "$arg" in -h|--help|plan|doctor|runtime) install_deps=false ;; esac
done
if $install_deps; then
  bash "$deploy_dir/host-deps.sh"
fi
command -v python3 >/dev/null || {
  printf 'Python 3 is required. On Ubuntu: sudo bash deploy/install.sh install --install-deps\n' >&2
  exit 1
}
exec python3 "$deploy_dir/setup.py" "${args[@]}"
