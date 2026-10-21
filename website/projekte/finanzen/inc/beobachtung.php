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
