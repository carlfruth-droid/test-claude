<?php
declare(strict_types=1);

/*
 * Depot-Verlauf: Wert aller (oder ausgewählter) Titel über einen frei
 * wählbaren Zeitraum – rekonstruiert aus den importierten Buchungen und
 * historischen Tageskursen (Yahoo). Werte in Euro.
 */

/**
 * Alle Titel mit ihren Bestandsbausteinen:
 * [id => ['name', 'symbol', 'art', 'quellen' => [...], 'tr' => [Ereignisse], 'et' => [Segmente]]]
 */
function verlaufTitel(array $d): array
{
    $titel = [];
    $tr = $d['broker']['traderepublic'] ?? null;
    foreach ((array)($tr['verlauf'] ?? []) as $schluessel => $v) {
        $p = $tr['positionen'][$schluessel] ?? null;
        $symbol = (string)($p['symbol'] ?? '');
        if ($p === null || $symbol === '' || empty($v['e'])) {
            continue;
        }
        $t = &$titel[$symbol];
        $t ??= ['name' => (string)$p['name'], 'symbol' => $symbol, 'art' => (string)($p['art'] ?? ''), 'quellen' => [], 'tr' => [], 'et' => []];
        $t['quellen']['traderepublic'] = true;
        $t['tr'][] = $v;
        unset($t);
    }
    $ea = $d['broker']['etoro_auszug'] ?? null;
    if ($ea !== null && empty($d['broker']['etoro']['positionen'])) {
        $namen = [];
        foreach ((array)($ea['positionen'] ?? []) as $g) {
            $namen[$g['kuerzel']] ??= ['name' => $g['name'], 'art' => $g['art']];
        }
        foreach ((array)($ea['segmente'] ?? []) as $s) {
            $symbol = (string)($ea['symbole'][$s['kuerzel']]['symbol'] ?? '');
            if ($symbol === '') {
                continue;
            }
            $t = &$titel[$symbol];
            $t ??= ['name' => (string)($namen[$s['kuerzel']]['name'] ?? ($ea['symbole'][$s['kuerzel']]['name'] ?? '') ?: $s['kuerzel']),
                'symbol' => $symbol, 'art' => (string)($namen[$s['kuerzel']]['art'] ?? ''), 'quellen' => [], 'tr' => [], 'et' => []];
            $t['quellen']['etoro'] = true;
            $t['et'][] = $s;
            unset($t);
        }
    }
    return $titel;
}

/** Rasterpunkte (Tagesende) von–bis; bei langen Zeiträumen wöchentlich, der letzte Tag immer dabei. */
function verlaufRaster(string $von, string $bis): array
{
    $a = new DateTimeImmutable($von . ' 23:59:59');
    $b = new DateTimeImmutable($bis . ' 23:59:59');
    $tage = (int)$a->diff($b)->days;
    $schritt = $tage > 400 ? 7 : 1;
    $punkte = [];
    for ($t = $b; $t >= $a; $t = $t->modify('-' . $schritt . ' days')) {
        $punkte[] = $t->getTimestamp();
    }
    if (end($punkte) !== $a->getTimestamp()) {
        $punkte[] = $a->getTimestamp();
    }
    return array_reverse($punkte);
}

/** Ist der Titel im Zeitraum (ganz oder teilweise) im Bestand gewesen? */
function titelImZeitraum(array $t, int $von, int $bis): bool
{
    foreach ($t['tr'] as $v) {
        $stueck = 0.0;
        $erster = strtotime($v['e'][0][0] . ' 00:00:00');
        if ($erster > $bis) {
            continue;
        }
        foreach ($v['e'] as $e) {
            if (strtotime($e[0] . ' 23:59:59') > $von) {
                return true; // Bewegung im Zeitraum
            }
            $stueck += (float)$e[1];
        }
        if ($stueck > 1e-6) {
            return true; // vorher gekauft, zu Beginn noch gehalten
        }
    }
    foreach ($t['et'] as $s) {
        if ($s['auf'] <= $bis && ($s['zu'] === 0 || $s['zu'] >= $von)) {
            return true;
        }
    }
    return false;
}

function historieSchluessel(string $symbol): string
{
    return 'hist:' . $symbol;
}

/**
 * Kursverlauf aus dem Zwischenspeicher, wenn er weit genug zurückreicht – auch wenn er
 * schon älter ist: Vergangene Kurse ändern sich nicht, die letzten Tage werden nebenher
 * ergänzt (historienLaden) und der heutige Kurs kommt aus den aktuellen Kursen.
 */
function historieGecacht(string $symbol, int $ab): ?array
{
    $c = cacheLesen(historieSchluessel($symbol), PHP_INT_MAX);
    if ($c === null || !is_array($c['wert']) || (int)($c['wert']['v'] ?? 0) !== 2 || (int)($c['wert']['ab'] ?? PHP_INT_MAX) > $ab) {
        return null;
    }
    return $c['wert'];
}

/** Neue Tageskurse an einen gespeicherten Verlauf anhängen; null bei einem neuen Split (dann ganz neu laden). */
function historieAnhaengen(array $alt, array $neu): ?array
{
    $bekannt = array_map(static fn(array $sp): int => (int)$sp[0], (array)$alt['sp']);
    foreach ((array)$neu['sp'] as $sp) {
        if (!in_array((int)$sp[0], $bekannt, true)) {
            return null; // Yahoo rechnet ältere Kurse nach einem Split um – die gespeicherten passen nicht mehr
        }
    }
    if ($neu['t'] === []) {
        return $alt;
    }
    $abTag = intdiv((int)$neu['t'][0], 86400);
    $t = [];
    $c = [];
    foreach ($alt['t'] as $i => $ts) {
        if (intdiv((int)$ts, 86400) < $abTag) {
            $t[] = (int)$ts;
            $c[] = $alt['c'][$i];
        }
    }
    return ['v' => 2, 'ab' => (int)$alt['ab'], 't' => array_merge($t, $neu['t']), 'c' => array_merge($c, $neu['c']),
        'sp' => $alt['sp'], 'w' => $neu['w'] !== '' ? $neu['w'] : $alt['w']];
}

/** Aktuellen Kurs als jüngsten Punkt anhängen (bzw. den heutigen Tageskurs ersetzen), wenn er neuer ist als der Verlauf. */
function historieMitKurs(array $h, ?array $k): array
{
    if ($k === null || $h['t'] === [] || !isset($k['kurs'], $k['zeit']) || (float)$k['kurs'] <= 0) {
        return $h;
    }
    if ((string)($k['waehrung'] ?? '') !== '' && (string)$h['w'] !== '' && $k['waehrung'] !== $h['w']) {
        return $h; // andere Währung als der Verlauf – lieber nichts mischen
    }
    $letzter = (int)end($h['t']);
    $zeit = (int)$k['zeit'];
    if ($zeit <= $letzter) {
        return $h;
    }
    if (intdiv($zeit, 86400) === intdiv($letzter, 86400)) {
        $h['c'][count($h['c']) - 1] = (float)$k['kurs'];
    } else {
        $h['t'][] = $zeit;
        $h['c'][] = (float)$k['kurs'];
    }
    return $h;
}

/** Zuletzt abgerufene Kurse aus dem Zwischenspeicher, ohne neue Abfrage. */
function kurseAusCache(array $symbole, int $maxAlter): array
{
    $erg = [];
    foreach (array_unique($symbole) as $s) {
        $c = cacheLesen('kurs:' . $s, $maxAlter);
        if ($c !== null && $c['frisch'] && is_array($c['wert']) && ($c['wert']['kurs'] ?? null) !== null) {
            $erg[(string)$s] = $c['wert'];
        }
    }
    return $erg;
}

/** Erster Kauf eines Titels (Zeitstempel). */
function titelBeginn(array $t): int
{
    $erster = PHP_INT_MAX;
    foreach ($t['tr'] as $v) {
        if (!empty($v['e'])) {
            $erster = min($erster, (int)strtotime($v['e'][0][0] . ' 00:00:00'));
        }
    }
    foreach ($t['et'] as $seg) {
        $erster = min($erster, (int)$seg['auf']);
    }
    return $erster === PHP_INT_MAX ? time() : $erster;
}

/** Ab wann Devisen und Vergleichswerte geladen werden: erste Buchung minus Vorlauf (Ø 200 Tage bei „Max“). */
function verlaufBoden(array $d): int
{
    return (int)strtotime(verlaufBeginn($d) . ' 00:00:00') - 330 * 86400;
}

/** Antwort von Yahoo auswerten: Schlusskurse (von Yahoo split-bereinigt) und gemeldete Splits. */
function historieAuswerten(array $j, int $ab): ?array
{
    $res = $j['chart']['result'][0] ?? null;
    if (!is_array($res)) {
        return null;
    }
    $zeiten = (array)($res['timestamp'] ?? []);
    $schluss = (array)($res['indicators']['quote'][0]['close'] ?? []);
    $splits = [];
    foreach ((array)($res['events']['splits'] ?? []) as $sp) {
        if (!empty($sp['numerator']) && !empty($sp['denominator'])) {
            $splits[] = [(int)$sp['date'], (float)$sp['numerator'] / (float)$sp['denominator']];
        }
    }
    usort($splits, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
    $t = [];
    $c = [];
    foreach ($zeiten as $i => $ts) {
        $v = $schluss[$i] ?? null;
        if (is_numeric($v) && (float)$v > 0) {
            $t[] = (int)$ts;
            $c[] = round((float)$v, 6);
        }
    }
    return ['v' => 2, 'ab' => $ab, 't' => $t, 'c' => $c, 'sp' => $splits, 'w' => (string)($res['meta']['currency'] ?? '')];
}

/** Produkt der Split-Faktoren nach dem Zeitpunkt (rechnet bereinigte Kurse auf den damaligen Kurs zurück). */
function splitFaktor(array $splits, int $ts): float
{
    $f = 1.0;
    foreach ($splits as [$sd, $sf]) {
        if ($ts < $sd) {
            $f *= $sf;
        }
    }
    return $f;
}

/**
 * Yahoo meldet gelegentlich falsche Splits (z. B. Naspers „5000:1“). Prüft die
 * gemeldeten Splits gegen die eigenen Kauf-/Verkaufskurse und behält die
 * Kombination, die am besten passt. $handel: [[Zeit, Kurs je Stück in EUR], …]
 */
function splitsPruefen(array $h, array $handel, callable $inEur): array
{
    $alle = (array)($h['sp'] ?? []);
    if ($alle === []) {
        return [];
    }
    // ohne eigene Kurse: nur offensichtlich unsinnige Faktoren verwerfen
    $plausibel = array_values(array_filter($alle, static fn(array $s): bool => $s[1] >= 1 / 50 && $s[1] <= 50));
    $proben = [];
    $idx = 0;
    foreach ($handel as [$ts, $preis]) {
        $k = kursZum($h, $ts, $idx);
        $kEur = $k !== null ? $inEur($k, $ts) : null;
        if ($kEur && $preis > 0) {
            $proben[] = [$ts, $preis, $kEur];
        }
    }
    if ($proben === [] || count($alle) > 8) {
        return $plausibel;
    }
    $bester = $plausibel;
    $besteGuete = INF;
    $n = count($alle);
    for ($maske = 0; $maske < (1 << $n); $maske++) {
        $auswahl = [];
        for ($b = 0; $b < $n; $b++) {
            if ($maske & (1 << $b)) {
                $auswahl[] = $alle[$b];
            }
        }
        $abw = [];
        foreach ($proben as [$ts, $preis, $kEur]) {
            $abw[] = abs(log($preis / ($kEur * splitFaktor($auswahl, $ts))));
        }
        sort($abw);
        $guete = $abw[intdiv(count($abw), 2)] + count($auswahl) * 1e-6; // Median; bei Gleichstand weniger Splits
        if ($auswahl == $plausibel) {
            $guete -= 0.02; // die plausiblen Yahoo-Angaben leicht bevorzugen
        }
        if ($guete < $besteGuete) {
            [$besteGuete, $bester] = [$guete, $auswahl];
        }
    }
    return $bester;
}

/**
 * Lädt fehlende Kursverläufe parallel nach, solange das Zeitbudget reicht, und ergänzt
 * ältere um die letzten Tage (höchstens $auffrischen Sekunden, die ältesten zuerst).
 * $bedarf: [Symbol => [nötig ab, laden ab]] – geladen wird gleich ab „laden ab“ (z. B. ab dem
 * ersten Kauf), damit ein längerer Zeitraum später nicht noch einmal alles laden muss.
 * Rückgabe: Anzahl noch fehlender Verläufe; ältere, aber ausreichende zählen nicht.
 */
function historienLaden(array $bedarf, float $budget, float $auffrischen = 0.0): int
{
    $start = microtime(true);
    $fehlend = [];
    $alt = [];
    foreach ($bedarf as $s => [$noetig, $laden]) {
        $s = (string)$s;
        $c = cacheLesen(historieSchluessel($s), PHP_INT_MAX);
        $h = $c !== null && is_array($c['wert']) && (int)($c['wert']['v'] ?? 0) === 2 ? $c['wert'] : null;
        if ($h === null || (int)$h['ab'] > $noetig) {
            $fehlend[$s] = (int)min($noetig, $laden);
        } elseif (time() - (int)$c['zeit'] > 6 * 3600) {
            $alt[$s] = ['h' => $h, 'zeit' => (int)$c['zeit']];
        }
    }
    $url = static fn(string $s, int $von): string => 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($s)
        . '?period1=' . $von . '&period2=' . (time() + 86400) . '&interval=1d&events=split&includePrePost=false';
    // 1. Fehlende ganz laden – ohne sie lässt sich nichts rechnen
    foreach (array_chunk(array_keys($fehlend), 12) as $teil) {
        if (microtime(true) - $start > $budget) {
            break;
        }
        $anfragen = [];
        foreach ($teil as $s) {
            $anfragen[$s] = ['url' => $url((string)$s, $fehlend[$s]), 'ua' => 'browser', 'zeit' => 20];
        }
        foreach (httpViele($anfragen) as $s => $r) {
            $s = (string)$s;
            $j = $r['code'] === 200 ? json_decode((string)$r['body'], true) : null;
            $h = is_array($j) ? historieAuswerten($j, $fehlend[$s]) : null;
            if ($h === null && in_array($r['code'], [200, 404, 400], true)) {
                $h = ['v' => 2, 'ab' => $fehlend[$s], 't' => [], 'c' => [], 'sp' => [], 'w' => '']; // unbekannt – nicht bei jedem Aufruf erneut fragen
            }
            if ($h !== null) {
                cacheSchreiben(historieSchluessel($s), $h);
                unset($fehlend[$s]);
            }
        }
    }
    // 2. Ältere nur um die letzten Tage ergänzen – die Anzeige wartet darauf nicht
    if ($auffrischen > 0 && $alt !== []) {
        uasort($alt, static fn(array $a, array $b): int => $a['zeit'] <=> $b['zeit']);
        $beginn = microtime(true);
        foreach (array_chunk(array_keys($alt), 12) as $teil) {
            if (microtime(true) - $beginn > $auffrischen) {
                break;
            }
            $anfragen = [];
            foreach ($teil as $s) {
                $h = $alt[$s]['h'];
                $letzter = $h['t'] !== [] ? (int)end($h['t']) : (int)$h['ab'];
                $anfragen[$s] = ['url' => $url((string)$s, max((int)$h['ab'], $letzter - 7 * 86400)), 'ua' => 'browser', 'zeit' => 15];
            }
            foreach (httpViele($anfragen) as $s => $r) {
                $s = (string)$s;
                $h = $alt[$s]['h'];
                $j = $r['code'] === 200 ? json_decode((string)$r['body'], true) : null;
                $neu = is_array($j) ? historieAuswerten($j, (int)$h['ab']) : null;
                if ($neu === null) {
                    if (in_array($r['code'], [200, 404, 400], true)) {
                        cacheSchreiben(historieSchluessel($s), $h); // nichts Neues – erst beim nächsten Durchgang wieder fragen
                    }
                    continue;
                }
                $zusammen = historieAnhaengen($h, $neu);
                if ($zusammen === null) {
                    @unlink(cacheDatei(historieSchluessel($s))); // neuer Split: beim nächsten Mal ganz neu laden
                } else {
                    cacheSchreiben(historieSchluessel($s), $zusammen);
                }
            }
        }
    }
    return count($fehlend);
}

/** Kurs zum Zeitpunkt (letzter Schlusskurs davor; kurz vor dem ersten Kurs der erste). */
function kursZum(array $h, int $ts, int &$i): ?float
{
    $n = count($h['t']);
    if ($n === 0) {
        return null;
    }
    while ($i + 1 < $n && $h['t'][$i + 1] <= $ts) {
        $i++;
    }
    if ($h['t'][$i] <= $ts) {
        return $h['c'][$i];
    }
    return $h['t'][0] - $ts < 14 * 86400 ? $h['c'][0] : null;
}

/** Euro-Umrechnung zum Zeitpunkt: Kurs EUR→Währung aus dem Verlauf von EURxxx=X. */
function fxZum(array $fxVerlaeufe, array &$fxIdx, string $waehrung, int $ts): ?float
{
    $basis = waehrungBasis($waehrung);
    if ($basis === '' || $basis === 'EUR') {
        return 1.0;
    }
    $h = $fxVerlaeufe[$basis] ?? null;
    if ($h === null) {
        return null;
    }
    $fxIdx[$basis] ??= 0;
    return kursZum($h, $ts, $fxIdx[$basis]);
}

function betragInEuro(?float $betrag, string $waehrung, ?float $fx): ?float
{
    if ($betrag === null || $fx === null || $fx <= 0) {
        return null;
    }
    if (in_array($waehrung, ['GBp', 'ZAc', 'ILA'], true)) {
        $betrag /= 100;
    }
    return $betrag / $fx;
}

/**
 * Bereitet den Verlauf vor (Kurse nachladen) und berechnet ihn, sobald alles da ist.
 * ['fertig' => bool, 'offen' => n, 'gesamt' => n] oder ['fertig' => true, 'punkte' => [...], 'titel' => [...]]
 */
/**
 * $vergleich: optional ein Yahoo-Kürzel (Index, ETF, Aktie), dessen Kurs in Euro
 * je Rasterpunkt mitgeliefert wird – für den Vergleich im Chart.
 */
function depotVerlauf(array $d, string $von, string $bis, float $budget = 8.0, int $vorlaufTage = 0, string $vergleich = '', float $auffrischen = 0.0): array
{
    $vonTs = (int)strtotime($von . ' 00:00:00');
    $bisTs = (int)strtotime($bis . ' 23:59:59');
    // Rasterpunkte; davor ein Vorlauf für die gleitenden Durchschnitte (30/100 Tage)
    $raster = verlaufRaster(date('Y-m-d', $vonTs), date('Y-m-d', $bisTs));
    $schritt = count($raster) > 1 ? $raster[1] - $raster[0] : 86400;
    $vorlauf = [];
    for ($k = (int)ceil($vorlaufTage * 86400 / $schritt); $k >= 1; $k--) {
        $vorlauf[] = $raster[0] - $k * $schritt;
    }
    $start = count($vorlauf);
    $raster = array_merge($vorlauf, $raster);
    $ab = $raster[0] - 10 * 86400;
    $vonTs = min($vonTs, $raster[0]);
    // Kursverläufe nur für Titel, die im Zeitraum im Bestand waren
    $titel = array_filter(verlaufTitel($d), static fn(array $t): bool => titelImZeitraum($t, $vonTs, $bisTs));
    $symbole = array_keys($titel);
    // Kursverläufe je Titel gleich ab dem ersten Kauf – dann passt derselbe Verlauf für jeden Zeitraum
    $boden = verlaufBoden($d);
    $bedarf = [];
    foreach ($titel as $s => $t) {
        $erster = titelBeginn($t) - 20 * 86400;
        $bedarf[$s] = [max($ab, $erster), max($boden, $erster)];
    }
    if ($vergleich !== '') {
        $bedarf[$vergleich] = [$ab, min($ab, $boden)];
    }
    $offen = historienLaden($bedarf, $budget, $auffrischen);
    if ($offen > 0) {
        return ['fertig' => false, 'offen' => $offen, 'gesamt' => count($bedarf)];
    }
    // heute: der zuletzt abgerufene Kurs statt des letzten Schlusskurses
    $live = $bisTs >= strtotime('today') ? kurseAusCache(array_keys($bedarf), 6 * 3600) : [];
    $leer = ['v' => 2, 'ab' => $ab, 't' => [], 'c' => [], 'sp' => [], 'w' => ''];
    $hVergleich = $vergleich !== '' ? historieGecacht($vergleich, $ab) : null;
    if ($hVergleich !== null) {
        $hVergleich = historieMitKurs($hVergleich, $live[$vergleich] ?? null);
    }
    // Währungen der Titel und eToro-Einsätze (USD) → Devisenverläufe
    $waehrungen = ['USD' => true];
    $verlaeufe = [];
    foreach ($symbole as $s) {
        $verlaeufe[$s] = historieMitKurs(historieGecacht($s, $bedarf[$s][0]) ?? $leer, $live[$s] ?? null);
        $w = waehrungBasis((string)($verlaeufe[$s]['w'] ?? ''));
        if ($w !== '') {
            $waehrungen[$w] = true;
        }
        foreach ($titel[$s]['et'] as $seg) {
            $waehrungen[waehrungBasis($seg['waehrung'])] = true;
        }
    }
    if ($hVergleich !== null && waehrungBasis((string)$hVergleich['w']) !== '') {
        $waehrungen[waehrungBasis((string)$hVergleich['w'])] = true;
    }
    unset($waehrungen['EUR']);
    $fxBedarf = [];
    foreach (array_keys($waehrungen) as $w) {
        $fxBedarf['EUR' . $w . '=X'] = [$ab, min($ab, $boden)];
    }
    $offenFx = historienLaden($fxBedarf, max(2.0, $budget - 2), $auffrischen > 0 ? 1.0 : 0.0);
    if ($offenFx > 0) {
        return ['fertig' => false, 'offen' => $offenFx, 'gesamt' => count($bedarf) + count($fxBedarf)];
    }
    $liveFx = $bisTs >= strtotime('today') ? kurseAusCache(array_keys($fxBedarf), 6 * 3600) : [];
    $fx = [];
    foreach (array_keys($waehrungen) as $w) {
        $fx[$w] = historieMitKurs(historieGecacht('EUR' . $w . '=X', $ab) ?? $leer, $liveFx['EUR' . $w . '=X'] ?? null);
    }

    $ergebnis = [];
    $sichtVon = $raster[$start];
    foreach ($titel as $s => $t) {
        $h = $verlaeufe[$s];
        $w = (string)$h['w'];
        // Eigene Handelskurse (EUR je Stück) und Käufe/Verkäufe im sichtbaren Zeitraum
        $handel = [];
        $geschaefte = [];
        foreach ($t['tr'] as $v) {
            foreach ($v['e'] as $e) {
                $et = strtotime($e[0] . ' 12:00:00');
                $ist = ($e[3] ?? '') === 'm';
                if (!$ist && (float)$e[1] != 0.0 && (float)$e[2] != 0.0) {
                    $handel[] = [$et, abs((float)$e[2] / (float)$e[1])];
                }
                if ($et >= $sichtVon && $et <= $bisTs && ((float)$e[1] != 0.0 || (float)$e[2] != 0.0)) {
                    $geschaefte[] = [$et, round((float)$e[1], 6), round((float)$e[2], 2), $ist ? 'massnahme' : ((float)$e[1] > 0 ? 'kauf' : 'verkauf'), 'tr'];
                }
            }
        }
        $fxPruef = [];
        foreach ($t['et'] as $seg) {
            $usdAuf = fxZum($fx, $fxPruef, 'USD', (int)$seg['auf']);
            $fxPruef = [];
            // nur offene Positionen: geschlossene führt eToro in Stück und Kurs nach späteren Splits
            if ((int)$seg['zu'] === 0 && (float)$seg['einheiten'] > 0 && $seg['richtung'] > 0) {
                $handel[] = [(int)$seg['auf'], (float)betragInEuro((float)$seg['betrag'] * max(1.0, (float)$seg['hebel']) / (float)$seg['einheiten'], 'USD', $usdAuf)];
            }
            if ((int)$seg['auf'] >= $sichtVon && (int)$seg['auf'] <= $bisTs) {
                $geschaefte[] = [(int)$seg['auf'], round((float)$seg['einheiten'], 6), round((float)betragInEuro((float)$seg['betrag'], 'USD', $usdAuf), 2), 'kauf', 'etoro'];
            }
            if ((int)$seg['zu'] > 0 && (int)$seg['zu'] >= $sichtVon && (int)$seg['zu'] <= $bisTs) {
                $usdZu = fxZum($fx, $fxPruef, 'USD', (int)$seg['zu']);
                $fxPruef = [];
                $geschaefte[] = [(int)$seg['zu'], -round((float)$seg['einheiten'], 6), -round((float)betragInEuro((float)$seg['betrag'] + (float)$seg['gewinn'], 'USD', $usdZu), 2), 'verkauf', 'etoro'];
            }
        }
        usort($handel, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        usort($geschaefte, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        // Kurse auf den damaligen Stand zurückrechnen – nur mit geprüften Splits
        $pruefIdx = [];
        $splits = splitsPruefen($h, $handel, static function (float $k, int $ts) use ($fx, &$pruefIdx, $w): ?float {
            $pruefIdx = [];
            return betragInEuro($k, $w, fxZum($fx, $pruefIdx, $w, $ts));
        });
        if ($splits !== []) {
            foreach ($h['t'] as $i => $ts) {
                $h['c'][$i] *= splitFaktor($splits, $ts);
            }
        }
        $werte = [];
        $fluesse = [];
        $luecke = false;
        $div = 0.0;
        $divPunkte = [];
        $idx = 0;
        $fxIdx = [];
        $vorher = PHP_INT_MIN;
        // Buchungen einmal in Zeitstempel umrechnen; je Rasterpunkt nur die neu hinzugekommenen abarbeiten
        $trListe = [];
        foreach ($t['tr'] as $v) {
            $ev = [];
            foreach ($v['e'] as $e) {
                $ev[] = [(int)strtotime($e[0] . ' 12:00:00'), (float)$e[1], (float)$e[2]];
            }
            $dv = [];
            foreach ($v['d'] as $x) {
                $dv[] = [(int)strtotime($x[0] . ' 12:00:00'), (float)$x[1]];
            }
            usort($dv, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
            $trListe[] = ['e' => $ev, 'd' => $dv, 'ie' => 0, 'id' => 0, 'stueck' => 0.0];
        }
        foreach ($raster as $n => $ts) {
            $kurs = kursZum($h, $ts, $idx);
            $kursEur = betragInEuro($kurs, $w, fxZum($fx, $fxIdx, $w, $ts));
            $usd = fxZum($fx, $fxIdx, 'USD', $ts);
            $wert = 0.0;
            $fluss = 0.0;
            foreach ($trListe as &$tv) {
                while ($tv['ie'] < count($tv['e']) && $tv['e'][$tv['ie']][0] <= $ts) {
                    $e = $tv['e'][$tv['ie']++];
                    $tv['stueck'] += $e[1];
                    if ($n > 0) {
                        $fluss += $e[2]; // Käufe/Verkäufe seit dem letzten Rasterpunkt
                    }
                }
                if ($tv['stueck'] > 1e-6) {
                    if ($kursEur === null) {
                        $luecke = true;
                    } else {
                        $wert += $tv['stueck'] * $kursEur;
                    }
                }
                while ($tv['id'] < count($tv['d']) && $tv['d'][$tv['id']][0] <= $ts) {
                    $x = $tv['d'][$tv['id']++];
                    if ($n > $start) {
                        $div += $x[1];
                        $divPunkte[$n] = ($divPunkte[$n] ?? 0.0) + $x[1];
                    }
                }
            }
            unset($tv);
            foreach ($t['et'] as $seg) {
                $auf = (int)$seg['auf'];
                $zu = (int)$seg['zu'];
                $einsatzEur = betragInEuro((float)$seg['betrag'], 'USD', $usd);
                if ($n > 0 && $auf > $vorher && $auf <= $ts) {
                    $fluss += (float)$einsatzEur;
                }
                if ($n > 0 && $zu > 0 && $zu > $vorher && $zu <= $ts) {
                    $fluss -= (float)betragInEuro((float)$seg['betrag'] + (float)$seg['gewinn'], 'USD', $usd);
                }
                if ($auf > $ts || ($zu > 0 && $zu <= $ts)) {
                    continue;
                }
                $einheiten = (float)$seg['einheiten'];
                foreach ($seg['splits'] as [$sz, $sf]) {
                    if ($sz <= $ts) {
                        $einheiten *= (float)$sf;
                    }
                }
                if ($kursEur === null) {
                    $luecke = true;
                    continue;
                }
                $segKurs = $kursEur;
                if ($zu > 0 && $splits !== []) {
                    // geschlossene eToro-Position: Stück und Einstieg stehen auf dem Stand bei Schließung
                    $segKurs = $kursEur * splitFaktor($splits, $zu) / splitFaktor($splits, $ts);
                }
                if ($seg['hebel'] <= 1 && $seg['richtung'] > 0) {
                    $wert += $einheiten * $segKurs;
                } else {
                    // Einsatz plus Gewinn/Verlust seit Eröffnung (bei Hebel und Short)
                    $kostenEur = betragInEuro((float)$seg['kosten'], $seg['waehrung'], fxZum($fx, $fxIdx, $seg['waehrung'], $ts));
                    $wert += (float)$einsatzEur + $seg['richtung'] * ($einheiten * $segKurs - (float)$kostenEur);
                }
            }
            $werte[] = round($wert, 2);
            $fluesse[] = round($fluss, 2);
            $vorher = $ts;
        }
        $sichtbar = array_slice($werte, $start);
        if (max(array_map('abs', $sichtbar)) < 0.01 && max(array_map('abs', array_slice($fluesse, $start))) < 0.01) {
            continue;
        }
        $ergebnis[] = [
            'id' => $s, 'name' => $t['name'], 'symbol' => $s, 'art' => $t['art'], 'quellen' => array_keys($t['quellen']),
            'w' => $werte, 'f' => $fluesse, 'div' => round($div, 2), 'luecke' => $luecke,
            'dv' => array_map(static fn(int $n, float $b): array => [$n, round($b, 2)], array_keys($divPunkte), array_values($divPunkte)),
            'k' => array_slice($geschaefte, -300),
            'pot' => isset($d['firmen'][$s]['analysten']['potenzial']) ? round((float)$d['firmen'][$s]['analysten']['potenzial'], 4) : null,
        ];
    }
    usort($ergebnis, static fn(array $a, array $b): int => end($b['w']) <=> end($a['w']));
    $erg = ['fertig' => true, 'von' => $von, 'bis' => date('Y-m-d', $bisTs), 'punkte' => $raster, 'start' => $start, 'titel' => $ergebnis,
        'katalog' => titelKatalog($d)];
    if ($vergleich !== '') {
        $erg['vergleich'] = vergleichInEuro($vergleich, $hVergleich, $raster, $fx);
    }
    return $erg;
}

/** Kurs des Vergleichswerts in Euro je Rasterpunkt (null, wo es noch keinen Kurs gab). */
function vergleichInEuro(string $symbol, ?array $h, array $raster, array $fx): array
{
    if ($h === null || $h['t'] === []) {
        return ['symbol' => $symbol, 'fehler' => 'Zu „' . $symbol . '“ gibt es keinen Kursverlauf.'];
    }
    $w = (string)$h['w'];
    $idx = 0;
    $fxIdx = [];
    $eur = [];
    foreach ($raster as $ts) {
        $k = kursZum($h, $ts, $idx);
        $wert = betragInEuro($k, $w, fxZum($fx, $fxIdx, $w, $ts));
        $eur[] = $wert !== null ? round($wert, 4) : null;
    }
    return ['symbol' => $symbol, 'w' => $w, 'eur' => $eur];
}

/** Alle Titel, die je im Depot waren, mit Haltedauer – auch außerhalb des gewählten Zeitraums. */
function titelKatalog(array $d): array
{
    $liste = [];
    foreach (verlaufTitel($d) as $id => $t) {
        $erster = PHP_INT_MAX;
        $letzter = 0;
        $offen = false;
        foreach ($t['tr'] as $v) {
            $stueck = 0.0;
            foreach ($v['e'] as $e) {
                $et = (int)strtotime($e[0] . ' 12:00:00');
                $erster = min($erster, $et);
                $letzter = max($letzter, $et);
                $stueck += (float)$e[1];
            }
            $offen = $offen || $stueck > 1e-6;
        }
        foreach ($t['et'] as $seg) {
            $erster = min($erster, (int)$seg['auf']);
            $letzter = max($letzter, (int)($seg['zu'] ?: $seg['auf']));
            $offen = $offen || (int)$seg['zu'] === 0;
        }
        if ($erster === PHP_INT_MAX) {
            continue;
        }
        $liste[] = ['id' => $id, 'name' => $t['name'], 'symbol' => $id, 'quellen' => array_keys($t['quellen']),
            'erster' => $erster, 'letzter' => $letzter, 'aktuell' => $offen];
    }
    usort($liste, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    return $liste;
}

/** Frühestes Datum mit Buchungen (für „Max“). */
function verlaufBeginn(array $d): string
{
    $erste = time();
    foreach ((array)($d['broker']['traderepublic']['verlauf'] ?? []) as $v) {
        if (!empty($v['e'])) {
            $erste = min($erste, (int)strtotime($v['e'][0][0]));
        }
    }
    foreach ((array)($d['broker']['etoro_auszug']['segmente'] ?? []) as $s) {
        $erste = min($erste, (int)$s['auf']);
    }
    return date('Y-m-d', $erste);
}

/** Fehlen noch Daten für den Verlauf (älterer Import ohne Buchungsverlauf)? */
function verlaufHinweise(array $d): array
{
    $h = [];
    if (isset($d['broker']['traderepublic']) && !isset($d['broker']['traderepublic']['verlauf'])) {
        $h[] = 'Der Trade-Republic-Import ist älter als diese Funktion – bitte die CSV-Datei einmal neu importieren, damit der Verlauf berechnet werden kann.';
    }
    if (isset($d['broker']['etoro_auszug']) && !isset($d['broker']['etoro_auszug']['segmente']) && empty($d['broker']['etoro_auszug']['offen'])) {
        $h[] = 'Der eToro-Import ist älter als diese Funktion – bitte den Kontoauszug einmal neu importieren.';
    }
    if (zuordnungOffen($d) > 0) {
        $h[] = 'Die Kurse der importierten Titel werden noch zugeordnet – öffne kurz die Depotseite und warte, bis sie fertig ist.';
    }
    return $h;
}

// ---------------------------------------------------------------------------
// Sofort anzeigen: fertige Ergebnisse zwischenspeichern und im Hintergrund vorwärmen
// ---------------------------------------------------------------------------

const FZ_VERLAUF_ZEITRAEUME = ['1t', '1w', '1m', '3m', '6m', 'ytd', '1j', '3j', '5j', 'max'];

/** Kennung des Depotstands – ändert sich mit jedem Import und jeder Zuordnung. */
function verlaufStand(array $d): string
{
    $tr = (array)($d['broker']['traderepublic'] ?? []);
    $et = (array)($d['broker']['etoro_auszug'] ?? []);
    $symbole = [];
    foreach ((array)($tr['positionen'] ?? []) as $k => $p) {
        $symbole[] = $k . '=' . ($p['symbol'] ?? '');
    }
    foreach ((array)($et['symbole'] ?? []) as $k => $x) {
        $symbole[] = $k . '=' . ($x['symbol'] ?? '');
    }
    return md5((string)json_encode([(int)($tr['zeit'] ?? 0), (int)($et['zeit'] ?? 0), $symbole, !empty($d['broker']['etoro']['positionen'])]));
}

/** Zeitraum-Kürzel → [von, bis], genau wie im Browser gerechnet. */
function verlaufGrenzen(string $zeitraum, string $beginn): array
{
    $heute = new DateTimeImmutable('today');
    $v = $heute;
    $monate = ['1m' => 1, '3m' => 3, '6m' => 6, '1j' => 12, '3j' => 36, '5j' => 60][$zeitraum] ?? null;
    if ($monate !== null) {
        $v = $heute->modify('-' . $monate . ' months');
    } elseif ($zeitraum === '1t') {
        // letzter Handelstag davor (am Montag also Freitag)
        $v = $heute->modify('-1 day');
        while ((int)$v->format('N') >= 6) {
            $v = $v->modify('-1 day');
        }
    } elseif ($zeitraum === '1w') {
        $v = $heute->modify('-7 days');
    } elseif ($zeitraum === 'ytd') {
        $v = $heute->setDate((int)$heute->format('Y'), 1, 1);
    }
    $von = $zeitraum === 'max' ? $beginn : $v->format('Y-m-d');
    return [max($von, $beginn), $heute->format('Y-m-d')];
}

/** Fürs Netz: Werte gerundet und ohne lange Null-Strecken, Zahlungen nur dort, wo es welche gab. */
function verlaufSchlank(array $v): array
{
    foreach ($v['titel'] as &$t) {
        $n = count($t['w']);
        $a = 0;
        while ($a < $n && abs((float)$t['w'][$a]) < 0.005) {
            $a++;
        }
        $e = $n - 1;
        while ($e >= $a && abs((float)$t['w'][$e]) < 0.005) {
            $e--;
        }
        $t['w0'] = $a;
        $t['w'] = array_map(static fn($x): int|float => abs((float)$x) >= 100 ? (int)round((float)$x) : round((float)$x, 2), array_slice($t['w'], $a, max(0, $e - $a + 1)));
        $fluesse = [];
        foreach ($t['f'] as $i => $x) {
            if (abs((float)$x) >= 0.005) {
                $fluesse[] = [$i, round((float)$x, 2)];
            }
        }
        $t['f'] = $fluesse;
    }
    unset($t);
    $v['schlank'] = 1;
    return $v;
}

/** Kurzfassung für die Übersicht: Summe aller Titel ab dem sichtbaren Start, Zahlungen und Dividenden dünn besetzt. */
function verlaufKurz(array $v): array
{
    $n = count($v['punkte']);
    $s0 = (int)$v['start'];
    $summe = array_fill(0, $n, 0.0);
    $fluss = array_fill(0, $n, 0.0);
    $div = array_fill(0, $n, 0.0);
    foreach ($v['titel'] as $t) {
        foreach ($t['w'] as $i => $x) {
            $summe[$i] += (float)$x;
        }
        foreach ($t['f'] as $i => $x) {
            $fluss[$i] += (float)$x;
        }
        foreach ($t['dv'] as [$i, $b]) {
            $div[$i] += (float)$b;
        }
    }
    $duenn = static function (array $reihe) use ($s0): array {
        $erg = [];
        foreach ($reihe as $i => $x) {
            if ($i > $s0 && abs($x) >= 0.005) {
                $erg[] = [$i - $s0, round($x, 2)];
            }
        }
        return $erg;
    };
    return ['von' => $v['von'], 'bis' => $v['bis'], 'zeit' => time(), 'punkte' => array_slice($v['punkte'], $s0),
        'wert' => array_map(static fn(float $x): int => (int)round($x), array_slice($summe, $s0)), 'fluss' => $duenn($fluss), 'div' => $duenn($div)];
}

/**
 * Depot-Verlauf mit Zwischenspeicher. Ein gespeichertes Ergebnis kommt sofort – auch ein
 * älteres oder eines von gestern ('veraltet': dann rechnet der Browser im Hintergrund neu).
 * Je Zeitraum, Vorlauf und Vergleich gibt es genau einen Eintrag.
 */
function verlaufBerechnen(array $d, string $zeitraum, string $von, string $bis, int $vorlauf, string $vergleich, bool $neu,
    float $budget = 8.0, float $auffrischen = 1.5): array
{
    $stand = verlaufStand($d);
    $frei = !in_array($zeitraum, FZ_VERLAUF_ZEITRAEUME, true);
    $schluessel = 'verlauf-erg:' . md5((string)json_encode([$frei ? $von . '|' . $bis : $zeitraum, $vorlauf, $vergleich]));
    $kennung = md5((string)json_encode([$von, $bis, $vorlauf, $vergleich, $stand]));
    if (!$neu) {
        $c = cacheLesen($schluessel, 900);
        if ($c !== null && is_array($c['wert']) && !empty($c['wert']['fertig'])) {
            $passt = ($c['wert']['kennung'] ?? '') === $kennung;
            return ['alter' => time() - (int)$c['zeit'], 'veraltet' => !$passt || !$c['frisch']] + $c['wert'];
        }
    }
    $v = depotVerlauf($d, $von, $bis, $budget, $vorlauf, $vergleich, $auffrischen);
    if (!$v['fertig']) {
        return $v;
    }
    if (!$frei) {
        $kurz = verlaufKurz($v);
        $kurz['kennung'] = md5((string)json_encode([$von, $bis, $stand]));
        cacheSchreiben('verlauf-kurz:' . $zeitraum, $kurz);
    }
    $v = verlaufSchlank($v);
    $v['kennung'] = $kennung;
    cacheSchreiben($schluessel, $v);
    return ['alter' => 0, 'veraltet' => false] + $v;
}

/** Kurzfassung für die Übersicht aus dem Zwischenspeicher (sofort, ohne zu rechnen). */
function verlaufFuerUebersicht(array $d): ?array
{
    if (empty($d['broker']['traderepublic']['verlauf']) && empty($d['broker']['etoro_auszug']['segmente'])) {
        return null;
    }
    $z = (string)($d['einstellungen']['uebersicht_zeitraum'] ?? '1j');
    $z = in_array($z, FZ_VERLAUF_ZEITRAEUME, true) ? $z : '1j';
    [$von, $bis] = verlaufGrenzen($z, verlaufBeginn($d));
    $c = cacheLesen('verlauf-kurz:' . $z, 900);
    $daten = $c !== null && is_array($c['wert']) ? $c['wert'] : null;
    $passt = $daten !== null && ($daten['kennung'] ?? '') === md5((string)json_encode([$von, $bis, verlaufStand($d)]));
    return ['zeitraum' => $z, 'daten' => $daten, 'veraltet' => !$passt || !$c['frisch']];
}

/**
 * Für den Cron: aktuelle Kurse holen, Kursverläufe um die letzten Tage ergänzen und die
 * Ansichten der Übersicht und des Depot-Verlaufs vorrechnen – damit beim Öffnen nichts lädt.
 */
function verlaufAufwaermen(float $budget): array
{
    $start = microtime(true);
    $d = datenLaden();
    if (empty($d['broker']['traderepublic']['verlauf']) && empty($d['broker']['etoro_auszug']['segmente'])) {
        return ['verlauf' => 'keine Buchungen'];
    }
    $titel = verlaufTitel($d);
    $boden = verlaufBoden($d);
    $bedarf = [];
    foreach ($titel as $s => $t) {
        $erster = max($boden, titelBeginn($t) - 20 * 86400);
        $bedarf[$s] = [$erster, $erster];
    }
    $zuletzt = (array)($d['einstellungen']['verlauf_zuletzt'] ?? []);
    $vergleich = (string)($zuletzt['vergleich'] ?? '');
    if ($vergleich !== '') {
        $bedarf[$vergleich] = [$boden, $boden];
    }
    $rest = static fn(): float => $budget - (microtime(true) - $start);
    $offen = historienLaden($bedarf, max(5.0, $rest() * 0.4), max(5.0, $rest() * 0.5));
    // Devisen der Titel (aus den gespeicherten Verläufen)
    $fx = ['EURUSD=X' => [$boden, $boden]];
    foreach (array_keys($bedarf) as $s) {
        $w = waehrungBasis((string)(historieGecacht((string)$s, PHP_INT_MAX)['w'] ?? ''));
        if ($w !== '' && $w !== 'EUR') {
            $fx['EUR' . $w . '=X'] = [$boden, $boden];
        }
    }
    historienLaden($fx, 5.0, 5.0);
    // aktuelle Kurse – daraus kommt der heutige Wert
    if ($rest() > 15) {
        yahooKurse(array_merge(array_keys($bedarf), array_keys($fx)));
    }
    $gerechnet = [];
    $d = datenLaden();
    $z = (string)($d['einstellungen']['uebersicht_zeitraum'] ?? '1j');
    $ansichten = [[in_array($z, FZ_VERLAUF_ZEITRAEUME, true) ? $z : '1j', 0, '']];
    if (in_array((string)($zuletzt['zeitraum'] ?? ''), FZ_VERLAUF_ZEITRAEUME, true)) {
        $ansichten[] = [(string)$zuletzt['zeitraum'], (int)($zuletzt['vorlauf'] ?? 100), $vergleich];
    }
    foreach ($ansichten as [$zr, $vl, $vg]) {
        if ($rest() < 8) {
            break;
        }
        [$von, $bis] = verlaufGrenzen($zr, verlaufBeginn($d));
        $v = verlaufBerechnen($d, $zr, $von, $bis, $vl, $vg, true, max(3.0, $rest() - 5), 0.0);
        $gerechnet[] = $zr . ($v['fertig'] ? '' : ' (fehlt noch)');
    }
    return ['verlauf' => ['fehlend' => $offen, 'gerechnet' => $gerechnet, 'sekunden' => round(microtime(true) - $start, 1)]];
}
