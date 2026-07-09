<?php
declare(strict_types=1);

// Anmeldung soll 30 Tage halten – eigene Session-Ablage, damit der
// Hoster die Sitzungen nicht früher wegräumt.
$sitzungsDir = __DIR__ . '/daten/sessions';
if (!is_dir($sitzungsDir)) {
    @mkdir($sitzungsDir, 0755, true);
}
if (is_dir($sitzungsDir) && is_writable($sitzungsDir)) {
    session_save_path($sitzungsDir);
}
ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 30));
session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 30,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
date_default_timezone_set('Europe/Berlin');
@ini_set('memory_limit', '512M'); // große Handyfotos beim Verkleinern verarbeiten können
header('Cache-Control: no-cache, must-revalidate'); // Handys sollen immer die frische Version holen

// Passwort zum Mitmachen (Bewerten und Champagner anlegen).
// Zum Ändern: einfach den Text zwischen den Anführungszeichen austauschen.
const PASSWORT = 'dontwastewater';

const DATEN_DATEI = __DIR__ . '/daten/bewertungen.json';
const BILDER_DIR  = __DIR__ . '/bilder';
const MAX_BILD_GROESSE = 25 * 1024 * 1024; // 25 MB pro Foto

$BILD_TYPEN = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];

$KATEGORIEN = [
    'duft'          => ['Duft',          'Wie angenehm und interessant riecht der Champagner?'],
    'perlage'       => ['Perlage',       'Wie fein und angenehm sind die Bläschen?'],
    'geschmack'     => ['Geschmack',     'Wie gut schmeckt er insgesamt?'],
    'balance'       => ['Balance',       'Passen Säure, Frucht und Kraft gut zusammen?'],
    'komplexitaet'  => ['Komplexität',   'Entdeckt man mehrere Aromen oder wirkt er eher einfach?'],
    'abgang'        => ['Abgang',        'Wie lange und angenehm bleibt der Geschmack?'],
    'besonderheit'  => ['Besonderheit',  'Hat der Champagner einen eigenen Charakter oder Wiedererkennungswert?'],
    'trinkfreude'   => ['Trinkfreude',   'Wie gerne würdest du ein zweites Glas trinken oder die Flasche kaufen?'],
];

if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$eingeloggt = ($_SESSION['tasting_ok'] ?? false) === true;

function zurueck(string $query = ''): void
{
    header('Location: ./' . $query);
    exit;
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Daten lesen (ohne Schreibabsicht). */
function datenLaden(): array
{
    if (!is_file(DATEN_DATEI)) {
        return ['champagner' => [], 'bewertungen' => [], 'weingueter' => []];
    }
    $roh = (string)file_get_contents(DATEN_DATEI);
    $d = json_decode($roh, true);
    return is_array($d)
        ? $d + ['champagner' => [], 'bewertungen' => [], 'weingueter' => []]
        : ['champagner' => [], 'bewertungen' => [], 'weingueter' => []];
}

/** Daten unter Sperre ändern: $fn bekommt die Daten und gibt die neuen zurück. */
function datenAendern(callable $fn): void
{
    $dir = dirname(DATEN_DATEI);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $h = fopen(DATEN_DATEI, 'c+');
    if ($h === false) {
        zurueck('?fehler=' . rawurlencode('Daten konnten nicht gespeichert werden.'));
    }
    flock($h, LOCK_EX);
    $roh = stream_get_contents($h);
    $d = json_decode((string)$roh, true);
    if (!is_array($d)) {
        $d = ['champagner' => [], 'bewertungen' => [], 'weingueter' => []];
    }
    $d += ['champagner' => [], 'bewertungen' => [], 'weingueter' => []];
    $d = $fn($d);
    ftruncate($h, 0);
    rewind($h);
    fwrite($h, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Überschreitet der Upload das Server-Limit, kommt der POST leer an –
    // dann eine verständliche Meldung statt "Sitzung abgelaufen".
    if ($_POST === [] && $_FILES === [] && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        zurueck('?fehler=' . rawurlencode('Die Fotos sind zusammen zu groß für einen Upload – bitte weniger Fotos auf einmal auswählen.'));
    }
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        zurueck('?fehler=' . rawurlencode('Die Sitzung ist abgelaufen – bitte nochmal versuchen.'));
    }
    $aktion = (string)($_POST['aktion'] ?? '');

    if ($aktion === 'login') {
        $weiter = (string)($_POST['weiter'] ?? '');
        if (!preg_match('/^\?[A-Za-z0-9=&_%-]*$/', $weiter)) {
            $weiter = '';
        }
        $anhang = $weiter === '' ? '?' : $weiter . '&';
        if (hash_equals(PASSWORT, (string)($_POST['passwort'] ?? ''))) {
            $_SESSION['tasting_ok'] = true;
            zurueck($anhang . 'ok=' . rawurlencode('Willkommen zur Verkostung!'));
        }
        zurueck($anhang . 'fehler=' . rawurlencode('Das Passwort stimmt nicht.'));
    }

    if ($aktion === 'logout') {
        unset($_SESSION['tasting_ok']);
        zurueck('?ok=' . rawurlencode('Abgemeldet.'));
    }

    if (!$eingeloggt) {
        zurueck('?fehler=' . rawurlencode('Bitte zuerst mit dem Passwort anmelden.'));
    }

    if ($aktion === 'champagner_anlegen') {
        $name = trim((string)($_POST['name'] ?? ''));
        $name = mb_substr($name, 0, 60);
        $preis = trim((string)($_POST['preis'] ?? ''));
        $preis = mb_substr($preis, 0, 20);
        $weingutId = (string)($_POST['weingut_id'] ?? '');
        if ($name === '') {
            zurueck('?fehler=' . rawurlencode('Bitte einen Namen für den Champagner angeben.'));
        }
        $neueId = bin2hex(random_bytes(4));
        datenAendern(function (array $d) use ($name, $preis, $weingutId, $neueId): array {
            foreach ($d['champagner'] as $c) {
                if (mb_strtolower($c['name']) === mb_strtolower($name)) {
                    zurueck('?fehler=' . rawurlencode('Diesen Champagner gibt es schon in der Liste.'));
                }
            }
            if ($weingutId !== '' && weingutHolen($d, $weingutId) === null) {
                $weingutId = '';
            }
            $d['champagner'][] = ['id' => $neueId, 'name' => $name, 'preis' => $preis, 'weingut_id' => $weingutId, 'zeit' => time()];
            return $d;
        });
        zurueck('?ok=' . rawurlencode('„' . $name . '“ wurde angelegt – jetzt bewerten!'));
    }

    if ($aktion === 'schnell_foto') {
        // Schritt 1 der Schnell-Erfassung: Foto sichern und Etikett erkennen
        $praefix = 'neu-' . bin2hex(random_bytes(4));
        [$hochgeladen, ] = fotoUploadVerarbeiten($BILD_TYPEN, $praefix);
        $fotoName = '';
        if ($hochgeladen > 0) {
            $treffer = glob(BILDER_DIR . '/' . $praefix . '-*') ?: [];
            $fotoName = $treffer !== [] ? basename($treffer[0]) : '';
        }
        if ($fotoName === '') {
            zurueck('?neu=1&fehler=' . rawurlencode('Das Foto kam nicht an – bitte nochmal versuchen (nur JPG/PNG, max. 25 MB).'));
        }
        $erkannt = etikettErkennen($fotoName);
        // Passen die Foto-Koordinaten zu einem gespeicherten Weingut?
        $gpsHinweis = '';
        $gps = gpsAusFoto(BILDER_DIR . '/' . $fotoName);
        if ($gps !== null) {
            $passendes = weingutPerKoordinaten(datenLaden(), $gps[0], $gps[1]);
            if ($passendes !== null) {
                $gpsHinweis = 'Die Foto-Koordinaten entsprechen dem Weingut „' . $passendes['name'] . '“ – es ist unten vorausgewählt.';
                if ($erkannt['weingut'] === '') {
                    $erkannt['weingut'] = $passendes['name'];
                }
            }
        }
        $_SESSION['neu'] = ['praefix' => $praefix, 'foto' => $fotoName, 'name' => $erkannt['name'], 'weingut' => $erkannt['weingut']];
        zurueck('?neu=2' . ($gpsHinweis !== '' ? '&ok=' . rawurlencode($gpsHinweis) : ''));
    }

    if ($aktion === 'schnell_anlegen') {
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 60);
        $preis = mb_substr(trim((string)($_POST['preis'] ?? '')), 0, 20);
        $weingutId = (string)($_POST['weingut_id'] ?? '');
        $weingutNeu = mb_substr(trim((string)($_POST['weingut_neu'] ?? '')), 0, 60);
        $foto = basename((string)($_POST['foto'] ?? ''));
        if ($name === '') {
            zurueck('?neu=2&fehler=' . rawurlencode('Bitte einen Namen für den Champagner angeben.'));
        }
        $neueId = bin2hex(random_bytes(4));
        $neueWeingutId = bin2hex(random_bytes(4));
        datenAendern(function (array $d) use ($name, $preis, $weingutId, $weingutNeu, $neueId, $neueWeingutId): array {
            foreach ($d['champagner'] as $c) {
                if (mb_strtolower($c['name']) === mb_strtolower($name)) {
                    zurueck('?neu=2&fehler=' . rawurlencode('Diesen Champagner gibt es schon in der Liste.'));
                }
            }
            if ($weingutNeu !== '') {
                $weingutId = '';
                foreach ($d['weingueter'] as $w) {
                    if (mb_strtolower($w['name']) === mb_strtolower($weingutNeu)) {
                        $weingutId = $w['id'];
                        break;
                    }
                }
                if ($weingutId === '') {
                    $d['weingueter'][] = ['id' => $neueWeingutId, 'name' => $weingutNeu, 'notiz' => '', 'zeit' => time()];
                    $weingutId = $neueWeingutId;
                }
            } elseif ($weingutId !== '' && weingutHolen($d, $weingutId) === null) {
                $weingutId = '';
            }
            $d['champagner'][] = ['id' => $neueId, 'name' => $name, 'preis' => $preis, 'weingut_id' => $weingutId, 'zeit' => time()];
            return $d;
        });
        // Alle Fotos vom Zwischen-Präfix auf den neuen Champagner umhängen
        fotosUmhaengen((string)($_SESSION['neu']['praefix'] ?? ''), 'neu', $neueId);
        unset($_SESSION['neu']);
        zurueck('?bewerten=' . rawurlencode($neueId) . '&ok=' . rawurlencode('„' . $name . '“ ist angelegt – jetzt direkt bewerten!'));
    }

    if ($aktion === 'erkennen_bestehend') {
        $cid = (string)($_POST['champagner_id'] ?? '');
        if (champagnerHolen(datenLaden(), $cid) === null) {
            zurueck('?fehler=' . rawurlencode('Dieser Champagner existiert nicht (mehr).'));
        }
        $fotos = fotosFuer($cid);
        if ($fotos === []) {
            zurueck('?ergebnis=' . rawurlencode($cid) . '&fehler=' . rawurlencode('Kein Foto vorhanden – bitte zuerst ein Etikett-Foto hochladen.'));
        }
        $gewaehlt = basename((string)($_POST['foto'] ?? ''));
        $fotoName = in_array($gewaehlt, $fotos, true) ? $gewaehlt : $fotos[0];
        $erkannt = etikettErkennen($fotoName);
        if ($erkannt['name'] === '' && $erkannt['weingut'] === '') {
            zurueck('?ergebnis=' . rawurlencode($cid) . '&fehler=' . rawurlencode('Auf dem Foto war kein Etikett zu erkennen – am besten ein neues Foto direkt vom Etikett hochladen und nochmal versuchen.'));
        }
        $_SESSION['erkannt'] = ['cid' => $cid, 'name' => $erkannt['name'], 'weingut' => $erkannt['weingut']];
        zurueck('?ergebnis=' . rawurlencode($cid) . '&ok=' . rawurlencode('Etikett erkannt – die Vorschläge stehen unten in den Bearbeiten-Feldern. Prüfen und speichern!'));
    }

    if ($aktion === 'champagner_bearbeiten') {
        $cid = (string)($_POST['champagner_id'] ?? '');
        $name = trim((string)($_POST['name'] ?? ''));
        $name = mb_substr($name, 0, 60);
        $preis = trim((string)($_POST['preis'] ?? ''));
        $preis = mb_substr($preis, 0, 20);
        if ($name === '') {
            zurueck('?ergebnis=' . rawurlencode($cid) . '&fehler=' . rawurlencode('Der Name darf nicht leer sein.'));
        }
        datenAendern(function (array $d) use ($cid, $name, $preis): array {
            foreach ($d['champagner'] as $c) {
                if ($c['id'] !== $cid && mb_strtolower($c['name']) === mb_strtolower($name)) {
                    zurueck('?ergebnis=' . rawurlencode($cid) . '&fehler=' . rawurlencode('Ein anderer Champagner heißt schon so.'));
                }
            }
            foreach ($d['champagner'] as &$c) {
                if ($c['id'] === $cid) {
                    $c['name']  = $name;
                    $c['preis'] = $preis;
                }
            }
            return $d;
        });
        unset($_SESSION['erkannt']);
        zurueck('?ergebnis=' . rawurlencode($cid) . '&ok=' . rawurlencode('Name und Preis gespeichert.'));
    }

    if ($aktion === 'foto_upload') {
        $cid = (string)($_POST['champagner_id'] ?? '');
        $champagner = champagnerHolen(datenLaden(), $cid);
        if ($champagner === null) {
            zurueck('?fehler=' . rawurlencode('Dieser Champagner existiert nicht (mehr).'));
        }
        [$hochgeladen, $abgelehnt] = fotoUploadVerarbeiten($BILD_TYPEN, $cid);
        // Foto am Weingut aufgenommen? Dann bei fehlender Zuordnung automatisch zuordnen
        $extra = '';
        if ($hochgeladen > 0 && trim((string)($champagner['weingut_id'] ?? '')) === '') {
            foreach (array_slice(fotosFuer($cid), 0, $hochgeladen) as $foto) {
                $gps = gpsAusFoto(BILDER_DIR . '/' . $foto);
                if ($gps === null) {
                    continue;
                }
                $passendes = weingutPerKoordinaten(datenLaden(), $gps[0], $gps[1]);
                if ($passendes !== null) {
                    $wid = $passendes['id'];
                    datenAendern(function (array $d) use ($cid, $wid): array {
                        foreach ($d['champagner'] as &$c) {
                            if ($c['id'] === $cid) {
                                $c['weingut_id'] = $wid;
                            }
                        }
                        return $d;
                    });
                    $extra = ' Die Foto-Koordinaten entsprechen dem Weingut „' . $passendes['name'] . '“ – es wurde automatisch zugeordnet.';
                    break;
                }
            }
        }
        zurueck('?ergebnis=' . rawurlencode($cid) . '&ok=' . rawurlencode(uploadText($hochgeladen, $abgelehnt) . $extra));
    }

    if ($aktion === 'foto_loeschen') {
        $name = basename((string)($_POST['datei'] ?? ''));
        $pfad = BILDER_DIR . '/' . $name;
        if (preg_match('/^([a-f0-9]{8})-.*\.(jpe?g|png|gif|webp)$/i', $name, $m) && is_file($pfad)) {
            unlink($pfad);
            thumbLoeschen($name);
            zurueck('?ergebnis=' . rawurlencode($m[1]) . '&ok=' . rawurlencode('Foto gelöscht.'));
        }
        zurueck('?fehler=' . rawurlencode('Foto nicht gefunden.'));
    }

    if ($aktion === 'weingut_anlegen') {
        $name = trim((string)($_POST['name'] ?? ''));
        $name = mb_substr($name, 0, 60);
        $notiz = trim((string)($_POST['notiz'] ?? ''));
        $notiz = mb_substr($notiz, 0, 1000);
        if ($name === '') {
            zurueck('?weingueter=1&fehler=' . rawurlencode('Bitte einen Namen für das Weingut angeben.'));
        }
        $neueId = bin2hex(random_bytes(4));
        datenAendern(function (array $d) use ($name, $notiz, $neueId): array {
            foreach ($d['weingueter'] as $w) {
                if (mb_strtolower($w['name']) === mb_strtolower($name)) {
                    zurueck('?weingueter=1&fehler=' . rawurlencode('Dieses Weingut gibt es schon in der Liste.'));
                }
            }
            $d['weingueter'][] = ['id' => $neueId, 'name' => $name, 'notiz' => $notiz, 'zeit' => time()];
            return $d;
        });
        zurueck('?weingut=' . rawurlencode($neueId) . '&ok=' . rawurlencode('„' . $name . '“ wurde angelegt.'));
    }

    if ($aktion === 'weingut_notiz') {
        $id = (string)($_POST['id'] ?? '');
        $notiz = trim((string)($_POST['notiz'] ?? ''));
        $notiz = mb_substr($notiz, 0, 1000);
        $name = trim((string)($_POST['name'] ?? ''));
        $name = mb_substr($name, 0, 60);
        $kontakt = mb_substr(trim((string)($_POST['kontakt'] ?? '')), 0, 900);
        if ($name === '') {
            zurueck('?weingut=' . rawurlencode($id) . '&fehler=' . rawurlencode('Der Name darf nicht leer sein.'));
        }
        datenAendern(function (array $d) use ($id, $name, $notiz, $kontakt): array {
            foreach ($d['weingueter'] as $w) {
                if ($w['id'] !== $id && mb_strtolower($w['name']) === mb_strtolower($name)) {
                    zurueck('?weingut=' . rawurlencode($id) . '&fehler=' . rawurlencode('Ein anderes Weingut heißt schon so.'));
                }
            }
            foreach ($d['weingueter'] as &$w) {
                if ($w['id'] === $id) {
                    $w['name']    = $name;
                    $w['notiz']   = $notiz;
                    $w['kontakt'] = $kontakt;
                }
            }
            return $d;
        });
        zurueck('?weingut=' . rawurlencode($id) . '&ok=' . rawurlencode('Gespeichert.'));
    }

    if ($aktion === 'weingut_loeschen') {
        $id = (string)($_POST['id'] ?? '');
        datenAendern(function (array $d) use ($id): array {
            $d['weingueter'] = array_values(array_filter($d['weingueter'], fn($w) => $w['id'] !== $id));
            foreach ($d['champagner'] as &$c) {
                if (($c['weingut_id'] ?? '') === $id) {
                    $c['weingut_id'] = '';
                }
            }
            return $d;
        });
        if (preg_match('/^[a-f0-9]{8}$/', $id)) {
            foreach (glob(BILDER_DIR . '/wg-' . $id . '-*') ?: [] as $foto) {
                @unlink($foto);
                thumbLoeschen(basename($foto));
            }
        }
        zurueck('?weingueter=1&ok=' . rawurlencode('Weingut gelöscht (Champagner bleiben erhalten).'));
    }

    if ($aktion === 'weingut_foto_upload') {
        $id = (string)($_POST['weingut_id'] ?? '');
        if (weingutHolen(datenLaden(), $id) === null) {
            zurueck('?weingueter=1&fehler=' . rawurlencode('Dieses Weingut existiert nicht (mehr).'));
        }
        [$hochgeladen, $abgelehnt] = fotoUploadVerarbeiten($BILD_TYPEN, 'wg-' . $id);
        // GPS aus den neuen Fotos lesen und daraus den Standort ermitteln
        $extra = '';
        if ($hochgeladen > 0) {
            $gps = null;
            foreach (array_slice(weingutFotos($id), 0, $hochgeladen) as $foto) {
                $gps = gpsAusFoto(BILDER_DIR . '/' . $foto);
                if ($gps !== null) {
                    break;
                }
            }
            if ($gps !== null) {
                $bisherigerKontakt = trim((string)(weingutHolen(datenLaden(), $id)['kontakt'] ?? ''));
                if (!str_contains($bisherigerKontakt, 'Standort (aus Foto-GPS)')) {
                    $standort = standortErmitteln($gps[0], $gps[1]);
                    if ($standort !== '') {
                        $zeile = 'Standort (aus Foto-GPS): ' . $standort . "\n"
                            . 'Karte: https://www.openstreetmap.org/?mlat=' . round($gps[0], 6) . '&mlon=' . round($gps[1], 6) . '#map=17/' . round($gps[0], 6) . '/' . round($gps[1], 6);
                        datenAendern(function (array $d) use ($id, $zeile): array {
                            foreach ($d['weingueter'] as &$w) {
                                if ($w['id'] === $id) {
                                    $alt = trim((string)($w['kontakt'] ?? ''));
                                    $w['kontakt'] = mb_substr(trim($alt . ($alt === '' ? '' : "\n") . $zeile), 0, 900);
                                }
                            }
                            return $d;
                        });
                        $extra = ' Standort aus dem Foto erkannt und im Kontakt-Bereich ergänzt.';
                    }
                }
            }
        }
        zurueck('?weingut=' . rawurlencode($id) . '&ok=' . rawurlencode(uploadText($hochgeladen, $abgelehnt) . $extra));
    }

    if ($aktion === 'weingut_foto_loeschen') {
        $name = basename((string)($_POST['datei'] ?? ''));
        $pfad = BILDER_DIR . '/' . $name;
        if (preg_match('/^wg-([a-f0-9]{8})-.*\.(jpe?g|png|gif|webp)$/i', $name, $m) && is_file($pfad)) {
            unlink($pfad);
            thumbLoeschen($name);
            zurueck('?weingut=' . rawurlencode($m[1]) . '&ok=' . rawurlencode('Foto gelöscht.'));
        }
        zurueck('?fehler=' . rawurlencode('Foto nicht gefunden.'));
    }

    if ($aktion === 'bewertung_loeschen') {
        $cid = (string)($_POST['champagner_id'] ?? '');
        $person = trim((string)($_POST['person'] ?? ''));
        datenAendern(function (array $d) use ($cid, $person): array {
            $d['bewertungen'] = array_values(array_filter(
                $d['bewertungen'],
                fn($b) => !($b['champagner_id'] === $cid && mb_strtolower($b['person']) === mb_strtolower($person))
            ));
            return $d;
        });
        zurueck('?ergebnis=' . rawurlencode($cid) . '&ok=' . rawurlencode('Bewertung von ' . $person . ' gelöscht.'));
    }

    if ($aktion === 'div_foto_upload') {
        [$hochgeladen, $abgelehnt] = fotoUploadVerarbeiten($BILD_TYPEN, 'div');
        zurueck('?fotos=1&ok=' . rawurlencode(uploadText($hochgeladen, $abgelehnt)));
    }

    if ($aktion === 'div_foto_loeschen') {
        $name = basename((string)($_POST['datei'] ?? ''));
        $pfad = BILDER_DIR . '/' . $name;
        if (preg_match('/^div-.*\.(jpe?g|png|gif|webp)$/i', $name) && is_file($pfad)) {
            unlink($pfad);
            thumbLoeschen($name);
            zurueck('?fotos=1&ok=' . rawurlencode('Foto gelöscht.'));
        }
        zurueck('?fotos=1&fehler=' . rawurlencode('Foto nicht gefunden.'));
    }

    if ($aktion === 'champagner_weingut') {
        $cid = (string)($_POST['champagner_id'] ?? '');
        $wid = (string)($_POST['weingut_id'] ?? '');
        $weingutNeu = mb_substr(trim((string)($_POST['weingut_neu'] ?? '')), 0, 60);
        $neueWeingutId = bin2hex(random_bytes(4));
        datenAendern(function (array $d) use ($cid, &$wid, $weingutNeu, $neueWeingutId): array {
            if ($weingutNeu !== '') {
                $wid = '';
                foreach ($d['weingueter'] as $w) {
                    if (mb_strtolower($w['name']) === mb_strtolower($weingutNeu)) {
                        $wid = $w['id'];
                        break;
                    }
                }
                if ($wid === '') {
                    $d['weingueter'][] = ['id' => $neueWeingutId, 'name' => $weingutNeu, 'notiz' => '', 'zeit' => time()];
                    $wid = $neueWeingutId;
                }
            }
            if ($wid !== '') {
                $gefunden = false;
                foreach ($d['weingueter'] as $w) {
                    if ($w['id'] === $wid) {
                        $gefunden = true;
                        break;
                    }
                }
                if (!$gefunden) {
                    zurueck('?fehler=' . rawurlencode('Dieses Weingut existiert nicht (mehr).'));
                }
            }
            foreach ($d['champagner'] as &$c) {
                if ($c['id'] === $cid) {
                    $c['weingut_id'] = $wid;
                }
            }
            return $d;
        });
        unset($_SESSION['erkannt']);
        zurueck('?ergebnis=' . rawurlencode($cid) . '&ok=' . rawurlencode($wid === '' ? 'Zuordnung entfernt.' : 'Weingut zugeordnet.'));
    }

    if ($aktion === 'weingut_foto_neu') {
        // Schritt 1: Foto sichern, GPS auslesen, Standort und Erzeuger ermitteln
        $praefix = 'wneu-' . bin2hex(random_bytes(4));
        [$hochgeladen, ] = fotoUploadVerarbeiten($BILD_TYPEN, $praefix);
        $fotoName = '';
        if ($hochgeladen > 0) {
            $treffer = glob(BILDER_DIR . '/' . $praefix . '-*') ?: [];
            $fotoName = $treffer !== [] ? basename($treffer[0]) : '';
        }
        if ($fotoName === '') {
            zurueck('?wneu=1&fehler=' . rawurlencode('Das Foto kam nicht an – bitte nochmal versuchen (nur JPG/PNG, max. 25 MB).'));
        }
        $name = '';
        $kontakt = '';
        $hinweis = '';
        $gps = gpsAusFoto(BILDER_DIR . '/' . $fotoName);
        if ($gps !== null) {
            $standort = standortErmitteln($gps[0], $gps[1]);
            if ($standort !== '') {
                $kontakt = 'Standort (aus Foto-GPS): ' . $standort . "\n"
                    . 'Karte: https://www.openstreetmap.org/?mlat=' . round($gps[0], 6) . '&mlon=' . round($gps[1], 6) . '#map=17/' . round($gps[0], 6) . '/' . round($gps[1], 6);
                $kandidaten = weingutKandidatenOSM($gps[0], $gps[1]);
                if ($kandidaten !== [] && $kandidaten[0]['dist'] <= 60) {
                    // Man steht praktisch davor – der OSM-Eintrag ist es
                    $name = mb_substr($kandidaten[0]['name'], 0, 60);
                } else {
                    $name = weingutNameErmitteln($standort, $gps[0], $gps[1], $kandidaten);
                    if ($name === '' && $kandidaten !== []) {
                        // Lieber der nächstgelegene Kandidat als gar kein Vorschlag
                        $name = mb_substr($kandidaten[0]['name'], 0, 60);
                    }
                }
            }
        } else {
            $hinweis = 'Im Foto stecken keine GPS-Daten (beim iPhone: im Auswahldialog „Optionen“ → „Standort“ einschalten). Du kannst den Namen unten von Hand eintragen.';
        }
        $_SESSION['wneu'] = ['praefix' => $praefix, 'foto' => $fotoName, 'name' => $name, 'kontakt' => $kontakt];
        zurueck('?wneu=2' . ($hinweis !== '' ? '&fehler=' . rawurlencode($hinweis) : ''));
    }

    if ($aktion === 'weingut_foto_anlegen') {
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 60);
        $notiz = mb_substr(trim((string)($_POST['notiz'] ?? '')), 0, 1000);
        $kontakt = mb_substr(trim((string)($_POST['kontakt'] ?? '')), 0, 900);
        $foto = basename((string)($_POST['foto'] ?? ''));
        if ($name === '') {
            zurueck('?wneu=2&fehler=' . rawurlencode('Bitte einen Namen für das Weingut angeben.'));
        }
        $neueId = bin2hex(random_bytes(4));
        datenAendern(function (array $d) use ($name, $notiz, $kontakt, $neueId): array {
            foreach ($d['weingueter'] as $w) {
                if (mb_strtolower($w['name']) === mb_strtolower($name)) {
                    zurueck('?wneu=2&fehler=' . rawurlencode('Dieses Weingut gibt es schon in der Liste.'));
                }
            }
            $d['weingueter'][] = ['id' => $neueId, 'name' => $name, 'notiz' => $notiz, 'kontakt' => $kontakt, 'zeit' => time()];
            return $d;
        });
        // Alle Fotos vom Zwischen-Präfix auf das neue Weingut umhängen
        fotosUmhaengen((string)($_SESSION['wneu']['praefix'] ?? ''), 'wneu', 'wg-' . $neueId);
        unset($_SESSION['wneu']);
        zurueck('?weingut=' . rawurlencode($neueId) . '&ok=' . rawurlencode('„' . $name . '“ ist angelegt.'));
    }

    if ($aktion === 'champagner_recherche') {
        $cid = (string)($_POST['champagner_id'] ?? '');
        $c = champagnerHolen(datenLaden(), $cid);
        if ($c === null) {
            zurueck('?fehler=' . rawurlencode('Dieser Champagner existiert nicht (mehr).'));
        }
        $wg = weingutHolen(datenLaden(), (string)($c['weingut_id'] ?? ''));
        $auftrag = 'Recherchiere im Web den Champagner "' . $c['name'] . '"'
            . ($wg !== null ? ' vom Erzeuger "' . $wg['name'] . '"' : '')
            . '. Fasse kurz auf Deutsch zusammen, als Stichpunkte mit Zeilenumbrüchen, insgesamt höchstens ~120 Wörter: '
            . 'Stil und Rebsorten, Dosage, Besonderheiten der Herstellung, Bewertungen/Auszeichnungen (falls findbar), üblicher Preis. '
            . 'Keine Einleitung, keine Erklärungen, keine Quellenangaben.';
        $text = claudeWebsuche($auftrag);
        if ($text === '') {
            zurueck('?ergebnis=' . rawurlencode($cid) . '&fehler=' . rawurlencode('Die Recherche hat nichts Verwertbares ergeben – ggf. Name prüfen und nochmal versuchen.'));
        }
        datenAendern(function (array $d) use ($cid, $text): array {
            foreach ($d['champagner'] as &$c2) {
                if ($c2['id'] === $cid) {
                    $c2['recherche'] = $text;
                }
            }
            return $d;
        });
        zurueck('?ergebnis=' . rawurlencode($cid) . '&ok=' . rawurlencode('Recherche abgeschlossen – Ergebnis steht im Recherche-Bereich.'));
    }

    if ($aktion === 'weingut_recherche') {
        $id = (string)($_POST['id'] ?? '');
        $weingut = weingutHolen(datenLaden(), $id);
        if ($weingut === null) {
            zurueck('?weingueter=1&fehler=' . rawurlencode('Dieses Weingut existiert nicht (mehr).'));
        }
        $auftrag = 'Recherchiere im Web das Champagner-Weingut/Champagnerhaus "' . $weingut['name'] . '" (Champagne, Frankreich). '
            . 'Fasse kurz auf Deutsch zusammen, als Stichpunkte mit Zeilenumbrüchen, insgesamt höchstens ~120 Wörter: '
            . 'Geschichte und Größe, Stil und Besonderheiten, bekannte Cuvées, Preisniveau, Besuchsmöglichkeiten. '
            . 'Keine Einleitung, keine Erklärungen, keine Quellenangaben.';
        $text = claudeWebsuche($auftrag);
        if ($text === '') {
            zurueck('?weingut=' . rawurlencode($id) . '&fehler=' . rawurlencode('Die Recherche hat nichts Verwertbares ergeben – ggf. Name prüfen und nochmal versuchen.'));
        }
        datenAendern(function (array $d) use ($id, $text): array {
            foreach ($d['weingueter'] as &$w) {
                if ($w['id'] === $id) {
                    $w['recherche'] = $text;
                }
            }
            return $d;
        });
        zurueck('?weingut=' . rawurlencode($id) . '&ok=' . rawurlencode('Recherche abgeschlossen – Ergebnis steht im Recherche-Bereich.'));
    }

    if ($aktion === 'weingut_kontakt') {
        $id = (string)($_POST['id'] ?? '');
        $weingut = weingutHolen(datenLaden(), $id);
        if ($weingut === null) {
            zurueck('?weingueter=1&fehler=' . rawurlencode('Dieses Weingut existiert nicht (mehr).'));
        }
        $kontakt = weingutKontaktErmitteln($weingut['name']);
        if ($kontakt === '') {
            zurueck('?weingut=' . rawurlencode($id) . '&fehler=' . rawurlencode('Es wurden keine verlässlichen Kontaktdaten gefunden – du kannst sie unten von Hand eintragen.'));
        }
        datenAendern(function (array $d) use ($id, $kontakt): array {
            foreach ($d['weingueter'] as &$w) {
                if ($w['id'] === $id) {
                    $w['kontakt'] = $kontakt;
                }
            }
            return $d;
        });
        zurueck('?weingut=' . rawurlencode($id) . '&ok=' . rawurlencode('Kontaktdaten gefunden und eingetragen – bitte kurz auf Plausibilität prüfen.'));
    }

    if ($aktion === 'champagner_loeschen') {
        $id = (string)($_POST['id'] ?? '');
        datenAendern(function (array $d) use ($id): array {
            $d['champagner']  = array_values(array_filter($d['champagner'], fn($c) => $c['id'] !== $id));
            $d['bewertungen'] = array_values(array_filter($d['bewertungen'], fn($b) => $b['champagner_id'] !== $id));
            return $d;
        });
        if (preg_match('/^[a-f0-9]{8}$/', $id)) {
            foreach (glob(BILDER_DIR . '/' . $id . '-*') ?: [] as $foto) {
                @unlink($foto);
                thumbLoeschen(basename($foto));
            }
        }
        zurueck('?ok=' . rawurlencode('Champagner samt Bewertungen und Fotos gelöscht.'));
    }

    if ($aktion === 'bewerten') {
        $cid    = (string)($_POST['champagner_id'] ?? '');
        $person = trim((string)($_POST['person'] ?? ''));
        $person = mb_substr($person, 0, 40);
        if ($person === '') {
            zurueck('?bewerten=' . rawurlencode($cid) . '&fehler=' . rawurlencode('Bitte deinen Namen eintragen.'));
        }
        $werte = [];
        foreach ($KATEGORIEN as $schluessel => $info) {
            $v = (int)($_POST[$schluessel] ?? 0);
            if ($v < 1 || $v > 5) {
                zurueck('?bewerten=' . rawurlencode($cid) . '&fehler=' . rawurlencode('Bitte in jeder Kategorie 1–5 Sterne vergeben.'));
            }
            $werte[$schluessel] = $v;
        }
        $notiz = trim((string)($_POST['notiz'] ?? ''));
        $notiz = mb_substr($notiz, 0, 500);
        $flaschen = max(0, min(99, (int)($_POST['flaschen'] ?? 0)));
        $_SESSION['person'] = $person;
        datenAendern(function (array $d) use ($cid, $person, $werte, $notiz, $flaschen): array {
            $existiert = false;
            foreach ($d['champagner'] as $c) {
                if ($c['id'] === $cid) {
                    $existiert = true;
                    break;
                }
            }
            if (!$existiert) {
                zurueck('?fehler=' . rawurlencode('Dieser Champagner existiert nicht (mehr).'));
            }
            // Frühere Bewertung derselben Person für diesen Champagner ersetzen
            $d['bewertungen'] = array_values(array_filter(
                $d['bewertungen'],
                fn($b) => !($b['champagner_id'] === $cid && mb_strtolower($b['person']) === mb_strtolower($person))
            ));
            $d['bewertungen'][] = [
                'champagner_id' => $cid,
                'person'        => $person,
                'werte'         => $werte,
                'notiz'         => $notiz,
                'flaschen'      => $flaschen,
                'zeit'          => time(),
            ];
            return $d;
        });
        zurueck('?ergebnis=' . rawurlencode($cid) . '&ok=' . rawurlencode('Danke, ' . $person . ' – deine Bewertung ist gespeichert!'));
    }

    zurueck();
}

$meldung = (string)($_GET['ok'] ?? '');
$fehler  = (string)($_GET['fehler'] ?? '');

$daten = datenLaden();

/** Alle Bewertungen zu einem Champagner. */
function bewertungenFuer(array $daten, string $cid): array
{
    return array_values(array_filter($daten['bewertungen'], fn($b) => $b['champagner_id'] === $cid));
}

/** Gesamtschnitt (über alle Kategorien und Personen) oder null. */
function gesamtSchnitt(array $bewertungen): ?float
{
    $summe = 0;
    $anzahl = 0;
    foreach ($bewertungen as $b) {
        foreach ($b['werte'] as $w) {
            $summe += (int)$w;
            $anzahl++;
        }
    }
    return $anzahl > 0 ? $summe / $anzahl : null;
}

/** Schnitt je Kategorie. */
function kategorieSchnitte(array $bewertungen, array $KATEGORIEN): array
{
    $result = [];
    foreach ($KATEGORIEN as $schluessel => $info) {
        $summe = 0;
        $anzahl = 0;
        foreach ($bewertungen as $b) {
            if (isset($b['werte'][$schluessel])) {
                $summe += (int)$b['werte'][$schluessel];
                $anzahl++;
            }
        }
        $result[$schluessel] = $anzahl > 0 ? $summe / $anzahl : null;
    }
    return $result;
}

function sterneAnzeige(?float $wert): string
{
    if ($wert === null) {
        return '<span class="sterne-anzeige leer-sterne">noch keine Bewertung</span>';
    }
    $voll = (int)round($wert);
    return '<span class="sterne-anzeige">' . str_repeat('★', $voll) . str_repeat('☆', 5 - $voll)
        . ' <span class="wert">' . number_format($wert, 1, ',', '') . '</span></span>';
}

/** Fotos zu einem Champagner (neueste zuerst). */
function fotosFuer(string $cid): array
{
    if (!preg_match('/^[a-f0-9]{8}$/', $cid)) {
        return [];
    }
    $treffer = glob(BILDER_DIR . '/' . $cid . '-*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE) ?: [];
    usort($treffer, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    return array_map('basename', $treffer);
}

/** Fotos zu einem Weingut (neueste zuerst). */
function weingutFotos(string $id): array
{
    if (!preg_match('/^[a-f0-9]{8}$/', $id)) {
        return [];
    }
    $treffer = glob(BILDER_DIR . '/wg-' . $id . '-*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE) ?: [];
    usort($treffer, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    return array_map('basename', $treffer);
}

/** Diverse Fotos des Albums (neueste zuerst). */
function divFotos(): array
{
    $treffer = glob(BILDER_DIR . '/div-*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE) ?: [];
    usort($treffer, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    return array_map('basename', $treffer);
}

function thumbVerzeichnis(): string
{
    return BILDER_DIR . '/thumbs';
}

/**
 * Verkleinerte, korrekt gedrehte Vorschau erzeugen (max. 640 px Kante).
 * Gibt false zurück, wenn GD fehlt oder das Bild nicht lesbar ist.
 */
function thumbErzeugen(string $quelle, string $ziel, int $maxKante = 640): bool
{
    if (!function_exists('imagecreatetruecolor') || !is_file($quelle)) {
        return false;
    }
    $info = @getimagesize($quelle);
    if ($info === false) {
        return false;
    }
    [$b, $h] = $info;
    // Extrem hochauflösende Fotos (>40 Megapixel) würden das Speicherlimit
    // sprengen – dann lieber keine Vorschau als ein abgebrochener Upload.
    if ($b * $h > 40000000) {
        return false;
    }
    $typ = $info[2];
    $img = match ($typ) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($quelle),
        IMAGETYPE_PNG  => @imagecreatefrompng($quelle),
        IMAGETYPE_GIF  => @imagecreatefromgif($quelle),
        IMAGETYPE_WEBP => @imagecreatefromwebp($quelle),
        default        => false,
    };
    if ($img === false) {
        return false;
    }
    // iPhone-Fotos: Drehung aus den EXIF-Daten übernehmen
    if ($typ === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($quelle);
        $o = (int)($exif['Orientation'] ?? 1);
        if ($o === 3) {
            $img = imagerotate($img, 180, 0);
        } elseif ($o === 6) {
            $img = imagerotate($img, -90, 0);
        } elseif ($o === 8) {
            $img = imagerotate($img, 90, 0);
        }
        $b = imagesx($img);
        $h = imagesy($img);
    }
    $faktor = min(1.0, $maxKante / max($b, $h));
    $nb = max(1, (int)round($b * $faktor));
    $nh = max(1, (int)round($h * $faktor));
    $neu = imagecreatetruecolor($nb, $nh);
    $weiss = imagecolorallocate($neu, 255, 255, 255);
    imagefill($neu, 0, 0, $weiss);
    imagecopyresampled($neu, $img, 0, 0, 0, 0, $nb, $nh, $b, $h);
    if (!is_dir(dirname($ziel))) {
        @mkdir(dirname($ziel), 0755, true);
    }
    $ok = imagejpeg($neu, $ziel, 82);
    imagedestroy($img);
    imagedestroy($neu);
    if ($ok) {
        @chmod($ziel, 0644);
    }
    return (bool)$ok;
}

/** URL der Vorschau eines Fotos; erzeugt sie bei Bedarf, Fallback aufs Original. */
function thumbUrl(string $name): string
{
    $thumb = thumbVerzeichnis() . '/' . $name . '.jpg';
    if (!is_file($thumb) && !thumbErzeugen(BILDER_DIR . '/' . $name, $thumb)) {
        return 'bilder/' . rawurlencode($name);
    }
    return 'bilder/thumbs/' . rawurlencode($name . '.jpg');
}

function thumbLoeschen(string $name): void
{
    @unlink(thumbVerzeichnis() . '/' . $name . '.jpg');
}

/** Alle Fotos eines Zwischen-Präfixes auf das endgültige Ziel-Präfix umbenennen. */
function fotosUmhaengen(string $praefix, string $erwarteterTyp, string $zielPraefix): void
{
    if (!preg_match('/^' . $erwarteterTyp . '-[a-f0-9]{8}$/', $praefix)) {
        return;
    }
    foreach (glob(BILDER_DIR . '/' . $praefix . '-*') ?: [] as $alt) {
        $basis = basename($alt);
        if (!preg_match('/\.(jpe?g|png|gif|webp)$/i', $basis, $m)) {
            continue;
        }
        $neuerName = sprintf('%s-%s-%s.%s', $zielPraefix, date('Ymd-His'), bin2hex(random_bytes(3)), strtolower($m[1]));
        if (rename($alt, BILDER_DIR . '/' . $neuerName)) {
            thumbLoeschen($basis);
            thumbErzeugen(BILDER_DIR . '/' . $neuerName, thumbVerzeichnis() . '/' . $neuerName . '.jpg');
        }
    }
}

function weingutHolen(array $daten, string $id): ?array
{
    foreach ($daten['weingueter'] as $w) {
        if ($w['id'] === $id) {
            return $w;
        }
    }
    return null;
}

/** Hochgeladene Fotos aus $_FILES['fotos'] speichern; gibt [hochgeladen, abgelehnt] zurück. */
function fotoUploadVerarbeiten(array $bildTypen, string $praefix): array
{
    $dateien = $_FILES['fotos'] ?? null;
    if ($dateien === null) {
        return [0, 0];
    }
    if (!is_dir(BILDER_DIR)) {
        mkdir(BILDER_DIR, 0755, true);
    }
    $hochgeladen = 0;
    $abgelehnt   = 0;
    $finfo       = new finfo(FILEINFO_MIME_TYPE);
    foreach ((array)$dateien['name'] as $i => $originalName) {
        if (($dateien['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $abgelehnt++;
            continue;
        }
        $tmp = (string)$dateien['tmp_name'][$i];
        if (!is_uploaded_file($tmp) || (int)$dateien['size'][$i] > MAX_BILD_GROESSE) {
            $abgelehnt++;
            continue;
        }
        $typ = $finfo->file($tmp);
        if (!isset($bildTypen[$typ])) {
            $abgelehnt++;
            continue;
        }
        $ziel = sprintf('%s/%s-%s-%s.%s', BILDER_DIR, $praefix, date('Ymd-His'), bin2hex(random_bytes(3)), $bildTypen[$typ]);
        if (move_uploaded_file($tmp, $ziel)) {
            @chmod($ziel, 0644);
            thumbErzeugen($ziel, thumbVerzeichnis() . '/' . basename($ziel) . '.jpg');
            $hochgeladen++;
        } else {
            $abgelehnt++;
        }
    }
    return [$hochgeladen, $abgelehnt];
}

function uploadText(int $hochgeladen, int $abgelehnt): string
{
    $text = $hochgeladen . ' Foto(s) hochgeladen.';
    if ($abgelehnt > 0) {
        $text .= ' ' . $abgelehnt . ' Datei(en) übersprungen (kein Bild, zu groß oder Fehler).';
    }
    return $text;
}

/**
 * Etikett per KI lesen (Claude API). Liefert ['name'=>..,'weingut'=>..] –
 * leere Strings, wenn kein API-Schlüssel hinterlegt ist oder nichts erkannt wurde.
 */
function etikettErkennen(string $fotoName): array
{
    $leer = ['name' => '', 'weingut' => ''];
    $keyDatei = __DIR__ . '/daten/apikey.php';
    if (!is_file($keyDatei)) {
        return $leer;
    }
    $key = (string)(require $keyDatei);
    if ($key === '' || !function_exists('curl_init')) {
        return $leer;
    }
    // Die kleine Vorschau reicht der KI und spart Übertragung
    $thumb = thumbVerzeichnis() . '/' . $fotoName . '.jpg';
    if (!is_file($thumb) && !thumbErzeugen(BILDER_DIR . '/' . $fotoName, $thumb, 1024)) {
        return $leer;
    }
    $bild = base64_encode((string)file_get_contents($thumb));
    $body = json_encode([
        'model'      => 'claude-haiku-4-5',
        'max_tokens' => 200,
        'messages'   => [[
            'role'    => 'user',
            'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $bild]],
                ['type' => 'text', 'text' => 'Auf dem Foto ist eine Champagner- oder Weinflasche. Lies das Etikett und antworte NUR mit JSON in genau dieser Form: {"weingut":"...","name":"..."} – weingut ist der Erzeuger bzw. das Champagnerhaus, name die Bezeichnung des Weins (mit Cuvée und Jahrgang, falls lesbar, aber ohne Erzeugername). Was du nicht erkennst, lässt du als leeren String.'],
            ],
        ]],
    ]);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'content-type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $antwort = curl_exec($ch);
    curl_close($ch);
    if (!is_string($antwort)) {
        return $leer;
    }
    $j = json_decode($antwort, true);
    $text = (string)($j['content'][0]['text'] ?? '');
    if (preg_match('/\{.*\}/s', $text, $m)) {
        $e = json_decode($m[0], true);
        if (is_array($e)) {
            return [
                'name'    => mb_substr(trim((string)($e['name'] ?? '')), 0, 60),
                'weingut' => mb_substr(trim((string)($e['weingut'] ?? '')), 0, 60),
            ];
        }
    }
    return $leer;
}

/**
 * Kontaktdaten eines Weinguts per KI-Websuche ermitteln.
 * Liefert einen kurzen Textblock oder '' (kein Schlüssel/kein Treffer).
 */
function weingutKontaktErmitteln(string $name): string
{
    $keyDatei = __DIR__ . '/daten/apikey.php';
    if (!is_file($keyDatei)) {
        return '';
    }
    $key = (string)(require $keyDatei);
    if ($key === '' || !function_exists('curl_init')) {
        return '';
    }
    @set_time_limit(120);
    $body = json_encode([
        'model'      => 'claude-haiku-4-5',
        'max_tokens' => 700,
        'tools'      => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 3]],
        'messages'   => [[
            'role'    => 'user',
            'content' => 'Suche im Web die Kontaktdaten des Champagner-Erzeugers/Weinguts "' . $name . '" (Region Champagne, Frankreich). '
                . 'Antworte NUR mit einem kurzen Textblock in diesem Format (Zeilen ohne gesicherte Angabe einfach weglassen, keine Einleitung, keine Erklärungen):' . "\n"
                . 'Adresse: …' . "\n" . 'Telefon: …' . "\n" . 'E-Mail: …' . "\n" . 'Website: …' . "\n" . 'Besuch/Verkostung: …',
        ]],
    ]);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_HTTPHEADER     => [
            'content-type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $antwort = curl_exec($ch);
    curl_close($ch);
    if (!is_string($antwort)) {
        return '';
    }
    $j = json_decode($antwort, true);
    // Bei Websuchen zerfällt die Antwort in mehrere Textblöcke (Zitate) –
    // alles hinter dem letzten Suchergebnis zusammensetzen.
    $bloecke = (array)($j['content'] ?? []);
    $letzteSuche = -1;
    foreach ($bloecke as $i => $block) {
        if (($block['type'] ?? '') === 'web_search_tool_result') {
            $letzteSuche = $i;
        }
    }
    $teile = [];
    foreach ($bloecke as $i => $block) {
        if ($i > $letzteSuche && ($block['type'] ?? '') === 'text') {
            $teile[] = (string)($block['text'] ?? '');
        }
    }
    return mb_substr(trim(implode('', $teile)), 0, 900);
}

/** GPS-Koordinaten aus den EXIF-Daten eines Fotos lesen (oder null). */
function gpsAusFoto(string $pfad): ?array
{
    if (!function_exists('exif_read_data') || !is_file($pfad)) {
        return null;
    }
    $exif = @exif_read_data($pfad);
    if (!is_array($exif) || !isset($exif['GPSLatitude'], $exif['GPSLongitude'])) {
        return null;
    }
    $umrechnen = static function (array $teile, string $ref): float {
        $wert = static function ($r): float {
            $p = explode('/', (string)$r);
            return isset($p[1]) && (float)$p[1] !== 0.0 ? (float)$p[0] / (float)$p[1] : (float)$r;
        };
        $dez = $wert($teile[0] ?? '0') + $wert($teile[1] ?? '0') / 60 + $wert($teile[2] ?? '0') / 3600;
        return in_array(strtoupper($ref), ['S', 'W'], true) ? -$dez : $dez;
    };
    $lat = $umrechnen((array)$exif['GPSLatitude'], (string)($exif['GPSLatitudeRef'] ?? 'N'));
    $lon = $umrechnen((array)$exif['GPSLongitude'], (string)($exif['GPSLongitudeRef'] ?? 'E'));
    if (abs($lat) < 0.0001 && abs($lon) < 0.0001) {
        return null;
    }
    return [$lat, $lon];
}

/** Adresse/Ort zu Koordinaten ermitteln (OpenStreetMap Nominatim); '' wenn nichts gefunden. */
function standortErmitteln(float $lat, float $lon): string
{
    if (!function_exists('curl_init')) {
        return '';
    }
    $url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=18&accept-language=de'
        . '&lat=' . rawurlencode((string)$lat) . '&lon=' . rawurlencode((string)$lon);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['User-Agent: fruthzeug.de Champagne26 (post@fruthzeug.de)'],
    ]);
    $antwort = curl_exec($ch);
    curl_close($ch);
    if (!is_string($antwort)) {
        return '';
    }
    $j = json_decode($antwort, true);
    $name    = trim((string)($j['name'] ?? ''));
    $anzeige = trim((string)($j['display_name'] ?? ''));
    if ($anzeige === '') {
        return '';
    }
    return $name !== '' && !str_starts_with($anzeige, $name) ? $name . ', ' . $anzeige : $anzeige;
}

/** Gespeicherte Koordinaten eines Weinguts (aus dem Karten-Link im Kontakt) oder null. */
function weingutKoordinaten(array $w): ?array
{
    if (preg_match('/mlat=(-?[0-9.]+)&(?:amp;)?mlon=(-?[0-9.]+)/', (string)($w['kontakt'] ?? ''), $m)) {
        return [(float)$m[1], (float)$m[2]];
    }
    return null;
}

/** Das gespeicherte Weingut, dessen Koordinaten zum Punkt passen (Umkreis in Metern), oder null. */
function weingutPerKoordinaten(array $daten, float $lat, float $lon, int $maxMeter = 150): ?array
{
    $bestes = null;
    $besteDistanz = PHP_INT_MAX;
    foreach ($daten['weingueter'] as $w) {
        $k = weingutKoordinaten($w);
        if ($k === null) {
            continue;
        }
        $dx = ($k[1] - $lon) * cos(deg2rad($lat)) * 111320;
        $dy = ($k[0] - $lat) * 110540;
        $dist = (int)round(sqrt($dx * $dx + $dy * $dy));
        if ($dist <= $maxMeter && $dist < $besteDistanz) {
            $bestes = $w;
            $besteDistanz = $dist;
        }
    }
    return $bestes;
}

/** In OpenStreetMap verzeichnete Weingüter/Weinhändler im Umkreis (sortiert nach Entfernung). */
function weingutKandidatenOSM(float $lat, float $lon): array
{
    if (!function_exists('curl_init')) {
        return [];
    }
    $q = '[out:json][timeout:15];('
        . 'nwr(around:200,' . $lat . ',' . $lon . ')["craft"="winery"];'
        . 'nwr(around:200,' . $lat . ',' . $lon . ')["shop"~"^(wine|alcohol)$"];'
        . 'nwr(around:200,' . $lat . ',' . $lon . ')["name"~"[Cc]hampagne"];'
        . ');out center tags 20;';
    $ch = curl_init('https://overpass-api.de/api/interpreter');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'data=' . rawurlencode($q),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['User-Agent: fruthzeug.de Champagne26 (post@fruthzeug.de)'],
    ]);
    $antwort = curl_exec($ch);
    curl_close($ch);
    if (!is_string($antwort)) {
        return [];
    }
    $j = json_decode($antwort, true);
    $kandidaten = [];
    foreach ((array)($j['elements'] ?? []) as $e) {
        $name = trim((string)($e['tags']['name'] ?? ''));
        $eLat = (float)($e['lat'] ?? ($e['center']['lat'] ?? 0));
        $eLon = (float)($e['lon'] ?? ($e['center']['lon'] ?? 0));
        if ($name === '' || ($eLat === 0.0 && $eLon === 0.0)) {
            continue;
        }
        // grobe Distanz in Metern (für kleine Abstände ausreichend)
        $dx = ($eLon - $lon) * cos(deg2rad($lat)) * 111320;
        $dy = ($eLat - $lat) * 110540;
        $dist = (int)round(sqrt($dx * $dx + $dy * $dy));
        // Riesige Regions-Polygone (z. B. Landschaft "Champagne crayeuse") aussortieren
        if ($dist > 250) {
            continue;
        }
        $kandidaten[] = ['name' => $name, 'dist' => $dist];
    }
    usort($kandidaten, static fn(array $a, array $b): int => $a['dist'] <=> $b['dist']);
    return $kandidaten;
}

/** Per KI-Websuche ermitteln, welcher Erzeuger an einem Standort sitzt; '' wenn unklar. */
function weingutNameErmitteln(string $standort, float $lat, float $lon, array $kandidaten = []): string
{
    $keyDatei = __DIR__ . '/daten/apikey.php';
    if ($standort === '' || !is_file($keyDatei)) {
        return '';
    }
    $key = (string)(require $keyDatei);
    if ($key === '' || !function_exists('curl_init')) {
        return '';
    }
    @set_time_limit(120);
    $hinweise = '';
    if ($kandidaten !== []) {
        $teile = array_map(
            static fn(array $k): string => '„' . $k['name'] . '“ (' . $k['dist'] . ' m entfernt)',
            array_slice($kandidaten, 0, 5)
        );
        $hinweise = ' Laut OpenStreetMap gibt es in der Nähe: ' . implode(', ', $teile) . '.';
    }
    $body = json_encode([
        'model'      => 'claude-haiku-4-5',
        'max_tokens' => 400,
        'tools'      => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 3]],
        'messages'   => [[
            'role'    => 'user',
            'content' => 'An diesem Ort wurde ein Foto aufgenommen: "' . $standort . '" (GPS ' . round($lat, 5) . ', ' . round($lon, 5) . ').' . $hinweise
                . ' Welcher Champagner-Erzeuger (Weingut/Champagnerhaus) hat an genau dieser Adresse oder unmittelbar daneben seinen Sitz bzw. seine Verkaufsstelle? '
                . 'In so einem kleinen Umkreis gibt es in der Regel genau einen Erzeuger – suche im Web nach der Adresse und nenne den wahrscheinlichsten, auch wenn du nicht hundertprozentig sicher bist. '
                . 'Antworte NUR mit JSON in genau dieser Form: {"weingut":"..."} – leer nur dann, wenn wirklich nichts dafür spricht, dass dort ein Erzeuger sitzt.',
        ]],
    ]);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_HTTPHEADER     => [
            'content-type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $antwort = curl_exec($ch);
    curl_close($ch);
    if (!is_string($antwort)) {
        return '';
    }
    $j = json_decode($antwort, true);
    $bloecke = (array)($j['content'] ?? []);
    $letzteSuche = -1;
    foreach ($bloecke as $i => $block) {
        if (($block['type'] ?? '') === 'web_search_tool_result') {
            $letzteSuche = $i;
        }
    }
    $text = '';
    foreach ($bloecke as $i => $block) {
        if ($i > $letzteSuche && ($block['type'] ?? '') === 'text') {
            $text .= (string)($block['text'] ?? '');
        }
    }
    if (preg_match('/\{.*\}/s', $text, $m)) {
        $e = json_decode($m[0], true);
        if (is_array($e)) {
            return mb_substr(trim((string)($e['weingut'] ?? '')), 0, 60);
        }
    }
    return '';
}

/** Allgemeine KI-Websuche: liefert die Textantwort oder '' (kein Schlüssel/kein Ergebnis). */
function claudeWebsuche(string $auftrag, int $maxTokens = 800): string
{
    $keyDatei = __DIR__ . '/daten/apikey.php';
    if (!is_file($keyDatei)) {
        return '';
    }
    $key = (string)(require $keyDatei);
    if ($key === '' || !function_exists('curl_init')) {
        return '';
    }
    @set_time_limit(120);
    $body = json_encode([
        'model'      => 'claude-haiku-4-5',
        'max_tokens' => $maxTokens,
        'tools'      => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 4]],
        'messages'   => [['role' => 'user', 'content' => $auftrag]],
    ]);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_HTTPHEADER     => [
            'content-type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $antwort = curl_exec($ch);
    curl_close($ch);
    if (!is_string($antwort)) {
        return '';
    }
    $j = json_decode($antwort, true);
    $bloecke = (array)($j['content'] ?? []);
    $letzteSuche = -1;
    foreach ($bloecke as $i => $block) {
        if (($block['type'] ?? '') === 'web_search_tool_result') {
            $letzteSuche = $i;
        }
    }
    $text = '';
    foreach ($bloecke as $i => $block) {
        if ($i > $letzteSuche && ($block['type'] ?? '') === 'text') {
            $text .= (string)($block['text'] ?? '');
        }
    }
    return mb_substr(trim($text), 0, 1500);
}

/** Kontakt-Text fürs Anzeigen: escapen, Links klickbar machen, Zeilenumbrüche. */
function verlinken(string $text): string
{
    $esc = e($text);
    $esc = preg_replace('~(https?://[^\s<]+)~', '<a href="$1" target="_blank" rel="noopener">$1</a>', $esc) ?? $esc;
    $esc = preg_replace('~(?<![/\w])(www\.[^\s<]+)~', '<a href="https://$1" target="_blank" rel="noopener">$1</a>', $esc) ?? $esc;
    return nl2br($esc);
}

/** Anmeldeformular; $weiter = Query der aktuellen Seite (z. B. "?ergebnis=abc"), um dorthin zurückzukehren. */
function loginFormular(string $weiter = ''): string
{
    $csrf = e((string)$_SESSION['csrf']);
    $weiterFeld = $weiter === '' ? '' : '<input type="hidden" name="weiter" value="' . e($weiter) . '">';
    return '<h2>Zum Mitmachen anmelden</h2>
      <form method="post">
        <input type="hidden" name="csrf" value="' . $csrf . '">
        <input type="hidden" name="aktion" value="login">' . $weiterFeld . '
        <input type="password" name="passwort" placeholder="Passwort" autocomplete="current-password" required>
        <button class="knopf" type="submit">Anmelden</button>
      </form>';
}

function preisZeile(array $c): string
{
    $preis = trim((string)($c['preis'] ?? ''));
    return $preis === '' ? '' : ' &middot; ' . e($preis);
}

function champagnerHolen(array $daten, string $id): ?array
{
    foreach ($daten['champagner'] as $c) {
        if ($c['id'] === $id) {
            return $c;
        }
    }
    return null;
}

// Welche Ansicht?
$ansicht = 'liste';
$aktiverChampagner = null;
$aktivesWeingut = null;
if (isset($_GET['bewerten'])) {
    $aktiverChampagner = champagnerHolen($daten, (string)$_GET['bewerten']);
    if ($aktiverChampagner !== null) {
        $ansicht = 'bewerten';
    }
} elseif (isset($_GET['ergebnis'])) {
    $aktiverChampagner = champagnerHolen($daten, (string)$_GET['ergebnis']);
    if ($aktiverChampagner !== null) {
        $ansicht = 'ergebnis';
    }
} elseif (isset($_GET['weingut'])) {
    $aktivesWeingut = weingutHolen($daten, (string)$_GET['weingut']);
    if ($aktivesWeingut !== null) {
        $ansicht = 'weingut';
    }
} elseif (isset($_GET['weingueter'])) {
    $ansicht = 'weingueter';
} elseif (isset($_GET['fotos'])) {
    $ansicht = 'fotos';
} elseif (isset($_GET['neu'])) {
    $ansicht = 'neu';
} elseif (isset($_GET['wneu'])) {
    $ansicht = 'wneu';
}

$personVorschlag = (string)($_SESSION['person'] ?? '');
$sortierung = ($_GET['sort'] ?? 'datum') === 'name' ? 'name' : 'datum';
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title>Champagne 26 – fruthzeug.de</title>
  <style>
    :root {
      --bg: #faf9f7;
      --text: #2a2a28;
      --muted: #6b6b66;
      --accent: #b4532a;
      --card: #ffffff;
      --border: #e8e6e1;
      --stern: #d99a1b;
      --ok: #2e7d32;
      --warn: #b3261e;
    }
    @media (prefers-color-scheme: dark) {
      :root {
        --bg: #1c1b19;
        --text: #ece9e4;
        --muted: #a3a09a;
        --accent: #e08050;
        --card: #262421;
        --border: #3a3833;
        --stern: #e8b544;
        --ok: #7cc47f;
        --warn: #ef8a80;
      }
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
      font-family: Georgia, 'Times New Roman', serif;
      background: var(--bg);
      color: var(--text);
      line-height: 1.7;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }
    a { color: var(--accent); }

    .site-header {
      position: sticky; top: 0; z-index: 10;
      display: flex; align-items: center; gap: 1rem;
      padding: 0.85rem 1.2rem;
      background: var(--card); border-bottom: 1px solid var(--border);
    }
    .brand { margin-right: auto; font-size: 1.15rem; color: var(--text); text-decoration: none; }
    .brand strong { color: var(--accent); font-weight: normal; }
    #nav-toggle { display: none; }
    .burger { display: none; }
    nav.site-nav { display: flex; gap: 1.4rem; }
    nav.site-nav a { color: var(--text); text-decoration: none; padding: 0.15rem 0; border-bottom: 2px solid transparent; }
    nav.site-nav a:hover,
    nav.site-nav a[aria-current="page"] { color: var(--accent); border-bottom-color: var(--accent); }
    @media (max-width: 700px) {
      .burger { display: flex; flex-direction: column; justify-content: center; gap: 5px; padding: 8px; cursor: pointer; -webkit-tap-highlight-color: transparent; }
      .burger span { display: block; width: 26px; height: 3px; background: var(--text); border-radius: 2px; transition: transform 0.25s, opacity 0.25s; }
      nav.site-nav {
        display: none; position: absolute; top: 100%; left: 0; right: 0;
        flex-direction: column; gap: 0; background: var(--card);
        border-bottom: 1px solid var(--border); box-shadow: 0 8px 16px rgba(0,0,0,0.08);
      }
      nav.site-nav a { padding: 0.95rem 1.4rem; border-bottom: 1px solid var(--border); }
      #nav-toggle:checked ~ nav.site-nav { display: flex; }
      #nav-toggle:checked ~ .burger span:nth-child(1) { transform: translateY(8px) rotate(45deg); }
      #nav-toggle:checked ~ .burger span:nth-child(2) { opacity: 0; }
      #nav-toggle:checked ~ .burger span:nth-child(3) { transform: translateY(-8px) rotate(-45deg); }
    }

    main { flex: 1; width: 100%; max-width: 46rem; margin: 0 auto; padding: 2rem 1.2rem 4rem; }
    .zurueck { margin-bottom: 0.7rem; }
    .zurueck a { text-decoration: none; font-size: 1.05rem; }
    h1 { font-size: clamp(1.7rem, 5vw, 2.4rem); font-weight: normal; margin-bottom: 0.3rem; }
    .untertitel { color: var(--muted); font-style: italic; margin-bottom: 1.6rem; }

    .hinweis { border-radius: 8px; padding: 0.8rem 1.1rem; margin-bottom: 1.4rem; border: 1px solid var(--border); background: var(--card); }
    .hinweis.ok { border-left: 4px solid var(--ok); }
    .hinweis.fehler { border-left: 4px solid var(--warn); }

    .card {
      background: var(--card); border: 1px solid var(--border);
      border-radius: 10px; padding: 1.4rem 1.6rem; margin-bottom: 1rem;
    }
    .card h2 { font-size: 1.15rem; font-weight: normal; color: var(--accent); margin-bottom: 0.5rem; }

    .champagner-zeile { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }
    .champagner-zeile .info { flex: 1; min-width: 12rem; }
    .champagner-zeile .name { font-size: 1.15rem; }
    .sterne-anzeige { color: var(--stern); letter-spacing: 2px; white-space: nowrap; }
    .sterne-anzeige .wert { color: var(--muted); font-size: 0.9rem; letter-spacing: normal; }
    .leer-sterne { color: var(--muted); font-style: italic; font-size: 0.9rem; letter-spacing: normal; }
    .anzahl { color: var(--muted); font-size: 0.9rem; }

    .knopfreihe { display: flex; gap: 0.6rem; flex-wrap: wrap; }
    a.knopf, button.knopf {
      display: inline-block; text-decoration: none; text-align: center;
      background: var(--accent); color: #fff; border: none; border-radius: 8px;
      padding: 0.55rem 1.1rem; font-size: 0.95rem; cursor: pointer; font-family: inherit;
    }
    a.knopf.zweit, button.knopf.zweit {
      background: transparent; color: var(--accent); border: 1px solid var(--accent);
    }
    a.knopf:active, button:active {
      transform: scale(0.96);
      filter: brightness(0.85);
    }
    a.knopf, button { transition: transform 0.08s, filter 0.08s; }
    button[disabled] { opacity: 0.55; cursor: wait; }
    @keyframes pulsieren { 50% { opacity: 0.4; } }
    button.laedt { animation: pulsieren 1s infinite; }
    button.loeschen { background: none; border: none; color: var(--muted); text-decoration: underline; cursor: pointer; font-size: 0.85rem; font-family: inherit; }

    input[type="password"], input[type="text"], input[type="number"], input[type="file"], textarea, select {
      width: 100%; padding: 0.65rem; border: 1px solid var(--border); border-radius: 8px;
      background: var(--bg); color: var(--text); font-size: 1rem; margin-bottom: 0.8rem;
      font-family: inherit;
    }
    textarea { resize: vertical; }
    .thumb {
      width: 72px; height: 72px; object-fit: cover; border-radius: 8px;
      border: 1px solid var(--border); display: block; flex-shrink: 0;
    }
    .thumb.platzhalter {
      display: flex; align-items: center; justify-content: center;
      font-size: 1.9rem; background: var(--bg);
    }
    a.knopf.gross {
      display: block; text-align: center; font-size: 1.1rem;
      padding: 0.9rem 1.2rem; margin-bottom: 1.2rem;
    }
    a.knopf.klein { padding: 0.45rem 0.95rem; font-size: 0.9rem; white-space: nowrap; }
    .card.flasche {
      display: flex; align-items: center; gap: 0.9rem;
      padding: 0.9rem 1.1rem; margin-bottom: 0.7rem;
    }
    .flasche-link {
      flex: 1; min-width: 0; display: flex; align-items: center; gap: 0.9rem;
      text-decoration: none; color: var(--text);
    }
    .flasche-info { display: flex; flex-direction: column; min-width: 0; line-height: 1.45; }
    .f-name { font-size: 1.12rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .f-meta { color: var(--muted); font-size: 0.88rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .f-wertung { font-size: 0.95rem; }
    .foto-galerie {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
      gap: 0.6rem;
    }
    .foto {
      position: relative; border: 1px solid var(--border); border-radius: 8px;
      overflow: hidden; aspect-ratio: 1; background: var(--bg);
    }
    .foto img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .foto form { position: absolute; top: 6px; right: 6px; }
    .foto button {
      border: none; border-radius: 6px; padding: 4px 9px; cursor: pointer;
      background: rgba(0,0,0,0.55); color: #fff; font-size: 0.85rem;
    }
    .sortier-leiste { color: var(--muted); font-size: 0.9rem; margin-bottom: 0.7rem; }
    .sortier-leiste a {
      color: var(--muted); text-decoration: none;
      padding: 2px 10px; border-radius: 999px; border: 1px solid var(--border);
      margin-left: 0.3rem; white-space: nowrap;
    }
    .sortier-leiste a.aktiv { color: var(--accent); border-color: var(--accent); }

    /* Listenansicht: Kopf bleibt stehen, nur die Einträge scrollen */
    body.listenansicht { height: 100vh; height: 100dvh; overflow: hidden; }
    body.listenansicht main {
      flex: 1 1 auto; min-height: 0;
      display: flex; flex-direction: column;
      padding-bottom: 0.5rem;
    }
    body.listenansicht main > * { flex-shrink: 0; }
    body.listenansicht h1 { font-size: clamp(1.4rem, 4vw, 1.9rem); }
    body.listenansicht .untertitel { margin-bottom: 1rem; }
    body.listenansicht .liste-scroll {
      flex: 1 1 auto; min-height: 0;
      overflow-y: auto;
      -webkit-overflow-scrolling: touch;
      margin: 0 -0.3rem;
      padding: 0.3rem 0.3rem 2rem;
    }
    body.listenansicht footer { display: none; }

    .foto-wahl { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.8rem; }
    .foto-wahl-item input { display: none; }
    .foto-wahl-item img {
      width: 72px; height: 72px; object-fit: cover; border-radius: 8px;
      border: 2px solid var(--border); cursor: pointer; display: block;
    }
    .foto-wahl-item input:checked + img {
      border-color: var(--accent);
      box-shadow: 0 0 0 2px var(--accent);
    }
    /* Großansicht (Lightbox) */
    #grossansicht {
      position: fixed; inset: 0; z-index: 100;
      background: rgba(0, 0, 0, 0.92);
      display: flex; align-items: center; justify-content: center;
      padding: 1.2rem;
    }
    #grossansicht[hidden] { display: none; }
    #grossansicht img {
      max-width: 100%; max-height: 100%;
      object-fit: contain; border-radius: 6px;
    }
    #grossansicht .schliessen {
      position: absolute; top: max(0.8rem, env(safe-area-inset-top)); right: 1rem;
      background: rgba(255, 255, 255, 0.15); color: #fff;
      border: none; border-radius: 50%;
      width: 44px; height: 44px; font-size: 1.5rem; line-height: 1;
      cursor: pointer;
    }

    .notiz { padding: 0.4rem 0; border-bottom: 1px solid var(--border); }
    .notiz:last-of-type { border-bottom: none; }
    .notiz b { font-weight: normal; color: var(--accent); }

    /* Sterne-Eingabe (5 Radios, rückwärts angeordnet) */
    .kategorie { padding: 0.9rem 0; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }
    .kategorie:last-of-type { border-bottom: none; }
    .kategorie .frage { flex: 1; min-width: 14rem; }
    .kategorie .frage b { font-weight: normal; font-size: 1.05rem; }
    .kategorie .frage small { display: block; color: var(--muted); line-height: 1.45; }
    .sterne { display: inline-flex; flex-direction: row-reverse; }
    .sterne input { display: none; }
    .sterne label { font-size: 2rem; color: var(--border); cursor: pointer; padding: 0 0.12rem; transition: color 0.1s; user-select: none; -webkit-tap-highlight-color: transparent; }
    .sterne input:checked ~ label { color: var(--stern); }
    @media (hover: hover) {
      .sterne label:hover, .sterne label:hover ~ label { color: var(--stern); }
    }

    table { border-collapse: collapse; width: 100%; font-size: 0.92rem; }
    th, td { padding: 0.45rem 0.6rem; border-bottom: 1px solid var(--border); text-align: center; white-space: nowrap; }
    th:first-child, td:first-child { text-align: left; }
    thead th { color: var(--muted); font-weight: normal; font-style: italic; }
    .tabelle-scroll { overflow-x: auto; }

    .ergebnis-kategorie { display: flex; justify-content: space-between; gap: 1rem; padding: 0.45rem 0; border-bottom: 1px solid var(--border); }
    .ergebnis-kategorie:last-of-type { border-bottom: none; }

    .gesamt { text-align: center; padding: 1rem 0 0.6rem; }
    .gesamt .sterne-anzeige { font-size: 1.7rem; }
    .gesamt .anzahl { display: block; }

    .abmelden { margin-top: 0.9rem; font-size: 0.9rem; }
    .abmelden button { background: none; border: none; color: var(--muted); text-decoration: underline; cursor: pointer; font-size: 0.9rem; font-family: inherit; }

    footer { text-align: center; padding: 2rem 1.5rem; color: var(--muted); font-size: 0.9rem; border-top: 1px solid var(--border); }
  </style>
</head>
<body<?= in_array($ansicht, ['liste', 'weingueter'], true) ? ' class="listenansicht"' : '' ?>>
  <header class="site-header">
    <a class="brand" href="/"><strong>fruthzeug</strong>.de</a>
    <input type="checkbox" id="nav-toggle" aria-hidden="true">
    <label for="nav-toggle" class="burger" aria-label="Menü öffnen">
      <span></span><span></span><span></span>
    </label>
    <nav class="site-nav">
      <a href="/">Start</a>
      <a href="/#projekte">Projekte</a>
      <a href="/projekte/champagner/" aria-current="page">Champagne 26</a>
      <a href="mailto:post@fruthzeug.de">Kontakt</a>
      <a href="#" id="neu-laden">&#10227; Neu laden</a>
    </nav>
  </header>

  <main>
    <?php if ($ansicht === 'liste'): ?>
      <h1>Champagne 26 🍾</h1>
      <p class="untertitel">Champagner-Verkostung – jede Person bewertet für sich, 8 Kategorien, 1 bis 5 Sterne.</p>
    <?php elseif ($ansicht === 'weingueter'): ?>
      <h1>Weingüter 🍇</h1>
      <p class="untertitel">Die Erzeuger hinter den Flaschen – mit Notizen und Bildern.</p>
    <?php elseif ($ansicht === 'fotos'): ?>
      <h1>Fotoalbum 📸</h1>
      <p class="untertitel">Diverse Fotos rund um Champagne 26.</p>
    <?php elseif ($ansicht === 'neu'): ?>
      <p class="zurueck"><a href="./">&larr; Zur&uuml;ck zur Champagner-Liste</a></p>
      <h1>Neue Flasche 📷</h1>
      <p class="untertitel">Fotografieren – erkennen – bewerten.</p>
    <?php elseif ($ansicht === 'wneu'): ?>
      <p class="zurueck"><a href="?weingueter=1">&larr; Zur&uuml;ck zur Weingut-Liste</a></p>
      <h1>Neues Weingut 📷</h1>
      <p class="untertitel">Fotografieren – Standort erkennen – anlegen.</p>
    <?php elseif ($ansicht === 'weingut'): ?>
      <p class="zurueck"><a href="?weingueter=1">&larr; Zur&uuml;ck zur Weingut-Liste</a></p>
      <h1><?= e($aktivesWeingut['name']) ?></h1>
      <p class="untertitel">Weingut</p>
    <?php elseif ($ansicht === 'bewerten'): ?>
      <p class="zurueck"><a href="?ergebnis=<?= e(rawurlencode($aktiverChampagner['id'])) ?>">&larr; Zur&uuml;ck zu <?= e($aktiverChampagner['name']) ?></a></p>
      <h1><?= e($aktiverChampagner['name']) ?></h1>
      <p class="untertitel">Deine persönliche Bewertung<?= preisZeile($aktiverChampagner) ?></p>
    <?php else: ?>
      <p class="zurueck"><a href="./">&larr; Zur&uuml;ck zur Champagner-Liste</a></p>
      <h1><?= e($aktiverChampagner['name']) ?></h1>
      <p class="untertitel">Ergebnis der Verkostung<?= preisZeile($aktiverChampagner) ?></p>
    <?php endif; ?>

    <?php if ($meldung !== ''): ?>
      <div class="hinweis ok"><?= e($meldung) ?></div>
    <?php endif; ?>
    <?php if ($fehler !== ''): ?>
      <div class="hinweis fehler"><?= e($fehler) ?></div>
    <?php endif; ?>

    <?php if ($ansicht === 'bewerten'): ?>
      <!-- ==================== BEWERTUNGSFORMULAR ==================== -->
      <?php if (!$eingeloggt): ?>
        <div class="card">
          <?= loginFormular('?bewerten=' . rawurlencode($aktiverChampagner['id'])) ?>
        </div>
      <?php else: ?>
        <?php
          // Bestehende Bewertung der Person vorbelegen (Name aus ?person=… oder aus der Sitzung)
          $formPerson = trim((string)($_GET['person'] ?? $personVorschlag));
          $vorhandene = null;
          if ($formPerson !== '') {
              foreach (bewertungenFuer($daten, $aktiverChampagner['id']) as $b) {
                  if (mb_strtolower($b['person']) === mb_strtolower($formPerson)) {
                      $vorhandene = $b;
                      break;
                  }
              }
          }
        ?>
        <?php if ($vorhandene !== null): ?>
          <div class="hinweis ok">Du änderst die bestehende Bewertung von <b><?= e($vorhandene['person']) ?></b> – Sterne, Notiz und Flaschen sind vorausgefüllt.</div>
        <?php endif; ?>
        <form method="post" class="card">
          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
          <input type="hidden" name="aktion" value="bewerten">
          <input type="hidden" name="champagner_id" value="<?= e($aktiverChampagner['id']) ?>">
          <h2>Wer bewertet?</h2>
          <input type="text" name="person" placeholder="Dein Name" value="<?= e($formPerson) ?>" maxlength="40" required>

          <h2>Persönliche Notiz <span style="color:var(--muted); font-style:italic; font-size:0.9rem;">(optional)</span></h2>
          <textarea name="notiz" placeholder="z. B. erinnert an Brioche und grünen Apfel …" maxlength="500" rows="3"><?= e((string)($vorhandene['notiz'] ?? '')) ?></textarea>

          <h2>Flaschen <span style="color:var(--muted); font-style:italic; font-size:0.9rem;">(wie viele nimmst du mit / hast du gekauft?)</span></h2>
          <input type="number" name="flaschen" min="0" max="99" inputmode="numeric" value="<?= (int)($vorhandene['flaschen'] ?? 0) ?>">

          <?php foreach ($KATEGORIEN as $schluessel => [$titel, $frage]): ?>
            <?php $vorbelegt = (int)($vorhandene['werte'][$schluessel] ?? 0); ?>
            <div class="kategorie">
              <div class="frage">
                <b><?= e($titel) ?></b>
                <small><?= e($frage) ?></small>
              </div>
              <div class="sterne">
                <?php for ($i = 5; $i >= 1; $i--): ?>
                  <input type="radio" id="<?= e($schluessel) ?><?= $i ?>" name="<?= e($schluessel) ?>" value="<?= $i ?>"<?= $vorbelegt === $i ? ' checked' : '' ?> required>
                  <label for="<?= e($schluessel) ?><?= $i ?>" title="<?= $i ?> Sterne">★</label>
                <?php endfor; ?>
              </div>
            </div>
          <?php endforeach; ?>

          <div class="knopfreihe" style="margin-top:1.2rem;">
            <button class="knopf" type="submit"><?= $vorhandene !== null ? 'Änderungen speichern' : 'Bewertung speichern' ?></button>
            <a class="knopf zweit" href="?ergebnis=<?= e(rawurlencode($aktiverChampagner['id'])) ?>">Abbrechen</a>
          </div>
          <p class="abmelden">Pro Person zählt immer nur die neueste Bewertung – du kannst also jederzeit ändern.</p>
        </form>
      <?php endif; ?>

    <?php elseif ($ansicht === 'ergebnis'): ?>
      <!-- ==================== ERGEBNISSEITE ==================== -->
      <?php
        $bewertungen = bewertungenFuer($daten, $aktiverChampagner['id']);
        $gesamt      = gesamtSchnitt($bewertungen);
        $schnitte    = kategorieSchnitte($bewertungen, $KATEGORIEN);
      ?>
      <?php $flaschenGesamt = array_sum(array_map(fn($b) => (int)($b['flaschen'] ?? 0), $bewertungen)); ?>
      <div class="card">
        <div class="gesamt">
          <?= sterneAnzeige($gesamt) ?>
          <span class="anzahl"><?= count($bewertungen) ?> Bewertung(en)<?= $flaschenGesamt > 0 ? ' &middot; ' . $flaschenGesamt . ' Flasche(n)' : '' ?></span>
        </div>
      </div>

      <?php
        $fotos = fotosFuer($aktiverChampagner['id']);
        $erkanntVorschlag = null;
        if (($_SESSION['erkannt']['cid'] ?? '') === $aktiverChampagner['id']) {
            $erkanntVorschlag = $_SESSION['erkannt'];
        }
      ?>
      <?php if ($eingeloggt): ?>
        <div class="card">
          <h2>Name &amp; Preis bearbeiten</h2>
          <?php if ($fotos !== []): ?>
            <form method="post" style="margin-bottom:0.9rem;">
              <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
              <input type="hidden" name="aktion" value="erkennen_bestehend">
              <input type="hidden" name="champagner_id" value="<?= e($aktiverChampagner['id']) ?>">
              <?php if (count($fotos) > 1): ?>
                <p class="anzahl" style="margin-bottom:0.4rem;">Welches Foto zeigt das Etikett? Antippen:</p>
                <div class="foto-wahl">
                  <?php foreach ($fotos as $i => $f): ?>
                    <label class="foto-wahl-item">
                      <input type="radio" name="foto" value="<?= e($f) ?>"<?= $i === 0 ? ' checked' : '' ?>>
                      <img src="<?= e(thumbUrl($f)) ?>" alt="">
                    </label>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <button class="knopf zweit" type="submit">📷&nbsp; Etikett vom Foto erkennen</button>
            </form>
          <?php endif; ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="aktion" value="champagner_bearbeiten">
            <input type="hidden" name="champagner_id" value="<?= e($aktiverChampagner['id']) ?>">
            <input type="text" name="name" value="<?= e($erkanntVorschlag !== null && $erkanntVorschlag['name'] !== '' ? $erkanntVorschlag['name'] : $aktiverChampagner['name']) ?>" maxlength="60" required>
            <input type="text" name="preis" value="<?= e((string)($aktiverChampagner['preis'] ?? '')) ?>" placeholder="Preis, z. B. 39,90 € (optional)" maxlength="20">
            <button class="knopf zweit" type="submit">Speichern</button>
          </form>
        </div>
      <?php endif; ?>
      <div class="card">
        <h2>Fotos</h2>
        <?php if ($fotos === []): ?>
          <p style="color:var(--muted); font-style:italic;">Noch keine Fotos zu diesem Champagner.</p>
        <?php else: ?>
          <div class="foto-galerie">
            <?php foreach ($fotos as $foto): ?>
              <div class="foto">
                <a href="bilder/<?= e(rawurlencode($foto)) ?>" target="_blank">
                  <img src="<?= e(thumbUrl($foto)) ?>" alt="" loading="lazy">
                </a>
                <?php if ($eingeloggt): ?>
                  <form method="post" onsubmit="return confirm('Dieses Foto wirklich löschen?');">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <input type="hidden" name="aktion" value="foto_loeschen">
                    <input type="hidden" name="datei" value="<?= e($foto) ?>">
                    <button type="submit" title="Foto löschen">&#10005;</button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if ($eingeloggt): ?>
          <form method="post" enctype="multipart/form-data" style="margin-top:1rem;">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="aktion" value="foto_upload">
            <input type="hidden" name="champagner_id" value="<?= e($aktiverChampagner['id']) ?>">
            <input type="file" name="fotos[]" accept="image/*" multiple required>
            <button class="knopf" type="submit">Fotos hochladen</button>
          </form>
        <?php else: ?>
          <div style="margin-top:1rem;">
            <?= loginFormular('?ergebnis=' . rawurlencode($aktiverChampagner['id'])) ?>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($bewertungen !== []): ?>
        <div class="card">
          <h2>Durchschnitt je Kategorie</h2>
          <?php foreach ($KATEGORIEN as $schluessel => [$titel, $frage]): ?>
            <div class="ergebnis-kategorie">
              <span><?= e($titel) ?></span>
              <?= sterneAnzeige($schnitte[$schluessel]) ?>
            </div>
          <?php endforeach; ?>
        </div>

        <?php $mitNotiz = array_values(array_filter($bewertungen, fn($b) => trim((string)($b['notiz'] ?? '')) !== '')); ?>
        <?php if ($mitNotiz !== []): ?>
          <div class="card">
            <h2>Notizen</h2>
            <?php foreach ($mitNotiz as $b): ?>
              <p class="notiz"><b><?= e($b['person']) ?>:</b> <?= nl2br(e((string)$b['notiz'])) ?></p>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="card">
          <h2>Einzelbewertungen</h2>
          <div class="tabelle-scroll">
            <table>
              <thead>
                <tr>
                  <th>Person</th>
                  <?php foreach ($KATEGORIEN as [$titel, $frage]): ?>
                    <th><?= e(mb_substr($titel, 0, 4)) ?>.</th>
                  <?php endforeach; ?>
                  <th>Ø</th>
                  <th>Fl.</th>
                  <?php if ($eingeloggt): ?><th></th><?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($bewertungen as $b): ?>
                  <tr>
                    <td><?= e($b['person']) ?></td>
                    <?php $summe = 0; foreach ($KATEGORIEN as $schluessel => $info): $w = (int)($b['werte'][$schluessel] ?? 0); $summe += $w; ?>
                      <td><?= $w ?></td>
                    <?php endforeach; ?>
                    <td><b><?= number_format($summe / count($KATEGORIEN), 1, ',', '') ?></b></td>
                    <td><?= (int)($b['flaschen'] ?? 0) ?></td>
                    <?php if ($eingeloggt): ?>
                      <td>
                        <a href="?bewerten=<?= e(rawurlencode($aktiverChampagner['id'])) ?>&amp;person=<?= e(rawurlencode($b['person'])) ?>">ändern</a>
                        <form method="post" style="display:inline" onsubmit="return confirm('Bewertung von <?= e($b['person']) ?> wirklich löschen?');">
                          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                          <input type="hidden" name="aktion" value="bewertung_loeschen">
                          <input type="hidden" name="champagner_id" value="<?= e($aktiverChampagner['id']) ?>">
                          <input type="hidden" name="person" value="<?= e($b['person']) ?>">
                          <button class="loeschen" type="submit">löschen</button>
                        </form>
                      </td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="anzahl" style="margin-top:0.5rem;">Spalten: <?php $t = []; foreach ($KATEGORIEN as [$titel, $frage]) { $t[] = mb_substr($titel, 0, 4) . '. = ' . $titel; } echo e(implode(', ', $t)); ?>, Fl. = Flaschen</p>
        </div>
      <?php endif; ?>

      <?php $recherche = trim((string)($aktiverChampagner['recherche'] ?? '')); ?>
      <?php if ($recherche !== '' || $eingeloggt): ?>
        <div class="card">
          <h2>Recherche</h2>
          <?php if ($recherche !== ''): ?>
            <p><?= verlinken($recherche) ?></p>
          <?php else: ?>
            <p style="color:var(--muted); font-style:italic;">Noch keine Recherche zu diesem Champagner.</p>
          <?php endif; ?>
          <?php if ($eingeloggt): ?>
            <form method="post" style="margin-top:0.8rem;">
              <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
              <input type="hidden" name="aktion" value="champagner_recherche">
              <input type="hidden" name="champagner_id" value="<?= e($aktiverChampagner['id']) ?>">
              <button class="knopf zweit" type="submit">🔎&nbsp; Im Internet recherchieren</button>
            </form>
            <p class="anzahl" style="margin-top:0.4rem;">Dauert bis zu einer halben Minute; erneutes Recherchieren ersetzt den Text.</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php $zugeordnet = weingutHolen($daten, (string)($aktiverChampagner['weingut_id'] ?? '')); ?>
      <div class="card">
        <h2>Weingut</h2>
        <?php if ($zugeordnet !== null): ?>
          <p><a href="?weingut=<?= e(rawurlencode($zugeordnet['id'])) ?>"><?= e($zugeordnet['name']) ?></a></p>
        <?php else: ?>
          <p style="color:var(--muted); font-style:italic;">Noch keinem Weingut zugeordnet.</p>
        <?php endif; ?>
        <?php if ($eingeloggt): ?>
          <?php
            // Von der Erkennung vorgeschlagenes Weingut: vorhandenes vorauswählen, sonst als neues vorschlagen
            $vorschlagWeingutId = (string)($aktiverChampagner['weingut_id'] ?? '');
            $vorschlagWeingutNeu = '';
            if ($erkanntVorschlag !== null && $erkanntVorschlag['weingut'] !== '') {
                $gefundenes = null;
                foreach ($daten['weingueter'] as $w) {
                    if (mb_strtolower($w['name']) === mb_strtolower($erkanntVorschlag['weingut'])) {
                        $gefundenes = $w;
                        break;
                    }
                }
                if ($gefundenes !== null) {
                    $vorschlagWeingutId = $gefundenes['id'];
                } else {
                    $vorschlagWeingutNeu = $erkanntVorschlag['weingut'];
                }
            }
          ?>
          <form method="post" style="margin-top:0.6rem;">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="aktion" value="champagner_weingut">
            <input type="hidden" name="champagner_id" value="<?= e($aktiverChampagner['id']) ?>">
            <?php if ($daten['weingueter'] !== []): ?>
              <select name="weingut_id">
                <option value="">– kein Weingut –</option>
                <?php foreach ($daten['weingueter'] as $w): ?>
                  <option value="<?= e($w['id']) ?>" <?= $vorschlagWeingutId === $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
            <input type="text" name="weingut_neu" value="<?= e($vorschlagWeingutNeu) ?>" placeholder="… oder neues Weingut eintragen" maxlength="60">
            <button class="knopf zweit" type="submit">Zuordnung speichern</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="knopfreihe">
        <a class="knopf" href="?bewerten=<?= e(rawurlencode($aktiverChampagner['id'])) ?>">Jetzt selbst bewerten</a>
        <a class="knopf zweit" href="./">Zur Übersicht</a>
      </div>
      <?php if ($eingeloggt): ?>
        <form method="post" onsubmit="return confirm('„<?= e($aktiverChampagner['name']) ?>“ samt aller Bewertungen und Fotos löschen?');" style="margin-top:0.8rem;">
          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
          <input type="hidden" name="aktion" value="champagner_loeschen">
          <input type="hidden" name="id" value="<?= e($aktiverChampagner['id']) ?>">
          <button class="loeschen" type="submit">Diesen Champagner löschen</button>
        </form>
      <?php endif; ?>

    <?php elseif ($ansicht === 'neu'): ?>
      <!-- ==================== SCHNELL-ERFASSUNG ==================== -->
      <?php if (!$eingeloggt): ?>
        <div class="card"><?= loginFormular('?neu=1') ?></div>
      <?php elseif ((int)$_GET['neu'] === 2): ?>
        <?php
          $neu = $_SESSION['neu'] ?? ['foto' => '', 'name' => '', 'weingut' => ''];
          $erkanntesWeingut = null;
          if ($neu['weingut'] !== '') {
              foreach ($daten['weingueter'] as $w) {
                  if (mb_strtolower($w['name']) === mb_strtolower($neu['weingut'])) {
                      $erkanntesWeingut = $w;
                      break;
                  }
              }
          }
        ?>
        <?php if ($neu['name'] !== '' || $neu['weingut'] !== ''): ?>
          <div class="hinweis ok">Etikett erkannt – bitte kurz prüfen und ggf. korrigieren.</div>
        <?php elseif ($neu['foto'] !== ''): ?>
          <div class="hinweis ok">Foto gespeichert. Trag Name und Weingut ein – dann geht es direkt zur Bewertung.</div>
        <?php endif; ?>
        <form method="post" class="card">
          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
          <input type="hidden" name="aktion" value="schnell_anlegen">
          <input type="hidden" name="foto" value="<?= e($neu['foto']) ?>">
          <?php if ($neu['foto'] !== ''): ?>
            <img src="<?= e(thumbUrl($neu['foto'])) ?>" alt="" style="max-width:180px; border-radius:10px; border:1px solid var(--border); display:block; margin-bottom:1rem;">
          <?php endif; ?>
          <h2>Champagner</h2>
          <input type="text" name="name" value="<?= e($neu['name']) ?>" placeholder="Name des Champagners" maxlength="60" required>
          <input type="text" name="preis" placeholder="Preis, z. B. 39,90 € (optional)" maxlength="20">
          <h2>Weingut</h2>
          <?php if ($daten['weingueter'] !== []): ?>
            <select name="weingut_id">
              <option value="">– vorhandenes Weingut wählen –</option>
              <?php foreach ($daten['weingueter'] as $w): ?>
                <option value="<?= e($w['id']) ?>"<?= $erkanntesWeingut !== null && $erkanntesWeingut['id'] === $w['id'] ? ' selected' : '' ?>><?= e($w['name']) ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
          <input type="text" name="weingut_neu" value="<?= e($erkanntesWeingut === null ? $neu['weingut'] : '') ?>" placeholder="… oder neues Weingut eintragen" maxlength="60">
          <div class="knopfreihe" style="margin-top:0.6rem;">
            <button class="knopf" type="submit">Speichern &amp; bewerten</button>
            <a class="knopf zweit" href="?neu=1">Anderes Foto</a>
          </div>
        </form>
      <?php else: ?>
        <form method="post" enctype="multipart/form-data" class="card" id="schnellfoto">
          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
          <input type="hidden" name="aktion" value="schnell_foto">
          <h2>1. Etikett fotografieren</h2>
          <p style="margin-bottom:0.9rem;">Mach ein Foto vom Etikett – oder wähl ein vorhandenes Bild aus. Danach geht es automatisch weiter.</p>
          <input type="file" name="fotos[]" accept="image/*" capture="environment" id="foto-kamera" style="display:none;">
          <input type="file" name="fotos[]" accept="image/*" multiple id="foto-galerie" style="display:none;">
          <div class="knopfreihe">
            <label class="knopf" for="foto-kamera" id="kamera-label">📷&nbsp; Foto aufnehmen</label>
            <label class="knopf zweit" for="foto-galerie" id="galerie-label">🖼️&nbsp; Aus Galerie wählen</label>
            <a class="knopf zweit" href="?neu=2">Ohne Foto</a>
          </div>
          <noscript>
            <p style="margin-top:0.8rem;">Bitte Datei oben wählen und dann:</p>
            <button class="knopf" type="submit">Weiter</button>
          </noscript>
        </form>
        <script>
          ['foto-kamera', 'foto-galerie'].forEach(function (id) {
            var input = document.getElementById(id);
            input.addEventListener('change', function () {
              if (!input.files || input.files.length === 0) { return; }
              document.getElementById('kamera-label').textContent = 'Wird hochgeladen …';
              document.getElementById('galerie-label').style.display = 'none';
              document.getElementById('schnellfoto').submit();
            });
          });
        </script>
      <?php endif; ?>

    <?php elseif ($ansicht === 'wneu'): ?>
      <!-- ==================== WEINGUT-SCHNELL-ERFASSUNG ==================== -->
      <?php if (!$eingeloggt): ?>
        <div class="card"><?= loginFormular('?wneu=1') ?></div>
      <?php elseif ((int)$_GET['wneu'] === 2): ?>
        <?php $wneu = $_SESSION['wneu'] ?? ['foto' => '', 'name' => '', 'kontakt' => '']; ?>
        <?php if ($wneu['name'] !== ''): ?>
          <div class="hinweis ok">Erzeuger am Standort gefunden – bitte kurz prüfen und ggf. korrigieren.</div>
        <?php elseif ($wneu['kontakt'] !== ''): ?>
          <div class="hinweis ok">Standort aus dem Foto erkannt – der Name ließ sich nicht sicher bestimmen, bitte eintragen.</div>
        <?php endif; ?>
        <form method="post" class="card">
          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
          <input type="hidden" name="aktion" value="weingut_foto_anlegen">
          <input type="hidden" name="foto" value="<?= e($wneu['foto']) ?>">
          <?php if ($wneu['foto'] !== ''): ?>
            <img src="<?= e(thumbUrl($wneu['foto'])) ?>" alt="" style="max-width:180px; border-radius:10px; border:1px solid var(--border); display:block; margin-bottom:1rem;">
          <?php endif; ?>
          <h2>Weingut</h2>
          <input type="text" name="name" value="<?= e($wneu['name']) ?>" placeholder="Name des Weinguts" maxlength="60" required>
          <textarea name="kontakt" maxlength="900" rows="4" placeholder="Kontakt/Standort (füllt sich aus dem Foto-GPS)"><?= e($wneu['kontakt']) ?></textarea>
          <textarea name="notiz" maxlength="1000" rows="3" placeholder="Notiz, z. B. Besuch am … (optional)"></textarea>
          <div class="knopfreihe" style="margin-top:0.6rem;">
            <button class="knopf" type="submit">Weingut anlegen</button>
            <a class="knopf zweit" href="?wneu=1">Anderes Foto</a>
          </div>
        </form>
      <?php else: ?>
        <form method="post" enctype="multipart/form-data" class="card" id="wneufoto">
          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
          <input type="hidden" name="aktion" value="weingut_foto_neu">
          <h2>1. Weingut fotografieren</h2>
          <p style="margin-bottom:0.9rem;">Mach vor Ort ein Foto (Gebäude, Hof, Schild) – aus den GPS-Daten des Fotos ermitteln wir Standort und Erzeuger. Danach geht es automatisch weiter.</p>
          <input type="file" name="fotos[]" accept="image/*" capture="environment" id="wfoto-kamera" style="display:none;">
          <input type="file" name="fotos[]" accept="image/*" multiple id="wfoto-galerie" style="display:none;">
          <div class="knopfreihe">
            <label class="knopf" for="wfoto-kamera" id="wkamera-label">📷&nbsp; Foto aufnehmen</label>
            <label class="knopf zweit" for="wfoto-galerie" id="wgalerie-label">🖼️&nbsp; Aus Galerie wählen</label>
            <a class="knopf zweit" href="?wneu=2">Ohne Foto</a>
          </div>
          <p class="anzahl" style="margin-top:0.8rem;">iPhone-Tipp: Im Auswahldialog oben „Optionen" → „Standort" einschalten, sonst fehlen die GPS-Daten.</p>
          <noscript>
            <button class="knopf" type="submit">Weiter</button>
          </noscript>
        </form>
        <script>
          ['wfoto-kamera', 'wfoto-galerie'].forEach(function (id) {
            var input = document.getElementById(id);
            input.addEventListener('change', function () {
              if (!input.files || input.files.length === 0) { return; }
              document.getElementById('wkamera-label').textContent = 'Wird hochgeladen und Standort ermittelt …';
              document.getElementById('wgalerie-label').style.display = 'none';
              document.getElementById('wneufoto').submit();
            });
          });
        </script>
      <?php endif; ?>

    <?php elseif ($ansicht === 'fotos'): ?>
      <!-- ==================== FOTOALBUM ==================== -->
      <div class="knopfreihe" style="margin-bottom:1.2rem;">
        <a class="knopf zweit" href="./">Champagner</a>
        <a class="knopf zweit" href="?weingueter=1">Weingüter</a>
        <a class="knopf" href="?fotos=1">Fotos</a>
      </div>

      <?php $fotos = divFotos(); ?>
      <?php if ($fotos === []): ?>
        <div class="card"><p style="color:var(--muted); font-style:italic;">Noch keine Fotos im Album – unten das erste hochladen!</p></div>
      <?php else: ?>
        <div class="foto-galerie" style="margin-bottom:1rem;">
          <?php foreach ($fotos as $foto): ?>
            <div class="foto">
              <a href="bilder/<?= e(rawurlencode($foto)) ?>" target="_blank">
                <img src="<?= e(thumbUrl($foto)) ?>" alt="" loading="lazy">
              </a>
              <?php if ($eingeloggt): ?>
                <form method="post" onsubmit="return confirm('Dieses Foto wirklich löschen?');">
                  <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                  <input type="hidden" name="aktion" value="div_foto_loeschen">
                  <input type="hidden" name="datei" value="<?= e($foto) ?>">
                  <button type="submit" title="Foto löschen">&#10005;</button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="card">
        <?php if ($eingeloggt): ?>
          <h2>Fotos hochladen</h2>
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="aktion" value="div_foto_upload">
            <input type="file" name="fotos[]" accept="image/*" multiple required>
            <button class="knopf" type="submit">Hochladen</button>
          </form>
          <p class="abmelden">Mehrere Fotos auf einmal möglich (max. 25&nbsp;MB pro Foto).</p>
        <?php else: ?>
          <?= loginFormular('?fotos=1') ?>
        <?php endif; ?>
      </div>

    <?php elseif ($ansicht === 'weingueter'): ?>
      <!-- ==================== WEINGUT-LISTE ==================== -->
      <div class="knopfreihe" style="margin-bottom:1.2rem;">
        <a class="knopf zweit" href="./">Champagner</a>
        <a class="knopf" href="?weingueter=1">Weingüter</a>
        <a class="knopf zweit" href="?fotos=1">Fotos</a>
      </div>

      <a class="knopf gross" href="?wneu=1">📷&nbsp; Neues Weingut per Foto</a>
      <div class="sortier-leiste">Sortieren:
        <a class="<?= $sortierung === 'datum' ? 'aktiv' : '' ?>" href="?weingueter=1&amp;sort=datum">Anlagedatum</a>
        <a class="<?= $sortierung === 'name' ? 'aktiv' : '' ?>" href="?weingueter=1&amp;sort=name">Name</a>
      </div>
      <div class="liste-scroll">
      <?php if ($daten['weingueter'] === []): ?>
        <div class="card"><p style="color:var(--muted); font-style:italic;">Noch kein Weingut angelegt – unten das erste eintragen!</p></div>
      <?php endif; ?>

      <?php
        $weingutListe = $daten['weingueter'];
        if ($sortierung === 'name') {
            usort($weingutListe, fn(array $x, array $y): int => strcmp(mb_strtolower($x['name']), mb_strtolower($y['name'])));
        } else {
            usort($weingutListe, fn(array $x, array $y): int => ((int)($y['zeit'] ?? 0)) <=> ((int)($x['zeit'] ?? 0)));
        }
      ?>
      <?php foreach ($weingutListe as $w): ?>
        <?php
          $fotos = weingutFotos($w['id']);
          $anzahlChampagner = count(array_filter($daten['champagner'], fn($c) => ($c['weingut_id'] ?? '') === $w['id']));
          $kurznotiz = trim((string)($w['notiz'] ?? ''));
          if (mb_strlen($kurznotiz) > 120) {
              $kurznotiz = mb_substr($kurznotiz, 0, 120) . ' …';
          }
        ?>
        <div class="card">
          <div class="champagner-zeile">
            <?php if ($fotos !== []): ?>
              <a href="?weingut=<?= e(rawurlencode($w['id'])) ?>">
                <img class="thumb" src="<?= e(thumbUrl($fotos[0])) ?>" alt="" loading="lazy">
              </a>
            <?php endif; ?>
            <div class="info">
              <div class="name"><?= e($w['name']) ?></div>
              <span class="anzahl"><?= $anzahlChampagner ?> Champagner zugeordnet</span>
              <?php if ($kurznotiz !== ''): ?>
                <p class="anzahl" style="font-style:italic;"><?= e($kurznotiz) ?></p>
              <?php endif; ?>
            </div>
            <div class="knopfreihe">
              <a class="knopf" href="?weingut=<?= e(rawurlencode($w['id'])) ?>">Ansehen</a>
            </div>
          </div>
        </div>
      <?php endforeach; ?>

      <div class="card">
        <?php if ($eingeloggt): ?>
          <h2>Neues Weingut anlegen</h2>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="aktion" value="weingut_anlegen">
            <input type="text" name="name" placeholder="Name, z. B. Champagne Taittinger" maxlength="60" required>
            <textarea name="notiz" placeholder="Notiz, z. B. Besuch am 12.08., nette Führung … (optional)" maxlength="1000" rows="3"></textarea>
            <button class="knopf" type="submit">Anlegen</button>
          </form>
          <form method="post" class="abmelden">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="aktion" value="logout">
            <button type="submit">Abmelden</button>
          </form>
        <?php else: ?>
          <?= loginFormular('?weingueter=1') ?>
        <?php endif; ?>
      </div>
      </div>

    <?php elseif ($ansicht === 'weingut'): ?>
      <!-- ==================== WEINGUT-DETAIL ==================== -->
      <div class="card">
        <h2>Notiz</h2>
        <?php $wNotiz = trim((string)($aktivesWeingut['notiz'] ?? '')); ?>
        <?php if (!$eingeloggt): ?>
          <?php if ($wNotiz !== ''): ?>
            <p><?= nl2br(e($wNotiz)) ?></p>
          <?php else: ?>
            <p style="color:var(--muted); font-style:italic;">Noch keine Notiz.</p>
          <?php endif; ?>
        <?php else: ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="aktion" value="weingut_notiz">
            <input type="hidden" name="id" value="<?= e($aktivesWeingut['id']) ?>">
            <input type="text" name="name" value="<?= e($aktivesWeingut['name']) ?>" maxlength="60" required>
            <textarea name="notiz" maxlength="1000" rows="4" placeholder="Notiz zum Weingut …"><?= e($wNotiz) ?></textarea>
            <textarea name="kontakt" maxlength="900" rows="4" placeholder="Kontaktdaten (Adresse, Telefon, Website …)"><?= e(trim((string)($aktivesWeingut['kontakt'] ?? ''))) ?></textarea>
            <button class="knopf zweit" type="submit">Speichern</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="card">
        <h2>Kontakt</h2>
        <?php $wKontakt = trim((string)($aktivesWeingut['kontakt'] ?? '')); ?>
        <?php if ($wKontakt !== ''): ?>
          <p><?= verlinken($wKontakt) ?></p>
        <?php else: ?>
          <p style="color:var(--muted); font-style:italic;">Noch keine Kontaktdaten hinterlegt.</p>
        <?php endif; ?>
        <?php if ($eingeloggt): ?>
          <form method="post" style="margin-top:0.8rem;">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="aktion" value="weingut_kontakt">
            <input type="hidden" name="id" value="<?= e($aktivesWeingut['id']) ?>">
            <button class="knopf zweit" type="submit">🔎&nbsp; Kontaktdaten automatisch suchen</button>
          </form>
          <p class="anzahl" style="margin-top:0.4rem;">Die Suche dauert bis zu einer halben Minute; das Ergebnis kannst du oben im Bearbeiten-Feld anpassen.</p>
        <?php endif; ?>
      </div>

      <?php $wRecherche = trim((string)($aktivesWeingut['recherche'] ?? '')); ?>
      <?php if ($wRecherche !== '' || $eingeloggt): ?>
        <div class="card">
          <h2>Recherche</h2>
          <?php if ($wRecherche !== ''): ?>
            <p><?= verlinken($wRecherche) ?></p>
          <?php else: ?>
            <p style="color:var(--muted); font-style:italic;">Noch keine Recherche zu diesem Weingut.</p>
          <?php endif; ?>
          <?php if ($eingeloggt): ?>
            <form method="post" style="margin-top:0.8rem;">
              <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
              <input type="hidden" name="aktion" value="weingut_recherche">
              <input type="hidden" name="id" value="<?= e($aktivesWeingut['id']) ?>">
              <button class="knopf zweit" type="submit">🔎&nbsp; Im Internet recherchieren</button>
            </form>
            <p class="anzahl" style="margin-top:0.4rem;">Dauert bis zu einer halben Minute; erneutes Recherchieren ersetzt den Text.</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php $fotos = weingutFotos($aktivesWeingut['id']); ?>
      <div class="card">
        <h2>Bilder</h2>
        <?php if ($fotos === []): ?>
          <p style="color:var(--muted); font-style:italic;">Noch keine Bilder zu diesem Weingut.</p>
        <?php else: ?>
          <div class="foto-galerie">
            <?php foreach ($fotos as $foto): ?>
              <div class="foto">
                <a href="bilder/<?= e(rawurlencode($foto)) ?>" target="_blank">
                  <img src="<?= e(thumbUrl($foto)) ?>" alt="" loading="lazy">
                </a>
                <?php if ($eingeloggt): ?>
                  <form method="post" onsubmit="return confirm('Dieses Bild wirklich löschen?');">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <input type="hidden" name="aktion" value="weingut_foto_loeschen">
                    <input type="hidden" name="datei" value="<?= e($foto) ?>">
                    <button type="submit" title="Bild löschen">&#10005;</button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if ($eingeloggt): ?>
          <form method="post" enctype="multipart/form-data" style="margin-top:1rem;">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="aktion" value="weingut_foto_upload">
            <input type="hidden" name="weingut_id" value="<?= e($aktivesWeingut['id']) ?>">
            <input type="file" name="fotos[]" accept="image/*" multiple required>
            <button class="knopf" type="submit">Bilder hochladen</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="card">
        <h2>Champagner dieses Weinguts</h2>
        <?php $eigene = array_values(array_filter($daten['champagner'], fn($c) => ($c['weingut_id'] ?? '') === $aktivesWeingut['id'])); ?>
        <?php if ($eigene === []): ?>
          <p style="color:var(--muted); font-style:italic;">Noch kein Champagner zugeordnet. Die Zuordnung machst du auf der Ergebnisseite des jeweiligen Champagners.</p>
        <?php else: ?>
          <?php foreach ($eigene as $c): ?>
            <?php $g = gesamtSchnitt(bewertungenFuer($daten, $c['id'])); ?>
            <div class="ergebnis-kategorie">
              <span><a href="?ergebnis=<?= e(rawurlencode($c['id'])) ?>"><?= e($c['name']) ?></a><?= preisZeile($c) ?></span>
              <?= sterneAnzeige($g) ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <?php if (!$eingeloggt): ?>
        <div class="card">
          <?= loginFormular('?weingut=' . rawurlencode($aktivesWeingut['id'])) ?>
        </div>
      <?php endif; ?>

      <div class="knopfreihe">
        <a class="knopf zweit" href="?weingueter=1">Zur Weingut-Liste</a>
        <a class="knopf zweit" href="./">Zur Champagner-Liste</a>
      </div>
      <?php if ($eingeloggt): ?>
        <form method="post" onsubmit="return confirm('„<?= e($aktivesWeingut['name']) ?>“ löschen? Die Champagner bleiben erhalten, verlieren aber die Zuordnung.');" style="margin-top:0.8rem;">
          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
          <input type="hidden" name="aktion" value="weingut_loeschen">
          <input type="hidden" name="id" value="<?= e($aktivesWeingut['id']) ?>">
          <button class="loeschen" type="submit">Weingut löschen</button>
        </form>
      <?php endif; ?>

    <?php else: ?>
      <!-- ==================== ÜBERSICHT ==================== -->
      <div class="knopfreihe" style="margin-bottom:1.2rem;">
        <a class="knopf" href="./">Champagner</a>
        <a class="knopf zweit" href="?weingueter=1">Weingüter</a>
        <a class="knopf zweit" href="?fotos=1">Fotos</a>
      </div>

      <a class="knopf gross" href="?neu=1">📷&nbsp; Neue Flasche erfassen</a>
      <div class="sortier-leiste">Sortieren:
        <a class="<?= $sortierung === 'datum' ? 'aktiv' : '' ?>" href="?sort=datum">Anlagedatum</a>
        <a class="<?= $sortierung === 'name' ? 'aktiv' : '' ?>" href="?sort=name">Name</a>
      </div>
      <div class="liste-scroll">
      <?php if ($daten['champagner'] === []): ?>
        <div class="card"><p style="color:var(--muted); font-style:italic;">Noch kein Champagner angelegt – oben auf „Neue Flasche erfassen" tippen!</p></div>
      <?php endif; ?>

      <?php
        $champagnerListe = $daten['champagner'];
        if ($sortierung === 'name') {
            usort($champagnerListe, fn(array $x, array $y): int => strcmp(mb_strtolower($x['name']), mb_strtolower($y['name'])));
        } else {
            // Anlagedatum, neueste zuerst
            usort($champagnerListe, fn(array $x, array $y): int => ((int)$y['zeit']) <=> ((int)$x['zeit']));
        }
      ?>
      <?php foreach ($champagnerListe as $c): ?>
        <?php
          $bewertungen = bewertungenFuer($daten, $c['id']);
          $gesamt      = gesamtSchnitt($bewertungen);
          $fotos       = fotosFuer($c['id']);
          $wg          = weingutHolen($daten, (string)($c['weingut_id'] ?? ''));
          $meta        = [];
          if ($wg !== null) { $meta[] = $wg['name']; }
          if (trim((string)($c['preis'] ?? '')) !== '') { $meta[] = (string)$c['preis']; }
        ?>
        <div class="card flasche">
          <a class="flasche-link" href="?ergebnis=<?= e(rawurlencode($c['id'])) ?>">
            <?php if ($fotos !== []): ?>
              <img class="thumb" src="<?= e(thumbUrl($fotos[0])) ?>" alt="" loading="lazy">
            <?php else: ?>
              <span class="thumb platzhalter">🍾</span>
            <?php endif; ?>
            <span class="flasche-info">
              <span class="f-name"><?= e($c['name']) ?></span>
              <?php if ($meta !== []): ?>
                <span class="f-meta"><?= e(implode(' · ', $meta)) ?></span>
              <?php endif; ?>
              <span class="f-wertung"><?= sterneAnzeige($gesamt) ?><?= $bewertungen !== [] ? ' <span class="anzahl">(' . count($bewertungen) . ')</span>' : '' ?></span>
            </span>
          </a>
          <a class="knopf klein" href="?bewerten=<?= e(rawurlencode($c['id'])) ?>">Bewerten</a>
        </div>
      <?php endforeach; ?>

      <?php if ($eingeloggt): ?>
        <form method="post" class="abmelden">
          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
          <input type="hidden" name="aktion" value="logout">
          <button type="submit">Abmelden</button>
        </form>
      <?php else: ?>
        <div class="card">
          <?= loginFormular() ?>
        </div>
      <?php endif; ?>
      </div>
    <?php endif; ?>
  </main>

  <footer>
    <p>&copy; 2026 Carl &middot; <a href="/">Zur&uuml;ck zur Startseite</a></p>
  </footer>

  <div id="grossansicht" hidden>
    <button class="schliessen" type="button" aria-label="Schließen">&#10005;</button>
    <img src="" alt="">
  </div>

  <script>
    // "Neu laden": Seite garantiert frisch am Zwischenspeicher vorbei holen
    document.getElementById('neu-laden').addEventListener('click', function (e) {
      e.preventDefault();
      var u = new URL(location.href);
      u.searchParams.set('_r', Date.now());
      location.replace(u.toString());
    });

    // Fotos in der Großansicht öffnen statt die Seite zu verlassen
    (function () {
      var box = document.getElementById('grossansicht');
      var bild = box.querySelector('img');
      document.querySelectorAll('.foto > a, .bild > a').forEach(function (link) {
        link.addEventListener('click', function (e) {
          e.preventDefault();
          bild.src = link.getAttribute('href');
          box.hidden = false;
          document.body.style.overflow = 'hidden';
        });
      });
      function schliessen() {
        box.hidden = true;
        bild.src = '';
        document.body.style.overflow = '';
      }
      box.addEventListener('click', schliessen);
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { schliessen(); }
      });
    })();

    // Beim Absenden sichtbar machen, dass gearbeitet wird (v. a. Foto-Upload)
    document.querySelectorAll('form').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        var knopf = form.querySelector('button[type="submit"]');
        if (!knopf || knopf.disabled) { return; }
        setTimeout(function () {
          if (e.defaultPrevented) { return; } // z. B. Sicherheitsabfrage abgebrochen
          knopf.disabled = true;
          knopf.classList.add('laedt');
          if (knopf.classList.contains('knopf')) {
            knopf.textContent = form.querySelector('input[type="file"]')
              ? 'Wird hochgeladen …'
              : 'Bitte warten …';
          }
        }, 0);
      });
    });
  </script>
</body>
</html>
