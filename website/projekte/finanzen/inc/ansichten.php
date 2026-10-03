<?php
declare(strict_types=1);

/*
 * Darstellung: Grundgerüst (Kopfzeile, Burgermenü) und alle Seiten.
 */

const FZ_ICONS = [
    'home' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
    'liste' => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
    'depot' => '<rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
    'suche' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
    'glocke' => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
    'regler' => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
    'raus' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
    'zurueck' => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
    'pfeil' => '<polyline points="6 9 12 15 18 9"/>',
    'plus' => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
    'muell' => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
    'stift' => '<path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/>',
    'stern' => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
    'extern' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
    'neu' => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
    'haken' => '<polyline points="20 6 9 17 4 12"/>',
    'info' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
    'blitz' => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
    'dokument' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
    'personen' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'netz' => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>',
    'welt' => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
    'uhr' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    'ziel' => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
    'kalender' => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
    'balken' => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
    'trend' => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
    'faellt' => '<polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/>',
    'notiz' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>',
    'runter' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
    'hoch' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
    'x' => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
    'warnung' => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
    'schloss' => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    'kuchen' => '<path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/>',
    'puls' => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
    'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
];

function ico(string $name, string $klasse = ''): string
{
    return '<svg class="ico' . ($klasse !== '' ? ' ' . $klasse : '') . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . (FZ_ICONS[$name] ?? '') . '</svg>';
}

function url(array $parameter): string
{
    $parameter = array_filter($parameter, static fn($v): bool => $v !== null && $v !== '');
    return $parameter === [] ? './' : '?' . http_build_query($parameter);
}

function firmaUrl(string $symbol, string $isin = ''): string
{
    return url(['seite' => 'firma', 's' => $symbol, 'isin' => $isin]);
}

function assetVersion(string $datei): string
{
    $t = @filemtime(FZ_APP . '/' . $datei);
    return $datei . '?v=' . ($t ?: 1);
}

function datumLang(int $ts): string
{
    $tage = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
    $monate = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    return $tage[(int)date('w', $ts)] . ', ' . (int)date('j', $ts) . '. ' . $monate[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}

function gruss(): string
{
    $h = (int)date('G');
    return $h >= 5 && $h < 11 ? 'Guten Morgen' : ($h >= 11 && $h < 18 ? 'Guten Tag' : ($h >= 18 && $h < 23 ? 'Guten Abend' : 'Hallo'));
}

function avatar(string $name, string $symbol): string
{
    $kurz = nameKurz($name);
    $teile = preg_split('/\s+/', $kurz) ?: [];
    $kuerzel = mb_strtoupper(mb_substr($teile[0] ?? $symbol, 0, 1) . (isset($teile[1]) ? mb_substr($teile[1], 0, 1) : mb_substr($teile[0] ?? '', 1, 1)));
    $farbton = crc32($symbol) % 360;
    return '<span class="avatar" style="--h:' . $farbton . '" aria-hidden="true">' . e($kuerzel) . '</span>';
}

function pill(string $status): string
{
    return '<span class="pill p-' . e($status) . '">' . e(FZ_STATUS[$status] ?? $status) . '</span>';
}

function aendHtml(?float $anteil): string
{
    if ($anteil === null) {
        return '<span class="aend">–</span>';
    }
    return '<span class="aend ' . klasse($anteil) . '">' . proz($anteil, 2, true) . '</span>';
}

/** Akkordeon-Abschnitt. $optionen: offen, meta, teil (nachladen), symbol, id */
function akk(string $titel, string $icon, string $inhalt, array $optionen = []): string
{
    $id = (string)($optionen['id'] ?? 'akk-' . substr(md5($titel), 0, 8));
    $daten = '';
    if (!empty($optionen['teil'])) {
        $daten = ' data-teil="' . e($optionen['teil']) . '" data-s="' . e($optionen['symbol'] ?? '') . '"';
        $inhalt = '<div class="laden"><span class="kreisel"></span> Wird geladen …</div>';
    }
    return '<details class="akk" id="' . e($id) . '"' . (!empty($optionen['offen']) ? ' open' : '') . $daten . '>'
        . '<summary>' . ico($icon, 'akk-ico') . '<span class="akk-titel">' . e($titel) . '</span>'
        . (isset($optionen['meta']) && $optionen['meta'] !== '' ? '<span class="akk-meta">' . $optionen['meta'] . '</span>' : '')
        . ico('pfeil', 'akk-pfeil') . '</summary>'
        . '<div class="akk-inhalt">' . $inhalt . '</div></details>';
}

function leer(string $text): string
{
    return '<p class="leer">' . $text . '</p>';
}

// ---------------------------------------------------------------------------
// Grundgerüst
// ---------------------------------------------------------------------------

function seite(string $titel, string $inhalt, string $aktiv = '', ?array $d = null): void
{
    header('Content-Type: text/html; charset=utf-8');
    $meldungen = meldungenHolen();
    $ungelesen = 0;
    $firmenAnzahl = 0;
    if ($d !== null) {
        $firmenAnzahl = count($d['firmen']);
        foreach ($d['ereignisse'] as $ev) {
            if (empty($ev['gelesen'])) {
                $ungelesen++;
            }
        }
    }
    $menue = [
        ['', 'home', 'Übersicht', ''],
        ['firmen', 'liste', 'Meine Firmen', $firmenAnzahl > 0 ? (string)$firmenAnzahl : ''],
        ['depot', 'depot', 'Depot', ''],
        ['suche', 'suche', 'Firma prüfen', ''],
        ['alarme', 'glocke', 'Alarme & Termine', $ungelesen > 0 ? (string)$ungelesen : ''],
        ['einstellungen', 'regler', 'Einstellungen', ''],
    ];
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#ffffff">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Finanzen">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<title><?= e($titel) ?> · Finanzzentrale</title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="icon-192.png" type="image/png">
<link rel="apple-touch-icon" href="icon-180.png">
<link rel="stylesheet" href="<?= e(assetVersion('app.css')) ?>">
<script src="<?= e(assetVersion('app.js')) ?>" defer></script>
</head>
<body data-csrf="<?= e(csrfToken()) ?>">
<?php if ($d !== null): ?>
<header class="kopf">
  <a class="marke" href="./"><img src="icon-192.png" alt="" width="30" height="30"><span>Finanzzentrale</span></a>
  <div class="kopf-rechts">
    <a class="kopf-knopf" href="<?= e(url(['seite' => 'suche'])) ?>" aria-label="Firma suchen"><?= ico('suche') ?></a>
    <a class="kopf-knopf<?= $ungelesen > 0 ? ' mit-punkt' : '' ?>" href="<?= e(url(['seite' => 'alarme'])) ?>" aria-label="Alarme"><?= ico('glocke') ?></a>
    <button class="burger" type="button" aria-label="Menü öffnen" aria-expanded="false" aria-controls="menue"><span></span><span></span><span></span></button>
  </div>
</header>
<div class="schleier" hidden></div>
<nav class="menue" id="menue" aria-label="Hauptmenü" aria-hidden="true">
  <div class="menue-kopf"><span>Menü</span><button type="button" class="menue-zu" aria-label="Menü schließen"><?= ico('x') ?></button></div>
  <?php foreach ($menue as [$ziel, $icon, $text, $zahl]): ?>
    <a href="<?= e(url(['seite' => $ziel])) ?>"<?= $aktiv === $ziel ? ' class="aktiv" aria-current="page"' : '' ?>><?= ico($icon) ?><span><?= e($text) ?></span><?= $zahl !== '' ? '<b class="zahl' . ($icon === 'glocke' ? ' rot' : '') . '">' . e($zahl) . '</b>' : '' ?></a>
  <?php endforeach; ?>
  <div class="menue-trenner"></div>
  <a href="/"><?= ico('zurueck') ?><span>fruthzeug.de</span></a>
  <form method="post" action="./"><?= csrfFeld() ?><input type="hidden" name="aktion" value="abmelden"><button type="submit"><?= ico('raus') ?><span>Abmelden</span></button></form>
</nav>
<?php endif; ?>
<main class="haupt<?= $d === null ? ' schmal' : '' ?>">
<?php foreach ($meldungen as [$art, $text]): ?>
  <div class="meldung <?= e($art) ?>" role="status"><?= ico($art === 'fehler' ? 'warnung' : 'haken') ?><span><?= e($text) ?></span><button type="button" class="meldung-zu" aria-label="Schließen"><?= ico('x') ?></button></div>
<?php endforeach; ?>
<?= $inhalt ?>
</main>
<?php if ($d !== null): ?>
<footer class="fuss">Kurse über Yahoo Finance, etwa 15 Minuten verzögert · Alle Angaben ohne Gewähr, keine Anlageberatung · <a href="/">fruthzeug.de</a></footer>
<?php endif; ?>
</body>
</html>
<?php
}

// ---------------------------------------------------------------------------
// Anmeldung und Einrichtung
// ---------------------------------------------------------------------------

function seiteAnmelden(string $fehler = ''): string
{
    ob_start(); ?>
<div class="anmelde-karte karte">
  <img class="anmelde-logo" src="icon-192.png" alt="" width="64" height="64">
  <h1>Finanzzentrale</h1>
  <p class="leise">Bitte mit deinem Passwort anmelden.</p>
  <?php if ($fehler !== ''): ?><div class="fehlerbox"><?= e($fehler) ?></div><?php endif; ?>
  <form method="post" action="./" class="formular">
    <?= csrfFeld() ?>
    <input type="hidden" name="aktion" value="anmelden">
    <label class="feld"><span>Passwort</span><input type="password" name="passwort" autocomplete="current-password" required autofocus></label>
    <label class="schalter"><input type="checkbox" name="merken" value="1" checked><span>Auf diesem Gerät angemeldet bleiben (90 Tage)</span></label>
    <button class="knopf voll" type="submit"><?= ico('schloss') ?> Anmelden</button>
  </form>
  <p class="klein leise mitte"><a href="<?= e(url(['seite' => 'zuruecksetzen'])) ?>">Passwort vergessen?</a></p>
</div>
<?php
    return (string)ob_get_clean();
}

function seiteEinrichten(bool $erlaubt, bool $zuruecksetzen): string
{
    ob_start(); ?>
<div class="anmelde-karte karte">
  <img class="anmelde-logo" src="icon-192.png" alt="" width="64" height="64">
  <h1><?= $zuruecksetzen ? 'Neues Passwort' : 'Willkommen in deiner Finanzzentrale' ?></h1>
  <?php if (!$erlaubt): ?>
    <div class="hinweis">
      <p><strong>Zur Sicherheit</strong> lässt sich das Passwort nur festlegen, wenn du auf diesem Gerät in <strong>TasteLog</strong> mit deinem Administrator-Konto angemeldet bist. So kann niemand Fremdes die Finanzzentrale in Besitz nehmen.</p>
      <p>Öffne TasteLog, melde dich dort an und komm dann hierher zurück.</p>
    </div>
    <a class="knopf voll" href="/projekte/champagner/">Zu TasteLog</a>
    <p class="klein leise mitte"><a href="./">Erneut prüfen</a></p>
  <?php else: ?>
    <p class="leise"><?= $zuruecksetzen ? 'Lege ein neues Passwort fest. Alle Geräte werden dabei abgemeldet.' : 'Lege einmalig dein Passwort fest. Danach meldest du dich auf jedem Gerät damit an.' ?></p>
    <form method="post" action="./" class="formular">
      <?= csrfFeld() ?>
      <input type="hidden" name="aktion" value="einrichten">
      <label class="feld"><span>Passwort (mindestens 10 Zeichen)</span><input type="password" name="passwort" minlength="10" autocomplete="new-password" required autofocus></label>
      <label class="feld"><span>Passwort wiederholen</span><input type="password" name="passwort2" minlength="10" autocomplete="new-password" required></label>
      <?php if (!$zuruecksetzen): ?>
        <label class="feld"><span>E-Mail für Alarme</span><input type="email" name="email" autocomplete="email" placeholder="name@gmail.com"></label>
        <p class="klein leise">Die Adresse wird nur auf deinem Server gespeichert, nicht im (öffentlichen) Programmcode.</p>
      <?php endif; ?>
      <button class="knopf voll" type="submit"><?= ico('haken') ?> Speichern und loslegen</button>
    </form>
  <?php endif; ?>
</div>
<?php
    return (string)ob_get_clean();
}

// ---------------------------------------------------------------------------
// Bausteine für Firmenlisten
// ---------------------------------------------------------------------------

function naechstesLimit(array $f, ?float $kurs): ?array
{
    if ($kurs === null || $kurs <= 0) {
        return null;
    }
    $best = null;
    foreach ($f['limits'] as $l) {
        if (empty($l['aktiv']) || !empty($l['ausgeloest'])) {
            continue;
        }
        $abstand = ((float)$l['wert'] - $kurs) / $kurs;
        if ($best === null || abs($abstand) < abs($best['abstand'])) {
            $best = ['abstand' => $abstand, 'limit' => $l];
        }
    }
    return $best;
}

function letztePruefung(array $f): ?array
{
    $p = $f['pruefungen'];
    return $p === [] ? null : $p[count($p) - 1];
}

function firmaZeile(array $f, ?array $k, bool $mitStatus = false): string
{
    $kurs = $k['kurs'] ?? null;
    $w = (string)($k['waehrung'] ?? $f['waehrung']);
    $abzeichen = [];
    $nl = naechstesLimit($f, $kurs);
    if ($nl !== null) {
        $abzeichen[] = '<span class="abz">' . ico('glocke') . proz($nl['abstand'], 1, true) . '</span>';
    }
    foreach ($f['limits'] as $l) {
        if (!empty($l['ausgeloest'])) {
            $abzeichen[] = '<span class="abz rot">' . ico('glocke') . 'ausgelöst</span>';
            break;
        }
    }
    if ($f['wiedervorlage'] !== '') {
        $faellig = $f['wiedervorlage'] <= date('Y-m-d');
        $abzeichen[] = '<span class="abz' . ($faellig ? ' gelb' : '') . '">' . ico('kalender') . e(datum($f['wiedervorlage'])) . '</span>';
    }
    $lp = letztePruefung($f);
    $unter = e($f['symbol']) . ($lp !== null ? ' · geprüft ' . e(vorZeit((int)$lp['zeit'])) : ' · noch nicht geprüft');
    ob_start(); ?>
<a class="zeile" href="<?= e(firmaUrl($f['symbol'])) ?>">
  <?= avatar($f['name'], $f['symbol']) ?>
  <span class="z-mitte">
    <span class="z-name"><?= e($f['name']) ?></span>
    <span class="z-sub"><?= $mitStatus ? pill($f['status']) . ' ' : '' ?><?= $unter ?></span>
    <?php if ($abzeichen !== []): ?><span class="z-abz"><?= implode('', $abzeichen) ?></span><?php endif; ?>
  </span>
  <span class="z-rechts">
    <span class="z-kurs"><?= e(geld($kurs, $w)) ?></span>
    <?= aendHtml($k['aend_proz'] ?? null) ?>
  </span>
</a>
<?php
    return (string)ob_get_clean();
}

function suchfeld(string $wert = '', bool $gross = false): string
{
    return '<form class="suchfeld' . ($gross ? ' gross' : '') . '" method="get" action="./" role="search">'
        . '<input type="hidden" name="seite" value="suche">' . ico('suche')
        . '<input type="search" name="q" value="' . e($wert) . '" placeholder="Firma prüfen: Name, Kürzel, ISIN oder WKN" autocomplete="off" data-vorschlaege>'
        . '<div class="vorschlaege" hidden></div></form>';
}

// ---------------------------------------------------------------------------
// Übersicht
// ---------------------------------------------------------------------------

function seiteUebersicht(array $d, array $kurse, ?array $depot): string
{
    $heute = date('Y-m-d');
    $hinweise = [];
    $neu = array_values(array_filter($d['ereignisse'], static fn(array $e): bool => empty($e['gelesen'])));
    foreach (array_slice(array_reverse($neu), 0, 5) as $ev) {
        $hinweise[] = '<a class="hinweis-zeile rot" href="' . e(firmaUrl((string)$ev['symbol'])) . '">' . ico($ev['art'] === 'limit' ? 'glocke' : ($ev['art'] === 'termin' ? 'kalender' : 'uhr'))
            . '<span>' . e($ev['text']) . '<small>' . e(vorZeit((int)$ev['zeit'])) . '</small></span></a>';
    }
    foreach ($d['firmen'] as $f) {
        if ($f['wiedervorlage'] !== '' && $f['wiedervorlage'] <= $heute) {
            $hinweise[] = '<a class="hinweis-zeile gelb" href="' . e(firmaUrl($f['symbol'])) . '">' . ico('kalender') . '<span>Wiedervorlage fällig: <strong>' . e($f['name']) . '</strong><small>seit ' . e(datum($f['wiedervorlage'])) . '</small></span></a>';
        }
        foreach (['zahlen' => 'Quartalszahlen', 'exdiv' => 'Dividenden-Stichtag'] as $art => $text) {
            $tag = (string)($f['termine'][$art] ?? '');
            if ($tag !== '' && $f['status'] !== 'verworfen' && tageBis($tag) >= 0 && tageBis($tag) <= 7) {
                $hinweise[] = '<a class="hinweis-zeile" href="' . e(firmaUrl($f['symbol'])) . '">' . ico('kalender') . '<span>' . e($text) . ': <strong>' . e($f['name']) . '</strong><small>' . e(tageBis($tag) === 0 ? 'heute' : 'am ' . datum($tag)) . '</small></span></a>';
            }
        }
    }
    if (einstellung($d, 'email') === '' && einstellung($d, 'ntfy') === '') {
        $hinweise[] = '<a class="hinweis-zeile" href="' . e(url(['seite' => 'einstellungen'])) . '">' . ico('info') . '<span>Trage in den Einstellungen eine E-Mail-Adresse oder Push ein, damit dich Alarme erreichen.</span></a>';
    }
    $gruppen = [];
    foreach ($d['firmen'] as $f) {
        $gruppen[$f['status']][] = $f;
    }
    ob_start(); ?>
<section class="begruessung">
  <h1><?= e(gruss()) ?>!</h1>
  <p class="leise"><?= e(datumLang(time())) ?></p>
</section>
<?= suchfeld('', true) ?>
<?php if ($hinweise !== []): ?>
  <section class="karte hinweise">
    <h2 class="karten-titel"><?= ico('glocke') ?> Für dich</h2>
    <?= implode('', $hinweise) ?>
    <?php if ($neu !== []): ?>
      <form method="post" action="./" class="rechts"><?= csrfFeld() ?><input type="hidden" name="aktion" value="ereignisse_gelesen"><button class="knopf-text" type="submit"><?= ico('haken') ?> Alle Meldungen als gelesen markieren</button></form>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php if ($depot !== null && $depot['gesamt']['anzahl'] > 0): $g = $depot['gesamt']; ?>
  <a class="karte depot-kurz" href="<?= e(url(['seite' => 'depot'])) ?>">
    <span class="karten-titel"><?= ico('depot') ?> Depot</span>
    <span class="depot-wert"><?= e(geld($g['wert'])) ?></span>
    <span class="depot-zeile">
      <span>Heute <b class="<?= klasse($g['heute']) ?>"><?= e(($g['heute'] > 0 ? '+' : '') . geld($g['heute'])) ?></b></span>
      <span>Gesamt <b class="<?= klasse($g['gv']) ?>"><?= e(($g['gv'] > 0 ? '+' : '') . geld($g['gv'])) ?></b> <?= $g['einstand'] > 0 ? '(' . proz($g['gv'] / $g['einstand'], 1, true) . ')' : '' ?></span>
    </span>
  </a>
<?php endif; ?>
<?php if ($d['firmen'] === []): ?>
  <section class="karte willkommen">
    <h2>Deine Finanzzentrale ist bereit</h2>
    <p>Suche oben nach einer börsennotierten Firma – per Name, Kürzel, ISIN oder WKN. Jede Firma, die du dir ansiehst, landet automatisch in <strong>Meine Firmen</strong>, mit Datum und Kurs der Prüfung.</p>
    <p class="beispiele">Zum Ausprobieren:
      <a class="chip" href="<?= e(firmaUrl('SAP.DE')) ?>">SAP</a>
      <a class="chip" href="<?= e(firmaUrl('ALV.DE')) ?>">Allianz</a>
      <a class="chip" href="<?= e(firmaUrl('AAPL')) ?>">Apple</a>
      <a class="chip" href="<?= e(firmaUrl('MSFT')) ?>">Microsoft</a>
    </p>
    <p class="klein leise">Dein Trade-Republic- oder eToro-Depot holst du unter <a href="<?= e(url(['seite' => 'depot'])) ?>">Depot</a> herein.</p>
  </section>
<?php else: ?>
  <h2 class="abschnitt">Meine Firmen</h2>
  <?php foreach (FZ_STATUS as $status => $titel):
      $liste = $gruppen[$status] ?? [];
      if ($liste === []) {
          continue;
      }
      usort($liste, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
      $zeilen = '';
      foreach ($liste as $f) {
          $zeilen .= firmaZeile($f, $kurse[$f['symbol']] ?? null);
      }
      echo akk($titel, ['depot' => 'depot', 'kandidat' => 'ziel', 'beobachten' => 'puls', 'geprueft' => 'liste', 'verworfen' => 'x'][$status],
          '<div class="zeilen">' . $zeilen . '</div>', ['offen' => $status !== 'verworfen', 'meta' => (string)count($liste), 'id' => 'start-' . $status]);
  endforeach; ?>
<?php endif; ?>
<?php
    return (string)ob_get_clean();
}

// ---------------------------------------------------------------------------
// Meine Firmen
// ---------------------------------------------------------------------------

function seiteFirmen(array $d, array $kurse, string $filter, string $sortierung): string
{
    $liste = array_values(array_filter($d['firmen'], static fn(array $f): bool => $filter === '' || $f['status'] === $filter));
    $seitPruefung = static function (array $f) use ($kurse): ?float {
        $erste = $f['pruefungen'][0]['kurs'] ?? null;
        $jetzt = $kurse[$f['symbol']]['kurs'] ?? null;
        return $erste && $jetzt ? ($jetzt - $erste) / $erste : null;
    };
    usort($liste, static function (array $a, array $b) use ($sortierung, $kurse, $seitPruefung): int {
        switch ($sortierung) {
            case 'heute':
                return ($kurse[$b['symbol']]['aend_proz'] ?? -9) <=> ($kurse[$a['symbol']]['aend_proz'] ?? -9);
            case 'pruefung':
                return (int)(letztePruefung($b)['zeit'] ?? 0) <=> (int)(letztePruefung($a)['zeit'] ?? 0);
            case 'seit':
                return ($seitPruefung($b) ?? -9) <=> ($seitPruefung($a) ?? -9);
            case 'bewertung':
                return (int)$b['bewertung'] <=> (int)$a['bewertung'];
            default:
                return strcasecmp($a['name'], $b['name']);
        }
    });
    $zaehler = array_count_values(array_column($d['firmen'], 'status'));
    ob_start(); ?>
<h1 class="seitentitel">Meine Firmen</h1>
<div class="chips">
  <a class="chip<?= $filter === '' ? ' aktiv' : '' ?>" href="<?= e(url(['seite' => 'firmen', 'sort' => $sortierung])) ?>">Alle <b><?= count($d['firmen']) ?></b></a>
  <?php foreach (FZ_STATUS as $s => $t): if (empty($zaehler[$s])) continue; ?>
    <a class="chip<?= $filter === $s ? ' aktiv' : '' ?>" href="<?= e(url(['seite' => 'firmen', 'status' => $s, 'sort' => $sortierung])) ?>"><?= e($t) ?> <b><?= (int)$zaehler[$s] ?></b></a>
  <?php endforeach; ?>
</div>
<div class="werkzeugleiste">
  <input type="search" class="filterfeld" placeholder="In der Liste filtern …" data-filter="#firmenliste">
  <form method="get" action="./" class="sortieren">
    <input type="hidden" name="seite" value="firmen"><input type="hidden" name="status" value="<?= e($filter) ?>">
    <select name="sort" data-auto-absenden aria-label="Sortierung">
      <?php foreach (['name' => 'Name', 'heute' => 'Heute', 'seit' => 'Seit erster Prüfung', 'pruefung' => 'Zuletzt geprüft', 'bewertung' => 'Meine Bewertung'] as $k => $t): ?>
        <option value="<?= e($k) ?>"<?= $sortierung === $k ? ' selected' : '' ?>><?= e($t) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>
<section class="karte ohne-rand" id="firmenliste">
  <?php if ($liste === []): ?>
    <?= leer($d['firmen'] === [] ? 'Noch keine Firmen. Über <a href="' . e(url(['seite' => 'suche'])) . '">Firma prüfen</a> fügst du die erste hinzu.' : 'Keine Firma mit diesem Status.') ?>
  <?php else: foreach ($liste as $f):
      $seit = $seitPruefung($f); ?>
    <div class="filterbar">
      <?= firmaZeile($f, $kurse[$f['symbol']] ?? null, true) ?>
      <?php if ($seit !== null || $f['bewertung'] > 0): ?>
        <div class="zeile-extra">
          <?php if ($seit !== null): ?><span>Seit erster Prüfung <b class="<?= klasse($seit) ?>"><?= proz($seit, 1, true) ?></b></span><?php endif; ?>
          <?php if ($f['bewertung'] > 0): ?><span class="sterne-klein" aria-label="<?= (int)$f['bewertung'] ?> von 5 Sternen"><?= str_repeat('★', (int)$f['bewertung']) . '<i>' . str_repeat('★', 5 - (int)$f['bewertung']) . '</i>' ?></span><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; endif; ?>
</section>
<?php
    return (string)ob_get_clean();
}

// ---------------------------------------------------------------------------
// Suche
// ---------------------------------------------------------------------------

function seiteSuche(array $d, string $q, array $treffer): string
{
    ob_start(); ?>
<h1 class="seitentitel">Firma prüfen</h1>
<?= suchfeld($q, true) ?>
<?php if ($q === ''): ?>
  <section class="karte">
    <p>Suche nach Name (z. B. <em>Siemens</em>), Börsenkürzel (<em>SIE.DE</em>, <em>AAPL</em>), ISIN (<em>DE0007236101</em>) oder WKN (<em>723610</em>).</p>
    <p class="klein leise">Jede Firma, die du öffnest, wird automatisch in „Meine Firmen“ gespeichert – mit Datum und Kurs der Prüfung.</p>
  </section>
<?php elseif ($treffer === []): ?>
  <section class="karte"><?= leer('Keine börsennotierte Firma zu „' . e($q) . '“ gefunden. Tipp: Mit der ISIN oder WKN ist die Suche eindeutig.') ?></section>
<?php else: ?>
  <section class="karte ohne-rand">
    <?php foreach ($treffer as $t): $bekannt = isset($d['firmen'][$t['symbol']]); ?>
      <a class="zeile" href="<?= e(firmaUrl($t['symbol'], $t['isin'] ?? '')) ?>">
        <?= avatar($t['name'], $t['symbol']) ?>
        <span class="z-mitte">
          <span class="z-name"><?= e($t['name']) ?></span>
          <span class="z-sub"><?= e($t['symbol']) ?><?= $t['boerse'] !== '' ? ' · ' . e($t['boerse']) : '' ?><?= $t['branche'] !== '' ? ' · ' . e($t['branche']) : '' ?></span>
        </span>
        <span class="z-rechts"><?= $bekannt ? pill($d['firmen'][$t['symbol']]['status']) : ico('pfeil', 'nach-rechts') ?></span>
      </a>
    <?php endforeach; ?>
  </section>
  <p class="klein leise">Dieselbe Aktie gibt es oft an mehreren Börsen. Für deutsche Firmen ist meist Xetra (Kürzel mit „.DE“) die beste Wahl.</p>
<?php endif; ?>
<?php
    return (string)ob_get_clean();
}

// ---------------------------------------------------------------------------
// Firmen-Steckbrief
// ---------------------------------------------------------------------------

/** [Titel, Schlüssel, Format, Ampel-Grenzen [a, b, höher ist besser], Erklärung] */
const FZ_KENNZAHLEN = [
    'Bewertung' => [
        ['KGV', 'kgv', 'x', [15, 25, false], 'Kurs-Gewinn-Verhältnis: Wie viele Jahresgewinne kostet die Aktie? Niedriger heißt günstiger.'],
        ['KGV erwartet', 'kgv_erw', 'x', [15, 25, false], 'KGV auf Basis der für die nächsten zwölf Monate erwarteten Gewinne.'],
        ['KBV', 'kbv', 'x', [1.5, 4, false], 'Kurs-Buchwert-Verhältnis: Börsenwert im Verhältnis zum bilanziellen Eigenkapital.'],
        ['KUV', 'kuv', 'x', [2, 5, false], 'Kurs-Umsatz-Verhältnis: Börsenwert im Verhältnis zum Jahresumsatz.'],
        ['PEG', 'peg', 'x', [1, 2, false], 'KGV geteilt durch das erwartete Gewinnwachstum. Unter 1 gilt als günstig.'],
        ['EV/EBITDA', 'ev_ebitda', 'x', [10, 15, false], 'Unternehmenswert (inklusive Schulden) im Verhältnis zum operativen Ergebnis vor Abschreibungen.'],
    ],
    'Profitabilität' => [
        ['Bruttomarge', 'bruttomarge', '%', [0.4, 0.2, true], 'Anteil vom Umsatz, der nach den direkten Herstellkosten übrig bleibt.'],
        ['Operative Marge', 'opmarge', '%', [0.2, 0.1, true], 'Operativer Gewinn (EBIT) im Verhältnis zum Umsatz.'],
        ['Nettomarge', 'nettomarge', '%', [0.15, 0.05, true], 'Gewinn nach Steuern im Verhältnis zum Umsatz.'],
        ['Eigenkapitalrendite', 'ek_rendite', '%', [0.15, 0.08, true], 'Gewinn im Verhältnis zum Eigenkapital (ROE).'],
        ['Gesamtkapitalrendite', 'gk_rendite', '%', [0.07, 0.03, true], 'Gewinn im Verhältnis zum gesamten eingesetzten Kapital (ROA).'],
    ],
    'Wachstum' => [
        ['Umsatzwachstum', 'umsatzwachstum', '%±', [0.1, 0.0, true], 'Veränderung zum Vorjahresquartal.'],
        ['Gewinnwachstum', 'gewinnwachstum', '%±', [0.1, 0.0, true], 'Veränderung zum Vorjahresquartal.'],
        ['Umsatz (12 Monate)', 'umsatz', 'gross', null, 'Umsatz der letzten vier Quartale.'],
        ['Gewinn je Aktie', 'eps', 'geld', null, 'Gewinn der letzten zwölf Monate je Aktie.'],
        ['Gewinn je Aktie erwartet', 'eps_erw', 'geld', null, 'Von Analysten erwarteter Gewinn je Aktie.'],
    ],
    'Bilanz & Cashflow' => [
        ['Verschuldung/Eigenkapital', 'verschuldung', '%roh', [50, 150, false], 'Finanzschulden im Verhältnis zum Eigenkapital. Bei Banken und Versicherern wenig aussagekräftig.'],
        ['Liquidität (Current Ratio)', 'current_ratio', 'x', [1.5, 1.0, true], 'Kurzfristiges Vermögen geteilt durch kurzfristige Schulden. Über 1 heißt: kurzfristig gut gedeckt.'],
        ['Barmittel', 'cash', 'gross', null, 'Kasse und kurzfristige Anlagen.'],
        ['Finanzschulden', 'schulden', 'gross', null, 'Summe der Finanzschulden.'],
        ['Freier Cashflow', 'fcf', 'gross', null, 'Was nach Investitionen vom operativen Geldzufluss übrig bleibt.'],
    ],
    'Dividende' => [
        ['Dividendenrendite', 'div_rendite', '%', [0.03, 0.01, true], 'Jährliche Dividende im Verhältnis zum Kurs.'],
        ['Dividende je Aktie', 'div_je_aktie', 'geld', null, 'Jährliche Dividende je Aktie.'],
        ['Ausschüttungsquote', 'ausschuettung', '%', [0.6, 0.9, false], 'Anteil des Gewinns, der als Dividende ausgezahlt wird. Über 90 % lässt wenig Puffer.'],
        ['Letzter Ex-Tag', 'ex_div', 'datum', null, 'Wer die Aktie vor diesem Tag hielt, bekam die letzte Dividende.'],
    ],
    'Risiko & Handel' => [
        ['Beta', 'beta', 'x', null, 'Schwankung im Vergleich zum Gesamtmarkt (1 = wie der Markt, 1,5 = 50 % stärker).'],
        ['Börsenwert', 'boersenwert', 'gross', null, 'Wert aller Aktien zum aktuellen Kurs.'],
        ['Aktien im Umlauf', 'aktien', 'gross', null, 'Anzahl der ausgegebenen Aktien.'],
        ['Gleitender Durchschnitt 200 Tage', 'gd200', 'geld', null, 'Durchschnittskurs der letzten 200 Handelstage – ein gängiger Trendindikator.'],
    ],
];

function ampel(?float $v, ?array $grenzen, string $schluessel): string
{
    if ($v === null || $grenzen === null) {
        return '';
    }
    [$a, $b, $hoeherBesser] = $grenzen;
    if (in_array($schluessel, ['div_rendite', 'ausschuettung'], true) && $v <= 0) {
        return '';
    }
    if ($hoeherBesser) {
        if ($schluessel === 'div_rendite' && $v < $b) {
            return ''; // wenig Dividende ist kein Mangel
        }
        return $v >= $a ? 'gruen' : ($v >= $b ? 'gelb' : 'rot');
    }
    if ($v < 0) {
        return 'rot'; // z. B. negatives KGV = Verlust
    }
    return $v <= $a ? 'gruen' : ($v <= $b ? 'gelb' : 'rot');
}

function kennzahlWert(array $p, string $schluessel, string $format): string
{
    $v = $p[$schluessel] ?? null;
    switch ($format) {
        case 'x':
            return zahl($v !== null ? (float)$v : null, abs((float)$v) >= 100 ? 0 : (abs((float)$v) >= 10 ? 1 : 2));
        case '%':
            return proz($v !== null ? (float)$v : null);
        case '%±':
            return proz($v !== null ? (float)$v : null, 1, true);
        case '%roh':
            return $v !== null ? zahl((float)$v, 0) . "\u{00A0}%" : '–';
        case 'gross':
            return gross($v !== null ? (float)$v : null, (string)($p['bilanzwaehrung'] ?: $p['waehrung']));
        case 'geld':
            $w = in_array($schluessel, ['eps', 'eps_erw'], true) && $p['bilanzwaehrung'] !== '' ? $p['bilanzwaehrung'] : $p['waehrung'];
            return geld($v !== null ? (float)$v : null, (string)$w);
        case 'datum':
            return $v ? datum((int)$v) : '–';
    }
    return (string)$v;
}

function kacheln(array $kacheln): string
{
    $html = '<div class="raster">';
    foreach ($kacheln as [$titel, $wert, $unter, $ampel]) {
        $html .= '<div class="kachel"><span class="k-titel">' . e($titel) . '</span><span class="k-wert">'
            . ($ampel !== '' ? '<i class="ampel ' . e($ampel) . '"></i>' : '') . $wert . '</span>'
            . ($unter !== '' ? '<span class="k-unter">' . $unter . '</span>' : '') . '</div>';
    }
    return $html . '</div>';
}

function spanneHtml(?float $tief, ?float $hoch, ?float $kurs, string $w): string
{
    if ($tief === null || $hoch === null || $kurs === null || $hoch <= $tief) {
        return '';
    }
    $pos = max(0, min(100, ($kurs - $tief) / ($hoch - $tief) * 100));
    return '<div class="spanne"><div class="spanne-titel">52-Wochen-Spanne</div><div class="spanne-balken"><i style="left:' . round($pos, 1) . '%"></i></div>'
        . '<div class="spanne-werte"><span>' . e(geld($tief, $w)) . '</span><span>' . e(geld($hoch, $w)) . '</span></div></div>';
}

function balkenSvg(array $jahre, string $w): string
{
    $jahre = array_values(array_filter($jahre, static fn(array $j): bool => $j['umsatz'] !== null || $j['gewinn'] !== null));
    if ($jahre === []) {
        return leer('Keine Jahreszahlen verfügbar.');
    }
    $max = 0.0;
    foreach ($jahre as $j) {
        $max = max($max, abs((float)$j['umsatz']), abs((float)$j['gewinn']));
    }
    $max = $max > 0 ? $max : 1.0;
    $breite = 100 / count($jahre);
    $html = '<div class="balkengrafik" role="img" aria-label="Umsatz und Gewinn je Jahr">';
    foreach ($jahre as $j) {
        $u = $j['umsatz'] !== null ? abs((float)$j['umsatz']) / $max * 100 : 0;
        $g = $j['gewinn'] !== null ? abs((float)$j['gewinn']) / $max * 100 : 0;
        $html .= '<div class="bg-jahr" style="width:' . round($breite, 2) . '%">'
            . '<div class="bg-saeulen"><span class="bg-u" style="height:' . round($u, 1) . '%" title="Umsatz ' . e(gross($j['umsatz'], $w)) . '"></span>'
            . '<span class="bg-g' . ((float)$j['gewinn'] < 0 ? ' neg' : '') . '" style="height:' . round(max($g, 1), 1) . '%" title="Gewinn ' . e(gross($j['gewinn'], $w)) . '"></span></div>'
            . '<div class="bg-zahl">' . e(gross($j['umsatz'])) . '<br><b class="' . klasse((float)$j['gewinn']) . '">' . e(gross($j['gewinn'])) . '</b></div>'
            . '<div class="bg-label">' . e($j['jahr']) . '</div></div>';
    }
    return $html . '</div><p class="legende"><i class="lg-u"></i> Umsatz <i class="lg-g"></i> Gewinn' . ($w !== '' ? ' · in ' . e($w) : '') . '</p>';
}

function modellName(string $id): string
{
    return ['claude-opus-5-5' => 'Claude Opus 5.5', 'claude-haiku-4-5' => 'Claude Haiku 4.5', 'claude-opus-5' => 'Claude Opus 5', 'claude-opus-4-8' => 'Claude Opus 4.8'][$id] ?? $id;
}

function urteilText(string $schluessel): string
{
    return ['strong_buy' => 'Stark kaufen', 'buy' => 'Kaufen', 'hold' => 'Halten', 'underperform' => 'Untergewichten', 'sell' => 'Verkaufen'][$schluessel] ?? '–';
}

function empfehlungHtml(array $p): string
{
    $e = $p['empfehlungen'];
    $html = '';
    if (is_array($e) && array_sum($e) > 0) {
        $summe = array_sum($e);
        $teile = ['stark_kaufen' => 'Stark kaufen', 'kaufen' => 'Kaufen', 'halten' => 'Halten', 'verkaufen' => 'Verkaufen', 'stark_verkaufen' => 'Stark verkaufen'];
        $html .= '<div class="stapel" role="img" aria-label="Verteilung der Analysten-Empfehlungen">';
        foreach ($teile as $k => $t) {
            if ($e[$k] > 0) {
                $html .= '<span class="st-' . $k . '" style="flex:' . $e[$k] . '" title="' . e($t) . ': ' . $e[$k] . '">' . $e[$k] . '</span>';
            }
        }
        $html .= '</div><div class="stapel-legende">';
        foreach ($teile as $k => $t) {
            $html .= '<span><i class="st-' . $k . '"></i>' . e($t) . ' ' . $e[$k] . '</span>';
        }
        $html .= '</div><p class="klein leise">' . $summe . ' Analysten, aktueller Monat.</p>';
    }
    $w = (string)$p['waehrung'];
    if ($p['kursziel'] !== null) {
        $potenzial = $p['kurs'] ? ($p['kursziel'] - $p['kurs']) / $p['kurs'] : null;
        $urteil = urteilText($p['empfehlung']);
        $html .= kacheln([
            ['Kursziel Ø', e(geld($p['kursziel'], $w)), ($potenzial !== null ? '<span class="' . klasse($potenzial) . '">' . proz($potenzial, 1, true) . ' Potenzial</span>' : '')
                . ($p['analysten'] > 0 ? ' · ' . (int)$p['analysten'] . ' Kursziele' : ''), ''],
            ['Spanne der Kursziele', e(geld($p['kursziel_tief'], $w)) . ' – ' . e(geld($p['kursziel_hoch'], $w)), '', ''],
            ['Gesamturteil', e($urteil), $p['empfehlung_wert'] !== null ? 'Note ' . e(zahl($p['empfehlung_wert'], 1)) . ' (1 = stark kaufen, 5 = verkaufen)' : '', ''],
        ]);
    }
    if ($p['analystenwechsel'] !== []) {
        $html .= '<h4>Letzte Einstufungen</h4><ul class="liste-schlicht">';
        foreach ($p['analystenwechsel'] as $a) {
            $html .= '<li><span>' . e(datum($a['zeit'])) . ' · <strong>' . e($a['firma']) . '</strong>: ' . e($a['nach'])
                . ($a['von'] !== '' && $a['von'] !== $a['nach'] ? ' (vorher ' . e($a['von']) . ')' : '') . '</span>'
                . ($a['ziel'] !== null ? '<b>' . e(geld($a['ziel'], $w)) . '</b>' : '') . '</li>';
        }
        $html .= '</ul>';
    }
    return $html !== '' ? $html : leer('Keine Analystendaten verfügbar.');
}

function seiteFirma(array $d, string $symbol, ?array $p, ?array $depot, bool $kiVerfuegbar): string
{
    $f = $d['firmen'][$symbol] ?? null;
    if ($p === null) {
        ob_start(); ?>
<div class="zurueck"><a href="<?= e(url(['seite' => 'firmen'])) ?>"><?= ico('zurueck') ?> Meine Firmen</a></div>
<section class="karte">
  <h1><?= e($f['name'] ?? $symbol) ?></h1>
  <div class="fehlerbox">Zu „<?= e($symbol) ?>“ sind gerade keine Kursdaten abrufbar. Entweder ist das Kürzel unbekannt oder Yahoo Finance ist kurz nicht erreichbar – bitte später noch einmal versuchen.</div>
  <?php if ($f !== null): ?><p>Deine Notizen und Limits zu dieser Firma bleiben natürlich gespeichert.</p><?php endif; ?>
</section>
<?php
        return (string)ob_get_clean();
    }
    $f ??= firmaNormal(['name' => $p['name']], $symbol);
    $w = (string)$p['waehrung'];
    $s = $symbol;
    $erste = $f['pruefungen'][0] ?? null;
    $letzte = letztePruefung($f);
    $seitErster = $erste && $p['kurs'] && !empty($erste['kurs']) ? ($p['kurs'] - $erste['kurs']) / $erste['kurs'] : null;

    // --- Auf einen Blick
    $potenzial = $p['kursziel'] !== null && $p['kurs'] ? ($p['kursziel'] - $p['kurs']) / $p['kurs'] : null;
    $termine = $p['termine'];
    $blick = kacheln([
        ['Börsenwert', e(gross($p['boersenwert'], $w)), '', ''],
        ['KGV', e(zahl($p['kgv'], 1)), 'erwartet ' . e(zahl($p['kgv_erw'], 1)), ampel($p['kgv'], [15, 25, false], 'kgv')],
        ['Dividendenrendite', e(proz($p['div_rendite'])), $p['div_je_aktie'] !== null ? e(geld($p['div_je_aktie'], $w)) . ' je Aktie' : '', ''],
        ['Analysten-Kursziel', e(geld($p['kursziel'], $w)), $potenzial !== null ? '<span class="' . klasse($potenzial) . '">' . proz($potenzial, 1, true) . '</span>' : '', ''],
        ['Nettomarge', e(proz($p['nettomarge'])), '', ampel($p['nettomarge'], [0.15, 0.05, true], 'nettomarge')],
        ['Umsatzwachstum', e(proz($p['umsatzwachstum'], 1, true)), 'zum Vorjahresquartal', ampel($p['umsatzwachstum'], [0.1, 0.0, true], 'umsatzwachstum')],
        ['Nächste Zahlen', e(isset($termine['zahlen']) ? datum($termine['zahlen']) : '–'), isset($termine['zahlen']) ? e(tageBis($termine['zahlen']) >= 0 ? 'in ' . tageBis($termine['zahlen']) . ' Tagen' : 'vorbei') : '', ''],
        ['Branche', e($p['branche'] !== '' ? $p['branche'] : '–'), e(trim($p['land'] . ($p['mitarbeiter'] > 0 ? ' · ' . number_format($p['mitarbeiter'], 0, ',', '.') . ' Mitarbeiter' : ''), ' ·')), ''],
    ]) . spanneHtml($p['tief52'], $p['hoch52'], $p['kurs'], $w);
    if (!$p['kennzahlen_da']) {
        $blick = '<div class="warnbox">Die Kennzahlen sind gerade nicht abrufbar – angezeigt wird, was vorliegt.</div>' . $blick;
    }

    // --- Kennzahlen
    $kz = '<label class="schalter klein-schalter"><input type="checkbox" data-erklaerungen><span>Erklärungen einblenden</span></label>';
    foreach (FZ_KENNZAHLEN as $gruppe => $zeilen) {
        $kz .= '<h4>' . e($gruppe) . '</h4><dl class="kennzahlen">';
        foreach ($zeilen as [$titel, $k, $format, $grenzen, $erkl]) {
            $v = $p[$k] ?? null;
            $farbe = ampel($v !== null && $format !== 'datum' ? (float)$v : null, $grenzen, $k);
            $kz .= '<div class="kz"><dt>' . e($titel) . '</dt><dd>' . ($farbe !== '' ? '<i class="ampel ' . $farbe . '"></i>' : '') . e(kennzahlWert($p, $k, $format)) . '</dd>'
                . '<p class="erkl" hidden>' . e($erkl) . '</p></div>';
        }
        $kz .= '</dl>';
    }
    $kz .= '<p class="legende"><i class="ampel gruen"></i> günstig/stark <i class="ampel gelb"></i> mittel <i class="ampel rot"></i> teuer/schwach – grobe Faustregeln, je nach Branche verschieden.</p>';

    // --- KI
    $ki = is_array($f['ki']) ? $f['ki'] : null;
    ob_start(); ?>
<div class="ki-bereich" data-ki="<?= e($s) ?>">
  <?php if (!$kiVerfuegbar): ?>
    <div class="hinweis">Für die KI-Einschätzung fehlt noch der Anthropic-Schlüssel auf dem Server (GitHub-Secret <code>ANTHROPIC_API_KEY</code>).</div>
  <?php endif; ?>
  <?php if ($ki !== null): ?>
    <p class="klein leise">Erstellt <?= e(datumZeit((int)$ki['zeit'])) ?> mit <?= e(modellName((string)$ki['modell'])) ?> · Web-Recherche, keine Anlageberatung.</p>
    <div class="ki-text"><?= mdHtml((string)$ki['text']) ?></div>
    <?php if (!empty($ki['quellen'])): ?>
      <details class="quellen"><summary>Quellen (<?= count($ki['quellen']) ?>)</summary><ul>
        <?php foreach ($ki['quellen'] as $q): ?><li><a href="<?= e($q['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($q['titel']) ?></a></li><?php endforeach; ?>
      </ul></details>
    <?php endif; ?>
  <?php else: ?>
    <p>Die KI recherchiert im Web und fasst zusammen: Geschäftsmodell, aktuelle Entwicklung, Großaktionäre, Beteiligungen, Stärken, Risiken und Bewertung.</p>
  <?php endif; ?>
  <?php if ($kiVerfuegbar): ?>
    <button class="knopf<?= $ki !== null ? ' zweit' : '' ?>" type="button" data-ki-start><?= ico($ki !== null ? 'neu' : 'blitz') ?> <?= $ki !== null ? 'Neu erstellen' : 'KI-Einschätzung erstellen' ?></button>
    <span class="klein leise ki-dauer">dauert etwa 1–2 Minuten · <?= e(kiModell($d) === 'claude-haiku-4-5' ? 'ca. 10 Cent' : 'ca. 20–40 Cent') ?></span>
    <div class="ki-status" hidden></div>
  <?php endif; ?>
</div>
<?php
    $kiHtml = (string)ob_get_clean();

    // --- Notizen
    $notizen = array_reverse($f['notizen']);
    ob_start(); ?>
<form method="post" action="./" class="formular">
  <?= csrfFeld() ?><input type="hidden" name="aktion" value="these"><input type="hidden" name="s" value="<?= e($s) ?>">
  <label class="feld"><span>Meine Investment-These</span><textarea name="these" rows="3" placeholder="Warum könnte sich ein Investment lohnen – und was müsste passieren, damit ich falsch liege?"><?= e($f['these']) ?></textarea></label>
  <button class="knopf klein zweit" type="submit"><?= ico('haken') ?> These speichern</button>
</form>
<form method="post" action="./" class="formular notiz-neu">
  <?= csrfFeld() ?><input type="hidden" name="aktion" value="notiz_neu"><input type="hidden" name="s" value="<?= e($s) ?>">
  <label class="feld"><span>Neue Notiz</span><textarea name="text" rows="2" placeholder="Beobachtung, Gedanke, Quelle …" required></textarea></label>
  <button class="knopf klein" type="submit"><?= ico('plus') ?> Notiz hinzufügen</button>
</form>
<?php if ($notizen === []): ?>
  <?= leer('Noch keine Notizen.') ?>
<?php else: ?>
  <ul class="notizen">
    <?php foreach ($notizen as $n): ?>
      <li>
        <div class="notiz-kopf"><span><?= e(datumZeit((int)$n['zeit'])) ?><?= !empty($n['geaendert']) ? ' · bearbeitet' : '' ?></span>
          <form method="post" action="./" data-bestaetigen="Notiz löschen?"><?= csrfFeld() ?><input type="hidden" name="aktion" value="notiz_loeschen"><input type="hidden" name="s" value="<?= e($s) ?>"><input type="hidden" name="id" value="<?= e($n['id']) ?>"><button class="knopf-icon" type="submit" aria-label="Notiz löschen"><?= ico('muell') ?></button></form>
        </div>
        <div class="notiz-text"><?= nl2br(e($n['text'])) ?></div>
        <details class="notiz-bearbeiten"><summary><?= ico('stift') ?> Bearbeiten</summary>
          <form method="post" action="./" class="formular"><?= csrfFeld() ?><input type="hidden" name="aktion" value="notiz_aendern"><input type="hidden" name="s" value="<?= e($s) ?>"><input type="hidden" name="id" value="<?= e($n['id']) ?>">
            <textarea name="text" rows="3" required><?= e($n['text']) ?></textarea>
            <button class="knopf klein" type="submit">Speichern</button>
          </form>
        </details>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php
    $notizHtml = (string)ob_get_clean();

    // --- Limits & Erinnerungen
    ob_start(); ?>
<?php if ($f['limits'] === []): ?>
  <?= leer('Noch kein Kurslimit. Lege fest, ab welchem Kurs du benachrichtigt werden willst.') ?>
<?php else: ?>
  <ul class="limits">
    <?php foreach ($f['limits'] as $l):
        $abstand = $p['kurs'] ? ((float)$l['wert'] - $p['kurs']) / $p['kurs'] : null; ?>
      <li class="<?= !empty($l['ausgeloest']) ? 'ausgeloest' : '' ?>">
        <span class="limit-art"><?= ico($l['typ'] === 'unter' ? 'faellt' : 'trend') ?></span>
        <span class="limit-text"><strong><?= e(limitText($l, $w)) ?></strong>
          <small><?php if (!empty($l['ausgeloest'])): ?>ausgelöst <?= e(datumZeit((int)$l['ausgeloest'])) ?> bei <?= e(geld((float)($l['ausloesekurs'] ?? 0), $w)) ?><?php else: ?>aktiv · Abstand <?= e(proz($abstand, 1, true)) ?><?php endif; ?><?= trim((string)($l['notiz'] ?? '')) !== '' ? ' · ' . e((string)$l['notiz']) : '' ?></small></span>
        <span class="limit-knoepfe">
          <?php if (!empty($l['ausgeloest'])): ?>
            <form method="post" action="./"><?= csrfFeld() ?><input type="hidden" name="aktion" value="limit_scharf"><input type="hidden" name="s" value="<?= e($s) ?>"><input type="hidden" name="id" value="<?= e($l['id']) ?>"><button class="knopf klein zweit" type="submit"><?= ico('neu') ?> Wieder aktivieren</button></form>
          <?php endif; ?>
          <form method="post" action="./" data-bestaetigen="Limit löschen?"><?= csrfFeld() ?><input type="hidden" name="aktion" value="limit_loeschen"><input type="hidden" name="s" value="<?= e($s) ?>"><input type="hidden" name="id" value="<?= e($l['id']) ?>"><button class="knopf-icon" type="submit" aria-label="Limit löschen"><?= ico('muell') ?></button></form>
        </span>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
<form method="post" action="./" class="formular limit-neu" data-kurs="<?= e((string)($p['kurs'] ?? '')) ?>">
  <?= csrfFeld() ?><input type="hidden" name="aktion" value="limit_neu"><input type="hidden" name="s" value="<?= e($s) ?>">
  <h4>Neues Kurslimit</h4>
  <div class="feld-reihe">
    <label class="feld"><span>Melden, wenn der Kurs</span><select name="typ"><option value="unter">fällt unter</option><option value="ueber">steigt über</option></select></label>
    <label class="feld"><span>Wert in <?= e($w) ?></span><input type="text" inputmode="decimal" name="wert" required placeholder="<?= e(zahl($p['kurs'] ? $p['kurs'] * 0.9 : null)) ?>"></label>
  </div>
  <div class="chips klein-chips" data-prozent>
    <span class="leise klein">Vom aktuellen Kurs:</span>
    <?php foreach ([-20, -10, -5, 5, 10, 20] as $pz): ?><button type="button" class="chip" data-pz="<?= $pz ?>"><?= $pz > 0 ? '+' : '−' ?><?= abs($pz) ?> %</button><?php endforeach; ?>
  </div>
  <label class="feld"><span>Notiz (optional)</span><input type="text" name="notiz" maxlength="200" placeholder="z. B. Nachkaufen erwägen"></label>
  <button class="knopf klein" type="submit"><?= ico('glocke') ?> Limit anlegen</button>
</form>
<form method="post" action="./" class="formular">
  <?= csrfFeld() ?><input type="hidden" name="aktion" value="wiedervorlage"><input type="hidden" name="s" value="<?= e($s) ?>">
  <h4>Wiedervorlage</h4>
  <p class="klein leise">An welchem Tag willst du dir die Firma wieder ansehen?<?= $f['wiedervorlage'] !== '' ? ' Aktuell: <strong>' . e(datum($f['wiedervorlage'])) . '</strong>' : '' ?></p>
  <div class="feld-reihe">
    <label class="feld"><span>Datum</span><input type="date" name="datum" value="<?= e($f['wiedervorlage']) ?>"></label>
  </div>
  <div class="chips klein-chips">
    <?php foreach (['+1 month' => '1 Monat', '+3 months' => '3 Monaten', '+6 months' => '6 Monaten', '+1 year' => '1 Jahr'] as $k => $t): ?>
      <button class="chip" type="submit" name="schnell" value="<?= e($k) ?>">in <?= e($t) ?></button>
    <?php endforeach; ?>
    <?php if ($f['wiedervorlage'] !== ''): ?><button class="chip" type="submit" name="schnell" value="weg">entfernen</button><?php endif; ?>
  </div>
  <button class="knopf klein zweit" type="submit"><?= ico('kalender') ?> Datum speichern</button>
</form>
<form method="post" action="./" class="formular">
  <?= csrfFeld() ?><input type="hidden" name="aktion" value="termin_erinnerung"><input type="hidden" name="s" value="<?= e($s) ?>">
  <h4>Termine</h4>
  <ul class="liste-schlicht">
    <li><span>Nächste Quartalszahlen</span><b><?= e(isset($termine['zahlen']) ? datum($termine['zahlen']) : '–') ?></b></li>
    <li><span>Dividenden-Stichtag (Ex-Tag)</span><b><?= e(isset($termine['exdiv']) ? datum($termine['exdiv']) : '–') ?></b></li>
    <?php if (isset($termine['divzahlung'])): ?><li><span>Dividenden-Zahltag</span><b><?= e(datum($termine['divzahlung'])) ?></b></li><?php endif; ?>
  </ul>
  <label class="schalter"><input type="checkbox" name="an" value="1" data-auto-absenden<?= $f['termin_erinnerung'] ? ' checked' : '' ?>><span>Zwei Tage vor Quartalszahlen und Dividenden-Stichtag erinnern</span></label>
</form>
<?php
    $limitHtml = (string)ob_get_clean();

    // --- Depot (alle Quellen) zu dieser Firma
    $meine = [];
    foreach (($depot['positionen'] ?? []) as $pos) {
        if ($pos['symbol'] === $s || ($f['isin'] !== '' && $pos['isin'] === $f['isin'])) {
            $meine[] = $pos;
        }
    }
    ob_start(); ?>
<?php if ($meine !== []): ?>
  <ul class="positionen">
    <?php foreach ($meine as $pos): ?>
      <li><span><strong><?= e(FZ_QUELLEN[$pos['quelle']] ?? $pos['quelle']) ?></strong><small><?= e(zahl($pos['stueck'], $pos['stueck'] < 10 ? 4 : 2)) ?> Stück · Einstand <?= e(geld($pos['einstand'])) ?></small></span>
        <span class="rechts"><b><?= e(geld($pos['wert'])) ?></b><small class="<?= klasse($pos['gv']) ?>"><?= e(($pos['gv'] > 0 ? '+' : '') . geld($pos['gv'])) ?> (<?= e(proz($pos['gv_proz'], 1, true)) ?>)</small></span></li>
    <?php endforeach; ?>
  </ul>
<?php else: ?>
  <?= leer('Diese Firma ist in keinem deiner Depots.') ?>
<?php endif; ?>
<?php if ($f['depot'] !== []): ?>
  <h4>Manuell erfasst</h4>
  <ul class="liste-schlicht">
    <?php foreach (array_reverse($f['depot']) as $t): ?>
      <li><span><?= e(datum($t['datum'])) ?> · <?= e(['kauf' => 'Kauf', 'verkauf' => 'Verkauf', 'dividende' => 'Dividende'][$t['typ']] ?? $t['typ']) ?>
        <?= $t['typ'] === 'dividende' ? e(geld((float)$t['betrag'])) : e(zahl((float)$t['stueck'], 4)) . ' × ' . e(geld((float)$t['kurs'])) ?></span>
        <form method="post" action="./" data-bestaetigen="Buchung löschen?"><?= csrfFeld() ?><input type="hidden" name="aktion" value="depot_loeschen"><input type="hidden" name="s" value="<?= e($s) ?>"><input type="hidden" name="id" value="<?= e($t['id']) ?>"><button class="knopf-icon" type="submit" aria-label="Buchung löschen"><?= ico('muell') ?></button></form></li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
<details class="unterbereich">
  <summary><?= ico('plus') ?> Kauf, Verkauf oder Dividende von Hand erfassen</summary>
  <form method="post" action="./" class="formular">
    <?= csrfFeld() ?><input type="hidden" name="aktion" value="depot_neu"><input type="hidden" name="s" value="<?= e($s) ?>">
    <p class="klein leise">Für Käufe außerhalb von Trade Republic und eToro. Beträge in Euro.</p>
    <div class="feld-reihe">
      <label class="feld"><span>Art</span><select name="typ"><option value="kauf">Kauf</option><option value="verkauf">Verkauf</option><option value="dividende">Dividende</option></select></label>
      <label class="feld"><span>Datum</span><input type="date" name="datum" value="<?= e(date('Y-m-d')) ?>" required></label>
    </div>
    <div class="feld-reihe">
      <label class="feld"><span>Stück</span><input type="text" inputmode="decimal" name="stueck" placeholder="10"></label>
      <label class="feld"><span>Kurs je Aktie in €</span><input type="text" inputmode="decimal" name="kurs" placeholder="<?= e(zahl($p['kurs'])) ?>"></label>
    </div>
    <div class="feld-reihe">
      <label class="feld"><span>Gebühren in €</span><input type="text" inputmode="decimal" name="gebuehr" placeholder="1,00"></label>
      <label class="feld"><span>Betrag in € (nur Dividende)</span><input type="text" inputmode="decimal" name="betrag"></label>
    </div>
    <button class="knopf klein" type="submit"><?= ico('plus') ?> Buchung speichern</button>
  </form>
</details>
<?php
    $depotHtml = (string)ob_get_clean();

    // --- Prüf-Verlauf
    ob_start(); ?>
<?php if ($erste !== null && $seitErster !== null): ?>
  <p>Seit deiner ersten Prüfung am <strong><?= e(datum((int)$erste['zeit'])) ?></strong>: Kurs <b class="<?= klasse($seitErster) ?>"><?= e(proz($seitErster, 1, true)) ?></b></p>
<?php endif; ?>
<form method="post" action="./" class="formular">
  <?= csrfFeld() ?><input type="hidden" name="aktion" value="pruefung"><input type="hidden" name="s" value="<?= e($s) ?>">
  <label class="feld"><span>Fazit dieser Prüfung (optional)</span><input type="text" name="fazit" maxlength="300" placeholder="z. B. Bewertung noch zu hoch, Q3-Zahlen abwarten"></label>
  <button class="knopf klein" type="submit"><?= ico('haken') ?> Heute als geprüft speichern</button>
</form>
<?php if ($f['pruefungen'] !== []): ?>
  <div class="tabelle-rahmen"><table class="tabelle">
    <thead><tr><th>Datum</th><th>Kurs</th><th>KGV</th><th>Div.-Rendite</th><th>Status</th><th>Fazit</th></tr></thead>
    <tbody>
    <?php foreach (array_reverse($f['pruefungen']) as $pr): ?>
      <tr><td><?= e(datum((int)$pr['zeit'])) ?></td><td><?= e(geld(isset($pr['kurs']) ? (float)$pr['kurs'] : null, (string)($pr['waehrung'] ?? $w))) ?></td>
        <td><?= e(zahl(isset($pr['kgv']) ? (float)$pr['kgv'] : null, 1)) ?></td><td><?= e(proz(isset($pr['div_rendite']) ? (float)$pr['div_rendite'] : null)) ?></td>
        <td><?= e(FZ_STATUS[$pr['status'] ?? ''] ?? '') ?></td><td><?= e((string)($pr['fazit'] ?? '')) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
<?php
    $verlaufHtml = (string)ob_get_clean();

    $aktiveLimits = count(array_filter($f['limits'], static fn(array $l): bool => !empty($l['aktiv']) && empty($l['ausgeloest'])));
    $chartDaten = json_encode(['j1' => $p['verlauf_1j'], 'j5' => $p['verlauf_5j'], 'w' => $w], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);
    $istUs = !str_contains($s, '.') && !str_contains($s, '=');

    ob_start(); ?>
<div class="zurueck"><a href="<?= e(url(['seite' => 'firmen'])) ?>"><?= ico('zurueck') ?> Meine Firmen</a></div>
<section class="karte firmenkopf">
  <div class="fk-oben">
    <span class="fk-logo" data-logo="<?= e($s) ?>"><?= avatar($p['name'], $s) ?></span>
    <div class="fk-name">
      <h1><?= e($p['name']) ?></h1>
      <p class="leise klein"><?= e($s) ?><?= $p['boerse'] !== '' ? ' · ' . e($p['boerse']) : '' ?><?= $f['isin'] !== '' ? ' · ' . e($f['isin']) : '' ?></p>
    </div>
  </div>
  <div class="fk-kurs">
    <span class="kurs-gross"><?= e(geld($p['kurs'], $w)) ?></span>
    <span class="aend-gross <?= klasse($p['aend_proz']) ?>"><?= e(($p['aend'] !== null && $p['aend'] > 0 ? '+' : '') . geld($p['aend'], $w)) ?> (<?= e(proz($p['aend_proz'], 2, true)) ?>)</span>
  </div>
  <p class="klein leise">Stand <?= e($p['kurszeit'] > 0 ? datumZeit($p['kurszeit']) : '–') ?><?= $p['marktstatus'] === 'REGULAR' ? ' · Börse geöffnet' : '' ?></p>
  <form method="post" action="./" class="fk-status">
    <?= csrfFeld() ?><input type="hidden" name="aktion" value="status"><input type="hidden" name="s" value="<?= e($s) ?>">
    <label class="status-wahl"><span class="sr">Status</span>
      <select name="status" data-auto-absenden class="p-<?= e($f['status']) ?>">
        <?php foreach (FZ_STATUS as $k => $t): ?><option value="<?= e($k) ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
      </select>
    </label>
    <span class="sterne" role="group" aria-label="Meine Bewertung">
      <?php for ($i = 1; $i <= 5; $i++): ?><button type="submit" name="bewertung" value="<?= $i === (int)$f['bewertung'] ? 0 : $i ?>" class="<?= $i <= (int)$f['bewertung'] ? 'an' : '' ?>" aria-label="<?= $i ?> Sterne"><?= ico('stern') ?></button><?php endfor; ?>
    </span>
  </form>
  <?php if ($letzte !== null): ?>
    <p class="fk-pruefung"><?= ico('uhr') ?><span>Zuletzt geprüft <?= e(vorZeit((int)$letzte['zeit'])) ?><?= $seitErster !== null && time() - (int)$erste['zeit'] > 86400 ? ' · seit erster Prüfung <b class="' . klasse($seitErster) . '">' . e(proz($seitErster, 1, true)) . '</b>' : '' ?></span></p>
  <?php endif; ?>
</section>

<section class="karte chart-karte">
  <div class="chart-tabs" role="tablist">
    <?php foreach (['1m' => '1 M', '6m' => '6 M', '1j' => '1 J', '5j' => '5 J'] as $k => $t): ?><button type="button" data-spanne="<?= $k ?>"<?= $k === '1j' ? ' class="aktiv"' : '' ?>><?= $t ?></button><?php endforeach; ?>
    <span class="chart-aend"></span>
  </div>
  <div class="chart" data-chart></div>
  <script type="application/json" id="chart-daten"><?= $chartDaten ?></script>
</section>

<h2 class="abschnitt">Analyse</h2>
<?= akk('Auf einen Blick', 'puls', $blick, ['offen' => true, 'id' => 'f-blick']) ?>
<?= akk('Kennzahlen', 'balken', $kz, ['id' => 'f-kennzahlen', 'meta' => $p['kgv'] !== null ? 'KGV ' . e(zahl($p['kgv'], 1)) : '']) ?>
<?= akk('Umsatz & Gewinn', 'trend', balkenSvg($p['jahre'], (string)($p['bilanzwaehrung'] ?: $w)), ['id' => 'f-jahre']) ?>
<?= akk('Analysten', 'ziel', empfehlungHtml($p), ['id' => 'f-analysten', 'meta' => $p['empfehlung'] !== '' ? e(urteilText($p['empfehlung'])) : '']) ?>
<?= akk('News', 'dokument', '', ['teil' => 'news', 'symbol' => $s, 'id' => 'f-news']) ?>
<?= akk('Aktionäre', 'personen', '', ['teil' => 'aktionaere', 'symbol' => $s, 'id' => 'f-aktionaere']) ?>
<?= akk('Beteiligungen', 'netz', '', ['teil' => 'beteiligungen', 'symbol' => $s, 'id' => 'f-beteiligungen']) ?>
<?= akk('KI-Einschätzung', 'blitz', $kiHtml, ['id' => 'f-ki', 'meta' => $ki !== null ? e(datum((int)$ki['zeit'])) : '']) ?>
<?= akk('Profil & Stammdaten', 'welt', '', ['teil' => 'profil', 'symbol' => $s, 'id' => 'f-profil']) ?>
<?php if ($istUs): ?><?= akk('Pflichtmitteilungen (US-Börsenaufsicht)', 'dokument', '', ['teil' => 'sec', 'symbol' => $s, 'id' => 'f-sec']) ?><?php endif; ?>

<h2 class="abschnitt">Meine Sachen</h2>
<?= akk('Notizen & These', 'notiz', $notizHtml, ['id' => 'f-notizen', 'meta' => $f['notizen'] !== [] ? (string)count($f['notizen']) : '']) ?>
<?= akk('Limits & Erinnerungen', 'glocke', $limitHtml, ['id' => 'f-limits', 'meta' => $aktiveLimits > 0 ? $aktiveLimits . ' aktiv' : ($f['wiedervorlage'] !== '' ? e(datum($f['wiedervorlage'])) : '')]) ?>
<?= akk('Mein Depot', 'depot', $depotHtml, ['id' => 'f-depot', 'meta' => $meine !== [] ? e(geld(array_sum(array_map(static fn(array $x): float => (float)$x['wert'], $meine)))) : '']) ?>
<?= akk('Prüf-Verlauf', 'uhr', $verlaufHtml, ['id' => 'f-verlauf', 'meta' => (string)count($f['pruefungen'])]) ?>

<form method="post" action="./" class="entfernen" data-bestaetigen="<?= e($f['name']) ?> mit allen Notizen, Limits und Buchungen aus deiner Liste entfernen?">
  <?= csrfFeld() ?><input type="hidden" name="aktion" value="firma_entfernen"><input type="hidden" name="s" value="<?= e($s) ?>">
  <button class="knopf-text gefahr" type="submit"><?= ico('muell') ?> Aus „Meine Firmen“ entfernen</button>
</form>
<?php
    return (string)ob_get_clean();
}

// ---------------------------------------------------------------------------
// Nachladbare Teile des Steckbriefs
// ---------------------------------------------------------------------------

function teilNews(array $f, array $p): string
{
    $begriff = $f['suchbegriff'] !== '' ? $f['suchbegriff'] : nameKurz($p['name']);
    $de = googleNews($begriff, 'de');
    $en = googleNews($begriff, 'en');
    $liste = static function (array $news): string {
        if ($news === []) {
            return leer('Keine aktuellen Meldungen gefunden.');
        }
        $html = '<ul class="news">';
        foreach ($news as $n) {
            $html .= '<li><a href="' . e($n['link']) . '" target="_blank" rel="noopener noreferrer">' . e($n['titel']) . '</a>'
                . '<small>' . e($n['quelle']) . ($n['zeit'] > 0 ? ' · ' . e(vorZeit($n['zeit'])) : '') . '</small></li>';
        }
        return $html . '</ul>';
    };
    return '<div class="reiter" data-reiter><div class="reiter-knoepfe"><button type="button" class="aktiv" data-reiter-ziel="de">Deutsch</button><button type="button" data-reiter-ziel="en">Englisch</button></div>'
        . '<div data-reiter-inhalt="de">' . $liste($de) . '</div><div data-reiter-inhalt="en" hidden>' . $liste($en) . '</div></div>'
        . '<p class="klein leise">Suchbegriff: „' . e($begriff) . '“ – änderbar unter „Profil & Stammdaten“. Quelle: Google News.</p>';
}

/** „VANGUARD STAR FUNDS“ → „Vanguard Star Funds“ (nur bei durchgehender Großschreibung). */
function lesbarerName(string $name): string
{
    $name = trim($name);
    return $name !== '' && mb_strtoupper($name) === $name && preg_match('/[A-Z]{4,}/', $name)
        ? mb_convert_case(mb_strtolower($name), MB_CASE_TITLE) : $name;
}

function teilAktionaere(array $f, array $p, array $id): string
{
    $html = kacheln([
        ['Insider', e(proz($p['insider_anteil'])), 'Vorstand, Gründer, Großaktionäre', ''],
        ['Institutionen', e(proz($p['institutionen_anteil'])), $p['institutionen_anzahl'] > 0 ? e(number_format($p['institutionen_anzahl'], 0, ',', '.')) . ' Institutionen' : '', ''],
        ['Streubesitz (grob)', e(proz($p['insider_anteil'] !== null ? max(0, 1 - $p['insider_anteil']) : null)), 'ohne Insider', ''],
    ]);
    $html .= '<h4>Großaktionäre mit Namen</h4>';
    $ki = kiAbschnitt($f['ki'], 'großaktionäre');
    $wd = $id['wikidata'] !== '' ? wikidataDetails($id['wikidata']) : null;
    $eigentuemer = $wd['eigentuemer'] ?? [];
    if ($eigentuemer !== []) {
        $html .= '<ul class="liste-schlicht">';
        foreach (array_slice($eigentuemer, 0, 12) as $x) {
            $html .= '<li><span>' . e($x['name']) . '</span><b>' . e($x['anteil'] !== null ? proz($x['anteil'], 1) : '') . '</b></li>';
        }
        $html .= '</ul><p class="klein leise">Laut Wikidata – Stand kann älter sein.</p>';
    }
    if ($ki !== '') {
        $html .= '<div class="ki-text">' . mdHtml($ki) . '</div><p class="klein leise">Aus der KI-Einschätzung vom ' . e(datum((int)$f['ki']['zeit'])) . '.</p>';
    } elseif ($eigentuemer === []) {
        $html .= '<p class="klein">Namentliche Großaktionäre (z. B. Gründerfamilien, Stiftungen, Staat) liefert die <a href="#f-ki" data-oeffne="f-ki">KI-Einschätzung</a>.</p>';
    } else {
        $html .= '<p class="klein">Aktuelle Anteile mit Stand recherchiert die <a href="#f-ki" data-oeffne="f-ki">KI-Einschätzung</a>.</p>';
    }
    $liste = static function (array $eintraege, string $w): string {
        // Kleinstbestände (unter 0,05 %) sagen nichts über die Aktionärsstruktur aus
        $eintraege = array_values(array_filter($eintraege, static fn(array $x): bool => ($x['anteil'] ?? 0) >= 0.0005));
        if ($eintraege === []) {
            return '';
        }
        $h = '<ul class="liste-schlicht">';
        foreach ($eintraege as $x) {
            $h .= '<li><span>' . e(lesbarerName($x['name'])) . '<br><small class="leise">' . e(gross($x['wert'], $w)) . ' · Stand ' . e(datum($x['zeit'])) . '</small></span>'
                . '<b>' . e(proz($x['anteil'], 2)) . '</b></li>';
        }
        return $h . '</ul>';
    };
    $inst = $liste($p['institutionen'], (string)$p['waehrung']);
    if ($inst !== '') {
        $html .= '<h4>Größte institutionelle Anleger</h4>' . $inst;
    }
    $fonds = $liste($p['fonds'], (string)$p['waehrung']);
    if ($fonds !== '') {
        $html .= '<h4>Größte Fonds</h4>' . $fonds;
    }
    if ($p['insider_geschaefte'] !== []) {
        $html .= '<h4>Insider-Geschäfte</h4><ul class="liste-schlicht">';
        foreach ($p['insider_geschaefte'] as $t) {
            $html .= '<li><span>' . e(datum($t['zeit'])) . ' · <strong>' . e(ucwords(mb_strtolower($t['name']))) . '</strong> (' . e($t['rolle']) . ')<br><small>' . e($t['text'] !== '' ? $t['text'] : '–') . '</small></span>'
                . '<b>' . e($t['aktien'] !== null ? number_format($t['aktien'], 0, ',', '.') . ' St.' : '') . '</b></li>';
        }
        $html .= '</ul>';
    } elseif ($p['insider'] !== []) {
        $html .= '<h4>Insider</h4><ul class="liste-schlicht">';
        foreach ($p['insider'] as $t) {
            $html .= '<li><span><strong>' . e(ucwords(mb_strtolower($t['name']))) . '</strong> (' . e($t['rolle']) . ')</span><b>' . e($t['aktien'] !== null ? number_format($t['aktien'], 0, ',', '.') . ' St.' : '') . '</b></li>';
        }
        $html .= '</ul>';
    }
    return $html . '<p class="klein leise">Quelle: Yahoo Finance (Pflichtmeldungen, teils mit Verzögerung).</p>';
}

function teilBeteiligungen(array $f, array $id): string
{
    $html = '';
    $gleif = $id['lei'] !== '' ? gleifStruktur($id['lei']) : null;
    $wd = $id['wikidata'] !== '' ? wikidataDetails($id['wikidata']) : null;
    $mutter = $gleif['oberste'] ?? ($gleif['mutter'] ?? null);
    if ($mutter !== null && $mutter['lei'] !== $id['lei']) {
        $html .= '<div class="hinweis">' . ico('netz') . ' Gehört zum Konzern <strong>' . e($mutter['name']) . '</strong>' . ($mutter['land'] !== '' ? ' (' . e($mutter['land']) . ')' : '') . '.</div>';
    }
    $ki = kiAbschnitt($f['ki'], 'beteiligungen');
    if ($ki !== '') {
        $html .= '<h4>Wesentliche Beteiligungen (KI-Recherche)</h4><div class="ki-text">' . mdHtml($ki) . '</div>';
    }
    if ($wd !== null && ($wd['beteiligung'] !== [] || $wd['tochter'] !== [])) {
        $bekannt = [];
        foreach (array_merge($wd['beteiligung'], $wd['tochter']) as $x) {
            $bekannt[$x['name']] = $x;
        }
        ksort($bekannt, SORT_NATURAL | SORT_FLAG_CASE);
        $html .= '<h4>Bekannte Töchter und Beteiligungen <small class="leise">(' . count($bekannt) . ', Wikidata)</small></h4><ul class="spalten-liste">';
        foreach (array_slice($bekannt, 0, 80) as $x) {
            $html .= '<li>' . e($x['name']) . ($x['anteil'] !== null ? ' <b>' . e(proz($x['anteil'], 0)) . '</b>' : '') . '</li>';
        }
        $html .= '</ul>';
    }
    if ($gleif !== null) {
        $html .= '<h4>Tochtergesellschaften laut LEI-Register <small class="leise">(' . (int)$gleif['kinder_gesamt'] . ' direkt'
            . ($gleif['alle_gesamt'] > $gleif['kinder_gesamt'] ? ', ' . (int)$gleif['alle_gesamt'] . ' insgesamt' : '') . ')</small></h4>';
        if ($gleif['kinder'] === []) {
            $html .= leer('Im Register sind keine Tochtergesellschaften gemeldet.');
        } else {
            $html .= '<details class="unterbereich"><summary>Liste anzeigen</summary><ul class="spalten-liste">';
            foreach ($gleif['kinder'] as $k) {
                $html .= '<li>' . e($k['name']) . ' <small class="leise">' . e($k['land']) . '</small></li>';
            }
            $html .= '</ul>' . ($gleif['kinder_gesamt'] > count($gleif['kinder']) ? '<p class="klein leise">Die ersten ' . count($gleif['kinder']) . ' von ' . (int)$gleif['kinder_gesamt'] . '.</p>' : '') . '</details>';
        }
    }
    if ($html === '') {
        $html = leer('Zu dieser Firma wurden keine Konzern- oder Beteiligungsdaten gefunden. Die KI-Einschätzung recherchiert wesentliche Beteiligungen im Web.');
    }
    $quellen = [];
    if ($id['lei'] !== '') {
        $quellen[] = '<a href="https://search.gleif.org/#/record/' . e($id['lei']) . '" target="_blank" rel="noopener noreferrer">LEI-Register (GLEIF)</a>';
    }
    if ($id['wikidata'] !== '') {
        $quellen[] = '<a href="https://www.wikidata.org/wiki/' . e($id['wikidata']) . '" target="_blank" rel="noopener noreferrer">Wikidata</a>';
    }
    return $html . ($quellen !== [] ? '<p class="klein leise">Quellen: ' . implode(' · ', $quellen) . '</p>' : '');
}

function teilProfil(array $f, array $p, array $id): string
{
    $wd = $id['wikidata'] !== '' ? wikidataDetails($id['wikidata']) : null;
    $zeilen = [];
    $zeilen[] = ['Sitz', trim(($wd !== null && $wd['sitz'] !== [] ? $wd['sitz'][0]['name'] : $p['stadt']) . ($p['land'] !== '' ? ', ' . $p['land'] : ''), ', ')];
    if ($wd !== null && $wd['gegruendet'] !== '') {
        $zeilen[] = ['Gegründet', $wd['gegruendet']];
    }
    if ($wd !== null && $wd['gruender'] !== []) {
        $zeilen[] = ['Gründer', implode(', ', array_column(array_slice($wd['gruender'], 0, 5), 'name'))];
    }
    if ($p['vorstand'] !== []) {
        $zeilen[] = ['Führung', implode(' · ', array_map(static fn(array $v): string => $v['name'] . ($v['rolle'] !== '' ? ' (' . $v['rolle'] . ')' : ''), array_slice($p['vorstand'], 0, 3)))];
    } elseif ($wd !== null && $wd['chef'] !== []) {
        $zeilen[] = ['Chef', implode(', ', array_column($wd['chef'], 'name'))];
    }
    if ($p['mitarbeiter'] > 0) {
        $zeilen[] = ['Mitarbeiter', number_format($p['mitarbeiter'], 0, ',', '.')];
    }
    $zeilen[] = ['Sektor / Branche', trim($p['sektor'] . ' / ' . $p['branche'], ' /')];
    $website = $p['website'] !== '' ? $p['website'] : (string)($wd['website'] ?? '');
    $html = '<dl class="profil">';
    foreach ($zeilen as [$t, $v]) {
        if ($v !== '') {
            $html .= '<dt>' . e($t) . '</dt><dd>' . e($v) . '</dd>';
        }
    }
    if ($website !== '' && preg_match('~^https?://~', $website)) {
        $html .= '<dt>Website</dt><dd><a href="' . e($website) . '" target="_blank" rel="noopener noreferrer">' . e(preg_replace('~^https?://(www\.)?~', '', rtrim($website, '/'))) . ' ' . ico('extern') . '</a></dd>';
    }
    $html .= '</dl>';
    if ($p['beschreibung'] !== '') {
        $html .= '<h4>Beschreibung <small class="leise">(englisch, Yahoo Finance)</small></h4><p class="beschreibung">' . e($p['beschreibung']) . '</p>';
    }
    if ($wd !== null && $wd['logo'] !== '') {
        $html .= '<span hidden data-logo-url="' . e(preg_replace('~^http://~', 'https://', $wd['logo']) . '?width=160') . '"></span>';
    }
    ob_start(); ?>
<details class="unterbereich">
  <summary><?= ico('stift') ?> Stammdaten korrigieren</summary>
  <form method="post" action="./" class="formular">
    <?= csrfFeld() ?><input type="hidden" name="aktion" value="stammdaten"><input type="hidden" name="s" value="<?= e($f['symbol']) ?>">
    <p class="klein leise">Die Zuordnung zu Registern passiert automatisch. Falls etwas nicht passt, hier korrigieren.</p>
    <label class="feld"><span>ISIN</span><input type="text" name="isin" value="<?= e($id['isin']) ?>" maxlength="12" pattern="[A-Za-z]{2}[A-Za-z0-9]{9}[0-9]"></label>
    <label class="feld"><span>LEI (Unternehmenskennung)</span><input type="text" name="lei" value="<?= e($id['lei']) ?>" maxlength="20"></label>
    <label class="feld"><span>Wikidata-Eintrag</span><input type="text" name="wikidata" value="<?= e($id['wikidata']) ?>" maxlength="12" placeholder="Q…"></label>
    <label class="feld"><span>Suchbegriff für News</span><input type="text" name="suchbegriff" value="<?= e($f['suchbegriff']) ?>" maxlength="60" placeholder="<?= e(nameKurz($p['name'])) ?>"></label>
    <button class="knopf klein" type="submit">Speichern</button>
  </form>
</details>
<?php
    return $html . (string)ob_get_clean();
}

function teilSec(string $symbol): string
{
    $sec = secMeldungen($symbol);
    if ($sec === null || $sec['meldungen'] === []) {
        return leer('Keine Mitteilungen bei der US-Börsenaufsicht gefunden.');
    }
    $html = '<ul class="liste-schlicht">';
    foreach ($sec['meldungen'] as $m) {
        $html .= '<li><span>' . e(datum($m['datum'])) . ' · <strong>' . e($m['art']) . '</strong> <small class="leise">' . e($m['form']) . '</small></span>'
            . '<a href="' . e($m['link']) . '" target="_blank" rel="noopener noreferrer" aria-label="Öffnen">' . ico('extern') . '</a></li>';
    }
    return $html . '</ul><p class="klein leise">„Großaktionär > 5 %“ sind Meldungen von Anlegern, die mehr als 5 % der Aktien halten. Quelle: SEC EDGAR.</p>';
}

// ---------------------------------------------------------------------------
// Depot
// ---------------------------------------------------------------------------

function positionsTabelle(array $positionen, array $d): string
{
    if ($positionen === []) {
        return leer('Keine Positionen.');
    }
    $html = '<ul class="positionen">';
    foreach ($positionen as $pos) {
        $verlinkt = $pos['symbol'] !== '' && isset($d['firmen'][$pos['symbol']]);
        $name = e($pos['name']);
        $art = ['etf' => 'ETF', 'fonds' => 'Fonds', 'krypto' => 'Krypto', 'devisen' => 'Devisen', 'rohstoff' => 'Rohstoff', 'index' => 'Index'][$pos['art']] ?? '';
        $unter = zahl($pos['stueck'], $pos['stueck'] < 10 ? 4 : 2) . ' Stück'
            . ($art !== '' ? ' · ' . $art : '')
            . (!empty($pos['hebel']) && $pos['hebel'] > 1 ? ' · Hebel ' . zahl($pos['hebel'], 0) : '')
            . (!empty($pos['herkunft']) ? ' · ' . implode(', ', array_unique($pos['herkunft'])) : '')
            . ' · Einstand ' . geld($pos['einstand']);
        $html .= '<li>' . ($verlinkt ? '<a href="' . e(firmaUrl($pos['symbol'])) . '">' : '<div>')
            . '<span><strong>' . $name . '</strong><small>' . e($unter) . '</small></span>'
            . '<span class="rechts"><b>' . e($pos['wert'] !== null ? geld($pos['wert']) : 'kein Kurs') . '</b>'
            . ($pos['gv'] !== null ? '<small class="' . klasse($pos['gv']) . '">' . e(($pos['gv'] > 0 ? '+' : '') . geld($pos['gv'])) . ' (' . e(proz($pos['gv_proz'], 1, true)) . ')</small>' : '')
            . ($pos['heute'] !== null && abs((float)$pos['heute']) > 0.004 ? '<small class="' . klasse($pos['heute']) . '">heute ' . e(($pos['heute'] > 0 ? '+' : '') . geld($pos['heute'])) . '</small>' : '')
            . '</span>' . ($verlinkt ? '</a>' : '</div>') . '</li>';
    }
    return $html . '</ul>';
}

function seiteDepot(array $d, array $depot, bool $etoroVerbunden, ?array $vorschau): string
{
    $g = $depot['gesamt'];
    $tr = $d['broker']['traderepublic'] ?? null;
    $et = $d['broker']['etoro'] ?? null;
    $jeQuelle = [];
    foreach ($depot['positionen'] as $pos) {
        $jeQuelle[$pos['quelle']][] = $pos;
    }
    ob_start(); ?>
<h1 class="seitentitel">Depot</h1>
<?php if ($vorschau !== null): ?>
  <section class="karte vorschau">
    <h2 class="karten-titel"><?= ico('hoch') ?> Trade-Republic-Import prüfen</h2>
    <p><strong><?= (int)$vorschau['zeilen'] ?> Buchungen</strong> aus „<?= e($vorschau['datei']) ?>“ erkannt:</p>
    <ul class="liste-schlicht">
      <?php foreach ($vorschau['zaehler'] as $art => $n): ?><li><span><?= e(['kauf' => 'Käufe (inkl. Sparpläne)', 'verkauf' => 'Verkäufe', 'dividende' => 'Dividenden', 'zins' => 'Zinsen', 'einzahlung' => 'Einzahlungen', 'auszahlung' => 'Auszahlungen', 'steuer' => 'Steuern', 'gebuehr' => 'Gebühren', 'sonst' => 'Sonstiges'][$art] ?? $art) ?></span><b><?= (int)$n ?></b></li><?php endforeach; ?>
    </ul>
    <p class="klein leise">Erkannte Spalten: <?= e(implode(' · ', array_map(static fn(string $k, string $v): string => $k . ' = „' . $v . '“', array_keys($vorschau['spalten']), $vorschau['spalten']))) ?></p>
    <?php if (!empty($vorschau['positionen'])): ?>
      <p>Daraus ergeben sich <strong><?= count($vorschau['positionen']) ?> aktuelle Positionen</strong>:</p>
      <ul class="liste-schlicht"><?php foreach (array_slice($vorschau['positionen'], 0, 40) as $pp): ?><li><span><?= e($pp['name']) ?></span><b><?= e(zahl($pp['stueck'], 4)) ?> St.</b></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <div class="knopf-reihe">
      <form method="post" action="./?seite=depot"><?= csrfFeld() ?><input type="hidden" name="aktion" value="tr_uebernehmen"><button class="knopf" type="submit"><?= ico('haken') ?> Übernehmen</button></form>
      <form method="post" action="./?seite=depot"><?= csrfFeld() ?><input type="hidden" name="aktion" value="tr_verwerfen"><button class="knopf zweit" type="submit">Verwerfen</button></form>
    </div>
    <p class="klein leise">Ein neuer Import ersetzt den vorherigen vollständig. Wähle beim Export deshalb am besten den gesamten Zeitraum seit Kontoeröffnung.</p>
  </section>
<?php endif; ?>
<section class="karte depot-summe">
  <span class="karten-titel"><?= ico('kuchen') ?> Gesamt</span>
  <?php if ($g['anzahl'] === 0): ?>
    <?= leer('Noch keine Positionen. Importiere unten deinen Trade-Republic-Export oder verbinde eToro.') ?>
  <?php else: ?>
    <span class="depot-wert"><?= e(geld($g['wert'] + (float)($depot['etoro_cash'] ?? 0))) ?></span>
    <?php echo kacheln([
        ['Heute', '<span class="' . klasse($g['heute']) . '">' . e(($g['heute'] > 0 ? '+' : '') . geld($g['heute'])) . '</span>', '', ''],
        ['Gewinn/Verlust', '<span class="' . klasse($g['gv']) . '">' . e(($g['gv'] > 0 ? '+' : '') . geld($g['gv'])) . '</span>', $g['einstand'] > 0 ? '<span class="' . klasse($g['gv']) . '">' . proz($g['gv'] / $g['einstand'], 1, true) . '</span>' : '', ''],
        ['Eingesetzt', e(geld($g['einstand'])), '', ''],
        ['Positionen', (string)$g['anzahl'], $g['ohne_kurs'] > 0 ? $g['ohne_kurs'] . ' ohne aktuellen Kurs' : '', ''],
    ]); ?>
    <?php if (!empty($depot['etoro_cash'])): ?><p class="klein leise">inklusive <?= e(geld($depot['etoro_cash'])) ?> freiem Guthaben bei eToro</p><?php endif; ?>
    <?php
      // Verteilung je Wertpapier – dieselbe Aktie aus mehreren Depots zählt zusammen
      $jeTitel = [];
      foreach ($depot['positionen'] as $pos) {
          if ((float)$pos['wert'] <= 0) {
              continue;
          }
          $k = $pos['symbol'] !== '' ? $pos['symbol'] : $pos['name'];
          $jeTitel[$k] ??= ['name' => $pos['name'], 'wert' => 0.0];
          $jeTitel[$k]['wert'] += (float)$pos['wert'];
      }
      $mitWert = array_values($jeTitel);
      usort($mitWert, static fn(array $a, array $b): int => $b['wert'] <=> $a['wert']);
      $summe = array_sum(array_column($mitWert, 'wert'));
      if ($summe > 0): ?>
      <div class="verteilung" role="img" aria-label="Verteilung des Depots">
        <?php foreach (array_slice($mitWert, 0, 10) as $i => $pos): ?><span style="flex:<?= round($pos['wert'] / $summe * 1000) ?>;--h:<?= (int)($i * 37 + 200) % 360 ?>" title="<?= e($pos['name'] . ': ' . proz($pos['wert'] / $summe, 1)) ?>"></span><?php endforeach; ?>
        <?php $rest = array_sum(array_map(static fn(array $p): float => (float)$p['wert'], array_slice($mitWert, 10))); if ($rest > 0): ?><span class="rest" style="flex:<?= round($rest / $summe * 1000) ?>" title="Rest"></span><?php endif; ?>
      </div>
      <ul class="verteilung-legende">
        <?php foreach (array_slice($mitWert, 0, 10) as $i => $pos): ?><li><i style="--h:<?= (int)($i * 37 + 200) % 360 ?>"></i><?= e($pos['name']) ?> <b><?= e(proz($pos['wert'] / $summe, 1)) ?></b></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php foreach (FZ_QUELLEN as $q => $titel):
    if (empty($jeQuelle[$q])) {
        continue;
    }
    $s = $depot['quellen'][$q];
    $stand = $q === 'traderepublic' ? 'Import vom ' . datum((int)($tr['zeit'] ?? 0)) : ($q === 'etoro' ? 'Stand ' . vorZeit((int)($et['zeit'] ?? 0)) : '');
    echo akk($titel, 'depot', ($stand !== '' ? '<p class="klein leise">' . e($stand) . '</p>' : '') . positionsTabelle($jeQuelle[$q], $d),
        ['offen' => true, 'id' => 'd-' . $q, 'meta' => e(geld($s['wert']))]);
endforeach; ?>

<h2 class="abschnitt">Depots anbinden</h2>
<?php ob_start(); ?>
<?php if ($tr !== null): ?>
  <p class="erfolg"><?= ico('haken') ?> Zuletzt importiert am <?= e(datumZeit((int)$tr['zeit'])) ?>: <?= (int)$tr['zeilen'] ?> Buchungen (<?= e(datum($tr['von'])) ?> bis <?= e(datum($tr['bis'])) ?>)<?= ($tr['summen']['dividenden'] ?? 0) > 0 ? ', Dividenden gesamt ' . e(geld((float)$tr['summen']['dividenden'])) : '' ?>.</p>
<?php endif; ?>
<p>Trade Republic hat keine offene Schnittstelle, bietet aber einen offiziellen Export aller Buchungen. So geht’s:</p>
<ol class="schritte">
  <li>In der Trade-Republic-App oben rechts auf dein <strong>Profil</strong> tippen.</li>
  <li><strong>Kontoauszüge</strong> (bzw. Dokumente) → <strong>Transaktionsexport</strong> wählen.</li>
  <li>Als Zeitraum am besten <strong>alles seit Kontoeröffnung</strong> wählen, dann <strong>Erstellen</strong> und die CSV-Datei teilen oder speichern.</li>
  <li>Hier hochladen – du siehst vor dem Übernehmen eine Vorschau.</li>
</ol>
<form method="post" action="./?seite=depot" enctype="multipart/form-data" class="formular">
  <?= csrfFeld() ?><input type="hidden" name="aktion" value="tr_vorschau">
  <label class="feld datei"><span>CSV-Datei von Trade Republic</span><input type="file" name="datei" accept=".csv,text/csv,text/plain" required></label>
  <button class="knopf" type="submit"><?= ico('hoch') ?> Hochladen und prüfen</button>
</form>
<p class="klein leise">Die Datei wird nur auf deinem Server ausgewertet. Dein Trade-Republic-Zugang wird nie benötigt.</p>
<?php if ($tr !== null): ?>
  <form method="post" action="./?seite=depot" data-bestaetigen="Trade-Republic-Daten aus der Finanzzentrale löschen?"><?= csrfFeld() ?><input type="hidden" name="aktion" value="tr_loeschen"><button class="knopf-text gefahr" type="submit"><?= ico('muell') ?> Importierte Trade-Republic-Daten löschen</button></form>
<?php endif; ?>
<?php $trHtml = (string)ob_get_clean(); ?>
<?= akk('Trade Republic', 'hoch', $trHtml, ['id' => 'd-tr-import', 'offen' => $tr === null, 'meta' => $tr !== null ? e(datum((int)$tr['zeit'])) : 'CSV-Import']) ?>

<?php ob_start(); ?>
<?php if ($et !== null && !empty($et['fehler'])): ?>
  <div class="fehlerbox"><?= e($et['fehler']) ?></div>
<?php elseif ($et !== null && !empty($et['zeit'])): ?>
  <p class="erfolg"><?= ico('haken') ?> Verbunden – zuletzt abgerufen <?= e(vorZeit((int)$et['zeit'])) ?> (Kontowährung <?= e((string)$et['waehrung']) ?>).</p>
<?php endif; ?>
<?php if ($etoroVerbunden): ?>
  <form method="post" action="./?seite=depot" class="knopf-reihe"><?= csrfFeld() ?><input type="hidden" name="aktion" value="etoro_abrufen"><button class="knopf" type="submit"><?= ico('neu') ?> Jetzt abrufen</button></form>
  <p class="klein leise">Das Depot wird automatisch aktualisiert, wenn du diese Seite öffnest (höchstens alle 15 Minuten).</p>
<?php endif; ?>
<p>eToro hat eine offizielle Schnittstelle. Du brauchst zwei Schlüssel, die du selbst erzeugst:</p>
<ol class="schritte">
  <li>Bei eToro anmelden → <strong>Einstellungen</strong> → <strong>Trading</strong> → <strong>API Key Management</strong>.</li>
  <li><strong>Create New Key</strong>: Umgebung <strong>Real</strong>, Berechtigung nur <strong>Read</strong> (Lesen) – dann kann niemand über den Schlüssel handeln.</li>
  <li>Mit der SMS bestätigen und beide Schlüssel (<em>Public API Key</em> und <em>User Key</em>) hier eintragen.</li>
</ol>
<form method="post" action="./?seite=depot" class="formular">
  <?= csrfFeld() ?><input type="hidden" name="aktion" value="etoro_schluessel">
  <label class="feld"><span>Public API Key</span><input type="password" name="api" autocomplete="off" placeholder="<?= $etoroVerbunden ? '•••••••• (gespeichert)' : '' ?>"></label>
  <label class="feld"><span>User Key</span><input type="password" name="user" autocomplete="off" placeholder="<?= $etoroVerbunden ? '•••••••• (gespeichert)' : '' ?>"></label>
  <button class="knopf<?= $etoroVerbunden ? ' zweit' : '' ?>" type="submit"><?= ico('schloss') ?> <?= $etoroVerbunden ? 'Schlüssel ersetzen' : 'Speichern und verbinden' ?></button>
</form>
<?php if ($etoroVerbunden): ?>
  <form method="post" action="./?seite=depot" data-bestaetigen="eToro-Verbindung trennen und die Schlüssel löschen?"><?= csrfFeld() ?><input type="hidden" name="aktion" value="etoro_trennen"><button class="knopf-text gefahr" type="submit"><?= ico('x') ?> Verbindung trennen</button></form>
<?php endif; ?>
<p class="klein leise">Die Schlüssel liegen nur auf deinem Server, nicht im Programmcode.</p>
<?php $etHtml = (string)ob_get_clean(); ?>
<?= akk('eToro', 'link', $etHtml, ['id' => 'd-etoro', 'offen' => !$etoroVerbunden, 'meta' => $etoroVerbunden ? 'verbunden' : 'API-Schlüssel']) ?>
<?php
    return (string)ob_get_clean();
}

// ---------------------------------------------------------------------------
// Alarme & Termine
// ---------------------------------------------------------------------------

function seiteAlarme(array $d, array $kurse): string
{
    $cron = cronStatus($d);
    $aktiv = [];
    $ausgeloest = [];
    $wv = [];
    $termine = [];
    foreach ($d['firmen'] as $f) {
        $k = $kurse[$f['symbol']]['kurs'] ?? null;
        foreach ($f['limits'] as $l) {
            if (!empty($l['ausgeloest'])) {
                $ausgeloest[] = [$f, $l];
            } elseif (!empty($l['aktiv'])) {
                $aktiv[] = [$f, $l, $k ? ((float)$l['wert'] - $k) / $k : null];
            }
        }
        if ($f['wiedervorlage'] !== '') {
            $wv[] = $f;
        }
        foreach (['zahlen' => 'Quartalszahlen', 'exdiv' => 'Dividenden-Stichtag', 'divzahlung' => 'Dividenden-Zahltag'] as $art => $text) {
            $tag = (string)($f['termine'][$art] ?? '');
            if ($tag !== '' && $f['status'] !== 'verworfen' && tageBis($tag) >= 0 && tageBis($tag) <= 45) {
                $termine[] = [$tag, $text, $f];
            }
        }
    }
    usort($aktiv, static fn(array $a, array $b): int => abs($a[2] ?? 9) <=> abs($b[2] ?? 9));
    usort($wv, static fn(array $a, array $b): int => strcmp($a['wiedervorlage'], $b['wiedervorlage']));
    usort($termine, static fn(array $a, array $b): int => strcmp($a[0], $b[0]));
    $ereignisse = array_reverse($d['ereignisse']);
    $neu = array_filter($ereignisse, static fn(array $e): bool => empty($e['gelesen']));
    ob_start(); ?>
<h1 class="seitentitel">Alarme & Termine</h1>
<p class="status-zeile <?= $cron['ok'] ? 'ok' : 'warn' ?>"><?= ico($cron['ok'] ? 'haken' : 'warnung') ?> <?= e($cron['text']) ?></p>
<?php ob_start(); ?>
<?php if ($neu === []): ?><?= leer('Keine neuen Meldungen.') ?><?php else: ?>
  <ul class="ereignisse"><?php foreach ($neu as $ev): ?><li><a href="<?= e(firmaUrl((string)$ev['symbol'])) ?>"><?= e($ev['text']) ?></a><small><?= e(datumZeit((int)$ev['zeit'])) ?></small></li><?php endforeach; ?></ul>
  <form method="post" action="./?seite=alarme"><?= csrfFeld() ?><input type="hidden" name="aktion" value="ereignisse_gelesen"><button class="knopf klein zweit" type="submit"><?= ico('haken') ?> Alle als gelesen markieren</button></form>
<?php endif; ?>
<?= akk('Neue Meldungen', 'glocke', (string)ob_get_clean(), ['offen' => true, 'id' => 'a-neu', 'meta' => $neu !== [] ? '<b class="zahl rot">' . count($neu) . '</b>' : '']) ?>

<?php ob_start(); ?>
<?php if ($aktiv === []): ?><?= leer('Keine aktiven Kurslimits. Limits legst du im Steckbrief einer Firma unter „Limits & Erinnerungen“ an.') ?><?php else: ?>
  <ul class="limits"><?php foreach ($aktiv as [$f, $l, $abstand]): ?>
    <li><span class="limit-art"><?= ico($l['typ'] === 'unter' ? 'faellt' : 'trend') ?></span>
      <a class="limit-text" href="<?= e(firmaUrl($f['symbol'])) ?>#f-limits"><strong><?= e($f['name']) ?></strong><small><?= e(limitText($l, $f['waehrung'])) ?> · aktuell <?= e(geld($kurse[$f['symbol']]['kurs'] ?? null, $f['waehrung'])) ?></small></a>
      <span class="abstand <?= $abstand !== null && abs($abstand) < 0.03 ? 'nah' : '' ?>"><?= e(proz($abstand, 1, true)) ?></span></li>
  <?php endforeach; ?></ul>
<?php endif; ?>
<?= akk('Aktive Kurslimits', 'ziel', (string)ob_get_clean(), ['offen' => true, 'id' => 'a-aktiv', 'meta' => (string)count($aktiv)]) ?>

<?php if ($ausgeloest !== []): ob_start(); ?>
  <ul class="limits"><?php foreach ($ausgeloest as [$f, $l]): ?>
    <li class="ausgeloest"><span class="limit-art"><?= ico('glocke') ?></span>
      <a class="limit-text" href="<?= e(firmaUrl($f['symbol'])) ?>#f-limits"><strong><?= e($f['name']) ?></strong><small><?= e(limitText($l, $f['waehrung'])) ?> · ausgelöst <?= e(datumZeit((int)$l['ausgeloest'])) ?></small></a>
      <form method="post" action="./?seite=alarme"><?= csrfFeld() ?><input type="hidden" name="aktion" value="limit_scharf"><input type="hidden" name="s" value="<?= e($f['symbol']) ?>"><input type="hidden" name="id" value="<?= e($l['id']) ?>"><input type="hidden" name="zurueck" value="alarme"><button class="knopf klein zweit" type="submit">Wieder aktivieren</button></form></li>
  <?php endforeach; ?></ul>
<?= akk('Ausgelöste Limits', 'glocke', (string)ob_get_clean(), ['id' => 'a-ausgeloest', 'meta' => (string)count($ausgeloest)]) ?>
<?php endif; ?>

<?php ob_start(); ?>
<?php if ($wv === []): ?><?= leer('Keine Wiedervorlagen.') ?><?php else: ?>
  <ul class="liste-schlicht"><?php foreach ($wv as $f): $tage = tageBis($f['wiedervorlage']); ?>
    <li><a href="<?= e(firmaUrl($f['symbol'])) ?>"><?= e($f['name']) ?></a><b class="<?= $tage <= 0 ? 'gelb-text' : '' ?>"><?= e(datum($f['wiedervorlage'])) ?><?= $tage <= 0 ? ' · fällig' : ' · in ' . $tage . ' Tagen' ?></b></li>
  <?php endforeach; ?></ul>
<?php endif; ?>
<?= akk('Wiedervorlagen', 'kalender', (string)ob_get_clean(), ['offen' => true, 'id' => 'a-wv', 'meta' => (string)count($wv)]) ?>

<?php ob_start(); ?>
<?php if ($termine === []): ?><?= leer('Keine bekannten Termine in den nächsten sechs Wochen. Termine werden automatisch aus den Kursdaten übernommen.') ?><?php else: ?>
  <ul class="liste-schlicht"><?php foreach ($termine as [$tag, $text, $f]): ?>
    <li><span><a href="<?= e(firmaUrl($f['symbol'])) ?>"><?= e($f['name']) ?></a> · <?= e($text) ?></span><b><?= e(datum($tag)) ?></b></li>
  <?php endforeach; ?></ul>
<?php endif; ?>
<?= akk('Termine', 'uhr', (string)ob_get_clean(), ['offen' => true, 'id' => 'a-termine', 'meta' => (string)count($termine)]) ?>

<?php ob_start(); ?>
<?php if ($ereignisse === []): ?><?= leer('Noch keine Meldungen.') ?><?php else: ?>
  <ul class="ereignisse"><?php foreach (array_slice($ereignisse, 0, 60) as $ev): ?><li class="<?= empty($ev['gelesen']) ? 'neu' : '' ?>"><a href="<?= e(firmaUrl((string)$ev['symbol'])) ?>"><?= e($ev['text']) ?></a><small><?= e(datumZeit((int)$ev['zeit'])) ?></small></li><?php endforeach; ?></ul>
<?php endif; ?>
<?= akk('Verlauf', 'liste', (string)ob_get_clean(), ['id' => 'a-verlauf', 'meta' => (string)count($ereignisse)]) ?>
<?php
    return (string)ob_get_clean();
}

// ---------------------------------------------------------------------------
// Einstellungen
// ---------------------------------------------------------------------------

function seiteEinstellungen(array $d, bool $kiSchluessel): string
{
    $thema = einstellung($d, 'ntfy');
    $sicherungen = count(glob(fzPfad('sicherungen') . '/finanzen-*.json') ?: []);
    ob_start(); ?>
<h1 class="seitentitel">Einstellungen</h1>
<?php ob_start(); ?>
<form method="post" action="./?seite=einstellungen" class="formular">
  <?= csrfFeld() ?><input type="hidden" name="aktion" value="benachrichtigung">
  <label class="feld"><span>E-Mail-Adresse für Alarme</span><input type="email" name="email" value="<?= e(einstellung($d, 'email')) ?>" placeholder="name@gmail.com"></label>
  <h4>Push aufs Handy (App „ntfy“)</h4>
  <ol class="schritte">
    <li>Die kostenlose App <strong>ntfy</strong> installieren (<a href="https://apps.apple.com/app/ntfy/id1625396347" target="_blank" rel="noopener noreferrer">iPhone</a> · <a href="https://play.google.com/store/apps/details?id=io.heckel.ntfy" target="_blank" rel="noopener noreferrer">Android</a>).</li>
    <li>In der App auf <strong>+</strong> tippen und dieses Thema abonnieren (Server: ntfy.sh):</li>
  </ol>
  <div class="kopierfeld"><code id="ntfy-thema"><?= e($thema) ?></code><button class="knopf klein zweit" type="button" data-kopieren="#ntfy-thema">Kopieren</button></div>
  <p class="klein leise">Das Thema ist zufällig und nur dir bekannt – wer es nicht kennt, sieht deine Meldungen nicht.</p>
  <label class="schalter"><input type="checkbox" name="push" value="1"<?= einstellung($d, 'ntfy_aus') !== '1' ? ' checked' : '' ?>><span>Push-Meldungen senden</span></label>
  <div class="knopf-reihe">
    <button class="knopf" type="submit"><?= ico('haken') ?> Speichern</button>
    <button class="knopf zweit" type="submit" name="test" value="1"><?= ico('glocke') ?> Speichern und Test senden</button>
  </div>
</form>
<?= akk('Benachrichtigungen', 'glocke', (string)ob_get_clean(), ['offen' => true, 'id' => 'e-benachrichtigung']) ?>

<?php ob_start(); ?>
<form method="post" action="./?seite=einstellungen" class="formular">
  <?= csrfFeld() ?><input type="hidden" name="aktion" value="ki_einstellung">
  <p><?= $kiSchluessel ? ico('haken') . ' Ein Anthropic-Schlüssel ist auf dem Server hinterlegt.' : ico('warnung') . ' Es ist noch kein Anthropic-Schlüssel hinterlegt (GitHub-Secret <code>ANTHROPIC_API_KEY</code>).' ?></p>
  <fieldset class="auswahl"><legend>Modell für die KI-Einschätzung</legend>
    <?php foreach (FZ_MODELLE as $m => $t): ?><label class="schalter"><input type="radio" name="modell" value="<?= e($m) ?>"<?= kiModell($d) === $m ? ' checked' : '' ?>><span><?= e($t) ?></span></label><?php endforeach; ?>
  </fieldset>
  <div class="knopf-reihe">
    <button class="knopf" type="submit"><?= ico('haken') ?> Speichern</button>
    <?php if ($kiSchluessel): ?><button class="knopf zweit" type="submit" name="test" value="1"><?= ico('blitz') ?> Verbindung testen</button><?php endif; ?>
  </div>
</form>
<?= akk('KI-Einschätzung', 'blitz', (string)ob_get_clean(), ['id' => 'e-ki', 'meta' => e(kiModell($d) === 'claude-haiku-4-5' ? 'Haiku 4.5' : 'Opus 5.5')]) ?>

<?php ob_start(); ?>
<form method="post" action="./?seite=einstellungen" class="formular">
  <?= csrfFeld() ?><input type="hidden" name="aktion" value="passwort_aendern">
  <label class="feld"><span>Aktuelles Passwort</span><input type="password" name="alt" autocomplete="current-password" required></label>
  <label class="feld"><span>Neues Passwort (mindestens 10 Zeichen)</span><input type="password" name="neu" minlength="10" autocomplete="new-password" required></label>
  <label class="feld"><span>Neues Passwort wiederholen</span><input type="password" name="neu2" minlength="10" autocomplete="new-password" required></label>
  <button class="knopf" type="submit"><?= ico('schloss') ?> Passwort ändern</button>
</form>
<form method="post" action="./" data-bestaetigen="Auf allen Geräten abmelden?"><?= csrfFeld() ?><input type="hidden" name="aktion" value="ueberall_abmelden"><button class="knopf-text" type="submit"><?= ico('raus') ?> Auf allen Geräten abmelden</button></form>
<?= akk('Sicherheit', 'schloss', (string)ob_get_clean(), ['id' => 'e-sicherheit']) ?>

<?php ob_start(); ?>
<p><?= fzDatenExtern() ? ico('haken') . ' Deine Daten liegen <strong>außerhalb des Web-Verzeichnisses</strong> – per Internet nicht abrufbar und vor versehentlichem Löschen beim Deployment geschützt.' : ico('info') . ' Deine Daten liegen im geschützten App-Ordner (per .htaccess gesperrt).' ?></p>
<p>Automatische Tagessicherungen: <strong><?= $sicherungen ?></strong> (die letzten 30 Tage werden aufgehoben).</p>
<div class="knopf-reihe"><a class="knopf zweit" href="<?= e(url(['export' => '1'])) ?>"><?= ico('runter') ?> Alle Daten exportieren</a></div>
<details class="unterbereich"><summary><?= ico('hoch') ?> Daten aus einem Export wiederherstellen</summary>
  <form method="post" action="./?seite=einstellungen" enctype="multipart/form-data" class="formular" data-bestaetigen="Alle aktuellen Daten durch den Export ersetzen?">
    <?= csrfFeld() ?><input type="hidden" name="aktion" value="import">
    <label class="feld datei"><span>Export-Datei (.json)</span><input type="file" name="datei" accept=".json,application/json" required></label>
    <button class="knopf gefahr" type="submit">Wiederherstellen</button>
  </form>
</details>
<?= akk('Daten & Sicherung', 'runter', (string)ob_get_clean(), ['id' => 'e-daten']) ?>

<?php ob_start(); ?>
<ul class="liste-schlicht">
  <li><span>Kurse, Kennzahlen, Analysten, Aktionärsanteile</span><b>Yahoo Finance</b></li>
  <li><span>News</span><b>Google News</b></li>
  <li><span>Konzernstruktur, Tochtergesellschaften</span><b>GLEIF (LEI-Register)</b></li>
  <li><span>Beteiligungen, Gründung, Logo</span><b>Wikidata</b></li>
  <li><span>US-Pflichtmitteilungen</span><b>SEC EDGAR</b></li>
  <li><span>WKN-Suche</span><b>OpenFIGI</b></li>
  <li><span>KI-Einschätzung</span><b>Anthropic Claude</b></li>
</ul>
<p class="klein leise">Alle Angaben ohne Gewähr. Die Finanzzentrale ist ein privates Werkzeug und keine Anlageberatung.</p>
<?= akk('Datenquellen', 'info', (string)ob_get_clean(), ['id' => 'e-quellen']) ?>
<?php
    return (string)ob_get_clean();
}
