#!/bin/sh
# Removes the local stack, including the server containers Wings started, which docker compose does not know about.
# Server files in docker/wings stay. Only containers on this stack's own server network are touched.
set -eu

cd "$(dirname "$0")"

PWD="$(pwd -P)"
export PWD

servers="$(docker ps -aq --filter network=pelican-provisioner-dev-servers)"

if [ -n "$servers" ]; then
  echo "Removing server containers..."
  # shellcheck disable=SC2086
  docker rm -f $servers
fi

docker compose down -v --remove-orphans
docker network rm pelican-provisioner-dev-servers >/dev/null 2>&1 || true

echo "Removed. Server files are still in docker/wings/volumes."
