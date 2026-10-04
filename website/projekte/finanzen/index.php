<?php
declare(strict_types=1);

/*
 * Finanzzentrale auf fruthzeug.de – Firmen prüfen, Depot, Alarme.
 * Einstieg und Steuerung: Anmeldung, Aktionen (POST), Seiten (GET).
 */

define('FZ_APP', __DIR__);
date_default_timezone_set('Europe/Berlin');
mb_internal_encoding('UTF-8');

require FZ_APP . '/inc/kern.php';
require FZ_APP . '/inc/http.php';
require FZ_APP . '/inc/yahoo.php';
require FZ_APP . '/inc/quellen.php';
require FZ_APP . '/inc/ki.php';
require FZ_APP . '/inc/alarme.php';
require FZ_APP . '/inc/xlsx.php';
require FZ_APP . '/inc/depot.php';
require FZ_APP . '/inc/ansichten.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https://commons.wikimedia.org https://upload.wikimedia.org; "
    . "style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

// ---------------------------------------------------------------------------
// Automatische Prüfung (GitHub Actions) und Selbsttest nach dem Deployment
// ---------------------------------------------------------------------------
if (isset($_GET['cron'])) {
    header('Content-Type: application/json; charset=utf-8');
    $token = geheimLesen('crontoken');
    if ($token === '' || !hash_equals($token, (string)$_GET['cron'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'fehler' => 'Zugriff verweigert']);
        exit;
    }
    if (function_exists('set_time_limit')) { @set_time_limit(170); }
    if (isset($_GET['diagnose'])) {
        echo json_encode(diagnose(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    $erg = alarmeAusfuehren('cron');
    $z = brokerZugang();
    $d = datenLaden();
    if ($z['etoro_api'] !== '' && time() - (int)($d['broker']['etoro']['zeit'] ?? 0) > 6 * 3600) {
        $erg['etoro'] = etoroAktualisieren() === '' ? 'ok' : 'fehler';
    }
    if (zuordnungOffen($d) > 0) {
        // Falls die Depotseite während der Zuordnung geschlossen wurde
        $erg['zuordnung_offen'] = depotZuordnen(60.0);
    }
    cacheAufraeumen();
    echo json_encode(['ok' => true] + $erg, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Erreichbarkeit aller Quellen vom Server aus – ohne persönliche Daten. */
function diagnose(): array
{
    $crumb = yahooCrumb(true);
    $weg = (string)(jsonLesen(fzPfad('yahoo-crumb.json'))['weg'] ?? '');
    $suche = yahooSuche('SAP');
    $kurse = yahooKurse(['SAP.DE']);
    $qs = yahooKennzahlenRoh('SAP.DE', 0);
    return [
        'php' => PHP_VERSION,
        'speicherort' => ['extern' => 'außerhalb des Web-Verzeichnisses', 'eigen' => 'eigener gesperrter Ordner (vom Deployment unberührt)', 'app' => 'App-Ordner daten/'][fzDatenOrt()],
        'speicher_beschreibbar' => @is_writable(fzDatenDir()),
        'yahoo_crumb' => $crumb !== '' ? 'ok (' . $weg . ')' : 'FEHLT',
        'yahoo_suche' => $suche !== [] ? 'ok' : 'FEHLER',
        'yahoo_kurse' => isset($kurse['SAP.DE']['kurs']) ? 'ok' . (!empty($kurse['SAP.DE']['veraltet']) ? ' (alter Stand)' : '') : 'FEHLER',
        'yahoo_kennzahlen' => $qs !== null && isset($qs['financialData']) ? 'ok' : 'FEHLER',
        'gleif' => leiZuIsin('DE0007164600') !== '' ? 'ok' : 'FEHLER',
        'wikidata' => wikidataZuIsin('DE0007164600') !== '' ? 'ok' : 'FEHLER',
        'news' => googleNews('SAP', 'de') !== [] ? 'ok' : 'FEHLER',
        'ki_schluessel' => kiSchluessel() !== '' ? 'vorhanden' : 'fehlt',
        'mail' => function_exists('mail') ? 'verfügbar' : 'fehlt',
        'eingerichtet' => passwortGesetzt(),
        'php_grenzen' => [
            'max_execution_time' => (string)ini_get('max_execution_time'),
            'set_time_limit' => function_exists('set_time_limit') && !in_array('set_time_limit', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true),
            'upload_max_filesize' => (string)ini_get('upload_max_filesize'),
            'post_max_size' => (string)ini_get('post_max_size'),
            'memory_limit' => (string)ini_get('memory_limit'),
            'zip' => class_exists('ZipArchive'),
            'simplexml' => function_exists('simplexml_load_string'),
        ],
    ];
}

sitzungStarten();

// ---------------------------------------------------------------------------
// Anmeldung, Einrichtung, Zurücksetzen
// ---------------------------------------------------------------------------
$aktion = (string)($_POST['aktion'] ?? '');
$seite = (string)($_GET['seite'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aktion !== '' && !csrfOk()) {
    meldung('Die Sitzung war abgelaufen – bitte noch einmal versuchen.', 'fehler');
    weiter(vorherigeSeite());
}

if (!passwortGesetzt() || $seite === 'zuruecksetzen') {
    $zuruecksetzen = passwortGesetzt();
    $erlaubt = tastelogAdmin();
    if ($aktion === 'einrichten' && $erlaubt) {
        $pw = (string)($_POST['passwort'] ?? '');
        if (mb_strlen($pw) < 10) {
            meldung('Das Passwort muss mindestens 10 Zeichen lang sein.', 'fehler');
        } elseif (!hash_equals($pw, (string)($_POST['passwort2'] ?? ''))) {
            meldung('Die beiden Passwörter stimmen nicht überein.', 'fehler');
        } else {
            passwortSetzen($pw);
            $email = trim((string)($_POST['email'] ?? ''));
            datenAendern(function (array &$d) use ($email): void {
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $d['einstellungen']['email'] = $email;
                }
                if (einstellung($d, 'ntfy') === '') {
                    $d['einstellungen']['ntfy'] = 'fz-' . bin2hex(random_bytes(9));
                }
            });
            sitzungAnmelden(true);
            meldung($zuruecksetzen ? 'Neues Passwort gespeichert.' : 'Eingerichtet! Willkommen in deiner Finanzzentrale.');
            weiter('./');
        }
        weiter($zuruecksetzen ? url(['seite' => 'zuruecksetzen']) : './');
    }
    seite($zuruecksetzen ? 'Passwort zurücksetzen' : 'Einrichten', seiteEinrichten($erlaubt, $zuruecksetzen));
    exit;
}

if ($aktion === 'anmelden') {
    $fehler = anmeldenVersuch((string)($_POST['passwort'] ?? ''), !empty($_POST['merken']));
    if ($fehler === '') {
        weiter((string)($_SESSION['ziel'] ?? './'));
    }
    meldung($fehler, 'fehler');
    weiter('./');
}

if (!angemeldet()) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $seite !== '' && !isset($_GET['teil']) && !isset($_GET['api'])) {
        $_SESSION['ziel'] = (string)($_SERVER['REQUEST_URI'] ?? './');
    }
    if (isset($_GET['teil']) || isset($_GET['api'])) {
        http_response_code(401);
        echo 'Bitte neu anmelden.';
        exit;
    }
    seite('Anmelden', seiteAnmelden());
    exit;
}
unset($_SESSION['ziel']);

// ---------------------------------------------------------------------------
// Hilfen für Aktionen
// ---------------------------------------------------------------------------

function symbolAusAnfrage(): string
{
    $s = strtoupper(trim((string)($_POST['s'] ?? $_GET['s'] ?? '')));
    return preg_match('/^[A-Z0-9.\-^=]{1,24}$/', $s) ? $s : '';
}

/** Ändert eine gespeicherte Firma; legt sie bei Bedarf an. */
function firmaAendern(string $s, callable $fn): void
{
    datenAendern(function (array &$d) use ($s, $fn): void {
        if (!isset($d['firmen'][$s])) {
            $d['firmen'][$s] = firmaNormal([], $s);
        }
        $fn($d['firmen'][$s], $d);
        $d['firmen'][$s]['geaendert'] = time();
    });
}

function zurueckZurFirma(string $s, string $anker = ''): void
{
    weiter(firmaUrl($s) . ($anker !== '' ? '#' . $anker : ''));
}

function pruefSchnappschuss(array $p, string $status, string $fazit = ''): array
{
    return [
        'zeit' => time(), 'kurs' => $p['kurs'], 'waehrung' => $p['waehrung'], 'kgv' => $p['kgv'], 'kgv_erw' => $p['kgv_erw'],
        'kbv' => $p['kbv'], 'div_rendite' => $p['div_rendite'], 'nettomarge' => $p['nettomarge'], 'boersenwert' => $p['boersenwert'],
        'kursziel' => $p['kursziel'], 'status' => $status, 'fazit' => mb_substr($fazit, 0, 300),
    ];
}

function textFeld(string $name, int $max): string
{
    return mb_substr(trim(str_replace("\r\n", "\n", (string)($_POST[$name] ?? ''))), 0, $max);
}

// ---------------------------------------------------------------------------
// Aktionen (POST)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aktion !== '') {
    $s = symbolAusAnfrage();
    try {
        switch ($aktion) {
            case 'abmelden':
                abmelden();
                weiter('./');
                break;
            case 'ueberall_abmelden':
                abmelden(true);
                weiter('./');
                break;

            case 'status':
                if ($s === '') {
                    break;
                }
                firmaAendern($s, function (array &$f): void {
                    if (isset($_POST['bewertung'])) {
                        $f['bewertung'] = max(0, min(5, (int)$_POST['bewertung']));
                    }
                    $st = (string)($_POST['status'] ?? $f['status']);
                    if (isset(FZ_STATUS[$st])) {
                        $f['status'] = $st;
                    }
                });
                zurueckZurFirma($s);
                break;
            case 'these':
                firmaAendern($s, function (array &$f): void {
                    $f['these'] = textFeld('these', 3000);
                });
                meldung('These gespeichert.');
                zurueckZurFirma($s, 'f-notizen');
                break;
            case 'notiz_neu':
                $text = textFeld('text', 5000);
                if ($text !== '') {
                    firmaAendern($s, function (array &$f) use ($text): void {
                        $f['notizen'][] = ['id' => neueId(), 'zeit' => time(), 'text' => $text];
                    });
                    meldung('Notiz gespeichert.');
                }
                zurueckZurFirma($s, 'f-notizen');
                break;
            case 'notiz_aendern':
                $id = (string)($_POST['id'] ?? '');
                $text = textFeld('text', 5000);
                firmaAendern($s, function (array &$f) use ($id, $text): void {
                    foreach ($f['notizen'] as &$n) {
                        if ($n['id'] === $id && $text !== '') {
                            $n['text'] = $text;
                            $n['geaendert'] = time();
                        }
                    }
                    unset($n);
                });
                meldung('Notiz geändert.');
                zurueckZurFirma($s, 'f-notizen');
                break;
            case 'notiz_loeschen':
                $id = (string)($_POST['id'] ?? '');
                firmaAendern($s, function (array &$f) use ($id): void {
                    $f['notizen'] = array_values(array_filter($f['notizen'], static fn(array $n): bool => $n['id'] !== $id));
                });
                meldung('Notiz gelöscht.');
                zurueckZurFirma($s, 'f-notizen');
                break;

            case 'limit_neu':
                $wert = zahlLesen((string)($_POST['wert'] ?? ''));
                $typ = ($_POST['typ'] ?? '') === 'ueber' ? 'ueber' : 'unter';
                if ($wert === null || $wert <= 0) {
                    meldung('Bitte einen gültigen Kurs als Limit eintragen.', 'fehler');
                    zurueckZurFirma($s, 'f-limits');
                }
                $notiz = textFeld('notiz', 200);
                firmaAendern($s, function (array &$f) use ($wert, $typ, $notiz): void {
                    $f['limits'][] = ['id' => neueId(), 'typ' => $typ, 'wert' => (float)$wert, 'aktiv' => true, 'ausgeloest' => 0, 'notiz' => $notiz, 'zeit' => time()];
                });
                meldung('Limit angelegt – du wirst benachrichtigt, sobald der Kurs ' . ($typ === 'unter' ? 'darunter fällt.' : 'darüber steigt.'));
                zurueckZurFirma($s, 'f-limits');
                break;
            case 'limit_loeschen':
            case 'limit_scharf':
                $id = (string)($_POST['id'] ?? '');
                firmaAendern($s, function (array &$f) use ($id, $aktion): void {
                    if ($aktion === 'limit_loeschen') {
                        $f['limits'] = array_values(array_filter($f['limits'], static fn(array $l): bool => $l['id'] !== $id));
                        return;
                    }
                    foreach ($f['limits'] as &$l) {
                        if ($l['id'] === $id) {
                            $l['ausgeloest'] = 0;
                            $l['aktiv'] = true;
                        }
                    }
                    unset($l);
                });
                meldung($aktion === 'limit_loeschen' ? 'Limit gelöscht.' : 'Limit ist wieder aktiv.');
                if (($_POST['zurueck'] ?? '') === 'alarme') {
                    weiter(url(['seite' => 'alarme']));
                }
                zurueckZurFirma($s, 'f-limits');
                break;
            case 'wiedervorlage':
                $schnell = (string)($_POST['schnell'] ?? '');
                $tag = $schnell === 'weg' ? '' : ($schnell !== '' ? date('Y-m-d', (int)strtotime($schnell)) : (string)($_POST['datum'] ?? ''));
                if ($tag !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tag)) {
                    $tag = '';
                }
                firmaAendern($s, function (array &$f) use ($tag): void {
                    $f['wiedervorlage'] = $tag;
                    $f['wv_gemeldet'] = '';
                });
                meldung($tag === '' ? 'Wiedervorlage entfernt.' : 'Wiedervorlage am ' . datum($tag) . ' gespeichert.');
                zurueckZurFirma($s, 'f-limits');
                break;
            case 'termin_erinnerung':
                firmaAendern($s, function (array &$f): void {
                    $f['termin_erinnerung'] = !empty($_POST['an']);
                });
                meldung(!empty($_POST['an']) ? 'Du wirst vor Quartalszahlen und Dividenden-Stichtag erinnert.' : 'Termin-Erinnerung ausgeschaltet.');
                zurueckZurFirma($s, 'f-limits');
                break;

            case 'pruefung':
                $p = firmaProfil($s);
                if ($p === null) {
                    meldung('Gerade keine Kursdaten – bitte gleich noch einmal versuchen.', 'fehler');
                    zurueckZurFirma($s, 'f-verlauf');
                }
                $fazit = textFeld('fazit', 300);
                firmaAendern($s, function (array &$f) use ($p, $fazit): void {
                    $f['pruefungen'][] = pruefSchnappschuss($p, $f['status'], $fazit);
                });
                meldung('Prüfung vom ' . date('d.m.Y') . ' gespeichert.');
                zurueckZurFirma($s, 'f-verlauf');
                break;

            case 'depot_neu':
                $typ = in_array($_POST['typ'] ?? '', ['kauf', 'verkauf', 'dividende'], true) ? (string)$_POST['typ'] : 'kauf';
                $stueck = zahlLesen((string)($_POST['stueck'] ?? ''));
                $kurs = zahlLesen((string)($_POST['kurs'] ?? ''));
                $betrag = zahlLesen((string)($_POST['betrag'] ?? ''));
                $tag = (string)($_POST['datum'] ?? date('Y-m-d'));
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tag)
                    || ($typ !== 'dividende' && (!$stueck || $stueck <= 0 || $kurs === null || $kurs < 0))
                    || ($typ === 'dividende' && (!$betrag || $betrag <= 0))) {
                    meldung($typ === 'dividende' ? 'Bitte Datum und Betrag der Dividende eintragen.' : 'Bitte Datum, Stückzahl und Kurs eintragen.', 'fehler');
                    zurueckZurFirma($s, 'f-depot');
                }
                firmaAendern($s, function (array &$f) use ($typ, $stueck, $kurs, $betrag, $tag): void {
                    $f['depot'][] = ['id' => neueId(), 'typ' => $typ, 'datum' => $tag, 'stueck' => (float)$stueck, 'kurs' => (float)$kurs,
                        'betrag' => $betrag !== null ? abs($betrag) : null, 'gebuehr' => abs((float)zahlLesen((string)($_POST['gebuehr'] ?? '')))];
                    if ($typ === 'kauf') {
                        $f['status'] = 'depot';
                    }
                });
                meldung('Buchung gespeichert.');
                zurueckZurFirma($s, 'f-depot');
                break;
            case 'depot_loeschen':
                $id = (string)($_POST['id'] ?? '');
                firmaAendern($s, function (array &$f) use ($id): void {
                    $f['depot'] = array_values(array_filter($f['depot'], static fn(array $t): bool => $t['id'] !== $id));
                });
                meldung('Buchung gelöscht.');
                zurueckZurFirma($s, 'f-depot');
                break;

            case 'stammdaten':
                $isin = strtoupper(trim((string)($_POST['isin'] ?? '')));
                $lei = strtoupper(trim((string)($_POST['lei'] ?? '')));
                $qid = strtoupper(trim((string)($_POST['wikidata'] ?? '')));
                firmaAendern($s, function (array &$f) use ($isin, $lei, $qid): void {
                    $f['isin'] = istIsin($isin) ? $isin : '';
                    $f['lei'] = preg_match('/^[A-Z0-9]{20}$/', $lei) ? $lei : '';
                    $f['wikidata'] = preg_match('/^Q\d+$/', $qid) ? $qid : '';
                    $f['identitaet'] = time();
                    $f['suchbegriff'] = textFeld('suchbegriff', 60);
                });
                meldung('Stammdaten gespeichert.');
                zurueckZurFirma($s, 'f-profil');
                break;
            case 'firma_entfernen':
                datenAendern(function (array &$d) use ($s): void {
                    unset($d['firmen'][$s]);
                });
                meldung('Firma aus deiner Liste entfernt.');
                weiter(url(['seite' => 'firmen']));
                break;

            case 'ereignisse_gelesen':
                datenAendern(function (array &$d): void {
                    foreach ($d['ereignisse'] as &$ev) {
                        $ev['gelesen'] = true;
                    }
                    unset($ev);
                });
                weiter(vorherigeSeite());
                break;

            case 'tr_vorschau':
                $datei = $_FILES['datei'] ?? null;
                if (!is_array($datei) || ($datei['error'] ?? 1) !== UPLOAD_ERR_OK || (int)$datei['size'] > 20 * 1024 * 1024) {
                    meldung('Die Datei kam nicht an (höchstens 20 MB).', 'fehler');
                    weiter(url(['seite' => 'depot']));
                }
                $ergebnis = trCsvLesen((string)file_get_contents((string)$datei['tmp_name']));
                if (!$ergebnis['ok']) {
                    meldung($ergebnis['fehler'], 'fehler');
                    weiter(url(['seite' => 'depot']));
                }
                $ergebnis['datei'] = (string)$datei['name'];
                $ergebnis['positionen'] = array_values(array_filter(trPositionenAus($ergebnis), static fn(array $p): bool => $p['stueck'] > 1e-9));
                jsonSchreiben(fzPfad('tr-vorschau.json'), $ergebnis);
                weiter(url(['seite' => 'depot', 'vorschau' => '1']));
                break;
            case 'tr_uebernehmen':
                $ergebnis = jsonLesen(fzPfad('tr-vorschau.json'));
                if (empty($ergebnis['ok'])) {
                    meldung('Keine Vorschau vorhanden – bitte die Datei erneut hochladen.', 'fehler');
                    weiter(url(['seite' => 'depot']));
                }
                $tr = trImportSpeichern($ergebnis, (string)($ergebnis['datei'] ?? ''));
                @unlink(fzPfad('tr-vorschau.json'));
                $offen = count(array_filter($tr['positionen'], static fn(array $p): bool => $p['stueck'] > 1e-9));
                meldung('Trade Republic importiert: ' . $offen . ' aktuelle Positionen. Die Kurse werden jetzt zugeordnet.');
                weiter(url(['seite' => 'depot']));
                break;
            case 'tr_verwerfen':
                @unlink(fzPfad('tr-vorschau.json'));
                weiter(url(['seite' => 'depot']));
                break;
            case 'tr_loeschen':
                datenAendern(function (array &$d): void {
                    unset($d['broker']['traderepublic']);
                    depotFirmenAbgleichen($d);
                });
                meldung('Trade-Republic-Daten gelöscht.');
                weiter(url(['seite' => 'depot']));
                break;

            case 'etoro_vorschau':
                $datei = $_FILES['datei'] ?? null;
                if (!is_array($datei) || ($datei['error'] ?? 1) !== UPLOAD_ERR_OK || (int)$datei['size'] > 30 * 1024 * 1024) {
                    meldung('Die Datei kam nicht an (höchstens 30 MB).', 'fehler');
                    weiter(url(['seite' => 'depot']) . '#d-etoro-import');
                }
                if (function_exists('set_time_limit')) { @set_time_limit(120); }
                $blaetter = xlsxLesen((string)$datei['tmp_name']);
                if ($blaetter === []) {
                    meldung('Das ist keine lesbare Excel-Datei (.xlsx). Bitte den eToro-Kontoauszug im Format Excel herunterladen.', 'fehler');
                    weiter(url(['seite' => 'depot']) . '#d-etoro-import');
                }
                $ergebnis = etoroAuszugLesen($blaetter);
                if (!$ergebnis['ok']) {
                    meldung($ergebnis['fehler'], 'fehler');
                    weiter(url(['seite' => 'depot']) . '#d-etoro-import');
                }
                $ergebnis['datei'] = (string)$datei['name'];
                jsonSchreiben(fzPfad('etoro-vorschau.json'), $ergebnis);
                weiter(url(['seite' => 'depot', 'vorschau' => 'etoro']));
                break;
            case 'etoro_uebernehmen':
                $ergebnis = jsonLesen(fzPfad('etoro-vorschau.json'));
                if (empty($ergebnis['ok'])) {
                    meldung('Keine Vorschau vorhanden – bitte die Datei erneut hochladen.', 'fehler');
                    weiter(url(['seite' => 'depot']));
                }
                $auszug = etoroAuszugSpeichern($ergebnis, (string)($ergebnis['datei'] ?? ''));
                @unlink(fzPfad('etoro-vorschau.json'));
                meldung('eToro importiert: ' . $auszug['anzahl'] . ' offene Positionen in ' . count($auszug['offen']) . ' Titeln. Die Kurse werden jetzt zugeordnet.');
                weiter(url(['seite' => 'depot']));
                break;
            case 'etoro_verwerfen':
                @unlink(fzPfad('etoro-vorschau.json'));
                weiter(url(['seite' => 'depot']));
                break;
            case 'etoro_auszug_loeschen':
                datenAendern(function (array &$d): void {
                    unset($d['broker']['etoro_auszug']);
                    depotFirmenAbgleichen($d);
                });
                meldung('Importierte eToro-Daten gelöscht.');
                weiter(url(['seite' => 'depot']));
                break;

            case 'etoro_schluessel':
                $api = trim((string)($_POST['api'] ?? ''));
                $user = trim((string)($_POST['user'] ?? ''));
                if ($api === '' || $user === '') {
                    meldung('Bitte beide Schlüssel eintragen.', 'fehler');
                    weiter(url(['seite' => 'depot']) . '#d-etoro-import');
                }
                brokerZugangSpeichern(['etoro_api' => $api, 'etoro_user' => $user] + brokerZugang());
                $fehler = etoroAktualisieren();
                meldung($fehler === '' ? 'eToro ist verbunden – dein Depot wurde abgerufen.' : $fehler, $fehler === '' ? 'ok' : 'fehler');
                weiter(url(['seite' => 'depot']));
                break;
            case 'etoro_abrufen':
                $fehler = etoroAktualisieren();
                meldung($fehler === '' ? 'eToro-Depot aktualisiert.' : $fehler, $fehler === '' ? 'ok' : 'fehler');
                weiter(url(['seite' => 'depot']));
                break;
            case 'etoro_trennen':
                brokerZugangSpeichern(['etoro_api' => '', 'etoro_user' => ''] + brokerZugang());
                datenAendern(function (array &$d): void {
                    unset($d['broker']['etoro']);
                    depotFirmenAbgleichen($d);
                });
                meldung('eToro-Verbindung getrennt und Schlüssel gelöscht.');
                weiter(url(['seite' => 'depot']));
                break;

            case 'benachrichtigung':
                $email = trim((string)($_POST['email'] ?? ''));
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    meldung('Die E-Mail-Adresse sieht nicht gültig aus.', 'fehler');
                    weiter(url(['seite' => 'einstellungen']));
                }
                datenAendern(function (array &$d) use ($email): void {
                    $d['einstellungen']['email'] = $email;
                    $d['einstellungen']['ntfy_aus'] = empty($_POST['push']) ? '1' : '';
                    if (einstellung($d, 'ntfy') === '') {
                        $d['einstellungen']['ntfy'] = 'fz-' . bin2hex(random_bytes(9));
                    }
                });
                if (!empty($_POST['test'])) {
                    $ok = benachrichtigen(datenLaden(), '✅ Test aus deiner Finanzzentrale', 'Wenn du das liest, kommen deine Alarme an. (Falls die E-Mail fehlt: bitte auch im Spam-Ordner nachsehen.)', fzBasisUrl());
                    meldung($ok ? 'Gespeichert und Test verschickt.' : 'Gespeichert – der Test konnte aber nicht verschickt werden.', $ok ? 'ok' : 'fehler');
                } else {
                    meldung('Gespeichert.');
                }
                weiter(url(['seite' => 'einstellungen']));
                break;
            case 'ki_einstellung':
                $modell = (string)($_POST['modell'] ?? FZ_STANDARD_MODELL);
                datenAendern(function (array &$d) use ($modell): void {
                    $d['einstellungen']['modell'] = isset(FZ_MODELLE[$modell]) ? $modell : FZ_STANDARD_MODELL;
                });
                if (!empty($_POST['test'])) {
                    $fehler = kiTest(isset(FZ_MODELLE[$modell]) ? $modell : FZ_STANDARD_MODELL);
                    meldung($fehler === '' ? 'Gespeichert – die Verbindung zur KI funktioniert.' : $fehler, $fehler === '' ? 'ok' : 'fehler');
                } else {
                    meldung('Gespeichert.');
                }
                weiter(url(['seite' => 'einstellungen']) . '#e-ki');
                break;
            case 'passwort_aendern':
                $alt = (string)($_POST['alt'] ?? '');
                $neu = (string)($_POST['neu'] ?? '');
                if (!password_verify($alt, (string)zugangLaden()['passwort'])) {
                    meldung('Das aktuelle Passwort stimmt nicht.', 'fehler');
                } elseif (mb_strlen($neu) < 10 || !hash_equals($neu, (string)($_POST['neu2'] ?? ''))) {
                    meldung('Das neue Passwort muss mindestens 10 Zeichen haben und zweimal gleich eingegeben werden.', 'fehler');
                } else {
                    passwortSetzen($neu);
                    sitzungAnmelden(true);
                    meldung('Passwort geändert. Andere Geräte wurden abgemeldet.');
                }
                weiter(url(['seite' => 'einstellungen']) . '#e-sicherheit');
                break;
            case 'import':
                $datei = $_FILES['datei'] ?? null;
                $roh = is_array($datei) && ($datei['error'] ?? 1) === UPLOAD_ERR_OK ? (string)file_get_contents((string)$datei['tmp_name']) : '';
                $neu = json_decode($roh, true);
                if (!is_array($neu) || !isset($neu['firmen']) || !is_array($neu['firmen'])) {
                    meldung('Das ist keine gültige Export-Datei der Finanzzentrale.', 'fehler');
                    weiter(url(['seite' => 'einstellungen']) . '#e-daten');
                }
                datenAendern(function (array &$d) use ($neu): void {
                    $d = datenNormal($neu);
                });
                meldung('Daten wiederhergestellt (' . count($neu['firmen']) . ' Firmen).');
                weiter('./');
                break;
        }
    } catch (Throwable $t) {
        meldung('Das hat nicht geklappt: ' . $t->getMessage(), 'fehler');
        weiter(vorherigeSeite());
    }
    weiter('./');
}

// ---------------------------------------------------------------------------
// Datenabrufe für die Oberfläche (JSON bzw. HTML-Teile)
// ---------------------------------------------------------------------------
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    if ($_GET['api'] === 'suche') {
        $treffer = array_slice(firmenSuchen((string)($_GET['q'] ?? '')), 0, 8);
        echo json_encode(array_map(static fn(array $t): array => ['name' => $t['name'], 'symbol' => $t['symbol'], 'boerse' => $t['boerse'],
            'url' => firmaUrl($t['symbol'], $t['isin'] ?? '')], $treffer), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_GET['api'] === 'zuordnen' && $_SERVER['REQUEST_METHOD'] === 'POST' && csrfOk()) {
        session_write_close();
        echo json_encode(['ok' => true, 'offen' => depotZuordnen(8.0)]);
        exit;
    }
    if ($_GET['api'] === 'ki' && $_SERVER['REQUEST_METHOD'] === 'POST' && csrfOk()) {
        $s = symbolAusAnfrage();
        $p = $s !== '' ? firmaProfil($s) : null;
        if ($p === null) {
            echo json_encode(['ok' => false, 'fehler' => 'Für diese Firma sind gerade keine Daten abrufbar.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        session_write_close(); // die Analyse dauert – andere Seiten sollen derweil nicht blockieren
        $d = datenLaden();
        $f = $d['firmen'][$s] ?? firmaNormal(['name' => $p['name']], $s);
        $erg = kiAnalyse($f, $p, kiModell($d));
        if ($erg['ok']) {
            firmaAendern($s, function (array &$f) use ($erg): void {
                $f['ki'] = ['text' => $erg['text'], 'quellen' => $erg['quellen'], 'modell' => $erg['modell'], 'zeit' => $erg['zeit']];
            });
        }
        echo json_encode($erg['ok'] ? ['ok' => true] : ['ok' => false, 'fehler' => $erg['fehler']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}

if (isset($_GET['teil'])) {
    header('Content-Type: text/html; charset=utf-8');
    $s = symbolAusAnfrage();
    $d = datenLaden();
    $p = $s !== '' ? firmaProfil($s) : null;
    if ($p === null) {
        echo leer('Gerade nicht abrufbar – bitte später noch einmal öffnen.');
        exit;
    }
    session_write_close();
    $f = $d['firmen'][$s] ?? firmaNormal(['name' => $p['name']], $s);
    $teil = (string)$_GET['teil'];
    if (in_array($teil, ['beteiligungen', 'profil', 'aktionaere'], true)) {
        // ISIN, LEI und Wikidata einmalig ermitteln und an der Firma merken
        if ($f['identitaet'] === 0 && isset($d['firmen'][$s])) {
            $id = identitaetErmitteln($f, $p);
            firmaAendern($s, function (array &$f) use ($id): void {
                foreach ($id as $k => $v) {
                    if ($f[$k] === '') {
                        $f[$k] = $v;
                    }
                }
                $f['identitaet'] = time();
            });
            $f = datenLaden()['firmen'][$s];
        } elseif ($f['identitaet'] === 0) {
            $f = array_merge($f, identitaetErmitteln($f, $p));
        }
    }
    $id = ['isin' => $f['isin'], 'lei' => $f['lei'], 'wikidata' => $f['wikidata']];
    echo match ($teil) {
        'news' => teilNews($f, $p, isset($_GET['frisch'])),
        'aktionaere' => teilAktionaere($f, $p, $id),
        'beteiligungen' => teilBeteiligungen($f, $id),
        'profil' => teilProfil($f, $p, $id),
        'sec' => teilSec($s),
        default => '',
    };
    exit;
}

if (isset($_GET['export'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="finanzzentrale-' . date('Y-m-d') . '.json"');
    echo json_encode(datenLaden(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

// ---------------------------------------------------------------------------
// Seiten
// ---------------------------------------------------------------------------
$d = datenLaden();

// Beim Öffnen der App fällige Alarme prüfen (höchstens alle 10 Minuten)
if (in_array($seite, ['', 'alarme', 'firmen'], true)
    && time() - max((int)($d['cron']['seite'] ?? 0), (int)($d['cron']['cron'] ?? 0)) > 600) {
    alarmeAusfuehren('seite');
    $d = datenLaden();
}

switch ($seite) {
    case 'firma':
        $s = symbolAusAnfrage();
        if ($s === '') {
            weiter(url(['seite' => 'suche']));
        }
        $p = firmaProfil($s);
        if ($p !== null && !isset($d['firmen'][$s])) {
            // Jede geprüfte Firma automatisch merken – mit Kurs und Kennzahlen von heute
            $isin = strtoupper(trim((string)($_GET['isin'] ?? '')));
            datenAendern(function (array &$d) use ($s, $p, $isin): void {
                $f = firmaNormal(['name' => $p['name'], 'waehrung' => $p['waehrung'], 'boerse' => $p['boerse'], 'isin' => istIsin($isin) ? $isin : ''], $s);
                $f['pruefungen'][] = pruefSchnappschuss($p, 'geprueft');
                $f['termine'] = $p['termine'];
                $f['termine_zeit'] = time();
                $d['firmen'][$s] = $f;
            });
            meldung('In „Meine Firmen“ aufgenommen – du findest die Firma dort jederzeit wieder.');
            weiter(firmaUrl($s));
        }
        if ($p !== null && isset($d['firmen'][$s])) {
            $f = $d['firmen'][$s];
            $neu = ['name' => $p['name'], 'waehrung' => $p['waehrung'], 'boerse' => $p['boerse'], 'termine' => $p['termine']];
            if ($f['pruefungen'] === [] || $f['name'] !== $neu['name'] || $f['waehrung'] !== $neu['waehrung'] || $f['termine'] != $neu['termine']) {
                datenAendern(function (array &$d) use ($s, $neu, $p): void {
                    foreach ($neu as $k => $v) {
                        $d['firmen'][$s][$k] = $v;
                    }
                    $d['firmen'][$s]['termine_zeit'] = time();
                    if ($d['firmen'][$s]['pruefungen'] === []) {
                        $d['firmen'][$s]['pruefungen'][] = pruefSchnappschuss($p, $d['firmen'][$s]['status']);
                    }
                });
                $d = datenLaden();
            }
        }
        $depot = null;
        if ($p !== null && (($d['firmen'][$s]['depot'] ?? []) !== [] || !empty($d['broker']))) {
            $depot = depotUebersicht($d);
        }
        seite($p['name'] ?? $s, seiteFirma($d, $s, $p, $depot, kiSchluessel() !== ''), 'firma', $d);
        break;

    case 'firmen':
        $filter = (string)($_GET['status'] ?? '');
        $filter = isset(FZ_STATUS[$filter]) ? $filter : '';
        $sort = (string)($_GET['sort'] ?? 'name');
        seite('Meine Firmen', seiteFirmen($d, yahooKurse(array_keys($d['firmen'])), $filter, $sort), 'firmen', $d);
        break;

    case 'suche':
        $q = trim((string)($_GET['q'] ?? ''));
        $treffer = $q !== '' ? firmenSuchen($q) : [];
        if (count($treffer) === 1 && (istIsin($q) || istWkn($q))) {
            weiter(firmaUrl($treffer[0]['symbol'], $treffer[0]['isin'] ?? ''));
        }
        seite('Firma prüfen', seiteSuche($d, $q, $treffer), 'suche', $d);
        break;

    case 'depot':
        $zugang = brokerZugang();
        $etoroVerbunden = $zugang['etoro_api'] !== '' && $zugang['etoro_user'] !== '';
        if ($etoroVerbunden && time() - max((int)($d['broker']['etoro']['zeit'] ?? 0), (int)($d['broker']['etoro']['fehler_zeit'] ?? 0)) > 900) {
            etoroAktualisieren();
            $d = datenLaden();
        }
        $vorschau = null;
        $etVorschau = null;
        if (($_GET['vorschau'] ?? '') === 'etoro') {
            $v = jsonLesen(fzPfad('etoro-vorschau.json'));
            $etVorschau = !empty($v['ok']) ? $v : null;
        } elseif (isset($_GET['vorschau'])) {
            $v = jsonLesen(fzPfad('tr-vorschau.json'));
            $vorschau = !empty($v['ok']) ? $v : null;
        }
        seite('Depot', seiteDepot($d, depotUebersicht($d), $etoroVerbunden, $vorschau, $etVorschau), 'depot', $d);
        break;

    case 'alarme':
        $symbole = [];
        foreach ($d['firmen'] as $sym => $f) {
            if ($f['limits'] !== []) {
                $symbole[] = (string)$sym;
            }
        }
        seite('Alarme & Termine', seiteAlarme($d, yahooKurse($symbole)), 'alarme', $d);
        break;

    case 'einstellungen':
        if (einstellung($d, 'ntfy') === '') {
            datenAendern(function (array &$d): void {
                $d['einstellungen']['ntfy'] = 'fz-' . bin2hex(random_bytes(9));
            });
            $d = datenLaden();
        }
        seite('Einstellungen', seiteEinstellungen($d, kiSchluessel() !== ''), 'einstellungen', $d);
        break;

    default:
        $kurse = yahooKurse(array_keys($d['firmen']));
        $depot = !empty($d['broker']) || array_filter($d['firmen'], static fn(array $f): bool => $f['depot'] !== []) ? depotUebersicht($d) : null;
        seite('Übersicht', seiteUebersicht($d, $kurse, $depot), '', $d);
}
