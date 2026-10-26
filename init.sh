#!/usr/bin/env bash
# Macht aus dem Starter ein eigenes Projekt: ersetzt die Platzhalternamen in
# allen Dateien und benennt den PHP-Namespace-Ordner um. Einmal direkt nach
# „Use this template“ ausführen, danach löscht sich das Skript selbst.
#
#   ./init.sh <vendor> <paket> <kürzel> "<Titel>"
#   ./init.sh michaeldanielczok libellio libellio "Libellio"
#
# vendor   Composer-Vendor (klein), z. B. michaeldanielczok
# paket    Paketname (klein, Bindestriche erlaubt), z. B. haushalts-buch
# kürzel   App-Kürzel ohne Bindestriche für Rechte, Benutzerattribute,
#          Konsolenbefehle, localStorage, z. B. haushaltsbuch
# Titel    Anzeigename, z. B. "Haushaltsbuch"
#
# Namespace wird abgeleitet: michaeldanielczok/haushalts-buch →
# MichaelDanielczok\HaushaltsBuch. Anderer Vendor-Namespace:
#   VENDOR_NAMESPACE=PCSG ./init.sh pcsg …
set -Eeuo pipefail

cd "$(dirname "$0")"

if [ "$#" -ne 4 ]; then
    sed -n '2,18p' "$0" | sed 's/^# \{0,1\}//'
    exit 1
fi

vendor="$1"
package="$2"
appId="$3"
title="$4"

[[ "$vendor" =~ ^[a-z0-9-]+$ ]] || { echo "vendor: nur a-z, 0-9, -" >&2; exit 1; }
[[ "$package" =~ ^[a-z0-9-]+$ ]] || { echo "paket: nur a-z, 0-9, -" >&2; exit 1; }
[[ "$appId" =~ ^[a-z][a-z0-9]*$ ]] || { echo "kürzel: nur a-z, 0-9, ohne Bindestrich" >&2; exit 1; }

pascal() {
    echo "$1" | awk -F- '{ for (i = 1; i <= NF; i++) printf "%s%s", toupper(substr($i, 1, 1)), substr($i, 2) }'
}

# Vendor-Namespace: VENDOR_NAMESPACE setzen, wenn er sich nicht ableiten lässt
# (michaeldanielczok → MichaelDanielczok ist bekannt)
if [ -n "${VENDOR_NAMESPACE:-}" ]; then
    vendorNs="$VENDOR_NAMESPACE"
elif [ "$vendor" = "michaeldanielczok" ]; then
    vendorNs="MichaelDanielczok"
else
    vendorNs="$(pascal "$vendor")"
fi

packageNs="$(pascal "$package")"

echo "Paket:      $vendor/$package"
echo "Namespace:  $vendorNs\\$packageNs"
echo "Kürzel:     $appId"
echo "Titel:      $title"

# alter Build passt nicht mehr zu den neuen Pfaden
rm -rf bin/app

files=$(grep -rIl \
    --exclude-dir=.git --exclude-dir=node_modules --exclude-dir=tools \
    --exclude=init.sh \
    -e 'MichaelDanielczok' -e 'PwaStarter' -e 'michaeldanielczok' \
    -e 'pwa-starter' -e 'pwastarter' -e 'PWA Starter' .)

export VENDOR_NS="$vendorNs" PACKAGE_NS="$packageNs" VENDOR="$vendor" PACKAGE="$package" APP_ID="$appId" TITLE="$title"

for file in $files; do
    perl -pi -e '
        s/MichaelDanielczok\\\\PwaStarter/$ENV{VENDOR_NS}\\\\$ENV{PACKAGE_NS}/g;
        s/MichaelDanielczok\\PwaStarter/$ENV{VENDOR_NS}\\$ENV{PACKAGE_NS}/g;
        s/MichaelDanielczok\/PwaStarter/$ENV{VENDOR_NS}\/$ENV{PACKAGE_NS}/g;
        s/michaeldanielczok\/pwa-starter/$ENV{VENDOR}\/$ENV{PACKAGE}/g;
        s/pwa-starter/$ENV{PACKAGE}/g;
        s/pwastarter/$ENV{APP_ID}/g;
        s/PWA Starter/$ENV{TITLE}/g;
    ' "$file"
done

if [ "src/MichaelDanielczok/PwaStarter" != "src/$vendorNs/$packageNs" ]; then
    mkdir -p "src/$vendorNs"
    git mv "src/MichaelDanielczok/PwaStarter" "src/$vendorNs/$packageNs" 2>/dev/null \
        || mv "src/MichaelDanielczok/PwaStarter" "src/$vendorNs/$packageNs"
    rmdir "src/MichaelDanielczok" 2>/dev/null || true
fi

rm -- "$0"

cat <<EOF

Fertig. Nächste Schritte:
  1. Farben (frontend/styles/tokens.css, standalone.html, vite.config.js) und
     build/icon.svg anpassen, dann build/make-icons.sh
  2. npm install && npm run build
  3. Testinstanz mit quiqqer-docker-dev (README dort): eigene env-Datei mit
     PACKAGE_DIR auf dieses Repo, up -d, dann scripts/install-package.sh
  4. README.md anpassen, committen
EOF
