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

/** Kursverlauf aus dem Zwischenspeicher, wenn er weit genug zurückreicht und frisch ist. */
function historieGecacht(string $symbol, int $ab): ?array
{
    $c = cacheLesen(historieSchluessel($symbol), 12 * 3600);
    if ($c === null || !$c['frisch'] || !is_array($c['wert']) || (int)($c['wert']['ab'] ?? PHP_INT_MAX) > $ab) {
        return null;
    }
    return $c['wert'];
}

/** Antwort von Yahoo auswerten; Schlusskurse ohne Split-Bereinigung (tatsächliche Kurse des Tages). */
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
    $t = [];
    $c = [];
    foreach ($zeiten as $i => $ts) {
        $v = $schluss[$i] ?? null;
        if (!is_numeric($v) || (float)$v <= 0) {
            continue;
        }
        $faktor = 1.0;
        foreach ($splits as [$sd, $f]) {
            if ((int)$ts < $sd) {
                $faktor *= $f; // Yahoo rechnet Kurse vor einem Split herunter – hier zurück auf den echten Kurs
            }
        }
        $t[] = (int)$ts;
        $c[] = round((float)$v * $faktor, 6);
    }
    return ['ab' => $ab, 't' => $t, 'c' => $c, 'w' => (string)($res['meta']['currency'] ?? '')];
}

/**
 * Lädt fehlende Kursverläufe parallel nach, solange das Zeitbudget reicht.
 * Rückgabe: Anzahl noch fehlender Verläufe.
 */
function historienLaden(array $symbole, int $ab, float $budget): int
{
    $start = microtime(true);
    $fehlend = array_values(array_filter(array_unique($symbole), static fn(string $s): bool => historieGecacht($s, $ab) === null));
    foreach (array_chunk($fehlend, 12) as $teil) {
        if (microtime(true) - $start > $budget) {
            break;
        }
        $anfragen = [];
        foreach ($teil as $s) {
            $anfragen[$s] = ['url' => 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($s)
                . '?period1=' . $ab . '&period2=' . (time() + 86400) . '&interval=1d&events=split&includePrePost=false',
                'ua' => 'browser', 'zeit' => 20];
        }
        foreach (httpViele($anfragen) as $s => $r) {
            $j = $r['code'] === 200 ? json_decode($r['body'], true) : null;
            $h = is_array($j) ? historieAuswerten($j, $ab) : null;
            if ($h === null && in_array($r['code'], [200, 404, 400], true)) {
                $h = ['ab' => $ab, 't' => [], 'c' => [], 'w' => '']; // unbekannt – nicht bei jedem Aufruf erneut fragen
            }
            if ($h !== null) {
                cacheSchreiben(historieSchluessel((string)$s), $h);
            }
        }
    }
    return count(array_filter($fehlend, static fn(string $s): bool => historieGecacht($s, $ab) === null));
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
function depotVerlauf(array $d, string $von, string $bis, float $budget = 8.0, int $vorlaufTage = 0): array
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
    $offen = historienLaden($symbole, $ab, $budget);
    if ($offen > 0) {
        return ['fertig' => false, 'offen' => $offen, 'gesamt' => count($symbole)];
    }
    // Währungen der Titel und eToro-Einsätze (USD) → Devisenverläufe
    $waehrungen = ['USD' => true];
    $verlaeufe = [];
    foreach ($symbole as $s) {
        $verlaeufe[$s] = historieGecacht($s, $ab);
        $w = waehrungBasis((string)($verlaeufe[$s]['w'] ?? ''));
        if ($w !== '') {
            $waehrungen[$w] = true;
        }
        foreach ($titel[$s]['et'] as $seg) {
            $waehrungen[waehrungBasis($seg['waehrung'])] = true;
        }
    }
    unset($waehrungen['EUR']);
    $fxSymbole = array_map(static fn(string $w): string => 'EUR' . $w . '=X', array_keys($waehrungen));
    $offenFx = historienLaden($fxSymbole, $ab, max(2.0, $budget - 2));
    if ($offenFx > 0) {
        return ['fertig' => false, 'offen' => $offenFx, 'gesamt' => count($symbole) + count($fxSymbole)];
    }
    $fx = [];
    foreach (array_keys($waehrungen) as $w) {
        $fx[$w] = historieGecacht('EUR' . $w . '=X', $ab);
    }

    $ergebnis = [];
    foreach ($titel as $s => $t) {
        $h = $verlaeufe[$s];
        $w = (string)$h['w'];
        $werte = [];
        $fluesse = [];
        $luecke = false;
        $div = 0.0;
        $idx = 0;
        $fxIdx = [];
        $vorher = PHP_INT_MIN;
        foreach ($raster as $n => $ts) {
            $kurs = kursZum($h, $ts, $idx);
            $kursEur = betragInEuro($kurs, $w, fxZum($fx, $fxIdx, $w, $ts));
            $usd = fxZum($fx, $fxIdx, 'USD', $ts);
            $wert = 0.0;
            $fluss = 0.0;
            foreach ($t['tr'] as $v) {
                $stueck = 0.0;
                foreach ($v['e'] as $e) {
                    $et = strtotime($e[0] . ' 12:00:00');
                    if ($et > $ts) {
                        break;
                    }
                    $stueck += (float)$e[1];
                    if ($n > 0 && $et > $vorher) {
                        $fluss += (float)$e[2];
                    }
                }
                if ($stueck > 1e-6) {
                    if ($kursEur === null) {
                        $luecke = true;
                    } else {
                        $wert += $stueck * $kursEur;
                    }
                }
                if ($n > $start) {
                    foreach ($v['d'] as $dv) {
                        $dt = strtotime($dv[0] . ' 12:00:00');
                        if ($dt > $vorher && $dt <= $ts) {
                            $div += (float)$dv[1];
                        }
                    }
                }
            }
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
                if ($seg['hebel'] <= 1 && $seg['richtung'] > 0) {
                    $wert += $einheiten * $kursEur;
                } else {
                    // Einsatz plus Gewinn/Verlust seit Eröffnung (bei Hebel und Short)
                    $kostenEur = betragInEuro((float)$seg['kosten'], $seg['waehrung'], fxZum($fx, $fxIdx, $seg['waehrung'], $ts));
                    $wert += (float)$einsatzEur + $seg['richtung'] * ($einheiten * $kursEur - (float)$kostenEur);
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
        ];
    }
    usort($ergebnis, static fn(array $a, array $b): int => end($b['w']) <=> end($a['w']));
    return ['fertig' => true, 'von' => $von, 'bis' => date('Y-m-d', $bisTs), 'punkte' => $raster, 'start' => $start, 'titel' => $ergebnis];
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
