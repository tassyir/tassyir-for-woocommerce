#!/usr/bin/env bash
# Runs `composer <args>` in Docker on a copy of the project inside the container, then copies
# vendor/ and vendor-prefixed/ back. (Strauss cannot delete folders on a Windows bind mount.)
# usage: bin/composer.sh [project-dir] install [--no-dev] …
set -euo pipefail
export MSYS_NO_PATHCONV=1
DIR="$(cd "${1:-.}" && pwd)"; shift
hostpath() { if command -v cygpath >/dev/null 2>&1; then cygpath -w "$1"; else echo "$1"; fi; }
docker volume create tassyir-composer-cache >/dev/null
docker run --rm \
	-e COMPOSER_CACHE_DIR=/tmp/cache -e COMPOSER_MAX_PARALLEL_HTTP=4 \
	-v tassyir-composer-cache:/tmp/cache -v "$(hostpath "$DIR"):/app" \
	--entrypoint sh composer:2 -c '
		set -e
		mkdir -p /work && cd /app && tar --exclude=./node_modules --exclude=./build --exclude=./dist -cf - . | tar -xf - -C /work
		cd /work && composer "$@"
		rm -rf /app/vendor /app/vendor-prefixed
		cp -a vendor /app/vendor
		if [ -d vendor-prefixed ]; then cp -a vendor-prefixed /app/vendor-prefixed; fi
		if [ -f composer.lock ]; then cp composer.lock /app/composer.lock; fi
		if [ -f bin/strauss.phar ] && [ -d /app/bin ]; then cp bin/strauss.phar /app/bin/strauss.phar; fi
	' composer "$@"
