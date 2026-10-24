#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -euo pipefail
[[ "$(id -u)" == 0 ]] || {
  printf 'Installing host packages requires root. Run with sudo.\n' >&2
  exit 1
}
[[ -f /etc/os-release ]] || { printf 'Expected Ubuntu Linux.\n' >&2; exit 1; }
# This is the operating system's root-owned metadata, not a project env file.
. /etc/os-release
[[ "$ID" == ubuntu ]] || {
  printf 'Automatic package installation supports Ubuntu only. Install Docker Compose v2, git, python3, acl and curl manually.\n' >&2
  exit 1
}
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y ca-certificates curl git python3 acl
deploy_compose_version="$(docker compose version --short 2>/dev/null || true)"
if ! command -v docker >/dev/null || [[ -z "$deploy_compose_version" ]] \
  || ! dpkg --compare-versions "${deploy_compose_version#v}" ge 2.24.4; then
  # Do not remove a pre-existing container engine or its packages automatically.
  for package in docker.io docker-compose docker-compose-v2 docker-doc docker-buildx podman-docker containerd runc; do
    if [[ "$(dpkg-query -W -f='${Status}' "$package" 2>/dev/null || true)" == 'install ok installed' ]]; then
      printf 'Conflicting package: %s. Resolve Docker packages following https://docs.docker.com/engine/install/ubuntu/ and retry.\n' "$package" >&2
      exit 1
    fi
  done
  if ! grep -Rqs 'download.docker.com/linux/ubuntu' /etc/apt/sources.list.d \
    && ! grep -qs 'download.docker.com/linux/ubuntu' /etc/apt/sources.list; then
    install -m 0755 -d /etc/apt/keyrings
    curl --fail --silent --show-error --location https://download.docker.com/linux/ubuntu/gpg \
      -o /etc/apt/keyrings/libresign-docker.asc
    chmod a+r /etc/apt/keyrings/libresign-docker.asc
    cat > /etc/apt/sources.list.d/libresign-docker.sources <<EOF
Types: deb
URIs: https://download.docker.com/linux/ubuntu
Suites: ${UBUNTU_CODENAME:-$VERSION_CODENAME}
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/libresign-docker.asc
EOF
  fi
  apt-get update
  apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi
if ! docker info >/dev/null 2>&1; then
  if command -v systemctl >/dev/null && [[ -d /run/systemd/system ]]; then
    systemctl enable --now docker
  fi
fi
docker info >/dev/null
docker compose version
