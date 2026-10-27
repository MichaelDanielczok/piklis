#!/usr/bin/env bash
# Erzeugt alle PWA-Icons und Favicons nach frontend/public/icons/ aus
#   build/icon.svg           App-Icon mit Rundung (Manifest „any“)
#   build/icon-maskable.svg  vollflächig, Motiv in der inneren 80-%-Zone
#                            (Manifest „maskable“, Apple-Touch-Icon; iOS rundet selbst)
#   build/favicon.svg        reduzierte Fassung mit einem Zettel (Browser-Tab)
# Quelle: Designpaket „Leinen“ (design/piklis-designpaket/assets/logo/ in der Instanz).
# Braucht ImageMagick (`brew install imagemagick`). Danach `npm run build`.
set -Eeuo pipefail

cd "$(dirname "$0")/.."

target=frontend/public/icons

mkdir -p "$target"

render() {
    magick -background none -density 384 "build/$1" -resize "$2x$2" "$target/$3"
}

render icon.svg 192 icon-192.png
render icon.svg 512 icon-512.png
render icon-maskable.svg 192 icon-maskable-192.png
render icon-maskable.svg 512 icon-maskable-512.png
render icon-maskable.svg 180 apple-touch-icon.png
render favicon.svg 32 favicon-32.png
cp build/favicon.svg "$target/favicon.svg"

echo "Icons in $target"
