# fruthzeug.de – Hetzner Webhosting S

Private Website inkl. automatischem Deployment zu Hetzner.

## Was hier drin ist

| Datei | Zweck |
|---|---|
| `website/index.html` | Die Startseite (eine Datei, kein Build nötig) |
| `website/.htaccess` | Leitet automatisch auf HTTPS um |
| `.github/workflows/deploy.yml` | Lädt `website/` bei jedem Push automatisch per FTPS zu Hetzner hoch |
| `deploy.sh` | Alternativer manueller Upload per SFTP (für Mac/PC) |
| `.env.example` | Vorlage für die Zugangsdaten von `deploy.sh` |

## Automatisches Deployment (einmalig einrichten, geht auch am iPad)

1. In konsoleH das FTP-Passwort **einmal neu setzen** (*Einstellungen → Logindaten → Bearbeiten*).
2. Auf GitHub im Repo: *Settings → Secrets and variables → Actions → New repository secret* — zwei Secrets anlegen:
   - `FTP_USERNAME` → der FTP-Loginname aus konsoleH
   - `FTP_PASSWORD` → das neue FTP-Passwort
3. Fertig. Ab jetzt lädt GitHub bei jeder Änderung in `website/` die Seite
   automatisch hoch. Manuell auslösen geht über *Actions → „Website zu Hetzner
   deployen" → Run workflow*.

## Einmalig: Hosting bestellen (musst du selbst machen, ~5 Min.)

1. Auf <https://www.hetzner.com/de/webhosting/> **Webhosting S** in den Warenkorb legen.
2. Im Bestellprozess die Wunschdomain (z. B. `.de`) mitbestellen.
3. Hetzner-Konto als Privatperson anlegen, Zahlungsart wählen (SEPA, Kreditkarte oder PayPal), abschließen.
4. Warten auf die Freischaltungs-Mail (meist wenige Stunden). Darin stehen die Zugangsdaten für **konsoleH** (<https://konsoleh.hetzner.com>).

## Einmalig: In konsoleH einrichten (~10 Min.)

1. **Passwort ändern** und im Hetzner-Konto Zwei-Faktor-Authentifizierung aktivieren.
2. **SSL:** Unter *Einstellungen → SSL-Dienste* das kostenlose Let's-Encrypt-Zertifikat für die Domain aktivieren.
3. **E-Mail:** Unter *E-Mail → Mailboxen* ein Postfach anlegen (z. B. `post@deinedomain.de`).
4. **FTP-Zugang:** Unter *Zugänge* den (S)FTP-Benutzer nachsehen bzw. anlegen — Host, Benutzername und Passwort notieren.

## Website hochladen

```bash
cp .env.example .env
# .env öffnen und HETZNER_HOST + HETZNER_USER eintragen
./deploy.sh
```

Das Script fragt beim Upload nach dem FTP-Passwort. Danach ist die Seite unter deiner Domain erreichbar.

Alternativ geht der Upload auch manuell mit einem FTP-Programm wie FileZilla:
Inhalt von `website/` in das Verzeichnis `public_html` auf dem Server kopieren.

## Seite anpassen

- Text und E-Mail-Adresse direkt in `website/index.html` ändern
  (die Platzhalter-Adresse `post@example.de` durch die echte ersetzen).
- Bilder einfach mit in `website/` legen und per `<img src="bild.jpg">` einbinden.
- Nach jeder Änderung erneut `./deploy.sh` ausführen.

## Später mehr Platz nötig?

Upgrade von S auf M geht jederzeit in konsoleH ohne Umzug oder Datenverlust.

## Finanzzentrale (`website/projekte/finanzen/`)

Private Seite zum Prüfen börsennotierter Firmen, mit Depot-Übersicht und Kursalarmen.
Erreichbar unter <https://fruthzeug.de/projekte/finanzen/>.

- **Einrichtung:** Beim ersten Aufruf legst du das Passwort fest. Das geht nur auf einem Gerät,
  auf dem du in TasteLog als Administrator angemeldet bist. Auf demselben Weg lässt sich das
  Passwort zurücksetzen („Passwort vergessen?“).
- **Daten:** Liegen nie im App-Ordner, weil das Deployment ihn verwaltet und löschen kann. Wenn möglich
  liegen sie außerhalb des Web-Verzeichnisses, sonst (bei Hetzner) in `public_html/.finanzzentrale-daten/`.
  Diesen Ordner legt die App selbst an und sperrt ihn, das Deployment rührt ihn nicht an.
  Tägliche Sicherung der letzten 30 Tage; Export und Wiederherstellung unter *Einstellungen*.
- **Schlüssel:** Die KI-Einschätzung nutzt das Secret `ANTHROPIC_API_KEY`. Das Token für die
  automatische Alarm-Prüfung wird beim Deployment aus `FTP_PASSWORD` abgeleitet, ein eigenes
  Secret ist nicht nötig.
- **Alarm-Prüfung:** `.github/workflows/finanzen-alarme.yml` ruft die Seite täglich etwa alle
  30 Minuten auf. GitHub führt zeitgesteuerte Workflows nur auf dem Standard-Branch aus.
- **Depots:** Trade Republic per CSV-Transaktionsexport aus der App, eToro per Kontoauszug als Excel-Datei (offene Positionen, Kurse live) oder über die offizielle API
  (Schlüssel mit Leserecht, Umgebung „Real“).
- **Selbsttest:** Nach jedem Deployment prüft der Schritt „Finanzzentrale – Selbsttest vom Server“,
  ob alle Datenquellen vom Hetzner-Server aus erreichbar sind (ohne persönliche Daten im Log).
