<?php
declare(strict_types=1);

/*
 * Depot: manuell erfasste Käufe/Verkäufe, Trade-Republic-Import (offizieller
 * CSV-Transaktionsexport) und eToro (offizielle API, nur lesend).
 */

// ---------------------------------------------------------------------------
// Positionen nach der Durchschnittskosten-Methode
// ---------------------------------------------------------------------------

/**
 * Buchungen: [['datum', 'typ' (kauf|verkauf|dividende), 'stueck', 'kurs', 'betrag', 'gebuehr', 'steuer'], …]
 * Beträge in Euro. Ergebnis: Stück, Einstand der noch gehaltenen Stücke, realisierter Gewinn, Dividenden.
 */
function positionBerechnen(array $buchungen): array
{
    usort($buchungen, static fn(array $a, array $b): int => strcmp((string)$a['datum'], (string)$b['datum']));
    $menge = 0.0;
    $kosten = 0.0;
    $realisiert = 0.0;
    $dividenden = 0.0;
    $gebuehren = 0.0;
    foreach ($buchungen as $b) {
        $stueck = abs((float)($b['stueck'] ?? 0));
        $kurs = isset($b['kurs']) && $b['kurs'] !== null ? (float)$b['kurs'] : null;
        $betrag = isset($b['betrag']) && $b['betrag'] !== null ? abs((float)$b['betrag']) : null;
        $gebuehr = abs((float)($b['gebuehr'] ?? 0));
        $steuer = abs((float)($b['steuer'] ?? 0));
        $gebuehren += $gebuehr;
        if ($b['typ'] === 'kauf' && $stueck > 0) {
            $kosten += ($kurs !== null ? $stueck * $kurs : (float)$betrag) + $gebuehr;
            $menge += $stueck;
        } elseif ($b['typ'] === 'verkauf' && $stueck > 0 && $menge > 0) {
            $anteil = min($stueck, $menge);
            $schnitt = $kosten / $menge;
            $erloes = ($kurs !== null ? $anteil * $kurs : (float)$betrag * $anteil / $stueck) - $gebuehr - $steuer;
            $realisiert += $erloes - $schnitt * $anteil;
            $kosten -= $schnitt * $anteil;
            $menge -= $anteil;
            if ($menge < 1e-9) {
                $menge = 0.0;
                $kosten = 0.0;
            }
        } elseif ($b['typ'] === 'dividende') {
            $dividenden += (float)$betrag - $steuer;
        }
    }
    return ['stueck' => $menge, 'einstand' => $kosten, 'realisiert' => $realisiert, 'dividenden' => $dividenden, 'gebuehren' => $gebuehren];
}

/** Manuell erfasste Position einer Firma (Beträge in Euro). */
function manuellePosition(array $firma): array
{
    $buchungen = [];
    foreach ($firma['depot'] as $t) {
        $buchungen[] = [
            'datum' => (string)($t['datum'] ?? ''), 'typ' => (string)($t['typ'] ?? 'kauf'),
            'stueck' => (float)($t['stueck'] ?? 0), 'kurs' => isset($t['kurs']) ? (float)$t['kurs'] : null,
            'betrag' => isset($t['betrag']) ? (float)$t['betrag'] : null, 'gebuehr' => (float)($t['gebuehr'] ?? 0), 'steuer' => 0.0,
        ];
    }
    return positionBerechnen($buchungen);
}

// ---------------------------------------------------------------------------
// ISIN → Börsenkürzel (für Kurse und die Verknüpfung mit „Meine Firmen“)
// ---------------------------------------------------------------------------

/** ['symbol' => 'SAP.DE', 'art' => 'aktie'|'etf'|'fonds'|'krypto'|'', 'name' => …] */
function symbolZuIsin(string $isin, string $name = ''): array
{
    $isin = strtoupper(trim($isin));
    if (preg_match('/^XF000([A-Z]{2,6}?)\d{3,}$/', $isin, $m)) {
        return ['symbol' => $m[1] . '-EUR', 'art' => 'krypto', 'name' => $name !== '' ? $name : $m[1]];
    }
    if (!istIsin($isin)) {
        return ['symbol' => '', 'art' => '', 'name' => $name];
    }
    $wert = gecacht('isin-symbol:' . $isin, 30 * 86400, function () use ($isin): ?array {
        $treffer = yahooSuche($isin, ['EQUITY', 'ETF', 'MUTUALFUND']);
        if ($treffer === []) {
            return ['symbol' => '', 'art' => '', 'name' => ''];
        }
        // Bevorzugt Xetra, dann Frankfurt/Tradegate (Euro), dann die Heimatbörse
        $rang = static function (array $t): int {
            $s = $t['symbol'];
            return str_ends_with($s, '.DE') ? 0 : (str_ends_with($s, '.F') ? 2 : (!str_contains($s, '.') ? 1 : 3));
        };
        usort($treffer, static fn(array $a, array $b): int => $rang($a) <=> $rang($b));
        $t = $treffer[0];
        $art = ['EQUITY' => 'aktie', 'ETF' => 'etf', 'MUTUALFUND' => 'fonds'][$t['typ']] ?? '';
        $symbol = $t['symbol'];
        if ($art !== 'aktie' && !str_ends_with($symbol, '.DE')) {
            // Bei ETFs und Fonds kennt Yahoo oft nur die Heimatbörse – das Xetra-Kürzel (Euro)
            // liefert OpenFIGI. Aktien behalten ihre Heimatbörse: dort sind die Firmendaten am vollständigsten.
            $xetra = xetraKuerzel($isin);
            if ($xetra !== '') {
                $k = yahooKurse([$xetra . '.DE']);
                if (($k[$xetra . '.DE']['kurs'] ?? null) !== null && ($k[$xetra . '.DE']['waehrung'] ?? '') === 'EUR') {
                    $symbol = $xetra . '.DE';
                }
            }
        }
        return ['symbol' => $symbol, 'art' => $art, 'name' => $t['name']];
    });
    $wert = is_array($wert) ? $wert : ['symbol' => '', 'art' => '', 'name' => ''];
    if ($wert['name'] === '') {
        $wert['name'] = $name;
    }
    return $wert;
}

/** Deutsches Börsenkürzel zu einer ISIN über OpenFIGI, z. B. „EUNL“ (→ Yahoo „EUNL.DE“). */
function xetraKuerzel(string $isin): string
{
    $r = http('https://api.openfigi.com/v3/mapping', [
        'post' => (string)json_encode([['idType' => 'ID_ISIN', 'idValue' => $isin]]),
        'kopf' => ['Content-Type: application/json'], 'zeit' => 12,
    ]);
    $j = httpJson($r);
    // Bloomberg-Börsencodes: GY Xetra, GR deutscher Verbund, dann Regionalbörsen und Tradegate
    $reihenfolge = ['GY', 'GR', 'GT', 'GF', 'GS', 'GM', 'GD', 'GH', 'GI'];
    $best = '';
    $bestRang = PHP_INT_MAX;
    foreach ((array)($j[0]['data'] ?? []) as $x) {
        $rang = array_search((string)($x['exchCode'] ?? ''), $reihenfolge, true);
        $ticker = (string)($x['ticker'] ?? '');
        if ($rang !== false && $rang < $bestRang && preg_match('/^[A-Z0-9]{1,8}$/', $ticker)) {
            $best = $ticker;
            $bestRang = $rang;
        }
    }
    return $best;
}

// ---------------------------------------------------------------------------
// Trade Republic: offizieller CSV-Transaktionsexport
// (App: Profil → Kontoauszüge/Dokumente → Transaktionsexport)
// ---------------------------------------------------------------------------

const FZ_CSV_SPALTEN = [
    'datum'    => ['date', 'datetime', 'datum', 'buchungstag', 'buchungsdatum', 'valuta', 'zeitpunkt', 'time', 'timestamp', 'executiondate', 'ausfuehrungsdatum', 'tradedate'],
    'typ'      => ['type', 'typ', 'transactiontype', 'transaktionsart', 'transaktion', 'art', 'ordertype', 'side', 'action', 'vorgang', 'buchungsart'],
    'kategorie' => ['category', 'kategorie', 'subtype', 'assetclass', 'assettype'],
    'isin'     => ['isin', 'wkn/isin', 'isincode'],
    'name'     => ['name', 'title', 'wertpapier', 'instrument', 'instrumentname', 'bezeichnung', 'description', 'beschreibung', 'asset', 'security', 'securityname', 'assetname', 'titel'],
    'symbol'   => ['ticker', 'symbol', 'tickersymbol'],
    'stueck'   => ['shares', 'quantity', 'stueck', 'stück', 'anzahl', 'units', 'menge', 'qty', 'nominale', 'stueckzahl', 'stückzahl', 'anteile'],
    'kurs'     => ['price', 'kurs', 'preis', 'priceperunit', 'pricepershare', 'ausfuehrungskurs', 'ausführungskurs', 'unitprice', 'kursproanteil', 'preisproanteil', 'shareprice'],
    'betrag'   => ['amount', 'betrag', 'value', 'wert', 'total', 'gesamtbetrag', 'netamount', 'nettobetrag', 'amounteur', 'betrageur', 'summe', 'gesamt'],
    'waehrung' => ['currency', 'waehrung', 'währung', 'ccy'],
    'gebuehr'  => ['fee', 'fees', 'gebuehr', 'gebühr', 'gebuehren', 'gebühren', 'kosten', 'commission', 'fremdkosten', 'orderkosten'],
    'steuer'   => ['tax', 'taxes', 'steuer', 'steuern', 'withholdingtax', 'quellensteuer', 'kapitalertragsteuer'],
];

function spaltenName(string $s): string
{
    $s = mb_strtolower(trim($s, " \t\"'\u{FEFF}"));
    return (string)preg_replace('/[^a-z0-9äöüß\/]/u', '', $s);
}

/** Zahl aus „1.234,56“, „-1,234.56“, „12,5 €“ usw. */
function zahlLesen(string $s): ?float
{
    $s = trim(str_replace(["\u{00A0}", ' ', '€', '$', 'EUR', 'USD', '%', '+'], '', $s));
    if ($s === '' || $s === '-') {
        return null;
    }
    $minus = str_starts_with($s, '-') || str_starts_with($s, '−') || (str_starts_with($s, '(') && str_ends_with($s, ')'));
    $s = str_replace(['−', '(', ')', '-'], '', $s);
    $komma = strrpos($s, ',');
    $punkt = strrpos($s, '.');
    if ($komma !== false && $punkt !== false) {
        $s = $komma > $punkt ? str_replace(['.', ','], ['', '.'], $s) : str_replace(',', '', $s);
    } elseif ($komma !== false) {
        $s = str_replace(',', '.', $s);
    }
    if (!is_numeric($s)) {
        return null;
    }
    return $minus ? -(float)$s : (float)$s;
}

function datumLesen(string $s): string
{
    $s = trim($s);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    if (preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{2,4})/', $s, $m)) {
        $jahr = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3];
        return sprintf('%04d-%02d-%02d', (int)$jahr, (int)$m[2], (int)$m[1]);
    }
    $t = strtotime($s);
    return $t !== false ? date('Y-m-d', $t) : '';
}

/** Buchungsart aus Typ/Kategorie (deutsch oder englisch). */
function buchungsArt(string $typ, string $kategorie, ?float $stueck, ?float $betrag): string
{
    $t = mb_strtolower($typ . ' ' . $kategorie);
    // Reihenfolge zählt: „Verkauf“ enthält „kauf“, „Savings interest“ ist kein Kauf
    $regeln = [
        'verkauf'    => ['verkauf', 'sell', 'sale', 'sold'],
        'dividende'  => ['dividend', 'dividende', 'ausschüttung', 'ausschuettung', 'distribution', 'ertrag', 'coupon'],
        'zins'       => ['interest', 'zins'],
        'kauf'       => ['kauf', 'buy', 'purchase', 'sparplan', 'savings', 'saveback', 'round up', 'roundup', 'round-up', 'spare change'],
        'einzahlung' => ['deposit', 'einzahlung', 'top up', 'topup'],
        'auszahlung' => ['withdraw', 'auszahlung', 'payout'],
        'steuer'     => ['tax', 'steuer'],
        'gebuehr'    => ['fee', 'gebühr', 'gebuehr'],
    ];
    foreach ($regeln as $art => $woerter) {
        foreach ($woerter as $w) {
            if (str_contains($t, $w)) {
                return $art;
            }
        }
    }
    if ($stueck !== null && abs($stueck) > 0 && $betrag !== null) {
        return $betrag < 0 ? 'kauf' : 'verkauf';
    }
    return 'sonst';
}

/**
 * CSV lesen und verstehen. Ergebnis:
 * ['ok', 'fehler', 'spalten' => [feld => Spaltenname], 'buchungen' => [...], 'zeilen', 'zaehler' => [art => n]]
 */
function trCsvLesen(string $inhalt): array
{
    $inhalt = (string)preg_replace('/^\xEF\xBB\xBF/', '', $inhalt);
    if (!mb_check_encoding($inhalt, 'UTF-8')) {
        $inhalt = (string)mb_convert_encoding($inhalt, 'UTF-8', 'Windows-1252');
    }
    $ersteZeile = strtok($inhalt, "\n") ?: '';
    $trenner = ',';
    $anzahl = 0;
    foreach ([',', ';', "\t", '|'] as $t) {
        $n = substr_count($ersteZeile, $t);
        if ($n > $anzahl) {
            $anzahl = $n;
            $trenner = $t;
        }
    }
    $strom = fopen('php://temp', 'r+');
    fwrite($strom, $inhalt);
    rewind($strom);
    $zeilen = [];
    while (($z = fgetcsv($strom, 0, $trenner, '"', '')) !== false) {
        if ($z !== [null]) {
            $zeilen[] = $z;
        }
    }
    fclose($strom);
    if (count($zeilen) < 2) {
        return ['ok' => false, 'fehler' => 'Die Datei enthält keine lesbaren Zeilen.'];
    }
    // Kopfzeile suchen (manche Exporte haben Vorspann-Zeilen)
    $kopfIndex = -1;
    $spalten = [];
    foreach (array_slice($zeilen, 0, 10, true) as $i => $z) {
        $zuordnung = [];
        foreach ($z as $nr => $roh) {
            $n = spaltenName((string)$roh);
            foreach (FZ_CSV_SPALTEN as $feld => $namen) {
                if (!isset($zuordnung[$feld]) && in_array($n, array_map('spaltenName', $namen), true)) {
                    $zuordnung[$feld] = $nr;
                    break;
                }
            }
        }
        if (isset($zuordnung['datum']) && (isset($zuordnung['isin']) || isset($zuordnung['stueck']) || isset($zuordnung['betrag']))) {
            $kopfIndex = $i;
            $spalten = $zuordnung;
            break;
        }
    }
    if ($kopfIndex < 0) {
        return ['ok' => false, 'fehler' => 'Die Spalten wurden nicht erkannt. Erwartet werden u. a. Datum, Typ, ISIN, Stück/Anzahl, Kurs/Preis und Betrag. Erste Zeile der Datei: „' . mb_substr($ersteZeile, 0, 200) . '“'];
    }
    $kopf = $zeilen[$kopfIndex];
    $wert = static fn(array $z, string $feld): string => isset($spalten[$feld]) ? trim((string)($z[$spalten[$feld]] ?? '')) : '';
    $buchungen = [];
    $zaehler = [];
    foreach (array_slice($zeilen, $kopfIndex + 1) as $z) {
        $datum = datumLesen($wert($z, 'datum'));
        if ($datum === '') {
            continue;
        }
        $stueck = zahlLesen($wert($z, 'stueck'));
        $betrag = zahlLesen($wert($z, 'betrag'));
        $art = buchungsArt($wert($z, 'typ'), $wert($z, 'kategorie'), $stueck, $betrag);
        $zaehler[$art] = ($zaehler[$art] ?? 0) + 1;
        $kurs = zahlLesen($wert($z, 'kurs'));
        if ($kurs !== null) {
            $kurs = abs($kurs);
        }
        if ($kurs === null && $stueck && $betrag !== null && in_array($art, ['kauf', 'verkauf'], true)) {
            $kurs = abs($betrag) / abs($stueck);
        }
        $buchungen[] = [
            'datum' => $datum, 'typ' => $art, 'roh_typ' => mb_substr($wert($z, 'typ'), 0, 40),
            'isin' => strtoupper($wert($z, 'isin')), 'name' => mb_substr($wert($z, 'name'), 0, 80),
            'symbol' => strtoupper($wert($z, 'symbol')),
            'stueck' => $stueck !== null ? abs($stueck) : null, 'kurs' => $kurs, 'betrag' => $betrag,
            'waehrung' => strtoupper($wert($z, 'waehrung')) ?: 'EUR',
            'gebuehr' => abs((float)zahlLesen($wert($z, 'gebuehr'))), 'steuer' => abs((float)zahlLesen($wert($z, 'steuer'))),
        ];
    }
    if ($buchungen === []) {
        return ['ok' => false, 'fehler' => 'In der Datei wurden keine Buchungen mit gültigem Datum gefunden.'];
    }
    $erkannt = [];
    foreach ($spalten as $feld => $nr) {
        $erkannt[$feld] = (string)($kopf[$nr] ?? '');
    }
    return ['ok' => true, 'fehler' => '', 'spalten' => $erkannt, 'buchungen' => $buchungen, 'zeilen' => count($buchungen), 'zaehler' => $zaehler];
}

/** Aus den Buchungen die heutigen Bestände je Wertpapier berechnen. */
function trPositionen(array $buchungen): array
{
    $jeIsin = [];
    $namen = [];
    foreach ($buchungen as $b) {
        $schluessel = $b['isin'] !== '' ? $b['isin'] : ($b['symbol'] !== '' ? 'SYM:' . $b['symbol'] : '');
        if ($schluessel === '' || !in_array($b['typ'], ['kauf', 'verkauf', 'dividende'], true)) {
            continue;
        }
        $jeIsin[$schluessel][] = $b;
        if ($b['name'] !== '') {
            $namen[$schluessel] = $b['name'];
        }
    }
    $positionen = [];
    foreach ($jeIsin as $schluessel => $liste) {
        $p = positionBerechnen($liste);
        $positionen[$schluessel] = [
            'isin' => str_starts_with($schluessel, 'SYM:') ? '' : $schluessel,
            'ticker' => str_starts_with($schluessel, 'SYM:') ? substr($schluessel, 4) : '',
            'name' => $namen[$schluessel] ?? $schluessel,
            'stueck' => $p['stueck'], 'einstand' => $p['einstand'], 'realisiert' => $p['realisiert'],
            'dividenden' => $p['dividenden'], 'gebuehren' => $p['gebuehren'],
        ];
    }
    return $positionen;
}

/** Import übernehmen: Positionen mit Börsenkürzeln versehen und speichern. */
function trImportSpeichern(array $ergebnis, string $dateiname): array
{
    $positionen = trPositionen($ergebnis['buchungen']);
    if (function_exists('set_time_limit')) { @set_time_limit(180); }
    foreach ($positionen as $k => $p) {
        if ($p['stueck'] <= 1e-9) {
            $positionen[$k] += ['symbol' => '', 'art' => ''];
            continue; // komplett verkauft – kein Kurs nötig
        }
        $info = $p['isin'] !== '' ? symbolZuIsin($p['isin'], $p['name']) : ['symbol' => $p['ticker'], 'art' => '', 'name' => $p['name']];
        $positionen[$k]['symbol'] = $info['symbol'];
        $positionen[$k]['art'] = $info['art'];
        if ($p['name'] === $k && $info['name'] !== '') {
            $positionen[$k]['name'] = $info['name'];
        }
    }
    $daten = array_column($ergebnis['buchungen'], 'datum');
    sort($daten);
    $summe = static fn(string $art, string $feld): float => array_sum(array_map(
        static fn(array $b): float => $b['typ'] === $art ? abs((float)($b[$feld] ?? 0)) : 0.0, $ergebnis['buchungen']));
    $tr = [
        'zeit' => time(), 'datei' => mb_substr($dateiname, 0, 120), 'zeilen' => $ergebnis['zeilen'],
        'von' => $daten[0] ?? '', 'bis' => $daten[count($daten) - 1] ?? '',
        'zaehler' => $ergebnis['zaehler'], 'spalten' => $ergebnis['spalten'],
        'positionen' => $positionen,
        'summen' => [
            'dividenden' => $summe('dividende', 'betrag'), 'zinsen' => $summe('zins', 'betrag'),
            'einzahlungen' => $summe('einzahlung', 'betrag'), 'auszahlungen' => $summe('auszahlung', 'betrag'),
        ],
        'buchungen' => array_slice(array_reverse($ergebnis['buchungen']), 0, 400),
    ];
    datenAendern(function (array &$d) use ($tr): void {
        $d['broker']['traderepublic'] = $tr;
        depotFirmenAbgleichen($d);
    });
    return $tr;
}

// ---------------------------------------------------------------------------
// eToro: offizielle API (Schlüssel mit Leserecht, Umgebung „Real“)
// ---------------------------------------------------------------------------

const FZ_ETORO = 'https://public-api.etoro.com/api/v1/';

function brokerZugang(): array
{
    return jsonLesen(fzPfad('broker-zugang.json')) + ['etoro_api' => '', 'etoro_user' => ''];
}

function brokerZugangSpeichern(array $z): void
{
    jsonSchreiben(fzPfad('broker-zugang.json'), $z);
}

function uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function etoroAnfrage(string $pfad, array $zugang): array
{
    $r = http(FZ_ETORO . $pfad, ['zeit' => 25, 'kopf' => [
        'x-api-key: ' . $zugang['etoro_api'], 'x-user-key: ' . $zugang['etoro_user'],
        'x-request-id: ' . uuid4(), 'Accept: application/json',
    ]]);
    return $r + ['json' => json_decode($r['body'], true)];
}

function etoroFehlertext(array $r): string
{
    $meldung = '';
    if (is_array($r['json'])) {
        $meldung = (string)($r['json']['message'] ?? ($r['json']['error'] ?? ($r['json']['title'] ?? '')));
    }
    if ($r['code'] === 0) {
        return 'eToro war nicht erreichbar (' . ($r['fehler'] ?: 'Zeitüberschreitung') . ').';
    }
    if (in_array($r['code'], [401, 403], true)) {
        return 'eToro lehnt die Schlüssel ab (HTTP ' . $r['code'] . '). Bitte prüfen: Umgebung „Real“, Recht „Lesen“, beide Schlüssel vollständig kopiert.' . ($meldung !== '' ? ' Meldung: ' . $meldung : '');
    }
    return 'eToro meldet HTTP ' . $r['code'] . ($meldung !== '' ? ': ' . mb_substr($meldung, 0, 200) : '.');
}

/** Depot von eToro abrufen und speichern. Rückgabe: '' bei Erfolg, sonst Fehlertext. */
function etoroAktualisieren(): string
{
    $z = brokerZugang();
    if ($z['etoro_api'] === '' || $z['etoro_user'] === '') {
        return 'Für eToro sind noch keine Schlüssel hinterlegt.';
    }
    $r = etoroAnfrage('trading/info/aggregate-portfolio?pnlLevel=DailyPnl&dailyCutoffUtc=' . rawurlencode(gmdate('Y-m-d\T00:00:00\Z')), $z);
    if ($r['code'] === 400) {
        $r = etoroAnfrage('trading/info/aggregate-portfolio?pnlLevel=Pnl', $z);
    }
    if ($r['code'] !== 200 || !is_array($r['json'])) {
        $fehler = etoroFehlertext($r);
        datenAendern(function (array &$d) use ($fehler): void {
            $d['broker']['etoro']['fehler'] = $fehler;
            $d['broker']['etoro']['fehler_zeit'] = time();
        });
        return $fehler;
    }
    $j = $r['json'];
    $waehrung = (string)($j['accountCurrency'] ?? 'USD');
    $summe = static fn(array $a, string $k): float => (float)($a[$k] ?? 0);
    // Direkte Positionen und kopierte Trader (Copy-Trading) zusammenführen
    $roh = [];
    $quellen = [['', (array)($j['instrumentAggregates'] ?? [])]];
    foreach ((array)($j['mirrors'] ?? []) as $m) {
        $quellen[] = ['Copy ' . (int)($m['mirrorId'] ?? 0), (array)($m['instrumentAggregates'] ?? [])];
    }
    foreach ($quellen as [$herkunft, $liste]) {
        foreach ($liste as $a) {
            $id = (int)($a['instrumentId'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $einstand = isset($a['netInitialExposureAccountCurrency']) && (float)($a['avgLeverage'] ?? 1) <= 1
                ? $summe($a, 'netInitialExposureAccountCurrency') : $summe($a, 'totalMarginAccountCurrency');
            $gv = $summe($a, 'accountCurrencyReturn');
            $wert = isset($a['liquidationValueAccountCurrency']) ? $summe($a, 'liquidationValueAccountCurrency') : $einstand + $gv;
            $p = $roh[$id] ?? ['id' => $id, 'stueck' => 0.0, 'einstand' => 0.0, 'wert' => 0.0, 'gv' => 0.0, 'heute' => 0.0,
                'kurs_einstand' => null, 'waehrung' => (string)($a['assetCurrency'] ?? ''), 'hebel' => 1.0, 'herkunft' => []];
            $p['stueck'] += $summe($a, 'netUnits');
            $p['einstand'] += $einstand;
            $p['wert'] += $wert;
            $p['gv'] += $gv;
            $p['heute'] += $summe($a, 'dailyGainAccountCurrency');
            $p['kurs_einstand'] = isset($a['netAvgOpenRate']) ? (float)$a['netAvgOpenRate'] : (isset($a['avgOpenRate']) ? (float)$a['avgOpenRate'] : $p['kurs_einstand']);
            $p['hebel'] = max($p['hebel'], (float)($a['avgLeverage'] ?? 1));
            if ($herkunft !== '') {
                $p['herkunft'][] = $herkunft;
            }
            $roh[$id] = $p;
        }
    }
    // Namen und Kürzel nachschlagen
    $infos = [];
    foreach (array_chunk(array_keys($roh), 50) as $teil) {
        $ri = etoroAnfrage('market-data/instruments?instrumentIds=' . implode(',', $teil), $z);
        foreach ((array)($ri['json']['instrumentDisplayDatas'] ?? []) as $x) {
            $infos[(int)($x['instrumentID'] ?? 0)] = $x;
        }
    }
    $arten = [5 => 'aktie', 6 => 'etf', 10 => 'krypto', 1 => 'devisen', 2 => 'rohstoff', 4 => 'index'];
    $positionen = [];
    $kandidaten = [];
    foreach ($roh as $id => $p) {
        $info = $infos[$id] ?? [];
        $kuerzel = (string)($info['symbolFull'] ?? '');
        $p['name'] = (string)($info['instrumentDisplayName'] ?? ($kuerzel !== '' ? $kuerzel : 'Instrument ' . $id));
        $p['kuerzel'] = $kuerzel;
        $p['art'] = $arten[(int)($info['instrumentTypeID'] ?? 0)] ?? 'sonst';
        $p['symbol'] = $p['art'] === 'aktie' || $p['art'] === 'etf' ? etoroZuYahoo($kuerzel) : '';
        if ($p['symbol'] !== '') {
            $kandidaten[] = $p['symbol'];
        }
        $positionen[(string)$id] = $p;
    }
    // Nur Kürzel behalten, die Yahoo wirklich kennt (sonst keine Verknüpfung)
    $gepruefteKurse = $kandidaten !== [] ? yahooKurse($kandidaten) : [];
    foreach ($positionen as $id => $p) {
        if ($p['symbol'] !== '' && !isset($gepruefteKurse[$p['symbol']])) {
            $positionen[$id]['symbol'] = '';
        }
    }
    $konto = (array)($j['accountTotals'] ?? []);
    $etoro = [
        'zeit' => time(), 'waehrung' => $waehrung, 'fehler' => '',
        'konto' => [
            'cash' => $summe($konto, 'accountAvailableCash'), 'gesamt' => $summe($konto, 'accountTotalValue'),
            'gv' => $summe($konto, 'accountCurrentPnl'), 'heute' => $summe($konto, 'dailyGainAccountCurrency'),
        ],
        'positionen' => $positionen,
    ];
    datenAendern(function (array &$d) use ($etoro): void {
        $d['broker']['etoro'] = $etoro;
        depotFirmenAbgleichen($d);
    });
    return '';
}

/** eToro-Kürzel in Yahoo-Schreibweise (z. B. „NESN.ZU“ → „NESN.SW“, „BRK.B“ → „BRK-B“). */
function etoroZuYahoo(string $kuerzel): string
{
    $k = strtoupper(trim($kuerzel));
    if ($k === '') {
        return '';
    }
    $endungen = ['.ZU' => '.SW', '.LSB' => '.LS', '.NV' => '.AS'];
    foreach ($endungen as $von => $nach) {
        if (str_ends_with($k, $von)) {
            return substr($k, 0, -strlen($von)) . $nach;
        }
    }
    if (preg_match('/^([A-Z]+)\.([A-Z])$/', $k, $m)) {
        return $m[1] . '-' . $m[2];
    }
    return $k;
}

// ---------------------------------------------------------------------------
// Alles zusammen: Depot-Übersicht in Euro
// ---------------------------------------------------------------------------

/**
 * Hält „Meine Firmen“ mit den Depots in Einklang: gehaltene Aktien werden
 * aufgenommen bzw. als „Im Depot“ markiert, verkaufte zurück auf „Beobachten“.
 */
function depotFirmenAbgleichen(array &$d): void
{
    $gehalten = [];
    foreach ((array)($d['broker']['traderepublic']['positionen'] ?? []) as $p) {
        if ($p['stueck'] > 1e-9 && ($p['art'] ?? '') === 'aktie' && $p['symbol'] !== '') {
            $gehalten[$p['symbol']] = ['name' => $p['name'], 'isin' => $p['isin']];
        }
    }
    foreach ((array)($d['broker']['etoro']['positionen'] ?? []) as $p) {
        if ($p['stueck'] > 1e-9 && ($p['art'] ?? '') === 'aktie' && $p['symbol'] !== '') {
            $gehalten[$p['symbol']] ??= ['name' => $p['name'], 'isin' => ''];
        }
    }
    foreach ($gehalten as $s => $info) {
        if (!isset($d['firmen'][$s])) {
            $d['firmen'][$s] = firmaNormal(['name' => $info['name'], 'isin' => $info['isin'], 'status' => 'depot'], $s);
        } elseif ($d['firmen'][$s]['status'] !== 'depot') {
            $d['firmen'][$s]['status'] = 'depot';
        }
        if ($info['isin'] !== '' && $d['firmen'][$s]['isin'] === '') {
            $d['firmen'][$s]['isin'] = $info['isin'];
        }
    }
    foreach ($d['firmen'] as $s => &$f) {
        if ($f['status'] === 'depot' && !isset($gehalten[$s]) && manuellePosition($f)['stueck'] <= 1e-9) {
            $f['status'] = 'beobachten';
        }
    }
    unset($f);
}

/**
 * Alle Positionen aller Quellen mit aktuellem Wert in Euro.
 * ['positionen' => [...], 'quellen' => [quelle => Summen], 'gesamt' => Summen, 'fx' => …]
 */
function depotUebersicht(array $d): array
{
    $roh = [];
    foreach ($d['firmen'] as $s => $f) {
        $m = manuellePosition($f);
        if ($m['stueck'] > 1e-9) {
            $roh[] = ['quelle' => 'manuell', 'symbol' => (string)$s, 'isin' => $f['isin'], 'name' => $f['name'], 'art' => 'aktie',
                'stueck' => $m['stueck'], 'einstand' => $m['einstand']];
        }
    }
    foreach ((array)($d['broker']['traderepublic']['positionen'] ?? []) as $p) {
        if ($p['stueck'] > 1e-9) {
            $roh[] = ['quelle' => 'traderepublic', 'symbol' => (string)$p['symbol'], 'isin' => (string)$p['isin'], 'name' => (string)$p['name'],
                'art' => (string)($p['art'] ?? ''), 'stueck' => (float)$p['stueck'], 'einstand' => (float)$p['einstand']];
        }
    }
    $symbole = array_values(array_filter(array_column($roh, 'symbol')));
    $kurse = $symbole !== [] ? yahooKurse($symbole) : [];
    $waehrungen = array_map(static fn(array $k): string => (string)($k['waehrung'] ?? ''), $kurse);
    $etoroWaehrung = (string)($d['broker']['etoro']['waehrung'] ?? '');
    if ($etoroWaehrung !== '') {
        $waehrungen[] = $etoroWaehrung;
    }
    $fx = wechselkurse($waehrungen);

    $positionen = [];
    foreach ($roh as $p) {
        $k = $p['symbol'] !== '' ? ($kurse[$p['symbol']] ?? null) : null;
        $kurs = $k['kurs'] ?? null;
        $w = (string)($k['waehrung'] ?? 'EUR');
        $wert = $kurs !== null ? inEuro($kurs * $p['stueck'], $w, $fx) : null;
        $heute = ($k['aend'] ?? null) !== null ? inEuro((float)$k['aend'] * $p['stueck'], $w, $fx) : null;
        $positionen[] = $p + [
            'kurs' => $kurs, 'waehrung' => $w, 'wert' => $wert, 'heute' => $heute,
            'gv' => $wert !== null ? $wert - $p['einstand'] : null,
            'gv_proz' => $wert !== null && $p['einstand'] > 0 ? ($wert - $p['einstand']) / $p['einstand'] : null,
            'aend_proz' => $k['aend_proz'] ?? null,
        ];
    }
    foreach ((array)($d['broker']['etoro']['positionen'] ?? []) as $p) {
        $wert = inEuro((float)$p['wert'], $etoroWaehrung, $fx);
        $einstand = inEuro((float)$p['einstand'], $etoroWaehrung, $fx);
        $positionen[] = [
            'quelle' => 'etoro', 'symbol' => (string)$p['symbol'], 'isin' => '', 'name' => (string)$p['name'], 'art' => (string)$p['art'],
            'stueck' => (float)$p['stueck'], 'einstand' => (float)$einstand, 'kurs' => null, 'waehrung' => (string)$p['waehrung'],
            'wert' => $wert, 'heute' => inEuro((float)$p['heute'], $etoroWaehrung, $fx), 'gv' => inEuro((float)$p['gv'], $etoroWaehrung, $fx),
            'gv_proz' => $einstand ? (float)inEuro((float)$p['gv'], $etoroWaehrung, $fx) / $einstand : null, 'aend_proz' => null,
            'hebel' => (float)($p['hebel'] ?? 1), 'herkunft' => (array)($p['herkunft'] ?? []), 'kuerzel' => (string)($p['kuerzel'] ?? ''),
        ];
    }
    usort($positionen, static fn(array $a, array $b): int => ($b['wert'] ?? 0) <=> ($a['wert'] ?? 0));

    $leer = ['wert' => 0.0, 'einstand' => 0.0, 'gv' => 0.0, 'heute' => 0.0, 'anzahl' => 0, 'ohne_kurs' => 0];
    $quellen = [];
    $gesamt = $leer;
    foreach ($positionen as $p) {
        $q = $p['quelle'];
        $quellen[$q] ??= $leer;
        foreach ([&$quellen[$q], &$gesamt] as &$s) {
            $s['anzahl']++;
            if ($p['wert'] === null) {
                $s['ohne_kurs']++;
                continue;
            }
            $s['wert'] += $p['wert'];
            $s['einstand'] += $p['einstand'];
            $s['gv'] += (float)$p['gv'];
            $s['heute'] += (float)($p['heute'] ?? 0);
        }
        unset($s);
    }
    $etoroCash = isset($d['broker']['etoro']['konto']['cash']) ? inEuro((float)$d['broker']['etoro']['konto']['cash'], $etoroWaehrung, $fx) : null;
    return ['positionen' => $positionen, 'quellen' => $quellen, 'gesamt' => $gesamt, 'etoro_cash' => $etoroCash, 'fx' => $fx];
}

const FZ_QUELLEN = ['traderepublic' => 'Trade Republic', 'etoro' => 'eToro', 'manuell' => 'Manuell erfasst'];
