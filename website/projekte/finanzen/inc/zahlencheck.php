<?php
declare(strict_types=1);

/*
 * Zahlen-Check zu Quartalszahlen eines US-Werts (z. B. PureCycle):
 * Schleife 1 – sobald das 8-K mit den Zahlen (Item 2.02) bei der SEC liegt:
 *   Umsatz, Verlust je Aktie und Auslastung des Werks (Textsuche, per KI ausgelesen).
 * Schleife 2 – Nachbörse ab 16:15 Uhr New York (22:15 Uhr bei uns):
 *   Kurs gegenüber der Schwelle und Volumen nach 30 Minuten gegenüber dem Normalwert.
 * Um 16:30 Uhr New York (22:30 Uhr) kommt die Ampel per Push und E-Mail.
 * An den Tagen davor wird jeden Abend das Nachbörsen-Volumen nach 30 Minuten
 * gemessen – daraus entsteht der Normalwert.
 */

const FZ_ZAHLENCHECK_STANDARD = [
    'an' => false, 'us' => '', 'datum' => '', 'seit' => 0,
    'umsatz_min' => 0.0, 'eps_min' => 0.0, 'suchwort' => '', 'auslastung_min' => 40.0, 'kapazitaet' => 0.0,
    'kurs_trigger' => 0.0, 'vol_faktor' => 5.0,
    'aktion_gruen' => '', 'aktion_gelb' => '', 'aktion_rot' => '',
];

function zcEinstellung(array $f): array
{
    return array_merge(FZ_ZAHLENCHECK_STANDARD, (array)($f['zahlencheck'] ?? []));
}

function zcNewYork(?int $ts = null): DateTimeImmutable
{
    return (new DateTimeImmutable('@' . ($ts ?? time())))->setTimezone(new DateTimeZone('America/New_York'));
}

/** Neuestes 8-K mit Quartalszahlen (Item 2.02) nach $seit. */
function zcSecFiling(string $ticker, int $seit): ?array
{
    $cik = secCik($ticker);
    if ($cik === '') {
        return null;
    }
    $j = httpJson(http('https://data.sec.gov/submissions/CIK' . $cik . '.json', secKopf()));
    $f = $j['filings']['recent'] ?? null;
    if (!is_array($f)) {
        return null;
    }
    foreach ((array)($f['form'] ?? []) as $i => $form) {
        $zeit = (int)strtotime((string)($f['acceptanceDateTime'][$i] ?? ''));
        if ($zeit <= $seit) {
            break; // neueste zuerst
        }
        if ($form === '8-K' && str_contains((string)($f['items'][$i] ?? ''), '2.02')) {
            $nummer = str_replace('-', '', (string)$f['accessionNumber'][$i]);
            return ['nummer' => $nummer, 'zeit' => $zeit, 'cik' => (int)$cik,
                'ordner' => 'https://www.sec.gov/Archives/edgar/data/' . (int)$cik . '/' . $nummer . '/',
                'haupt' => (string)($f['primaryDocument'][$i] ?? '')];
        }
    }
    return [];
}

function zcHtmlText(string $html): string
{
    $t = preg_replace('/<(script|style)\b.*?<\/\1>/is', ' ', $html);
    $t = preg_replace('/<(br|p|div|tr|li|h\d)\b[^>]*>/i', "\n", (string)$t);
    $t = html_entity_decode(strip_tags((string)$t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace("/[ \t\x{a0}]+/u", ' ', $t);
    return trim((string)preg_replace("/\n\s*\n+/", "\n", (string)$t));
}

/** Text der Pressemitteilung (Exhibit 99.1) und ggf. der Präsentation (99.2). */
function zcFilingText(array $filing): array
{
    $idx = httpJson(http($filing['ordner'] . 'index.json', secKopf()));
    $dateien = [];
    foreach ((array)($idx['directory']['item'] ?? []) as $x) {
        $n = (string)($x['name'] ?? '');
        if (preg_match('/ex[-_]?99[-_.]?0?([12])\b.*\.htm/i', $n, $m) || preg_match('/ex99[-_]?0?([12])\.htm/i', $n, $m)) {
            $dateien[(int)$m[1]] ??= $n;
        }
    }
    ksort($dateien);
    if ($dateien === [] && $filing['haupt'] !== '') {
        $dateien[1] = $filing['haupt'];
    }
    $text = '';
    $link = '';
    foreach ($dateien as $n) {
        $r = http($filing['ordner'] . $n, ['ua' => 'ehrlich', 'zeit' => 30]);
        if ($r['code'] === 200 && $r['body'] !== '') {
            $text .= "\n\n=== " . $n . " ===\n" . zcHtmlText($r['body']);
            $link = $link !== '' ? $link : $filing['ordner'] . $n;
        }
    }
    return ['text' => trim($text), 'link' => $link !== '' ? $link : $filing['ordner']];
}

/** Sätze mit dem Suchwort (für die Meldung und als Rückfall ohne KI). */
function zcFundstellen(string $text, string $wort, int $max = 4): array
{
    if ($wort === '') {
        return [];
    }
    $saetze = preg_split('/(?<=[.!?])\s+|\n/u', $text) ?: [];
    $treffer = [];
    foreach ($saetze as $s) {
        $s = trim($s);
        // Rechtshinweise („forward-looking statements“) sind keine Nachricht
        if (mb_stripos($s, $wort) !== false && mb_strlen($s) < 500 && !preg_match('/forward-looking|risk factors|ability of PCT|ability to operate/i', $s)) {
            $treffer[] = $s;
        }
        if (count($treffer) >= $max) {
            break;
        }
    }
    return $treffer;
}

/** Schleife 1: die drei Kriterien aus dem Text auslesen (KI, sonst einfache Textsuche). */
function zcZahlenAuslesen(string $text, array $zc): array
{
    $erg = ['umsatz' => null, 'eps' => null, 'auslastung' => null, 'auslastung_art' => 'unbekannt', 'auslastung_text' => '',
        'probleme' => false, 'probleme_text' => '', 'quartal' => '', 'kurz' => '', 'fundstellen' => zcFundstellen($text, $zc['suchwort']), 'ki' => false, 'fehler' => ''];
    if (preg_match('/revenues?\s+(?:of|was|were)\s+\$\s?([0-9]+(?:\.[0-9]+)?)\s*million/i', $text, $m)) {
        $erg['umsatz'] = (float)$m[1];
    }
    $wort = $zc['suchwort'] !== '' ? $zc['suchwort'] : 'dem Hauptwerk';
    $kap = $zc['kapazitaet'] > 0 ? ' Wenn kein Prozentwert genannt ist, berechne die Auslastung aus der gemeldeten Produktion gegenüber der Nennkapazität von '
        . zahl($zc['kapazitaet'], 0) . ' Mio. Pfund pro Jahr (Quartal = ein Viertel, Monat = ein Zwölftel) und setze auslastung_art auf "berechnet".' : '';
    $auftrag = "Hier ist die Pressemitteilung zu Quartalszahlen (aus dem 8-K bei der SEC).\n\n" . mb_substr($text, 0, 60000) . "\n\n"
        . "Lies genau diese Werte aus und antworte NUR mit JSON:\n"
        . '{"quartal":"z. B. Q3 2026","umsatz_mio_usd":Zahl oder null,"eps_usd":Verlust/Gewinn je Aktie (basic, Quartal; Verlust negativ) oder null,'
        . '"auslastung_prozent":Zahl oder null,"auslastung_art":"gemeldet|berechnet|unbekannt","auslastung_text":"kurze Begründung mit Zahlen, Deutsch",'
        . '"probleme":true/false,"probleme_text":"neue Probleme, Ausfälle oder Verzögerungen bei ' . $wort . ' – Deutsch, leer wenn keine","zusammenfassung":"2 Sätze Deutsch"}' . "\n"
        . 'Auslastung: gemeint ist die Auslastung bzw. Produktionsrate von ' . $wort . ', möglichst für die jüngsten Monate (z. B. September/Oktober).' . $kap
        . ' Nichts erfinden – was nicht im Text steht, ist null.';
    $fehler = '';
    $j = kiAnfrage(['model' => FZ_BEOB_MODELL, 'max_tokens' => 1200, 'system' => 'Du liest Finanzberichte exakt aus. Antworte ausschließlich mit einem JSON-Objekt.',
        'messages' => [['role' => 'user', 'content' => $auftrag]]], 90, $fehler);
    $antwort = '';
    foreach ((array)($j['content'] ?? []) as $b) {
        $antwort .= ($b['type'] ?? '') === 'text' ? (string)$b['text'] : '';
    }
    $k = $j !== null ? beobJsonAus($antwort) : null;
    if ($k === null) {
        $erg['fehler'] = $fehler !== '' ? $fehler : 'KI-Antwort nicht lesbar';
        return $erg;
    }
    $zahlOderNull = static fn($x): ?float => is_numeric($x) ? (float)$x : null;
    $erg['ki'] = true;
    $erg['umsatz'] = $zahlOderNull($k['umsatz_mio_usd'] ?? null) ?? $erg['umsatz'];
    $erg['eps'] = $zahlOderNull($k['eps_usd'] ?? null);
    $erg['auslastung'] = $zahlOderNull($k['auslastung_prozent'] ?? null);
    $erg['auslastung_art'] = in_array($k['auslastung_art'] ?? '', ['gemeldet', 'berechnet'], true) ? (string)$k['auslastung_art'] : 'unbekannt';
    $erg['auslastung_text'] = mb_substr(trim((string)($k['auslastung_text'] ?? '')), 0, 400);
    $erg['probleme'] = !empty($k['probleme']);
    $erg['probleme_text'] = mb_substr(trim((string)($k['probleme_text'] ?? '')), 0, 400);
    $erg['quartal'] = mb_substr(trim((string)($k['quartal'] ?? '')), 0, 20);
    $erg['kurz'] = mb_substr(trim((string)($k['zusammenfassung'] ?? '')), 0, 500);
    return $erg;
}

/** Nachbörse bei Nasdaq: letzter Kurs, Hoch, Volumen; Stand in New Yorker Zeit. */
function zcNachboerse(string $ticker): ?array
{
    $r = http('https://api.nasdaq.com/api/quote/' . rawurlencode(strtoupper($ticker)) . '/extended-trading?markettype=post&assetclass=stocks&time=0',
        ['ua' => 'browser', 'zeit' => 15, 'kopf' => ['Accept: application/json', 'Origin: https://www.nasdaq.com', 'Referer: https://www.nasdaq.com/']]);
    $j = httpJson($r);
    $zeile = $j['data']['infoTable']['rows'][0] ?? null;
    if (!is_array($zeile)) {
        return null;
    }
    $zahl = static fn(string $s): ?float => preg_match('/([0-9][0-9,]*(?:\.[0-9]+)?)/', $s, $m) ? (float)str_replace(',', '', $m[1]) : null;
    $stand = 0;
    foreach ((array)($j['data']['lastUpdateInfo'] ?? []) as $z) {
        if (preg_match('/updated\s+(.+?)\s+ET/i', (string)$z, $m)) {
            $stand = (int)strtotime($m[1] . ' America/New_York');
        }
    }
    $kurs = $zahl((string)($zeile['consolidated'] ?? ''));
    if ($kurs === null) {
        return null;
    }
    return ['kurs' => $kurs, 'hoch' => $zahl((string)($zeile['highPrice'] ?? '')), 'volumen' => $zahl((string)($zeile['volume'] ?? '')) ?? 0.0, 'stand' => $stand ?: time()];
}

/** Normaler Nachbörsen-Umsatz nach 30 Minuten (aus den eigenen Messungen der Vortage). */
function zcVolumenSchnitt(array $st): ?array
{
    $werte = array_values(array_filter(array_column(array_slice((array)($st['ah'] ?? []), -15, null, true), 'v30'), static fn($x) => $x !== null && $x > 0));
    if (count($werte) >= 2) {
        return ['wert' => array_sum($werte) / count($werte), 'tage' => count($werte), 'art' => '30 Min.'];
    }
    $voll = array_values(array_filter(array_column(array_slice((array)($st['ah'] ?? []), -15, null, true), 'voll'), static fn($x) => $x !== null && $x > 0));
    if ($voll !== []) {
        return ['wert' => array_sum($voll) / count($voll), 'tage' => count($voll), 'art' => 'ganze Nachbörse'];
    }
    return null;
}

/** Ampel aus beiden Schleifen. */
function zcAmpel(array $zc, array $z, ?array $n): array
{
    $umsatzOk = $z['umsatz'] !== null ? $z['umsatz'] > $zc['umsatz_min'] : null;
    $epsOk = $z['eps'] !== null ? $z['eps'] > $zc['eps_min'] : null;
    $werkOk = $z['probleme'] ? false : ($z['auslastung'] !== null ? $z['auslastung'] >= $zc['auslastung_min'] : null);
    $kursOk = $n !== null ? $n['kurs'] > $zc['kurs_trigger'] : null;
    $volOk = $n !== null && ($n['faktor'] ?? null) !== null ? $n['faktor'] >= $zc['vol_faktor'] : null;
    $farbe = 'gelb';
    if ($umsatzOk === true && $werkOk === true && $kursOk === true) {
        $farbe = 'gruen';
    } elseif ($z['probleme'] || ($umsatzOk === false && ($epsOk === false || $werkOk === false))) {
        $farbe = 'rot';
    }
    return ['farbe' => $farbe, 'umsatz' => $umsatzOk, 'eps' => $epsOk, 'werk' => $werkOk, 'kurs' => $kursOk, 'volumen' => $volOk];
}

function zcHaken(?bool $x): string
{
    return $x === null ? '❓' : ($x ? '✅' : '❌');
}

/** Text der Meldung (Zahlen allein oder mit Nachbörse und Ampel). */
function zcText(array $zc, array $st, ?float $usdJeEur): string
{
    $z = (array)$st['zahlen'];
    $n = $st['nach'] ?? null;
    $a = zcAmpel($zc, $z, $n);
    $eur = static fn(?float $usd, int $nk = 2): string => $usd !== null && $usdJeEur ? ' (' . zahl($usd / $usdJeEur, $nk) . ' €)' : '';
    $wort = $zc['suchwort'] !== '' ? $zc['suchwort'] : 'Werk';
    $zeilen = [];
    $zeilen[] = ($z['quartal'] !== '' ? $z['quartal'] . ' – ' : '') . 'Zahlen bei der SEC: ' . (string)($st['filing']['link'] ?? '');
    $zeilen[] = zcHaken($a['umsatz']) . ' Umsatz ' . ($z['umsatz'] !== null ? zahl($z['umsatz'], 2) . ' Mio. $' . $eur($z['umsatz']) : 'nicht gefunden')
        . ' – Schwelle ' . zahl($zc['umsatz_min'], 2) . ' Mio. $';
    $zeilen[] = zcHaken($a['eps']) . ' Ergebnis je Aktie ' . ($z['eps'] !== null ? zahl($z['eps'], 2) . ' $' . $eur($z['eps']) : 'nicht gefunden')
        . ' – Schwelle ' . zahl($zc['eps_min'], 2) . ' $';
    $zeilen[] = zcHaken($a['werk']) . ' ' . $wort . ': ' . ($z['auslastung'] !== null ? 'Auslastung ' . zahl($z['auslastung'], 0) . ' %' . ($z['auslastung_art'] === 'berechnet' ? ' (aus der Produktion berechnet)' : '') : 'Auslastung nicht genannt')
        . ' – Schwelle ' . zahl($zc['auslastung_min'], 0) . ' %' . ($z['auslastung_text'] !== '' ? "\n   " . $z['auslastung_text'] : '')
        . ($z['probleme'] ? "\n   ⚠️ Probleme: " . $z['probleme_text'] : '');
    if ($n !== null) {
        $zeilen[] = '';
        $zeilen[] = zcHaken($a['kurs']) . ' Nachbörse ' . zahl($n['kurs'], 2) . ' $' . $eur($n['kurs']) . ($n['hoch'] !== null ? ', Hoch ' . zahl($n['hoch'], 2) . ' $' : '')
            . ' – Marke ' . zahl($zc['kurs_trigger'], 2) . ' $' . $eur($zc['kurs_trigger']);
        $zeilen[] = zcHaken($a['volumen']) . ' Volumen ' . zahl((float)$n['volumen'], 0) . ' Stück' . (($n['faktor'] ?? null) !== null
            ? ' = ' . zahl($n['faktor'], 1) . '× normal (Ø ' . zahl($n['schnitt'], 0) . ', ' . $n['schnitt_art'] . ', ' . $n['schnitt_tage'] . ($n['schnitt_tage'] === 1 ? ' Abend)' : ' Abende)') : ' – noch kein Normalwert gemessen')
            . ' – Schwelle ' . zahl($zc['vol_faktor'], 0) . '×';
    }
    if ($z['kurz'] !== '') {
        $zeilen[] = '';
        $zeilen[] = $z['kurz'];
    } elseif (!$z['ki'] && $z['fundstellen'] !== []) {
        $zeilen[] = '';
        $zeilen[] = 'Fundstellen „' . $wort . '“: ' . implode(' | ', $z['fundstellen']);
    }
    return implode("\n", $zeilen);
}

/**
 * Bei jedem Cron-Lauf (und in der Abend-Schleife alle 1–2 Minuten).
 * Rückgabe enthält 'aktiv' (Abend-Schleife soll weiterlaufen) – ohne Namen oder Werte.
 */
function zahlencheckAusfuehren(bool $abend = false): array
{
    $d = datenLaden();
    $ny = zcNewYork();
    $nyTag = $ny->format('Y-m-d');
    $nyUhr = (int)$ny->format('Hi');
    $werktag = (int)$ny->format('N') <= 5;
    $aktiv = false;
    $erg = ['aktiv' => false, 'gemeldet' => 0, 'sec' => null, 'nasdaq' => null];
    foreach (beobDaten($d)['firmen'] as $s => $f) {
        $zc = zcEinstellung($f);
        if (!$zc['an'] || $zc['us'] === '') {
            continue;
        }
        $s = (string)$s;
        $st = (array)($f['zc'] ?? []);
        $name = (string)($d['firmen'][$s]['name'] ?? $s);
        $meldungen = [];
        $fx = null;
        // Schleife 1: neue Zahlen bei der SEC? (an jedem Lauf; am Zahlentag ab 15:55 Uhr New York in der Abend-Schleife)
        // nur Berichte nach dem Einschalten – ein alter Bericht soll keinen Alarm auslösen
        $seit = max((int)$zc['seit'], (int)($st['erledigt'] ?? 0));
        $erwartet = $seit > 0 && ($zc['datum'] === '' || $zc['datum'] <= $nyTag);
        if ($erwartet && (empty($st['zahlen']) || !empty($st['ampel']))) {
            $filing = zcSecFiling($zc['us'], $seit);
            $erg['sec'] = $filing !== null;
            if ($filing) {
                $t = zcFilingText($filing);
                $st['ampel'] = null;
                $st['nach'] = null;
                $st['filing'] = ['nummer' => $filing['nummer'], 'zeit' => $filing['zeit'], 'link' => $t['link']];
                $st['zahlen'] = zcZahlenAuslesen($t['text'], $zc) + ['zeit' => time()];
                $fx = wechselkurse(['USD'])['USD'] ?? null;
                $meldungen[] = ['📊 ' . $name . ': Zahlen sind da', zcText($zc, $st, $fx)];
            }
        }
        // Normalwert der Nachbörse: jeden Abend nach 30 Minuten messen, nachts den ganzen Abend
        $mitNasdaq = $werktag && (($nyUhr >= 1610 && $nyUhr < 1700) || $nyUhr >= 1930 || $nyUhr < 400);
        $n = $mitNasdaq || (!empty($st['zahlen']) && empty($st['ampel'])) ? zcNachboerse($zc['us']) : null;
        if ($mitNasdaq || !empty($st['zahlen'])) {
            $erg['nasdaq'] = $n !== null;
        }
        if ($n !== null) {
            $tag = zcNewYork($n['stand'] - 6 * 3600)->format('Y-m-d'); // nach Mitternacht zählt noch der Vortag
            $uhrStand = (int)zcNewYork($n['stand'])->format('Hi');
            $ah = (array)($st['ah'] ?? []);
            $e = (array)($ah[$tag] ?? ['v30' => null, 'voll' => null]);
            if ($uhrStand >= 1628 && $uhrStand <= 1645 && $e['v30'] === null && $tag !== ($st['filing_tag'] ?? '')) {
                $e['v30'] = $n['volumen'];
            }
            if ($uhrStand >= 1930 || $uhrStand < 400) {
                $e['voll'] = max((float)($e['voll'] ?? 0), $n['volumen']);
            }
            $ah[$tag] = $e;
            ksort($ah);
            $st['ah'] = array_slice($ah, -20, null, true);
        }
        // Schleife 2 und Ampel: Zahlen da, Ampel noch nicht verschickt
        if (!empty($st['zahlen']) && empty($st['ampel'])) {
            $st['filing_tag'] = zcNewYork((int)$st['filing']['zeit'])->format('Y-m-d');
            $nachHandel = $nyTag === $st['filing_tag'] || $nyUhr < 400;
            if ($n !== null) {
                $schnitt = zcVolumenSchnitt($st);
                $st['nach'] = $n + ['schnitt' => $schnitt['wert'] ?? null, 'schnitt_art' => $schnitt['art'] ?? '', 'schnitt_tage' => $schnitt['tage'] ?? 0,
                    'faktor' => $schnitt ? $n['volumen'] / max(1.0, $schnitt['wert']) : null, 'zeit' => time()];
            }
            // Ampel ab 16:30 Uhr New York (22:30 Uhr bei uns) – oder sofort, wenn die Zahlen nicht nach US-Börsenschluss kamen
            $faellig = !$nachHandel || $nyUhr >= 1630 || $nyUhr < 400 || (int)zcNewYork((int)$st['filing']['zeit'])->format('Hi') < 1600;
            if ($faellig) {
                $fx ??= wechselkurse(['USD'])['USD'] ?? null;
                $a = zcAmpel($zc, (array)$st['zahlen'], $st['nach'] ?? null);
                $st['ampel'] = $a + ['zeit' => time()];
                $st['erledigt'] = (int)$st['filing']['zeit'];
                $kopf = ['gruen' => '🟢 GRÜN – Squeeze-Warnung', 'gelb' => '🟡 GELB – Mischlage', 'rot' => '🔴 ROT – Absturz-Gefahr'][$a['farbe']];
                $plan = (string)$zc['aktion_' . $a['farbe']];
                $meldungen[] = [$name . ': ' . $kopf, zcText($zc, $st, $fx) . ($plan !== '' ? "\n\nDein Plan dafür: " . $plan : '')];
            }
        } elseif ($zc['datum'] !== '' && $zc['datum'] === $nyTag && $nyUhr >= 1730 && ($st['filing_tag'] ?? '') !== $nyTag && ($st['fehlt_gemeldet'] ?? '') !== $nyTag) {
            $st['fehlt_gemeldet'] = $nyTag;
            $meldungen[] = ['⏳ ' . $name . ': noch keine Zahlen', 'Bis 17:30 Uhr New York liegt kein 8-K mit Quartalszahlen bei der SEC. Der Check sucht bei jedem Lauf weiter.'];
        }
        foreach ($meldungen as [$titel, $text]) {
            benachrichtigen($d, $titel, $text, fzBasisUrl() . '?seite=beobachtung#b-' . preg_replace('/[^A-Za-z0-9]/', '-', $s));
            $st['meldungen'] = array_slice(array_merge([['zeit' => time(), 'titel' => $titel, 'text' => $text]], (array)($st['meldungen'] ?? [])), 0, 10);
            $erg['gemeldet']++;
        }
        datenAendern(function (array &$d) use ($s, $st): void {
            if (isset($d['beobachtung']['firmen'][$s])) {
                $d['beobachtung']['firmen'][$s]['zc'] = $st;
            }
        });
        // Abend-Schleife: am Zahlentag 15:55–17:00 Uhr bis zur Ampel, sonst 16:25–16:50 Uhr für den Normalwert
        $heuteZahlentag = $zc['datum'] === $nyTag || (!empty($st['zahlen']) && empty($st['ampel']));
        if ($werktag && (($heuteZahlentag && $nyUhr >= 1555 && $nyUhr < 1700 && empty($st['ampel']))
            || ($nyUhr >= 1625 && $nyUhr < 1650 && (($st['ah'][$nyTag]['v30'] ?? null) === null)))) {
            $aktiv = true;
        }
    }
    $erg['aktiv'] = $aktiv;
    return ['zahlencheck' => $erg];
}

/** Von Hand: den letzten Quartalsbericht auslesen und anzeigen – ohne Meldung, ohne den echten Check zu verbrauchen. */
function zahlencheckProbe(string $symbol): array
{
    $d = datenLaden();
    $f = beobDaten($d)['firmen'][$symbol] ?? null;
    if ($f === null) {
        return ['ok' => false, 'fehler' => 'Nicht in der Beobachtung.'];
    }
    $zc = zcEinstellung($f);
    if ($zc['us'] === '') {
        return ['ok' => false, 'fehler' => 'Bitte ein US-Kürzel eintragen.'];
    }
    $filing = zcSecFiling($zc['us'], 0);
    if (!$filing) {
        return ['ok' => false, 'fehler' => 'Bei der SEC kein Quartalsbericht (8-K, Item 2.02) gefunden.'];
    }
    $t = zcFilingText($filing);
    $st = ['filing' => ['nummer' => $filing['nummer'], 'zeit' => $filing['zeit'], 'link' => $t['link']], 'zahlen' => zcZahlenAuslesen($t['text'], $zc)];
    $n = zcNachboerse($zc['us']);
    if ($n !== null) {
        $schnitt = zcVolumenSchnitt((array)($f['zc'] ?? []));
        $st['nach'] = $n + ['schnitt' => $schnitt['wert'] ?? null, 'schnitt_art' => $schnitt['art'] ?? '', 'schnitt_tage' => $schnitt['tage'] ?? 0,
            'faktor' => $schnitt ? $n['volumen'] / max(1.0, $schnitt['wert']) : null];
    }
    $a = zcAmpel($zc, $st['zahlen'], $st['nach'] ?? null);
    $probe = ['zeit' => time(), 'text' => zcText($zc, $st, wechselkurse(['USD'])['USD'] ?? null), 'farbe' => $a['farbe']];
    datenAendern(function (array &$d) use ($symbol, $probe): void {
        if (isset($d['beobachtung']['firmen'][$symbol])) {
            $d['beobachtung']['firmen'][$symbol]['zc']['probe'] = $probe;
        }
    });
    return ['ok' => true];
}
