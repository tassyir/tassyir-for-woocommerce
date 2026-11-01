#!/usr/bin/env bash
# Builds the release zips. Needs only Docker (uses the composer:2 image).
#   bin/build.sh          dist/tassyir-for-woocommerce-<version>.zip: the self-hosted build, with
#                         production vendor/ and vendor-prefixed/ (Strauss: the self-updater).
#   bin/build.sh --wporg  dist/tassyir-for-woocommerce-<version>-wporg.zip: the wordpress.org build,
#                         without any self-update code (no updater library, no Updater class).
# Same slug and version in both, so a site on the self-hosted zip can later take wordpress.org updates.
set -euo pipefail

WPORG=0
if [ "${1:-}" = "--wporg" ]; then
	WPORG=1
elif [ -n "${1:-}" ]; then
	echo "usage: bin/build.sh [--wporg]" >&2
	exit 1
fi

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="tassyir-for-woocommerce"
VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$ROOT/$SLUG.php" | tr -d '\r' | head -1)"
if [ "$WPORG" = 1 ]; then
	BUILD_REL="build/wporg/$SLUG"
	ZIP="dist/$SLUG-$VERSION-wporg.zip"
else
	BUILD_REL="build/self-hosted/$SLUG"
	ZIP="dist/$SLUG-$VERSION.zip"
fi
BUILD="$ROOT/$BUILD_REL"

# Docker on Windows (Git Bash) wants C:\… paths and no MSYS path rewriting.
export MSYS_NO_PATHCONV=1
hostpath() { if command -v cygpath >/dev/null 2>&1; then cygpath -w "$1"; else echo "$1"; fi; }

echo "Building $SLUG $VERSION ($([ "$WPORG" = 1 ] && echo wordpress.org || echo self-hosted))"
rm -rf "$(dirname "$BUILD")"
mkdir -p "$BUILD" "$ROOT/dist"

# Allow-list of what ships: never tests/, bin/, node_modules, dotfiles, tool configs or .wp-env.json.
for item in "$SLUG.php" uninstall.php readme.txt LICENSE composer.json composer.lock includes assets languages; do
	cp -R "$ROOT/$item" "$BUILD/"
done

if [ "$WPORG" = 1 ]; then
	# wordpress.org forbids self-updaters: drop the Updater class and the file that hooks it, the
	# update-checker dependency with Strauss, and the readme's "Updates" bullet.
	rm -f "$BUILD/includes/Updater.php" "$BUILD/includes/self-update.php"
	docker run --rm -v "$(hostpath "$BUILD"):/b" --entrypoint php composer:2 -r '
		$f = "/b/composer.json";
		$j = json_decode( file_get_contents( $f ), true );
		unset( $j["require"]["yahnis-elsts/plugin-update-checker"], $j["autoload"]["files"], $j["extra"], $j["scripts"] );
		file_put_contents( $f, json_encode( $j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );'
	sed -i '/^\* \*\*Updates\*\*:/d' "$BUILD/readme.txt"
	# No dependency left: only our own (PSR-4) autoloader.
	"$ROOT/bin/composer.sh" "$BUILD" dump-autoload --no-dev --optimize --no-interaction
else
	# Production dependencies only; composer's post-install script runs Strauss (vendor-prefixed/).
	"$ROOT/bin/composer.sh" "$BUILD" install --no-dev --no-interaction --no-progress --prefer-dist
	"$ROOT/bin/composer.sh" "$BUILD" dump-autoload --no-dev --optimize --no-interaction
fi
rm -rf "$BUILD/bin" "$BUILD/composer.lock" # The lock also lists dev tools; composer.json stays.

rm -f "$ROOT/$ZIP"
docker run --rm -v "$(hostpath "$ROOT"):/app" -w /app --entrypoint php composer:2 \
	bin/zip.php "$BUILD_REL" "$ZIP" "$SLUG"

echo "Done: $ZIP"
