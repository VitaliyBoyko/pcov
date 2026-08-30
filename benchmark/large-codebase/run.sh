#!/bin/sh
set -eu

repository=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
output=${1:-"$repository/benchmark/results/upstream-vs-large.jsonl"}
workspace=$(mktemp -d "${TMPDIR:-/tmp}/pcov-public-benchmark.XXXXXX")
trap 'rm -rf "$workspace"' EXIT HUP INT TERM
host_uid=$(id -u)
host_gid=$(id -g)

git clone --quiet --depth 1 --branch v1.0.12 https://github.com/krakjoe/pcov.git "$workspace/official"

docker run --rm -e HOST_UID="$host_uid" -e HOST_GID="$host_gid" \
    -v "$workspace/official":/src -w /src php:8.5-cli sh -lc '
    phpize && ./configure --enable-pcov && make -j"$(nproc)"
    chown -R "$HOST_UID:$HOST_GID" /src
'
docker run --rm -e HOST_UID="$host_uid" -e HOST_GID="$host_gid" \
    -v "$repository":/src -w /src php:8.5-cli sh -lc '
    phpize --clean >/dev/null 2>&1 || true
    phpize && CFLAGS="-O2 -Wall -Wextra -Werror" ./configure --enable-pcov && make -j"$(nproc)"
    chown -R "$HOST_UID:$HOST_GID" /src
'

mkdir -p "$(dirname -- "$output")"
docker run --rm \
    -v "$repository":/fork \
    -v "$workspace/official":/official \
    -w /fork \
    php:8.5-cli \
    php benchmark/large-codebase/run.php \
        --official-so=/official/modules/pcov.so \
        --fork-so=/fork/modules/pcov.so \
        --output="${output#$repository/}"
