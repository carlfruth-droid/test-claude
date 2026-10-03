<?php
declare(strict_types=1);

/*
 * Finanzzentrale – Grundgerüst: Datenablage, Anmeldung, Formatierung.
 */

const FZ_NAME     = 'Finanzzentrale';
const FZ_ABSENDER = 'Finanzzentrale <finanzen@fruthzeug.de>';
const FZ_STATUS   = [
    'depot'      => 'Im Depot',
    'kandidat'   => 'Kaufkandidat',
    'beobachten' => 'Beobachten',
    'geprueft'   => 'Geprüft',
    'verworfen'  => 'Verworfen',
];
const FZ_MODELLE = [
    'claude-opus-5-5'  => 'Claude Opus 5.5 – gründlich (grob 20–40 Cent pro Analyse)',
    'claude-haiku-4-5' => 'Claude Haiku 4.5 – schneller und günstiger (etwa 10 Cent), dafür oberflächlicher',
];
const FZ_STANDARD_MODELL = 'claude-opus-5-5';

// ---------------------------------------------------------------------------
// Datenablage
// ---------------------------------------------------------------------------

/**
 * Ordner für alle Daten – nie im App-Ordner selbst: Den verwaltet das
 * Deployment, und ein Upload von einem Stand ohne die Finanzzentrale würde ihn
 * samt Inhalt löschen. Bevorzugt AUSSERHALB des Web-Verzeichnisses (neben
 * public_html); erlaubt der Hoster das nicht, ein selbst angelegter, per
 * .htaccess gesperrter Ordner im Web-Verzeichnis, den das Deployment nicht kennt.
 * daten/ bleibt nur der letzte Ausweg.
 */
function fzDatenDir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $kandidaten = [dirname(FZ_APP, 3) . '/finanzzentrale-daten', dirname(FZ_APP, 2) . '/.finanzzentrale-daten', FZ_APP . '/daten'];
    foreach ($kandidaten as $kandidat) {
        if ((@is_dir($kandidat) || @mkdir($kandidat, 0700, true)) && @is_writable($kandidat)) {
            $dir = $kandidat;
            break;
        }
    }
    $dir ??= FZ_APP . '/daten';
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "# Finanzdaten sind nicht öffentlich abrufbar.\nRequire all denied\n");
    }
    foreach (['cache', 'sicherungen', 'sitzungen'] as $unter) {
        if (!@is_dir($dir . '/' . $unter)) {
            @mkdir($dir . '/' . $unter, 0700, true);
        }
    }
    datenUmziehen($dir);
    return $dir;
}

/** Daten aus dem früheren Ablageort daten/ einmalig übernehmen. */
function datenUmziehen(string $dir): void
{
    $alt = FZ_APP . '/daten';
    if ($dir === $alt || is_file($dir . '/finanzen.json') || !is_file($alt . '/finanzen.json')) {
        return;
    }
    foreach (['finanzen.json', 'zugang.json', 'broker-zugang.json'] as $datei) {
        if (is_file($alt . '/' . $datei)) {
            @copy($alt . '/' . $datei, $dir . '/' . $datei);
        }
    }
}

/** 'extern' (neben public_html), 'eigen' (eigener Ordner im Web-Verzeichnis) oder 'app' (daten/). */
function fzDatenOrt(): string
{
    $dir = fzDatenDir();
    if ($dir === FZ_APP . '/daten') {
        return 'app';
    }
    return $dir === dirname(FZ_APP, 2) . '/.finanzzentrale-daten' ? 'eigen' : 'extern';
}

function fzDatenExtern(): bool
{
    return fzDatenOrt() !== 'app';
}

function fzPfad(string $name): string
{
    return fzDatenDir() . '/' . $name;
}

function jsonLesen(string $datei): array
{
    if (!is_file($datei)) {
        return [];
    }
    $d = json_decode((string)@file_get_contents($datei), true);
    return is_array($d) ? $d : [];
}

function jsonSchreiben(string $datei, array $daten): bool
{
    $json = json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION);
    if ($json === false) {
        return false;
    }
    $tmp = $datei . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $json) === false) {
        return false;
    }
    if (!@rename($tmp, $datei)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** Geheimnis, das das Deployment in daten/ ablegt (API-Schlüssel, Cron-Token). */
function geheimLesen(string $name): string
{
    $datei = FZ_APP . '/daten/' . $name . '.php';
    if (!is_file($datei)) {
        return '';
    }
    $wert = require $datei;
    return is_string($wert) ? trim($wert) : '';
}

function datenLeer(): array
{
    return ['version' => 1, 'firmen' => [], 'ereignisse' => [], 'einstellungen' => [], 'cron' => [], 'broker' => []];
}

function datenNormal(array $d): array
{
    $d += datenLeer();
    foreach (['firmen', 'ereignisse', 'einstellungen', 'cron', 'broker'] as $k) {
        if (!is_array($d[$k])) {
            $d[$k] = [];
        }
    }
    foreach ($d['firmen'] as $s => $f) {
        $d['firmen'][$s] = firmaNormal(is_array($f) ? $f : [], (string)$s);
    }
    return $d;
}

function firmaNormal(array $f, string $symbol): array
{
    $f += [
        'symbol' => $symbol, 'name' => $symbol, 'waehrung' => '', 'boerse' => '',
        'isin' => '', 'lei' => '', 'wikidata' => '', 'identitaet' => 0, 'suchbegriff' => '',
        'status' => 'geprueft', 'bewertung' => 0, 'these' => '',
        'notizen' => [], 'limits' => [], 'wiedervorlage' => '', 'wv_gemeldet' => '',
        'termin_erinnerung' => false, 'termine' => [], 'termine_zeit' => 0, 'termin_gemeldet' => [],
        'depot' => [], 'pruefungen' => [], 'ki' => null,
        'angelegt' => time(), 'geaendert' => time(),
    ];
    if (!isset(FZ_STATUS[$f['status']])) {
        $f['status'] = 'geprueft';
    }
    foreach (['notizen', 'limits', 'depot', 'pruefungen', 'termine', 'termin_gemeldet'] as $k) {
        if (!is_array($f[$k])) {
            $f[$k] = [];
        }
    }
    return $f;
}

function datenLaden(): array
{
    return datenNormal(jsonLesen(fzPfad('finanzen.json')));
}

/**
 * Daten unter Dateisperre ändern. $fn bekommt die Daten als Referenz und darf
 * einen beliebigen Rückgabewert liefern, der durchgereicht wird.
 */
function datenAendern(callable $fn)
{
    $datei = fzPfad('finanzen.json');
    $sperre = @fopen($datei . '.sperre', 'c');
    if ($sperre !== false) {
        flock($sperre, LOCK_EX);
    }
    try {
        $d = datenNormal(jsonLesen($datei));
        $ergebnis = $fn($d);
        sicherungAnlegen($datei);
        if (!jsonSchreiben($datei, $d)) {
            throw new RuntimeException('Die Daten konnten nicht gespeichert werden – der Speicherort ist nicht beschreibbar.');
        }
        return $ergebnis;
    } finally {
        if ($sperre !== false) {
            flock($sperre, LOCK_UN);
            fclose($sperre);
        }
    }
}

/** Einmal täglich den Stand VOR der ersten Änderung sichern; 30 Tage aufheben. */
function sicherungAnlegen(string $datei): void
{
    if (!is_file($datei)) {
        return;
    }
    $dir = fzPfad('sicherungen');
    $ziel = $dir . '/finanzen-' . date('Y-m-d') . '.json';
    if (is_file($ziel)) {
        return;
    }
    @copy($datei, $ziel);
    $alle = glob($dir . '/finanzen-*.json') ?: [];
    sort($alle);
    while (count($alle) > 30) {
        @unlink((string)array_shift($alle));
    }
}

function einstellung(array $d, string $schluessel, string $standard = ''): string
{
    $wert = $d['einstellungen'][$schluessel] ?? $standard;
    return is_scalar($wert) ? (string)$wert : $standard;
}

function kiModell(array $d): string
{
    $m = einstellung($d, 'modell', FZ_STANDARD_MODELL);
    return isset(FZ_MODELLE[$m]) ? $m : FZ_STANDARD_MODELL;
}

function neueId(): string
{
    return bin2hex(random_bytes(5));
}

// ---------------------------------------------------------------------------
// Sitzung, Anmeldung, Schutz
// ---------------------------------------------------------------------------

function fzHttps(): bool
{
    $h = (string)($_SERVER['HTTPS'] ?? '');
    return ($h !== '' && $h !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/** URL-Pfad der App, z. B. „/projekte/finanzen/“. */
function fzBasisPfad(): string
{
    $p = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
    return rtrim($p, '/') . '/';
}

function fzBasisUrl(): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'fruthzeug.de');
    return (fzHttps() ? 'https' : 'http') . '://' . $host . fzBasisPfad();
}

function cookieOptionen(int $ablauf): array
{
    return ['expires' => $ablauf, 'path' => fzBasisPfad(), 'secure' => fzHttps(), 'httponly' => true, 'samesite' => 'Lax'];
}

function sitzungStarten(): void
{
    $dir = fzPfad('sitzungen');
    if (@is_dir($dir) && @is_writable($dir)) {
        session_save_path($dir);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
    }
    ini_set('session.gc_maxlifetime', (string)(60 * 60 * 12));
    ini_set('session.use_strict_mode', '1');
    session_name('FZSITZUNG');
    session_set_cookie_params(['lifetime' => 0, 'path' => fzBasisPfad(), 'secure' => fzHttps(), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function zugangLaden(): array
{
    $z = jsonLesen(fzPfad('zugang.json'));
    $z += ['passwort' => '', 'merken' => [], 'fehl' => ['n' => 0, 'bis' => 0]];
    if (!is_array($z['merken'])) {
        $z['merken'] = [];
    }
    if (!is_array($z['fehl'])) {
        $z['fehl'] = ['n' => 0, 'bis' => 0];
    }
    return $z;
}

function zugangSpeichern(array $z): void
{
    foreach ($z['merken'] as $h => $ablauf) {
        if ((int)$ablauf < time()) {
            unset($z['merken'][$h]);
        }
    }
    jsonSchreiben(fzPfad('zugang.json'), $z);
}

function passwortGesetzt(): bool
{
    return zugangLaden()['passwort'] !== '';
}

function angemeldet(): bool
{
    if (!empty($_SESSION['fz_ok'])) {
        return true;
    }
    $token = (string)($_COOKIE['FZMERKEN'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return false;
    }
    $z = zugangLaden();
    if ($z['passwort'] === '' || (int)($z['merken'][hash('sha256', $token)] ?? 0) < time()) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['fz_ok'] = true;
    return true;
}

function sitzungAnmelden(bool $merken): void
{
    session_regenerate_id(true);
    $_SESSION['fz_ok'] = true;
    if ($merken) {
        $token = bin2hex(random_bytes(32));
        $z = zugangLaden();
        $z['merken'][hash('sha256', $token)] = time() + 90 * 86400;
        zugangSpeichern($z);
        setcookie('FZMERKEN', $token, cookieOptionen(time() + 90 * 86400));
    }
}

/** Liefert '' bei Erfolg, sonst die Fehlermeldung. */
function anmeldenVersuch(string $passwort, bool $merken): string
{
    $z = zugangLaden();
    $sperre = (int)($z['fehl']['bis'] ?? 0);
    if ($sperre > time()) {
        return 'Zu viele Fehlversuche – bitte in ' . (int)ceil(($sperre - time()) / 60) . ' Minuten noch einmal versuchen.';
    }
    if ($z['passwort'] === '' || !password_verify($passwort, (string)$z['passwort'])) {
        $n = (int)($z['fehl']['n'] ?? 0) + 1;
        $z['fehl'] = $n >= 5 ? ['n' => 0, 'bis' => time() + 600] : ['n' => $n, 'bis' => 0];
        zugangSpeichern($z);
        usleep(400000);
        return $n >= 5 ? 'Zu viele Fehlversuche – die Anmeldung ist für 10 Minuten gesperrt.' : 'Das Passwort stimmt nicht.';
    }
    $z['fehl'] = ['n' => 0, 'bis' => 0];
    if (password_needs_rehash((string)$z['passwort'], PASSWORD_DEFAULT)) {
        $z['passwort'] = password_hash($passwort, PASSWORD_DEFAULT);
    }
    zugangSpeichern($z);
    sitzungAnmelden($merken);
    return '';
}

function passwortSetzen(string $passwort): void
{
    $z = zugangLaden();
    $z['passwort'] = password_hash($passwort, PASSWORD_DEFAULT);
    $z['merken'] = []; // alle „angemeldet bleiben“-Geräte abmelden
    $z['fehl'] = ['n' => 0, 'bis' => 0];
    zugangSpeichern($z);
}

function abmelden(bool $ueberall = false): void
{
    $token = (string)($_COOKIE['FZMERKEN'] ?? '');
    $z = zugangLaden();
    if ($ueberall) {
        $z['merken'] = [];
    } elseif ($token !== '') {
        unset($z['merken'][hash('sha256', $token)]);
    }
    zugangSpeichern($z);
    setcookie('FZMERKEN', '', cookieOptionen(time() - 3600));
    $_SESSION = [];
    session_regenerate_id(true);
}

/**
 * Ist der Besucher auf diesem Gerät als TasteLog-Administrator angemeldet?
 * Nur dann darf das Passwort der Finanzzentrale erstmals festgelegt oder
 * zurückgesetzt werden – so kann niemand Fremdes die App „in Besitz nehmen“.
 */
function tastelogAdmin(): bool
{
    $token = (string)($_COOKIE['benutzer'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return false;
    }
    $datei = dirname(FZ_APP) . '/champagner/daten/bewertungen.json';
    foreach ((jsonLesen($datei)['benutzer'] ?? []) as $b) {
        if (is_array($b) && !empty($b['admin']) && hash_equals((string)($b['token'] ?? ''), $token)) {
            return true;
        }
    }
    return false;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['csrf'];
}

function csrfFeld(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrfToken()) . '">';
}

function csrfOk(): bool
{
    $t = (string)($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? ''));
    return $t !== '' && hash_equals(csrfToken(), $t);
}

function meldung(string $text, string $art = 'ok'): void
{
    $_SESSION['meldungen'][] = [$art, $text];
}

function meldungenHolen(): array
{
    $m = $_SESSION['meldungen'] ?? [];
    unset($_SESSION['meldungen']);
    return is_array($m) ? $m : [];
}

/** Vorherige Seite – aber nur, wenn sie zur Finanzzentrale gehört. */
function vorherigeSeite(): string
{
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $teile = parse_url($ref);
    if (!is_array($teile) || ($teile['host'] ?? '') !== (string)($_SERVER['HTTP_HOST'] ?? '') || !str_starts_with((string)($teile['path'] ?? ''), fzBasisPfad())) {
        return './';
    }
    return (string)$teile['path'] . (isset($teile['query']) ? '?' . $teile['query'] : '');
}

function weiter(string $ziel): void
{
    header('Location: ' . ($ziel === '' ? './' : $ziel), true, 303);
    exit;
}

// ---------------------------------------------------------------------------
// Formatierung
// ---------------------------------------------------------------------------

function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function zahl(?float $z, int $nk = 2): string
{
    if ($z === null) {
        return '–';
    }
    $s = number_format(abs($z), $nk, ',', '.');
    return ($z < 0 && (float)str_replace(['.', ','], ['', '.'], $s) != 0.0 ? '−' : '') . $s;
}

function waehrungsZeichen(string $w): string
{
    return ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'GBp' => 'p', 'JPY' => '¥', 'CNY' => '¥'][$w] ?? $w;
}

function geld(?float $z, string $w = 'EUR', int $nk = 2): string
{
    if ($z === null) {
        return '–';
    }
    $zeichen = waehrungsZeichen($w);
    return zahl($z, $nk) . ($zeichen !== '' ? "\u{00A0}" . $zeichen : '');
}

/** Anteil (0,0123) als „1,2 %“, wahlweise mit Vorzeichen. */
function proz(?float $anteil, int $nk = 1, bool $vorzeichen = false): string
{
    if ($anteil === null) {
        return '–';
    }
    $p = round($anteil * 100, $nk);
    $s = number_format(abs($p), $nk, ',', '.');
    $vor = $p < 0 ? '−' : ($vorzeichen && $p > 0 ? '+' : '');
    return $vor . $s . "\u{00A0}%";
}

/** Große Zahlen kompakt: „230,4 Mrd.“ */
function gross(?float $z, string $w = ''): string
{
    if ($z === null) {
        return '–';
    }
    $a = abs($z);
    [$teiler, $einheit] = $a >= 1e12 ? [1e12, 'Bio.'] : ($a >= 1e9 ? [1e9, 'Mrd.'] : ($a >= 1e6 ? [1e6, 'Mio.'] : [1.0, '']));
    $wert = $a / $teiler;
    $nk = $teiler === 1.0 ? 0 : ($wert >= 100 ? 0 : 1);
    $s = ($z < 0 ? '−' : '') . number_format($wert, $nk, ',', '.');
    $zeichen = $w !== '' ? waehrungsZeichen($w) : '';
    return $s . ($einheit !== '' ? "\u{00A0}" . $einheit : '') . ($zeichen !== '' ? "\u{00A0}" . $zeichen : '');
}

function zeitstempel($wert): int
{
    if (is_int($wert)) {
        return $wert;
    }
    if (is_numeric($wert)) {
        return (int)$wert;
    }
    $t = strtotime((string)$wert);
    return $t === false ? 0 : $t;
}

function datum($wert): string
{
    $t = zeitstempel($wert);
    return $t > 0 ? date('d.m.Y', $t) : '–';
}

function datumZeit($wert): string
{
    $t = zeitstempel($wert);
    return $t > 0 ? date('d.m.Y, H:i', $t) . ' Uhr' : '–';
}

function vorZeit(int $ts): string
{
    $d = time() - $ts;
    if ($d < 90) {
        return 'gerade eben';
    }
    if ($d < 3600) {
        return 'vor ' . (int)round($d / 60) . ' Min.';
    }
    if ($d < 86400) {
        return 'vor ' . (int)round($d / 3600) . ' Std.';
    }
    $tage = (int)floor($d / 86400);
    return $tage === 1 ? 'gestern' : ($tage < 31 ? "vor $tage Tagen" : 'am ' . datum($ts));
}

/** Tage bis zu einem Datum (negativ = vorbei). */
function tageBis(string $tag): int
{
    $ziel = strtotime($tag . ' 00:00:00');
    $heute = strtotime(date('Y-m-d') . ' 00:00:00');
    return $ziel === false ? 0 : (int)round(($ziel - $heute) / 86400);
}

function klasse(?float $v): string
{
    return $v === null ? '' : ($v > 0 ? 'plus' : ($v < 0 ? 'minus' : ''));
}

/** Firmenname ohne Rechtsform – für News-Suche und Namensvergleiche. */
function nameKurz(string $name): string
{
    $n = trim((string)preg_replace('/\s*\([^)]*\)\s*/', ' ', $name));
    $weg = ['aktiengesellschaft', 'kgaa', 'gmbh', 'se', 'ag', 'inc', 'incorporated', 'corp', 'corporation', 'co', 'ltd',
        'limited', 'plc', 'n.v', 'nv', 's.a', 'sa', 's.p.a', 'spa', 'asa', 'ab', 'a/s', 'oyj', '&', 'vz', 'st', 'adr', 'ord', 'shs', 'the'];
    $teile = preg_split('/\s+/', $n) ?: [];
    while (count($teile) > 1) {
        $letztes = rtrim(mb_strtolower((string)end($teile)), '.,');
        if (!in_array($letztes, $weg, true)) {
            break;
        }
        array_pop($teile);
    }
    return trim(implode(' ', $teile), " ,.");
}

function nameNormal(string $name): string
{
    return (string)preg_replace('/[^a-z0-9]/', '', mb_strtolower(nameKurz($name)));
}

function namenAehnlich(string $a, string $b): bool
{
    $a = nameNormal($a);
    $b = nameNormal($b);
    if ($a === '' || $b === '') {
        return false;
    }
    if (str_contains($a, $b) || str_contains($b, $a)) {
        return true;
    }
    similar_text($a, $b, $prozent);
    return $prozent >= 70;
}
