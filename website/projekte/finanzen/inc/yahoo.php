<?php
declare(strict_types=1);

/*
 * Yahoo Finance: Suche, Kurse, Kursverlauf, Kennzahlen, Aktionäre.
 * Die Schnittstelle ist inoffiziell – deshalb alles mit Zwischenspeicher und
 * Ausweichwegen, damit die Seite bei Störungen mit dem letzten Stand weiterläuft.
 */

const FZ_YAHOO_MODULE = 'price,summaryDetail,financialData,defaultKeyStatistics,assetProfile,majorHoldersBreakdown,'
    . 'institutionOwnership,fundOwnership,insiderHolders,insiderTransactions,recommendationTrend,calendarEvents,earnings,upgradeDowngradeHistory,earningsTrend';

function yahooCookieDatei(): string
{
    return fzPfad('yahoo-cookies.txt');
}

/**
 * Sitzungsschlüssel („Crumb“), den Yahoo für Kennzahlen verlangt. Wird 6 Std.
 * aufgehoben. Von Servern in der EU schaltet Yahoo teils eine Zustimmungsseite
 * davor – die wird dann automatisch bestätigt.
 */
function yahooCrumb(bool $erneuern = false): string
{
    $datei = fzPfad('yahoo-crumb.json');
    $c = jsonLesen($datei);
    if (!$erneuern && (string)($c['crumb'] ?? '') !== '' && time() - (int)($c['zeit'] ?? 0) < 6 * 3600) {
        return (string)$c['crumb'];
    }
    if (!$erneuern && (int)($c['fehlschlag'] ?? 0) > time() - 300) {
        return ''; // nach einem Fehlschlag 5 Minuten Ruhe
    }
    $jar = yahooCookieDatei();
    @unlink($jar);
    http('https://fc.yahoo.com/', ['ua' => 'browser', 'cookies_speichern' => $jar, 'zeit' => 15]);
    $crumb = yahooCrumbAbholen($jar);
    $weg = 'standard';
    if ($crumb === '' && yahooEuZustimmung($jar)) {
        $crumb = yahooCrumbAbholen($jar);
        $weg = 'eu-zustimmung';
    }
    jsonSchreiben($datei, $crumb !== ''
        ? ['crumb' => $crumb, 'zeit' => time(), 'weg' => $weg]
        : ['crumb' => '', 'zeit' => 0, 'fehlschlag' => time()]);
    return $crumb;
}

function yahooCrumbAbholen(string $jar): string
{
    foreach (['query1', 'query2'] as $host) {
        $r = http('https://' . $host . '.finance.yahoo.com/v1/test/getcrumb', ['ua' => 'browser', 'cookies' => $jar, 'zeit' => 15]);
        $t = trim($r['body']);
        if ($r['code'] === 200 && $t !== '' && strlen($t) < 40 && !preg_match('/[<\s]/', $t)) {
            return $t;
        }
    }
    return '';
}

function yahooEuZustimmung(string $jar): bool
{
    $r = http('https://finance.yahoo.com/', ['ua' => 'browser', 'cookies_speichern' => $jar, 'zeit' => 20]);
    if (!preg_match('~(guce|consent)\.yahoo\.com~', $r['url'] . ' ' . substr($r['body'], 0, 4000))) {
        return false;
    }
    if (!preg_match('/name="csrfToken"\s+value="([^"]+)"/', $r['body'], $csrf)
        || !preg_match('/name="sessionId"\s+value="([^"]+)"/', $r['body'], $sid)) {
        return false;
    }
    $post = 'agree=agree&agree=agree&consentUUID=default'
        . '&sessionId=' . rawurlencode($sid[1]) . '&csrfToken=' . rawurlencode($csrf[1])
        . '&originalDoneUrl=' . rawurlencode('https://finance.yahoo.com/') . '&namespace=yahoo';
    http('https://consent.yahoo.com/v2/collectConsent?sessionId=' . rawurlencode($sid[1]), [
        'ua' => 'browser', 'cookies_speichern' => $jar, 'post' => $post, 'zeit' => 20,
        'kopf' => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    http('https://guce.yahoo.com/copyConsent?sessionId=' . rawurlencode($sid[1]), ['ua' => 'browser', 'cookies_speichern' => $jar, 'zeit' => 20]);
    return true;
}

function mitCrumb(string $url, string $crumb): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . 'crumb=' . rawurlencode($crumb);
}

/** Abruf, der den Crumb braucht – bei „ungültig“ einmal mit frischem Crumb wiederholen. */
function yahooCrumbJson(string $url): ?array
{
    $crumb = yahooCrumb();
    if ($crumb === '') {
        return null;
    }
    $r = http(mitCrumb($url, $crumb), ['ua' => 'browser', 'cookies' => yahooCookieDatei()]);
    if ($r['code'] === 401 || $r['code'] === 403) {
        $crumb = yahooCrumb(true);
        if ($crumb === '') {
            return null;
        }
        $r = http(mitCrumb($url, $crumb), ['ua' => 'browser', 'cookies' => yahooCookieDatei()]);
    }
    return httpJson($r);
}

// ---------------------------------------------------------------------------
// Suche
// ---------------------------------------------------------------------------

/** Viele Suchen auf einmal in den Zwischenspeicher laden (z. B. alle ISINs eines Depot-Imports). */
function yahooSucheVorladen(array $begriffe, array $typen): void
{
    $offen = [];
    foreach (array_unique($begriffe) as $q) {
        $c = cacheLesen('suche:' . implode(',', $typen) . ':' . mb_strtolower($q), 86400);
        if ($c === null || !$c['frisch']) {
            $offen[] = $q;
        }
    }
    foreach (array_chunk($offen, 8) as $teil) {
        $anfragen = [];
        foreach ($teil as $q) {
            $anfragen[$q] = ['url' => 'https://query2.finance.yahoo.com/v1/finance/search?q=' . rawurlencode($q)
                . '&quotesCount=12&newsCount=0&listsCount=0&lang=de-DE&region=DE', 'ua' => 'browser', 'zeit' => 12];
        }
        foreach (httpViele($anfragen) as $q => $r) {
            $j = httpJson($r);
            if ($j === null) {
                continue;
            }
            $liste = [];
            foreach (($j['quotes'] ?? []) as $x) {
                if (in_array((string)($x['quoteType'] ?? ''), $typen, true) && !empty($x['symbol'])) {
                    $liste[] = ['symbol' => (string)$x['symbol'], 'name' => (string)($x['longname'] ?? $x['shortname'] ?? $x['symbol']),
                        'boerse' => (string)($x['exchDisp'] ?? $x['exchange'] ?? ''), 'branche' => (string)($x['industryDisp'] ?? $x['industry'] ?? ''),
                        'typ' => (string)($x['quoteType'] ?? '')];
                }
            }
            cacheSchreiben('suche:' . implode(',', $typen) . ':' . mb_strtolower((string)$q), $liste);
        }
    }
}

/** Suche nach Aktien (Standard) oder weiteren Arten wie ETF/Fonds (für Depot-Positionen). */
function yahooSuche(string $q, array $typen = ['EQUITY']): array
{
    $q = trim($q);
    if ($q === '') {
        return [];
    }
    $liste = gecacht('suche:' . implode(',', $typen) . ':' . mb_strtolower($q), 86400, function () use ($q, $typen): ?array {
        $j = httpJson(http('https://query2.finance.yahoo.com/v1/finance/search?q=' . rawurlencode($q)
            . '&quotesCount=12&newsCount=0&listsCount=0&lang=de-DE&region=DE', ['ua' => 'browser', 'zeit' => 12]));
        if ($j === null) {
            return null;
        }
        $liste = [];
        foreach (($j['quotes'] ?? []) as $x) {
            if (!in_array((string)($x['quoteType'] ?? ''), $typen, true) || empty($x['symbol'])) {
                continue;
            }
            $liste[] = [
                'symbol'  => (string)$x['symbol'],
                'name'    => (string)($x['longname'] ?? $x['shortname'] ?? $x['symbol']),
                'boerse'  => (string)($x['exchDisp'] ?? $x['exchange'] ?? ''),
                'branche' => (string)($x['industryDisp'] ?? $x['industry'] ?? ''),
                'typ'     => (string)($x['quoteType'] ?? ''),
            ];
        }
        return $liste;
    });
    return is_array($liste) ? $liste : [];
}

// ---------------------------------------------------------------------------
// Kurse (viele Symbole auf einmal) und Verlauf
// ---------------------------------------------------------------------------

function kursAusV7(array $q): array
{
    $kurs = isset($q['regularMarketPrice']) ? (float)$q['regularMarketPrice'] : null;
    $proz = isset($q['regularMarketChangePercent']) ? (float)$q['regularMarketChangePercent'] / 100 : null;
    return [
        'kurs' => $kurs,
        'aend' => isset($q['regularMarketChange']) ? (float)$q['regularMarketChange'] : null,
        'aend_proz' => $proz,
        'waehrung' => (string)($q['currency'] ?? ''),
        'zeit' => (int)($q['regularMarketTime'] ?? 0),
        'status' => (string)($q['marketState'] ?? ''),
        'name' => (string)($q['longName'] ?? $q['shortName'] ?? ''),
        'boerse' => (string)($q['fullExchangeName'] ?? $q['exchange'] ?? ''),
    ];
}

function kursAusSpark(array $sp): array
{
    // Zwei bekannte Antwortformen: flach je Symbol oder mit „response[0].meta“
    $meta = $sp['response'][0]['meta'] ?? [];
    $schluss = $sp['close'] ?? ($sp['response'][0]['indicators']['quote'][0]['close'] ?? []);
    $kurs = null;
    foreach (array_reverse(is_array($schluss) ? $schluss : []) as $v) {
        if (is_numeric($v)) {
            $kurs = (float)$v;
            break;
        }
    }
    $kurs ??= isset($meta['regularMarketPrice']) ? (float)$meta['regularMarketPrice'] : (isset($sp['fulldayPrice']) ? (float)$sp['fulldayPrice'] : null);
    $vortag = $sp['chartPreviousClose'] ?? $sp['previousClose'] ?? $meta['chartPreviousClose'] ?? $meta['previousClose'] ?? null;
    $vortag = is_numeric($vortag) ? (float)$vortag : null;
    $zeiten = $sp['timestamp'] ?? ($sp['response'][0]['timestamp'] ?? []);
    return [
        'kurs' => $kurs,
        'aend' => ($kurs !== null && $vortag) ? $kurs - $vortag : null,
        'aend_proz' => ($kurs !== null && $vortag) ? ($kurs - $vortag) / $vortag : null,
        'waehrung' => (string)($meta['currency'] ?? ''),
        'zeit' => (int)(is_array($zeiten) && $zeiten !== [] ? end($zeiten) : 0),
        'status' => '',
        'name' => '',
        'boerse' => '',
    ];
}

/** Aktuelle Kurse: [symbol => ['kurs', 'aend', 'aend_proz', 'waehrung', 'zeit', …]] (4 Min. gespeichert) */
function yahooKurse(array $symbole): array
{
    $symbole = array_values(array_unique(array_filter(array_map('strval', $symbole))));
    $erg = [];
    $fehlend = [];
    foreach ($symbole as $s) {
        $c = cacheLesen('kurs:' . $s, 240);
        if ($c !== null && $c['frisch']) {
            $erg[$s] = $c['wert'];
        } else {
            $fehlend[] = $s;
        }
    }
    foreach (array_chunk($fehlend, 40) as $teil) {
        $gefunden = [];
        $j = yahooCrumbJson('https://query1.finance.yahoo.com/v7/finance/quote?symbols=' . rawurlencode(implode(',', $teil)));
        foreach (($j['quoteResponse']['result'] ?? []) as $q) {
            $s = (string)($q['symbol'] ?? '');
            $k = kursAusV7($q);
            if ($s !== '' && $k['kurs'] !== null) {
                $erg[$s] = $k;
                $gefunden[$s] = true;
                cacheSchreiben('kurs:' . $s, $k);
            }
        }
        $rest = array_values(array_filter($teil, static fn(string $s): bool => !isset($gefunden[$s])));
        if ($rest !== []) {
            // Ausweichweg ohne Crumb
            $j2 = httpJson(http('https://query1.finance.yahoo.com/v8/finance/spark?symbols=' . rawurlencode(implode(',', $rest))
                . '&range=1d&interval=1d', ['ua' => 'browser', 'zeit' => 15]));
            $karte = [];
            foreach (($j2['spark']['result'] ?? []) as $res) {
                $karte[(string)($res['symbol'] ?? '')] = $res;
            }
            foreach ($rest as $s) {
                $sp = $karte[$s] ?? ($j2[$s] ?? null);
                if (is_array($sp)) {
                    $k = kursAusSpark($sp);
                    if ($k['kurs'] !== null) {
                        $alt = cacheLesen('kurs:' . $s, PHP_INT_MAX);
                        $k['waehrung'] = $k['waehrung'] !== '' ? $k['waehrung'] : (string)($alt['wert']['waehrung'] ?? '');
                        $erg[$s] = $k;
                        cacheSchreiben('kurs:' . $s, $k);
                    }
                }
            }
        }
    }
    foreach ($fehlend as $s) {
        if (!isset($erg[$s])) {
            $c = cacheLesen('kurs:' . $s, 0);
            if ($c !== null) {
                $erg[$s] = $c['wert'] + ['veraltet' => true];
            }
        }
    }
    return $erg;
}

/** Veränderung über 5 Handelstage je Symbol (Anteil, z. B. 0.021), eine Stunde zwischengespeichert. */
function yahooWoche(array $symbole): array
{
    $symbole = array_values(array_unique(array_filter(array_map('strval', $symbole))));
    $erg = [];
    $fehlend = [];
    foreach ($symbole as $s) {
        $c = cacheLesen('woche:' . $s, 3600);
        if ($c !== null && $c['frisch']) {
            $erg[$s] = $c['wert'];
        } else {
            $fehlend[] = $s;
        }
    }
    $anfragen = [];
    foreach (array_chunk($fehlend, 20) as $i => $teil) {
        $anfragen[$i] = ['url' => 'https://query1.finance.yahoo.com/v8/finance/spark?symbols=' . rawurlencode(implode(',', $teil))
            . '&range=5d&interval=1d', 'ua' => 'browser', 'zeit' => 15];
    }
    foreach (httpViele($anfragen) as $r) {
        $j = $r['code'] === 200 ? json_decode($r['body'], true) : null;
        if (!is_array($j)) {
            continue;
        }
        $karte = [];
        foreach (($j['spark']['result'] ?? []) as $res) {
            $karte[(string)($res['symbol'] ?? '')] = $res;
        }
        foreach ($karte + $j as $s => $sp) {
            if (!is_array($sp) || !in_array((string)$s, $fehlend, true) || isset($erg[$s])) {
                continue;
            }
            // Mit range=5d ist der „Vortag“ der Schluss vor fünf Handelstagen
            $proz = kursAusSpark($sp)['aend_proz'];
            $erg[(string)$s] = $proz;
            cacheSchreiben('woche:' . $s, $proz);
        }
    }
    return $erg;
}

function verlaufAuswerten(array $j): ?array
{
    $res = $j['chart']['result'][0] ?? null;
    if (!is_array($res)) {
        return null;
    }
    $zeiten = $res['timestamp'] ?? [];
    $schluss = $res['indicators']['quote'][0]['close'] ?? [];
    $punkte = [];
    foreach ($zeiten as $i => $ts) {
        $v = $schluss[$i] ?? null;
        if (is_numeric($v)) {
            $punkte[] = [(int)$ts, round((float)$v, 4)];
        }
    }
    $div = [];
    foreach (($res['events']['dividends'] ?? []) as $dv) {
        if (isset($dv['date'], $dv['amount'])) {
            $div[] = [(int)$dv['date'], (float)$dv['amount']];
        }
    }
    usort($div, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
    $m = $res['meta'] ?? [];
    return [
        'punkte' => $punkte,
        'div' => $div,
        'meta' => [
            'kurs' => isset($m['regularMarketPrice']) ? (float)$m['regularMarketPrice'] : null,
            'vortag' => isset($m['chartPreviousClose']) ? (float)$m['chartPreviousClose'] : null,
            'waehrung' => (string)($m['currency'] ?? ''),
            'boerse' => (string)($m['fullExchangeName'] ?? $m['exchangeName'] ?? ''),
            'name' => (string)($m['longName'] ?? $m['shortName'] ?? ''),
            'hoch52' => isset($m['fiftyTwoWeekHigh']) ? (float)$m['fiftyTwoWeekHigh'] : null,
            'tief52' => isset($m['fiftyTwoWeekLow']) ? (float)$m['fiftyTwoWeekLow'] : null,
            'zeit' => (int)($m['regularMarketTime'] ?? 0),
        ],
    ];
}

function verlaufUrl(string $symbol, string $spanne): string
{
    $intervall = $spanne === '5y' ? '1wk' : '1d';
    return 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($symbol)
        . '?range=' . $spanne . '&interval=' . $intervall . '&events=div&includePrePost=false';
}

// ---------------------------------------------------------------------------
// Alles für den Firmen-Steckbrief – parallel geladen, einzeln gespeichert
// ---------------------------------------------------------------------------

/**
 * Lädt Kennzahlen (6 Std.), Kurs (4 Min.), 2-Jahres-Verlauf täglich (1 Std.) und
 * 5-Jahres-Verlauf wöchentlich (1 Tag) – nur das, was nicht mehr frisch ist, und parallel.
 */
function yahooBuendel(string $symbol): array
{
    $teile = [
        'qs' => ['cache' => 'qs:' . $symbol, 'alter' => 6 * 3600],
        'kurs' => ['cache' => 'kurs:' . $symbol, 'alter' => 240],
        'v1' => ['cache' => 'verlauf:' . $symbol . ':2y', 'alter' => 3600],
        'v5' => ['cache' => 'verlauf:' . $symbol . ':5y', 'alter' => 86400],
    ];
    $erg = [];
    $alt = [];
    $anfragen = [];
    $crumb = null;
    foreach ($teile as $k => $t) {
        $c = cacheLesen($t['cache'], $t['alter']);
        if ($c !== null && $c['frisch']) {
            $erg[$k] = $c['wert'];
            continue;
        }
        $alt[$k] = $c['wert'] ?? null;
        if ($k === 'qs' || $k === 'kurs') {
            $crumb ??= yahooCrumb();
            if ($crumb === '') {
                continue;
            }
            $url = $k === 'qs'
                ? 'https://query1.finance.yahoo.com/v10/finance/quoteSummary/' . rawurlencode($symbol) . '?modules=' . FZ_YAHOO_MODULE
                : 'https://query1.finance.yahoo.com/v7/finance/quote?symbols=' . rawurlencode($symbol);
            $anfragen[$k] = ['url' => mitCrumb($url, $crumb), 'ua' => 'browser', 'cookies' => yahooCookieDatei(), 'zeit' => 20];
        } else {
            $anfragen[$k] = ['url' => verlaufUrl($symbol, $k === 'v5' ? '5y' : '2y'), 'ua' => 'browser', 'zeit' => 20];
        }
    }
    $antworten = httpViele($anfragen);
    // Crumb abgelaufen? Einmal erneuern und die betroffenen Teile wiederholen.
    $nochmal = [];
    foreach (['qs', 'kurs'] as $k) {
        if (isset($antworten[$k]) && in_array($antworten[$k]['code'], [401, 403], true)) {
            $nochmal[] = $k;
        }
    }
    if ($nochmal !== []) {
        $crumb = yahooCrumb(true);
        if ($crumb !== '') {
            foreach ($nochmal as $k) {
                $url = preg_replace('/([?&])crumb=[^&]*/', '$1crumb=' . rawurlencode($crumb), $anfragen[$k]['url']);
                $antworten[$k] = http((string)$url, ['ua' => 'browser', 'cookies' => yahooCookieDatei()]);
            }
        }
    }
    foreach ($antworten as $k => $r) {
        $j = httpJson($r);
        $wert = null;
        if ($j !== null) {
            if ($k === 'qs') {
                $wert = $j['quoteSummary']['result'][0] ?? null;
            } elseif ($k === 'kurs') {
                $q = $j['quoteResponse']['result'][0] ?? null;
                $wert = is_array($q) ? kursAusV7($q) : null;
                if ($wert !== null && $wert['kurs'] === null) {
                    $wert = null;
                }
            } else {
                $wert = verlaufAuswerten($j);
            }
        }
        if ($wert !== null) {
            cacheSchreiben($teile[$k]['cache'], $wert);
            $erg[$k] = $wert;
        } elseif ($alt[$k] !== null) {
            $erg[$k] = $alt[$k];
        }
    }
    foreach ($alt as $k => $w) {
        if (!isset($erg[$k]) && $w !== null) {
            $erg[$k] = $w;
        }
    }
    return $erg;
}

/**
 * Nur der Kursverlauf (2 Jahre täglich, 5 Jahre wöchentlich) – für den Vergleich
 * im Kurs-Chart. Nutzt dieselben Zwischenspeicher wie der Steckbrief.
 */
function kursverlaufLaden(string $symbol): ?array
{
    $teile = ['j1' => ['verlauf:' . $symbol . ':2y', 3600, '2y'], 'j5' => ['verlauf:' . $symbol . ':5y', 86400, '5y']];
    $erg = [];
    $alt = [];
    $anfragen = [];
    foreach ($teile as $k => [$cache, $alter, $spanne]) {
        $c = cacheLesen($cache, $alter);
        if ($c !== null && $c['frisch']) {
            $erg[$k] = $c['wert'];
            continue;
        }
        $alt[$k] = $c['wert'] ?? null;
        $anfragen[$k] = ['url' => verlaufUrl($symbol, $spanne), 'ua' => 'browser', 'zeit' => 15];
    }
    foreach (httpViele($anfragen) as $k => $r) {
        $j = httpJson($r);
        $wert = $j !== null ? verlaufAuswerten($j) : null;
        if ($wert !== null) {
            cacheSchreiben($teile[$k][0], $wert);
            $erg[$k] = $wert;
        } elseif ($alt[$k] !== null) {
            $erg[$k] = $alt[$k];
        }
    }
    $j1 = (array)($erg['j1']['punkte'] ?? []);
    $j5 = (array)($erg['j5']['punkte'] ?? []);
    if ($j1 === [] && $j5 === []) {
        return null;
    }
    $meta = (array)($erg['j1']['meta'] ?? ($erg['j5']['meta'] ?? []));
    return ['symbol' => $symbol, 'name' => (string)($meta['name'] ?? '') ?: $symbol, 'w' => (string)($meta['waehrung'] ?? ''), 'j1' => $j1, 'j5' => $j5];
}

/** Nur die Kennzahlen (z. B. für Termin-Aktualisierung im Hintergrund). */
function yahooKennzahlenRoh(string $symbol, int $maxAlter = 6 * 3600): ?array
{
    $wert = gecacht('qs:' . $symbol, $maxAlter, function () use ($symbol): ?array {
        $j = yahooCrumbJson('https://query1.finance.yahoo.com/v10/finance/quoteSummary/' . rawurlencode($symbol) . '?modules=' . FZ_YAHOO_MODULE);
        $r = $j['quoteSummary']['result'][0] ?? null;
        return is_array($r) ? $r : null;
    });
    return is_array($wert) ? $wert : null;
}

// ---------------------------------------------------------------------------
// Aufbereitung in ein einheitliches, deutsches Datenformat
// ---------------------------------------------------------------------------

function yRoh(array $r, string $modul, string $feld): ?float
{
    $v = $r[$modul][$feld] ?? null;
    if (is_array($v)) {
        $v = $v['raw'] ?? null;
    }
    return is_numeric($v) ? (float)$v : null;
}

function yText(array $r, string $modul, string $feld): string
{
    $v = $r[$modul][$feld] ?? '';
    if (is_array($v)) {
        $v = $v['fmt'] ?? ($v['raw'] ?? '');
    }
    return is_scalar($v) ? trim((string)$v) : '';
}

function yWert($v): ?float
{
    if (is_array($v)) {
        $v = $v['raw'] ?? null;
    }
    return is_numeric($v) ? (float)$v : null;
}

/** Ein Firmenprofil aus Kennzahlen, Kurs und Verlauf zusammensetzen. */
function firmaProfil(string $symbol, bool $heimErgaenzen = true): ?array
{
    $b = yahooBuendel($symbol);
    $r = is_array($b['qs'] ?? null) ? $b['qs'] : [];
    $kurs = is_array($b['kurs'] ?? null) ? $b['kurs'] : [];
    $v1 = is_array($b['v1'] ?? null) ? $b['v1'] : null;
    $v5 = is_array($b['v5'] ?? null) ? $b['v5'] : null;
    if ($r === [] && $kurs === [] && $v1 === null) {
        return null;
    }
    $meta = $v1['meta'] ?? [];
    $p = [
        'symbol' => $symbol,
        'name' => yText($r, 'price', 'longName') ?: (yText($r, 'price', 'shortName') ?: ((string)($kurs['name'] ?? '') ?: ((string)($meta['name'] ?? '') ?: $symbol))),
        'kurzname' => yText($r, 'price', 'shortName'),
        'waehrung' => yText($r, 'price', 'currency') ?: ((string)($kurs['waehrung'] ?? '') ?: (string)($meta['waehrung'] ?? '')),
        'boerse' => (string)($kurs['boerse'] ?? '') ?: (yText($r, 'price', 'exchangeName') ?: (string)($meta['boerse'] ?? '')),
        'kurs' => $kurs['kurs'] ?? yRoh($r, 'price', 'regularMarketPrice') ?? ($meta['kurs'] ?? null),
        'aend' => $kurs['aend'] ?? yRoh($r, 'price', 'regularMarketChange'),
        'aend_proz' => $kurs['aend_proz'] ?? yRoh($r, 'price', 'regularMarketChangePercent'),
        'kurszeit' => (int)($kurs['zeit'] ?? 0) ?: (int)($r['price']['regularMarketTime'] ?? 0),
        'marktstatus' => (string)($kurs['status'] ?? '') ?: yText($r, 'price', 'marketState'),
        'kennzahlen_da' => $r !== [],

        'hoch52' => yRoh($r, 'summaryDetail', 'fiftyTwoWeekHigh') ?? ($meta['hoch52'] ?? null),
        'tief52' => yRoh($r, 'summaryDetail', 'fiftyTwoWeekLow') ?? ($meta['tief52'] ?? null),
        'boersenwert' => yRoh($r, 'summaryDetail', 'marketCap') ?? yRoh($r, 'price', 'marketCap'),
        'kgv' => yRoh($r, 'summaryDetail', 'trailingPE'),
        'kgv_erw' => yRoh($r, 'summaryDetail', 'forwardPE') ?? yRoh($r, 'defaultKeyStatistics', 'forwardPE'),
        'kbv' => yRoh($r, 'defaultKeyStatistics', 'priceToBook'),
        'kuv' => yRoh($r, 'summaryDetail', 'priceToSalesTrailing12Months'),
        'peg' => yRoh($r, 'defaultKeyStatistics', 'pegRatio') ?? yRoh($r, 'defaultKeyStatistics', 'trailingPegRatio'),
        'ev_ebitda' => yRoh($r, 'defaultKeyStatistics', 'enterpriseToEbitda'),
        'bruttomarge' => yRoh($r, 'financialData', 'grossMargins'),
        'opmarge' => yRoh($r, 'financialData', 'operatingMargins'),
        'nettomarge' => yRoh($r, 'financialData', 'profitMargins'),
        'ek_rendite' => yRoh($r, 'financialData', 'returnOnEquity'),
        'gk_rendite' => yRoh($r, 'financialData', 'returnOnAssets'),
        'umsatzwachstum' => yRoh($r, 'financialData', 'revenueGrowth'),
        'gewinnwachstum' => yRoh($r, 'financialData', 'earningsGrowth') ?? yRoh($r, 'defaultKeyStatistics', 'earningsQuarterlyGrowth'),
        'umsatz' => yRoh($r, 'financialData', 'totalRevenue'),
        'eps' => yRoh($r, 'defaultKeyStatistics', 'trailingEps'),
        'eps_erw' => yRoh($r, 'defaultKeyStatistics', 'forwardEps'),
        'verschuldung' => yRoh($r, 'financialData', 'debtToEquity'),
        'current_ratio' => yRoh($r, 'financialData', 'currentRatio'),
        'cash' => yRoh($r, 'financialData', 'totalCash'),
        'schulden' => yRoh($r, 'financialData', 'totalDebt'),
        'fcf' => yRoh($r, 'financialData', 'freeCashflow'),
        'ebitda' => yRoh($r, 'financialData', 'ebitda'),
        'bilanzwaehrung' => yText($r, 'financialData', 'financialCurrency') ?: (string)($r['earnings']['financialCurrency'] ?? ''),
        'div_rendite' => yRoh($r, 'summaryDetail', 'dividendYield') ?? yRoh($r, 'summaryDetail', 'trailingAnnualDividendYield'),
        'div_je_aktie' => yRoh($r, 'summaryDetail', 'dividendRate') ?? yRoh($r, 'summaryDetail', 'trailingAnnualDividendRate'),
        'ausschuettung' => yRoh($r, 'summaryDetail', 'payoutRatio'),
        'ex_div' => (int)(yRoh($r, 'summaryDetail', 'exDividendDate') ?? 0),
        'beta' => yRoh($r, 'summaryDetail', 'beta') ?? yRoh($r, 'defaultKeyStatistics', 'beta'),
        'aktien' => yRoh($r, 'defaultKeyStatistics', 'sharesOutstanding'),
        'gd200' => yRoh($r, 'summaryDetail', 'twoHundredDayAverage'),
        'gd50' => yRoh($r, 'summaryDetail', 'fiftyDayAverage'),

        'kursziel' => yRoh($r, 'financialData', 'targetMeanPrice'),
        'kursziel_hoch' => yRoh($r, 'financialData', 'targetHighPrice'),
        'kursziel_tief' => yRoh($r, 'financialData', 'targetLowPrice'),
        'analysten' => (int)(yRoh($r, 'financialData', 'numberOfAnalystOpinions') ?? 0),
        'empfehlung' => (string)($r['financialData']['recommendationKey'] ?? ''),
        'empfehlung_wert' => yRoh($r, 'financialData', 'recommendationMean'),

        'sektor' => (string)($r['assetProfile']['sector'] ?? ''),
        'branche' => (string)($r['assetProfile']['industry'] ?? ''),
        'land' => (string)($r['assetProfile']['country'] ?? ''),
        'stadt' => (string)($r['assetProfile']['city'] ?? ''),
        'website' => (string)($r['assetProfile']['website'] ?? ''),
        'mitarbeiter' => (int)($r['assetProfile']['fullTimeEmployees'] ?? 0),
        'beschreibung' => (string)($r['assetProfile']['longBusinessSummary'] ?? ''),

        'insider_anteil' => yRoh($r, 'majorHoldersBreakdown', 'insidersPercentHeld') ?? yRoh($r, 'defaultKeyStatistics', 'heldPercentInsiders'),
        'institutionen_anteil' => yRoh($r, 'majorHoldersBreakdown', 'institutionsPercentHeld') ?? yRoh($r, 'defaultKeyStatistics', 'heldPercentInstitutions'),
        'institutionen_anzahl' => (int)(yRoh($r, 'majorHoldersBreakdown', 'institutionsCount') ?? 0),
    ];

    $p['vorstand'] = [];
    foreach (array_slice((array)($r['assetProfile']['companyOfficers'] ?? []), 0, 6) as $o) {
        if (!empty($o['name'])) {
            $p['vorstand'][] = ['name' => (string)$o['name'], 'rolle' => (string)($o['title'] ?? '')];
        }
    }
    $p['institutionen'] = besitzerListe((array)($r['institutionOwnership']['ownershipList'] ?? []));
    $p['fonds'] = besitzerListe((array)($r['fundOwnership']['ownershipList'] ?? []));
    $p['insider'] = [];
    foreach (array_slice((array)($r['insiderHolders']['holders'] ?? []), 0, 12) as $h) {
        $p['insider'][] = [
            'name' => (string)($h['name'] ?? ''), 'rolle' => (string)($h['relation'] ?? ''),
            'aktien' => yWert($h['positionDirect'] ?? null), 'zeit' => (int)(yWert($h['latestTransDate'] ?? null) ?? 0),
            'text' => (string)($h['transactionDescription'] ?? ''),
        ];
    }
    $p['insider_geschaefte'] = [];
    foreach (array_slice((array)($r['insiderTransactions']['transactions'] ?? []), 0, 15) as $t) {
        $p['insider_geschaefte'][] = [
            'name' => (string)($t['filerName'] ?? ''), 'rolle' => (string)($t['filerRelation'] ?? ''),
            'text' => (string)($t['transactionText'] ?? ''), 'aktien' => yWert($t['shares'] ?? null),
            'wert' => yWert($t['value'] ?? null), 'zeit' => (int)(yWert($t['startDate'] ?? null) ?? 0),
        ];
    }
    $p['empfehlungen'] = null;
    foreach ((array)($r['recommendationTrend']['trend'] ?? []) as $t) {
        if (($t['period'] ?? '') === '0m') {
            $p['empfehlungen'] = [
                'stark_kaufen' => (int)($t['strongBuy'] ?? 0), 'kaufen' => (int)($t['buy'] ?? 0), 'halten' => (int)($t['hold'] ?? 0),
                'verkaufen' => (int)($t['sell'] ?? 0), 'stark_verkaufen' => (int)($t['strongSell'] ?? 0),
            ];
        }
    }
    $p['analystenwechsel'] = [];
    foreach (array_slice((array)($r['upgradeDowngradeHistory']['history'] ?? []), 0, 8) as $h) {
        $p['analystenwechsel'][] = [
            'zeit' => (int)($h['epochGradeDate'] ?? 0), 'firma' => (string)($h['firm'] ?? ''),
            'von' => (string)($h['fromGrade'] ?? ''), 'nach' => (string)($h['toGrade'] ?? ''), 'aktion' => (string)($h['action'] ?? ''),
            'ziel' => isset($h['currentPriceTarget']) && is_numeric($h['currentPriceTarget']) ? (float)$h['currentPriceTarget'] : null,
        ];
    }
    $p['jahre'] = [];
    foreach ((array)($r['earnings']['financialsChart']['yearly'] ?? []) as $j) {
        $p['jahre'][] = ['jahr' => (string)($j['date'] ?? ''), 'umsatz' => yWert($j['revenue'] ?? null), 'gewinn' => yWert($j['earnings'] ?? null)];
    }
    // Analysten-Schätzungen für das laufende und das nächste Geschäftsjahr
    $p['schaetzungen'] = [];
    foreach ((array)($r['earningsTrend']['trend'] ?? []) as $tr) {
        $periode = (string)($tr['period'] ?? '');
        if ($periode === '0y' || $periode === '+1y') {
            $p['schaetzungen'][$periode] = [
                'ende' => (string)($tr['endDate'] ?? ''),
                'eps' => yWert($tr['earningsEstimate']['avg'] ?? null),
                'eps_wachstum' => yWert($tr['earningsEstimate']['growth'] ?? null),
                'umsatz' => yWert($tr['revenueEstimate']['avg'] ?? null),
                'umsatz_wachstum' => yWert($tr['revenueEstimate']['growth'] ?? null),
                'analysten' => (int)(yWert($tr['earningsEstimate']['numberOfAnalysts'] ?? null) ?? 0),
            ];
        }
    }
    $p['termine'] = termineAus($r);
    $p['verlauf_2j'] = $v1['punkte'] ?? [];
    $p['verlauf_5j'] = $v5['punkte'] ?? [];
    $p['dividenden'] = $v5['div'] ?? ($v1['div'] ?? []);
    $p['daten_von'] = '';
    // Deutsche Zweitnotierung einer ausländischen Firma (z. B. Xetra-Kürzel von Apple oder Vulcan)?
    $endung = str_contains($symbol, '.') ? substr($symbol, (int)strrpos($symbol, '.')) : '';
    $zweit = in_array($endung, ['.DE', '.F', '.SG', '.MU', '.BE', '.DU', '.HM', '.HA'], true) && $p['land'] !== 'Germany';
    if ($heimErgaenzen && $zweit && ($p['branche'] === '' || $p['umsatz'] === null || $p['kursziel'] === null)) {
        $p = heimatErgaenzen($p);
    }
    return $p;
}

/** Ländercode der ISIN → Endung der Heimatbörse bei Yahoo. */
const FZ_HEIMATBOERSE = [
    'DE' => '.DE', 'US' => '', 'AU' => '.AX', 'GB' => '.L', 'FR' => '.PA', 'NL' => '.AS', 'CH' => '.SW', 'DK' => '.CO',
    'SE' => '.ST', 'NO' => '.OL', 'FI' => '.HE', 'IT' => '.MI', 'ES' => '.MC', 'AT' => '.VI', 'BE' => '.BR', 'PT' => '.LS',
    'IE' => '.IR', 'JP' => '.T', 'HK' => '.HK', 'CA' => '.TO', 'PL' => '.WA', 'NZ' => '.NZ', 'SG' => '.SI', 'KR' => '.KS',
];

/**
 * Zweitnotierungen (z. B. Xetra-Kürzel ausländischer Firmen) haben bei Yahoo
 * kaum Kennzahlen. Fehlendes wird von der Heimatbörse ergänzt; Beträge in
 * deren Währung werden in die Kurswährung umgerechnet.
 */
function heimatErgaenzen(array $p): array
{
    $symbol = (string)$p['symbol'];
    $heim = gecacht('heimat:' . $symbol, 30 * 86400, function () use ($symbol, $p): ?string {
        $isin = '';
        foreach (datenLaden()['firmen'] as $s => $f) {
            if ((string)$s === $symbol && $f['isin'] !== '') {
                $isin = $f['isin'];
            }
        }
        $treffer = yahooSuche($isin !== '' ? $isin : nameKurz((string)$p['name']));
        $endung = $isin !== '' ? (FZ_HEIMATBOERSE[substr($isin, 0, 2)] ?? null) : null;
        $deutsch = ['.DE', '.F', '.SG', '.MU', '.BE', '.DU', '.HM', '.HA'];
        $kandidat = '';
        $kandidatOtc = false;
        foreach ($treffer as $t) {
            $s = $t['symbol'];
            $endungVon = str_contains($s, '.') ? substr($s, (int)strrpos($s, '.')) : '';
            if ($s === $symbol || ($isin === '' && !namenAehnlich($t['name'], (string)$p['name']))) {
                continue;
            }
            if ($endung !== null && $endungVon === $endung) {
                return $s; // passende Heimatbörse
            }
            $usFreiverkehr = $endungVon === '' && (bool)preg_match('/^[A-Z]{4}[FY]$/', $s); // z. B. VULNF, NVOEY
            if (!in_array($endungVon, $deutsch, true) && !preg_match('/^[A-Z]{2}[A-Z0-9]{9}\d\./', $s)
                && ($kandidat === '' || ($kandidatOtc && !$usFreiverkehr))) {
                $kandidat = $s;
                $kandidatOtc = $usFreiverkehr;
            }
        }
        return $kandidat;
    });
    if (!is_string($heim) || $heim === '' || $heim === $symbol) {
        return $p;
    }
    $h = firmaProfil($heim, false);
    if ($h === null || ($h['branche'] === '' && $h['umsatz'] === null)) {
        return $p;
    }
    $faktor = 1.0;
    if ($h['waehrung'] !== '' && $p['waehrung'] !== '' && $h['waehrung'] !== $p['waehrung']) {
        $fx = wechselkurse([$h['waehrung'], $p['waehrung']]);
        $nachEuro = inEuro(1.0, $h['waehrung'], $fx);
        $einEuro = inEuro(1.0, $p['waehrung'], $fx);
        if ($nachEuro === null || !$einEuro) {
            return $p;
        }
        $faktor = $nachEuro / $einEuro;
    }
    $betraege = ['kursziel', 'kursziel_hoch', 'kursziel_tief', 'boersenwert', 'div_je_aktie', 'gd200', 'gd50'];
    foreach ($h as $k => $v) {
        if (in_array($k, ['symbol', 'name', 'kurzname', 'waehrung', 'boerse', 'kurs', 'aend', 'aend_proz', 'kurszeit', 'marktstatus',
            'hoch52', 'tief52', 'verlauf_2j', 'verlauf_5j', 'dividenden', 'daten_von', 'kennzahlen_da'], true)) {
            continue;
        }
        $leer = $p[$k] === null || $p[$k] === '' || $p[$k] === [] || $p[$k] === 0;
        if (!$leer) {
            continue;
        }
        $p[$k] = in_array($k, $betraege, true) && $v !== null ? (float)$v * $faktor : $v;
    }
    if ($p['schaetzungen'] === $h['schaetzungen']) {
        // Gewinn je Aktie in die Handelswährung umrechnen (Umsatz bleibt in der Bilanzwährung)
        foreach ($p['schaetzungen'] as &$sz) {
            $sz['eps'] = $sz['eps'] !== null ? $sz['eps'] * $faktor : null;
        }
        unset($sz);
    }
    foreach ($p['analystenwechsel'] as &$a) {
        if ($a['ziel'] !== null && $p['analystenwechsel'] === $h['analystenwechsel']) {
            $a['ziel'] *= $faktor;
        }
    }
    unset($a);
    if ($p['bilanzwaehrung'] === '') {
        $p['bilanzwaehrung'] = $h['bilanzwaehrung'] !== '' ? $h['bilanzwaehrung'] : $h['waehrung'];
    }
    // Kennzahlen mit Kurs-Bezug, die sich aus den Heimat-Werten ergeben
    $p['kennzahlen_da'] = true;
    $p['daten_von'] = $heim;
    return $p;
}

function besitzerListe(array $liste): array
{
    $erg = [];
    foreach (array_slice($liste, 0, 10) as $x) {
        if (empty($x['organization'])) {
            continue;
        }
        $erg[] = [
            'name' => (string)$x['organization'], 'anteil' => yWert($x['pctHeld'] ?? null),
            'aktien' => yWert($x['position'] ?? null), 'wert' => yWert($x['value'] ?? null),
            'zeit' => (int)(yWert($x['reportDate'] ?? null) ?? 0), 'aenderung' => yWert($x['pctChange'] ?? null),
        ];
    }
    return $erg;
}

/** Anstehende Termine: Quartalszahlen und Dividende (Tag als JJJJ-MM-TT). */
/** Analysten-Schnappschuss für Sortierung und Listen (Kursziel und Abstand zum Kurs). */
function analystenSchnappschuss(?float $ziel, ?float $hoch, ?float $tief, int $anzahl, string $empfehlung, ?float $kurs, string $waehrung): array
{
    return ['ziel' => $ziel, 'hoch' => $hoch, 'tief' => $tief, 'anzahl' => $anzahl, 'empfehlung' => $empfehlung,
        'kurs' => $kurs, 'waehrung' => $waehrung, 'potenzial' => $ziel !== null && $kurs ? $ziel / $kurs - 1 : null, 'zeit' => time()];
}

/**
 * Analystendaten für mehrere Firmen parallel nachladen (für die Sortierung nach Kursziel).
 * Rückgabe: [symbol => Schnappschuss]
 */
function analystenNachladen(array $symbole, float $budget = 8.0): array
{
    $start = microtime(true);
    $crumb = yahooCrumb();
    $erg = [];
    if ($crumb === '') {
        return $erg;
    }
    foreach (array_chunk(array_values($symbole), 10) as $teil) {
        if (microtime(true) - $start > $budget) {
            break;
        }
        $anfragen = [];
        foreach ($teil as $s) {
            $anfragen[$s] = ['url' => mitCrumb('https://query1.finance.yahoo.com/v10/finance/quoteSummary/' . rawurlencode((string)$s) . '?modules=financialData,price', $crumb),
                'ua' => 'browser', 'cookies' => yahooCookieDatei(), 'zeit' => 12];
        }
        foreach (httpViele($anfragen) as $s => $r) {
            $roh = httpJson($r)['quoteSummary']['result'][0] ?? null;
            // auch ohne Kursziel merken, damit nicht bei jedem Aufruf erneut gefragt wird
            $erg[(string)$s] = is_array($roh) ? analystenAusRoh($roh) : analystenSchnappschuss(null, null, null, 0, '', null, '');
        }
    }
    return $erg;
}

function analystenAusProfil(array $p): array
{
    return analystenSchnappschuss($p['kursziel'], $p['kursziel_hoch'], $p['kursziel_tief'], (int)$p['analysten'], (string)$p['empfehlung'], $p['kurs'], (string)$p['waehrung']);
}

function analystenAusRoh(array $r): array
{
    return analystenSchnappschuss(yRoh($r, 'financialData', 'targetMeanPrice'), yRoh($r, 'financialData', 'targetHighPrice'),
        yRoh($r, 'financialData', 'targetLowPrice'), (int)(yRoh($r, 'financialData', 'numberOfAnalystOpinions') ?? 0),
        (string)($r['financialData']['recommendationKey'] ?? ''), yRoh($r, 'price', 'regularMarketPrice') ?? yRoh($r, 'financialData', 'currentPrice'),
        (string)($r['price']['currency'] ?? ''));
}

function termineAus(array $r): array
{
    $t = [];
    foreach ((array)($r['calendarEvents']['earnings']['earningsDate'] ?? []) as $e) {
        $ts = (int)(yWert($e) ?? 0);
        if ($ts > 0) {
            $t['zahlen'] = date('Y-m-d', $ts);
            break;
        }
    }
    $ex = (int)(yWert($r['calendarEvents']['exDividendDate'] ?? null) ?? yRoh($r, 'summaryDetail', 'exDividendDate') ?? 0);
    if ($ex > 0) {
        $t['exdiv'] = date('Y-m-d', $ex);
    }
    $zahltag = (int)(yWert($r['calendarEvents']['dividendDate'] ?? null) ?? 0);
    if ($zahltag > 0) {
        $t['divzahlung'] = date('Y-m-d', $zahltag);
    }
    return $t;
}
