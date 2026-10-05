<?php
declare(strict_types=1);

/*
 * Meine Position in einer Firma: alle Käufe und Verkäufe über alle Depots,
 * Kauflose nach FIFO (wie beim Finanzamt), Rendite je Kauf, realisierte und
 * offene Gewinne, Dividenden und die jährliche Rendite (XIRR). Beträge in Euro.
 */

/** Jährliche Rendite aus Zahlungen [[Zeit, Betrag]] (Einzahlung negativ); null, wenn nicht lösbar. */
function xirr(array $zahlungen): ?float
{
    if (count($zahlungen) < 2) {
        return null;
    }
    $t0 = min(array_column($zahlungen, 0));
    $npv = static function (float $r) use ($zahlungen, $t0): float {
        $s = 0.0;
        foreach ($zahlungen as [$t, $b]) {
            $s += $b / (1 + $r) ** (($t - $t0) / (365.25 * 86400));
        }
        return $s;
    };
    $tief = -0.9999;
    $hoch = 100.0;
    $fTief = $npv($tief);
    $fHoch = $npv($hoch);
    if ($fTief * $fHoch > 0) {
        return null;
    }
    for ($i = 0; $i < 200; $i++) {
        $mitte = ($tief + $hoch) / 2;
        $f = $npv($mitte);
        if (abs($f) < 0.005) {
            return $mitte;
        }
        if ($f * $fTief < 0) {
            $hoch = $mitte;
        } else {
            [$tief, $fTief] = [$mitte, $f];
        }
    }
    return ($tief + $hoch) / 2;
}

/**
 * Alle Daten zu meiner Position. $p = Firmenprofil (für den aktuellen Kurs).
 * Rückgabe null, wenn die Firma nie in einem importierten Depot war.
 */
function positionDetails(array $d, string $symbol, array $p): ?array
{
    $t = verlaufTitel($d)[$symbol] ?? null;
    if ($t === null) {
        return null;
    }
    $w = (string)$p['waehrung'];
    $waehrungen = [$w, 'USD'];
    foreach ($t['et'] as $seg) {
        $waehrungen[] = $seg['waehrung'];
    }
    $fx = wechselkurse($waehrungen);
    $kursEur = $p['kurs'] !== null ? inEuro((float)$p['kurs'], $w, $fx) : null;
    $jetzt = time();

    $lose = [];       // offene Kauflose
    $realisiert = []; // verkaufte Teile mit Kauf- und Verkaufsdatum
    $geschaefte = []; // alles chronologisch
    $dividenden = 0.0;
    $zahlungen = [];  // für XIRR
    $sonstRealisiert = 0.0;

    // --- Trade Republic: Ereignisse in Euro
    foreach ($t['tr'] as $v) {
        $ereignisse = $v['e'];
        // Kapitalmaßnahmen desselben Tages zusammenfassen
        $massnahmen = [];
        foreach ($ereignisse as $i => $e) {
            if (($e[3] ?? '') === 'm') {
                $massnahmen[$e[0]][] = $e;
                unset($ereignisse[$i]);
            }
        }
        foreach ($massnahmen as $datum => $liste) {
            $ereignisse[] = [$datum, 0.0, 0.0, 'mgruppe', $liste];
        }
        usort($ereignisse, static fn(array $a, array $b): int => strcmp($a[0], $b[0]));
        foreach ($ereignisse as $e) {
            $ts = (int)strtotime($e[0] . ' 12:00:00');
            $st = (float)$e[1];
            $geld = (float)$e[2];
            if (($e[3] ?? '') === 'mgruppe') {
                $raus = 0.0;
                $rein = 0.0;
                $reinGeld = 0.0;
                $rausGeld = 0.0;
                foreach ($e[4] as $m) {
                    if ((float)$m[1] < 0) {
                        $raus += -(float)$m[1];
                        $rausGeld += (float)$m[2];
                    } else {
                        $rein += (float)$m[1];
                        $reinGeld += (float)$m[2];
                    }
                }
                $bestand = array_sum(array_column($lose, 'stueck'));
                if ($raus > 0 && $rein > 0 && abs($raus - $bestand) < 1e-6) {
                    // Split oder Umtausch im selben Titel: Lose behalten ihr Kaufdatum
                    $faktor = $rein / $raus;
                    foreach ($lose as &$l) {
                        $l['stueck'] *= $faktor;
                        $l['preis'] /= $faktor;
                    }
                    unset($l);
                } elseif ($rein > 0) {
                    $lose[] = ['zeit' => $ts, 'stueck' => $rein, 'preis' => $reinGeld / $rein, 'quelle' => 'tr', 'massnahme' => true];
                } elseif ($raus > 0) {
                    // ausgebucht: wertlos (kein Geld) oder in einen anderen Titel getauscht
                    $rest = $raus;
                    while ($rest > 1e-9 && $lose !== []) {
                        $l = &$lose[0];
                        $teil = min($rest, $l['stueck']);
                        if ($rausGeld == 0.0) {
                            $realisiert[] = ['kauf' => $l['zeit'], 'verkauf' => $ts, 'stueck' => $teil, 'kaufpreis' => $l['preis'], 'verkaufspreis' => 0.0, 'quelle' => 'tr'];
                        }
                        $l['stueck'] -= $teil;
                        $rest -= $teil;
                        if ($l['stueck'] <= 1e-9) {
                            array_shift($lose);
                        }
                        unset($l);
                    }
                }
                $geschaefte[] = ['zeit' => $ts, 'typ' => 'massnahme', 'stueck' => $rein - $raus, 'betrag' => 0.0, 'kurs' => null, 'quelle' => 'tr'];
                continue;
            }
            if ($st > 0) {
                $lose[] = ['zeit' => $ts, 'stueck' => $st, 'preis' => $geld / $st, 'quelle' => 'tr'];
                $geschaefte[] = ['zeit' => $ts, 'typ' => 'kauf', 'stueck' => $st, 'betrag' => $geld, 'kurs' => $geld / $st, 'quelle' => 'tr'];
                $zahlungen[] = [$ts, -$geld];
            } elseif ($st < 0) {
                $erloes = -$geld;
                $preis = $erloes / -$st;
                $rest = -$st;
                while ($rest > 1e-9 && $lose !== []) {
                    $l = &$lose[0];
                    $teil = min($rest, $l['stueck']);
                    $realisiert[] = ['kauf' => $l['zeit'], 'verkauf' => $ts, 'stueck' => $teil, 'kaufpreis' => $l['preis'], 'verkaufspreis' => $preis, 'quelle' => 'tr'];
                    $l['stueck'] -= $teil;
                    $rest -= $teil;
                    if ($l['stueck'] <= 1e-9) {
                        array_shift($lose);
                    }
                    unset($l);
                }
                $geschaefte[] = ['zeit' => $ts, 'typ' => 'verkauf', 'stueck' => $st, 'betrag' => $erloes, 'kurs' => $preis, 'quelle' => 'tr'];
                $zahlungen[] = [$ts, $erloes];
            } elseif ($geld != 0.0) {
                // Rückzahlung, Abfindung o. Ä. ohne Stückbewegung
                $sonstRealisiert += -$geld;
                $zahlungen[] = [$ts, -$geld];
            }
        }
        foreach ($v['d'] as $dv) {
            $ts = (int)strtotime($dv[0] . ' 12:00:00');
            $dividenden += (float)$dv[1];
            $zahlungen[] = [$ts, (float)$dv[1]];
            $geschaefte[] = ['zeit' => $ts, 'typ' => 'dividende', 'stueck' => 0.0, 'betrag' => (float)$dv[1], 'kurs' => null, 'quelle' => 'tr'];
        }
    }
    foreach ($lose as &$l) {
        $l['offen'] = true;
    }
    unset($l);

    // --- eToro: jede Position ist ein eigenes Los (Beträge in USD, zum heutigen Kurs in Euro)
    $usd = static fn(float $x): float => (float)inEuro($x, 'USD', $fx);
    foreach ($t['et'] as $seg) {
        $einsatz = $usd((float)$seg['betrag']);
        $auf = (int)$seg['auf'];
        $zu = (int)$seg['zu'];
        $einheiten = (float)$seg['einheiten'];
        if ($zu === 0) {
            foreach ($seg['splits'] as [$sz, $sf]) {
                $einheiten *= (float)$sf;
            }
        }
        $kostenEur = inEuro((float)$seg['kosten'], (string)$seg['waehrung'], $fx);
        $kaufpreis = $einheiten > 0 && $kostenEur !== null ? $kostenEur / $einheiten : null;
        $zusatz = ['hebel' => (float)$seg['hebel'], 'short' => $seg['richtung'] < 0];
        $geschaefte[] = ['zeit' => $auf, 'typ' => 'kauf', 'stueck' => $einheiten, 'betrag' => $einsatz, 'kurs' => $kaufpreis, 'quelle' => 'etoro'] + $zusatz;
        $zahlungen[] = [$auf, -$einsatz];
        if ($zu > 0) {
            $erloes = $usd((float)$seg['betrag'] + (float)$seg['gewinn']);
            $realisiert[] = ['kauf' => $auf, 'verkauf' => $zu, 'stueck' => $einheiten, 'einsatz' => $einsatz, 'erloes' => $erloes,
                'kaufpreis' => $kaufpreis, 'verkaufspreis' => null, 'quelle' => 'etoro'] + $zusatz;
            $geschaefte[] = ['zeit' => $zu, 'typ' => 'verkauf', 'stueck' => -$einheiten, 'betrag' => $erloes, 'kurs' => null, 'quelle' => 'etoro'] + $zusatz;
            $zahlungen[] = [$zu, $erloes];
        } else {
            $wert = null;
            if ($kursEur !== null) {
                $wert = $seg['hebel'] <= 1 && $seg['richtung'] > 0 ? $einheiten * $kursEur
                    : $einsatz + $seg['richtung'] * ($einheiten * $kursEur - (float)$kostenEur);
            }
            $lose[] = ['zeit' => $auf, 'stueck' => $einheiten, 'preis' => $kaufpreis, 'einsatz' => $einsatz, 'wert' => $wert, 'quelle' => 'etoro', 'offen' => true] + $zusatz;
        }
    }

    // --- Kennzahlen je Los und gesamt
    $offenWert = 0.0;
    $offenEinstand = 0.0;
    $bestand = 0.0;
    foreach ($lose as &$l) {
        $einstand = $l['einsatz'] ?? $l['stueck'] * (float)$l['preis'];
        $wert = $l['wert'] ?? ($kursEur !== null ? $l['stueck'] * $kursEur : null);
        $l['einstand'] = $einstand;
        $l['wert'] = $wert;
        $l['gewinn'] = $wert !== null ? $wert - $einstand : null;
        $l['rendite'] = $wert !== null && $einstand > 0 ? $wert / $einstand - 1 : null;
        $l['tage'] = (int)floor(($jetzt - $l['zeit']) / 86400);
        $l['jahresrendite'] = $l['rendite'] !== null && $l['tage'] >= 30 ? (1 + $l['rendite']) ** (365.25 / $l['tage']) - 1 : null;
        $offenWert += (float)$wert;
        $offenEinstand += $einstand;
        $bestand += empty($l['short']) ? $l['stueck'] : 0;
    }
    unset($l);
    $realGewinn = $sonstRealisiert;
    foreach ($realisiert as &$r) {
        $einsatz = $r['einsatz'] ?? $r['stueck'] * (float)$r['kaufpreis'];
        $erloes = $r['erloes'] ?? $r['stueck'] * (float)$r['verkaufspreis'];
        $r['einsatz'] = $einsatz;
        $r['erloes'] = $erloes;
        $r['gewinn'] = $erloes - $einsatz;
        $r['rendite'] = $einsatz > 0 ? $erloes / $einsatz - 1 : null;
        $r['tage'] = max(0, (int)floor(($r['verkauf'] - $r['kauf']) / 86400));
        $realGewinn += $r['gewinn'];
    }
    unset($r);
    usort($lose, static fn(array $a, array $b): int => $b['zeit'] <=> $a['zeit']);
    usort($realisiert, static fn(array $a, array $b): int => $b['verkauf'] <=> $a['verkauf']);
    usort($geschaefte, static fn(array $a, array $b): int => $b['zeit'] <=> $a['zeit']);

    $gekauft = array_sum(array_map(static fn(array $g): float => $g['typ'] === 'kauf' ? (float)$g['betrag'] : 0.0, $geschaefte));
    $gesamt = ($offenWert - $offenEinstand) + $realGewinn + $dividenden;
    if ($offenWert > 0) {
        $zahlungen[] = [$jetzt, $offenWert];
    }
    $kaeufe = array_filter($geschaefte, static fn(array $g): bool => $g['typ'] === 'kauf');
    $erster = $kaeufe !== [] ? min(array_column($kaeufe, 'zeit')) : null;
    return [
        'bestand' => $bestand, 'wert' => $offenWert, 'einstand' => $offenEinstand,
        'offen_gewinn' => $offenWert - $offenEinstand, 'offen_rendite' => $offenEinstand > 0 ? $offenWert / $offenEinstand - 1 : null,
        'realisiert' => $realGewinn, 'dividenden' => $dividenden, 'gesamt' => $gesamt,
        'gekauft' => $gekauft, 'gesamt_rendite' => $gekauft > 0 ? $gesamt / $gekauft : null,
        'xirr' => xirr($zahlungen), 'erster' => $erster, 'anzahl_kaeufe' => count($kaeufe),
        'anzahl_verkaeufe' => count(array_filter($geschaefte, static fn(array $g): bool => $g['typ'] === 'verkauf')),
        'lose' => $lose, 'realisiert_liste' => $realisiert, 'geschaefte' => $geschaefte, 'kurs_eur' => $kursEur,
    ];
}
