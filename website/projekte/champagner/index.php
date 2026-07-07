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
        zurueck('?ergebnis=' . rawurlencode($cid) . '&ok=' . rawurlencode('Name und Preis gespeichert.'));
    }

    if ($aktion === 'foto_upload') {
        $cid = (string)($_POST['champagner_id'] ?? '');
        if (champagnerHolen(datenLaden(), $cid) === null) {
            zurueck('?fehler=' . rawurlencode('Dieser Champagner existiert nicht (mehr).'));
        }
        [$hochgeladen, $abgelehnt] = fotoUploadVerarbeiten($BILD_TYPEN, $cid);
        zurueck('?ergebnis=' . rawurlencode($cid) . '&ok=' . rawurlencode(uploadText($hochgeladen, $abgelehnt)));
    }

    if ($aktion === 'foto_loeschen') {
        $name = basename((string)($_POST['datei'] ?? ''));
        $pfad = BILDER_DIR . '/' . $name;
        if (preg_match('/^([a-f0-9]{8})-.*\.(jpe?g|png|gif|webp)$/i', $name, $m) && is_file($pfad)) {
            unlink($pfad);
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
        if ($name === '') {
            zurueck('?weingut=' . rawurlencode($id) . '&fehler=' . rawurlencode('Der Name darf nicht leer sein.'));
        }
        datenAendern(function (array $d) use ($id, $name, $notiz): array {
            foreach ($d['weingueter'] as $w) {
                if ($w['id'] !== $id && mb_strtolower($w['name']) === mb_strtolower($name)) {
                    zurueck('?weingut=' . rawurlencode($id) . '&fehler=' . rawurlencode('Ein anderes Weingut heißt schon so.'));
                }
            }
            foreach ($d['weingueter'] as &$w) {
                if ($w['id'] === $id) {
                    $w['name']  = $name;
                    $w['notiz'] = $notiz;
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
        zurueck('?weingut=' . rawurlencode($id) . '&ok=' . rawurlencode(uploadText($hochgeladen, $abgelehnt)));
    }

    if ($aktion === 'weingut_foto_loeschen') {
        $name = basename((string)($_POST['datei'] ?? ''));
        $pfad = BILDER_DIR . '/' . $name;
        if (preg_match('/^wg-([a-f0-9]{8})-.*\.(jpe?g|png|gif|webp)$/i', $name, $m) && is_file($pfad)) {
            unlink($pfad);
            zurueck('?weingut=' . rawurlencode($m[1]) . '&ok=' . rawurlencode('Foto gelöscht.'));
        }
        zurueck('?fehler=' . rawurlencode('Foto nicht gefunden.'));
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
            zurueck('?fotos=1&ok=' . rawurlencode('Foto gelöscht.'));
        }
        zurueck('?fotos=1&fehler=' . rawurlencode('Foto nicht gefunden.'));
    }

    if ($aktion === 'champagner_weingut') {
        $cid = (string)($_POST['champagner_id'] ?? '');
        $wid = (string)($_POST['weingut_id'] ?? '');
        datenAendern(function (array $d) use ($cid, $wid): array {
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
        zurueck('?ergebnis=' . rawurlencode($cid) . '&ok=' . rawurlencode($wid === '' ? 'Zuordnung entfernt.' : 'Weingut zugeordnet.'));
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
}

$personVorschlag = (string)($_SESSION['person'] ?? '');
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
    button.loeschen { background: none; border: none; color: var(--muted); text-decoration: underline; cursor: pointer; font-size: 0.85rem; font-family: inherit; }

    input[type="password"], input[type="text"], input[type="number"], input[type="file"], textarea, select {
      width: 100%; padding: 0.65rem; border: 1px solid var(--border); border-radius: 8px;
      background: var(--bg); color: var(--text); font-size: 1rem; margin-bottom: 0.8rem;
      font-family: inherit;
    }
    textarea { resize: vertical; }
    .thumb {
      width: 72px; height: 72px; object-fit: cover; border-radius: 8px;
      border: 1px solid var(--border); display: block;
    }
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
<body>
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
    <?php elseif ($ansicht === 'weingut'): ?>
      <h1><?= e($aktivesWeingut['name']) ?></h1>
      <p class="untertitel">Weingut</p>
    <?php elseif ($ansicht === 'bewerten'): ?>
      <h1><?= e($aktiverChampagner['name']) ?></h1>
      <p class="untertitel">Deine persönliche Bewertung<?= preisZeile($aktiverChampagner) ?></p>
    <?php else: ?>
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

      <?php if ($eingeloggt): ?>
        <div class="card">
          <h2>Name &amp; Preis bearbeiten</h2>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="aktion" value="champagner_bearbeiten">
            <input type="hidden" name="champagner_id" value="<?= e($aktiverChampagner['id']) ?>">
            <input type="text" name="name" value="<?= e($aktiverChampagner['name']) ?>" maxlength="60" required>
            <input type="text" name="preis" value="<?= e((string)($aktiverChampagner['preis'] ?? '')) ?>" placeholder="Preis, z. B. 39,90 € (optional)" maxlength="20">
            <button class="knopf zweit" type="submit">Speichern</button>
          </form>
        </div>
      <?php endif; ?>

      <?php $fotos = fotosFuer($aktiverChampagner['id']); ?>
      <div class="card">
        <h2>Fotos</h2>
        <?php if ($fotos === []): ?>
          <p style="color:var(--muted); font-style:italic;">Noch keine Fotos zu diesem Champagner.</p>
        <?php else: ?>
          <div class="foto-galerie">
            <?php foreach ($fotos as $foto): ?>
              <div class="foto">
                <a href="bilder/<?= e(rawurlencode($foto)) ?>" target="_blank">
                  <img src="bilder/<?= e(rawurlencode($foto)) ?>" alt="" loading="lazy">
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
                      <td><a href="?bewerten=<?= e(rawurlencode($aktiverChampagner['id'])) ?>&amp;person=<?= e(rawurlencode($b['person'])) ?>">ändern</a></td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="anzahl" style="margin-top:0.5rem;">Spalten: <?php $t = []; foreach ($KATEGORIEN as [$titel, $frage]) { $t[] = mb_substr($titel, 0, 4) . '. = ' . $titel; } echo e(implode(', ', $t)); ?>, Fl. = Flaschen</p>
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
        <?php if ($eingeloggt && $daten['weingueter'] !== []): ?>
          <form method="post" style="margin-top:0.6rem;">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="aktion" value="champagner_weingut">
            <input type="hidden" name="champagner_id" value="<?= e($aktiverChampagner['id']) ?>">
            <select name="weingut_id">
              <option value="">– kein Weingut –</option>
              <?php foreach ($daten['weingueter'] as $w): ?>
                <option value="<?= e($w['id']) ?>" <?= ($aktiverChampagner['weingut_id'] ?? '') === $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="knopf zweit" type="submit">Zuordnung speichern</button>
          </form>
        <?php elseif ($eingeloggt): ?>
          <p class="anzahl">Lege zuerst unter <a href="?weingueter=1">Weingüter</a> eines an.</p>
        <?php endif; ?>
      </div>

      <div class="knopfreihe">
        <a class="knopf" href="?bewerten=<?= e(rawurlencode($aktiverChampagner['id'])) ?>">Jetzt selbst bewerten</a>
        <a class="knopf zweit" href="./">Zur Übersicht</a>
      </div>

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
                <img src="bilder/<?= e(rawurlencode($foto)) ?>" alt="" loading="lazy">
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

      <?php if ($daten['weingueter'] === []): ?>
        <div class="card"><p style="color:var(--muted); font-style:italic;">Noch kein Weingut angelegt – unten das erste eintragen!</p></div>
      <?php endif; ?>

      <?php foreach ($daten['weingueter'] as $w): ?>
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
                <img class="thumb" src="bilder/<?= e(rawurlencode($fotos[0])) ?>" alt="" loading="lazy">
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
            <button class="knopf zweit" type="submit">Speichern</button>
          </form>
        <?php endif; ?>
      </div>

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
                  <img src="bilder/<?= e(rawurlencode($foto)) ?>" alt="" loading="lazy">
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
      <?php if ($daten['champagner'] === []): ?>
        <div class="card"><p style="color:var(--muted); font-style:italic;">Noch kein Champagner angelegt – unten den ersten eintragen!</p></div>
      <?php endif; ?>

      <?php foreach ($daten['champagner'] as $c): ?>
        <?php
          $bewertungen = bewertungenFuer($daten, $c['id']);
          $gesamt      = gesamtSchnitt($bewertungen);
        ?>
        <?php $fotos = fotosFuer($c['id']); ?>
        <div class="card">
          <div class="champagner-zeile">
            <?php if ($fotos !== []): ?>
              <a href="?ergebnis=<?= e(rawurlencode($c['id'])) ?>">
                <img class="thumb" src="bilder/<?= e(rawurlencode($fotos[0])) ?>" alt="" loading="lazy">
              </a>
            <?php endif; ?>
            <div class="info">
              <div class="name"><?= e($c['name']) ?><?= preisZeile($c) ?></div>
              <?php $wg = weingutHolen($daten, (string)($c['weingut_id'] ?? '')); ?>
              <?php if ($wg !== null): ?>
                <span class="anzahl">Weingut: <a href="?weingut=<?= e(rawurlencode($wg['id'])) ?>"><?= e($wg['name']) ?></a></span><br>
              <?php endif; ?>
              <?= sterneAnzeige($gesamt) ?>
              <span class="anzahl">&middot; <?= count($bewertungen) ?> Bewertung(en)</span>
            </div>
            <div class="knopfreihe">
              <a class="knopf" href="?bewerten=<?= e(rawurlencode($c['id'])) ?>">Bewerten</a>
              <a class="knopf zweit" href="?ergebnis=<?= e(rawurlencode($c['id'])) ?>">Details &amp; Fotos</a>
            </div>
          </div>
          <?php if ($eingeloggt): ?>
            <form method="post" onsubmit="return confirm('„<?= e($c['name']) ?>“ samt aller Bewertungen löschen?');" style="margin-top:0.5rem;">
              <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
              <input type="hidden" name="aktion" value="champagner_loeschen">
              <input type="hidden" name="id" value="<?= e($c['id']) ?>">
              <button class="loeschen" type="submit">Löschen</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <div class="card">
        <?php if ($eingeloggt): ?>
          <h2>Neuen Champagner anlegen</h2>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="aktion" value="champagner_anlegen">
            <input type="text" name="name" placeholder="Name, z. B. Moët &amp; Chandon Brut Impérial" maxlength="60" required>
            <input type="text" name="preis" placeholder="Preis, z. B. 39,90 € (optional)" maxlength="20">
            <?php if ($daten['weingueter'] !== []): ?>
              <select name="weingut_id">
                <option value="">– Weingut zuordnen (optional) –</option>
                <?php foreach ($daten['weingueter'] as $w): ?>
                  <option value="<?= e($w['id']) ?>"><?= e($w['name']) ?></option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
            <button class="knopf" type="submit">Anlegen</button>
          </form>
          <form method="post" class="abmelden">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="aktion" value="logout">
            <button type="submit">Abmelden</button>
          </form>
        <?php else: ?>
          <?= loginFormular() ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </main>

  <footer>
    <p>&copy; 2026 Carl &middot; <a href="/">Zur&uuml;ck zur Startseite</a></p>
  </footer>
</body>
</html>
