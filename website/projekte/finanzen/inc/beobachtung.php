<?php
declare(strict_types=1);

/*
 * Beobachtungsliste: je Firma Strategien aus Signalen (Zahlen mit Schwellen
 * und Ereignisse mit Fristen). Zahlen werden täglich geprüft (Sofort-Alarm bei
 * Schwellen), Ereignisse einmal pro Woche per KI-Recherche; samstags um 18 Uhr
 * kommt der Wochenbericht per E-Mail.
 */

const FZ_BEOB_MODELL = 'claude-haiku-4-5'; // schnell und günstig

const FZ_BEOB_QUELLEN = [
    'short_quote'   => ['Short-Quote', '%'],
    'days_to_cover' => ['Days to Cover', 'Tage'],
    'kurs'          => ['Kurs', ''],
    'potenzial'     => ['Abstand zum Analysten-Kursziel', '%'],
];

/** Vorlagen für typische Beobachtungsstrategien. */
function beobVorlagen(): array
{
    $e = static fn(string $id, string $titel, string $gruen, string $rot, string $frist = ''): array =>
        ['id' => $id, 'art' => 'ereignis', 'titel' => $titel, 'gruen' => $gruen, 'rot' => $rot, 'frist' => $frist];
    $z = static fn(string $id, string $titel, string $quelle, ?array $gruen, ?array $rot): array =>
        ['id' => $id, 'art' => 'zahl', 'titel' => $titel, 'quelle' => $quelle, 'gruen' => $gruen, 'rot' => $rot];
    return [
        'vulcan' => [
            'name' => 'Beispiel: Vulcan Energy – Timing der Shortseller',
            'titel' => 'Shortseller-Timing bis Mitte 2027',
            'these' => 'Shortseller setzen auf die umsatzlose Bauphase bis zur Produktion (Lionheart, Ende 2028) und auf Finanzierungsprobleme 2027. Gelingt das Financial Close und läuft der Bau nach Plan, droht ihnen ein Short Squeeze.',
            'asx' => 'VUL',
            'tagescheck' => ['an' => true, 'asx' => 'VUL.AX', 'eu' => 'VUL.DE'],
            'signale' => [
                $z('short', 'Short-Quote (ASIC)', 'short_quote', ['<', 5.5], ['>', 10.0]),
                $z('dtc', 'Days to Cover', 'days_to_cover', ['<', 8.0], ['>', 20.0]),
                $e('close', 'Financial Close der Projektfinanzierung (EIB und Geschäftsbanken)',
                    'Financial Close offiziell gemeldet: Kreditverträge unterschrieben, die Bankkredite stehen unwiderruflich bereit.',
                    'Financial Close verschiebt sich oder die Banken stellen neue Bedingungen.', '2027-03-31'),
                $e('kapital', 'Kapitalerhöhung / Verwässerung', '',
                    'Ausgabe neuer Aktien bzw. Kapitalerhöhung, vor allem deutlich unter dem Börsenkurs.'),
                $e('bau', 'Genehmigungen und Baustart CLEOP Frankfurt sowie Bohrplätze Oberrheingraben',
                    'Genehmigungen erteilt, der Hochbau beginnt planmäßig ohne Klagen.',
                    'Klagen von Bürgerinitiativen, behördliche Auflagen (z. B. Seismik) oder Verschiebung des Baustarts.', '2027-09-30'),
                $e('pilot', 'CLEOP-Pilotanlage: Reinheit und Produkt-Zertifizierung',
                    'Reinheit im Dauerbetrieb über den Erwartungen; Produkt-Zertifizierung durch Abnehmer (Stellantis, VW) gemeldet.',
                    'Technische Probleme oder Verunreinigungen gemeldet – oder auffällige Funkstille zu den Testergebnissen.', '2027-12-31'),
                $e('stellantis', 'Stellantis als Ankeraktionär',
                    'Stellantis stockt auf, gibt Bürgschaften oder erweitert die Abnahmezusage.',
                    'Stellantis reduziert oder verkauft seinen Anteil, zieht Zusagen zurück.'),
                $e('lithium', 'Lithiumpreis',
                    'Deutliche Erholung bzw. Trendwende beim Lithiumpreis (Carbonat/Hydroxid).',
                    'Lithiumpreis bleibt auf dem Tief oder fällt weiter.'),
            ],
        ],
        'pct' => [
            'name' => 'Beispiel: PureCycle – Quartalszahlen-Check',
            'titel' => 'Squeeze oder Absturz nach den Q3-Zahlen',
            'these' => 'Entscheidend sind Umsatz, Verlust je Aktie und vor allem die Auslastung in Ironton. Springt der Kurs nachbörslich mit hohem Volumen über den Widerstand, decken Shortseller ein.',
            'zahlencheck' => ['an' => true, 'us' => 'PCT', 'datum' => '2026-11-05', 'umsatz_min' => 8.28, 'eps_min' => -0.21, 'suchwort' => 'Ironton',
                'auslastung_min' => 40.0, 'kapazitaet' => 107.0, 'kurs_trigger' => 3.35, 'vol_faktor' => 5.0,
                'aktion_gruen' => 'Stop-Buy-Order bei 2,98 € für den nächsten Morgen im Broker scharfschalten; die restlichen 70 % investieren.',
                'aktion_gelb' => 'Keine Aktion. Erste Tranche (30 %) halten und abwarten, ob der Markt am nächsten Tag Kraft sammelt.',
                'aktion_rot' => 'Nichts tun. Darauf vorbereiten, dass am nächsten Morgen der Stop-Loss bei 2,45 € greift.'],
            'signale' => [
                $z('short', 'Short-Quote (USA)', 'short_quote', ['<', 15.0], ['>', 30.0]),
                $z('dtc', 'Days to Cover', 'days_to_cover', ['<', 3.0], ['>', 8.0]),
                $e('ironton', 'Ironton läuft stabil (Auslastung über 40–50 %)',
                    'Stabile Auslastung über 40–50 % gemeldet, keine neuen Ausfälle.',
                    'Neue Probleme, Stillstände oder Auslastung deutlich darunter.'),
                $e('finanzierung', 'Finanzierung / Verwässerung', '',
                    'Neue Aktien oder Wandelanleihen mit hoher Verwässerung.'),
            ],
        ],
        'short' => [
            'name' => 'Short-Squeeze-Kandidat',
            'titel' => 'Short-Squeeze möglich?',
            'these' => 'Viele Leerverkäufer – bröckeln ihre Positionen, kann der Kurs schnell steigen.',
            'signale' => [
                $z('short', 'Short-Quote', 'short_quote', ['<', 5.0], ['>', 10.0]),
                $z('dtc', 'Days to Cover', 'days_to_cover', ['<', 5.0], ['>', 15.0]),
                $e('katalysator', 'Positiver Katalysator', 'Wichtige gute Nachricht (Auftrag, Genehmigung, Finanzierung).', 'Gewinnwarnung, Verschiebung oder Finanzierungslücke.'),
            ],
        ],
        'finanzierung' => [
            'name' => 'Finanzierung und Verwässerung',
            'titel' => 'Ist die Finanzierung gesichert?',
            'these' => 'Das Unternehmen braucht viel Kapital, bevor Umsätze kommen. Entscheidend ist, ob die Finanzierung ohne starke Verwässerung gelingt.',
            'signale' => [
                $e('kredit', 'Kredite / Fördermittel gesichert', 'Finanzierung abgeschlossen bzw. Kredite ausgezahlt.', 'Finanzierung verzögert oder geplatzt.'),
                $e('kapital', 'Kapitalerhöhung', '', 'Kapitalerhöhung unter dem Börsenkurs oder Wandelanleihe mit hoher Verwässerung.'),
                $e('cash', 'Liquiditätsreichweite', 'Kasse reicht laut Bericht über die nächsten 12 Monate.', 'Warnung zur Liquidität oder Fortführungsrisiko.'),
            ],
        ],
        'bewertung' => [
            'name' => 'Bewertungs-Einstieg',
            'titel' => 'Einstieg, wenn es günstig wird',
            'these' => 'Gute Firma, aber zu teuer – kaufen, wenn das Kursziel der Analysten deutlich über dem Kurs liegt.',
            'signale' => [
                $z('potenzial', 'Abstand zum Analysten-Kursziel', 'potenzial', ['>', 30.0], ['<', 0.0]),
                $e('ausblick', 'Ausblick des Managements', 'Prognose angehoben.', 'Prognose gesenkt.'),
            ],
        ],
        'leer' => ['name' => 'Leere Strategie (Signale selbst anlegen)', 'titel' => 'Meine Beobachtung', 'these' => '', 'signale' => []],
    ];
}

function beobDaten(array $d): array
{
    $b = (array)($d['beobachtung'] ?? []);
    $b['firmen'] = (array)($b['firmen'] ?? []);
    foreach ($b['firmen'] as $s => $f) {
        $b['firmen'][$s] = array_merge(['strategien' => [], 'stand' => [], 'zahlen' => [], 'zahl_stand' => [], 'ki' => []], (array)$f);
    }
    $b['berichte'] = (array)($b['berichte'] ?? []);
    return $b;
}

/** Neue Strategie aus einer Vorlage (oder einer KI-Extraktion) anlegen. */
function beobStrategieNeu(string $symbol, array $vorlage): array
{
    return ['id' => bin2hex(random_bytes(4)), 'titel' => mb_substr((string)($vorlage['titel'] ?? 'Beobachtung'), 0, 120),
        'these' => mb_substr((string)($vorlage['these'] ?? ''), 0, 1500), 'asx' => strtoupper((string)($vorlage['asx'] ?? '')),
        'signale' => array_values(array_map('beobSignalNormal', (array)($vorlage['signale'] ?? []))), 'erstellt' => time()];
}

function beobSignalNormal(array $s): array
{
    $schwelle = static function ($x): ?array {
        if (!is_array($x) || count($x) < 2 || !in_array($x[0], ['<', '>'], true) || !is_numeric($x[1])) {
            return null;
        }
        return [$x[0], (float)$x[1]];
    };
    $art = ($s['art'] ?? '') === 'zahl' && isset(FZ_BEOB_QUELLEN[$s['quelle'] ?? '']) ? 'zahl' : 'ereignis';
    $frist = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($s['frist'] ?? '')) ? (string)$s['frist'] : '';
    $basis = ['id' => preg_match('/^[a-z0-9_-]{1,20}$/i', (string)($s['id'] ?? '')) ? (string)$s['id'] : bin2hex(random_bytes(3)),
        'art' => $art, 'titel' => mb_substr(trim((string)($s['titel'] ?? 'Signal')), 0, 140)];
    return $art === 'zahl'
        ? $basis + ['quelle' => (string)$s['quelle'], 'gruen' => $schwelle($s['gruen'] ?? null), 'rot' => $schwelle($s['rot'] ?? null)]
        : $basis + ['gruen' => mb_substr(trim((string)($s['gruen'] ?? '')), 0, 400), 'rot' => mb_substr(trim((string)($s['rot'] ?? '')), 0, 400), 'frist' => $frist];
}

/** Strategie aus einem eingefügten Text per KI erzeugen (ohne Websuche). */
function beobStrategieAusText(string $text, string $firmenname): array
{
    $system = 'Du strukturierst Anlage-Thesen für eine Beobachtungsliste. Antworte ausschließlich mit einem JSON-Objekt, ohne weiteren Text.';
    $auftrag = 'Firma: ' . $firmenname . "\n\nText des Nutzers:\n" . mb_substr($text, 0, 12000) . "\n\n"
        . "Mach daraus eine Beobachtungsstrategie im JSON-Format:\n"
        . '{"titel":"kurz","these":"1–2 Sätze","asx":"ASX-Kürzel falls an der ASX notiert, sonst leer","signale":['
        . '{"id":"kurz_ohne_leerzeichen","art":"ereignis","titel":"…","gruen":"wann spricht es für die These / den Anleger","rot":"wann dagegen","frist":"JJJJ-MM-TT oder leer"},'
        . '{"id":"…","art":"zahl","titel":"…","quelle":"short_quote|days_to_cover|kurs|potenzial","gruen":["<",5],"rot":[">",10]}]}' . "\n"
        . 'Zahlen-Signale nur für diese Quellen (short_quote in Prozent, days_to_cover in Tagen, potenzial = Abstand zum Analysten-Kursziel in Prozent). '
        . 'Alles andere (Meilensteine, Finanzierung, Genehmigungen, Rohstoffpreise, Großaktionäre) als Ereignis mit klarer, prüfbarer Bedingung. Höchstens 10 Signale. Deutsch.';
    $fehler = '';
    $j = kiAnfrage(['model' => FZ_BEOB_MODELL, 'max_tokens' => 3000, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $auftrag]]], 90, $fehler);
    if ($j === null) {
        return ['ok' => false, 'fehler' => $fehler];
    }
    $antwort = '';
    foreach ((array)($j['content'] ?? []) as $b) {
        $antwort .= ($b['type'] ?? '') === 'text' ? (string)$b['text'] : '';
    }
    $daten = beobJsonAus($antwort);
    if ($daten === null || empty($daten['signale'])) {
        return ['ok' => false, 'fehler' => 'Die KI hat keine verwertbare Strategie geliefert – bitte noch einmal versuchen oder eine Vorlage nehmen.'];
    }
    return ['ok' => true, 'vorlage' => $daten];
}

function beobJsonAus(string $text): ?array
{
    if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $m)) {
        $text = $m[1];
    } elseif (($a = strpos($text, '{')) !== false && ($e = strrpos($text, '}')) !== false) {
        $text = substr($text, $a, $e - $a + 1);
    }
    $j = json_decode($text, true);
    return is_array($j) ? $j : null;
}

// ---------------------------------------------------------------------------
// Zahlen: Kurs, Short-Quote (ASIC bzw. USA), Days to Cover
// ---------------------------------------------------------------------------

/** Tägliche Leerverkaufs-Meldungen der australischen Aufsicht ASIC (4 Handelstage Verzug): [Kürzel => [Quote %, Positionen, Datum]] */
function asicShortDaten(): array
{
    $wert = gecacht('asic-short', 12 * 3600, function (): ?array {
        for ($tag = 2; $tag <= 12; $tag++) {
            $dt = strtotime('-' . $tag . ' days');
            if ((int)date('N', $dt) >= 6) {
                continue;
            }
            $r = http('https://download.asic.gov.au/short-selling/RR' . date('Ymd', $dt) . '-001-SSDailyAggShortPos.csv', ['zeit' => 20]);
            if ($r['code'] !== 200 || !str_contains($r['body'], 'Product Code')) {
                continue;
            }
            $erg = [];
            foreach (preg_split('/\R/', $r['body']) ?: [] as $i => $zeile) {
                $z = str_getcsv($zeile);
                if ($i === 0 || count($z) < 5 || !is_numeric($z[4])) {
                    continue;
                }
                $erg[strtoupper(trim($z[1]))] = [round((float)$z[4], 3), (int)$z[2], date('Y-m-d', $dt)];
            }
            return $erg !== [] ? $erg : null;
        }
        return null;
    });
    return is_array($wert) ? $wert : [];
}

/** ASX-Kürzel einer Firma: ausdrücklich gesetzt, .AX-Kürzel oder die gemerkte Heimatbörse. */
function beobAsxKuerzel(string $symbol, array $strategien): string
{
    foreach ($strategien as $st) {
        if (($st['asx'] ?? '') !== '') {
            return (string)$st['asx'];
        }
    }
    if (str_ends_with($symbol, '.AX')) {
        return substr($symbol, 0, -3);
    }
    $heim = cacheLesen('heimat:' . $symbol, PHP_INT_MAX);
    $h = is_string($heim['wert'] ?? null) ? $heim['wert'] : '';
    return str_ends_with($h, '.AX') ? substr($h, 0, -3) : '';
}

/** Aktuelle Zahlen einer beobachteten Firma. */
function beobZahlen(string $symbol, array $strategien): array
{
    $z = ['datum' => date('Y-m-d'), 'kurs' => null, 'waehrung' => '', 'short_quote' => null, 'short_stand' => '', 'short_quelle' => '', 'days_to_cover' => null, 'potenzial' => null];
    $k = yahooKurse([$symbol])[$symbol] ?? null;
    if ($k !== null) {
        $z['kurs'] = $k['kurs'];
        $z['waehrung'] = (string)$k['waehrung'];
    }
    $roh = yahooKennzahlenRoh($symbol, 86400);
    if ($roh !== null) {
        $ziel = yRoh($roh, 'financialData', 'targetMeanPrice');
        $preis = yRoh($roh, 'financialData', 'currentPrice') ?? $z['kurs'];
        if ($ziel !== null && $preis) {
            $z['potenzial'] = round(($ziel / $preis - 1) * 100, 1);
        }
    }
    $asx = beobAsxKuerzel($symbol, $strategien);
    if ($asx !== '') {
        $a = asicShortDaten()[$asx] ?? null;
        if ($a !== null) {
            [$z['short_quote'], $positionen, $z['short_stand']] = $a;
            $z['short_quelle'] = 'ASIC';
            $ax = yahooKennzahlenRoh($asx . '.AX', 86400);
            $volumen = $ax !== null ? (yRoh($ax, 'summaryDetail', 'averageVolume') ?? yRoh($ax, 'summaryDetail', 'averageVolume10days')) : null;
            if ($volumen) {
                $z['days_to_cover'] = round($positionen / $volumen, 1);
            }
        }
    } elseif ($roh !== null) {
        // US-Werte: Short-Interest laut Yahoo (zweimal im Monat gemeldet)
        $q = yRoh($roh, 'defaultKeyStatistics', 'shortPercentOfFloat');
        if ($q !== null) {
            $z['short_quote'] = round($q * 100, 2);
            $z['short_quelle'] = 'Short Interest (Streubesitz)';
            $z['days_to_cover'] = yRoh($roh, 'defaultKeyStatistics', 'shortRatio');
        }
    }
    return $z;
}

/** Status eines Signals: 'gruen' | 'rot' | 'offen', mit Begründung. */
function beobSignalStatus(array $sig, array $zahlen, array $stand): array
{
    if ($sig['art'] === 'zahl') {
        $wert = $zahlen[$sig['quelle']] ?? null;
        if ($wert === null) {
            return ['status' => 'offen', 'text' => 'Kein Wert verfügbar.'];
        }
        $trifft = static fn(?array $s): bool => $s !== null && ($s[0] === '<' ? $wert < $s[1] : $wert > $s[1]);
        $einheit = FZ_BEOB_QUELLEN[$sig['quelle']][1];
        $text = zahl((float)$wert, 1) . ($einheit !== '' ? ' ' . $einheit : '');
        return ['status' => $trifft($sig['gruen']) ? 'gruen' : ($trifft($sig['rot']) ? 'rot' : 'offen'), 'text' => $text];
    }
    $s = (array)($stand[$sig['id']] ?? []);
    $status = in_array($s['status'] ?? '', ['gruen', 'rot'], true) ? $s['status'] : 'offen';
    if ($status !== 'gruen' && ($sig['frist'] ?? '') !== '' && $sig['frist'] < date('Y-m-d')) {
        return ['status' => 'rot', 'text' => 'Frist ' . datum((int)strtotime($sig['frist'])) . ' abgelaufen, ohne dass es eingetreten ist.'] + $s;
    }
    return ['status' => $status, 'text' => (string)($s['begruendung'] ?? '')] + $s;
}

function beobAmpel(array $stati): string
{
    $g = count(array_filter($stati, static fn(array $s): bool => $s['status'] === 'gruen'));
    $r = count(array_filter($stati, static fn(array $s): bool => $s['status'] === 'rot'));
    return $g > $r ? 'gruen' : ($r > $g ? 'rot' : 'gelb');
}

// ---------------------------------------------------------------------------
// Wöchentliche KI-Prüfung der Ereignisse
// ---------------------------------------------------------------------------

function beobKiPruefen(array $d, string $symbol): array
{
    $f = beobDaten($d)['firmen'][$symbol] ?? null;
    $firma = $d['firmen'][$symbol] ?? null;
    if ($f === null || $firma === null) {
        return ['ok' => false, 'fehler' => 'Firma nicht in der Beobachtungsliste.'];
    }
    $zeilen = [];
    foreach ($f['strategien'] as $st) {
        foreach ($st['signale'] as $sig) {
            if ($sig['art'] !== 'ereignis') {
                continue;
            }
            $alt = (array)($f['stand'][$sig['id']] ?? []);
            $zeilen[] = '- id "' . $sig['id'] . '": ' . $sig['titel']
                . ($sig['gruen'] !== '' ? ' | GRÜN, wenn: ' . $sig['gruen'] : '') . ($sig['rot'] !== '' ? ' | ROT, wenn: ' . $sig['rot'] : '')
                . ($sig['frist'] !== '' ? ' | erwartet bis ' . $sig['frist'] : '')
                . (!empty($alt['status']) ? ' | bisher: ' . $alt['status'] . (!empty($alt['datum']) ? ' (' . $alt['datum'] . ')' : '') : '');
        }
    }
    if ($zeilen === []) {
        return ['ok' => true, 'signale' => [], 'lage' => '', 'quellen' => []];
    }
    $z = (array)(end($f['zahlen']) ?: []);
    $system = 'Du bist ein nüchterner Aktien-Rechercheur. Du prüfst gezielt, ob bestimmte Ereignisse eingetreten sind. '
        . 'Nutze vorrangig Primärquellen: Börsenmitteilungen (z. B. ASX-Announcements), Investor-Relations-Seiten, Behörden, seriöse Finanzmedien. '
        . 'Erfinde nichts: Was du nicht belegen kannst, bleibt "offen". Antworte auf Deutsch.';
    $auftrag = 'Firma: ' . $firma['name'] . ' (' . $symbol . ($firma['isin'] !== '' ? ', ISIN ' . $firma['isin'] : '') . '). Heute ist der ' . date('d.m.Y') . ".\n"
        . ($z !== [] ? 'Aktuelle Zahlen: Kurs ' . ($z['kurs'] ?? '–') . ' ' . ($z['waehrung'] ?? '') . ($z['short_quote'] !== null ? ', Short-Quote ' . $z['short_quote'] . ' % (' . $z['short_quelle'] . ', Stand ' . $z['short_stand'] . ')' : '') . "\n" : '')
        . "Prüfe für jedes dieser Signale, ob es inzwischen eingetreten ist (grün oder rot) oder noch offen ist:\n" . implode("\n", $zeilen) . "\n\n"
        . 'Antworte mit einem JSON-Block in ```json … ``` genau so: {"signale":{"<id>":{"status":"gruen|rot|offen","datum":"JJJJ-MM-TT oder leer","quelle":"URL oder leer","begruendung":"ein Satz"}},'
        . '"lage":"2–3 Sätze zur aktuellen Lage der These","neu":["wichtige neue Meldungen der letzten 7 Tage, je ein kurzer Satz"]}';
    $erg = kiLauf($system, $auftrag, FZ_BEOB_MODELL, 5);
    if (!$erg['ok']) {
        return $erg;
    }
    $j = beobJsonAus($erg['text']);
    if ($j === null) {
        return ['ok' => false, 'fehler' => 'Die KI-Antwort war nicht auswertbar.'];
    }
    $signale = [];
    foreach ((array)($j['signale'] ?? []) as $id => $s) {
        if (!is_array($s)) {
            continue;
        }
        $signale[(string)$id] = ['status' => in_array($s['status'] ?? '', ['gruen', 'rot', 'offen'], true) ? $s['status'] : 'offen',
            'datum' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($s['datum'] ?? '')) ? $s['datum'] : '',
            'quelle' => preg_match('~^https?://~', (string)($s['quelle'] ?? '')) ? mb_substr((string)$s['quelle'], 0, 400) : '',
            'begruendung' => mb_substr(trim((string)($s['begruendung'] ?? '')), 0, 400), 'zeit' => time()];
    }
    return ['ok' => true, 'signale' => $signale, 'lage' => mb_substr(trim((string)($j['lage'] ?? '')), 0, 800),
        'neu' => array_slice(array_map(static fn($x): string => mb_substr(trim((string)$x), 0, 300), (array)($j['neu'] ?? [])), 0, 6), 'quellen' => $erg['quellen']];
}

/** KI-Ergebnis speichern. */
function beobKiSpeichern(string $symbol, array $erg): void
{
    datenAendern(function (array &$d) use ($symbol, $erg): void {
        if (!isset($d['beobachtung']['firmen'][$symbol])) {
            return;
        }
        $f = &$d['beobachtung']['firmen'][$symbol];
        foreach ($erg['signale'] as $id => $s) {
            $alt = (array)($f['stand'][$id] ?? []);
            // eingetretene Signale nicht wieder auf „offen“ zurückfallen lassen
            if ($s['status'] === 'offen' && in_array($alt['status'] ?? '', ['gruen', 'rot'], true)) {
                continue;
            }
            $s['neu'] = ($alt['status'] ?? 'offen') !== $s['status'];
            $f['stand'][$id] = $s;
        }
        $f['ki'] = ['zeit' => time(), 'lage' => $erg['lage'], 'neu' => $erg['neu'] ?? [], 'quellen' => array_slice((array)$erg['quellen'], 0, 8)];
        unset($f);
    });
}

// ---------------------------------------------------------------------------
// Ablauf im Cron: täglich Zahlen, samstags 18 Uhr Bericht
// ---------------------------------------------------------------------------

/** Letzter fälliger Berichtszeitpunkt: Samstag 18:00 (deutsche Zeit). */
function beobBerichtFaellig(): int
{
    $t = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    $sa = $t->modify('saturday this week')->setTime(18, 0);
    if ($sa > $t) {
        $sa = $sa->modify('-7 days');
    }
    return $sa->getTimestamp();
}

function beobNaechsterBericht(): int
{
    return beobBerichtFaellig() + 7 * 86400;
}

/** Zahlen eines Tages holen und Schwellen prüfen; bei neu erreichten Schwellen Sofort-Alarm. */
function beobZahlenTag(array $d, string $symbol): array
{
    $f = beobDaten($d)['firmen'][$symbol];
    $z = beobZahlen($symbol, $f['strategien']);
    $alarme = [];
    foreach ($f['strategien'] as $st) {
        foreach ($st['signale'] as $sig) {
            if ($sig['art'] !== 'zahl') {
                continue;
            }
            $neu = beobSignalStatus($sig, $z, [])['status'];
            $erstmals = !isset($f['zahl_stand'][$sig['id']]);
            $alt = (string)($f['zahl_stand'][$sig['id']] ?? 'offen');
            if (!$erstmals && $neu !== $alt && $neu !== 'offen') {
                $alarme[] = ($neu === 'gruen' ? '🟢 ' : '🔴 ') . $sig['titel'] . ': ' . zahl((float)$z[$sig['quelle']], 1) . ' ' . FZ_BEOB_QUELLEN[$sig['quelle']][1]
                    . ' – Schwelle ' . ($neu === 'gruen' ? $sig['gruen'][0] . ' ' . zahl($sig['gruen'][1], 1) : $sig['rot'][0] . ' ' . zahl($sig['rot'][1], 1)) . ' erreicht';
            }
            $f['zahl_stand'][$sig['id']] = $neu;
        }
    }
    datenAendern(function (array &$d) use ($symbol, $z, $f): void {
        if (!isset($d['beobachtung']['firmen'][$symbol])) {
            return;
        }
        $x = &$d['beobachtung']['firmen'][$symbol];
        $verlauf = array_values(array_filter((array)($x['zahlen'] ?? []), static fn(array $e): bool => $e['datum'] !== $z['datum']));
        $verlauf[] = $z;
        $x['zahlen'] = array_slice($verlauf, -120);
        $x['zahl_stand'] = $f['zahl_stand'] ?? [];
        unset($x);
    });
    if ($alarme !== []) {
        $name = (string)($d['firmen'][$symbol]['name'] ?? $symbol);
        benachrichtigen($d, 'Beobachtung ' . $name, implode("\n", $alarme), fzBasisUrl() . '?seite=beobachtung');
    }
    return $alarme;
}

/** Im Cron aufgerufen. $budget: verfügbare Sekunden. */
function beobAusfuehren(float $budget): array
{
    $start = microtime(true);
    $d = datenLaden();
    $b = beobDaten($d);
    if ($b['firmen'] === []) {
        return ['beobachtung' => 'leer'];
    }
    $erg = ['zahlen' => 0, 'ki' => [], 'bericht' => false];
    $heute = date('Y-m-d');
    foreach ($b['firmen'] as $s => $f) {
        $letzte = end($f['zahlen']);
        if (!is_array($letzte) || $letzte['datum'] !== $heute) {
            beobZahlenTag(datenLaden(), (string)$s);
            $erg['zahlen']++;
        }
    }
    $faellig = beobBerichtFaellig();
    if ((int)($b['bericht_zeit'] ?? 0) >= $faellig) {
        return ['beobachtung' => $erg];
    }
    // Wochenprüfung: je Firma einmal die KI (ein Lauf dauert bis zu einer Minute)
    foreach ($b['firmen'] as $s => $f) {
        if ((int)($f['ki']['zeit'] ?? 0) >= $faellig - 3600) {
            continue;
        }
        if (microtime(true) - $start > $budget - 90) {
            return ['beobachtung' => $erg + ['warten' => 'Zeit reicht nicht – nächster Lauf']];
        }
        $k = beobKiPruefen(datenLaden(), (string)$s);
        if ($k['ok']) {
            beobKiSpeichern((string)$s, $k);
        } else {
            // nicht endlos versuchen: als geprüft markieren, Fehler im Bericht nennen
            beobKiSpeichern((string)$s, ['signale' => [], 'lage' => 'Die KI-Prüfung ist fehlgeschlagen: ' . $k['fehler'], 'neu' => [], 'quellen' => []]);
        }
        $erg['ki'][] = (string)$s;
    }
    $erg['bericht'] = beobBerichtSenden(datenLaden());
    return ['beobachtung' => $erg];
}

/** Wochenbericht als Text. */
function beobBerichtText(array $d): array
{
    $b = beobDaten($d);
    $teile = [];
    $kurz = [];
    foreach ($b['firmen'] as $s => $f) {
        $name = (string)($d['firmen'][$s]['name'] ?? $s);
        $z = (array)(end($f['zahlen']) ?: []);
        $vorwoche = null;
        foreach (array_reverse((array)$f['zahlen']) as $e) {
            if (($e['datum'] ?? '') <= date('Y-m-d', strtotime('-6 days')) && $e['short_quote'] !== null) {
                $vorwoche = $e;
                break;
            }
        }
        $stati = [];
        $zeilen = [];
        $fristen = [];
        foreach ($f['strategien'] as $st) {
            $zeilen[] = '» ' . $st['titel'];
            foreach ($st['signale'] as $sig) {
                $x = beobSignalStatus($sig, $z, (array)($f['stand'] ?? []));
                $stati[] = $x;
                $zeichen = ['gruen' => '🟢', 'rot' => '🔴', 'offen' => '⚪'][$x['status']];
                $zeilen[] = '  ' . $zeichen . ' ' . $sig['titel'] . (!empty($x['neu']) ? ' [NEU]' : '') . ($x['text'] !== '' ? ' – ' . $x['text'] : '')
                    . (!empty($x['quelle']) ? ' (' . $x['quelle'] . ')' : '');
                if ($sig['art'] === 'ereignis' && $sig['frist'] !== '' && $x['status'] === 'offen' && $sig['frist'] >= date('Y-m-d')) {
                    $wochen = (int)ceil((strtotime($sig['frist']) - time()) / (7 * 86400));
                    $fristen[] = '  ⏳ ' . $sig['titel'] . ': erwartet bis ' . datum((int)strtotime($sig['frist'])) . ' (noch ' . $wochen . ' Wochen)';
                }
            }
        }
        $ampel = beobAmpel($stati);
        $g = count(array_filter($stati, static fn($x) => $x['status'] === 'gruen'));
        $r = count(array_filter($stati, static fn($x) => $x['status'] === 'rot'));
        $zahlen = [];
        if (($z['kurs'] ?? null) !== null) {
            $zahlen[] = 'Kurs ' . geld($z['kurs'], (string)$z['waehrung']);
        }
        if (($z['short_quote'] ?? null) !== null) {
            $zahlen[] = 'Short-Quote ' . zahl($z['short_quote'], 2) . ' % (' . $z['short_quelle'] . ($z['short_stand'] !== '' ? ', Stand ' . datum((int)strtotime($z['short_stand'])) : '') . ')'
                . ($vorwoche !== null ? ', Vorwoche ' . zahl($vorwoche['short_quote'], 2) . ' % ' . ($z['short_quote'] > $vorwoche['short_quote'] ? '↑' : ($z['short_quote'] < $vorwoche['short_quote'] ? '↓' : '→')) : '');
        }
        if (($z['days_to_cover'] ?? null) !== null) {
            $zahlen[] = 'Days to Cover ' . zahl($z['days_to_cover'], 1);
        }
        $text = $name . ' – ' . ['gruen' => '🟢 These hält (eher für dich)', 'rot' => '🔴 eher für die Gegenseite', 'gelb' => '🟡 offen'][$ampel]
            . ' (' . $g . ' grün, ' . $r . ' rot, ' . (count($stati) - $g - $r) . ' offen)' . "\n"
            . ($zahlen !== [] ? implode(' · ', $zahlen) . "\n" : '')
            . (!empty($f['ki']['lage']) ? "\nLage: " . $f['ki']['lage'] . "\n" : '')
            . (!empty($f['ki']['neu']) ? "\nNeu diese Woche:\n" . implode("\n", array_map(static fn($x) => '  • ' . $x, $f['ki']['neu'])) . "\n" : '')
            . ($fristen !== [] ? "\nAnstehende Fristen:\n" . implode("\n", $fristen) . "\n" : '')
            . "\nSignale:\n" . implode("\n", $zeilen);
        $teile[] = $text;
        $kurz[] = $name . ' ' . ['gruen' => '🟢', 'rot' => '🔴', 'gelb' => '🟡'][$ampel];
    }
    return ['text' => implode("\n\n――――――――――\n\n", $teile), 'kurz' => implode(' · ', $kurz)];
}

/** Bericht per E-Mail schicken und im Verlauf ablegen. */
function beobBerichtSenden(array $d, bool $test = false): bool
{
    $bericht = beobBerichtText($d);
    if ($bericht['text'] === '') {
        return false;
    }
    $betreff = 'Beobachtungsliste – Wochenbericht ' . date('d.m.Y') . ($bericht['kurz'] !== '' ? ': ' . $bericht['kurz'] : '');
    $mail = einstellung($d, 'email');
    $ok = $mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL)
        && mailSenden($mail, $betreff, $bericht['text'] . "\n\nAlle Details: " . fzBasisUrl() . '?seite=beobachtung');
    datenAendern(function (array &$d) use ($bericht, $ok, $test): void {
        $b = (array)($d['beobachtung'] ?? []);
        $liste = (array)($b['berichte'] ?? []);
        array_unshift($liste, ['zeit' => time(), 'text' => $bericht['text'], 'kurz' => $bericht['kurz'], 'gesendet' => $ok, 'test' => $test]);
        $b['berichte'] = array_slice($liste, 0, 26);
        if (!$test) {
            $b['bericht_zeit'] = time();
            // „neu“-Markierungen gelten nur für einen Bericht
            foreach ((array)($b['firmen'] ?? []) as $s => $f) {
                foreach ((array)($f['stand'] ?? []) as $id => $st) {
                    $b['firmen'][$s]['stand'][$id]['neu'] = false;
                }
            }
        }
        $d['beobachtung'] = $b;
    });
    return $ok;
}

// ---------------------------------------------------------------------------
// Tagescheck (z. B. Vulcan): ASX-Mitteilungen, Morgen- und Nachmittags-Check,
// Short-Trend – Meldung per Push und E-Mail nur bei einem Signal
// ---------------------------------------------------------------------------

const FZ_TAGESCHECK_STANDARD = ['an' => false, 'asx' => '', 'eu' => '', 'faktor' => 2.5, 'plus' => 4.0, 'plus_eu' => 3.0,
    'stichworte' => 'Financial Close, EIB, Kredit, Loan, Debt, Placement, Platzierung, Capital Raising, Kapitalerhöhung, Entitlement, Substantial Holding, Stellantis, LEOP, CLEOP, Trading Halt'];

function beobTagescheck(array $f): array
{
    return array_merge(FZ_TAGESCHECK_STANDARD, (array)($f['tagescheck'] ?? []));
}

/** Yahoo-Chart: Zeitpunkte, Schlusskurse, Volumen. */
function beobChart(string $symbol, string $spanne, string $intervall): ?array
{
    $r = http('https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($symbol) . '?range=' . $spanne . '&interval=' . $intervall . '&includePrePost=false', ['ua' => 'browser', 'zeit' => 15]);
    $j = httpJson($r);
    $res = $j['chart']['result'][0] ?? null;
    if (!is_array($res) || empty($res['timestamp'])) {
        return null;
    }
    $q = $res['indicators']['quote'][0] ?? [];
    $erg = ['t' => [], 'c' => [], 'v' => [], 'w' => (string)($res['meta']['currency'] ?? ''), 'tz' => (string)($res['meta']['exchangeTimezoneName'] ?? 'UTC')];
    foreach ($res['timestamp'] as $i => $ts) {
        if (is_numeric($q['close'][$i] ?? null)) {
            $erg['t'][] = (int)$ts;
            $erg['c'][] = (float)$q['close'][$i];
            $erg['v'][] = (float)($q['volume'][$i] ?? 0);
        }
    }
    return $erg['t'] === [] ? null : $erg;
}

/** Offizielle Mitteilungen an der ASX (neueste zuerst). */
function asxMitteilungen(string $kuerzel): array
{
    $j = httpJson(http('https://asx.api.markitdigital.com/asx-research/1.0/companies/' . rawurlencode(strtolower($kuerzel)) . '/announcements?count=10&market_sensitive=false', ['ua' => 'browser', 'zeit' => 15]));
    $liste = [];
    foreach ((array)($j['data']['items'] ?? []) as $x) {
        if (!empty($x['headline']) && !empty($x['date'])) {
            $liste[] = ['schluessel' => (string)($x['documentKey'] ?? md5($x['headline'] . $x['date'])), 'titel' => (string)$x['headline'],
                'zeit' => (int)strtotime((string)$x['date']), 'kursrelevant' => !empty($x['isPriceSensitive']), 'art' => (string)($x['announcementType'] ?? '')];
        }
    }
    return $liste;
}

function beobStichwort(string $text, string $liste): string
{
    foreach (array_filter(array_map('trim', explode(',', $liste))) as $w) {
        if (mb_stripos($text, $w) !== false) {
            return $w;
        }
    }
    return '';
}

/** Morgen-Check: ASX-Handelstag – Volumen gegenüber Durchschnitt, Kurs, Mitteilungen des Tages. */
function tagescheckMorgen(array $tc, array $mitteilungen): ?array
{
    $c = beobChart($tc['asx'], '1mo', '1d');
    if ($c === null || count($c['t']) < 3) {
        return null;
    }
    $n = count($c['t']) - 1;
    $tz = new DateTimeZone($c['tz']);
    $tag = (new DateTimeImmutable('@' . $c['t'][$n]))->setTimezone($tz)->format('Y-m-d');
    $heuteDort = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    $roh = yahooKennzahlenRoh($tc['asx'], 86400);
    $schnitt = $roh !== null ? (yRoh($roh, 'summaryDetail', 'averageVolume') ?? 0.0) : 0.0;
    if ($schnitt <= 0) {
        $schnitt = array_sum(array_slice($c['v'], 0, $n)) / max(1, $n);
    }
    $aend = ($c['c'][$n] / $c['c'][$n - 1] - 1) * 100;
    $faktor = $schnitt > 0 ? $c['v'][$n] / $schnitt : 0;
    $heutige = array_values(array_filter($mitteilungen, static fn(array $m): bool => $m['zeit'] > $c['t'][$n] - 20 * 3600));
    $kapital = array_filter($heutige, static fn(array $m): bool => (bool)preg_match('/placement|capital raising|entitlement|issue of|kapitalerh/i', $m['titel']));
    $art = 'ruhig';
    if ($tag !== $heuteDort) {
        $art = 'kein Handel';
    } elseif ($faktor >= $tc['faktor'] && $aend >= $tc['plus']) {
        $art = $kapital !== [] ? 'meldung' : 'squeeze';
    } elseif ($faktor >= $tc['faktor'] && $aend <= -$tc['plus']) {
        $art = 'verkauf';
    } elseif ($faktor >= $tc['faktor']) {
        $art = 'volumen';
    }
    return ['datum' => $tag, 'kurs' => $c['c'][$n], 'w' => $c['w'], 'aend' => round($aend, 1), 'volumen' => $c['v'][$n], 'schnitt' => round($schnitt), 'faktor' => round($faktor, 1),
        'art' => $art, 'mitteilungen' => array_map(static fn(array $m): string => $m['titel'], $heutige)];
}

/** Nachmittags-Check: deutscher Handel seit 15:30 und gegenüber dem umgerechneten ASX-Schluss. */
function tagescheckNachmittag(array $tc): ?array
{
    $c = beobChart($tc['eu'], '1d', '5m');
    if ($c === null || count($c['t']) < 6) {
        return null;
    }
    // 15:30 Uhr am Tag der letzten Kurse (von Hand nachts gerechnet: der Vortag)
    $tag = (new DateTimeImmutable('@' . end($c['t'])))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d');
    $grenze = (new DateTimeImmutable($tag . ' 15:30', new DateTimeZone('Europe/Berlin')))->getTimestamp();
    $vor = $nach = 0.0;
    $nVor = $nNach = 0;
    $ref = null;
    foreach ($c['t'] as $i => $ts) {
        if ($ts < $grenze) {
            $vor += $c['v'][$i];
            $nVor++;
            $ref = $c['c'][$i];
        } else {
            $nach += $c['v'][$i];
            $nNach++;
        }
    }
    $jetzt = (float)end($c['c']);
    $seit = $ref ? ($jetzt / $ref - 1) * 100 : null;
    $tempo = $nVor > 0 && $nNach > 0 && $vor > 0 ? ($nach / $nNach) / ($vor / $nVor) : null;
    // Aufschlag gegenüber dem ASX-Schluss in Euro
    $aufschlag = null;
    $asx = beobChart($tc['asx'], '5d', '1d');
    $fx = wechselkurse(['AUD'])['AUD'] ?? null;
    if ($asx !== null && $fx) {
        $aufschlag = round(($jetzt / ((float)end($asx['c']) / $fx) - 1) * 100, 1);
    }
    $signal = $seit !== null && $seit >= $tc['plus_eu'] && ($tempo ?? 0) >= 2;
    return ['kurs' => $jetzt, 'w' => $c['w'], 'seit_1530' => $seit !== null ? round($seit, 1) : null, 'tempo' => $tempo !== null ? round($tempo, 1) : null,
        'volumen' => $vor + $nach, 'aufschlag' => $aufschlag, 'art' => $signal ? 'anstieg' : ($seit !== null && $seit <= -$tc['plus_eu'] && ($tempo ?? 0) >= 2 ? 'abgabe' : 'ruhig')];
}

/** Bei jedem Cron-Lauf: Mitteilungen, und zur passenden Uhrzeit Morgen- bzw. Nachmittags-Check. */
function tagescheckAusfuehren(): array
{
    $d = datenLaden();
    $jetzt = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    $heute = $jetzt->format('Y-m-d');
    $werktag = (int)$jetzt->format('N') <= 5;
    $uhr = (int)$jetzt->format('Hi');
    $erg = [];
    foreach (beobDaten($d)['firmen'] as $s => $f) {
        $tc = beobTagescheck($f);
        if (!$tc['an'] || $tc['asx'] === '') {
            continue;
        }
        $name = (string)($d['firmen'][$s]['name'] ?? $s);
        $st = (array)($f['tc'] ?? []);
        $meldungen = [];
        $kuerzel = preg_replace('/\.AX$/', '', $tc['asx']);
        $mitteilungen = asxMitteilungen((string)$kuerzel);
        // 1. neue ASX-Mitteilungen (kursrelevant oder mit Stichwort)
        $gesehen = (array)($st['gesehen'] ?? []);
        $erstmals = $gesehen === [];
        foreach (array_reverse($mitteilungen) as $m) {
            if (in_array($m['schluessel'], $gesehen, true)) {
                continue;
            }
            $gesehen[] = $m['schluessel'];
            $wort = beobStichwort($m['titel'], $tc['stichworte']);
            if (!$erstmals && ($m['kursrelevant'] || $wort !== '')) {
                $meldungen[] = '📰 ASX-Mitteilung' . ($m['kursrelevant'] ? ' (kursrelevant)' : '') . ': „' . $m['titel'] . '“' . ($wort !== '' ? ' – Stichwort ' . $wort : '')
                    . ' · ' . date('d.m. H:i', $m['zeit']) . ' Uhr';
            }
        }
        $st['gesehen'] = array_slice($gesehen, -60);
        $st['mitteilungen'] = array_slice($mitteilungen, 0, 5);
        // 2. Morgen-Check nach ASX-Schluss
        if ($werktag && $uhr >= 715 && $uhr < 1300 && ($st['morgen']['geprueft'] ?? '') !== $heute) {
            $m = tagescheckMorgen($tc, $mitteilungen);
            // Yahoo hat den Handelstag manchmal erst etwas später – bis 10 Uhr erneut versuchen
            if ($m !== null && ($m['art'] !== 'kein Handel' || $uhr >= 1000)) {
                $st['morgen'] = $m + ['geprueft' => $heute, 'zeit' => time()];
                $st['verlauf'] = tagescheckVerlauf($st, 'morgen', $st['morgen']);
                $txt = 'ASX ' . date('d.m.', strtotime($m['datum'])) . ': Kurs ' . zahl($m['kurs'], 3) . ' ' . $m['w'] . ' (' . ($m['aend'] > 0 ? '+' : '') . zahl($m['aend'], 1) . ' %), Volumen '
                    . zahl($m['volumen'] / 1e6, 2) . ' Mio. = ' . zahl($m['faktor'], 1) . '× Ø (' . zahl($m['schnitt'] / 1e6, 2) . ' Mio.)';
                $titel = ['squeeze' => '🔥 Squeeze-Verdacht', 'verkauf' => '⚠️ Starker Verkaufsdruck', 'meldung' => '📈 Hohes Volumen wegen Kapitalmaßnahme', 'volumen' => '👀 Ungewöhnlich hohes Volumen'][$m['art']] ?? '';
                if ($titel !== '') {
                    $meldungen[] = $titel . ' – ' . $txt . ($m['mitteilungen'] !== [] ? "\nMitteilungen: " . implode(' · ', $m['mitteilungen']) : "\nKeine ASX-Mitteilung dazu.")
                        . ($m['art'] === 'squeeze' ? "\nOb Shortseller wirklich eindecken, zeigt die ASIC-Quote erst in 4 Handelstagen." : '');
                }
            }
        }
        // 3. Nachmittags-Check nach US-Eröffnung
        if ($werktag && $tc['eu'] !== '' && $uhr >= 1600 && $uhr < 1830 && ($st['nachmittag']['geprueft'] ?? '') !== $heute) {
            $n = tagescheckNachmittag($tc);
            if ($n !== null) {
                $st['nachmittag'] = $n + ['geprueft' => $heute, 'zeit' => time()];
                $st['verlauf'] = tagescheckVerlauf($st, 'nachmittag', $st['nachmittag']);
                if ($n['art'] !== 'ruhig') {
                    $meldungen[] = ($n['art'] === 'anstieg' ? '🚀 Europa zieht seit US-Eröffnung an' : '📉 Europa gibt seit US-Eröffnung nach') . ' – ' . $tc['eu'] . ' ' . zahl($n['kurs'], 3) . ' ' . $n['w']
                        . ', seit 15:30 ' . ($n['seit_1530'] > 0 ? '+' : '') . zahl($n['seit_1530'], 1) . ' %, Volumen-Tempo ' . zahl($n['tempo'], 1) . '× gegenüber vormittags'
                        . ($n['aufschlag'] !== null ? ', ' . ($n['aufschlag'] >= 0 ? '+' : '') . zahl($n['aufschlag'], 1) . ' % zum ASX-Schluss' : '');
                }
            }
        }
        // 4. Short-Trend: ±1 Prozentpunkt in zwei Wochen
        $quoten = array_values(array_filter((array)$f['zahlen'], static fn($e) => ($e['short_quote'] ?? null) !== null));
        $letzte = end($quoten);
        if ($letzte && ($st['trend_datum'] ?? '') !== $letzte['short_stand']) {
            foreach ($quoten as $e) {
                if ($e['datum'] >= date('Y-m-d', strtotime('-15 days')) && $e['datum'] <= date('Y-m-d', strtotime('-12 days'))) {
                    $diff = $letzte['short_quote'] - $e['short_quote'];
                    if (abs($diff) >= 1.0) {
                        $meldungen[] = ($diff < 0 ? '🟢 Short-Quote fällt deutlich: ' : '🔴 Short-Quote steigt deutlich: ') . zahl($e['short_quote'], 2) . ' % → ' . zahl($letzte['short_quote'], 2) . ' % in zwei Wochen (ASIC)';
                    }
                    break;
                }
            }
            $st['trend_datum'] = $letzte['short_stand'];
        }
        if ($meldungen !== []) {
            benachrichtigen($d, $name . ': ' . mb_substr(trim(explode(' – ', explode(':', $meldungen[0])[0])[0]), 0, 60), implode("\n\n", $meldungen), fzBasisUrl() . '?seite=beobachtung');
            $st['meldungen'] = array_slice(array_merge(array_map(static fn($x) => ['zeit' => time(), 'text' => $x], $meldungen), (array)($st['meldungen'] ?? [])), 0, 20);
        }
        datenAendern(function (array &$d) use ($s, $st): void {
            if (isset($d['beobachtung']['firmen'][$s])) {
                $d['beobachtung']['firmen'][$s]['tc'] = $st;
            }
        });
        $erg[$s] = count($meldungen);
    }
    return ['tagescheck' => $erg];
}

/** Ergebnis vorne in den Verlauf (neueste zuerst, höchstens 60). */
function tagescheckVerlauf(array $st, string $art, array $erg): array
{
    unset($erg['geprueft']);
    return array_slice(array_merge([['check' => $art] + $erg], (array)($st['verlauf'] ?? [])), 0, 60);
}

/** Von Hand: beide Checks sofort rechnen und anzeigen – ohne Meldung, die geplanten Checks laufen trotzdem. */
function tagescheckJetzt(string $symbol): bool
{
    $d = datenLaden();
    $f = beobDaten($d)['firmen'][$symbol] ?? null;
    if ($f === null) {
        return false;
    }
    $tc = beobTagescheck($f);
    if ($tc['asx'] === '') {
        return false;
    }
    $mitteilungen = asxMitteilungen((string)preg_replace('/\.AX$/', '', $tc['asx']));
    $m = tagescheckMorgen($tc, $mitteilungen);
    $n = $tc['eu'] !== '' ? tagescheckNachmittag($tc) : null;
    datenAendern(function (array &$d) use ($symbol, $m, $n, $mitteilungen): void {
        if (!isset($d['beobachtung']['firmen'][$symbol])) {
            return;
        }
        $st = (array)($d['beobachtung']['firmen'][$symbol]['tc'] ?? []);
        $st['mitteilungen'] = array_slice($mitteilungen, 0, 5);
        // geprueft bleibt, wie es war: der geplante Check meldet sich trotzdem
        if ($m !== null) {
            $st['morgen'] = $m + ['geprueft' => (string)($st['morgen']['geprueft'] ?? ''), 'zeit' => time()];
            $st['verlauf'] = tagescheckVerlauf($st, 'morgen', $st['morgen'] + ['hand' => true]);
        }
        if ($n !== null) {
            $st['nachmittag'] = $n + ['geprueft' => (string)($st['nachmittag']['geprueft'] ?? ''), 'zeit' => time()];
            $st['verlauf'] = tagescheckVerlauf($st, 'nachmittag', $st['nachmittag'] + ['hand' => true]);
        }
        $d['beobachtung']['firmen'][$symbol]['tc'] = $st;
    });
    return $m !== null || $n !== null;
}

/**
 * Vulcan und PureCycle einmalig von selbst in die Beobachtung aufnehmen (mit Vorlage und Checks).
 * Wird eine davon später entfernt, bleibt sie draußen.
 */
function beobVulcanStart(): void
{
    $starts = [
        'vulcan' => ['such' => 'vulcan energy', 'symbol' => 'VUL.AX', 'name' => 'Vulcan Energy Resources Ltd', 'waehrung' => 'AUD'],
        'pct' => ['such' => 'purecycle', 'symbol' => 'PCT', 'name' => 'PureCycle Technologies, Inc.', 'waehrung' => 'USD'],
    ];
    foreach ($starts as $vk => $x) {
        beobAutoStart($vk, $x);
    }
}

function beobAutoStart(string $vk, array $x): void
{
    $d = datenLaden();
    $merker = $vk === 'vulcan' ? 'vulcan_start' : $vk . '_start';
    if (!empty($d['beobachtung'][$merker])) {
        return;
    }
    $symbol = '';
    foreach ($d['firmen'] as $s => $f) {
        if (stripos((string)$f['name'], $x['such']) !== false || strtoupper((string)$s) === $x['symbol']) {
            $symbol = (string)$s;
            if (strtoupper($symbol) === $x['symbol'] || $f['status'] === 'depot') {
                break;
            }
        }
    }
    $v = beobVorlagen()[$vk];
    $neueFirma = $symbol === '';
    $symbol = $neueFirma ? $x['symbol'] : $symbol;
    $neu = beobStrategieNeu($symbol, $v);
    datenAendern(function (array &$d) use ($symbol, $neueFirma, $v, $neu, $merker, $x): void {
        $d['beobachtung'][$merker] = time();
        if ($neueFirma && !isset($d['firmen'][$symbol])) {
            $d['firmen'][$symbol] = firmaNormal(['name' => $x['name'], 'waehrung' => $x['waehrung']], $symbol);
        }
        $f = (array)($d['beobachtung']['firmen'][$symbol] ?? []);
        $hat = false;
        foreach ((array)($f['strategien'] ?? []) as $st) {
            $hat = $hat || ($st['titel'] ?? '') === $neu['titel'];
        }
        if (!$hat) {
            $f['strategien'][] = $neu;
        }
        if (isset($v['tagescheck']) && empty($f['tagescheck']['an'])) {
            $f['tagescheck'] = array_merge(FZ_TAGESCHECK_STANDARD, (array)($f['tagescheck'] ?? []), $v['tagescheck']);
        }
        if (isset($v['zahlencheck']) && empty($f['zahlencheck']['an'])) {
            $f['zahlencheck'] = array_merge(FZ_ZAHLENCHECK_STANDARD, (array)($f['zahlencheck'] ?? []), $v['zahlencheck'], ['seit' => time()]);
        }
        $d['beobachtung']['firmen'][$symbol] = $f;
    });
    // gleich Zahlen und Tagescheck zeigen, nicht erst nach dem nächsten Cron-Lauf
    beobZahlenTag(datenLaden(), $symbol);
    if (isset($v['tagescheck'])) {
        tagescheckJetzt($symbol);
    }
}
