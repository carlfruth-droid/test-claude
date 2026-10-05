<?php
declare(strict_types=1);

/*
 * Kurslimits, Wiedervorlagen und Termin-Erinnerungen prüfen und melden
 * (per E-Mail und Push über die App „ntfy“).
 */

function ereignisNeu(array &$d, string $symbol, string $name, string $art, string $text): void
{
    $d['ereignisse'][] = ['id' => neueId(), 'zeit' => time(), 'symbol' => $symbol, 'name' => $name, 'art' => $art, 'text' => $text, 'gelesen' => false];
    if (count($d['ereignisse']) > 300) {
        $d['ereignisse'] = array_values(array_slice($d['ereignisse'], -300));
    }
}

function limitText(array $l, string $waehrung): string
{
    return ($l['typ'] === 'unter' ? 'unter ' : 'über ') . geld((float)$l['wert'], $waehrung);
}

/**
 * Prüft alles Fällige. $anlass: 'cron' (automatisch, aktualisiert auch Termine)
 * oder 'seite' (beim Öffnen der App). Rückgabe: Zähler für die Statusanzeige.
 */
function alarmeAusfuehren(string $anlass): array
{
    $d = datenLaden();
    $symbole = [];
    foreach ($d['firmen'] as $s => $f) {
        foreach ($f['limits'] as $l) {
            if (!empty($l['aktiv']) && empty($l['ausgeloest'])) {
                $symbole[] = (string)$s;
                break;
            }
        }
    }
    $kurse = $symbole !== [] ? yahooKurse($symbole) : [];

    // Termine (Quartalszahlen, Dividende) im Hintergrund auffrischen – je Lauf nur wenige Firmen
    $termine = [];
    $analysten = [];
    if ($anlass === 'cron') {
        $kandidaten = array_filter($d['firmen'], static fn(array $f): bool =>
            $f['status'] !== 'verworfen' && time() - (int)$f['termine_zeit'] > 3 * 86400);
        uasort($kandidaten, static fn(array $a, array $b): int => (int)$a['termine_zeit'] <=> (int)$b['termine_zeit']);
        foreach (array_slice(array_keys($kandidaten), 0, 8) as $s) {
            $roh = yahooKennzahlenRoh((string)$s, 86400);
            if ($roh !== null) {
                $termine[(string)$s] = termineAus($roh);
                $analysten[(string)$s] = analystenAusRoh($roh);
            }
        }
    }

    $heute = date('Y-m-d');
    $bald = date('Y-m-d', strtotime('+2 days'));
    $meldungen = [];
    datenAendern(function (array &$d) use ($kurse, $termine, $analysten, $heute, $bald, $anlass, &$meldungen): void {
        foreach ($d['firmen'] as $s => &$f) {
            $s = (string)$s;
            if (isset($termine[$s])) {
                $f['termine'] = $termine[$s];
                $f['termine_zeit'] = time();
            }
            // Kursziel nur übernehmen, wenn vorhanden (die Firmenseite ergänzt es ggf. von der Heimatbörse)
            if (isset($analysten[$s]) && ($analysten[$s]['ziel'] !== null || empty($f['analysten']))) {
                $f['analysten'] = $analysten[$s];
            }
            $kurs = $kurse[$s]['kurs'] ?? null;
            $veraltet = !empty($kurse[$s]['veraltet']);
            foreach ($f['limits'] as &$l) {
                if (empty($l['aktiv']) || !empty($l['ausgeloest']) || $kurs === null || $veraltet) {
                    continue;
                }
                $getroffen = $l['typ'] === 'unter' ? $kurs <= (float)$l['wert'] : $kurs >= (float)$l['wert'];
                if (!$getroffen) {
                    continue;
                }
                $l['ausgeloest'] = time();
                $l['ausloesekurs'] = $kurs;
                $text = $f['name'] . ' ist ' . ($l['typ'] === 'unter' ? 'unter' : 'über') . ' dein Limit gegangen: aktuell '
                    . geld($kurs, $f['waehrung']) . ' (Limit: ' . limitText($l, $f['waehrung']) . ').'
                    . (trim((string)($l['notiz'] ?? '')) !== '' ? ' Notiz: ' . trim((string)$l['notiz']) : '');
                ereignisNeu($d, $s, $f['name'], 'limit', $text);
                $meldungen[] = ['titel' => '🔔 ' . $f['name'] . ' ' . limitText($l, $f['waehrung']), 'text' => $text, 'symbol' => $s];
            }
            unset($l);
            if ($f['wiedervorlage'] !== '' && $f['wiedervorlage'] <= $heute && $f['wv_gemeldet'] !== $f['wiedervorlage']) {
                $f['wv_gemeldet'] = $f['wiedervorlage'];
                $text = 'Wiedervorlage: ' . $f['name'] . ' wolltest du dir heute wieder ansehen.';
                ereignisNeu($d, $s, $f['name'], 'wiedervorlage', $text);
                $meldungen[] = ['titel' => '📅 ' . $f['name'] . ': Wiedervorlage', 'text' => $text, 'symbol' => $s];
            }
            if (!empty($f['termin_erinnerung'])) {
                foreach (['zahlen' => 'Quartalszahlen', 'exdiv' => 'Dividenden-Stichtag (Ex-Tag)'] as $art => $titel) {
                    $tag = (string)($f['termine'][$art] ?? '');
                    $schluessel = $art . ':' . $tag;
                    if ($tag !== '' && $tag >= $heute && $tag <= $bald && !in_array($schluessel, $f['termin_gemeldet'], true)) {
                        $f['termin_gemeldet'][] = $schluessel;
                        $f['termin_gemeldet'] = array_slice($f['termin_gemeldet'], -20);
                        $text = $f['name'] . ': ' . $titel . ' am ' . datum($tag) . '.';
                        ereignisNeu($d, $s, $f['name'], 'termin', $text);
                        $meldungen[] = ['titel' => '🗓️ ' . $f['name'] . ': ' . $titel, 'text' => $text, 'symbol' => $s];
                    }
                }
            }
        }
        unset($f);
        $d['cron'][$anlass] = time();
    });

    $d = datenLaden();
    $versendet = 0;
    foreach ($meldungen as $m) {
        if (benachrichtigen($d, $m['titel'], $m['text'], fzBasisUrl() . '?seite=firma&s=' . rawurlencode($m['symbol']))) {
            $versendet++;
        }
    }
    return ['geprueft' => count($symbole), 'ausgeloest' => count($meldungen), 'versendet' => $versendet];
}

// ---------------------------------------------------------------------------
// Benachrichtigungen
// ---------------------------------------------------------------------------

function benachrichtigen(array $d, string $titel, string $text, string $link): bool
{
    $erg = benachrichtigenMitDetails($d, $titel, $text, $link);
    return in_array(true, array_column($erg, 'ok'), true);
}

/**
 * Verschickt über alle eingerichteten Wege und merkt sich das Ergebnis je Weg
 * (für die Anzeige unter Einstellungen). Rückgabe: [weg => ['ok' => bool, 'text' => …]]
 */
function benachrichtigenMitDetails(array $d, string $titel, string $text, string $link): array
{
    $erg = [];
    $mail = einstellung($d, 'email');
    if ($mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL)) {
        $ok = mailSenden($mail, $titel, $text . "\n\nLink: " . $link);
        $erg['email'] = ['ok' => $ok, 'text' => $ok ? 'an den Mailserver übergeben' : 'vom Server abgelehnt (mail() meldet einen Fehler)'];
    }
    $thema = einstellung($d, 'ntfy');
    if ($thema !== '' && einstellung($d, 'ntfy_aus') !== '1') {
        [$ok, $info] = ntfySenden($thema, $titel, $text, $link);
        $erg['push'] = ['ok' => $ok, 'text' => $info];
    }
    datenAendern(function (array &$d) use ($erg, $titel): void {
        $log = (array)($d['benachrichtigungen'] ?? []);
        array_unshift($log, ['zeit' => time(), 'titel' => mb_substr($titel, 0, 80), 'wege' => $erg]);
        $d['benachrichtigungen'] = array_slice($log, 0, 10);
    });
    return $erg;
}

function mailSenden(string $an, string $betreff, string $text): bool
{
    if (!function_exists('mail')) {
        return false;
    }
    $absender = preg_match('/<([^>]+)>/', FZ_ABSENDER, $m) ? $m[1] : FZ_ABSENDER;
    $domain = substr((string)strrchr($absender, '@'), 1);
    $kopf = 'From: ' . FZ_ABSENDER . "\r\n"
        . 'Reply-To: ' . $absender . "\r\n"
        . 'Date: ' . date(DATE_RFC2822) . "\r\n"
        . 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . ">\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit";
    $inhalt = $text . "\n\n— Deine Finanzzentrale auf fruthzeug.de\n(Keine Anlageberatung. Kurse können verzögert sein.)";
    $betreff = mb_encode_mimeheader($betreff, 'UTF-8', 'B');
    // Absender auch im Umschlag setzen (sonst nimmt der Server den technischen Benutzer – Gmail sortiert das gern aus)
    return @mail($an, $betreff, $inhalt, $kopf, '-f' . $absender) || @mail($an, $betreff, $inhalt, $kopf);
}

/** Push über ntfy.sh (oder einen eigenen ntfy-Server, wenn eine URL eingetragen ist). Rückgabe: [ok, Beschreibung] */
function ntfySenden(string $thema, string $titel, string $text, string $link): array
{
    $server = 'https://ntfy.sh';
    $name = $thema;
    if (preg_match('~^(https?://[^/]+)/([A-Za-z0-9_-]{1,64})/?$~', $thema, $m)) {
        [$server, $name] = [$m[1], $m[2]];
    }
    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name)) {
        return [false, 'ungültiges Thema'];
    }
    $nachricht = ['topic' => $name, 'title' => $titel, 'message' => $text, 'click' => $link, 'tags' => ['chart_with_upwards_trend']];
    $r = http($server . '/', ['post' => (string)json_encode($nachricht), 'kopf' => ['Content-Type: application/json'], 'zeit' => 12]);
    if ($r['code'] >= 200 && $r['code'] < 300) {
        return [true, 'von ntfy angenommen'];
    }
    // ntfy.sh drosselt geteilte Webhosting-Adressen (HTTP 429) – dann schickt die
    // automatische Prüfung (GitHub) die Meldung von dort aus nach
    ntfyVormerken($server, $nachricht);
    $grund = $r['code'] === 0 ? 'nicht erreichbar' : 'HTTP ' . $r['code'];
    return [false, 'ntfy weist den Webserver ab (' . $grund . ') – wird über die automatische Prüfung innerhalb von etwa 30 Minuten nachgeschickt'];
}

function ntfyVormerken(string $server, array $nachricht): void
{
    datenAendern(function (array &$d) use ($server, $nachricht): void {
        $liste = array_filter((array)($d['ntfy_warteschlange'] ?? []), static fn(array $x): bool => (int)$x['zeit'] > time() - 86400);
        $liste[] = ['zeit' => time(), 'server' => $server, 'nachricht' => $nachricht];
        $d['ntfy_warteschlange'] = array_slice(array_values($liste), -50);
    });
}

/** Vorgemerkte Push-Meldungen abholen (und aus der Warteschlange nehmen). */
function ntfyWarteschlangeHolen(): array
{
    return datenAendern(function (array &$d): array {
        $liste = (array)($d['ntfy_warteschlange'] ?? []);
        $d['ntfy_warteschlange'] = [];
        return array_values(array_map(static fn(array $x): array => ['server' => $x['server'], 'nachricht' => $x['nachricht']], $liste));
    });
}

/** Status der automatischen Prüfung für die Anzeige: ['ok' => bool, 'text' => …] */
function cronStatus(array $d): array
{
    $zuletzt = (int)($d['cron']['cron'] ?? 0);
    if ($zuletzt === 0) {
        return ['ok' => false, 'text' => 'Die automatische Prüfung im Hintergrund ist noch nicht aktiv. Bis dahin prüft die App bei jedem Öffnen.'];
    }
    $werktag = (int)date('N') <= 5;
    $grenze = $werktag ? 4 * 3600 : 30 * 3600;
    $stunde = (int)date('G');
    if (time() - $zuletzt > $grenze && ($stunde >= 9 && $stunde <= 22 || !$werktag)) {
        return ['ok' => false, 'text' => 'Die automatische Prüfung lief zuletzt ' . vorZeit($zuletzt) . ' – sie scheint zu stocken (z. B. pausiert GitHub zeitgesteuerte Abläufe nach 60 Tagen ohne Änderungen am Repository).'];
    }
    return ['ok' => true, 'text' => 'Automatische Prüfung zuletzt ' . vorZeit($zuletzt) . '.'];
}
