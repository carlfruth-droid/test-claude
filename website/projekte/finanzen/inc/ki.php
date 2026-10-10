<?php
declare(strict_types=1);

/*
 * KI-Einschätzung einer Firma mit Claude und Websuche.
 */

function kiUrl(): string
{
    $test = getenv('FZ_KI_URL');
    return is_string($test) && $test !== '' ? $test : 'https://api.anthropic.com/v1/messages';
}

/** Gleicher Schlüssel wie bei TasteLog (wird beim Deployment hinterlegt). */
function kiSchluessel(): string
{
    $k = geheimLesen('apikey');
    if ($k === '') {
        $tastelog = dirname(FZ_APP) . '/champagner/daten/apikey.php';
        if (is_file($tastelog)) {
            $v = require $tastelog;
            $k = is_string($v) ? trim($v) : '';
        }
    }
    return $k;
}

/** Ein API-Aufruf; bei Problemen steht der Grund (im Klartext) in $fehler. */
function kiAnfrage(array $body, int $zeit, ?string &$fehler, array $betas = []): ?array
{
    $fehler = '';
    $key = kiSchluessel();
    if ($key === '') {
        $fehler = 'Es ist kein Anthropic-API-Schlüssel hinterlegt (GitHub-Secret ANTHROPIC_API_KEY).';
        return null;
    }
    $kopf = ['content-type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'];
    if ($betas !== []) {
        $kopf[] = 'anthropic-beta: ' . implode(',', $betas);
    }
    $r = http(kiUrl(), ['post' => (string)json_encode($body), 'kopf' => $kopf, 'zeit' => $zeit, 'folgen' => false]);
    if ($r['code'] === 0) {
        $fehler = 'Der KI-Dienst war nicht erreichbar (' . ($r['fehler'] !== '' ? $r['fehler'] : 'Zeitüberschreitung') . ').';
        return null;
    }
    $j = json_decode($r['body'], true);
    if (!is_array($j)) {
        $fehler = 'Unlesbare Antwort vom KI-Dienst (HTTP ' . $r['code'] . ').';
        return null;
    }
    if ($r['code'] >= 400 || ($j['type'] ?? '') === 'error') {
        $meldung = (string)($j['error']['message'] ?? 'unbekannter Fehler');
        $fehler = 'Der KI-Dienst meldet einen Fehler (HTTP ' . $r['code'] . '): ' . mb_substr($meldung, 0, 300);
        return null;
    }
    return $j;
}

/** Anfrage-Gerüst je Modell: Opus nutzt die neue Websuche und den automatischen Rückfall. */
function kiGeruest(string $modell): array
{
    if ($modell === 'claude-haiku-4-5') {
        return [['web_search_20250305'], []];
    }
    return [['web_search_20260209'], ['server-side-fallback-2026-07-01']];
}

/** Kurzer Verbindungstest für die Einstellungen. */
function kiTest(string $modell): string
{
    $body = ['model' => $modell, 'max_tokens' => 256, 'messages' => [['role' => 'user', 'content' => 'Antworte nur mit: OK']]];
    if ($modell !== 'claude-haiku-4-5') {
        $body['output_config'] = ['effort' => 'low'];
    }
    $j = kiAnfrage($body, 60, $fehler);
    return $j === null ? (string)$fehler : '';
}

function kiAuftrag(array $firma, array $p): array
{
    $zeilen = [];
    $kennzahl = static function (string $titel, string $wert) use (&$zeilen): void {
        if ($wert !== '–' && $wert !== '') {
            $zeilen[] = '- ' . $titel . ': ' . $wert;
        }
    };
    $w = (string)$p['waehrung'];
    $kennzahl('Kurs', geld($p['kurs'], $w));
    $kennzahl('Börsenwert', gross($p['boersenwert'], $w));
    $kennzahl('KGV', zahl($p['kgv'], 1));
    $kennzahl('KGV erwartet', zahl($p['kgv_erw'], 1));
    $kennzahl('KBV', zahl($p['kbv'], 1));
    $kennzahl('Dividendenrendite', proz($p['div_rendite']));
    $kennzahl('Nettomarge', proz($p['nettomarge']));
    $kennzahl('Umsatzwachstum', proz($p['umsatzwachstum'], 1, true));
    $kennzahl('Verschuldung/Eigenkapital', $p['verschuldung'] !== null ? zahl($p['verschuldung'], 0) . ' %' : '–');
    $kennzahl('Analysten-Kursziel', geld($p['kursziel'], $w));

    $system = 'Du bist ein erfahrener, nüchterner Aktienanalyst. Du schreibst für einen langfristig orientierten '
        . 'Privatanleger aus Deutschland, der eine Firma vor einem möglichen Investment prüft. Recherchiere im Web '
        . 'aktuelle, belastbare Informationen – bevorzugt Geschäftsberichte, Investor-Relations-Seiten, '
        . 'Stimmrechtsmitteilungen und seriöse Finanzmedien. Schreibe auf Deutsch, knapp und sachlich, mit konkreten '
        . 'Zahlen samt Stand (Monat/Jahr). Was du nicht sicher belegen kannst, kennzeichnest du offen als unsicher. '
        . 'Gib keine Kauf- oder Verkaufsempfehlung, sondern eine ausgewogene Einordnung.';

    $auftrag = 'Firma: ' . $p['name'] . ' (Kürzel ' . $p['symbol']
        . ($firma['isin'] !== '' ? ', ISIN ' . $firma['isin'] : '')
        . ($p['boerse'] !== '' ? ', Börse ' . $p['boerse'] : '')
        . ($p['branche'] !== '' ? ', Branche ' . $p['branche'] : '')
        . ($p['land'] !== '' ? ', Land ' . $p['land'] : '') . ")\n"
        . 'Kennzahlen laut Yahoo Finance, Stand ' . date('d.m.Y') . ":\n" . implode("\n", $zeilen) . "\n\n"
        . "Schreibe eine Analyse mit genau diesen Abschnitten als Überschriften der zweiten Ebene (##):\n"
        . "## Kurzfazit – 2 bis 3 Sätze.\n"
        . "## Geschäftsmodell – womit die Firma ihr Geld verdient, wichtigste Segmente und Regionen mit Umsatzanteilen.\n"
        . "## Aktuelle Entwicklung – die wichtigsten Ereignisse der letzten sechs Monate mit Datum (Zahlen, Prognosen, Übernahmen, Management, Rechtsstreit).\n"
        . "## Großaktionäre – die größten Aktionäre mit Namen und Anteil in Prozent samt Stand; Ankeraktionäre, Familien, Stiftungen und Staatsbeteiligungen hervorheben.\n"
        . "## Beteiligungen – wesentliche Tochtergesellschaften und Beteiligungen an anderen Firmen mit Anteil in Prozent, soweit bekannt; börsennotierte Beteiligungen hervorheben.\n"
        . "## Stärken – 3 bis 5 Stichpunkte.\n"
        . "## Risiken – 3 bis 5 Stichpunkte.\n"
        . "## Bewertung – Einordnung der Bewertung im Vergleich zur eigenen Historie und zu Wettbewerbern.\n"
        . "## Worauf achten – anstehende Termine und Kennzahlen, die man beobachten sollte.\n\n"
        . 'Nutze Stichpunkte mit „- “ und hebe wichtige Zahlen mit **…** hervor. Insgesamt höchstens etwa 700 Wörter. '
        . 'Keine Einleitung vor dem ersten Abschnitt und keine Quellenliste am Ende – die Quellen werden separat angezeigt. '
        . kiLinkRegel();
    return [$system, $auftrag];
}

/** Bitte an die KI, börsennotierte Firmen anklickbar zu schreiben (wird in mdInline zum Link auf die Firmenseite). */
function kiLinkRegel(): string
{
    return 'Andere börsennotierte Firmen (z. B. Wettbewerber, Großaktionäre, Beteiligungen) schreibst du bei der ersten Nennung als Link '
        . 'mit ihrem Kürzel bei Yahoo Finance: [Name](aktie:KÜRZEL), etwa [Rheinmetall](aktie:RHM.DE), [Lockheed Martin](aktie:LMT) '
        . 'oder [Toyota](aktie:7203.T) – nur, wenn du das Kürzel sicher kennst; sonst einfach den Namen.';
}

/**
 * Börsenkürzel aus einer Tabellenzelle im Yahoo-Format: „LMT“, „RHM.DE“,
 * „NYSE: LMT“, „HO (Euronext Paris)“ → HO.PA. Leer, wenn nicht börsennotiert.
 */
function tickerAusText(string $z): string
{
    $z = trim((string)preg_replace('/[*_`]|\[|\]|\(aktie:[^)]*\)/u', '', $z));
    if ($z === '' || preg_match('/nicht|privat|keine?|staat|unlisted|n\/a|^[–—\-]+$/iu', $z)) {
        return '';
    }
    $z = trim((string)(preg_split('/\s*[\/,;]\s*|\s+(?:und|bzw\.?|oder)\s+/u', $z)[0] ?? ''));
    $boerse = '';
    if (preg_match('/^([^:]+):\s*(\S+)$/u', $z, $m)) {
        [$boerse, $z] = [$m[1], $m[2]];
    } elseif (preg_match('/^(\S+)\s*\(([^)]+)\)$/u', $z, $m)) {
        [$z, $boerse] = [$m[1], $m[2]];
    }
    $z = strtoupper($z);
    if (!str_contains(rtrim($z, '.'), '.') && $boerse !== '') {
        $endungen = ['xetra' => 'DE', 'frankfurt' => 'DE', 'etr' => 'DE', 'fra' => 'F', 'paris' => 'PA', 'epa' => 'PA', 'london' => 'L', 'lse' => 'L', 'lon' => 'L',
            'tokio' => 'T', 'tokyo' => 'T', 'tse' => 'T', 'tyo' => 'T', 'mailand' => 'MI', 'milan' => 'MI', 'milano' => 'MI', 'bit' => 'MI', 'madrid' => 'MC', 'bme' => 'MC',
            'amsterdam' => 'AS', 'ams' => 'AS', 'brüssel' => 'BR', 'brussels' => 'BR', 'zürich' => 'SW', 'zurich' => 'SW', 'six' => 'SW', 'swx' => 'SW',
            'stockholm' => 'ST', 'sto' => 'ST', 'oslo' => 'OL', 'osl' => 'OL', 'kopenhagen' => 'CO', 'copenhagen' => 'CO', 'cph' => 'CO', 'helsinki' => 'HE', 'hel' => 'HE',
            'wien' => 'VI', 'vienna' => 'VI', 'hongkong' => 'HK', 'hong kong' => 'HK', 'hkex' => 'HK', 'hkg' => 'HK', 'seoul' => 'KS', 'krx' => 'KS', 'kospi' => 'KS',
            'toronto' => 'TO', 'tsx' => 'TO', 'sydney' => 'AX', 'asx' => 'AX', 'shanghai' => 'SS', 'shenzhen' => 'SZ', 'taiwan' => 'TW', 'twse' => 'TW',
            'mumbai' => 'BO', 'bse' => 'BO', 'nse' => 'NS', 'são paulo' => 'SA', 'sao paulo' => 'SA', 'b3' => 'SA', 'warschau' => 'WA', 'warsaw' => 'WA',
            'lissabon' => 'LS', 'lisbon' => 'LS', 'dublin' => 'IR', 'tel aviv' => 'TA', 'singapur' => 'SI', 'singapore' => 'SI', 'sgx' => 'SI',
            'borsa italiana' => 'MI', 'deutsche börse' => 'DE', 'korea' => 'KS', 'taipei' => 'TW', 'wiener' => 'VI', 'johannesburg' => 'JO', 'jse' => 'JO',
            'mexiko' => 'MX', 'mexico' => 'MX', 'bmv' => 'MX', 'nzx' => 'NZ', 'istanbul' => 'IS', 'tadawul' => 'SR'];
        foreach ($endungen as $name => $endung) {
            if (preg_match('/(?<![\p{L}])' . preg_quote($name, '/') . '(?![\p{L}])/iu', $boerse)) {
                $z = rtrim($z, '.') . '.' . $endung;
                break;
            }
        }
    }
    $z = rtrim($z, '.');
    return preg_match('/^[A-Z0-9][A-Z0-9.\-^=]{0,23}$/', $z) ? $z : '';
}

/**
 * Führt die Analyse aus. Ergebnis: ['ok' => true, 'text', 'quellen', 'modell', 'zeit']
 * oder ['ok' => false, 'fehler' => …].
 */
function kiAnalyse(array $firma, array $profil, string $modell): array
{
    [$system, $auftrag] = kiAuftrag($firma, $profil);
    return kiLauf($system, $auftrag, $modell);
}

/** Eine Anfrage mit Websuche ausführen (mit Fortsetzung bei „pause_turn“). */
function kiLauf(string $system, string $auftrag, string $modell, int $suchen = 6): array
{
    [$werkzeug, $betas] = kiGeruest($modell);
    $body = [
        'model' => $modell,
        'max_tokens' => 16000,
        'system' => $system,
        'tools' => [['type' => $werkzeug[0], 'name' => 'web_search', 'max_uses' => $suchen]],
    ];
    if ($betas !== []) {
        $body['fallbacks'] = 'default';
    }
    if (function_exists('set_time_limit')) {
        @set_time_limit(330);
    }
    if (function_exists('ignore_user_abort')) {
        ignore_user_abort(true); // Ergebnis auch speichern, wenn das Handy zwischendurch die Verbindung trennt
    }
    $frage = ['role' => 'user', 'content' => $auftrag];
    $bloecke = [];
    $stop = '';
    $genutztesModell = $modell;
    // Die Websuche läuft serverseitig in Runden; bei „pause_turn“ einfach fortsetzen.
    for ($runde = 0; $runde < 4; $runde++) {
        $body['messages'] = $bloecke === [] ? [$frage] : [$frage, ['role' => 'assistant', 'content' => $bloecke]];
        $j = kiAnfrage($body, 280, $fehler, $betas);
        if ($j === null) {
            return ['ok' => false, 'fehler' => (string)$fehler];
        }
        $stop = (string)($j['stop_reason'] ?? '');
        $genutztesModell = (string)($j['model'] ?? $modell);
        if ($stop === 'refusal') {
            return ['ok' => false, 'fehler' => 'Die KI hat diese Anfrage abgelehnt (Sicherheitsfilter). Bitte später noch einmal versuchen.'];
        }
        $bloecke = nachLetztemRueckfall(array_merge($bloecke, array_values(array_filter((array)($j['content'] ?? []), 'is_array'))));
        if ($stop !== 'pause_turn') {
            break;
        }
    }
    [$text, $quellen] = kiTextUndQuellen($bloecke);
    if (trim($text) === '') {
        return ['ok' => false, 'fehler' => 'Die KI hat keine Analyse geliefert' . ($stop !== '' ? ' (Abbruchgrund: ' . $stop . ')' : '') . '. Bitte noch einmal versuchen.'];
    }
    if ($stop === 'max_tokens') {
        $text .= "\n\n_(Die Analyse wurde wegen ihrer Länge abgeschnitten.)_";
    }
    return ['ok' => true, 'text' => trim($text), 'quellen' => $quellen, 'modell' => $genutztesModell, 'zeit' => time()];
}

/**
 * Hat ein Rückfall-Modell übernommen, zählt nur, was nach der letzten
 * Übergabe kam – Teile des ablehnenden Modells dürfen nicht zurückgeschickt werden.
 */
function nachLetztemRueckfall(array $bloecke): array
{
    $letzte = -1;
    foreach ($bloecke as $i => $b) {
        if (($b['type'] ?? '') === 'fallback') {
            $letzte = $i;
        }
    }
    return $letzte < 0 ? $bloecke : array_values(array_slice($bloecke, $letzte + 1));
}

/** Antworttext (nach der letzten Recherche) und die genutzten Quellen. */
function kiTextUndQuellen(array $bloecke): array
{
    $letzteRecherche = -1;
    foreach ($bloecke as $i => $b) {
        $typ = (string)($b['type'] ?? '');
        if ($typ === 'server_tool_use' || str_ends_with($typ, '_tool_result')) {
            $letzteRecherche = $i;
        }
    }
    $text = '';
    $zitiert = [];
    $gefunden = [];
    foreach ($bloecke as $i => $b) {
        $typ = (string)($b['type'] ?? '');
        if ($typ === 'web_search_tool_result' && is_array($b['content'] ?? null)) {
            foreach ($b['content'] as $q) {
                if (is_array($q) && ($q['type'] ?? '') === 'web_search_result' && !empty($q['url'])) {
                    $gefunden[(string)$q['url']] = (string)($q['title'] ?? $q['url']);
                }
            }
        }
        if ($typ === 'text' && $i > $letzteRecherche) {
            $text .= (string)($b['text'] ?? '');
            foreach ((array)($b['citations'] ?? []) as $c) {
                if (is_array($c) && !empty($c['url'])) {
                    $zitiert[(string)$c['url']] = (string)($c['title'] ?? $c['url']);
                }
            }
        }
    }
    $quellen = $zitiert !== [] ? $zitiert : array_slice($gefunden, 0, 8, true);
    $liste = [];
    foreach ($quellen as $url => $titel) {
        if (preg_match('~^https?://~', $url)) {
            $liste[] = ['url' => $url, 'titel' => $titel !== '' ? $titel : $url];
        }
    }
    return [$text, array_slice($liste, 0, 12)];
}

// ---------------------------------------------------------------------------
// Darstellung: schlankes Markdown → HTML (nur das, was die Analyse nutzt)
// ---------------------------------------------------------------------------

function mdInline(string $s): string
{
    $s = e($s);
    $s = (string)preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s);
    $s = (string)preg_replace('/(?<![\w*])_(.+?)_(?![\w*])/u', '<em>$1</em>', $s);
    $s = (string)preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/u', '<em>$1</em>', $s);
    // [Name](aktie:KÜRZEL) → Link auf die Firmenseite (siehe kiLinkRegel)
    $s = (string)preg_replace_callback('~\[([^\]]+)\]\(aktie:([A-Za-z0-9.\-^=]{1,24})\)~u', static fn(array $m): string => firmenLink($m[1], strtoupper($m[2])), $s);
    return (string)preg_replace('~\[([^\]]+)\]\((https?://[^\s)]+)\)~u', '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>', $s);
}

/**
 * Link auf die Firmenseite. $html ist fertiges HTML (das Sichtbare); der Name
 * (ebenfalls HTML) wird mitgegeben, damit ein unbekanntes Kürzel per Suche aufgelöst werden kann.
 */
function firmenLink(string $html, string $symbol, ?string $nameHtml = null): string
{
    $name = trim(html_entity_decode(strip_tags($nameHtml ?? $html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return '<a class="firmenlink" href="' . e(url(['seite' => 'firma', 's' => $symbol, 'n' => mb_substr($name, 0, 80)])) . '">' . $html . '</a>';
}

function mdHtml(string $md): string
{
    $html = '';
    $absatz = [];
    $liste = '';
    $tabelle = [];
    $kuerzelSpalte = -1;
    $absatzZu = static function () use (&$absatz, &$html): void {
        if ($absatz !== []) {
            $html .= '<p>' . mdInline(implode(' ', $absatz)) . '</p>';
            $absatz = [];
        }
    };
    $listeZu = static function () use (&$liste, &$html): void {
        if ($liste !== '') {
            $html .= '</' . $liste . '>';
            $liste = '';
        }
    };
    foreach (preg_split('/\R/u', trim($md)) ?: [] as $zeile) {
        $t = trim($zeile);
        if ($t === '') {
            $absatzZu();
            $listeZu();
            if ($tabelle !== []) {
                $html .= '</tbody></table></div>';
                $tabelle = [];
            }
            continue;
        }
        if (preg_match('/^(#{1,4})\s+(.+)$/u', $t, $m)) {
            $absatzZu();
            $listeZu();
            $ebene = min(4, strlen($m[1]) + 1);
            $html .= '<h' . $ebene . '>' . mdInline(trim($m[2], '# ')) . '</h' . $ebene . '>';
            continue;
        }
        if (str_starts_with($t, '|') && str_ends_with($t, '|')) {
            // Tabelle: Zeilen sammeln, Trennzeile (|---|) überspringen
            $absatzZu();
            $listeZu();
            $zellen = array_map('trim', explode('|', trim($t, '|')));
            if (preg_match('/^[\s|:\-]+$/', $t)) {
                continue;
            }
            if ($tabelle === []) {
                // Spalte mit Börsenkürzeln? Dann Kürzel und Firmenname (erste Spalte) verlinken.
                $kuerzelSpalte = -1;
                foreach ($zellen as $i => $z) {
                    if (preg_match('/kürzel|ticker|symbol/iu', $z)) {
                        $kuerzelSpalte = $i;
                        break;
                    }
                }
                $html .= '<div class="tabelle-rahmen"><table class="tabelle"><thead><tr>' . implode('', array_map(static fn(string $z): string => '<th>' . mdInline($z) . '</th>', $zellen)) . '</tr></thead><tbody>';
            } else {
                $symbol = $kuerzelSpalte >= 0 ? tickerAusText((string)($zellen[$kuerzelSpalte] ?? '')) : '';
                $html .= '<tr>';
                foreach ($zellen as $i => $z) {
                    $inhalt = mdInline($z);
                    if ($symbol !== '' && ($i === $kuerzelSpalte || $i === 0) && !str_contains($inhalt, '<a ')) {
                        // der Firmenname aus der ersten Spalte hilft, falls Yahoo das Kürzel anders führt
                        $inhalt = firmenLink($inhalt, $symbol, mdInline((string)$zellen[0]));
                    }
                    $html .= '<td>' . $inhalt . '</td>';
                }
                $html .= '</tr>';
            }
            $tabelle[] = $zellen;
            continue;
        }
        if ($tabelle !== []) {
            $html .= '</tbody></table></div>';
            $tabelle = [];
        }
        if (preg_match('/^[-*•]\s+(.+)$/u', $t, $m) || preg_match('/^\d+[.)]\s+(.+)$/u', $t, $m)) {
            $art = preg_match('/^\d/', $t) ? 'ol' : 'ul';
            $absatzZu();
            if ($liste !== $art) {
                $listeZu();
                $html .= '<' . $art . '>';
                $liste = $art;
            }
            $html .= '<li>' . mdInline($m[1]) . '</li>';
            continue;
        }
        $listeZu();
        $absatz[] = $t;
    }
    $absatzZu();
    $listeZu();
    if ($tabelle !== []) {
        $html .= '</tbody></table></div>';
    }
    return $html;
}

/** Analyse in Abschnitte zerlegen: [normalisierte Überschrift => Markdown]. */
function kiAbschnitte(string $md): array
{
    $abschnitte = [];
    $aktuell = '';
    foreach (preg_split('/\R/u', $md) ?: [] as $zeile) {
        if (preg_match('/^##\s+(.+)$/u', trim($zeile), $m)) {
            $aktuell = (string)preg_replace('/[^a-zäöüß]/u', '', mb_strtolower($m[1]));
            $abschnitte[$aktuell] = '';
            continue;
        }
        if ($aktuell !== '') {
            $abschnitte[$aktuell] .= $zeile . "\n";
        }
    }
    return array_map('trim', $abschnitte);
}

function kiAbschnitt(?array $ki, string $anfang): string
{
    if (!is_array($ki) || empty($ki['text'])) {
        return '';
    }
    foreach (kiAbschnitte((string)$ki['text']) as $titel => $inhalt) {
        if (str_starts_with($titel, $anfang)) {
            return $inhalt;
        }
    }
    return '';
}
