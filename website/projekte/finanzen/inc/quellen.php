<?php
declare(strict_types=1);

/*
 * Weitere Quellen: WKN-Auflösung (OpenFIGI), Konzernstruktur (GLEIF),
 * Beteiligungen und Stammdaten (Wikidata), News (Google News, Yahoo),
 * US-Pflichtmitteilungen (SEC) und Wechselkurse.
 */

// ---------------------------------------------------------------------------
// Suche nach Name, Kürzel, ISIN oder WKN
// ---------------------------------------------------------------------------

function istIsin(string $q): bool
{
    return (bool)preg_match('/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/', strtoupper(trim($q)));
}

function istWkn(string $q): bool
{
    $q = strtoupper(trim($q));
    return (bool)preg_match('/^[A-Z0-9]{6}$/', $q) && (bool)preg_match('/[0-9]/', $q);
}

/** WKN über OpenFIGI in einen Firmennamen übersetzen (kostenlos, ohne Schlüssel). */
function wknZuName(string $wkn): string
{
    $name = gecacht('wkn:' . strtoupper($wkn), 30 * 86400, function () use ($wkn): ?string {
        $r = http('https://api.openfigi.com/v3/mapping', [
            'post' => json_encode([['idType' => 'ID_WERTPAPIER', 'idValue' => strtoupper($wkn)]]),
            'kopf' => ['Content-Type: application/json'], 'zeit' => 12,
        ]);
        $j = httpJson($r);
        if ($j === null) {
            return null;
        }
        return (string)($j[0]['data'][0]['name'] ?? '');
    });
    return is_string($name) ? $name : '';
}

/** Ergebnisliste für die Suche: [['symbol', 'name', 'boerse', 'branche', 'isin'], …] */
function firmenSuchen(string $q): array
{
    $q = trim($q);
    if (mb_strlen($q) < 2) {
        return [];
    }
    $isin = istIsin($q) ? strtoupper($q) : '';
    $treffer = yahooSuche($isin !== '' ? $isin : $q);
    if ($treffer === [] && istWkn($q)) {
        $name = wknZuName($q);
        if ($name !== '') {
            $treffer = yahooSuche(nameKurz($name));
        }
    }
    foreach ($treffer as &$t) {
        $t['isin'] = $isin;
    }
    unset($t);
    return $treffer;
}

// ---------------------------------------------------------------------------
// GLEIF: offizielles Register der Unternehmenskennungen (LEI) mit Konzernstruktur
// ---------------------------------------------------------------------------

const FZ_GLEIF = 'https://api.gleif.org/api/v1/';

function gleifOptionen(): array
{
    return ['kopf' => ['Accept: application/vnd.api+json'], 'zeit' => 20];
}

function leiZuIsin(string $isin): string
{
    $lei = gecacht('lei-isin:' . $isin, 30 * 86400, function () use ($isin): ?string {
        $j = httpJson(http(FZ_GLEIF . 'lei-records?filter%5Bisin%5D=' . rawurlencode($isin), gleifOptionen()));
        return $j === null ? null : (string)($j['data'][0]['id'] ?? '');
    });
    return is_string($lei) ? $lei : '';
}

/** LEI über den eingetragenen Firmennamen – nur bei eindeutigem Treffer. */
function leiZuName(string $name, string $land = ''): string
{
    $lei = gecacht('lei-name:' . mb_strtolower($name) . ':' . $land, 30 * 86400, function () use ($name, $land): ?string {
        $j = httpJson(http(FZ_GLEIF . 'lei-records?filter%5Bentity.legalName%5D=' . rawurlencode($name) . '&page%5Bsize%5D=10', gleifOptionen()));
        if ($j === null) {
            return null;
        }
        foreach (($j['data'] ?? []) as $x) {
            $ent = $x['attributes']['entity'] ?? [];
            $passtName = mb_strtolower(trim((string)($ent['legalName']['name'] ?? ''))) === mb_strtolower(trim($name));
            $passtLand = $land === '' || strtoupper((string)($ent['legalAddress']['country'] ?? '')) === $land;
            if ($passtName && $passtLand && ($x['attributes']['registration']['status'] ?? '') === 'ISSUED') {
                return (string)$x['id'];
            }
        }
        return '';
    });
    return is_string($lei) ? $lei : '';
}

function gleifEintrag(array $rec): array
{
    $ent = $rec['attributes']['entity'] ?? [];
    return [
        'lei' => (string)($rec['id'] ?? ''),
        'name' => (string)($ent['legalName']['name'] ?? ''),
        'land' => (string)($ent['legalAddress']['country'] ?? ''),
        'ort' => (string)($ent['legalAddress']['city'] ?? ''),
    ];
}

/** Mutterkonzern, oberste Muttergesellschaft und Tochtergesellschaften. */
function gleifStruktur(string $lei): ?array
{
    if (!preg_match('/^[A-Z0-9]{20}$/', $lei)) {
        return null;
    }
    $wert = gecacht('gleif:' . $lei, 7 * 86400, function () use ($lei): ?array {
        $basis = FZ_GLEIF . 'lei-records/' . rawurlencode($lei);
        $o = gleifOptionen();
        $r = httpViele([
            'firma' => ['url' => $basis] + $o,
            'mutter' => ['url' => $basis . '/direct-parent'] + $o,
            'oberste' => ['url' => $basis . '/ultimate-parent'] + $o,
            'kinder' => ['url' => $basis . '/direct-children?page%5Bsize%5D=100&page%5Bnumber%5D=1'] + $o,
            'alle' => ['url' => $basis . '/ultimate-children?page%5Bsize%5D=1'] + $o,
        ]);
        $firma = httpJson($r['firma']);
        if (!isset($firma['data']['id'])) {
            return null;
        }
        $mutter = httpJson($r['mutter']);
        $oberste = httpJson($r['oberste']);
        $kinder = httpJson($r['kinder']);
        $alle = httpJson($r['alle']);
        $liste = array_map('gleifEintrag', (array)($kinder['data'] ?? []));
        usort($liste, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return [
            'firma' => gleifEintrag($firma['data']),
            'mutter' => isset($mutter['data']['id']) ? gleifEintrag($mutter['data']) : null,
            'oberste' => isset($oberste['data']['id']) ? gleifEintrag($oberste['data']) : null,
            'kinder' => $liste,
            'kinder_gesamt' => (int)($kinder['meta']['pagination']['total'] ?? count($liste)),
            'alle_gesamt' => (int)($alle['meta']['pagination']['total'] ?? 0),
        ];
    });
    return is_array($wert) ? $wert : null;
}

// ---------------------------------------------------------------------------
// Wikidata: bekannte Beteiligungen, Eigentümer, Gründung, Sitz, Logo
// ---------------------------------------------------------------------------

function wikidataSparql(string $abfrage): ?array
{
    $r = http('https://query.wikidata.org/sparql?format=json&query=' . rawurlencode($abfrage), [
        'kopf' => ['Accept: application/sparql-results+json'], 'zeit' => 25,
    ]);
    $j = httpJson($r);
    return isset($j['results']['bindings']) && is_array($j['results']['bindings']) ? $j['results']['bindings'] : null;
}

function wikidataZuIsin(string $isin): string
{
    $qid = gecacht('wd-isin:' . $isin, 30 * 86400, function () use ($isin): ?string {
        $b = wikidataSparql('SELECT ?f WHERE { ?f wdt:P946 "' . addslashes($isin) . '" } LIMIT 1');
        if ($b === null) {
            return null;
        }
        return isset($b[0]['f']['value']) ? (string)preg_replace('~^.*/~', '', $b[0]['f']['value']) : '';
    });
    return is_string($qid) ? $qid : '';
}

/** Kandidaten (Firmen mit ISIN) über die Volltextsuche. */
function wikidataSuche(string $name): array
{
    $ids = gecacht('wd-suche:' . mb_strtolower($name), 30 * 86400, function () use ($name): ?array {
        $j = httpJson(http('https://www.wikidata.org/w/api.php?action=query&list=search&format=json&srlimit=4&srsearch='
            . rawurlencode($name . ' haswbstatement:P946'), ['zeit' => 15]));
        if ($j === null) {
            return null;
        }
        return array_values(array_map(static fn(array $x): string => (string)$x['title'], (array)($j['query']['search'] ?? [])));
    });
    return is_array($ids) ? $ids : [];
}

/** Name, ISINs und LEI eines Wikidata-Eintrags. */
function wikidataKern(string $qid): ?array
{
    if (!preg_match('/^Q\d+$/', $qid)) {
        return null;
    }
    $wert = gecacht('wd-kern:' . $qid, 30 * 86400, function () use ($qid): ?array {
        $j = httpJson(http('https://www.wikidata.org/w/api.php?action=wbgetentities&format=json&props=labels%7Cclaims&languages=de%7Cen&ids=' . $qid, ['zeit' => 15]));
        $ent = $j['entities'][$qid] ?? null;
        if (!is_array($ent)) {
            return null;
        }
        $werte = static function (string $p) use ($ent): array {
            $liste = [];
            foreach ((array)($ent['claims'][$p] ?? []) as $c) {
                $v = $c['mainsnak']['datavalue']['value'] ?? null;
                if (is_string($v)) {
                    $liste[] = $v;
                }
            }
            return $liste;
        };
        return [
            'name' => (string)($ent['labels']['de']['value'] ?? ($ent['labels']['en']['value'] ?? '')),
            'isins' => $werte('P946'),
            'lei' => $werte('P1278')[0] ?? '',
        ];
    });
    return is_array($wert) ? $wert : null;
}

function wikidataDetails(string $qid): ?array
{
    if (!preg_match('/^Q\d+$/', $qid)) {
        return null;
    }
    $wert = gecacht('wd-details:' . $qid, 7 * 86400, function () use ($qid): ?array {
        $ohneEnde = 'FILTER NOT EXISTS { ?s pq:P582 [] }';
        $abfrage = 'SELECT ?art ?wert ?wertLabel ?anteil WHERE { VALUES ?f { wd:' . $qid . ' } '
            . '{ ?f p:P355 ?s . ?s ps:P355 ?wert . ' . $ohneEnde . ' FILTER NOT EXISTS { ?wert wdt:P576 [] } BIND("tochter" AS ?art) } '
            . 'UNION { ?f p:P1830 ?s . ?s ps:P1830 ?wert . ' . $ohneEnde . ' OPTIONAL { ?s pq:P1107 ?anteil } BIND("beteiligung" AS ?art) } '
            . 'UNION { ?f p:P127 ?s . ?s ps:P127 ?wert . ' . $ohneEnde . ' OPTIONAL { ?s pq:P1107 ?anteil } BIND("eigentuemer" AS ?art) } '
            . 'UNION { ?f wdt:P749 ?wert . BIND("mutter" AS ?art) } '
            . 'UNION { ?f p:P169 ?s . ?s ps:P169 ?wert . ' . $ohneEnde . ' BIND("chef" AS ?art) } '
            . 'UNION { ?f wdt:P112 ?wert . BIND("gruender" AS ?art) } '
            . 'UNION { ?f wdt:P571 ?wert . BIND("gegruendet" AS ?art) } '
            . 'UNION { ?f wdt:P159 ?wert . BIND("sitz" AS ?art) } '
            . 'UNION { ?f wdt:P154 ?wert . BIND("logo" AS ?art) } '
            . 'UNION { ?f wdt:P856 ?wert . BIND("website" AS ?art) } '
            . 'SERVICE wikibase:label { bd:serviceParam wikibase:language "de,en". } } LIMIT 500';
        $b = wikidataSparql($abfrage);
        if ($b === null) {
            return null;
        }
        $erg = ['tochter' => [], 'beteiligung' => [], 'eigentuemer' => [], 'mutter' => [], 'chef' => [], 'gruender' => [],
            'gegruendet' => '', 'sitz' => [], 'logo' => '', 'website' => ''];
        foreach ($b as $z) {
            $art = (string)($z['art']['value'] ?? '');
            $wertRoh = (string)($z['wert']['value'] ?? '');
            $label = (string)($z['wertLabel']['value'] ?? '');
            if ($art === 'gegruendet') {
                $erg['gegruendet'] = $erg['gegruendet'] ?: substr($wertRoh, 0, 4);
            } elseif ($art === 'logo') {
                $erg['logo'] = $erg['logo'] ?: $wertRoh;
            } elseif ($art === 'website') {
                $erg['website'] = $erg['website'] ?: $wertRoh;
            } elseif (isset($erg[$art]) && is_array($erg[$art])) {
                if ($label === '' || preg_match('/^Q\d+$/', $label)) {
                    continue; // Eintrag ohne Namen
                }
                $anteil = isset($z['anteil']['value']) && is_numeric($z['anteil']['value']) ? (float)$z['anteil']['value'] : null;
                $erg[$art][$label] = ['name' => $label, 'anteil' => $anteil];
            }
        }
        foreach ($erg as $k => $v) {
            if (is_array($v)) {
                $v = array_values($v);
                usort($v, $k === 'eigentuemer'
                    ? static fn(array $a, array $c): int => ($c['anteil'] ?? -1) <=> ($a['anteil'] ?? -1) // größter Anteil zuerst
                    : static fn(array $a, array $c): int => strcasecmp($a['name'], $c['name']));
                $erg[$k] = $v;
            }
        }
        return $erg;
    });
    return is_array($wert) ? $wert : null;
}

/**
 * ISIN, LEI und Wikidata-Eintrag einer Firma ermitteln (einmalig, dann an
 * der Firma gespeichert). Was die Firma schon hat, bleibt unangetastet.
 */
function identitaetErmitteln(array $firma, array $profil): array
{
    $isin = (string)$firma['isin'];
    $lei = (string)$firma['lei'];
    $qid = (string)$firma['wikidata'];
    $name = (string)($profil['name'] ?? $firma['name']);
    if ($qid === '' && $isin !== '') {
        $qid = wikidataZuIsin($isin);
    }
    if ($qid === '') {
        foreach (wikidataSuche(nameKurz($name)) as $kandidat) {
            $kern = wikidataKern($kandidat);
            if ($kern !== null && namenAehnlich($kern['name'], $name)) {
                $qid = $kandidat;
                break;
            }
        }
    }
    $kern = $qid !== '' ? wikidataKern($qid) : null;
    if ($isin === '' && $kern !== null && $kern['isins'] !== []) {
        // Bevorzugt die ISIN aus dem Land der Börse (z. B. DE bei Xetra)
        $land = str_ends_with((string)$firma['symbol'], '.DE') || str_ends_with((string)$firma['symbol'], '.F') ? 'DE'
            : (str_contains((string)$firma['symbol'], '.') ? '' : 'US');
        $isin = $kern['isins'][0];
        foreach ($kern['isins'] as $i) {
            if ($land !== '' && str_starts_with($i, $land)) {
                $isin = $i;
                break;
            }
        }
    }
    if ($lei === '' && $kern !== null && $kern['lei'] !== '') {
        $lei = $kern['lei'];
    }
    if ($lei === '' && $isin !== '') {
        $lei = leiZuIsin($isin);
    }
    if ($lei === '' && $name !== '') {
        $lei = leiZuName($name);
    }
    return ['isin' => $isin, 'lei' => $lei, 'wikidata' => $qid];
}

// ---------------------------------------------------------------------------
// News
// ---------------------------------------------------------------------------

/** Meldungen der letzten 30 Tage; $frisch = Abruf erzwingen (höchstens einmal pro Minute). */
function googleNews(string $suchbegriff, string $sprache, bool $frisch = false): array
{
    $liste = gecacht(newsSchluessel($suchbegriff, $sprache), $frisch ? 60 : 1800, function () use ($suchbegriff, $sprache): ?array {
        $q = '"' . $suchbegriff . '" ' . ($sprache === 'de' ? 'Aktie' : 'stock') . ' when:30d';
        $ort = $sprache === 'de' ? '&hl=de&gl=DE&ceid=DE:de' : '&hl=en-US&gl=US&ceid=US:en';
        $r = http('https://news.google.com/rss/search?q=' . rawurlencode($q) . $ort, ['ua' => 'browser', 'zeit' => 15]);
        if ($r['code'] !== 200 || $r['body'] === '') {
            return null;
        }
        if (!function_exists('simplexml_load_string')) {
            return null;
        }
        $xml = @simplexml_load_string($r['body']);
        if ($xml === false || !isset($xml->channel)) {
            return null;
        }
        $liste = [];
        foreach ($xml->channel->item as $it) {
            $titel = trim((string)$it->title);
            $quelle = trim((string)$it->source);
            if ($quelle !== '' && str_ends_with($titel, ' - ' . $quelle)) {
                $titel = substr($titel, 0, -strlen(' - ' . $quelle));
            }
            $link = trim((string)$it->link);
            if (!preg_match('~^https?://~i', $link)) {
                continue;
            }
            $liste[] = [
                'titel' => html_entity_decode($titel, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'link' => $link,
                'quelle' => $quelle,
                'zeit' => (int)(strtotime((string)$it->pubDate) ?: 0),
            ];
        }
        usort($liste, static fn(array $a, array $b): int => $b['zeit'] <=> $a['zeit']);
        return array_slice($liste, 0, 15);
    });
    return is_array($liste) ? $liste : [];
}

function newsSchluessel(string $suchbegriff, string $sprache): string
{
    return 'news:' . $sprache . ':' . mb_strtolower($suchbegriff);
}

/** Zeitpunkt des letzten erfolgreichen Abrufs (0 = unbekannt). */
function newsAbgerufen(string $suchbegriff, string $sprache): int
{
    return (int)(cacheLesen(newsSchluessel($suchbegriff, $sprache), 0)['zeit'] ?? 0);
}

// ---------------------------------------------------------------------------
// SEC (US-Börsenaufsicht): Pflichtmitteilungen US-notierter Firmen
// ---------------------------------------------------------------------------

function secKopf(): array
{
    return ['ua' => 'ehrlich', 'zeit' => 20, 'kopf' => ['Accept: application/json']];
}

function secCik(string $ticker): string
{
    $karte = gecacht('sec-ticker', 7 * 86400, function (): ?array {
        $j = httpJson(http('https://www.sec.gov/files/company_tickers.json', secKopf()));
        if ($j === null) {
            return null;
        }
        $karte = [];
        foreach ($j as $x) {
            if (isset($x['ticker'], $x['cik_str'])) {
                $karte[strtoupper((string)$x['ticker'])] = (int)$x['cik_str'];
            }
        }
        return $karte;
    });
    $cik = is_array($karte) ? ($karte[strtoupper($ticker)] ?? 0) : 0;
    return $cik > 0 ? str_pad((string)$cik, 10, '0', STR_PAD_LEFT) : '';
}

const FZ_SEC_FORMULARE = [
    '10-K' => 'Jahresbericht', '10-Q' => 'Quartalsbericht', '8-K' => 'Wichtiges Ereignis', '4' => 'Insider-Geschäft',
    'SC 13D' => 'Großaktionär > 5 % (aktiv)', 'SC 13D/A' => 'Großaktionär > 5 % (Änderung)', 'SC 13G' => 'Großaktionär > 5 %',
    'SC 13G/A' => 'Großaktionär > 5 % (Änderung)', 'DEF 14A' => 'Hauptversammlung', '20-F' => 'Jahresbericht (ausländisch)', '6-K' => 'Mitteilung (ausländisch)',
];

function secMeldungen(string $ticker): ?array
{
    if (str_contains($ticker, '.') || str_contains($ticker, '=') || str_contains($ticker, '^')) {
        return null;
    }
    $cik = secCik($ticker);
    if ($cik === '') {
        return null;
    }
    $wert = gecacht('sec-meldungen:' . $cik, 6 * 3600, function () use ($cik): ?array {
        $j = httpJson(http('https://data.sec.gov/submissions/CIK' . $cik . '.json', secKopf()));
        $f = $j['filings']['recent'] ?? null;
        if (!is_array($f)) {
            return null;
        }
        $liste = [];
        foreach ((array)($f['form'] ?? []) as $i => $form) {
            if (!isset(FZ_SEC_FORMULARE[$form])) {
                continue;
            }
            $nummer = str_replace('-', '', (string)($f['accessionNumber'][$i] ?? ''));
            $liste[] = [
                'form' => (string)$form,
                'art' => FZ_SEC_FORMULARE[$form],
                'datum' => (string)($f['filingDate'][$i] ?? ''),
                'link' => 'https://www.sec.gov/Archives/edgar/data/' . (int)$cik . '/' . $nummer . '/' . (string)($f['primaryDocument'][$i] ?? ''),
            ];
            if (count($liste) >= 25) {
                break;
            }
        }
        return ['cik' => $cik, 'name' => (string)($j['name'] ?? ''), 'meldungen' => $liste];
    });
    return is_array($wert) ? $wert : null;
}

// ---------------------------------------------------------------------------
// Wechselkurse (für den Depotwert in Euro)
// ---------------------------------------------------------------------------

function waehrungBasis(string $w): string
{
    return ['GBp' => 'GBP', 'ZAc' => 'ZAR', 'ILA' => 'ILS'][$w] ?? $w;
}

/** [Währung => Einheiten je 1 €] */
function wechselkurse(array $waehrungen): array
{
    $symbole = [];
    foreach (array_unique($waehrungen) as $w) {
        $b = waehrungBasis((string)$w);
        if ($b !== '' && $b !== 'EUR') {
            $symbole[$b] = 'EUR' . $b . '=X';
        }
    }
    $kurse = $symbole !== [] ? yahooKurse(array_values($symbole)) : [];
    $erg = ['EUR' => 1.0];
    foreach ($symbole as $b => $s) {
        $k = $kurse[$s]['kurs'] ?? null;
        if ($k !== null && $k > 0) {
            $erg[$b] = (float)$k;
        }
    }
    return $erg;
}

function inEuro(?float $betrag, string $waehrung, array $fx): ?float
{
    if ($betrag === null) {
        return null;
    }
    if (in_array($waehrung, ['GBp', 'ZAc', 'ILA'], true)) {
        $betrag /= 100;
    }
    $b = waehrungBasis($waehrung);
    if ($b === '' || $b === 'EUR') {
        return $betrag;
    }
    $kurs = $fx[$b] ?? null;
    return $kurs ? $betrag / $kurs : null;
}

// ---------------------------------------------------------------------------
// Wikipedia: Einleitung und Geschichte (deutsch, sonst englisch)
// ---------------------------------------------------------------------------

/** Artikel zur Wikidata-ID: ['sprache', 'titel', 'url', 'einleitung', 'geschichte'] oder null. */
function wikipediaArtikel(string $qid): ?array
{
    if (!preg_match('/^Q\d+$/', $qid)) {
        return null;
    }
    $wert = gecacht('wp:' . $qid, 7 * 86400, function () use ($qid): ?array {
        $j = httpJson(http('https://www.wikidata.org/w/api.php?action=wbgetentities&format=json&props=sitelinks&sitefilter=dewiki%7Cenwiki&ids=' . $qid, ['zeit' => 15]));
        $links = $j['entities'][$qid]['sitelinks'] ?? [];
        foreach (['de' => 'dewiki', 'en' => 'enwiki'] as $sprache => $wiki) {
            $titel = (string)($links[$wiki]['title'] ?? '');
            if ($titel === '') {
                continue;
            }
            $a = httpJson(http('https://' . $sprache . '.wikipedia.org/w/api.php?action=query&format=json&prop=extracts&explaintext=1&exsectionformat=wiki&redirects=1&titles='
                . rawurlencode($titel), ['zeit' => 20]));
            $seite = is_array($a['query']['pages'] ?? null) ? reset($a['query']['pages']) : null;
            $text = (string)($seite['extract'] ?? '');
            if ($text === '') {
                continue;
            }
            // Einleitung = alles vor der ersten Überschrift; Geschichte = passender Abschnitt bis zur nächsten Hauptüberschrift
            $teile = preg_split('/^(==[^=].*?==)\s*$/m', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
            $einleitung = trim((string)$teile[0]);
            $geschichte = '';
            for ($i = 1; $i < count($teile) - 1; $i += 2) {
                $kopf = trim($teile[$i], "= \t");
                if (preg_match('/^(Geschichte|Unternehmensgeschichte|Historie|History|Corporate history)$/iu', $kopf)) {
                    $geschichte = trim((string)$teile[$i + 1]);
                    break;
                }
            }
            return ['sprache' => $sprache, 'titel' => $titel, 'url' => 'https://' . $sprache . '.wikipedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $titel)),
                'einleitung' => mb_substr($einleitung, 0, 4000), 'geschichte' => mb_substr($geschichte, 0, 12000)];
        }
        return ['sprache' => '', 'titel' => '', 'url' => '', 'einleitung' => '', 'geschichte' => ''];
    });
    return is_array($wert) && $wert['einleitung'] !== '' ? $wert : null;
}

/** Wikipedia-Klartext mit „=== Unterüberschriften ===“ als HTML-Absätze. */
function wikiTextHtml(string $text): string
{
    $html = '';
    foreach (preg_split('/\n{1,}/', trim($text)) ?: [] as $absatz) {
        $absatz = trim($absatz);
        if ($absatz === '') {
            continue;
        }
        if (preg_match('/^=+\s*(.+?)\s*=+$/u', $absatz, $m)) {
            $html .= '<h5>' . e($m[1]) . '</h5>';
        } else {
            $html .= '<p>' . e($absatz) . '</p>';
        }
    }
    return $html;
}

/** Vergleichbare Aktien laut Yahoo („Leute schauen sich auch an“). */
function yahooAehnliche(string $symbol): array
{
    $wert = gecacht('aehnlich:' . $symbol, 7 * 86400, function () use ($symbol): ?array {
        $j = httpJson(http('https://query1.finance.yahoo.com/v6/finance/recommendationsbysymbol/' . rawurlencode($symbol), ['ua' => 'browser', 'zeit' => 15]));
        $liste = $j['finance']['result'][0]['recommendedSymbols'] ?? null;
        return is_array($liste) ? array_values(array_filter(array_map(static fn($x): string => (string)($x['symbol'] ?? ''), $liste))) : null;
    });
    return is_array($wert) ? array_slice($wert, 0, 8) : [];
}
