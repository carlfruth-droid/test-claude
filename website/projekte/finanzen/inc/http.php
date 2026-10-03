<?php
declare(strict_types=1);

/*
 * Netzwerkzugriffe (einzeln und parallel) und ein einfacher Datei-Zwischenspeicher.
 */

const FZ_UA_EHRLICH = 'Finanzzentrale/1.0 (https://fruthzeug.de; private Nutzung; finanzen@fruthzeug.de)';
// Yahoo drosselt einzelne Kennungen je nach Absender-IP (HTTP 429). Dann wird die
// nächste probiert und die funktionierende gemerkt.
const FZ_UAS = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
    'Mozilla/5.0',
    FZ_UA_EHRLICH,
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
    'Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0',
];

function uaIndex(?int $neu = null): int
{
    static $index = null;
    if ($neu !== null) {
        $index = $neu % count(FZ_UAS);
        jsonSchreiben(fzPfad('kennung.json'), ['i' => $index, 'zeit' => time()]);
    }
    if ($index === null) {
        $index = (int)(jsonLesen(fzPfad('kennung.json'))['i'] ?? 0) % count(FZ_UAS);
    }
    return $index;
}

function uaBrowser(): string
{
    return FZ_UAS[uaIndex()];
}

/**
 * Optionen: ua ('browser'|'ehrlich'), kopf (Header-Liste), post (Body),
 * zeit (Timeout s), folgen (Weiterleitungen), cookies (lesen), cookies_speichern (lesen+schreiben).
 */
function httpVorbereiten(string $url, array $o)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => (bool)($o['folgen'] ?? true),
        CURLOPT_MAXREDIRS      => 6,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => (int)($o['zeit'] ?? 20),
        CURLOPT_USERAGENT      => ($o['ua'] ?? 'ehrlich') === 'browser' ? uaBrowser() : FZ_UA_EHRLICH,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => $o['kopf'] ?? [],
    ]);
    if (array_key_exists('post', $o)) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$o['post']);
    }
    if (!empty($o['cookies_speichern'])) {
        curl_setopt($ch, CURLOPT_COOKIEFILE, (string)$o['cookies_speichern']);
        curl_setopt($ch, CURLOPT_COOKIEJAR, (string)$o['cookies_speichern']);
    } elseif (!empty($o['cookies'])) {
        curl_setopt($ch, CURLOPT_COOKIEFILE, (string)$o['cookies']);
    }
    return $ch;
}

function httpErgebnis($ch, $body): array
{
    return [
        'code'   => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'body'   => is_string($body) ? $body : '',
        'fehler' => curl_error($ch),
        'url'    => (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
    ];
}

function http(string $url, array $o = []): array
{
    if (!function_exists('curl_init')) {
        return ['code' => 0, 'body' => '', 'fehler' => 'PHP-curl fehlt', 'url' => $url];
    }
    $versuche = ($o['ua'] ?? '') === 'browser' ? count(FZ_UAS) : 1;
    for ($v = 0; $v < $versuche; $v++) {
        if ($v > 0) {
            uaIndex(uaIndex() + 1);
        }
        $ch = httpVorbereiten($url, $o);
        $body = curl_exec($ch);
        $r = httpErgebnis($ch, $body);
        curl_close($ch);
        unset($ch); // schreibt ggf. die Cookie-Datei
        if ($r['code'] !== 429) {
            break;
        }
    }
    return $r;
}

/** Mehrere Anfragen gleichzeitig: [schlüssel => ['url' => …, …Optionen]] */
function httpViele(array $anfragen): array
{
    if ($anfragen === []) {
        return [];
    }
    if (!function_exists('curl_multi_init')) {
        $erg = [];
        foreach ($anfragen as $k => $a) {
            $erg[$k] = http((string)$a['url'], $a);
        }
        return $erg;
    }
    $mh = curl_multi_init();
    $handles = [];
    foreach ($anfragen as $k => $a) {
        $ch = httpVorbereiten((string)$a['url'], $a);
        curl_multi_add_handle($mh, $ch);
        $handles[$k] = $ch;
    }
    do {
        $status = curl_multi_exec($mh, $laufend);
        if ($laufend > 0) {
            curl_multi_select($mh, 1.0);
        }
    } while ($laufend > 0 && $status === CURLM_OK);
    $erg = [];
    foreach ($handles as $k => $ch) {
        $erg[$k] = httpErgebnis($ch, curl_multi_getcontent($ch));
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    // Gedrosselte Anfragen einzeln mit anderer Kennung wiederholen
    foreach ($erg as $k => $r) {
        if ($r['code'] === 429 && ($anfragen[$k]['ua'] ?? '') === 'browser') {
            uaIndex(uaIndex() + 1);
            $erg[$k] = http((string)$anfragen[$k]['url'], $anfragen[$k]);
        }
    }
    return $erg;
}

function httpJson(array $r): ?array
{
    if ($r['code'] < 200 || $r['code'] >= 300) {
        return null;
    }
    $j = json_decode($r['body'], true);
    return is_array($j) ? $j : null;
}

// ---------------------------------------------------------------------------
// Zwischenspeicher: schont die Quellen und hält die Seite schnell. Fällt eine
// Quelle aus, wird der letzte bekannte Stand weiter angezeigt.
// ---------------------------------------------------------------------------

function cacheDatei(string $schluessel): string
{
    return fzPfad('cache') . '/' . md5($schluessel) . '.json';
}

/** ['wert' => …, 'frisch' => bool, 'zeit' => int] oder null */
function cacheLesen(string $schluessel, int $maxAlter): ?array
{
    $datei = cacheDatei($schluessel);
    if (!is_file($datei)) {
        return null;
    }
    $j = json_decode((string)@file_get_contents($datei), true);
    if (!is_array($j) || !array_key_exists('w', $j)) {
        return null;
    }
    $zeit = (int)($j['t'] ?? 0);
    return ['wert' => $j['w'], 'frisch' => time() - $zeit <= $maxAlter, 'zeit' => $zeit];
}

function cacheSchreiben(string $schluessel, $wert): void
{
    @file_put_contents(cacheDatei($schluessel), json_encode(['t' => time(), 'w' => $wert], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
}

function cacheLoeschen(string $schluessel): void
{
    @unlink(cacheDatei($schluessel));
}

/** Frischen Wert aus dem Speicher – sonst neu erzeugen (null = fehlgeschlagen → alter Stand). */
function gecacht(string $schluessel, int $maxAlter, callable $erzeugen)
{
    $c = cacheLesen($schluessel, $maxAlter);
    if ($c !== null && $c['frisch']) {
        return $c['wert'];
    }
    $neu = $erzeugen();
    if ($neu !== null) {
        cacheSchreiben($schluessel, $neu);
        return $neu;
    }
    return $c['wert'] ?? null;
}

function cacheAufraeumen(): void
{
    if (random_int(1, 40) !== 1) {
        return;
    }
    foreach (glob(fzPfad('cache') . '/*.json') ?: [] as $datei) {
        if (@filemtime($datei) < time() - 21 * 86400) {
            @unlink($datei);
        }
    }
}
