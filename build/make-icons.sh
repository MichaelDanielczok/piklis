#!/usr/bin/env bash
# Erzeugt alle PWA-Icons und Favicons aus build/icon.svg nach frontend/public/icons/.
# Braucht ImageMagick (`brew install imagemagick`). Danach `npm run build`.
set -Eeuo pipefail

cd "$(dirname "$0")/.."

source=build/icon.svg
target=frontend/public/icons

mkdir -p "$target"

render() {
    magick -background none -density 384 "$source" -resize "$1x$1" "$target/$2"
}

render 192 icon-192.png
render 512 icon-512.png
render 512 icon-maskable-512.png
render 180 apple-touch-icon.png
render 32 favicon-32.png

echo "Icons in $target"
