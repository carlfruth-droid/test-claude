<?php
declare(strict_types=1);

/*
 * „Meine Empfehlung“: dreistufige Aktienanalyse nach dem Plausibilitäts- und
 * Szenariomodell. Die harten Zahlen (Schritt 1 und 2) rechnet die Seite selbst
 * aus Yahoo-Daten; die KI recherchiert dazu und erstellt die drei Szenarien
 * samt Chance-Risiko-Verhältnis (CRV).
 */

/** Kurs am Jahresende aus dem Wochenverlauf (letzter Punkt bis 31.12.). */
function kursZumJahresende(array $punkte, int $jahr): ?float
{
    $grenze = (int)strtotime($jahr . '-12-31 23:59:59');
    $kurs = null;
    foreach ($punkte as [$ts, $v]) {
        if ($ts <= $grenze) {
            $kurs = (float)$v;
        } else {
            break;
        }
    }
    return $kurs !== null && $grenze - $punkte[0][0] > 0 ? $kurs : null;
}

/** Fakten für Schritt 1 und 2 aus dem Firmenprofil. */
function szenarioFakten(array $p): array
{
    $w = (string)$p['waehrung'];
    $bw = (string)($p['bilanzwaehrung'] ?: $w);
    // Umrechnung Bilanzwährung → Handelswährung (für KGV aus Jahresgewinn und Börsenwert)
    $faktor = 1.0;
    if ($bw !== $w && $bw !== '' && $w !== '') {
        $fx = wechselkurse([$bw, $w]);
        $a = inEuro(1.0, $bw, $fx);
        $b = inEuro(1.0, $w, $fx);
        $faktor = $a && $b ? $a / $b : 0.0;
    }

    // Umsatz und Gewinn je Jahr mit Wachstum und Nettomarge
    $jahre = [];
    $vor = null;
    foreach ($p['jahre'] as $j) {
        $zeile = ['jahr' => $j['jahr'], 'umsatz' => $j['umsatz'], 'gewinn' => $j['gewinn'],
            'marge' => $j['umsatz'] && $j['gewinn'] !== null ? $j['gewinn'] / $j['umsatz'] : null,
            'umsatz_wachstum' => $vor && $vor['umsatz'] && $j['umsatz'] !== null ? $j['umsatz'] / $vor['umsatz'] - 1 : null,
            'gewinn_wachstum' => $vor && $vor['gewinn'] && $j['gewinn'] !== null && $vor['gewinn'] > 0 ? $j['gewinn'] / $vor['gewinn'] - 1 : null];
        $jahre[] = $zeile;
        $vor = $j;
    }

    // Historisches KGV (Näherung): Kurs am Jahresende × heutige Aktienzahl ÷ Jahresgewinn
    $kgvHist = [];
    if ($p['aktien'] && $faktor > 0) {
        foreach ($p['jahre'] as $j) {
            $kurs = ctype_digit((string)$j['jahr']) ? kursZumJahresende($p['verlauf_5j'], (int)$j['jahr']) : null;
            if ($kurs !== null && $j['gewinn'] !== null && $j['gewinn'] > 0) {
                $kgvHist[(string)$j['jahr']] = $kurs * $p['aktien'] / ($j['gewinn'] * $faktor);
            }
        }
    }
    $kgvSchnitt = $kgvHist !== [] ? array_sum($kgvHist) / count($kgvHist) : null;

    // Erwartetes KGV für dieses und nächstes Geschäftsjahr aus dem Analysten-Konsens
    $fwd = [];
    foreach (['0y' => 'dieses Jahr', '+1y' => 'nächstes Jahr'] as $k => $titel) {
        $sz = $p['schaetzungen'][$k] ?? null;
        if ($sz !== null && $sz['eps'] && $sz['eps'] > 0 && $p['kurs']) {
            $kgv = $p['kurs'] / $sz['eps'];
            // Plausibilität: weicht es extrem vom Yahoo-Forward-KGV ab, stimmt meist die Währung nicht
            if ($p['kgv_erw'] === null || ($kgv / $p['kgv_erw'] < 4 && $kgv / $p['kgv_erw'] > 0.25)) {
                $fwd[$k] = ['titel' => $titel, 'ende' => $sz['ende'], 'kgv' => $kgv, 'eps' => $sz['eps']];
            }
        }
    }

    $nettoSchulden = $p['schulden'] !== null && $p['cash'] !== null ? $p['schulden'] - $p['cash'] : null;
    $verhaeltnis = $nettoSchulden !== null && $p['ebitda'] && $p['ebitda'] > 0 ? $nettoSchulden / $p['ebitda'] : null;
    return [
        'waehrung' => $w, 'bilanzwaehrung' => $bw, 'jahre' => $jahre,
        'bruttomarge' => $p['bruttomarge'], 'opmarge' => $p['opmarge'], 'nettomarge' => $p['nettomarge'],
        'schaetzungen' => $p['schaetzungen'], 'netto_schulden' => $nettoSchulden, 'ebitda' => $p['ebitda'],
        'schulden_ebitda' => $verhaeltnis, 'kgv' => $p['kgv'], 'kgv_erw' => $p['kgv_erw'], 'kgv_fwd' => $fwd,
        'kgv_hist' => $kgvHist, 'kgv_schnitt' => $kgvSchnitt, 'kurs' => $p['kurs'],
        'tief52' => $p['tief52'], 'hoch52' => $p['hoch52'], 'gd200' => $p['gd200'],
        'kursziel' => $p['kursziel'], 'kursziel_tief' => $p['kursziel_tief'], 'kursziel_hoch' => $p['kursziel_hoch'],
    ];
}

/** Die Fakten als Textblock für die KI. */
function szenarioFaktenText(array $f): string
{
    $w = $f['waehrung'];
    $bw = $f['bilanzwaehrung'];
    $z = [];
    foreach ($f['jahre'] as $j) {
        $z[] = '- Geschäftsjahr ' . $j['jahr'] . ': Umsatz ' . gross($j['umsatz'], $bw) . ($j['umsatz_wachstum'] !== null ? ' (' . proz($j['umsatz_wachstum'], 1, true) . ')' : '')
            . ', Gewinn ' . gross($j['gewinn'], $bw) . ', Nettomarge ' . proz($j['marge']);
    }
    foreach ($f['schaetzungen'] as $k => $s) {
        $z[] = '- Analysten-Konsens ' . ($k === '0y' ? 'laufendes' : 'nächstes') . ' Geschäftsjahr (bis ' . $s['ende'] . '): Umsatz ' . gross($s['umsatz'], $bw)
            . ($s['umsatz_wachstum'] !== null ? ' (' . proz($s['umsatz_wachstum'], 1, true) . ')' : '') . ', Gewinn je Aktie ' . zahl($s['eps'], 2) . ' ' . $w
            . ($s['analysten'] > 0 ? ' (' . $s['analysten'] . ' Analysten)' : '');
    }
    $z[] = '- Bruttomarge ' . proz($f['bruttomarge']) . ', operative Marge ' . proz($f['opmarge']) . ', Nettomarge ' . proz($f['nettomarge']) . ' (jeweils letzte 12 Monate)';
    $z[] = '- Nettoverschuldung ' . gross($f['netto_schulden'], $bw) . ', EBITDA ' . gross($f['ebitda'], $bw) . ', Nettoverschuldung/EBITDA ' . zahl($f['schulden_ebitda'], 2);
    $z[] = '- KGV (letzte 12 Monate) ' . zahl($f['kgv'], 1) . ', Forward-KGV laut Yahoo ' . zahl($f['kgv_erw'], 1);
    foreach ($f['kgv_fwd'] as $x) {
        $z[] = '- KGV ' . $x['titel'] . ' (Geschäftsjahr bis ' . $x['ende'] . ') auf Konsens-Basis: ' . zahl($x['kgv'], 1);
    }
    if ($f['kgv_hist'] !== []) {
        $z[] = '- Historisches KGV (Näherung aus Jahresendkurs und Jahresgewinn): ' . implode(', ', array_map(static fn($j, $k): string => $j . ': ' . zahl($k, 1), array_keys($f['kgv_hist']), $f['kgv_hist']))
            . ' – Durchschnitt ' . zahl($f['kgv_schnitt'], 1);
    }
    $z[] = '- Kurs ' . geld($f['kurs'], $w) . ', 52-Wochen-Spanne ' . geld($f['tief52'], $w) . ' bis ' . geld($f['hoch52'], $w) . ', 200-Tage-Linie ' . geld($f['gd200'], $w);
    $z[] = '- Analysten-Kursziele: Durchschnitt ' . geld($f['kursziel'], $w) . ', Spanne ' . geld($f['kursziel_tief'], $w) . ' bis ' . geld($f['kursziel_hoch'], $w);
    return implode("\n", array_filter($z, static fn(string $x): bool => !preg_match('/: –(,|$)/', $x)));
}

/** Auftrag an die KI – der Prompt des Nutzers, ergänzt um Daten und ein festes Ausgabeformat. */
function szenarioAuftrag(array $firma, array $p, array $fakten): array
{
    $system = 'Du bist ein erfahrener, nüchterner Aktienanalyst und schreibst für einen Privatanleger aus Deutschland. '
        . 'Recherchiere im Web aktuelle, belastbare Zahlen (Geschäftsberichte, Investor Relations, Analysten-Konsens, seriöse Finanzmedien). '
        . 'Schreibe auf Deutsch, präzise und mit konkreten Zahlen samt Stand. Keine vagen Floskeln, keine unbegründeten Kursziele. '
        . 'Was du nicht belegen kannst, kennzeichnest du offen als unsicher oder geschätzt.';
    $name = $p['name'] . ' (' . $p['symbol'] . ($firma['isin'] !== '' ? ', ISIN ' . $firma['isin'] : '') . ')';
    $w = (string)$p['waehrung'];
    $auftrag = 'Führe für mich eine strukturierte, dreistufige Aktienanalyse nach dem Plausibilitäts- und Szenariomodell durch.' . "\n\n"
        . 'Die Aktie, die ich analysieren möchte, ist: ' . $name . "\n\n"
        . 'Diese Zahlen liegen bereits vor (Yahoo Finance, Stand ' . date('d.m.Y') . '). Prüfe sie, ergänze fehlende per Recherche und weise auf Widersprüche hin:' . "\n"
        . szenarioFaktenText($fakten) . "\n\n"
        . "Bitte gliedere deine Analyse streng in die folgenden 3 Schritte auf und verzichte auf vage, pauschale Floskeln oder unbegründete Kursziele:\n\n"
        . "## Schritt 1: Das operative Fundament (Die Realität)\n"
        . "- Analysiere die Umsatz- und Gewinnentwicklung der letzten 3 Jahre sowie die aktuelle Analysten-Umsatzprognose. Ist das Wachstum stabil?\n"
        . "- Wie hat sich die Brutto- und Nettomarge entwickelt? Zeigt sich hier Preismacht oder Margendruck?\n"
        . "- Prüfe die finanzielle Stabilität: Wie hoch ist die Nettoverschuldung im Verhältnis zum EBITDA? Liegt sie im gesunden Bereich (unter 3)?\n\n"
        . "## Schritt 2: Die Erwartungshaltung (Der Bewertungs-Check)\n"
        . "- Nenne das aktuelle KGV sowie das erwartete KGV (Forward KGV) für die nächsten 2 Jahre auf Basis des aktuellen Analysten-Konsensus.\n"
        . "- Vergleiche diese Werte mit dem historischen Durchschnitts-KGV dieses Unternehmens der letzten 5 Jahre.\n"
        . "- Fazit zu Schritt 2: Ist die Aktie im historischen Vergleich aktuell unterbewertet, fair bewertet oder ist bereits extrem viel zukünftiges Wachstum eingepreist?\n\n"
        . "## Schritt 3: Das Drei-Szenarien-Modell (12–24 Monate Horizont)\n"
        . "Erstelle eine tabellarische Übersicht (Markdown-Tabelle mit den Spalten Szenario | Annahmen und Begründung | Kursziel | Rendite) für drei realistische Szenarien inklusive konkreter fundamentaler Begründungen und schätze für jedes Szenario die potenzielle Rendite/Kursentwicklung in Prozent ab:\n"
        . "1. Bären-Szenario (Worst Case): Was passiert bei einer Enttäuschung oder Rezession? Wo liegt fundamental/charttechnisch eine starke Unterstützung?\n"
        . "2. Basis-Szenario (Likely Case): Was passiert, wenn das Unternehmen exakt die aktuellen Markterwartungen erfüllt?\n"
        . "3. Bullen-Szenario (Best Case): Was passiert, wenn das Unternehmen die Erwartungen deutlich übertrifft (z. B. durch neue Produkte oder Margenexpansion)?\n\n"
        . "## Fazit: Chance-Risiko-Verhältnis\n"
        . "Berechne das Chance-Risiko-Verhältnis (CRV) basierend auf dem Bullen- und Bären-Szenario. Lohnt sich ein Einstieg mathematisch (ist das Aufwärtspotenzial mindestens doppelt so groß wie das Abwärtsrisiko)?\n\n"
        . 'Format: Verwende genau diese vier Überschriften (##), Stichpunkte mit „- “ und **…** für wichtige Zahlen. Kursziele in ' . $w
        . ' bezogen auf den aktuellen Kurs von ' . geld($p['kurs'], $w) . '. Höchstens etwa 900 Wörter, keine Einleitung, keine Quellenliste.' . "\n"
        . 'Schließe mit einem JSON-Block in ```json … ``` ab, exakt in dieser Form (Zahlen ohne Einheiten, Rendite in Prozent):' . "\n"
        . '{"baer":{"kurs":0,"rendite":0,"unterstuetzung":0},"basis":{"kurs":0,"rendite":0},"bulle":{"kurs":0,"rendite":0},'
        . '"crv":0,"bewertung":"unterbewertet|fair|teuer","einstieg":"ja|nein|grenzwertig","kurz":"ein Satz Fazit"}';
    return [$system, $auftrag];
}

/** JSON-Block am Ende der Antwort lesen und aus dem Text entfernen. */
function szenarioJson(string $text): array
{
    $daten = null;
    if (preg_match_all('/```(?:json)?\s*(\{.*?\})\s*```/s', $text, $m, PREG_SET_ORDER)) {
        $letzter = end($m);
        $daten = json_decode($letzter[1], true);
        $text = trim(str_replace($letzter[0], '', $text));
    }
    if (!is_array($daten)) {
        return [$text, null];
    }
    $zahl = static fn($v): ?float => is_numeric($v) ? (float)$v : null;
    $s = [];
    foreach (['baer', 'basis', 'bulle'] as $k) {
        $s[$k] = ['kurs' => $zahl($daten[$k]['kurs'] ?? null), 'rendite' => $zahl($daten[$k]['rendite'] ?? null)];
    }
    $s['baer']['unterstuetzung'] = $zahl($daten['baer']['unterstuetzung'] ?? null);
    // CRV selbst nachrechnen: Chance (Bulle) ÷ Risiko (Bär)
    $crv = $s['bulle']['rendite'] !== null && $s['baer']['rendite'] !== null && $s['baer']['rendite'] < 0
        ? $s['bulle']['rendite'] / abs($s['baer']['rendite']) : $zahl($daten['crv'] ?? null);
    $s['crv'] = $crv !== null ? round($crv, 2) : null;
    $s['bewertung'] = in_array($daten['bewertung'] ?? '', ['unterbewertet', 'fair', 'teuer'], true) ? $daten['bewertung'] : '';
    $s['einstieg'] = in_array($daten['einstieg'] ?? '', ['ja', 'nein', 'grenzwertig'], true) ? $daten['einstieg'] : '';
    $s['kurz'] = mb_substr(trim((string)($daten['kurz'] ?? '')), 0, 300);
    return [$text, $s];
}

function szenarioAnalyse(array $firma, array $p, string $modell): array
{
    $fakten = szenarioFakten($p);
    [$system, $auftrag] = szenarioAuftrag($firma, $p, $fakten);
    $erg = kiLauf($system, $auftrag, $modell, 8);
    if (!$erg['ok']) {
        return $erg;
    }
    [$erg['text'], $werte] = szenarioJson($erg['text']);
    return $erg + ['werte' => $werte, 'kurs' => $p['kurs'], 'waehrung' => (string)$p['waehrung']];
}

/** Die wichtigsten Wettbewerber mit Marktstellung (kurze KI-Recherche). */
function wettbewerberAnalyse(array $firma, array $p, string $modell): array
{
    $system = 'Du bist ein nüchterner Branchenanalyst und schreibst für einen Privatanleger aus Deutschland. Recherchiere im Web aktuelle, belastbare Angaben. '
        . 'Schreibe auf Deutsch, knapp, mit konkreten Zahlen samt Stand. Unsichere Angaben kennzeichnest du.';
    $auftrag = 'Firma: ' . $p['name'] . ' (' . $p['symbol'] . ($firma['isin'] !== '' ? ', ISIN ' . $firma['isin'] : '') . ($p['branche'] !== '' ? ', Branche ' . $p['branche'] : '') . ")\n\n"
        . "Nenne die 4 bis 8 wichtigsten direkten Wettbewerber – nach Geschäftsbereichen, falls die Firma mehrere hat.\n"
        . "Gib eine Markdown-Tabelle mit den Spalten: Wettbewerber | Land | Börsenkürzel | Umsatz (letztes Geschäftsjahr) | Überschneidung / Marktstellung.\n"
        . "Danach unter ## Marktstellung 3 bis 5 Stichpunkte: Marktanteile, wo die Firma führt, wo sie hinterherläuft, und welche neuen Konkurrenten oder Ersatzprodukte drohen.\n"
        . 'Höchstens etwa 350 Wörter, keine Einleitung, keine Quellenliste.';
    return kiLauf($system, $auftrag, $modell, 4);
}
