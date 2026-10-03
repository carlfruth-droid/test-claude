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
    $kopfNamen = array_map('spaltenName', array_map('strval', $zeilen[0]));
    if (in_array('accounttype', $kopfNamen, true) && in_array('assetclass', $kopfNamen, true) && in_array('transactionid', $kopfNamen, true)) {
        return trNativLesen($zeilen);
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

/**
 * Offizieller Trade-Republic-Transaktionsexport (seit 2026): Spalten u. a.
 * category, type, asset_class, name, symbol (= ISIN), shares (mit Vorzeichen),
 * price, amount, fee, tax. Jede Bestandsänderung – Kauf, Verkauf, Split,
 * Fusion, Spin-off, Ausbuchung – steht als Stück-Differenz darin.
 */
function trNativLesen(array $zeilen): array
{
    $kopf = array_map(static fn($k): string => spaltenName((string)$k), $zeilen[0]);
    $buchungen = [];
    foreach (array_slice($zeilen, 1) as $z) {
        if (count($z) < count($kopf)) {
            $z = array_pad($z, count($kopf), '');
        }
        $r = array_combine($kopf, array_slice($z, 0, count($kopf)));
        $datum = datumLesen((string)($r['date'] ?? $r['datetime'] ?? ''));
        if ($datum === '') {
            continue;
        }
        $isin = strtoupper(trim((string)($r['symbol'] ?? '')));
        $buchungen[] = [
            'zeit' => (string)($r['datetime'] ?? $datum), 'datum' => $datum,
            'kategorie' => strtoupper((string)($r['category'] ?? '')), 'art' => strtoupper((string)($r['type'] ?? '')),
            'klasse' => strtoupper((string)($r['assetclass'] ?? '')), 'name' => mb_substr(trim((string)($r['name'] ?? '')), 0, 80),
            'isin' => istIsin($isin) ? $isin : '', 'stueck' => zahlLesen((string)($r['shares'] ?? '')),
            'kurs' => zahlLesen((string)($r['price'] ?? '')), 'betrag' => zahlLesen((string)($r['amount'] ?? '')),
            'gebuehr' => (float)zahlLesen((string)($r['fee'] ?? '')), 'steuer' => (float)zahlLesen((string)($r['tax'] ?? '')),
            'beschreibung' => mb_substr((string)($r['description'] ?? ''), 0, 80),
        ];
    }
    if ($buchungen === []) {
        return ['ok' => false, 'fehler' => 'In der Datei wurden keine Buchungen gefunden.'];
    }
    usort($buchungen, static fn(array $a, array $b): int => strcmp($a['zeit'], $b['zeit']));

    $pos = [];
    $leer = static fn(array $b): array => ['isin' => $b['isin'], 'name' => $b['name'], 'klasse' => $b['klasse'],
        'stueck' => 0.0, 'einstand' => 0.0, 'realisiert' => 0.0, 'dividenden' => 0.0, 'gebuehren' => 0.0];
    $zaehler = [];
    $summen = ['dividenden' => 0.0, 'zinsen' => 0.0, 'einzahlungen' => 0.0, 'auszahlungen' => 0.0, 'konto' => 0.0];
    // Kapitalmaßnahmen am selben Tag gehören zusammen (z. B. Fusion: alte Aktie raus, neue rein)
    $massnahmen = [];
    foreach ($buchungen as $b) {
        $summen['konto'] += (float)$b['betrag'] + $b['gebuehr'] + $b['steuer'];
        $i = $b['isin'];
        if ($i !== '') {
            $pos[$i] ??= $leer($b);
            if ($b['name'] !== '') {
                $pos[$i]['name'] = $b['name'];
            }
            if ($b['klasse'] !== '') {
                $pos[$i]['klasse'] = $b['klasse'];
            }
        }
        $st = (float)$b['stueck'];
        if ($b['kategorie'] === 'TRADING' && $i !== '' && $st != 0.0) {
            $p = &$pos[$i];
            $p['gebuehren'] += abs($b['gebuehr']);
            if ($st > 0) {
                $p['einstand'] += ($b['betrag'] !== null ? abs((float)$b['betrag']) : $st * (float)$b['kurs']) + abs($b['gebuehr']);
                $p['stueck'] += $st;
                $zaehler['kauf'] = ($zaehler['kauf'] ?? 0) + 1;
            } else {
                $anteil = min(-$st, max($p['stueck'], 0.0));
                $schnitt = $p['stueck'] > 1e-9 ? $p['einstand'] / $p['stueck'] : 0.0;
                $erloes = ($b['betrag'] !== null ? (float)$b['betrag'] : -$st * (float)$b['kurs']) - abs($b['gebuehr']);
                $p['realisiert'] += $erloes - $schnitt * $anteil;
                $p['einstand'] -= $schnitt * $anteil;
                $p['stueck'] += $st;
                $zaehler['verkauf'] = ($zaehler['verkauf'] ?? 0) + 1;
            }
            unset($p);
        } elseif ($b['kategorie'] === 'CORPORATE_ACTION' && $i !== '' && $st != 0.0) {
            $massnahmen[$b['datum'] . '|' . $b['art']][] = $b;
            $zaehler['massnahme'] = ($zaehler['massnahme'] ?? 0) + 1;
        } elseif ($b['kategorie'] === 'CASH') {
            $betrag = (float)$b['betrag'];
            if (in_array($b['art'], ['DIVIDEND', 'DIVIDEND_EQUIVALENT_PAYMENT', 'EARNINGS'], true) && $i !== '') {
                $pos[$i]['dividenden'] += $betrag + $b['steuer'];
                $summen['dividenden'] += $betrag + $b['steuer'];
                $zaehler['dividende'] = ($zaehler['dividende'] ?? 0) + 1;
            } elseif (in_array($b['art'], ['TILG', 'COMPENSATION', 'EXCHANGE', 'LIQUIDATION_PROCEEDS'], true) && $i !== '') {
                $pos[$i]['realisiert'] += $betrag; // Auszahlung aus Fälligkeit, Abfindung, Umtausch
            } elseif ($b['art'] === 'INTEREST_PAYMENT') {
                $summen['zinsen'] += $betrag;
                $zaehler['zins'] = ($zaehler['zins'] ?? 0) + 1;
            } elseif ($betrag > 0 && in_array($b['art'], ['CUSTOMER_INBOUND', 'CUSTOMER_INPAYMENT', 'TRANSFER_INBOUND'], true)) {
                $summen['einzahlungen'] += $betrag;
                $zaehler['einzahlung'] = ($zaehler['einzahlung'] ?? 0) + 1;
            } elseif ($betrag < 0 && str_contains($b['art'], 'OUTBOUND')) {
                $summen['auszahlungen'] += -$betrag;
                $zaehler['auszahlung'] = ($zaehler['auszahlung'] ?? 0) + 1;
            } else {
                $zaehler['sonst'] = ($zaehler['sonst'] ?? 0) + 1;
            }
        } else {
            $zaehler['sonst'] = ($zaehler['sonst'] ?? 0) + 1;
        }
    }
    // Kapitalmaßnahmen: Einstand der ausgebuchten Stücke geht auf die eingebuchten über
    foreach ($massnahmen as $gruppe) {
        $wegKosten = 0.0;
        $rein = [];
        foreach ($gruppe as $b) {
            $p = &$pos[$b['isin']];
            $st = (float)$b['stueck'];
            if ($st < 0) {
                $anteil = min(-$st, max($p['stueck'], 0.0));
                $schnitt = $p['stueck'] > 1e-9 ? $p['einstand'] / $p['stueck'] : 0.0;
                $wegKosten += $schnitt * $anteil;
                $p['einstand'] -= $schnitt * $anteil;
                $p['stueck'] += $st;
            } else {
                $p['stueck'] += $st;
                $rein[] = $b['isin'];
            }
            unset($p);
        }
        $rein = array_values(array_unique($rein));
        if ($rein !== []) {
            foreach ($rein as $ziel) {
                $pos[$ziel]['einstand'] += $wegKosten / count($rein);
            }
        } elseif ($gruppe !== []) {
            // Wertlos, ausgebucht, fällig: der Einstand ist verloren (Erlöse kamen ggf. als Zahlung)
            $pos[$gruppe[0]['isin']]['realisiert'] -= $wegKosten;
        }
    }
    $positionen = [];
    foreach ($pos as $isin => $p) {
        if (abs($p['stueck']) < 1e-6) {
            $p['stueck'] = 0.0;
            $p['einstand'] = 0.0;
        }
        $positionen[$isin] = [
            'isin' => $isin, 'ticker' => '', 'name' => $p['name'] !== '' ? $p['name'] : $isin,
            'klasse' => $p['klasse'], 'stueck' => max(0.0, $p['stueck']), 'einstand' => max(0.0, $p['einstand']),
            'realisiert' => $p['realisiert'], 'dividenden' => $p['dividenden'], 'gebuehren' => $p['gebuehren'],
        ];
    }
    $anzeige = array_map(static fn(array $b): array => [
        'datum' => $b['datum'], 'typ' => strtolower($b['art']), 'roh_typ' => $b['art'], 'isin' => $b['isin'], 'name' => $b['name'],
        'symbol' => '', 'stueck' => $b['stueck'], 'kurs' => $b['kurs'], 'betrag' => $b['betrag'], 'waehrung' => 'EUR',
        'gebuehr' => abs($b['gebuehr']), 'steuer' => abs($b['steuer']),
    ], $buchungen);
    return [
        'ok' => true, 'fehler' => '', 'format' => 'traderepublic',
        'spalten' => ['Format' => 'Trade-Republic-Transaktionsexport'],
        'buchungen' => $anzeige, 'zeilen' => count($buchungen), 'zaehler' => $zaehler,
        'fertige_positionen' => $positionen, 'summen' => $summen,
    ];
}

/** Positionen eines gelesenen Exports – fertig berechnet oder aus den Buchungen. */
function trPositionenAus(array $ergebnis): array
{
    return isset($ergebnis['fertige_positionen']) && is_array($ergebnis['fertige_positionen'])
        ? $ergebnis['fertige_positionen'] : trPositionen($ergebnis['buchungen']);
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
    $positionen = trPositionenAus($ergebnis);
    if (function_exists('set_time_limit')) { @set_time_limit(240); }
    $klassen = ['STOCK' => 'aktie', 'FUND' => 'etf', 'CRYPTO' => 'krypto', 'DERIVATIVE' => 'derivat', 'BOND' => 'anleihe', 'SYNTHETIC' => 'sonst'];
    $isins = [];
    foreach ($positionen as $p) {
        if ($p['stueck'] > 1e-9 && $p['isin'] !== '' && !in_array($klassen[(string)($p['klasse'] ?? '')] ?? '', ['derivat', 'sonst'], true)) {
            $isins[] = $p['isin'];
        }
    }
    yahooSucheVorladen($isins, ['EQUITY', 'ETF', 'MUTUALFUND']);
    foreach ($positionen as $k => $p) {
        $klasse = $klassen[(string)($p['klasse'] ?? '')] ?? '';
        if ($p['stueck'] <= 1e-9 || $klasse === 'derivat' || $klasse === 'sonst') {
            // komplett verkauft oder ohne Börsenkurs bei Yahoo (Optionsscheine, Zertifikate, Bezugsrechte)
            $positionen[$k] += ['symbol' => '', 'art' => $klasse];
            continue;
        }
        $info = $p['isin'] !== '' ? symbolZuIsin($p['isin'], $p['name']) : ['symbol' => $p['ticker'], 'art' => '', 'name' => $p['name']];
        $positionen[$k]['symbol'] = $info['symbol'];
        $positionen[$k]['art'] = $info['art'] !== '' ? $info['art'] : $klasse;
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
        'summen' => $ergebnis['summen'] ?? [
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
// eToro: Kontoauszug als Excel-Datei (ohne Schnittstelle)
// ---------------------------------------------------------------------------

const FZ_KRYPTO = ['BTC', 'ETH', 'BNB', 'XRP', 'SOL', 'TRX', 'ADA', 'BCH', 'XLM', 'LINK', 'DOGE', 'DOT', 'AVAX', 'LTC', 'SHIB', 'POL',
    'MATIC', 'UNI', 'ATOM', 'ETC', 'HBAR', 'NEAR', 'APT', 'ALGO', 'XTZ', 'EOS', 'AAVE', 'FIL', 'ICP', 'SAND', 'MANA', 'ARB', 'OP', 'SUI', 'TON', 'PEPE'];

/** Spalten eines Blatts über die Kopfzeile finden: [feld => Spaltenbuchstabe] und Index der ersten Datenzeile. */
function blattSpalten(array $zeilen, array $felder, array $pflicht): ?array
{
    foreach (array_slice($zeilen, 0, 30, true) as $i => $z) {
        $namen = array_map('spaltenName', $z);
        $spalten = [];
        foreach ($felder as $feld => $aliase) {
            foreach ($aliase as $a) {
                $col = array_search(spaltenName($a), $namen, true);
                if ($col !== false) {
                    $spalten[$feld] = (string)$col;
                    break;
                }
            }
        }
        if (count(array_intersect_key(array_flip($pflicht), $spalten)) === count($pflicht)) {
            return ['spalten' => $spalten, 'ab' => $i + 1];
        }
    }
    return null;
}

/** Zeilen eines Blatts als [feld => wert] ab der Kopfzeile. */
function blattZeilen(array $zeilen, array $kopf): array
{
    $erg = [];
    foreach (array_slice($zeilen, $kopf['ab']) as $z) {
        $r = [];
        foreach ($kopf['spalten'] as $feld => $col) {
            $r[$feld] = (string)($z[$col] ?? '');
        }
        if (implode('', $r) !== '') {
            $erg[] = $r;
        }
    }
    return $erg;
}

/** Excel-Seriennummer, „02/10/2026 13:45:00“ oder „02.10.2026“ → Unix-Zeit (UTC); 0 wenn unlesbar. */
function excelZeit(string $s): int
{
    $s = trim($s);
    if (is_numeric($s) && (float)$s > 20000 && (float)$s < 80000) {
        return (int)round(((float)$s - 25569) * 86400);
    }
    if (preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?/', $s, $m)) {
        return gmmktime((int)($m[4] ?? 0), (int)($m[5] ?? 0), (int)($m[6] ?? 0), (int)$m[2], (int)$m[1], (int)$m[3]) ?: 0;
    }
    $t = strtotime($s . ' UTC');
    return $t !== false ? $t : 0;
}

function zahlAusZelle(string $s): ?float
{
    $s = trim($s);
    if (is_numeric($s)) {
        return (float)$s;
    }
    return preg_match('/\d/', $s) ? zahlLesen($s) : null;
}

/** Hebel aus „X2“, „2“ oder „x 2“. */
function hebelLesen(string $s): float
{
    return preg_match('/(\d+(?:[.,]\d+)?)/', $s, $m) ? max(1.0, (float)str_replace(',', '.', $m[1])) : 1.0;
}

/** eToro-Währung („GBX“) in Yahoo-Schreibweise („GBp“). */
function etoroWaehrung(string $w): string
{
    $w = strtoupper(trim($w));
    return ['GBX' => 'GBp', 'ZAC' => 'ZAc', 'ILA' => 'ILA', '' => 'USD'][$w] ?? $w;
}

/**
 * eToro-Kontoauszug (Excel) auswerten. Grundlage sind alle eröffneten Positionen
 * ohne die geschlossenen (Kontoaktivität + Geschlossene Positionen). Ein Blatt
 * „Bestände“ (falls vorhanden) liefert Hebel, Richtung, Einstiegskurs und ISIN.
 */
function etoroAuszugLesen(array $blaetter): array
{
    $felder = [
        'aktivitaet' => [
            'datum' => ['Datum', 'Date'], 'art' => ['Art', 'Type'], 'details' => ['Details'], 'betrag' => ['Betrag', 'Amount'],
            'einheiten' => ['Einheiten', 'Units'], 'kontostand' => ['Kontostand', 'Balance'], 'id' => ['Positions-ID', 'Position ID'],
            'typ' => ['Anlagentyp', 'Asset type'],
        ],
        'geschlossen' => [
            'instrument' => ['Instrument / Aktion', 'Action', 'Instrument'], 'id' => ['Positions-ID', 'Position ID'],
            'richtung' => ['Long / Short', 'Long/Short'], 'schluss' => ['Schließungsdatum', 'Close Date'], 'hebel' => ['Hebel', 'Leverage'],
            'typ' => ['Art', 'Type'], 'isin' => ['ISIN'], 'kopiert' => ['Kopiert von', 'Copied From'],
        ],
        'bestaende' => [
            'stichtag' => ['Stichtag', 'Date'], 'instrument' => ['Instrument'], 'id' => ['Positions-ID', 'Position ID'],
            'richtung' => ['Long / Short', 'Long/Short'], 'hebel' => ['Hebel', 'Leverage'], 'kurs_auf' => ['Eröffnungskurs', 'Open Rate'],
            'einheiten' => ['Einheiten', 'Units'], 'kurs' => ['Kurs am Stichtag', 'Current Rate'], 'wert_usd' => ['Wert (USD)', 'Value (USD)'],
            'art' => ['Art', 'Type'], 'isin' => ['ISIN'],
        ],
        'uebersicht' => ['kennzahl' => ['Kennzahl', 'Details'], 'usd' => ['Betrag (USD)', 'USD'], 'eur' => ['Betrag (EUR)', 'EUR']],
        'dividenden' => [
            'instrument' => ['Instrument', 'Instrument Name'], 'id' => ['Positions-ID', 'Position ID'], 'isin' => ['ISIN'],
            'netto' => ['Nettodividende (USD)', 'Net Dividend Received (USD)'],
        ],
    ];
    $pflicht = [
        'aktivitaet' => ['datum', 'art', 'details', 'betrag', 'einheiten', 'id', 'kontostand'],
        'geschlossen' => ['id', 'schluss'],
        'bestaende' => ['stichtag', 'id', 'einheiten', 'kurs_auf'],
        'uebersicht' => ['kennzahl', 'usd'],
        'dividenden' => ['id', 'isin', 'instrument', 'netto'],
    ];
    $daten = [];
    foreach ($blaetter as $zeilen) {
        foreach ($felder as $teil => $f) {
            if (isset($daten[$teil])) {
                continue;
            }
            $kopf = blattSpalten($zeilen, $f, $pflicht[$teil]);
            if ($kopf !== null) {
                $daten[$teil] = blattZeilen($zeilen, $kopf);
                break;
            }
        }
    }
    if (!isset($daten['aktivitaet'], $daten['geschlossen'])) {
        return ['ok' => false, 'fehler' => 'In der Datei fehlen die Blätter „Kontoaktivität“ und „Geschlossene Positionen“ (bzw. „Account Activity“ und „Closed Positions“). Bitte den eToro-Kontoauszug als Excel-Datei hochladen.'];
    }

    // Geschlossene Positionen und Namen/ISIN je eToro-Kürzel
    $geschlossen = [];
    $instrumente = [];
    foreach ($daten['geschlossen'] as $r) {
        if ($r['id'] !== '') {
            $geschlossen[$r['id']] = true;
        }
        if (preg_match('/^(.*\S)\s*\(([^()]+)\)\s*$/u', $r['instrument'], $m)) {
            $instrumente[strtoupper($m[2])] ??= ['name' => $m[1], 'isin' => istIsin(strtoupper($r['isin'] ?? '')) ? strtoupper($r['isin']) : ''];
        }
    }

    // Kontoaktivität: Eröffnungen, Splits, Kontostand, Summen
    $eroeffnet = [];
    $splits = [];
    $kontostand = null;
    $letzte = 0;
    $erste = PHP_INT_MAX;
    $summen = ['dividenden' => 0.0, 'einzahlungen' => 0.0, 'auszahlungen' => 0.0, 'zinsen' => 0.0, 'gebuehren' => 0.0];
    foreach ($daten['aktivitaet'] as $r) {
        $zeit = excelZeit($r['datum']);
        if ($zeit <= 0) {
            continue;
        }
        $art = mb_strtolower($r['art']);
        $betrag = zahlAusZelle($r['betrag']) ?? 0.0;
        if ($zeit >= $letzte) {
            $letzte = $zeit;
            $kontostand = zahlAusZelle($r['kontostand']) ?? $kontostand;
        }
        $erste = min($erste, $zeit);
        if (in_array($art, ['position eröffnen', 'open position'], true) && $r['id'] !== '') {
            [$kuerzel, $waehrung] = array_pad(explode('/', $r['details'], 2), 2, 'USD');
            $eroeffnet[$r['id']] = [
                'zeit' => $zeit, 'kuerzel' => strtoupper(trim($kuerzel)), 'waehrung' => etoroWaehrung($waehrung),
                'betrag' => $betrag, 'einheiten' => zahlAusZelle($r['einheiten']) ?? 0.0,
                'typ' => mb_strtolower($r['typ']),
            ];
        } elseif (str_contains($art, 'split') && preg_match('/(\d+(?:\.\d+)?)\s*:\s*(\d+(?:\.\d+)?)/', $r['details'], $m) && (float)$m[2] > 0) {
            $splits[$r['id']][] = ['zeit' => $zeit, 'faktor' => (float)$m[1] / (float)$m[2]];
        } elseif (str_starts_with($art, 'dividend')) {
            $summen['dividenden'] += $betrag;
        } elseif (in_array($art, ['einzahlung', 'deposit'], true)) {
            $summen['einzahlungen'] += $betrag;
        } elseif (in_array($art, ['auszahlung', 'withdraw request', 'withdrawal'], true)) {
            $summen['auszahlungen'] += abs($betrag);
        } elseif (str_contains($art, 'zins') || str_contains($art, 'interest')) {
            $summen['zinsen'] += $betrag;
        } elseif (str_contains($art, 'gebühr') || str_contains($art, 'fee') || $art === 'sdrt') {
            $summen['gebuehren'] += $betrag;
        }
    }

    // Letzter Stichtag im Blatt „Bestände“
    $bestand = [];
    $stichtag = 0;
    foreach ($daten['bestaende'] ?? [] as $r) {
        $stichtag = max($stichtag, excelZeit($r['stichtag']));
    }
    foreach ($daten['bestaende'] ?? [] as $r) {
        if ($stichtag > 0 && excelZeit($r['stichtag']) === $stichtag && $r['id'] !== '') {
            $bestand[$r['id']] = $r;
        }
    }
    foreach ($bestand as $id => $b) {
        if (isset($eroeffnet[$id])) {
            $isin = strtoupper(trim($b['isin']));
            $instrumente[$eroeffnet[$id]['kuerzel']] = ['name' => $b['instrument'], 'isin' => istIsin($isin) ? $isin : '']
                + ($instrumente[$eroeffnet[$id]['kuerzel']] ?? []);
            if (stripos($b['art'], 'crypto') !== false) {
                $instrumente[$eroeffnet[$id]['kuerzel']]['krypto'] = true;
            }
        }
    }

    // Dividenden nennen Name und ISIN je Position – hilft bei nach dem Stichtag gekauften Titeln
    foreach ($daten['dividenden'] ?? [] as $r) {
        $isin = strtoupper(trim($r['isin']));
        $k = $eroeffnet[$r['id']]['kuerzel'] ?? '';
        if ($k !== '' && istIsin($isin) && empty($instrumente[$k]['isin'])) {
            $instrumente[$k] = ['name' => $r['instrument'], 'isin' => $isin] + ($instrumente[$k] ?? []);
        }
    }

    // Offene Positionen
    $positionen = [];
    foreach ($eroeffnet as $id => $o) {
        if (isset($geschlossen[$id])) {
            continue;
        }
        $b = $bestand[$id] ?? null;
        $info = $instrumente[$o['kuerzel']] ?? ['name' => '', 'isin' => ''];
        $ab = $b !== null ? $stichtag : $o['zeit'];
        $faktor = 1.0;
        foreach ($splits[$id] ?? [] as $s) {
            if ($s['zeit'] > $ab) {
                $faktor *= $s['faktor'];
            }
        }
        $krypto = !empty($info['krypto']) || in_array($o['kuerzel'], FZ_KRYPTO, true);
        $cfd = $o['typ'] === 'cfd';
        $p = [
            'id' => (string)$id, 'kuerzel' => $o['kuerzel'], 'waehrung' => $o['waehrung'],
            'name' => $info['name'] !== '' ? $info['name'] : $o['kuerzel'], 'isin' => $info['isin'],
            'art' => $krypto ? 'krypto' : ($cfd ? 'cfd' : (str_contains($o['typ'], 'etf') ? 'etf' : 'aktie')),
            'cfd' => $cfd, 'betrag' => $o['betrag'], 'zeit' => $o['zeit'],
            'stueck' => ($b !== null ? (zahlAusZelle($b['einheiten']) ?? $o['einheiten']) : $o['einheiten']) * $faktor,
            'hebel' => null, 'richtung' => 1, 'kurs_einstand' => null, 'wert_usd' => null,
        ];
        if ($b !== null) {
            $p['hebel'] = hebelLesen($b['hebel']);
            $p['richtung'] = stripos($b['richtung'], 'short') !== false ? -1 : 1;
            $auf = zahlAusZelle($b['kurs_auf']);
            $p['kurs_einstand'] = $auf !== null ? $auf / $faktor : null;
            $kurs = zahlAusZelle($b['kurs']);
            $wert = zahlAusZelle($b['wert_usd']);
            $einh = zahlAusZelle($b['einheiten']);
            if ($kurs !== null && $wert !== null && $einh && $kurs > 0 && $auf !== null) {
                // Wert in USD am Stichtag: Einsatz + Gewinn/Verlust (bei Hebel und Short nicht das Volumen)
                $usdJe = abs($wert) / ($einh * $kurs);
                $p['wert_usd'] = $p['hebel'] <= 1 && $p['richtung'] > 0 ? abs($wert)
                    : $o['betrag'] + $p['richtung'] * $einh * ($kurs - $auf) * $usdJe;
            }
        } elseif (!$cfd) {
            $p['hebel'] = 1.0;
        }
        $positionen[] = $p;
    }
    if ($positionen === [] && $eroeffnet === []) {
        return ['ok' => false, 'fehler' => 'Im Kontoauszug wurden keine eröffneten Positionen gefunden.'];
    }

    $uebersicht = [];
    foreach ($daten['uebersicht'] ?? [] as $r) {
        $uebersicht[spaltenName($r['kennzahl'])] = ['usd' => zahlAusZelle($r['usd']), 'eur' => zahlAusZelle($r['eur'] ?? '')];
    }
    $eigenkapital = $uebersicht['unealisierteseigenkapitalende']['usd'] ?? ($uebersicht['unrealisierteseigenkapitalende']['usd'] ?? null);
    $zaehler = ['aktie' => 0, 'etf' => 0, 'krypto' => 0, 'cfd' => 0];
    foreach ($positionen as $p) {
        $zaehler[$p['art']] = ($zaehler[$p['art']] ?? 0) + 1;
    }
    return [
        'ok' => true, 'von' => $erste === PHP_INT_MAX ? 0 : $erste, 'bis' => $letzte, 'stichtag' => $stichtag,
        'cash' => $kontostand, 'eigenkapital' => $eigenkapital, 'summen' => $summen,
        'eroeffnet' => count($eroeffnet), 'geschlossen' => count($geschlossen), 'zaehler' => $zaehler,
        'eingesetzt' => array_sum(array_column($positionen, 'betrag')), 'positionen' => $positionen,
    ];
}

/** Nächster üblicher eToro-Hebel zum Verhältnis Volumen/Einsatz. */
function hebelSchaetzen(float $verhaeltnis): float
{
    $best = 1.0;
    foreach ([1, 2, 3, 5, 10, 20] as $h) {
        if (abs(log(max($verhaeltnis, 0.01) / $h)) < abs(log(max($verhaeltnis, 0.01) / $best))) {
            $best = (float)$h;
        }
    }
    return $best;
}

/** Eurobetrag in eine andere Währung (Gegenstück zu inEuro). */
function ausEuro(?float $eur, string $waehrung, array $fx): ?float
{
    $eins = inEuro(1.0, $waehrung, $fx);
    return $eur !== null && $eins ? $eur / $eins : null;
}

/** Kontoauszug übernehmen: Yahoo-Kürzel zuordnen, fehlende Hebel schätzen, Positionen je Instrument zusammenfassen. */
function etoroAuszugSpeichern(array $ergebnis, string $dateiname): array
{
    if (function_exists('set_time_limit')) { @set_time_limit(240); }
    $positionen = $ergebnis['positionen'];
    $isins = array_values(array_unique(array_filter(array_map(
        static fn(array $p): string => $p['art'] !== 'krypto' ? $p['isin'] : '', $positionen))));
    yahooSucheVorladen($isins, ['EQUITY', 'ETF', 'MUTUALFUND']);

    // Kürzel je Instrument: Krypto → BTC-USD, sonst über die ISIN, ersatzweise das eToro-Kürzel
    $symbole = [];
    $ohneIsin = [];
    foreach ($positionen as $p) {
        $k = $p['kuerzel'];
        if (isset($symbole[$k])) {
            continue;
        }
        if ($p['art'] === 'krypto') {
            $symbole[$k] = ['symbol' => $k . '-USD', 'name' => ''];
        } elseif ($p['isin'] !== '') {
            $info = symbolZuIsin($p['isin'], $p['name']);
            $symbole[$k] = ['symbol' => $info['symbol'], 'name' => $info['name']];
        } else {
            $s = etoroZuYahoo($k);
            if (!str_contains($s, '.') && isset(['CHF' => 1, 'GBp' => 1, 'HKD' => 1][$p['waehrung']])) {
                $s .= ['CHF' => '.SW', 'GBp' => '.L', 'HKD' => '.HK'][$p['waehrung']];
            }
            $symbole[$k] = ['symbol' => $s, 'name' => ''];
            $ohneIsin[$k] = $p['waehrung'];
        }
    }
    $alle = array_values(array_unique(array_filter(array_column($symbole, 'symbol'))));
    $kurse = $alle !== [] ? yahooKurse($alle) : [];
    // Ohne ISIN nur verknüpfen, wenn Yahoo das Kürzel in derselben Währung kennt (sonst droht eine falsche Firma)
    foreach ($ohneIsin as $k => $w) {
        $s = $symbole[$k]['symbol'];
        if ($s === '' || !isset($kurse[$s]) || waehrungBasis((string)($kurse[$s]['waehrung'] ?? '')) !== waehrungBasis($w)) {
            $symbole[$k]['symbol'] = '';
        }
    }
    $waehrungen = array_merge(['USD'], array_column($positionen, 'waehrung'),
        array_map(static fn(array $k): string => (string)($k['waehrung'] ?? ''), $kurse));
    $fx = wechselkurse($waehrungen);

    $gruppen = [];
    foreach ($positionen as $p) {
        $symbol = $symbole[$p['kuerzel']]['symbol'];
        $k = $symbol !== '' ? ($kurse[$symbol] ?? null) : null;
        $kursInst = $k !== null && $k['kurs'] !== null ? ausEuro(inEuro((float)$k['kurs'], (string)$k['waehrung'], $fx), $p['waehrung'], $fx) : null;
        $einsatzInst = ausEuro(inEuro($p['betrag'], 'USD', $fx), $p['waehrung'], $fx);
        $geschaetzt = false;
        if ($p['hebel'] === null) {
            // Nach dem Stichtag eröffnete CFDs: Hebel aus Volumen zu Einsatz schätzen
            $p['hebel'] = $kursInst && $einsatzInst ? hebelSchaetzen($p['stueck'] * $kursInst / $einsatzInst) : 1.0;
            $geschaetzt = true;
        }
        if ($p['kurs_einstand'] === null) {
            $p['kurs_einstand'] = $einsatzInst && $p['stueck'] > 0 ? $einsatzInst * $p['hebel'] / $p['stueck'] : null;
        }
        if ($p['wert_usd'] === null) {
            $p['wert_usd'] = $p['betrag'];
        }
        if ($p['name'] === $p['kuerzel']) {
            $p['name'] = $symbole[$p['kuerzel']]['name'] !== '' ? $symbole[$p['kuerzel']]['name'] : ((string)($k['name'] ?? '') !== '' ? (string)$k['name'] : $p['name']);
        }
        $schluessel = ($symbol !== '' ? $symbol : 'eToro:' . $p['kuerzel']) . '|' . $p['art'] . '|' . $p['hebel'] . '|' . $p['richtung'];
        $g = $gruppen[$schluessel] ?? [
            'name' => $p['name'],
            'isin' => $p['isin'], 'symbol' => $symbol, 'kuerzel' => $p['kuerzel'], 'art' => $p['art'], 'cfd' => $p['cfd'],
            'hebel' => $p['hebel'], 'richtung' => $p['richtung'], 'waehrung' => $p['waehrung'],
            'stueck' => 0.0, 'einstand_usd' => 0.0, 'wert_usd' => 0.0, 'kurs_summe' => 0.0, 'anzahl' => 0, 'geschaetzt' => false,
        ];
        $g['stueck'] += $p['stueck'];
        $g['einstand_usd'] += $p['betrag'];
        $g['wert_usd'] += (float)$p['wert_usd'];
        $g['kurs_summe'] += $p['stueck'] * (float)$p['kurs_einstand'];
        $g['anzahl']++;
        $g['geschaetzt'] = $g['geschaetzt'] || $geschaetzt;
        $gruppen[$schluessel] = $g;
    }
    foreach ($gruppen as &$g) {
        $g['kurs_einstand'] = $g['stueck'] > 0 ? $g['kurs_summe'] / $g['stueck'] : null;
        unset($g['kurs_summe']);
    }
    unset($g);
    uasort($gruppen, static fn(array $a, array $b): int => $b['einstand_usd'] <=> $a['einstand_usd']);

    $auszug = [
        'zeit' => time(), 'datei' => mb_substr($dateiname, 0, 120), 'von' => $ergebnis['von'], 'bis' => $ergebnis['bis'],
        'stichtag' => $ergebnis['stichtag'], 'cash_usd' => $ergebnis['cash'], 'eigenkapital_usd' => $ergebnis['eigenkapital'],
        'summen' => $ergebnis['summen'], 'eingesetzt_usd' => $ergebnis['eingesetzt'], 'anzahl' => count($positionen),
        'positionen' => array_values($gruppen),
    ];
    datenAendern(function (array &$d) use ($auszug): void {
        $d['broker']['etoro_auszug'] = $auszug;
        depotFirmenAbgleichen($d);
    });
    return $auszug;
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
    foreach (etoroAuszugPositionen($d) as $p) {
        if ($p['stueck'] > 1e-9 && $p['art'] === 'aktie' && $p['symbol'] !== '' && $p['richtung'] > 0) {
            $gehalten[$p['symbol']] ??= ['name' => $p['name'], 'isin' => (string)$p['isin']];
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
    $auszug = etoroAuszugPositionen($d);
    $symbole = array_values(array_filter(array_merge(array_column($roh, 'symbol'), array_column($auszug, 'symbol'))));
    $kurse = $symbole !== [] ? yahooKurse($symbole) : [];
    $waehrungen = array_merge(array_map(static fn(array $k): string => (string)($k['waehrung'] ?? ''), $kurse),
        array_column($auszug, 'waehrung'), $auszug !== [] ? ['USD'] : []);
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
    foreach ($auszug as $p) {
        // Wert = Einsatz + Gewinn/Verlust seit Eröffnung; ohne Hebel einfach Stück × Kurs
        $k = $p['symbol'] !== '' ? ($kurse[$p['symbol']] ?? null) : null;
        $kursEur = ($k['kurs'] ?? null) !== null ? inEuro((float)$k['kurs'], (string)$k['waehrung'], $fx) : null;
        $einsatz = (float)inEuro((float)$p['einstand_usd'], 'USD', $fx);
        $aufEur = $p['kurs_einstand'] !== null ? inEuro((float)$p['kurs_einstand'], (string)$p['waehrung'], $fx) : null;
        $einfach = $p['hebel'] <= 1 && $p['richtung'] > 0;
        if ($kursEur !== null && ($einfach || $aufEur !== null)) {
            $wert = $einfach ? $p['stueck'] * $kursEur : $einsatz + $p['richtung'] * $p['stueck'] * ($kursEur - $aufEur);
            $heute = ($k['aend'] ?? null) !== null ? $p['richtung'] * (float)inEuro((float)$k['aend'] * $p['stueck'], (string)$k['waehrung'], $fx) : null;
            $live = true;
        } else {
            $wert = inEuro((float)$p['wert_usd'], 'USD', $fx);
            $heute = null;
            $live = false;
        }
        $positionen[] = [
            'quelle' => 'etoro', 'symbol' => (string)$p['symbol'], 'isin' => (string)$p['isin'], 'name' => (string)$p['name'],
            'art' => (string)$p['art'], 'stueck' => (float)$p['stueck'], 'einstand' => $einsatz, 'kurs' => $k['kurs'] ?? null,
            'waehrung' => (string)($k['waehrung'] ?? $p['waehrung']), 'wert' => $wert, 'heute' => $heute,
            'gv' => $wert !== null ? $wert - $einsatz : null, 'gv_proz' => $wert !== null && $einsatz > 0 ? ($wert - $einsatz) / $einsatz : null,
            'aend_proz' => $live ? ($k['aend_proz'] ?? null) : null, 'hebel' => (float)$p['hebel'], 'kuerzel' => (string)$p['kuerzel'],
            'short' => $p['richtung'] < 0, 'cfd' => !empty($p['cfd']), 'geschaetzt' => !empty($p['geschaetzt']), 'stand_auszug' => !$live,
            'herkunft' => [],
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
    if ($etoroCash === null && $auszug !== [] && isset($d['broker']['etoro_auszug']['cash_usd'])) {
        $etoroCash = inEuro((float)$d['broker']['etoro_auszug']['cash_usd'], 'USD', $fx);
    }
    return ['positionen' => $positionen, 'quellen' => $quellen, 'gesamt' => $gesamt, 'etoro_cash' => $etoroCash, 'fx' => $fx];
}

/** Positionen aus dem eToro-Kontoauszug – nur solange keine Schnittstelle verbunden ist (sonst doppelt). */
function etoroAuszugPositionen(array $d): array
{
    if (!empty($d['broker']['etoro']['positionen'])) {
        return [];
    }
    return array_values(array_filter((array)($d['broker']['etoro_auszug']['positionen'] ?? []),
        static fn(array $p): bool => (float)($p['stueck'] ?? 0) > 1e-9));
}

const FZ_QUELLEN =['traderepublic' => 'Trade Republic', 'etoro' => 'eToro', 'manuell' => 'Manuell erfasst'];
