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
    if ($anlass === 'cron') {
        $kandidaten = array_filter($d['firmen'], static fn(array $f): bool =>
            $f['status'] !== 'verworfen' && time() - (int)$f['termine_zeit'] > 3 * 86400);
        uasort($kandidaten, static fn(array $a, array $b): int => (int)$a['termine_zeit'] <=> (int)$b['termine_zeit']);
        foreach (array_slice(array_keys($kandidaten), 0, 4) as $s) {
            $roh = yahooKennzahlenRoh((string)$s, 86400);
            if ($roh !== null) {
                $termine[(string)$s] = termineAus($roh);
            }
        }
    }

    $heute = date('Y-m-d');
    $bald = date('Y-m-d', strtotime('+2 days'));
    $meldungen = [];
    datenAendern(function (array &$d) use ($kurse, $termine, $heute, $bald, $anlass, &$meldungen): void {
        foreach ($d['firmen'] as $s => &$f) {
            $s = (string)$s;
            if (isset($termine[$s])) {
                $f['termine'] = $termine[$s];
                $f['termine_zeit'] = time();
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
    $ok = false;
    $mail = einstellung($d, 'email');
    if ($mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL)) {
        $ok = mailSenden($mail, $titel, $text . "\n\nZur Firma: " . $link) || $ok;
    }
    $thema = einstellung($d, 'ntfy');
    if ($thema !== '' && einstellung($d, 'ntfy_aus') !== '1') {
        $ok = ntfySenden($thema, $titel, $text, $link) || $ok;
    }
    return $ok;
}

function mailSenden(string $an, string $betreff, string $text): bool
{
    if (!function_exists('mail')) {
        return false;
    }
    $kopf = 'From: ' . FZ_ABSENDER . "\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit";
    $inhalt = $text . "\n\n— Deine Finanzzentrale auf fruthzeug.de\n(Keine Anlageberatung. Kurse können verzögert sein.)";
    return @mail($an, mb_encode_mimeheader($betreff, 'UTF-8', 'B'), $inhalt, $kopf);
}

/** Push über ntfy.sh (oder einen eigenen ntfy-Server, wenn eine URL eingetragen ist). */
function ntfySenden(string $thema, string $titel, string $text, string $link): bool
{
    $server = 'https://ntfy.sh';
    $name = $thema;
    if (preg_match('~^(https?://[^/]+)/([A-Za-z0-9_-]{1,64})/?$~', $thema, $m)) {
        [$server, $name] = [$m[1], $m[2]];
    }
    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name)) {
        return false;
    }
    $r = http($server . '/', [
        'post' => (string)json_encode(['topic' => $name, 'title' => $titel, 'message' => $text, 'click' => $link, 'tags' => ['chart_with_upwards_trend']]),
        'kopf' => ['Content-Type: application/json'], 'zeit' => 12,
    ]);
    return $r['code'] >= 200 && $r['code'] < 300;
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
