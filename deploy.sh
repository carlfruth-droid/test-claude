#!/usr/bin/env bash
#
# Lädt den Inhalt von website/ per SFTP zu Hetzner Webhosting hoch.
#
# Voraussetzungen:
#   1. Hetzner Webhosting ist bestellt und freigeschaltet.
#   2. In konsoleH unter "Zugänge" einen (S)FTP-User angelegt.
#   3. Die Zugangsdaten in eine Datei .env neben diesem Script eintragen
#      (Vorlage: .env.example). Die .env wird NICHT ins Repo committet.
#
# Aufruf:  ./deploy.sh
#
set -euo pipefail

cd "$(dirname "$0")"

if [[ ! -f .env ]]; then
  echo "Fehler: .env fehlt. Kopiere .env.example nach .env und trage deine Hetzner-Zugangsdaten ein." >&2
  exit 1
fi
# shellcheck disable=SC1091
source .env

: "${HETZNER_HOST:?HETZNER_HOST fehlt in .env (z.B. www123.your-server.de oder deine Domain)}"
: "${HETZNER_USER:?HETZNER_USER fehlt in .env (der FTP-Username aus konsoleH)}"

REMOTE_DIR="${HETZNER_REMOTE_DIR:-public_html}"

echo "Lade website/ nach ${HETZNER_USER}@${HETZNER_HOST}:${REMOTE_DIR} hoch ..."

if command -v lftp >/dev/null 2>&1; then
  # lftp spiegelt das Verzeichnis (löscht auf dem Server, was lokal nicht mehr existiert)
  lftp -u "$HETZNER_USER" "sftp://$HETZNER_HOST" -e "
    mirror --reverse --delete --verbose ./website/ $REMOTE_DIR/;
    bye
  "
else
  # Fallback ohne lftp: einfacher Upload per sftp (löscht keine alten Dateien)
  sftp "$HETZNER_USER@$HETZNER_HOST" <<EOF
cd $REMOTE_DIR
put -r website/* .
put website/.htaccess .htaccess
bye
EOF
fi

echo "Fertig. Die Seite ist jetzt live."
