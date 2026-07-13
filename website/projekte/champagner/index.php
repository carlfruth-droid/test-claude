<?php
declare(strict_types=1);

// Anmeldung soll 30 Tage halten – eigene Session-Ablage, damit der
// Hoster die Sitzungen nicht früher wegräumt.
$sitzungsDir = __DIR__ . '/daten/sessions';
if (!is_dir($sitzungsDir)) {
    @mkdir($sitzungsDir, 0755, true);
}
if (is_dir($sitzungsDir) && is_writable($sitzungsDir)) {
    session_save_path($sitzungsDir);
}
ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 30));
session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 30,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
date_default_timezone_set('Europe/Berlin');
@ini_set('memory_limit', '512M'); // große Handyfotos beim Verkleinern verarbeiten können
header('Cache-Control: no-cache, must-revalidate'); // Handys sollen immer die frische Version holen

// Passwort zum Mitmachen (Bewerten und Champagner anlegen).
// Zum Ändern: einfach den Text zwischen den Anführungszeichen austauschen.
const PASSWORT = 'dontwastewater';

const DATEN_DATEI = __DIR__ . '/daten/bewertungen.json';
const BILDER_DIR  = __DIR__ . '/bilder';
const MAX_BILD_GROESSE = 25 * 1024 * 1024; // 25 MB pro Foto

$BILD_TYPEN = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];

$KATEGORIEN = [
    'duft'          => ['Duft',          'Wie angenehm und interessant riecht der Champagner?'],
    'perlage'       => ['Perlage',       'Wie fein und angenehm sind die Bläschen?'],
    'geschmack'     => ['Geschmack',     'Wie gut schmeckt er insgesamt?'],
    'balance'       => ['Balance',       'Passen Säure, Frucht und Kraft gut zusammen?'],
    'komplexitaet'  => ['Komplexität',   'Entdeckt man mehrere Aromen oder wirkt er eher einfach?'],
    'abgang'        => ['Abgang',        'Wie lange und angenehm bleibt der Geschmack?'],
    'besonderheit'  => ['Besonderheit',  'Hat der Champagner einen eigenen Charakter oder Wiedererkennungswert?'],
    'trinkfreude'   => ['Trinkfreude',   'Wie gerne würdest du ein zweites Glas trinken oder die Flasche kaufen?'],
];

/**
 * Bewertungs-Konfiguration je Getränkeart. Die 8 Kategorie-Schlüssel sind für
 * alle Typen gleich (gleiche Sterne-Logik); der „perlage“-Platz trägt je Typ
 * ein passendes Etikett (Tannin, Frische, Schaum) und die Wizard-Fragen folgen
 * der jeweils üblichen Verkostungs-Systematik (Wein: WSET-Schema Auge–Nase–
 * Gaumen–Abgang; Bier: Optik/Schaum–Geruch–Antrunk/Rezenz–Nachtrunk).
 */
function kategorienFuer(string $typ, array $kategorien): array
{
    if ($typ === 'rotwein') {
        $kategorien['perlage'] = ['Tannin', 'Wie fein und angenehm ist das Tannin (Gerbstoff)?'];
    } elseif ($typ === 'weisswein') {
        $kategorien['perlage'] = ['Frische', 'Wie lebendig und frisch wirkt der Wein?'];
    } elseif ($typ === 'bier') {
        $kategorien['duft'] = ['Geruch', 'Wie angenehm und interessant riecht das Bier?'];
        $kategorien['perlage'] = ['Schaum & Rezenz', 'Wie sind Schaum und Kohlensäure?'];
        $kategorien['trinkfreude'] = ['Trinkfreude', 'Wie gerne würdest du noch eins bestellen?'];
    }
    return $kategorien;
}

/** Wizard-Schritte (Fragen mit Antwort-Kacheln) je Getränkeart. */
function wizardSchritte(string $typ): array
{
    $smiley = [['top', '😍', 'Klasse'], ['gut', '🙂', 'Gut'], ['ok', '😐', 'Okay'], ['geht', '🙁', 'Schwach']];
    $balance = [['perfekt', '⚖️', 'Perfekt rund'], ['stimmig', '👍', 'Stimmig'], ['unrund', '😕', 'Etwas unrund'], ['schlecht', '👎', 'Unausgewogen']];
    $abgang = [['kurz', '⏱', 'Kurz (&lt;5&nbsp;Sek.)'], ['mittel', '⏱⏱', 'Mittel (5–15&nbsp;Sek.)'], ['lang', '⏱⏱⏱', 'Lang (&gt;15&nbsp;Sek.)']];
    $charakter = [['unverwechselbar', '🌟', 'Unverwechselbar'], ['hatwas', '👌', 'Hat was'], ['austauschbar', '😶', 'Austauschbar']];

    if ($typ === 'rotwein') {
        return [
            ['titel' => '👁 Das Auge', 'fragen' => [
                ['frage' => 'Welche Farbe hat er? (Purpur = jung, Ziegel = reif)', 'feld' => 'farbe', 'optionen' => [
                    ['purpur', '🟣', 'Purpurrot'], ['rubin', '❤️', 'Rubinrot'], ['granat', '🟥', 'Granatrot'], ['ziegel', '🧱', 'Ziegelrot'],
                ]],
                ['frage' => 'Wie dicht ist die Farbe?', 'feld' => 'tiefe', 'optionen' => [
                    ['blass', '💧', 'Blass'], ['mittel', '🌗', 'Mittel'], ['tief', '🌑', 'Tief & dicht'],
                ]],
            ]],
            ['titel' => '👃 Die Nase', 'fragen' => [
                ['frage' => 'Riecht er sauber?', 'feld' => 'sauber', 'optionen' => [
                    ['ja', '✅', 'Sauber'], ['kork', '🚫', 'Kork / muffig'],
                ]],
                ['frage' => 'Welche Aromen erkennst du? <small>(mehrere)</small>', 'feld' => 'aromen', 'mehrfach' => true, 'block' => true, 'optionen' => [
                    ['kirsche', '🍒', 'Kirsche'], ['dunkle_beeren', '🫐', 'Dunkle Beeren'], ['rote_beeren', '🍓', 'Rote Beeren'],
                    ['pflaume', '🍑', 'Pflaume/Dörrobst'], ['pfeffer', '🌶️', 'Pfeffer/Gewürz'], ['vanille', '🍦', 'Vanille'],
                    ['schoko', '🍫', 'Schokolade/Kaffee'], ['tabak', '🍂', 'Tabak/Leder'], ['kraeuter', '🌿', 'Kräuter'],
                    ['veilchen', '🌸', 'Veilchen/Blüten'], ['holz', '🪵', 'Holz/Rauch'], ['erdig', '🍄', 'Erdig/Waldboden'], ['lakritz', '⚫', 'Lakritz'],
                ]],
                ['frage' => 'Wie gefällt dir der Duft?', 'feld' => 'duft', 'block' => true, 'optionen' => $smiley],
            ]],
            ['titel' => '👅 Der Mund', 'fragen' => [
                ['frage' => 'Wie ist das Tannin? (das leicht pelzige Gefühl am Zahnfleisch)', 'feld' => 'perlage', 'optionen' => [
                    ['fein', '🪶', 'Samtig & fein'], ['mittel', '🧤', 'Spürbar, passt'], ['grob', '🧱', 'Hart/pelzig'],
                ]],
                ['frage' => 'Wie ist der Körper?', 'feld' => 'koerper', 'optionen' => [
                    ['leicht', '🎈', 'Leicht'], ['mittel', '🌗', 'Mittel'], ['voll', '💪', 'Voll & kräftig'],
                ]],
                ['frage' => 'Wirkt alles ausgewogen? (Frucht, Säure, Tannin, Alkohol)', 'feld' => 'balance', 'optionen' => $balance],
                ['frage' => 'Wie schmeckt er dir insgesamt?', 'feld' => 'geschmack', 'optionen' => $smiley],
            ]],
            ['titel' => '⏱ Der Abgang', 'fragen' => [
                ['frage' => 'Wie lange bleibt der Geschmack nach dem Schlucken?', 'feld' => 'abgang', 'optionen' => $abgang],
                ['frage' => 'Hat er Charakter / Wiedererkennungswert?', 'feld' => 'charakter', 'optionen' => $charakter],
                ['frage' => 'Noch ein Glas?', 'feld' => 'nochmal', 'optionen' => [
                    ['sofort', '🍷', 'Sofort!'], ['gerne', '🙂', 'Gerne'], ['muss_nicht', '🤷', 'Muss nicht'], ['nein', '🙅', 'Nein'],
                ]],
            ]],
        ];
    }

    if ($typ === 'weisswein') {
        return [
            ['titel' => '👁 Das Auge', 'fragen' => [
                ['frage' => 'Welche Farbe hat er? (Grüngelb = jung, Gold = reif/Holz)', 'feld' => 'farbe', 'optionen' => [
                    ['gruengelb', '🟢', 'Grüngelb'], ['stroh', '🌾', 'Strohgelb'], ['gold', '✨', 'Goldgelb'], ['bernstein', '🟠', 'Bernstein'],
                ]],
            ]],
            ['titel' => '👃 Die Nase', 'fragen' => [
                ['frage' => 'Riecht er sauber?', 'feld' => 'sauber', 'optionen' => [
                    ['ja', '✅', 'Sauber'], ['kork', '🚫', 'Kork / muffig'],
                ]],
                ['frage' => 'Welche Aromen erkennst du? <small>(mehrere)</small>', 'feld' => 'aromen', 'mehrfach' => true, 'block' => true, 'optionen' => [
                    ['zitrus', '🍋', 'Zitrus'], ['apfel', '🍏', 'Apfel/Birne'], ['steinobst', '🍑', 'Pfirsich/Aprikose'],
                    ['exotisch', '🍍', 'Exotisch'], ['stachelbeere', '🥝', 'Stachelbeere/Kiwi'], ['blueten', '🌼', 'Blüten'],
                    ['kraeuter', '🌿', 'Kräuter/Gras'], ['mineralisch', '⚗️', 'Mineralisch'], ['honig', '🍯', 'Honig'],
                    ['butter', '🧈', 'Butter/Karamell'], ['nuss', '🥜', 'Nuss/Mandel'], ['holz', '🪵', 'Holz/Vanille'],
                ]],
                ['frage' => 'Wie gefällt dir der Duft?', 'feld' => 'duft', 'block' => true, 'optionen' => $smiley],
            ]],
            ['titel' => '👅 Der Mund', 'fragen' => [
                ['frage' => 'Wie frisch und lebendig wirkt er? (Säure = Speichelfluss)', 'feld' => 'perlage', 'optionen' => [
                    ['fein', '⚡', 'Lebendig & frisch'], ['mittel', '🙂', 'Angenehm'], ['grob', '😴', 'Müde/flach'],
                ]],
                ['frage' => 'Wie ist die Süße?', 'feld' => 'suesse', 'optionen' => [
                    ['trocken', '🏜️', 'Trocken'], ['feinherb', '🌗', 'Feinherb'], ['suess', '🍯', 'Süß'],
                ]],
                ['frage' => 'Wirkt alles ausgewogen? (Säure, Frucht, Süße, Körper)', 'feld' => 'balance', 'optionen' => $balance],
                ['frage' => 'Wie schmeckt er dir insgesamt?', 'feld' => 'geschmack', 'optionen' => $smiley],
            ]],
            ['titel' => '⏱ Der Abgang', 'fragen' => [
                ['frage' => 'Wie lange bleibt der Geschmack nach dem Schlucken?', 'feld' => 'abgang', 'optionen' => $abgang],
                ['frage' => 'Hat er Charakter / Wiedererkennungswert?', 'feld' => 'charakter', 'optionen' => $charakter],
                ['frage' => 'Noch ein Glas?', 'feld' => 'nochmal', 'optionen' => [
                    ['sofort', '🥂', 'Sofort!'], ['gerne', '🙂', 'Gerne'], ['muss_nicht', '🤷', 'Muss nicht'], ['nein', '🙅', 'Nein'],
                ]],
            ]],
        ];
    }

    if ($typ === 'bier') {
        return [
            ['titel' => '👁 Das Auge', 'fragen' => [
                ['frage' => 'Welche Farbe hat es?', 'feld' => 'farbe', 'optionen' => [
                    ['hell', '🌕', 'Hell/Stroh'], ['gold', '✨', 'Golden'], ['bernstein', '🟠', 'Bernstein'], ['dunkel', '🟤', 'Dunkel'], ['schwarz', '⚫', 'Schwarz'],
                ]],
                ['frage' => 'Wie ist der Schaum? (feinporig & stabil = top)', 'feld' => 'perlage', 'optionen' => [
                    ['fein', '☁️', 'Feinporig & stabil'], ['mittel', '🫧', 'Okay'], ['grob', '💨', 'Schnell weg/grob'],
                ]],
            ]],
            ['titel' => '👃 Die Nase', 'fragen' => [
                ['frage' => 'Riecht es frisch?', 'feld' => 'sauber', 'optionen' => [
                    ['ja', '✅', 'Frisch'], ['kork', '🚫', 'Alt (Pappe/muffig)'],
                ]],
                ['frage' => 'Welche Aromen erkennst du? <small>(mehrere)</small>', 'feld' => 'aromen', 'mehrfach' => true, 'block' => true, 'optionen' => [
                    ['zitrushopfen', '🍋', 'Zitrus (Hopfen)'], ['blumig', '🌼', 'Blumig (Hopfen)'], ['harzig', '🌲', 'Harzig/grasig'],
                    ['tropisch', '🍍', 'Tropisch'], ['karamell', '🍬', 'Karamell'], ['brotig', '🍞', 'Brotig/Getreide'],
                    ['roest', '☕', 'Röst/Kaffee'], ['schoko', '🍫', 'Schokolade'], ['honig', '🍯', 'Honig'],
                    ['banane', '🍌', 'Banane (Hefe)'], ['nelke', '🌸', 'Nelke/würzig'], ['rauch', '🔥', 'Rauch'], ['nuss', '🥜', 'Nuss'],
                ]],
                ['frage' => 'Wie gefällt dir der Geruch?', 'feld' => 'duft', 'block' => true, 'optionen' => $smiley],
            ]],
            ['titel' => '👅 Der Mund', 'fragen' => [
                ['frage' => 'Wie ist der Antrunk?', 'feld' => 'antrunk', 'optionen' => [
                    ['spritzig', '⚡', 'Spritzig'], ['weich', '☁️', 'Weich & rund'], ['schal', '💤', 'Schal'],
                ]],
                ['frage' => 'Sind Malz (Süße) und Hopfen (Bittere) im Gleichgewicht?', 'feld' => 'balance', 'optionen' => $balance],
                ['frage' => 'Wie schmeckt es dir insgesamt?', 'feld' => 'geschmack', 'optionen' => $smiley],
            ]],
            ['titel' => '⏱ Der Abgang', 'fragen' => [
                ['frage' => 'Wie lange bleibt der Geschmack? Ist die Bittere angenehm?', 'feld' => 'abgang', 'optionen' => $abgang],
                ['frage' => 'Hat es Charakter / Wiedererkennungswert?', 'feld' => 'charakter', 'optionen' => $charakter],
                ['frage' => 'Noch eins?', 'feld' => 'nochmal', 'optionen' => [
                    ['sofort', '🍺', 'Sofort!'], ['gerne', '🙂', 'Gerne'], ['muss_nicht', '🤷', 'Muss nicht'], ['nein', '🙅', 'Nein'],
                ]],
            ]],
        ];
    }

    // Champagner (Standard) – exakt die bewährten Fragen und Antwort-Werte
    return [
        ['titel' => '👁 Das Auge', 'fragen' => [
            ['frage' => 'Welche Farbe hat er?', 'feld' => 'farbe', 'optionen' => [
                ['zitrus', '🍋', 'Zitronengelb'], ['gold', '✨', 'Goldgelb'], ['kupfer', '🟠', 'Kupfer'], ['lachs', '🌸', 'Lachsrosé'], ['himbeer', '🍓', 'Himbeerrot'],
            ]],
            ['frage' => 'Wie ist die Perlage?', 'feld' => 'perlage', 'optionen' => [
                ['fein', '💫', 'Sehr fein'], ['mittel', '🫧', 'Mittel'], ['grob', '⚪', 'Grob'],
            ]],
        ]],
        ['titel' => '👃 Die Nase', 'fragen' => [
            ['frage' => 'Riecht er sauber?', 'feld' => 'sauber', 'optionen' => [
                ['ja', '✅', 'Sauber'], ['kork', '🚫', 'Kork / muffig'],
            ]],
            ['frage' => 'Welche Aromen erkennst du? <small>(mehrere)</small>', 'feld' => 'aromen', 'mehrfach' => true, 'block' => true, 'optionen' => [
                ['apfel', '🍏', 'Apfel/Birne'], ['zitrus', '🍋', 'Zitrus'], ['steinobst', '🍑', 'Steinobst'], ['beeren', '🍓', 'Rote Beeren'],
                ['exotisch', '🍍', 'Exotisch'], ['brioche', '🥐', 'Brioche'], ['toast', '🍞', 'Toast'], ['nuss', '🥜', 'Nuss/Mandel'],
                ['honig', '🍯', 'Honig'], ['butter', '🧈', 'Butter/Karamell'], ['erdig', '🍄', 'Erdig/reif'], ['blueten', '🌼', 'Blüten'],
                ['kraeuter', '🌿', 'Kräuter'], ['mineralisch', '⚗️', 'Mineralisch'],
            ]],
            ['frage' => 'Wie gefällt dir der Duft?', 'feld' => 'duft', 'block' => true, 'optionen' => $smiley],
        ]],
        ['titel' => '👅 Der Mund', 'fragen' => [
            ['frage' => 'Wie ist die Säure?', 'feld' => 'saeure', 'optionen' => [
                ['hoch', '🍋🍋🍋', 'Frisch/hoch'], ['mittel', '🍋🍋', 'Mittel'], ['mild', '🍋', 'Mild'],
            ]],
            ['frage' => 'Wie ist die Mousse?', 'feld' => 'mousse', 'optionen' => [
                ['cremig', '☁️', 'Cremig'], ['lebhaft', '🫧', 'Lebhaft'], ['aggressiv', '⚡', 'Prickelig'],
            ]],
            ['frage' => 'Wirkt alles ausgewogen?', 'feld' => 'balance', 'optionen' => $balance],
            ['frage' => 'Wie schmeckt er dir insgesamt?', 'feld' => 'geschmack', 'optionen' => $smiley],
        ]],
        ['titel' => '⏱ Der Abgang', 'fragen' => [
            ['frage' => 'Wie lange bleibt der Geschmack nach dem Schlucken?', 'feld' => 'abgang', 'optionen' => $abgang],
            ['frage' => 'Hat er Charakter / Wiedererkennungswert?', 'feld' => 'charakter', 'optionen' => $charakter],
            ['frage' => 'Noch ein Glas?', 'feld' => 'nochmal', 'optionen' => [
                ['sofort', '🥂', 'Sofort!'], ['gerne', '🙂', 'Gerne'], ['muss_nicht', '🤷', 'Muss nicht'], ['nein', '🙅', 'Nein'],
            ]],
        ]],
    ];
}

/** Beschriftung des Sorten-Felds je Getränkeart. */
function sortenLabel(string $typ): string
{
    return $typ === 'bier' ? 'Sorte/Stil, z. B. Pils, IPA (optional)' : 'Rebsorte, z. B. Chardonnay (optional)';
}

/**
 * Gängige Rebsorten, Weinstile bzw. Bierstile je Getränkeart – zum Antippen
 * statt Tippen, jeweils mit kurzer Erklärung (erscheint beim Antippen).
 */
function sortenVorschlaege(string $typ): array
{
    return match ($typ) {
        'rotwein' => [
            'Spätburgunder'      => 'auch Pinot Noir – elegant, rote Frucht (Kirsche, Erdbeere), feines Tannin',
            'Merlot'             => 'weich und rund, Pflaume und dunkle Beeren, wenig Kanten',
            'Cabernet Sauvignon' => 'kräftig, Cassis und Paprika, deutliches Tannin, reift lange',
            'Syrah/Shiraz'       => 'würzig – schwarzer Pfeffer, dunkle Frucht, oft rauchig',
            'Tempranillo'        => 'die Rioja-Traube – Kirsche, Leder, Vanille vom Fass',
            'Primitivo'          => 'auch Zinfandel – süßfruchtig und kräftig, viel Wärme',
            'Sangiovese'         => 'die Chianti-Traube – Sauerkirsche, Kräuter, lebendige Säure',
            'Nebbiolo'           => 'die Barolo-Traube – hell in der Farbe, aber mächtiges Tannin, Rose und Teer',
            'Garnacha'           => 'auch Grenache – warm, Himbeere und Gewürz, oft im Verschnitt',
            'Malbec'             => 'Argentiniens Star – dunkel, samtig, Brombeere und Veilchen',
            'Blaufränkisch'      => 'Österreich – dunkle Kirsche, Pfeffer, frische Säure',
            'Zweigelt'           => 'Österreichs häufigste rote – fruchtig-weich, Kirsche',
            'Bardolino'          => 'leichter Italiener vom Gardasee (Corvina-Verschnitt) – frisch, Kirsche',
            'Valpolicella'       => 'norditalienischer Klassiker, gleiche Trauben wie Amarone, aber leicht',
            'Ripasso'            => 'Valpolicella „aufgezogen“ am Amarone-Trester – kräftiger, samtig',
            'Amarone'            => 'aus getrockneten (rosinierten) Trauben – wuchtig, süßliche Frucht, hoher Alkohol',
            'Chianti'            => 'Toskana, aus Sangiovese – Kirsche, Kräuter, gute Säure zum Essen',
            'Rioja'              => 'Spanien, meist Tempranillo – Vanille und Kokos durch Fassreife',
            'Cuvée'              => 'Verschnitt mehrerer Rebsorten',
        ],
        'weisswein' => [
            'Riesling'         => 'Deutschlands Königin – Pfirsich, Apfel, spannende Säure, oft mineralisch',
            'Chardonnay'       => 'vielseitig – schlank-mineralisch (Chablis) bis cremig-buttrig (Holzfass)',
            'Sauvignon Blanc'  => 'Stachelbeere, frisch gemähtes Gras, exotische Frucht – knackig frisch',
            'Grauburgunder'    => 'auch Pinot Grigio – mild, Birne und Nuss, unkompliziert',
            'Weißburgunder'    => 'zart – Apfel, Blüten, sanfte Säure, guter Essensbegleiter',
            'Grüner Veltliner' => 'Österreich – das berühmte „Pfefferl“, Apfel, Zitrus',
            'Gewürztraminer'   => 'Duftwunder – Rose, Litschi, Gewürz, oft leicht süß',
            'Silvaner'         => 'Franken – erdig-ruhig, Birne, dezente Säure',
            'Müller-Thurgau'   => 'leicht und blumig, Muskatnote, früh zu trinken',
            'Chenin Blanc'     => 'Loire und Südafrika – Quitte, Honig, von trocken bis edelsüß',
            'Albariño'         => 'Spaniens Atlantikküste – salzig-frisch, Aprikose, Zitrus',
            'Viognier'         => 'üppig – Aprikose, Blüten, cremige Textur',
            'Verdejo'          => 'Spanien (Rueda) – grasig-frisch, Fenchel, Zitrus',
            'Soave'            => 'Norditalien (Garganega-Traube) – zart, Mandel, weiße Blüten',
            'Lugana'           => 'vom Gardasee – cremig und doch frisch, Pfirsich, Mandel',
            'Verdicchio'       => 'Marken – frisch, Mandel im Abgang, gute Säure',
            'Chablis'          => 'Chardonnay aus dem nördlichen Burgund – stahlig, mineralisch, kein Holz',
            'Muskateller'      => 'duftet nach Traube pur – Blüten, Muskat, meist leicht',
            'Cuvée'            => 'Verschnitt mehrerer Rebsorten',
        ],
        'bier' => [
            'Pils'       => 'hopfenbetont, schlank, feinherb – der deutsche Klassiker',
            'Helles'     => 'malzig-mild, süffig, wenig Bittere – Bayerns Antwort aufs Pils',
            'Weizen'     => 'obergärig, Banane und Nelke von der Hefe, spritzig',
            'IPA'        => 'India Pale Ale – stark gehopft, tropische Frucht, kräftige Bittere',
            'Pale Ale'   => 'wie IPA, aber gemäßigter – fruchtiger Hopfen, ausgewogen',
            'Lager'      => 'untergärig, klar und sauber, dezent gehopft',
            'Dunkles'    => 'dunkles Malz – Brotkruste, Karamell, mild',
            'Bock'       => 'Starkbier – malzig, kräftig, oft süßlich, mehr Alkohol',
            'Stout'      => 'fast schwarz – Röstaromen, Kaffee, Schokolade, cremig',
            'Porter'     => 'dunkel wie Stout, meist etwas leichter und süßer',
            'Kellerbier' => 'unfiltriert und naturtrüb – hefig, frisch vom Fass',
            'Kölsch'     => 'hell, obergärig, schlank – nur aus Köln',
            'Altbier'    => 'kupferfarben, obergärig – Düsseldorfs malzig-herbe Antwort',
            'Sauerbier'  => 'gezielt säuerlich (Gose, Berliner Weisse) – erfrischend, oft fruchtig',
        ],
        default => [
            'Chardonnay'       => 'bringt Frische, Zitrus und Eleganz in den Champagner',
            'Pinot Noir'       => 'gibt Kraft, Struktur und rote Frucht',
            'Meunier'          => 'macht ihn fruchtig, weich und früh zugänglich',
            'Blanc de Blancs'  => 'nur aus weißen Trauben (Chardonnay) – fein und frisch',
            'Blanc de Noirs'   => 'weiß gekeltert aus roten Trauben – kraftvoller Stil',
            'Rosé'             => 'mit rotem Wein oder Maischekontakt – Beerenfrucht',
            'Millésime'        => 'Jahrgangs-Champagner – nur aus einem einzigen (guten) Jahr',
            'Cuvée'            => 'Verschnitt aus Trauben, Lagen und Jahrgängen – der Normalfall',
        ],
    };
}

/** Antipp-Chips für Rebsorte/Stil: füllen das Textfeld, zeigen beim Antippen eine Kurzerklärung. */
function sortenChips(string $zielId, string $aktiveKat): string
{
    $html = '<div class="sorten-chips" data-ziel="' . e($zielId) . '">';
    foreach (['champagner', 'rotwein', 'weisswein', 'bier'] as $k) {
        $html .= '<div class="sorten-set" data-kat="' . $k . '"' . ($k === $aktiveKat ? '' : ' hidden') . '>';
        foreach (sortenVorschlaege($k) as $s => $info) {
            $html .= '<button type="button" class="sorte" data-info="' . e($info) . '">' . e($s) . '</button>';
        }
        $html .= '</div>';
    }
    return $html . '<p class="sorten-info" hidden></p></div>';
}

/**
 * Foto-Upload als direkte Knöpfe: „Foto aufnehmen“ öffnet die Kamera,
 * „Aus Galerie wählen“ den Bild-Picker – nach der Auswahl lädt das Formular
 * automatisch hoch (kein totes „Hochladen“ ohne gewählte Datei mehr).
 */
function fotoUploadFelder(string $prefix): string
{
    $p = htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8');
    return '<input type="file" name="fotos[]" accept="image/*" capture="environment" id="' . $p . '-kamera" class="upload-direkt" style="display:none;">'
        . '<input type="file" name="fotos[]" accept="image/*" multiple id="' . $p . '-galerie" class="upload-direkt" style="display:none;">'
        . '<div class="knopfreihe">'
        . '<label class="knopf" for="' . $p . '-kamera">📷&nbsp; Foto aufnehmen</label>'
        . '<label class="knopf zweit" for="' . $p . '-galerie">🖼️&nbsp; Aus Galerie wählen</label>'
        . '</div>'
        . '<noscript><button class="knopf" type="submit" style="margin-top:0.6rem;">Hochladen</button></noscript>';
}

if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

/** Teilnehmer zu einem Einladungs-Token finden: [tasting, teilnehmer] oder null. */
function teilnehmerZuToken(array $daten, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{16}$/', $token)) {
        return null;
    }
    foreach ($daten['tastings'] as $t) {
        foreach (($t['teilnehmer'] ?? []) as $p) {
            if (hash_equals($p['token'], $token)) {
                return [$t, $p];
            }
        }
    }
    return null;
}

/** Benutzerkonto zum Dauer-Login-Token finden. */
function benutzerZuToken(array $daten, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    foreach (($daten['benutzer'] ?? []) as $b) {
        if (hash_equals((string)($b['token'] ?? ''), $token)) {
            return $b;
        }
    }
    return null;
}

/** Aktuell angemeldeter Benutzer (per Dauer-Cookie) oder null. */
function aktuellerBenutzer(array $daten): ?array
{
    return benutzerZuToken($daten, (string)($_COOKIE['benutzer'] ?? ''));
}

/** Darf dieser Benutzer Tastings anlegen? (Admin oder freigeschaltet) */
function darfTastingsAnlegen(?array $b): bool
{
    return $b !== null && (!empty($b['admin']) || !empty($b['darf_tasting']));
}

/** Gast-Tasting = ohne Account angelegt: maximal Gastgeber + 1 eingeladene Person. */
function teilnehmerLimitErreicht(array $t): bool
{
    return !empty($t['gast']) && count($t['teilnehmer'] ?? []) >= 2;
}

/** Profilfoto einer Person (Dateiname) oder ''. */
function profilFoto(array $daten, string $name): string
{
    $datei = (string)($daten['profile'][mb_strtolower(trim($name))] ?? '');
    return $datei !== '' && is_file(BILDER_DIR . '/' . $datei) ? $datei : '';
}

/** Bewertung der aktuellen Person zu einem Getränk oder null. */
function meineBewertung(array $daten, string $cid): ?array
{
    $ich = mb_strtolower(trim((string)($_SESSION['person'] ?? '')));
    if ($ich === '') {
        return null;
    }
    foreach ($daten['bewertungen'] as $b) {
        if ($b['champagner_id'] === $cid && mb_strtolower(trim((string)$b['person'])) === $ich) {
            return $b;
        }
    }
    return null;
}

/** Grobe Distanz in Metern zwischen zwei Koordinaten (für kleine Abstände ausreichend). */
function distanzMeter(float $lat1, float $lon1, float $lat2, float $lon2): int
{
    $dx = ($lon2 - $lon1) * cos(deg2rad($lat1)) * 111320;
    $dy = ($lat2 - $lat1) * 110540;
    return (int)round(sqrt($dx * $dx + $dy * $dy));
}

/**
 * War eine Bewertung „live“ (im Moment der Verkostung) oder nachträglich?
 * Live = innerhalb von 6 Stunden nach Anlage des Getränks abgegeben.
 * Gespeicherte Kennzeichnung hat Vorrang; sonst wird sie hergeleitet.
 */
function bewertungWarLive(array $b, ?array $getraenk): bool
{
    if (array_key_exists('live', $b)) {
        return (bool)$b['live'];
    }
    $gz = (int)($getraenk['zeit'] ?? 0);
    return $gz === 0 || ((int)($b['zeit'] ?? 0) - $gz) <= 6 * 3600;
}

/**
 * Wer war bei dieser Bewertung mit dabei? Andere Bewerter desselben Getränks
 * im Zeitfenster von ±2 Stunden – und, falls beide Standorte haben, im Umkreis
 * von 250 m. Das fließt als gemeinsames Erlebnis ins Bewertungs-Umfeld ein.
 */
function mitDabei(array $bewertungen, array $b): array
{
    $dabei = [];
    foreach ($bewertungen as $andere) {
        if (mb_strtolower(trim((string)$andere['person'])) === mb_strtolower(trim((string)$b['person']))) {
            continue;
        }
        if (abs((int)($andere['zeit'] ?? 0) - (int)($b['zeit'] ?? 0)) > 2 * 3600) {
            continue;
        }
        if (isset($b['lat'], $b['lon'], $andere['lat'], $andere['lon'])
            && is_numeric($b['lat']) && is_numeric($andere['lat'])
            && distanzMeter((float)$b['lat'], (float)$b['lon'], (float)$andere['lat'], (float)$andere['lon']) > 250) {
            continue; // beide orten sich, aber weit auseinander → nicht zusammen
        }
        $dabei[] = (string)$andere['person'];
    }
    return array_values(array_unique($dabei));
}

// Benutzerkonto-Cookie: dauerhaft angemeldet bleiben (läuft nicht ab)
if (($_SESSION['tasting_ok'] ?? false) !== true && isset($_COOKIE['benutzer'])) {
    $kontoTreffer = benutzerZuToken(datenLaden(), (string)$_COOKIE['benutzer']);
    if ($kontoTreffer !== null) {
        $_SESSION['tasting_ok'] = true;
        if (trim((string)($_SESSION['person'] ?? '')) === '') {
            $_SESSION['person'] = (string)($kontoTreffer['vorname'] ?? '');
        }
    }
}

// Einladungslink angeklickt? Beim ersten Mal einmalig die Nutzungsbedingungen
// bestätigen lassen, danach Cookie setzen und automatisch anmelden.
$einladungFrage = null;
if (isset($_GET['einladung'])) {
    $treffer = teilnehmerZuToken(datenLaden(), (string)$_GET['einladung']);
    if ($treffer === null) {
        header('Location: ./?fehler=' . rawurlencode('Dieser Einladungslink ist nicht (mehr) gültig.'));
        exit;
    }
    [$einladungsTasting, $teilnehmer] = $treffer;
    if (!empty($teilnehmer['agb_zeit'])) {
        setcookie('einladung', $teilnehmer['token'], [
            'expires' => time() + 60 * 60 * 24 * 3650, // läuft praktisch nie ab
            'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
        ]);
        tastingWaehlen($einladungsTasting['id']);
        $_SESSION['tasting_ok'] = true;
        $_SESSION['person'] = $teilnehmer['name'];
        header('Location: ./?ok=' . rawurlencode('Willkommen, ' . $teilnehmer['name'] . '! Du bist angemeldet für „' . $einladungsTasting['titel'] . '“. 🥂'));
        exit;
    }
    // Noch keine Bestätigung: Frage-Seite anzeigen (kein Redirect)
    $einladungFrage = [
        'tasting' => $einladungsTasting,
        'teilnehmer' => $teilnehmer,
        'token' => (string)$_GET['einladung'],
    ];
}

// Wiederkehrende Gäste am Einladungs-Cookie erkennen
if (($_SESSION['tasting_ok'] ?? false) !== true && isset($_COOKIE['einladung'])) {
    $treffer = teilnehmerZuToken(datenLaden(), (string)$_COOKIE['einladung']);
    if ($treffer !== null) {
        $_SESSION['tasting_ok'] = true;
        if (trim((string)($_SESSION['person'] ?? '')) === '') {
            $_SESSION['person'] = $treffer[1]['name'];
        }
    }
}

$eingeloggt = ($_SESSION['tasting_ok'] ?? false) === true;

// Bildinfo für die Großansicht: Aufnahmedatum, Kamera, Ort, Maße, Zuordnung.
// Wird vom ℹ️-Knopf der Großansicht per fetch geholt und als JSON geliefert.
if (isset($_GET['bildinfo'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!$eingeloggt) {
        echo json_encode(['fehler' => 'Bitte zuerst anmelden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $datei = basename((string)$_GET['bildinfo']);
    $pfad = BILDER_DIR . '/' . $datei;
    if (!preg_match('/\.(jpe?g|png|gif|webp)$/i', $datei) || !is_file($pfad)) {
        echo json_encode(['fehler' => 'Bild nicht gefunden.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $d = datenLaden();
    $zeilen = [];
    // Zuordnung: das Dateipräfix verrät, wozu das Bild gehört
    if (preg_match('/^([a-f0-9]{8})-/', $datei, $m) && ($c = champagnerHolen($d, $m[1])) !== null) {
        $zeilen[] = ['🥂 Gehört zu', (string)$c['name']];
    } elseif (preg_match('/^wg-([a-f0-9]{8})-/', $datei, $m) && ($w = weingutHolen($d, $m[1])) !== null) {
        $zeilen[] = ['🍇 Gehört zu', 'Weingut ' . $w['name']];
    } elseif (preg_match('/^ts-([a-f0-9]{8})-/', $datei, $m)) {
        foreach ($d['tastings'] as $t) {
            if ($t['id'] === $m[1]) { $zeilen[] = ['👥 Gehört zu', 'Tasting „' . $t['titel'] . '“']; break; }
        }
    } elseif (str_starts_with($datei, 'div-')) {
        $zeilen[] = ['📸 Gehört zu', 'Fotoalbum (Gruppenfoto)'];
    } elseif (str_starts_with($datei, 'pf-')) {
        $zeilen[] = ['👤 Gehört zu', 'Profilbild'];
    }
    $uploader = $d['bilder'][$datei] ?? null;
    if ($uploader !== null && trim((string)($uploader['person'] ?? '')) !== '') {
        $zeilen[] = ['⬆️ Hochgeladen von', trim((string)$uploader['person'])];
    }
    $exif = (function_exists('exif_read_data') && preg_match('/\.jpe?g$/i', $datei))
        ? (@exif_read_data($pfad) ?: [])
        : [];
    $aufnahme = '';
    foreach (['DateTimeOriginal', 'DateTimeDigitized', 'DateTime'] as $k) {
        $roh = (string)($exif[$k] ?? '');
        if ($roh !== '' && ($ts = strtotime($roh)) !== false) {
            $aufnahme = date('d.m.Y, H:i', $ts) . ' Uhr';
            break;
        }
    }
    if ($aufnahme === '') {
        $aufnahme = date('d.m.Y, H:i', (int)filemtime($pfad)) . ' Uhr (Zeitpunkt des Hochladens)';
    }
    $zeilen[] = ['📅 Aufnahme', $aufnahme];
    $kamera = trim((string)($exif['Make'] ?? '') . ' ' . (string)($exif['Model'] ?? ''));
    if ($kamera !== '') {
        $zeilen[] = ['📷 Kamera', $kamera];
    }
    $karte = '';
    $gps = gpsAusFoto($pfad);
    if ($gps !== null) {
        $ort = ortKurz($gps[0], $gps[1]);
        $zeilen[] = ['📍 Ort', $ort !== '' ? $ort : sprintf('%.5f, %.5f', $gps[0], $gps[1])];
        $karte = 'https://www.google.com/maps/search/?api=1&query=' . $gps[0] . ',' . $gps[1];
    }
    $groesse = @getimagesize($pfad);
    if ($groesse !== false) {
        $zeilen[] = ['🖼️ Maße', $groesse[0] . ' × ' . $groesse[1] . ' Pixel'];
    }
    $bytes = (int)filesize($pfad);
    $zeilen[] = ['💾 Dateigröße', $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int)round($bytes / 1024)) . ' kB'];
    $zeilen[] = ['🗂️ Datei', $datei];
    echo json_encode(['zeilen' => $zeilen, 'karte' => $karte], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Das Tasting des aktuellen Nutzers: zuerst das ausdrücklich gewählte
 * (zuletzt geöffnet/beigetreten/angelegt), sonst das der Einladung,
 * sonst das neueste.
 */
function meinTasting(array $daten): ?array
{
    $gewaehlt = (string)($_COOKIE['tasting_aktuell'] ?? '');
    if (preg_match('/^[a-f0-9]{8}$/', $gewaehlt)) {
        foreach ($daten['tastings'] as $t) {
            if ($t['id'] === $gewaehlt) {
                return $t;
            }
        }
    }
    $treffer = teilnehmerZuToken($daten, (string)($_COOKIE['einladung'] ?? ''));
    if ($treffer !== null) {
        return $treffer[0];
    }
    $ts = $daten['tastings'];
    usort($ts, static fn(array $a, array $b): int => ((int)($b['zeit'] ?? 0)) <=> ((int)($a['zeit'] ?? 0)));
    return $ts[0] ?? null;
}

/** Ein Tasting zum „aktuellen“ machen – Header, Listen und Neuanlagen folgen. */
function tastingWaehlen(string $tid): void
{
    setcookie('tasting_aktuell', $tid, [
        'expires' => time() + 60 * 60 * 24 * 3650,
        'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
    ]);
    $_COOKIE['tasting_aktuell'] = $tid; // gilt sofort, schon für diese Seite
}

function zurueck(string $query = ''): void
{
    header('Location: ./' . $query);
    exit;
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Daten lesen (ohne Schreibabsicht). */
function datenLaden(): array
{
    if (!is_file(DATEN_DATEI)) {
        return ['champagner' => [], 'bewertungen' => [], 'weingueter' => [], 'tastings' => [], 'benutzer' => [], 'kontoanfragen' => [], 'profile' => []];
    }
    $roh = (string)file_get_contents(DATEN_DATEI);
    $d = json_decode($roh, true);
    return is_array($d)
        ? $d + ['champagner' => [], 'bewertungen' => [], 'weingueter' => [], 'tastings' => [], 'benutzer' => [], 'kontoanfragen' => [], 'profile' => []]
        : ['champagner' => [], 'bewertungen' => [], 'weingueter' => [], 'tastings' => [], 'benutzer' => [], 'kontoanfragen' => [], 'profile' => []];
}

/** Daten unter Sperre ändern: $fn bekommt die Daten und gibt die neuen zurück. */
function datenAendern(callable $fn): void
{
    $dir = dirname(DATEN_DATEI);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $h = fopen(DATEN_DATEI, 'c+');
    if ($h === false) {
        zurueck('?fehler=' . rawurlencode('Daten konnten nicht gespeichert werden.'));
    }
    flock($h, LOCK_EX);
    $roh = stream_get_contents($h);
    $d = json_decode((string)$roh, true);
    if (!is_array($d)) {
        $d = ['champagner' => [], 'bewertungen' => [], 'weingueter' => [], 'tastings' => []];
    }
    $d += ['champagner' => [], 'bewertungen' => [], 'weingueter' => [], 'tastings' => []];
    $d = $fn($d);
    ftruncate($h, 0);
    rewind($h);
    fwrite($h, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Überschreitet der Upload das Server-Limit, kommt der POST leer an –
    // dann eine verständliche Meldung statt "Sitzung abgelaufen".
    if ($_POST === [] && $_FILES === [] && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        zurueck('?fehler=' . rawurlencode('Die Fotos sind zusammen zu groß für einen Upload – bitte weniger Fotos auf einmal auswählen.'));
    }
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        zurueck('?fehler=' . rawurlencode('Die Sitzung ist abgelaufen – bitte nochmal versuchen.'));
    }
    $aktion = (string)($_POST['aktion'] ?? '');

    if ($aktion === 'login') {
        $weiter = (string)($_POST['weiter'] ?? '');
        if (!preg_match('/^\?[A-Za-z0-9=&_%-]*$/', $weiter)) {
            $weiter = '';
        }
        $anhang = $weiter === '' ? '?' : $weiter . '&';
        if (($_POST['agb'] ?? '') !== 'ja') {
            zurueck($anhang . 'fehler=' . rawurlencode('Bitte bestätige, dass du mindestens 18 Jahre alt bist und die Nutzungsbedingungen akzeptierst.'));
        }
        if (hash_equals(PASSWORT, (string)($_POST['passwort'] ?? ''))) {
            $_SESSION['tasting_ok'] = true;
            zurueck($anhang . 'ok=' . rawurlencode('Willkommen zur Verkostung!'));
        }
        zurueck($anhang . 'fehler=' . rawurlencode('Das Passwort stimmt nicht.'));
    }

    if ($aktion === 'logout') {
        unset($_SESSION['tasting_ok']);
        zurueck('?ok=' . rawurlencode('Abgemeldet.'));
    }

    if ($aktion === 'benutzer_login') {
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $pw = (string)($_POST['passwort'] ?? '');
        $gefunden = null;
        foreach (datenLaden()['benutzer'] as $b) {
            if (mb_strtolower((string)($b['email'] ?? '')) === $email) {
                $gefunden = $b;
                break;
            }
        }
        if ($gefunden === null || !password_verify($pw, (string)($gefunden['pw_hash'] ?? ''))) {
            zurueck('?konto=1&fehler=' . rawurlencode('E-Mail oder Passwort stimmt nicht.'));
        }
        setcookie('benutzer', (string)$gefunden['token'], [
            'expires' => time() + 60 * 60 * 24 * 3650, // läuft nicht ab
            'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
        ]);
        $_SESSION['tasting_ok'] = true;
        $_SESSION['person'] = (string)($gefunden['vorname'] ?? '');
        zurueck('?meine=1&ok=' . rawurlencode('Willkommen, ' . ($gefunden['vorname'] ?? '') . '! Du bleibst auf diesem Gerät dauerhaft angemeldet.'));
    }

    if ($aktion === 'benutzer_logout') {
        setcookie('benutzer', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
        zurueck('?konto=1&ok=' . rawurlencode('Vom Benutzerkonto abgemeldet.'));
    }

    if ($aktion === 'einladung_bestaetigen') {
        // Erster Klick auf einen Einladungslink: Nutzungsbedingungen bestätigen
        $token = (string)($_POST['token'] ?? '');
        $treffer = teilnehmerZuToken(datenLaden(), $token);
        if ($treffer === null) {
            zurueck('?fehler=' . rawurlencode('Dieser Einladungslink ist nicht (mehr) gültig.'));
        }
        if (($_POST['agb'] ?? '') !== 'ja') {
            zurueck('?einladung=' . rawurlencode($token) . '&fehler=' . rawurlencode('Bitte bestätige, dass du mindestens 18 Jahre alt bist und die Nutzungsbedingungen akzeptierst.'));
        }
        [$bestTasting, $bestTeilnehmer] = $treffer;
        $bestTid = $bestTasting['id'];
        $bestToken = (string)$bestTeilnehmer['token'];
        datenAendern(function (array $d) use ($bestTid, $bestToken): array {
            foreach ($d['tastings'] as &$t) {
                if ($t['id'] !== $bestTid) {
                    continue;
                }
                foreach ($t['teilnehmer'] as &$p) {
                    if (hash_equals((string)$p['token'], $bestToken)) {
                        $p['agb_zeit'] = time(); // wann die Nutzungsbedingungen bestätigt wurden
                    }
                }
            }
            return $d;
        });
        setcookie('einladung', $bestToken, [
            'expires' => time() + 60 * 60 * 24 * 3650,
            'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
        ]);
        tastingWaehlen($bestTid);
        $_SESSION['tasting_ok'] = true;
        $_SESSION['person'] = $bestTeilnehmer['name'];
        zurueck('?ok=' . rawurlencode('Willkommen, ' . $bestTeilnehmer['name'] . '! Du bist angemeldet für „' . $bestTasting['titel'] . '“. 🥂'));
    }

    if ($aktion === 'beitreten') {
        // Beitritt per QR-Code/Gruppenlink – braucht keine Anmeldung
        $beitritt = (string)($_POST['beitritt'] ?? '');
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 40);
        $email = mb_substr(trim((string)($_POST['email'] ?? '')), 0, 80);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = '';
        }
        $ziel = null;
        foreach (datenLaden()['tastings'] as $t) {
            if (($t['beitritt'] ?? '') !== '' && hash_equals((string)$t['beitritt'], $beitritt)) {
                $ziel = $t;
                break;
            }
        }
        if ($ziel === null) {
            zurueck('?fehler=' . rawurlencode('Dieser Beitritts-Code ist nicht (mehr) gültig.'));
        }
        if ($name === '') {
            zurueck('?beitritt=' . rawurlencode($beitritt) . '&fehler=' . rawurlencode('Bitte deinen Namen eintragen.'));
        }
        if (($_POST['agb'] ?? '') !== 'ja') {
            zurueck('?beitritt=' . rawurlencode($beitritt) . '&fehler=' . rawurlencode('Bitte bestätige, dass du mindestens 18 Jahre alt bist und die Nutzungsbedingungen akzeptierst.'));
        }
        // Gleicher Name schon dabei? Dann dessen Zugang übernehmen statt doppelt anlegen.
        $token = '';
        foreach (($ziel['teilnehmer'] ?? []) as $p) {
            if (mb_strtolower($p['name']) === mb_strtolower($name)) {
                $token = $p['token'];
                break;
            }
        }
        $tid = $ziel['id'];
        if ($token === '') {
            if (teilnehmerLimitErreicht($ziel)) {
                zurueck('?beitritt=' . rawurlencode($beitritt) . '&fehler=' . rawurlencode('Dieses Tasting ist voll (2 Teilnehmer). Für große Runden kann der Administrator den Gastgeber freischalten.'));
            }
            $token = bin2hex(random_bytes(8));
            datenAendern(function (array $d) use ($tid, $name, $email, $token): array {
                foreach ($d['tastings'] as &$t) {
                    if ($t['id'] === $tid) {
                        $t['teilnehmer'][] = ['token' => $token, 'name' => $name, 'email' => $email, 'agb_zeit' => time()];
                    }
                }
                return $d;
            });
        } else {
            // Bestehender Zugang: Bestätigung der Nutzungsbedingungen festhalten
            $tokenAlt = $token;
            datenAendern(function (array $d) use ($tid, $tokenAlt): array {
                foreach ($d['tastings'] as &$t) {
                    if ($t['id'] !== $tid) {
                        continue;
                    }
                    foreach ($t['teilnehmer'] as &$p) {
                        if (hash_equals((string)$p['token'], (string)$tokenAlt) && empty($p['agb_zeit'])) {
                            $p['agb_zeit'] = time();
                        }
                    }
                }
                return $d;
            });
        }
        setcookie('einladung', $token, [
            'expires' => time() + 60 * 60 * 24 * 3650, // läuft praktisch nie ab
            'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
        ]);
        tastingWaehlen($ziel['id']);
        $_SESSION['tasting_ok'] = true;
        $_SESSION['person'] = $name;
        zurueck('?ok=' . rawurlencode('Willkommen, ' . $name . '! Du bist dabei bei „' . $ziel['titel'] . '“. 🥂'));
    }

    if (!$eingeloggt) {
        zurueck('?fehler=' . rawurlencode('Bitte zuerst mit dem Passwort anmelden.'));
    }

    if ($aktion === 'champagner_anlegen') {
        $name = trim((string)($_POST['name'] ?? ''));
        $name = mb_substr($name, 0, 60);
        $preis = trim((string)($_POST['preis'] ?? ''));
        $preis = mb_substr($preis, 0, 20);
        $rebsorte = mb_substr(trim((string)($_POST['rebsorte'] ?? '')), 0, 80);
        $kat = (string)($_POST['kat'] ?? 'champagner');
        if (!in_array($kat, ['champagner', 'rotwein', 'weisswein', 'bier'], true)) {
            $kat = 'champagner';
        }
        $weingutId = (string)($_POST['weingut_id'] ?? '');
        if ($name === '') {
            zurueck('?fehler=' . rawurlencode('Bitte einen Namen für den Champagner angeben.'));
        }
        $neueId = bin2hex(random_bytes(4));
        $meinTid = (string)(meinTasting(datenLaden())['id'] ?? '');
        datenAendern(function (array $d) use ($name, $preis, $rebsorte, $kat, $weingutId, $neueId, $meinTid): array {
            foreach ($d['champagner'] as $c) {
                if (mb_strtolower($c['name']) === mb_strtolower($name) && (string)($c['tasting_id'] ?? '') === $meinTid) {
                    zurueck('?fehler=' . rawurlencode('Diesen Champagner gibt es schon in der Liste.'));
                }
            }
            if ($weingutId !== '' && weingutHolen($d, $weingutId) === null) {
                $weingutId = '';
            }
            $d['champagner'][] = ['id' => $neueId, 'name' => $name, 'preis' => $preis, 'rebsorte' => $rebsorte, 'weingut_id' => $weingutId, 'typ' => $kat, 'tasting_id' => $meinTid, 'zeit' => time()];
            return $d;
        });
        zurueck('?ok=' . rawurlencode('„' . $name . '“ wurde angelegt – jetzt bewerten!'));
    }

    if ($aktion === 'schnell_foto') {
        // Schritt 1 der Schnell-Erfassung: Foto sichern und Etikett erkennen
        $praefix = 'neu-' . bin2hex(random_bytes(4));
        [$hochgeladen, ] = fotoUploadVerarbeiten($BILD_TYPEN, $praefix);
        $fotoName = '';
        if ($hochgeladen > 0) {
            $treffer = glob(BILDER_DIR . '/' . $praefix . '-*') ?: [];
            $fotoName = $treffer !== [] ? basename($treffer[0]) : '';
        }
        if ($fotoName === '') {
            zurueck('?neu=1&fehler=' . rawurlencode('Das Foto kam nicht an – bitte nochmal versuchen (nur JPG/PNG, max. 25 MB).'));
        }
        $erkannt = etikettErkennen($fotoName);
        // Passen die Foto-Koordinaten zu einem gespeicherten Weingut?
        $gpsHinweis = '';
        $gpsWeingut = null;
        $gps = gpsAusFoto(BILDER_DIR . '/' . $fotoName);
        if ($gps !== null) {
            $gpsWeingut = weingutPerKoordinaten(datenLaden(), $gps[0], $gps[1]);
            if ($gpsWeingut !== null) {
                $gpsHinweis = 'Die Foto-Koordinaten entsprechen dem Weingut „' . $gpsWeingut['name'] . '“ – es ist unten vorausgewählt.';
                if ($erkannt['weingut'] === '') {
                    $erkannt['weingut'] = $gpsWeingut['name'];
                }
            }
        }
        $vk = (string)($_POST['vk'] ?? '');
        if ($vk !== 'ohne' && !preg_match('/^[a-f0-9]{8}$/', $vk)) {
            $vk = '';
        }
        $kat = (string)($_POST['kat'] ?? 'champagner');
        if (!in_array($kat, ['champagner', 'rotwein', 'weisswein', 'bier'], true)) {
            $kat = 'champagner';
        }

        // Etikett erkannt? Dann sofort loslegen – ohne weitere Rückfragen.
        if ($erkannt['name'] !== '') {
            $vkAnhang = $vk !== '' ? '&vk=' . rawurlencode($vk) : '';
            $d0 = datenLaden();
            $meinTid = (string)(meinTasting($d0)['id'] ?? '');
            // Gibt es das Getränk schon in meinem Tasting? → Foto anhängen, direkt bewerten
            foreach ($d0['champagner'] as $c) {
                similar_text(mb_strtolower($c['name']), mb_strtolower($erkannt['name']), $proz);
                if ($proz >= 85 && champagnerInTasting($c, $meinTid)) {
                    fotosUmhaengen($praefix, 'neu', $c['id']);
                    unset($_SESSION['neu']);
                    zurueck('?bewerten=' . rawurlencode($c['id']) . $vkAnhang . '&ok=' . rawurlencode('„' . $c['name'] . '“ ist schon in der Datenbank – Foto zugeordnet, direkt bewerten!'));
                }
            }
            // Weingut bestimmen: Verkosten-Kontext > GPS-Treffer > Etikett (vorhandenes oder neues)
            $weingutId = '';
            $weingutNeu = '';
            if (preg_match('/^[a-f0-9]{8}$/', $vk) && weingutHolen($d0, $vk) !== null) {
                $weingutId = $vk;
            } elseif ($gpsWeingut !== null) {
                $weingutId = $gpsWeingut['id'];
            } elseif ($erkannt['weingut'] !== '') {
                foreach ($d0['weingueter'] as $w) {
                    if (mb_strtolower($w['name']) === mb_strtolower($erkannt['weingut'])) {
                        $weingutId = $w['id'];
                        break;
                    }
                }
                if ($weingutId === '') {
                    $weingutNeu = $erkannt['weingut'];
                }
            }
            $neueId = bin2hex(random_bytes(4));
            $neueWeingutId = bin2hex(random_bytes(4));
            $nName = $erkannt['name'];
            $nRebsorte = (string)($erkannt['rebsorte'] ?? '');
            datenAendern(function (array $d) use ($nName, $nRebsorte, $kat, $weingutId, $weingutNeu, $neueId, $neueWeingutId, $meinTid): array {
                if ($weingutNeu !== '') {
                    $d['weingueter'][] = ['id' => $neueWeingutId, 'name' => $weingutNeu, 'notiz' => '', 'zeit' => time()];
                    $weingutId = $neueWeingutId;
                }
                $d['champagner'][] = ['id' => $neueId, 'name' => $nName, 'preis' => '', 'rebsorte' => $nRebsorte, 'weingut_id' => $weingutId, 'typ' => $kat, 'tasting_id' => $meinTid, 'zeit' => time()];
                if ($meinTid !== '') {
                    foreach ($d['tastings'] as &$t) {
                        if ($t['id'] === $meinTid) {
                            $t['aktiv_cid'] = $neueId;
                        }
                    }
                    unset($t);
                }
                return $d;
            });
            fotosUmhaengen($praefix, 'neu', $neueId);
            unset($_SESSION['neu']);
            zurueck('?bewerten=' . rawurlencode($neueId) . $vkAnhang . '&ok=' . rawurlencode('„' . $nName . '“ erkannt und angelegt – los geht’s! (Preis & Co. später unter „Stammdaten“ ergänzbar)'));
        }

        // Nichts erkannt: kurzes Formular zum Eintragen zeigen
        $_SESSION['neu'] = ['praefix' => $praefix, 'foto' => $fotoName, 'name' => $erkannt['name'], 'weingut' => $erkannt['weingut'], 'rebsorte' => ($erkannt['rebsorte'] ?? ''), 'vk' => $vk, 'kat' => $kat];
        zurueck('?neu=2' . ($gpsHinweis !== '' ? '&ok=' . rawurlencode($gpsHinweis) : ''));
    }

    if ($aktion === 'schnell_anlegen') {
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 60);
        $preis = mb_substr(trim((string)($_POST['preis'] ?? '')), 0, 20);
        $rebsorte = mb_substr(trim((string)($_POST['rebsorte'] ?? '')), 0, 80);
        $kat = (string)($_POST['kat'] ?? 'champagner');
        if (!in_array($kat, ['champagner', 'rotwein', 'weisswein', 'bier'], true)) {
            $kat = 'champagner';
        }
        $weingutId = (string)($_POST['weingut_id'] ?? '');
        $weingutNeu = mb_substr(trim((string)($_POST['weingut_neu'] ?? '')), 0, 60);
        $vk = (string)($_POST['vk'] ?? '');
        if ($vk !== 'ohne' && !preg_match('/^[a-f0-9]{8}$/', $vk)) {
            $vk = '';
        }
        if ($weingutId === '' && $weingutNeu === '' && preg_match('/^[a-f0-9]{8}$/', $vk)) {
            $weingutId = $vk; // Weingut aus dem Verkosten-Kontext übernehmen
        }
        if ($name === '') {
            zurueck('?neu=2&fehler=' . rawurlencode('Bitte einen Namen für den Champagner angeben.'));
        }
        $neueId = bin2hex(random_bytes(4));
        $neueWeingutId = bin2hex(random_bytes(4));
        $meinTid = (string)(meinTasting(datenLaden())['id'] ?? '');
        datenAendern(function (array $d) use ($name, $preis, $rebsorte, $kat, $weingutId, $weingutNeu, $neueId, $neueWeingutId, $meinTid): array {
            foreach ($d['champagner'] as $c) {
                if (mb_strtolower($c['name']) === mb_strtolower($name) && (string)($c['tasting_id'] ?? '') === $meinTid) {
                    zurueck('?neu=2&fehler=' . rawurlencode('Diesen Champagner gibt es schon in der Liste.'));
                }
            }
            if ($weingutNeu !== '') {
                $weingutId = '';
                foreach ($d['weingueter'] as $w) {
                    if (mb_strtolower($w['name']) === mb_strtolower($weingutNeu)) {
                        $weingutId = $w['id'];
                        break;
                    }
                }
                if ($weingutId === '') {
                    $d['weingueter'][] = ['id' => $neueWeingutId, 'name' => $weingutNeu, 'notiz' => '', 'zeit' => time()];
                    $weingutId = $neueWeingutId;
                }
            } elseif ($weingutId !== '' && weingutHolen($d, $weingutId) === null) {
                $weingutId = '';
            }
            $d['champagner'][] = ['id' => $neueId, 'name' => $name, 'preis' => $preis, 'rebsorte' => $rebsorte, 'weingut_id' => $weingutId, 'typ' => $kat, 'tasting_id' => $meinTid, 'zeit' => time()];
            return $d;
        });
        // Alle Fotos vom Zwischen-Präfix auf den neuen Champagner umhängen
        fotosUmhaengen((string)($_SESSION['neu']['praefix'] ?? ''), 'neu', $neueId);
        unset($_SESSION['neu']);
        // Für die eigene Tasting-Gruppe automatisch „ins Glas“ stellen
        if ($meinTid !== '') {
            datenAendern(function (array $d) use ($meinTid, $neueId): array {
                foreach ($d['tastings'] as &$t) {
                    if ($t['id'] === $meinTid) {
                        $t['aktiv_cid'] = $neueId;
                    }
                }
                return $d;
            });
        }
        zurueck('?bewerten=' . rawurlencode($neueId) . ($vk !== '' ? '&vk=' . rawurlencode($vk) : '') . '&ok=' . rawurlencode('„' . $name . '“ ist angelegt – jetzt direkt bewerten!'));
    }

    if ($aktion === 'erkennen_bestehend') {
        $cid = (string)($_POST['champagner_id'] ?? '');
        if (champagnerHolen(datenLaden(), $cid) === null) {
            zurueck('?fehler=' . rawurlencode('Dieser Champagner existiert nicht (mehr).'));
        }
        $fotos = fotosFuer($cid);
        if ($fotos === []) {
            zurueck('?ergebnis=' . rawurlencode($cid) . '&fehler=' . rawurlencode('Kein Foto vorhanden – bitte zuerst ein Etikett-Foto hochladen.'));
        }
        $gewaehlt = basename((string)($_POST['foto'] ?? ''));
        $fotoName = in_array($gewaehlt, $fotos, true) ? $gewaehlt : $fotos[0];
        $erkannt = etikettErkennen($fotoName);
        if ($erkannt['name'] === '' && $erkannt['weingut'] === '') {
            zurueck('?ergebnis=' . rawurlencode($cid) . '&fehler=' . rawurlencode('Auf dem Foto war kein Etikett zu erkennen – am besten ein neues Foto direkt vom Etikett hochladen und nochmal versuchen.'));
        }
        $_SESSION['erkannt'] = ['cid' => $cid, 'name' => $erkannt['name'], 'weingut' => $erkannt['weingut'], 'rebsorte' => ($erkannt['rebsorte'] ?? '')];
        zurueck('?ergebnis=' . rawurlencode($cid) . '&ok=' . rawurlencode('Etikett erkannt – die Vorschläge stehen unten in den Bearbeiten-Feldern. Prüfen und speichern!'));
    }

    if ($aktion === 'titelbild_setzen') {
        $typ = (string)($_POST['typ'] ?? '');
        $id = (string)($_POST['id'] ?? '');
        $datei = basename((string)($_POST['datei'] ?? ''));
        [$weiter, $vorhandene, $liste] = match ($typ) {
            'weingut' => ['?weingut=' . rawurlencode($id), weingutFotos($id), 'weingueter'],
            'tasting' => ['?tasting=' . rawurlencode($id), tastingFotos($id), 'tastings'],
            default   => ['?ergebnis=' . rawurlencode($id), fotosFuer($id), 'champagner'],
        };
        if (!in_array($datei, $vorhandene, true)) {
            zurueck($weiter . '&fehler=' . rawurlencode('Dieses Foto wurde nicht gefunden.'));
        }
        datenAendern(function (array $d) use ($liste, $id, $datei): array {
            foreach ($d[$liste] as &$eintrag) {
                if ($eintrag['id'] === $id) {
                    $eintrag['titelbild'] = $datei;
                }
            }
            unset($eintrag);
            return $d;
        });
        zurueck($weiter . '&ok=' . rawurlencode('Titelbild gesetzt – dieses Foto erscheint jetzt überall als Vorschau. ⭐'));
    }

    if ($aktion === 'champagner_bearbeiten') {
        $cid = (string)($_POST['champagner_id'] ?? '');
        $name = trim((string)($_POST['name'] ?? ''));
        $name = mb_substr($name, 0, 60);
        $preis = trim((string)($_POST['preis'] ?? ''));
        $preis = mb_substr($preis, 0, 20);
        $rebsorte = mb_substr(trim((string)($_POST['rebsorte'] ?? '')), 0, 80);
        $kat = (string)($_POST['kat'] ?? '');
        if (!in_array($kat, ['champagner', 'rotwein', 'weisswein', 'bier'], true)) {
            $kat = '';
        }
        $neuesTasting = null; // null = Feld nicht mitgeschickt, '' = „ohne Tasting“
        if (array_key_exists('tasting_id', $_POST)) {
            $neuesTasting = (string)$_POST['tasting_id'];
            if ($neuesTasting !== '') {
                $gefunden = false;
                foreach (datenLaden()['tastings'] as $t) {
                    if ($t['id'] === $neuesTasting) {
                        $gefunden = true;
                        break;
                    }
                }
                if (!$gefunden) {
                    $neuesTasting = null;
                }
            }
        }
        if ($name === '') {
            zurueck('?ergebnis=' . rawurlencode($cid) . '&fehler=' . rawurlencode('Der Name darf nicht leer sein.'));
        }
        datenAendern(function (array $d) use ($cid, $name, $preis, $rebsorte, $kat, $neuesTasting): array {
            $eigenesTasting = (string)(champagnerHolen($d, $cid)['tasting_id'] ?? '');
            foreach ($d['champagner'] as $c) {
                if ($c['id'] !== $cid && mb_strtolower($c['name']) === mb_strtolower($name)
                    && (string)($c['tasting_id'] ?? '') === $eigenesTasting) {
                    zurueck('?ergebnis=' . rawurlencode($cid) . '&fehler=' . rawurlencode('Ein anderes Getränk in diesem Tasting heißt schon so.'));
                }
            }
            foreach ($d['champagner'] as &$c) {
                if ($c['id'] === $cid) {
                    $c['name']     = $name;
                    $c['preis']    = $preis;
                    $c['rebsorte'] = $rebsorte;
                    if ($kat !== '') {
                        $c['typ'] = $kat;
                    }
                    if ($neuesTasting !== null) {
                        $c['tasting_id'] = $neuesTasting;
                    }
                }
            }
            return $d;
        });
        unset($_SESSION['erkannt']);
        zurueck('?ergebnis=' . rawurlencode($cid) . '&ok=' . rawurlencode('Stammdaten gespeichert.'));
    }

    if ($aktion === 'foto_upload') {
        $cid = (string)($_POST['champagner_id'] ?? '');
        $champagner = champagnerHolen(datenLaden(), $cid);
        if ($champagner === null) {
            zurueck('?fehler=' . rawurlencode('Dieser Champagner existiert nicht (mehr).'));
        }
        [$hochgeladen, $abgelehnt] = fotoUploadVerarbeiten($BILD_TYPEN, $cid);
        // Foto am Weingut aufgenommen? Dann bei fehlender Zuordnung automatisch zuordnen
        $extra = '';
        if ($hochgeladen > 0 && trim((string)($champagner['weingut_id'] ?? '')) === '') {
            foreach (array_slice(fotosFuer($cid), 0, $hochgeladen) as $foto) {
                $gps = gpsAusFoto(BILDER_DIR . '/' . $foto);
                if ($gps === null) {
                    continue;
                }
                $passendes = weingutPerKoordinaten(datenLaden(), $gps[0], $gps[1]);
                if ($passendes !== null) {
                    $wid = $passendes['id'];
                    datenAendern(function (array $d) use ($cid, $wid): array {
                        foreach ($d['champagner'] as &$c) {
                            if ($c['id'] === $cid) {
                                $c['weingut_id'] = $wid;
                            }
                        }
                        return $d;
                    });
                    $extra = ' Die Foto-Koordinaten entsprechen dem Weingut „' . $passendes['name'] . '“ – es wurde automatisch zugeordnet.';
                    break;
                }
            }
        }
        zurueck('?ergebnis=' . rawurlencode($cid) . '&ok=' . rawurlencode(uploadText($hochgeladen, $abgelehnt) . $extra));
    }

    if ($aktion === 'foto_loeschen') {
        $name = basename((string)($_POST['datei'] ?? ''));
        $pfad = BILDER_DIR . '/' . $name;
        if (preg_match('/^([a-f0-9]{8})-.*\.(jpe?g|png|gif|webp)$/i', $name, $m) && is_file($pfad)) {
            unlink($pfad);
            thumbLoeschen($name);
            zurueck('?ergebnis=' . rawurlencode($m[1]) . '&ok=' . rawurlencode('Foto gelöscht.'));
        }
        zurueck('?fehler=' . rawurlencode('Foto nicht gefunden.'));
    }

    if ($aktion === 'weingut_anlegen') {
        $name = trim((string)($_POST['name'] ?? ''));
        $name = mb_substr($name, 0, 60);
        $notiz = trim((string)($_POST['notiz'] ?? ''));
        $notiz = mb_substr($notiz, 0, 1000);
        if ($name === '') {
            zurueck('?weingueter=1&fehler=' . rawurlencode('Bitte einen Namen für das Weingut angeben.'));
        }
        $neueId = bin2hex(random_bytes(4));
        datenAendern(function (array $d) use ($name, $notiz, $neueId): array {
            foreach ($d['weingueter'] as $w) {
                if (mb_strtolower($w['name']) === mb_strtolower($name)) {
                    zurueck('?weingueter=1&fehler=' . rawurlencode('Dieses Weingut gibt es schon in der Liste.'));
                }
            }
            $d['weingueter'][] = ['id' => $neueId, 'name' => $name, 'notiz' => $notiz, 'zeit' => time()];
            return $d;
        });
        zurueck('?weingut=' . rawurlencode($neueId) . '&ok=' . rawurlencode('„' . $name . '“ wurde angelegt.'));
    }

    if ($aktion === 'weingut_notiz') {
        $id = (string)($_POST['id'] ?? '');
        $notiz = trim((string)($_POST['notiz'] ?? ''));
        $notiz = mb_substr($notiz, 0, 1000);
        $name = trim((string)($_POST['name'] ?? ''));
        $name = mb_substr($name, 0, 60);
        $kontakt = mb_substr(trim((string)($_POST['kontakt'] ?? '')), 0, 900);
        if ($name === '') {
            zurueck('?weingut=' . rawurlencode($id) . '&fehler=' . rawurlencode('Der Name darf nicht leer sein.'));
        }
        datenAendern(function (array $d) use ($id, $name, $notiz, $kontakt): array {
            foreach ($d['weingueter'] as $w) {
                if ($w['id'] !== $id && mb_strtolower($w['name']) === mb_strtolower($name)) {
                    zurueck('?weingut=' . rawurlencode($id) . '&fehler=' . rawurlencode('Ein anderes Weingut heißt schon so.'));
                }
            }
            foreach ($d['weingueter'] as &$w) {
                if ($w['id'] === $id) {
                    $w['name']    = $name;
                    $w['notiz']   = $notiz;
                    $w['kontakt'] = $kontakt;
                }
            }
            return $d;
        });
        zurueck('?weingut=' . rawurlencode($id) . '&ok=' . rawurlencode('Gespeichert.'));
    }

    if ($aktion === 'weingut_loeschen') {
        $id = (string)($_POST['id'] ?? '');
        datenAendern(function (array $d) use ($id): array {
            $d['weingueter'] = array_values(array_filter($d['weingueter'], fn($w) => $w['id'] !== $id));
            foreach ($d['champagner'] as &$c) {
                if (($c['weingut_id'] ?? '') === $id) {
                    $c['weingut_id'] = '';
                }
            }
            return $d;
        });
        if (preg_match('/^[a-f0-9]{8}$/', $id)) {
            foreach (glob(BILDER_DIR . '/wg-' . $id . '-*') ?: [] as $foto) {
                @unlink($foto);
                thumbLoeschen(basename($foto));
            }
        }
        zurueck('?weingueter=1&ok=' . rawurlencode('Weingut gelöscht (Champagner bleiben erhalten).'));
    }

    if ($aktion === 'weingut_foto_upload') {
        $id = (string)($_POST['weingut_id'] ?? '');
        if (weingutHolen(datenLaden(), $id) === null) {
            zurueck('?weingueter=1&fehler=' . rawurlencode('Dieses Weingut existiert nicht (mehr).'));
        }
        [$hochgeladen, $abgelehnt] = fotoUploadVerarbeiten($BILD_TYPEN, 'wg-' . $id);
        // GPS aus den neuen Fotos lesen und daraus den Standort ermitteln
        $extra = '';
        if ($hochgeladen > 0) {
            $gps = null;
            foreach (array_slice(weingutFotos($id), 0, $hochgeladen) as $foto) {
                $gps = gpsAusFoto(BILDER_DIR . '/' . $foto);
                if ($gps !== null) {
                    break;
                }
            }
            if ($gps !== null) {
                $bisherigerKontakt = trim((string)(weingutHolen(datenLaden(), $id)['kontakt'] ?? ''));
                if (!str_contains($bisherigerKontakt, 'Standort (aus Foto-GPS)')) {
                    $standort = standortErmitteln($gps[0], $gps[1]);
                    if ($standort !== '') {
                        $zeile = 'Standort (aus Foto-GPS): ' . $standort . "\n"
                            . 'Karte: https://www.openstreetmap.org/?mlat=' . round($gps[0], 6) . '&mlon=' . round($gps[1], 6) . '#map=17/' . round($gps[0], 6) . '/' . round($gps[1], 6);
                        datenAendern(function (array $d) use ($id, $zeile): array {
                            foreach ($d['weingueter'] as &$w) {
                                if ($w['id'] === $id) {
                                    $alt = trim((string)($w['kontakt'] ?? ''));
                                    $w['kontakt'] = mb_substr(trim($alt . ($alt === '' ? '' : "\n") . $zeile), 0, 900);
                                }
                            }
                            return $d;
                        });
                        $extra = ' Standort aus dem Foto erkannt und im Kontakt-Bereich ergänzt.';
                    }
                }
            }
        }
        zurueck('?weingut=' . rawurlencode($id) . '&ok=' . rawurlencode(uploadText($hochgeladen, $abgelehnt) . $extra));
    }

    if ($aktion === 'weingut_foto_loeschen') {
        $name = basename((string)($_POST['datei'] ?? ''));
        $pfad = BILDER_DIR . '/' . $name;
        if (preg_match('/^wg-([a-f0-9]{8})-.*\.(jpe?g|png|gif|webp)$/i', $name, $m) && is_file($pfad)) {
            unlink($pfad);
            thumbLoeschen($name);
            zurueck('?weingut=' . rawurlencode($m[1]) . '&ok=' . rawurlencode('Foto gelöscht.'));
        }
        zurueck('?fehler=' . rawurlencode('Foto nicht gefunden.'));
    }

    if ($aktion === 'bewertung_loeschen') {
        $cid = (string)($_POST['champagner_id'] ?? '');
        $person = trim((string)($_POST['person'] ?? ''));
        datenAendern(function (array $d) use ($cid, $person): array {
            $d['bewertungen'] = array_values(array_filter(
                $d['bewertungen'],
                fn($b) => !($b['champagner_id'] === $cid && mb_strtolower($b['person']) === mb_strtolower($person))
            ));
            return $d;
        });
        zurueck('?ergebnis=' . rawurlencode($cid) . '&ok=' . rawurlencode('Bewertung von ' . $person . ' gelöscht.'));
    }

    if ($aktion === 'profil_foto_upload') {
        // Persönliches Profilfoto (Kamera oder Galerie) – gehört zum Namen der Person
        $ich = mb_strtolower(trim((string)($_SESSION['person'] ?? '')));
        if ($ich === '') {
            zurueck('?meine=1&fehler=' . rawurlencode('Wir kennen deinen Namen noch nicht – tritt erst einem Tasting bei oder bewerte einmal.'));
        }
        [$hochgeladen, $abgelehnt] = fotoUploadVerarbeiten($BILD_TYPEN, 'pf');
        if ($hochgeladen === 0) {
            zurueck('?meine=1&fehler=' . rawurlencode('Das Foto kam nicht an – bitte nochmal versuchen (nur Bilder, max. 25 MB).'));
        }
        $neustes = glob(BILDER_DIR . '/pf-*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE) ?: [];
        usort($neustes, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $neuDatei = basename($neustes[0] ?? '');
        datenAendern(function (array $d) use ($ich, $neuDatei): array {
            $alt = (string)($d['profile'][$ich] ?? '');
            if ($alt !== '' && $alt !== $neuDatei && is_file(BILDER_DIR . '/' . $alt)) {
                @unlink(BILDER_DIR . '/' . $alt);
                thumbLoeschen($alt);
            }
            $d['profile'][$ich] = $neuDatei;
            return $d;
        });
        zurueck('?meine=1&ok=' . rawurlencode('Dein Profilfoto ist gesetzt – schön, dich zu sehen! 👋'));
    }

    if ($aktion === 'tasting_foto_upload') {
        $tid = (string)($_POST['tasting_id'] ?? '');
        $ziel = null;
        foreach (datenLaden()['tastings'] as $t) {
            if ($t['id'] === $tid) { $ziel = $t; break; }
        }
        if ($ziel === null) {
            zurueck('?tasting=1&fehler=' . rawurlencode('Dieses Tasting existiert nicht (mehr).'));
        }
        [$hochgeladen, $abgelehnt] = fotoUploadVerarbeiten($BILD_TYPEN, 'ts-' . $tid);
        $doppelt = 0;
        if ($hochgeladen > 0) {
            // Doppelte sofort wieder aussortieren – identische Fotos landen
            // nicht zweimal im Album
            $doppelt = doppelteAussortieren(array_slice(tastingFotos($tid), 0, $hochgeladen));
        }
        $text = uploadText($hochgeladen - $doppelt, $abgelehnt)
            . ($doppelt > 0 ? ' ' . $doppelt . ' doppelte(s) Foto(s) automatisch aussortiert.' : '');
        zurueck('?tasting=' . rawurlencode($tid) . '&ok=' . rawurlencode($text));
    }

    if ($aktion === 'tasting_foto_loeschen') {
        $name = basename((string)($_POST['datei'] ?? ''));
        $pfad = BILDER_DIR . '/' . $name;
        if (preg_match('/^ts-([a-f0-9]{8})-.*\.(jpe?g|png|gif|webp)$/i', $name, $m) && is_file($pfad)) {
            unlink($pfad);
            thumbLoeschen($name);
            zurueck('?tasting=' . rawurlencode($m[1]) . '&ok=' . rawurlencode('Foto gelöscht.'));
        }
        zurueck('?tasting=1&fehler=' . rawurlencode('Foto nicht gefunden.'));
    }

    if ($aktion === 'foto_analysieren') {
        // Ein nicht zugeordnetes Album-Foto per Etikett + GPS auswerten
        $datei = basename((string)($_POST['datei'] ?? ''));
        if (!preg_match('/^div-.*\.(jpe?g|png|gif|webp)$/i', $datei) || !is_file(BILDER_DIR . '/' . $datei)) {
            zurueck('?fotos=1&fehler=' . rawurlencode('Foto nicht gefunden.'));
        }
        $vorschlagC = '';   // Champagner-ID
        $vorschlagW = '';   // Weingut-ID
        $hinweise = [];
        // Etikett lesen und mit vorhandenen Champagnern abgleichen
        $erkannt = etikettErkennen($datei);
        if ($erkannt['name'] !== '') {
            $best = null; $bestScore = 0;
            foreach (datenLaden()['champagner'] as $c) {
                similar_text(mb_strtolower($c['name']), mb_strtolower($erkannt['name']), $proz);
                if ($proz > $bestScore) { $bestScore = $proz; $best = $c; }
            }
            if ($best !== null && $bestScore >= 55) {
                $vorschlagC = $best['id'];
                $hinweise[] = '🍾 Etikett „' . $erkannt['name'] . '“ → ' . $best['name'];
            } elseif ($erkannt['name'] !== '') {
                $hinweise[] = '🍾 Etikett gelesen: „' . $erkannt['name'] . '“ (kein passender Champagner in der Liste)';
            }
        }
        // GPS mit vorhandenen Weingütern abgleichen
        $gps = gpsAusFoto(BILDER_DIR . '/' . $datei);
        if ($gps !== null) {
            $wg = weingutPerKoordinaten(datenLaden(), $gps[0], $gps[1]);
            if ($wg !== null) {
                $vorschlagW = $wg['id'];
                $hinweise[] = '🍇 Standort → ' . $wg['name'];
            }
        }
        $_SESSION['foto_vorschlag'] = ['datei' => $datei, 'champagner' => $vorschlagC, 'weingut' => $vorschlagW, 'text' => implode(' · ', $hinweise)];
        if ($vorschlagC === '' && $vorschlagW === '') {
            zurueck('?fotos=1&fehler=' . rawurlencode('Konnte nichts Passendes erkennen – bitte von Hand am Champagner/Weingut hochladen.'));
        }
        zurueck('?fotos=1&ok=' . rawurlencode('Vorschlag: ' . implode(' · ', $hinweise) . ' – unten übernehmen.'));
    }

    if ($aktion === 'foto_uebernehmen') {
        $datei = basename((string)($_POST['datei'] ?? ''));
        $zielC = (string)($_POST['champagner_id'] ?? '');
        $zielW = (string)($_POST['weingut_id'] ?? '');
        if (!preg_match('/^div-.*\.(jpe?g|png|gif|webp)$/i', $datei) || !is_file(BILDER_DIR . '/' . $datei)) {
            zurueck('?fotos=1&fehler=' . rawurlencode('Foto nicht gefunden.'));
        }
        $endung = strtolower(pathinfo($datei, PATHINFO_EXTENSION));
        $neuName = '';
        if (preg_match('/^[a-f0-9]{8}$/', $zielC) && champagnerHolen(datenLaden(), $zielC) !== null) {
            $neuName = sprintf('%s-%s-%s.%s', $zielC, date('Ymd-His'), bin2hex(random_bytes(3)), $endung);
        } elseif (preg_match('/^[a-f0-9]{8}$/', $zielW) && weingutHolen(datenLaden(), $zielW) !== null) {
            $neuName = sprintf('wg-%s-%s-%s.%s', $zielW, date('Ymd-His'), bin2hex(random_bytes(3)), $endung);
        }
        if ($neuName === '') {
            zurueck('?fotos=1&fehler=' . rawurlencode('Kein gültiges Ziel gewählt.'));
        }
        if (rename(BILDER_DIR . '/' . $datei, BILDER_DIR . '/' . $neuName)) {
            thumbLoeschen($datei);
            thumbErzeugen(BILDER_DIR . '/' . $neuName, thumbVerzeichnis() . '/' . $neuName . '.jpg');
            bildEintraegeUmbenennen([$datei => $neuName]);
        }
        unset($_SESSION['foto_vorschlag']);
        zurueck('?fotos=1&ok=' . rawurlencode('Foto zugeordnet.'));
    }

    if ($aktion === 'doppelte_entfernen') {
        // Bild-Duplikate anhand des Inhalts (MD5) finden; jeweils das erste behalten
        $alle = glob(BILDER_DIR . '/*.{jpg,jpeg,png,gif,webp,JPG,JPEG,PNG,GIF,WEBP}', GLOB_BRACE) ?: [];
        $gesehen = [];
        $geloescht = 0;
        foreach ($alle as $pfad) {
            $basis = basename($pfad);
            if (str_starts_with($basis, 'neu-') || str_starts_with($basis, 'wneu-')) {
                continue; // Zwischendateien nicht anfassen
            }
            $hash = md5_file($pfad);
            if ($hash === false) { continue; }
            if (isset($gesehen[$hash])) {
                if (unlink($pfad)) {
                    thumbLoeschen($basis);
                    $geloescht++;
                }
            } else {
                $gesehen[$hash] = $basis;
            }
        }
        zurueck('?fotos=1&ok=' . rawurlencode($geloescht . ' doppelte Foto(s) entfernt.'));
    }

    if ($aktion === 'div_foto_upload') {
        [$hochgeladen, $abgelehnt] = fotoUploadVerarbeiten($BILD_TYPEN, 'div');
        // Sofort analysieren: Doppelte aussortieren, den Rest automatisch
        // per Etikett (→ Getränk) bzw. GPS (→ Weingut) zuordnen.
        $doppelt = 0;
        $autoZugeordnet = [];
        $offenNeu = 0;
        if ($hochgeladen > 0) {
            @set_time_limit(300);
            $neueDateien = array_slice(divFotos(), 0, $hochgeladen); // die gerade hochgeladenen (neueste zuerst)
            $neueSet = array_flip($neueDateien);
            $bekannt = [];
            foreach (glob(BILDER_DIR . '/*.{jpg,jpeg,png,gif,webp,JPG,JPEG,PNG,GIF,WEBP}', GLOB_BRACE) ?: [] as $pfad) {
                $basis = basename($pfad);
                if (isset($neueSet[$basis]) || str_starts_with($basis, 'neu-') || str_starts_with($basis, 'wneu-')) {
                    continue;
                }
                $h = md5_file($pfad);
                if ($h !== false) {
                    $bekannt[$h] = true;
                }
            }
            $d0 = datenLaden();
            foreach ($neueDateien as $datei) {
                $pfad = BILDER_DIR . '/' . $datei;
                if (!is_file($pfad)) {
                    continue;
                }
                $h = md5_file($pfad);
                if ($h !== false && isset($bekannt[$h])) {
                    unlink($pfad);
                    thumbLoeschen($datei);
                    $doppelt++;
                    continue;
                }
                if ($h !== false) {
                    $bekannt[$h] = true;
                }
                $zielC = '';
                $zielW = '';
                $zielName = '';
                $erkannt = etikettErkennen($datei);
                if ($erkannt['name'] !== '') {
                    $best = null;
                    $bestScore = 0;
                    foreach ($d0['champagner'] as $c) {
                        similar_text(mb_strtolower($c['name']), mb_strtolower($erkannt['name']), $proz);
                        if ($proz > $bestScore) {
                            $bestScore = $proz;
                            $best = $c;
                        }
                    }
                    if ($best !== null && $bestScore >= 55) {
                        $zielC = $best['id'];
                        $zielName = '🍾 ' . $best['name'];
                    }
                }
                if ($zielC === '') {
                    $gps = gpsAusFoto($pfad);
                    if ($gps !== null) {
                        $wg = weingutPerKoordinaten($d0, $gps[0], $gps[1]);
                        if ($wg !== null) {
                            $zielW = $wg['id'];
                            $zielName = '🍇 ' . $wg['name'];
                        }
                    }
                }
                if ($zielC === '' && $zielW === '') {
                    $offenNeu++;
                    continue;
                }
                $endung = strtolower(pathinfo($datei, PATHINFO_EXTENSION));
                $neuName = $zielC !== ''
                    ? sprintf('%s-%s-%s.%s', $zielC, date('Ymd-His'), bin2hex(random_bytes(3)), $endung)
                    : sprintf('wg-%s-%s-%s.%s', $zielW, date('Ymd-His'), bin2hex(random_bytes(3)), $endung);
                if (rename($pfad, BILDER_DIR . '/' . $neuName)) {
                    thumbLoeschen($datei);
                    thumbErzeugen(BILDER_DIR . '/' . $neuName, thumbVerzeichnis() . '/' . $neuName . '.jpg');
                    bildEintraegeUmbenennen([$datei => $neuName]);
                    $autoZugeordnet[] = $zielName;
                } else {
                    $offenNeu++;
                }
            }
        }
        $teile = [];
        if ($autoZugeordnet !== []) {
            $teile[] = count($autoZugeordnet) . ' automatisch zugeordnet (' . implode(', ', array_slice($autoZugeordnet, 0, 4)) . (count($autoZugeordnet) > 4 ? ', …' : '') . ')';
        }
        if ($doppelt > 0) {
            $teile[] = $doppelt . ' schon vorhanden (aussortiert)';
        }
        if ($offenNeu > 0) {
            $teile[] = $offenNeu . ' nicht erkannt – unten von Hand zuordnen';
        }
        if ($abgelehnt > 0) {
            $teile[] = $abgelehnt . ' Datei(en) übersprungen';
        }
        zurueck('?fotos=1&ok=' . rawurlencode($hochgeladen . ' Foto(s) geprüft: ' . ($teile === [] ? 'nichts zu tun.' : implode(' · ', $teile))));
    }

    if ($aktion === 'div_foto_loeschen') {
        $name = basename((string)($_POST['datei'] ?? ''));
        $pfad = BILDER_DIR . '/' . $name;
        if (preg_match('/^div-.*\.(jpe?g|png|gif|webp)$/i', $name) && is_file($pfad)) {
            unlink($pfad);
            thumbLoeschen($name);
            zurueck('?fotos=1&ok=' . rawurlencode('Foto gelöscht.'));
        }
        zurueck('?fotos=1&fehler=' . rawurlencode('Foto nicht gefunden.'));
    }

    if ($aktion === 'champagner_weingut') {
        $cid = (string)($_POST['champagner_id'] ?? '');
        $wid = (string)($_POST['weingut_id'] ?? '');
        $weingutNeu = mb_substr(trim((string)($_POST['weingut_neu'] ?? '')), 0, 60);
        $neueWeingutId = bin2hex(random_bytes(4));
        datenAendern(function (array $d) use ($cid, &$wid, $weingutNeu, $neueWeingutId): array {
            if ($weingutNeu !== '') {
                $wid = '';
                foreach ($d['weingueter'] as $w) {
                    if (mb_strtolower($w['name']) === mb_strtolower($weingutNeu)) {
                        $wid = $w['id'];
                        break;
                    }
                }
                if ($wid === '') {
                    $d['weingueter'][] = ['id' => $neueWeingutId, 'name' => $weingutNeu, 'notiz' => '', 'zeit' => time()];
                    $wid = $neueWeingutId;
                }
            }
            if ($wid !== '') {
                $gefunden = false;
                foreach ($d['weingueter'] as $w) {
                    if ($w['id'] === $wid) {
                        $gefunden = true;
                        break;
                    }
                }
                if (!$gefunden) {
                    zurueck('?fehler=' . rawurlencode('Dieses Weingut existiert nicht (mehr).'));
                }
            }
            foreach ($d['champagner'] as &$c) {
                if ($c['id'] === $cid) {
                    $c['weingut_id'] = $wid;
                }
            }
            return $d;
        });
        unset($_SESSION['erkannt']);
        zurueck('?ergebnis=' . rawurlencode($cid) . '&ok=' . rawurlencode($wid === '' ? 'Zuordnung entfernt.' : 'Weingut zugeordnet.'));
    }

    if ($aktion === 'weingut_foto_neu') {
        // Schritt 1: Foto sichern, GPS auslesen, Standort und Erzeuger ermitteln
        $praefix = 'wneu-' . bin2hex(random_bytes(4));
        [$hochgeladen, ] = fotoUploadVerarbeiten($BILD_TYPEN, $praefix);
        $fotoName = '';
        if ($hochgeladen > 0) {
            $treffer = glob(BILDER_DIR . '/' . $praefix . '-*') ?: [];
            $fotoName = $treffer !== [] ? basename($treffer[0]) : '';
        }
        if ($fotoName === '') {
            zurueck('?wneu=1&fehler=' . rawurlencode('Das Foto kam nicht an – bitte nochmal versuchen (nur JPG/PNG, max. 25 MB).'));
        }
        $name = '';
        $kontakt = '';
        $hinweis = '';
        $alternativen = [];
        $gps = gpsAusFoto(BILDER_DIR . '/' . $fotoName);
        if ($gps !== null) {
            $standort = standortErmitteln($gps[0], $gps[1]);
            if ($standort !== '') {
                $kontakt = 'Standort (aus Foto-GPS): ' . $standort . "\n"
                    . 'Karte: https://www.openstreetmap.org/?mlat=' . round($gps[0], 6) . '&mlon=' . round($gps[1], 6) . '#map=17/' . round($gps[0], 6) . '/' . round($gps[1], 6);
                $kandidaten = weingutKandidatenOSM($gps[0], $gps[1]);
                if ($kandidaten !== [] && $kandidaten[0]['dist'] <= 60) {
                    // Man steht praktisch davor – der OSM-Eintrag ist es
                    $name = mb_substr($kandidaten[0]['name'], 0, 60);
                } else {
                    $name = weingutNameErmitteln($standort, $gps[0], $gps[1], $kandidaten);
                    if ($name === '' && $kandidaten !== []) {
                        // Lieber der nächstgelegene Kandidat als gar kein Vorschlag
                        $name = mb_substr($kandidaten[0]['name'], 0, 60);
                    }
                }
                // Bis zu 3 Alternativen aus der Umgebung zum Antippen anbieten
                foreach ($kandidaten as $k) {
                    if (mb_strtolower($k['name']) !== mb_strtolower($name) && count($alternativen) < 3) {
                        $alternativen[] = mb_substr($k['name'], 0, 60);
                    }
                }
            }
        } else {
            $hinweis = 'Im Foto stecken keine GPS-Daten (beim iPhone: im Auswahldialog „Optionen“ → „Standort“ einschalten). Du kannst den Namen unten von Hand eintragen.';
        }
        $vkmodus = (string)($_POST['vkmodus'] ?? '') === '1';
        // Kennen wir dieses Weingut schon? (per GPS-Koordinaten oder per Name)
        $bekanntesId = '';
        if ($gps !== null) {
            $treffer = weingutPerKoordinaten(datenLaden(), $gps[0], $gps[1]);
            if ($treffer !== null) {
                $bekanntesId = $treffer['id'];
            }
        }
        if ($bekanntesId === '' && $name !== '') {
            foreach (datenLaden()['weingueter'] as $w) {
                if (mb_strtolower($w['name']) === mb_strtolower($name)) {
                    $bekanntesId = $w['id'];
                    break;
                }
            }
        }
        $_SESSION['wneu'] = ['praefix' => $praefix, 'foto' => $fotoName, 'name' => $name, 'kontakt' => $kontakt, 'alternativen' => $alternativen, 'vkmodus' => $vkmodus, 'bekannt' => $bekanntesId];
        zurueck('?wneu=2' . ($hinweis !== '' ? '&fehler=' . rawurlencode($hinweis) : ''));
    }

    if ($aktion === 'weingut_foto_anlegen') {
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 60);
        $notiz = mb_substr(trim((string)($_POST['notiz'] ?? '')), 0, 1000);
        $kontakt = mb_substr(trim((string)($_POST['kontakt'] ?? '')), 0, 900);
        $foto = basename((string)($_POST['foto'] ?? ''));
        if ($name === '') {
            zurueck('?wneu=2&fehler=' . rawurlencode('Bitte einen Namen für das Weingut angeben.'));
        }
        $neueId = bin2hex(random_bytes(4));
        datenAendern(function (array $d) use ($name, $notiz, $kontakt, $neueId): array {
            foreach ($d['weingueter'] as $w) {
                if (mb_strtolower($w['name']) === mb_strtolower($name)) {
                    zurueck('?wneu=2&fehler=' . rawurlencode('Dieses Weingut gibt es schon in der Liste.'));
                }
            }
            $d['weingueter'][] = ['id' => $neueId, 'name' => $name, 'notiz' => $notiz, 'kontakt' => $kontakt, 'zeit' => time()];
            return $d;
        });
        // Alle Fotos vom Zwischen-Präfix auf das neue Weingut umhängen
        fotosUmhaengen((string)($_SESSION['wneu']['praefix'] ?? ''), 'wneu', 'wg-' . $neueId);
        $vkmodus = (bool)($_SESSION['wneu']['vkmodus'] ?? false);
        unset($_SESSION['wneu']);
        if ($vkmodus) {
            // Verkosten-Modus: direkt auf die Arbeitsfläche des Weinguts
            zurueck('?vk=' . rawurlencode($neueId) . '&ok=' . rawurlencode('„' . $name . '“ ist angelegt – jetzt den ersten Wein verkosten!'));
        }
        zurueck('?weingut=' . rawurlencode($neueId) . '&ok=' . rawurlencode('„' . $name . '“ ist angelegt.'));
    }

    if ($aktion === 'champagner_recherche') {
        $cid = (string)($_POST['champagner_id'] ?? '');
        $c = champagnerHolen(datenLaden(), $cid);
        if ($c === null) {
            zurueck('?fehler=' . rawurlencode('Dieser Champagner existiert nicht (mehr).'));
        }
        $wg = weingutHolen(datenLaden(), (string)($c['weingut_id'] ?? ''));
        $auftrag = 'Recherchiere im Web den Champagner "' . $c['name'] . '"'
            . ($wg !== null ? ' vom Erzeuger "' . $wg['name'] . '"' : '')
            . '. Fasse kurz auf Deutsch zusammen, als Stichpunkte mit Zeilenumbrüchen, insgesamt höchstens ~120 Wörter: '
            . 'Stil und Rebsorten, Dosage, Besonderheiten der Herstellung, Bewertungen/Auszeichnungen (falls findbar), üblicher Preis. '
            . 'Keine Einleitung, keine Erklärungen, keine Quellenangaben.';
        $text = claudeWebsuche($auftrag);
        if ($text === '') {
            zurueck('?ergebnis=' . rawurlencode($cid) . '&fehler=' . rawurlencode('Die Recherche hat nichts Verwertbares ergeben – ggf. Name prüfen und nochmal versuchen.'));
        }
        datenAendern(function (array $d) use ($cid, $text): array {
            foreach ($d['champagner'] as &$c2) {
                if ($c2['id'] === $cid) {
                    $c2['recherche'] = $text;
                }
            }
            return $d;
        });
        zurueck('?ergebnis=' . rawurlencode($cid) . '&ok=' . rawurlencode('Recherche abgeschlossen – Ergebnis steht im Recherche-Bereich.'));
    }

    if ($aktion === 'weingut_recherche') {
        $id = (string)($_POST['id'] ?? '');
        $weingut = weingutHolen(datenLaden(), $id);
        if ($weingut === null) {
            zurueck('?weingueter=1&fehler=' . rawurlencode('Dieses Weingut existiert nicht (mehr).'));
        }
        $auftrag = 'Recherchiere im Web das Champagner-Weingut/Champagnerhaus "' . $weingut['name'] . '" (Champagne, Frankreich). '
            . 'Fasse kurz auf Deutsch zusammen, als Stichpunkte mit Zeilenumbrüchen, insgesamt höchstens ~120 Wörter: '
            . 'Geschichte und Größe, Stil und Besonderheiten, bekannte Cuvées, Preisniveau, Besuchsmöglichkeiten. '
            . 'Keine Einleitung, keine Erklärungen, keine Quellenangaben.';
        $text = claudeWebsuche($auftrag);
        if ($text === '') {
            zurueck('?weingut=' . rawurlencode($id) . '&fehler=' . rawurlencode('Die Recherche hat nichts Verwertbares ergeben – ggf. Name prüfen und nochmal versuchen.'));
        }
        datenAendern(function (array $d) use ($id, $text): array {
            foreach ($d['weingueter'] as &$w) {
                if ($w['id'] === $id) {
                    $w['recherche'] = $text;
                }
            }
            return $d;
        });
        zurueck('?weingut=' . rawurlencode($id) . '&ok=' . rawurlencode('Recherche abgeschlossen – Ergebnis steht im Recherche-Bereich.'));
    }

    if ($aktion === 'tasting_anlegen') {
        $konto = aktuellerBenutzer(datenLaden());
        $mitKonto = darfTastingsAnlegen($konto);
        $titel = mb_substr(trim((string)($_POST['titel'] ?? '')), 0, 60);
        if ($titel === '') {
            zurueck('?tasting=1&fehler=' . rawurlencode('Bitte einen Titel für das Tasting angeben.'));
        }
        $neueId = bin2hex(random_bytes(4));
        $beitrittToken = bin2hex(random_bytes(8));
        $besitzerId = (string)($konto['id'] ?? '');
        $istGast = !$mitKonto; // Gast-Tastings: beliebig viele, aber je maximal du + 1 Person
        datenAendern(function (array $d) use ($titel, $neueId, $beitrittToken, $besitzerId, $istGast): array {
            $d['tastings'][] = ['id' => $neueId, 'titel' => $titel, 'aktiv_cid' => '', 'teilnehmer' => [], 'beitritt' => $beitrittToken, 'besitzer' => $besitzerId, 'gast' => $istGast, 'zeit' => time()];
            return $d;
        });
        tastingWaehlen($neueId);
        if ($istGast) {
            zurueck('?tasting=' . rawurlencode($neueId) . '&ok=' . rawurlencode('Tasting „' . $titel . '“ angelegt! Es gilt: du + 1 eingeladene Person. Unbegrenzte Runden schaltet der Administrator frei.'));
        }
        zurueck('?tasting=' . rawurlencode($neueId) . '&ok=' . rawurlencode('Tasting „' . $titel . '“ angelegt – jetzt Teilnehmer einladen!'));
    }

    if ($aktion === 'benutzer_registrieren') {
        // Selbst-Registrierung: sofort nutzbar, Tastings bis 2 Personen –
        // größere Runden schaltet der Administrator frei
        $vorname = mb_substr(trim((string)($_POST['vorname'] ?? '')), 0, 40);
        $nachname = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 40);
        $email = mb_strtolower(mb_substr(trim((string)($_POST['email'] ?? '')), 0, 80));
        $pw = (string)($_POST['passwort'] ?? '');
        if ($vorname === '' || $nachname === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            zurueck('?konto=1&fehler=' . rawurlencode('Bitte die Stammdaten vollständig ausfüllen: Vorname, Nachname und eine gültige E-Mail.'));
        }
        if (mb_strlen($pw) < 6) {
            zurueck('?konto=1&fehler=' . rawurlencode('Das Passwort braucht mindestens 6 Zeichen.'));
        }
        if (($_POST['agb'] ?? '') !== 'ja') {
            zurueck('?konto=1&fehler=' . rawurlencode('Bitte bestätige, dass du mindestens 18 Jahre alt bist und die Nutzungsbedingungen akzeptierst.'));
        }
        $neuerBenutzer = [
            'id' => bin2hex(random_bytes(4)),
            'vorname' => $vorname,
            'name' => $nachname,
            'email' => $email,
            'pw_hash' => password_hash($pw, PASSWORD_DEFAULT),
            'admin' => false,
            'darf_tasting' => false, // = noch keine großen Tastings (unbegrenzt Personen)
            'token' => bin2hex(random_bytes(16)),
            'agb_zeit' => time(), // wann die Nutzungsbedingungen bestätigt wurden
            'zeit' => time(),
        ];
        datenAendern(function (array $d) use ($neuerBenutzer, $email): array {
            foreach ($d['benutzer'] as $b) {
                if (mb_strtolower((string)($b['email'] ?? '')) === $email) {
                    zurueck('?konto=1&fehler=' . rawurlencode('Diese E-Mail hat schon ein Konto – einfach oben anmelden.'));
                }
            }
            $d['benutzer'][] = $neuerBenutzer;
            return $d;
        });
        setcookie('benutzer', $neuerBenutzer['token'], [
            'expires' => time() + 60 * 60 * 24 * 3650,
            'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
        ]);
        $_SESSION['tasting_ok'] = true;
        $_SESSION['person'] = $vorname;
        zurueck('?meine=1&ok=' . rawurlencode('Willkommen, ' . $vorname . '! Dein Konto ist fertig – du kannst sofort Tastings anlegen (du + 1 Person). Für größere Runden schaltet dich der Administrator frei.'));
    }

    if ($aktion === 'konto_anfragen') {
        // Kostenfreien Account anfragen – Stammdaten müssen vollständig sein
        $vorname = mb_substr(trim((string)($_POST['vorname'] ?? '')), 0, 40);
        $nachname = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 40);
        $email = mb_strtolower(mb_substr(trim((string)($_POST['email'] ?? '')), 0, 80));
        $pw = (string)($_POST['passwort'] ?? '');
        if ($vorname === '' || $nachname === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            zurueck('?tasting=1&fehler=' . rawurlencode('Bitte die Stammdaten vollständig ausfüllen: Vorname, Nachname und eine gültige E-Mail.'));
        }
        if (mb_strlen($pw) < 6) {
            zurueck('?tasting=1&fehler=' . rawurlencode('Das Wunsch-Passwort braucht mindestens 6 Zeichen.'));
        }
        $anfrage = [
            'id' => bin2hex(random_bytes(4)),
            'vorname' => $vorname,
            'name' => $nachname,
            'email' => $email,
            'pw_hash' => password_hash($pw, PASSWORD_DEFAULT),
            'zeit' => time(),
        ];
        datenAendern(function (array $d) use ($anfrage, $email): array {
            foreach ($d['benutzer'] as $b) {
                if (mb_strtolower((string)($b['email'] ?? '')) === $email) {
                    zurueck('?tasting=1&fehler=' . rawurlencode('Diese E-Mail hat schon ein Benutzerkonto – einfach unten anmelden.'));
                }
            }
            foreach ($d['kontoanfragen'] as $a) {
                if (mb_strtolower((string)($a['email'] ?? '')) === $email) {
                    zurueck('?tasting=1&fehler=' . rawurlencode('Für diese E-Mail liegt schon eine Anfrage vor – der Administrator schaltet dich frei.'));
                }
            }
            $d['kontoanfragen'][] = $anfrage;
            return $d;
        });
        zurueck('?tasting=1&ok=' . rawurlencode('Anfrage gesendet! Der Administrator schaltet dich frei – danach meldest du dich einfach mit E-Mail und Passwort an. Der Account ist kostenfrei.'));
    }

    if (in_array($aktion, ['benutzer_anlegen', 'benutzer_rechte', 'benutzer_loeschen', 'benutzer_passwort', 'konto_anfrage_entscheiden'], true)) {
        // Benutzerverwaltung: nur für Administratoren
        $admin = aktuellerBenutzer(datenLaden());
        if ($admin === null || empty($admin['admin'])) {
            zurueck('?verwaltung=1&fehler=' . rawurlencode('Die Benutzerverwaltung ist nur für Administratoren.'));
        }

        if ($aktion === 'benutzer_anlegen') {
            $vorname = mb_substr(trim((string)($_POST['vorname'] ?? '')), 0, 40);
            $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 40);
            $email = mb_strtolower(mb_substr(trim((string)($_POST['email'] ?? '')), 0, 80));
            $pw = (string)($_POST['passwort'] ?? '');
            $darfTasting = (string)($_POST['darf_tasting'] ?? '') === '1';
            if ($vorname === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                zurueck('?verwaltung=1&fehler=' . rawurlencode('Bitte mindestens Vorname und eine gültige E-Mail angeben.'));
            }
            if (mb_strlen($pw) < 6) {
                zurueck('?verwaltung=1&fehler=' . rawurlencode('Das Passwort braucht mindestens 6 Zeichen.'));
            }
            $neuerBenutzer = [
                'id' => bin2hex(random_bytes(4)),
                'vorname' => $vorname,
                'name' => $name,
                'email' => $email,
                'pw_hash' => password_hash($pw, PASSWORD_DEFAULT),
                'admin' => false,
                'darf_tasting' => $darfTasting,
                'token' => bin2hex(random_bytes(16)),
                'zeit' => time(),
            ];
            datenAendern(function (array $d) use ($neuerBenutzer, $email): array {
                foreach ($d['benutzer'] as $b) {
                    if (mb_strtolower((string)($b['email'] ?? '')) === $email) {
                        zurueck('?verwaltung=1&fehler=' . rawurlencode('Diese E-Mail hat schon ein Benutzerkonto.'));
                    }
                }
                $d['benutzer'][] = $neuerBenutzer;
                return $d;
            });
            zurueck('?verwaltung=1&ok=' . rawurlencode('Benutzer „' . $vorname . '“ angelegt' . ($darfTasting ? ' – darf Tastings anlegen.' : '.')));
        }

        if ($aktion === 'benutzer_rechte') {
            $bid = (string)($_POST['id'] ?? '');
            $feld = (string)($_POST['feld'] ?? '');
            if (!in_array($feld, ['darf_tasting', 'admin'], true)) {
                zurueck('?verwaltung=1');
            }
            if ($feld === 'admin' && $bid === (string)$admin['id']) {
                zurueck('?verwaltung=1&fehler=' . rawurlencode('Du kannst dir nicht selbst die Admin-Rechte entziehen.'));
            }
            datenAendern(function (array $d) use ($bid, $feld): array {
                foreach ($d['benutzer'] as &$b) {
                    if ($b['id'] === $bid) {
                        $b[$feld] = empty($b[$feld]);
                    }
                }
                unset($b);
                return $d;
            });
            zurueck('?verwaltung=1&ok=' . rawurlencode('Rechte aktualisiert.'));
        }

        if ($aktion === 'benutzer_passwort') {
            $bid = (string)($_POST['id'] ?? '');
            $pw = (string)($_POST['passwort'] ?? '');
            if (mb_strlen($pw) < 6) {
                zurueck('?verwaltung=1&fehler=' . rawurlencode('Das neue Passwort braucht mindestens 6 Zeichen.'));
            }
            $hash = password_hash($pw, PASSWORD_DEFAULT);
            datenAendern(function (array $d) use ($bid, $hash): array {
                foreach ($d['benutzer'] as &$b) {
                    if ($b['id'] === $bid) {
                        $b['pw_hash'] = $hash;
                    }
                }
                unset($b);
                return $d;
            });
            zurueck('?verwaltung=1&ok=' . rawurlencode('Passwort neu gesetzt.'));
        }

        if ($aktion === 'benutzer_loeschen') {
            $bid = (string)($_POST['id'] ?? '');
            if ($bid === (string)$admin['id']) {
                zurueck('?verwaltung=1&fehler=' . rawurlencode('Du kannst dein eigenes Konto nicht löschen.'));
            }
            datenAendern(function (array $d) use ($bid): array {
                $d['benutzer'] = array_values(array_filter($d['benutzer'], fn($b) => $b['id'] !== $bid));
                return $d;
            });
            zurueck('?verwaltung=1&ok=' . rawurlencode('Benutzer gelöscht.'));
        }

        if ($aktion === 'konto_anfrage_entscheiden') {
            $aid = (string)($_POST['id'] ?? '');
            $annehmen = (string)($_POST['entscheidung'] ?? '') === 'annehmen';
            $ergebnis = '';
            datenAendern(function (array $d) use ($aid, $annehmen, &$ergebnis): array {
                foreach ($d['kontoanfragen'] as $i => $a) {
                    if ($a['id'] === $aid) {
                        if ($annehmen) {
                            $d['benutzer'][] = [
                                'id' => bin2hex(random_bytes(4)),
                                'vorname' => (string)$a['vorname'],
                                'name' => (string)$a['name'],
                                'email' => (string)$a['email'],
                                'pw_hash' => (string)$a['pw_hash'],
                                'admin' => false,
                                'darf_tasting' => true,
                                'token' => bin2hex(random_bytes(16)),
                                'zeit' => time(),
                            ];
                            $ergebnis = $a['vorname'] . ' ist freigeschaltet und kann sich jetzt anmelden – Tastings anlegen inklusive.';
                        } else {
                            $ergebnis = 'Anfrage von ' . $a['vorname'] . ' abgelehnt.';
                        }
                        unset($d['kontoanfragen'][$i]);
                        $d['kontoanfragen'] = array_values($d['kontoanfragen']);
                        break;
                    }
                }
                return $d;
            });
            zurueck('?verwaltung=1&' . ($ergebnis !== '' ? 'ok=' . rawurlencode($ergebnis) : 'fehler=' . rawurlencode('Anfrage nicht gefunden.')));
        }
    }

    if ($aktion === 'tasting_titel') {
        $tid = (string)($_POST['tasting_id'] ?? '');
        $titel = mb_substr(trim((string)($_POST['titel'] ?? '')), 0, 60);
        if ($titel === '') {
            zurueck('?tasting=' . rawurlencode($tid) . '&fehler=' . rawurlencode('Der Titel darf nicht leer sein.'));
        }
        datenAendern(function (array $d) use ($tid, $titel): array {
            foreach ($d['tastings'] as &$t) {
                if ($t['id'] === $tid) {
                    $t['titel'] = $titel;
                }
            }
            return $d;
        });
        zurueck('?tasting=' . rawurlencode($tid) . '&ok=' . rawurlencode('Titel gespeichert.'));
    }

    if ($aktion === 'tasting_loeschen') {
        $tid = (string)($_POST['tasting_id'] ?? '');
        datenAendern(function (array $d) use ($tid): array {
            $d['tastings'] = array_values(array_filter($d['tastings'], fn($t) => $t['id'] !== $tid));
            return $d;
        });
        zurueck('?tasting=1&ok=' . rawurlencode('Tasting gelöscht (Bewertungen bleiben erhalten).'));
    }

    if ($aktion === 'teilnehmer_anlegen') {
        $tid = (string)($_POST['tasting_id'] ?? '');
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 40);
        $email = mb_substr(trim((string)($_POST['email'] ?? '')), 0, 80);
        if ($name === '') {
            zurueck('?tasting=' . rawurlencode($tid) . '&fehler=' . rawurlencode('Bitte einen Namen angeben.'));
        }
        foreach (datenLaden()['tastings'] as $t) {
            if ($t['id'] === $tid && teilnehmerLimitErreicht($t)) {
                zurueck('?tasting=' . rawurlencode($tid) . '&fehler=' . rawurlencode('Dieses Tasting ist auf 2 Teilnehmer begrenzt (du + 1 Gast). Für große Runden kann der Administrator den Ersteller freischalten.'));
            }
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            zurueck('?tasting=' . rawurlencode($tid) . '&fehler=' . rawurlencode('Die E-Mail-Adresse sieht nicht gültig aus.'));
        }
        $token = bin2hex(random_bytes(8));
        datenAendern(function (array $d) use ($tid, $name, $email, $token): array {
            foreach ($d['tastings'] as &$t) {
                if ($t['id'] === $tid) {
                    $t['teilnehmer'][] = ['token' => $token, 'name' => $name, 'email' => $email];
                }
            }
            return $d;
        });
        zurueck('?tasting=' . rawurlencode($tid) . '&ok=' . rawurlencode($name . ' ist dabei – Link kopieren oder per Mail senden!'));
    }

    if ($aktion === 'teilnehmer_loeschen') {
        $tid = (string)($_POST['tasting_id'] ?? '');
        $token = (string)($_POST['token'] ?? '');
        datenAendern(function (array $d) use ($tid, $token): array {
            foreach ($d['tastings'] as &$t) {
                if ($t['id'] === $tid) {
                    $t['teilnehmer'] = array_values(array_filter($t['teilnehmer'], fn($p) => $p['token'] !== $token));
                }
            }
            return $d;
        });
        zurueck('?tasting=' . rawurlencode($tid) . '&ok=' . rawurlencode('Teilnehmer entfernt.'));
    }

    if ($aktion === 'teilnehmer_mail') {
        $tid = (string)($_POST['tasting_id'] ?? '');
        $token = (string)($_POST['token'] ?? '');
        $treffer = teilnehmerZuToken(datenLaden(), $token);
        if ($treffer === null || $treffer[0]['id'] !== $tid) {
            zurueck('?tasting=' . rawurlencode($tid) . '&fehler=' . rawurlencode('Teilnehmer nicht gefunden.'));
        }
        [$tasting, $person] = $treffer;
        if ($person['email'] === '') {
            zurueck('?tasting=' . rawurlencode($tid) . '&fehler=' . rawurlencode('Für ' . $person['name'] . ' ist keine E-Mail hinterlegt – nutze den Kopier-Knopf.'));
        }
        $link = 'https://fruthzeug.de/projekte/champagner/?einladung=' . $person['token'];
        $betreff = mb_encode_mimeheader('Einladung zum Tasting „' . $tasting['titel'] . '“', 'UTF-8');
        $text = "Hallo " . $person['name'] . ",\n\n"
            . "du bist eingeladen zum Tasting \u{201E}" . $tasting['titel'] . "\u{201C}!\n\n"
            . "Tipp einfach auf diesen Link – damit bist du angemeldet und kannst sofort mitbewerten (kein Passwort nötig):\n"
            . $link . "\n\nBis gleich! 🥂";
        $ok = @mail($person['email'], $betreff, $text,
            "From: Tasting fruthzeug.de <tasting@fruthzeug.de>\r\nContent-Type: text/plain; charset=UTF-8");
        if ($ok) {
            zurueck('?tasting=' . rawurlencode($tid) . '&ok=' . rawurlencode('Einladung an ' . $person['email'] . ' verschickt.'));
        }
        zurueck('?tasting=' . rawurlencode($tid) . '&fehler=' . rawurlencode('Mail-Versand hat nicht geklappt – nutze den Kopier-Knopf.'));
    }

    if ($aktion === 'glas_setzen') {
        $tid = (string)($_POST['tasting_id'] ?? '');
        $cid = (string)($_POST['champagner_id'] ?? '');
        datenAendern(function (array $d) use ($tid, $cid): array {
            if ($cid !== '' && champagnerHolen($d, $cid) === null) {
                zurueck('?tasting=' . rawurlencode($tid) . '&fehler=' . rawurlencode('Champagner nicht gefunden.'));
            }
            foreach ($d['tastings'] as &$t) {
                if ($t['id'] === $tid) {
                    $t['aktiv_cid'] = $cid;
                }
            }
            return $d;
        });
        zurueck('?tasting=' . rawurlencode($tid) . '&ok=' . rawurlencode($cid === '' ? 'Zurückgesetzt – gerade ist kein Getränk als „aktuell“ gewählt. Das nächste erfasste Getränk wird automatisch das aktuelle.' : 'Für alle als aktuelles Getränk der Runde gesetzt. 🥂'));
    }

    if ($aktion === 'tasting_zuordnen') {
        $tid = (string)($_POST['tasting_id'] ?? '');
        $cid = (string)($_POST['champagner_id'] ?? '');
        $d0 = datenLaden();
        $zielTasting = null;
        foreach ($d0['tastings'] as $t) {
            if ($t['id'] === $tid) {
                $zielTasting = $t;
                break;
            }
        }
        $getraenk = champagnerHolen($d0, $cid);
        if ($zielTasting === null || $getraenk === null) {
            zurueck('?tasting=' . rawurlencode($tid) . '&fehler=' . rawurlencode('Getränk oder Tasting nicht gefunden.'));
        }
        datenAendern(function (array $d) use ($tid, $cid): array {
            foreach ($d['champagner'] as &$c) {
                if ($c['id'] === $cid) {
                    $c['tasting_id'] = $tid;
                }
            }
            unset($c);
            return $d;
        });
        zurueck('?tasting=' . rawurlencode($tid) . '&ok=' . rawurlencode('„' . $getraenk['name'] . '“ gehört jetzt zu „' . $zielTasting['titel'] . '“.'));
    }

    if ($aktion === 'weingut_kontakt') {
        $id = (string)($_POST['id'] ?? '');
        $weingut = weingutHolen(datenLaden(), $id);
        if ($weingut === null) {
            zurueck('?weingueter=1&fehler=' . rawurlencode('Dieses Weingut existiert nicht (mehr).'));
        }
        $kontakt = weingutKontaktErmitteln($weingut['name']);
        if ($kontakt === '') {
            zurueck('?weingut=' . rawurlencode($id) . '&fehler=' . rawurlencode('Es wurden keine verlässlichen Kontaktdaten gefunden – du kannst sie unten von Hand eintragen.'));
        }
        datenAendern(function (array $d) use ($id, $kontakt): array {
            foreach ($d['weingueter'] as &$w) {
                if ($w['id'] === $id) {
                    $w['kontakt'] = $kontakt;
                }
            }
            return $d;
        });
        zurueck('?weingut=' . rawurlencode($id) . '&ok=' . rawurlencode('Kontaktdaten gefunden und eingetragen – bitte kurz auf Plausibilität prüfen.'));
    }

    if ($aktion === 'champagner_loeschen') {
        $id = (string)($_POST['id'] ?? '');
        datenAendern(function (array $d) use ($id): array {
            $d['champagner']  = array_values(array_filter($d['champagner'], fn($c) => $c['id'] !== $id));
            $d['bewertungen'] = array_values(array_filter($d['bewertungen'], fn($b) => $b['champagner_id'] !== $id));
            return $d;
        });
        if (preg_match('/^[a-f0-9]{8}$/', $id)) {
            foreach (glob(BILDER_DIR . '/' . $id . '-*') ?: [] as $foto) {
                @unlink($foto);
                thumbLoeschen(basename($foto));
            }
        }
        zurueck('?ok=' . rawurlencode('Champagner samt Bewertungen und Fotos gelöscht.'));
    }

    if ($aktion === 'bewerten') {
        $cid    = (string)($_POST['champagner_id'] ?? '');
        $person = trim((string)($_POST['person'] ?? ''));
        $person = mb_substr($person, 0, 40);
        if ($person === '') {
            zurueck('?bewerten=' . rawurlencode($cid) . '&fehler=' . rawurlencode('Bitte deinen Namen eintragen.'));
        }
        // Geführte Bewertung: Detail-Antworten übernehmen
        $detail = [];
        $rohDetail = json_decode((string)($_POST['detail'] ?? ''), true);
        if (is_array($rohDetail)) {
            foreach (['farbe', 'perlage', 'duft', 'geschmack', 'saeure', 'mousse', 'balance', 'abgang', 'charakter', 'nochmal', 'sauber', 'tiefe', 'koerper', 'suesse', 'antrunk'] as $k) {
                if (isset($rohDetail[$k]) && is_string($rohDetail[$k])) {
                    $detail[$k] = mb_substr($rohDetail[$k], 0, 30);
                }
            }
            if (isset($rohDetail['aromen']) && is_array($rohDetail['aromen'])) {
                $detail['aromen'] = array_slice(array_map(fn($a) => mb_substr((string)$a, 0, 30), $rohDetail['aromen']), 0, 20);
            }
        }
        // Sterne: entweder direkt (justiert) oder aus den Detail-Antworten abgeleitet
        $werte = [];
        $abgeleitet = sterneAusDetail($detail);
        foreach ($KATEGORIEN as $schluessel => $info) {
            $v = (int)($_POST[$schluessel] ?? 0);
            if ($v < 1 || $v > 5) {
                $v = $abgeleitet[$schluessel] ?? 0;
            }
            if ($v < 1 || $v > 5) {
                zurueck('?bewerten=' . rawurlencode($cid) . '&fehler=' . rawurlencode('Die Bewertung war unvollständig – bitte nochmal durchgehen.'));
            }
            $werte[$schluessel] = $v;
        }
        $notiz = trim((string)($_POST['notiz'] ?? ''));
        $notiz = mb_substr($notiz, 0, 500);
        $flaschen = max(0, min(99, (int)($_POST['flaschen'] ?? 0)));
        $vk = (string)($_POST['vk'] ?? '');
        if ($vk !== 'ohne' && !preg_match('/^[a-f0-9]{8}$/', $vk)) {
            $vk = '';
        }
        $_SESSION['person'] = $person;
        $meinTastingId = (string)(meinTasting(datenLaden())['id'] ?? '');
        // Umfeld der Bewertung: Standort vom Gerät → Ort und Wetter dazu ermitteln
        $umfeldLat = is_numeric($_POST['geo_lat'] ?? null) ? round((float)$_POST['geo_lat'], 5) : null;
        $umfeldLon = is_numeric($_POST['geo_lon'] ?? null) ? round((float)$_POST['geo_lon'], 5) : null;
        $umfeldOrt = '';
        $umfeldWetter = '';
        if ($umfeldLat !== null && $umfeldLon !== null && abs($umfeldLat) <= 90 && abs($umfeldLon) <= 180
            && (abs($umfeldLat) > 0.0001 || abs($umfeldLon) > 0.0001)) {
            @set_time_limit(60);
            $umfeldOrt = ortKurz($umfeldLat, $umfeldLon);
            $umfeldWetter = wetterErmitteln($umfeldLat, $umfeldLon);
        } else {
            $umfeldLat = null;
            $umfeldLon = null;
        }
        datenAendern(function (array $d) use ($cid, $person, $werte, $notiz, $flaschen, $detail, $meinTastingId, $umfeldOrt, $umfeldWetter, $umfeldLat, $umfeldLon): array {
            $existiert = false;
            foreach ($d['champagner'] as $c) {
                if ($c['id'] === $cid) {
                    $existiert = true;
                    break;
                }
            }
            if (!$existiert) {
                zurueck('?fehler=' . rawurlencode('Dieser Champagner existiert nicht (mehr).'));
            }
            // Frühere Bewertung derselben Person für diesen Champagner ersetzen
            $vorherige = null;
            foreach ($d['bewertungen'] as $b) {
                if ($b['champagner_id'] === $cid && mb_strtolower($b['person']) === mb_strtolower($person)) {
                    $vorherige = $b;
                    break;
                }
            }
            $d['bewertungen'] = array_values(array_filter(
                $d['bewertungen'],
                fn($b) => !($b['champagner_id'] === $cid && mb_strtolower($b['person']) === mb_strtolower($person))
            ));
            $d['bewertungen'][] = [
                'champagner_id' => $cid,
                'person'        => $person,
                'werte'         => $werte,
                'notiz'         => $notiz,
                'flaschen'      => $flaschen,
                'detail'        => $detail,
                'tasting_id'    => $meinTastingId,
                // Umfeld: Ort und Wetter zum Zeitpunkt der Bewertung
                'ort'           => $umfeldOrt !== '' ? $umfeldOrt : (string)($vorherige['ort'] ?? ''),
                'wetter'        => $umfeldWetter !== '' ? $umfeldWetter : (string)($vorherige['wetter'] ?? ''),
                'lat'           => $umfeldLat ?? ($vorherige['lat'] ?? null),
                'lon'           => $umfeldLon ?? ($vorherige['lon'] ?? null),
                // Live im Verkostungs-Moment oder nachträglich? (Beim Ändern bleibt der ursprüngliche Status)
                'live'          => $vorherige !== null && array_key_exists('live', $vorherige)
                    ? (bool)$vorherige['live']
                    : (function () use ($d, $cid): bool {
                        $c = champagnerHolen($d, $cid);
                        $gz = (int)($c['zeit'] ?? 0);
                        return $gz === 0 || (time() - $gz) <= 6 * 3600;
                    })(),
                'zeit'          => time(),
            ];
            return $d;
        });
        if ($vk !== '') {
            // Im Verkosten-Modus direkt zurück zur Arbeitsfläche – der nächste Wein wartet
            zurueck('?vk=' . rawurlencode($vk) . '&ok=' . rawurlencode('Danke, ' . $person . ' – gespeichert! Der nächste Wein kann kommen. 🥂'));
        }
        zurueck('?ergebnis=' . rawurlencode($cid) . '&ok=' . rawurlencode('Danke, ' . $person . ' – deine Bewertung ist gespeichert!'));
    }

    zurueck();
}

$meldung = (string)($_GET['ok'] ?? '');
$fehler  = (string)($_GET['fehler'] ?? '');

$daten = datenLaden();

// Einmalige Migration: alle Bewertungen und Champagner ohne Tasting-Zuordnung
// dem bestehenden „Champagne 26“ zuschlagen (bzw. es anlegen).
$brauchtMigration = false;
foreach ($daten['bewertungen'] as $b) {
    if (!isset($b['tasting_id'])) {
        $brauchtMigration = true;
        break;
    }
}
if (!$brauchtMigration) {
    foreach ($daten['champagner'] as $c) {
        if (!isset($c['tasting_id'])) {
            $brauchtMigration = true;
            break;
        }
    }
}
if ($brauchtMigration) {
    datenAendern(function (array $d): array {
        // Ziel-Tasting suchen (Name enthält „champagne“) oder ältestes, sonst neu anlegen
        $zielId = '';
        foreach ($d['tastings'] as $t) {
            if (str_contains(mb_strtolower($t['titel']), 'champagne')) {
                $zielId = $t['id'];
                break;
            }
        }
        if ($zielId === '' && $d['tastings'] !== []) {
            $aeltest = $d['tastings'];
            usort($aeltest, fn($a, $b) => ((int)($a['zeit'] ?? 0)) <=> ((int)($b['zeit'] ?? 0)));
            $zielId = $aeltest[0]['id'];
        }
        if ($zielId === '') {
            $zielId = bin2hex(random_bytes(4));
            $d['tastings'][] = ['id' => $zielId, 'titel' => 'Champagne 26', 'aktiv_cid' => '', 'teilnehmer' => [], 'beitritt' => bin2hex(random_bytes(8)), 'zeit' => time()];
        }
        foreach ($d['bewertungen'] as &$b) {
            if (!isset($b['tasting_id'])) {
                $b['tasting_id'] = $zielId;
            }
        }
        unset($b);
        // Champagner erben das Tasting ihrer Bewertungen, sonst das Ziel-Tasting
        foreach ($d['champagner'] as &$c) {
            if (!isset($c['tasting_id'])) {
                $cTid = '';
                foreach ($d['bewertungen'] as $bw) {
                    if ($bw['champagner_id'] === $c['id'] && (string)($bw['tasting_id'] ?? '') !== '') {
                        $cTid = (string)$bw['tasting_id'];
                        break;
                    }
                }
                $c['tasting_id'] = $cTid !== '' ? $cTid : $zielId;
            }
        }
        unset($c);
        return $d;
    });
    $daten = datenLaden();
}

/** Gehört ein Champagner zum angegebenen Tasting? (Einträge ohne Zuordnung zählen überall mit.) */
function champagnerInTasting(array $c, string $tid): bool
{
    $ct = (string)($c['tasting_id'] ?? '');
    return $ct === '' || $tid === '' || $ct === $tid;
}

/** Weingüter, an denen in diesem Tasting verkostet wurde (IDs als Schlüssel). */
function weingutIdsImTasting(array $daten, string $tid): array
{
    $ids = [];
    foreach ($daten['champagner'] as $c) {
        if (champagnerInTasting($c, $tid)) {
            $wid = trim((string)($c['weingut_id'] ?? ''));
            if ($wid !== '') {
                $ids[$wid] = true;
            }
        }
    }
    return $ids;
}

// Einmalig: Admin-Konto für Carl anlegen, falls noch kein Administrator existiert
$hatAdmin = false;
foreach ($daten['benutzer'] as $b) {
    if (!empty($b['admin'])) {
        $hatAdmin = true;
        break;
    }
}
if (!$hatAdmin) {
    datenAendern(function (array $d): array {
        foreach ($d['benutzer'] as $b) {
            if (!empty($b['admin'])) {
                return $d;
            }
        }
        $d['benutzer'][] = [
            'id' => bin2hex(random_bytes(4)),
            'vorname' => 'Carl',
            'name' => 'Fruth',
            'email' => 'carl.fruth@gmail.com',
            'pw_hash' => password_hash(PASSWORT, PASSWORD_DEFAULT),
            'admin' => true,
            'darf_tasting' => true,
            'token' => bin2hex(random_bytes(16)),
            'zeit' => time(),
        ];
        return $d;
    });
    $daten = datenLaden();
}

// Tasting-Blick des Betrachters (per Cookie, sonst neuestes) – filtert die Listen
$blickTastingId = (string)(meinTasting($daten)['id'] ?? '');

// Angemeldetes Benutzerkonto (Dauer-Cookie) – steuert Tasting-Anlage und Verwaltung
$benutzerAktiv = aktuellerBenutzer($daten);
$istAdmin = $benutzerAktiv !== null && !empty($benutzerAktiv['admin']);

/** Alle Bewertungen zu einem Champagner. */
function bewertungenFuer(array $daten, string $cid): array
{
    return array_values(array_filter($daten['bewertungen'], fn($b) => $b['champagner_id'] === $cid));
}

/** Gesamtschnitt (über alle Kategorien und Personen) oder null. */
function gesamtSchnitt(array $bewertungen): ?float
{
    $summe = 0;
    $anzahl = 0;
    foreach ($bewertungen as $b) {
        foreach ($b['werte'] as $w) {
            $summe += (int)$w;
            $anzahl++;
        }
    }
    return $anzahl > 0 ? $summe / $anzahl : null;
}

/** Schnitt je Kategorie. */
function kategorieSchnitte(array $bewertungen, array $KATEGORIEN): array
{
    $result = [];
    foreach ($KATEGORIEN as $schluessel => $info) {
        $summe = 0;
        $anzahl = 0;
        foreach ($bewertungen as $b) {
            if (isset($b['werte'][$schluessel])) {
                $summe += (int)$b['werte'][$schluessel];
                $anzahl++;
            }
        }
        $result[$schluessel] = $anzahl > 0 ? $summe / $anzahl : null;
    }
    return $result;
}

function sterneAnzeige(?float $wert): string
{
    if ($wert === null) {
        return '<span class="sterne-anzeige leer-sterne">noch keine Bewertung</span>';
    }
    $voll = (int)round($wert);
    return '<span class="sterne-anzeige">' . str_repeat('★', $voll) . str_repeat('☆', 5 - $voll)
        . ' <span class="wert">' . number_format($wert, 1, ',', '') . '</span></span>';
}

/** Fotos zu einem Champagner (neueste zuerst). */
function fotosFuer(string $cid): array
{
    if (!preg_match('/^[a-f0-9]{8}$/', $cid)) {
        return [];
    }
    $treffer = glob(BILDER_DIR . '/' . $cid . '-*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE) ?: [];
    usort($treffer, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    return array_map('basename', $treffer);
}

/** Fotos zu einem Weingut (neueste zuerst). */
function weingutFotos(string $id): array
{
    if (!preg_match('/^[a-f0-9]{8}$/', $id)) {
        return [];
    }
    $treffer = glob(BILDER_DIR . '/wg-' . $id . '-*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE) ?: [];
    usort($treffer, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    return array_map('basename', $treffer);
}

/** Fotos zu einem Tasting (neueste zuerst). */
function tastingFotos(string $id): array
{
    if (!preg_match('/^[a-f0-9]{8}$/', $id)) {
        return [];
    }
    $treffer = glob(BILDER_DIR . '/ts-' . $id . '-*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE) ?: [];
    usort($treffer, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    return array_map('basename', $treffer);
}

/** Gewähltes Titelbild an den Anfang stellen – es dient überall als Vorschaubild. */
function mitTitelbild(array $fotos, string $titelbild): array
{
    if ($titelbild === '' || !in_array($titelbild, $fotos, true)) {
        return $fotos;
    }
    return array_merge([$titelbild], array_values(array_diff($fotos, [$titelbild])));
}

/** Diverse Fotos des Albums (neueste zuerst). */
function divFotos(): array
{
    $treffer = glob(BILDER_DIR . '/div-*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE) ?: [];
    usort($treffer, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    return array_map('basename', $treffer);
}

/**
 * Gerade hochgeladene Dateien wieder löschen, wenn ihr Inhalt (MD5) schon
 * irgendwo im Bilderordner liegt – doppelte Fotos entstehen so gar nicht erst.
 * Gibt die Zahl der aussortierten Duplikate zurück.
 */
function doppelteAussortieren(array $neueDateien): int
{
    if ($neueDateien === []) {
        return 0;
    }
    $neuSet = array_flip($neueDateien);
    $bekannt = [];
    foreach (glob(BILDER_DIR . '/*.{jpg,jpeg,png,gif,webp,JPG,JPEG,PNG,GIF,WEBP}', GLOB_BRACE) ?: [] as $pfad) {
        $basis = basename($pfad);
        if (isset($neuSet[$basis]) || str_starts_with($basis, 'neu-') || str_starts_with($basis, 'wneu-')) {
            continue;
        }
        $h = md5_file($pfad);
        if ($h !== false) {
            $bekannt[$h] = true;
        }
    }
    $weg = 0;
    foreach ($neueDateien as $datei) {
        $pfad = BILDER_DIR . '/' . $datei;
        if (!is_file($pfad)) {
            continue;
        }
        $h = md5_file($pfad);
        if ($h === false) {
            continue;
        }
        if (isset($bekannt[$h])) {
            unlink($pfad);
            thumbLoeschen($datei);
            $weg++;
        } else {
            $bekannt[$h] = true;
        }
    }
    return $weg;
}

function thumbVerzeichnis(): string
{
    return BILDER_DIR . '/thumbs';
}

/**
 * Verkleinerte, korrekt gedrehte Vorschau erzeugen (max. 640 px Kante).
 * Gibt false zurück, wenn GD fehlt oder das Bild nicht lesbar ist.
 */
function thumbErzeugen(string $quelle, string $ziel, int $maxKante = 640): bool
{
    if (!function_exists('imagecreatetruecolor') || !is_file($quelle)) {
        return false;
    }
    $info = @getimagesize($quelle);
    if ($info === false) {
        return false;
    }
    [$b, $h] = $info;
    // Extrem hochauflösende Fotos (>40 Megapixel) würden das Speicherlimit
    // sprengen – dann lieber keine Vorschau als ein abgebrochener Upload.
    if ($b * $h > 40000000) {
        return false;
    }
    $typ = $info[2];
    $img = match ($typ) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($quelle),
        IMAGETYPE_PNG  => @imagecreatefrompng($quelle),
        IMAGETYPE_GIF  => @imagecreatefromgif($quelle),
        IMAGETYPE_WEBP => @imagecreatefromwebp($quelle),
        default        => false,
    };
    if ($img === false) {
        return false;
    }
    // iPhone-Fotos: Drehung aus den EXIF-Daten übernehmen
    if ($typ === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($quelle);
        $o = (int)($exif['Orientation'] ?? 1);
        if ($o === 3) {
            $img = imagerotate($img, 180, 0);
        } elseif ($o === 6) {
            $img = imagerotate($img, -90, 0);
        } elseif ($o === 8) {
            $img = imagerotate($img, 90, 0);
        }
        $b = imagesx($img);
        $h = imagesy($img);
    }
    $faktor = min(1.0, $maxKante / max($b, $h));
    $nb = max(1, (int)round($b * $faktor));
    $nh = max(1, (int)round($h * $faktor));
    $neu = imagecreatetruecolor($nb, $nh);
    $weiss = imagecolorallocate($neu, 255, 255, 255);
    imagefill($neu, 0, 0, $weiss);
    imagecopyresampled($neu, $img, 0, 0, 0, 0, $nb, $nh, $b, $h);
    if (!is_dir(dirname($ziel))) {
        @mkdir(dirname($ziel), 0755, true);
    }
    $ok = imagejpeg($neu, $ziel, 82);
    imagedestroy($img);
    imagedestroy($neu);
    if ($ok) {
        @chmod($ziel, 0644);
    }
    return (bool)$ok;
}

/** URL der Vorschau eines Fotos; erzeugt sie bei Bedarf, Fallback aufs Original. */
function thumbUrl(string $name): string
{
    $thumb = thumbVerzeichnis() . '/' . $name . '.jpg';
    if (!is_file($thumb) && !thumbErzeugen(BILDER_DIR . '/' . $name, $thumb)) {
        return 'bilder/' . rawurlencode($name);
    }
    return 'bilder/thumbs/' . rawurlencode($name . '.jpg');
}

function thumbLoeschen(string $name): void
{
    @unlink(thumbVerzeichnis() . '/' . $name . '.jpg');
}

/** Alle Fotos eines Zwischen-Präfixes auf das endgültige Ziel-Präfix umbenennen. */
function fotosUmhaengen(string $praefix, string $erwarteterTyp, string $zielPraefix): void
{
    if (!preg_match('/^' . $erwarteterTyp . '-[a-f0-9]{8}$/', $praefix)) {
        return;
    }
    $umbenannt = [];
    foreach (glob(BILDER_DIR . '/' . $praefix . '-*') ?: [] as $alt) {
        $basis = basename($alt);
        if (!preg_match('/\.(jpe?g|png|gif|webp)$/i', $basis, $m)) {
            continue;
        }
        $neuerName = sprintf('%s-%s-%s.%s', $zielPraefix, date('Ymd-His'), bin2hex(random_bytes(3)), strtolower($m[1]));
        if (rename($alt, BILDER_DIR . '/' . $neuerName)) {
            thumbLoeschen($basis);
            thumbErzeugen(BILDER_DIR . '/' . $neuerName, thumbVerzeichnis() . '/' . $neuerName . '.jpg');
            $umbenannt[$basis] = $neuerName;
        }
    }
    bildEintraegeUmbenennen($umbenannt);
}

function weingutHolen(array $daten, string $id): ?array
{
    foreach ($daten['weingueter'] as $w) {
        if ($w['id'] === $id) {
            return $w;
        }
    }
    return null;
}

/**
 * Festhalten, wer diese Bilddateien hochgeladen hat – wichtig, wenn jemand
 * später seine Daten löschen will. Räumt dabei Einträge zu inzwischen
 * gelöschten Dateien gleich mit auf.
 */
function bildUploaderMerken(array $dateien): void
{
    if ($dateien === []) {
        return;
    }
    $konto = aktuellerBenutzer(datenLaden());
    $eintrag = [
        'person' => trim((string)($_SESSION['person'] ?? '')),
        'benutzer_id' => $konto !== null ? (string)$konto['id'] : '',
        'zeit' => time(),
    ];
    datenAendern(function (array $d) use ($dateien, $eintrag): array {
        foreach (($d['bilder'] ?? []) as $name => $info) {
            if (!is_file(BILDER_DIR . '/' . $name)) {
                unset($d['bilder'][$name]); // Datei existiert nicht mehr
            }
        }
        foreach ($dateien as $datei) {
            $d['bilder'][$datei] = $eintrag;
        }
        return $d;
    });
}

/** Uploader-Einträge beim Umbenennen von Bilddateien mitnehmen ([alt => neu]). */
function bildEintraegeUmbenennen(array $paare): void
{
    if ($paare === []) {
        return;
    }
    datenAendern(function (array $d) use ($paare): array {
        foreach ($paare as $alt => $neu) {
            if (isset($d['bilder'][$alt])) {
                $d['bilder'][$neu] = $d['bilder'][$alt];
                unset($d['bilder'][$alt]);
            }
        }
        return $d;
    });
}

/** Hochgeladene Fotos aus $_FILES['fotos'] speichern; gibt [hochgeladen, abgelehnt] zurück. */
function fotoUploadVerarbeiten(array $bildTypen, string $praefix): array
{
    $dateien = $_FILES['fotos'] ?? null;
    if ($dateien === null) {
        return [0, 0];
    }
    if (!is_dir(BILDER_DIR)) {
        mkdir(BILDER_DIR, 0755, true);
    }
    $hochgeladen = 0;
    $abgelehnt   = 0;
    $gespeichert = [];
    $finfo       = new finfo(FILEINFO_MIME_TYPE);
    foreach ((array)$dateien['name'] as $i => $originalName) {
        if (($dateien['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $abgelehnt++;
            continue;
        }
        $tmp = (string)$dateien['tmp_name'][$i];
        if (!is_uploaded_file($tmp) || (int)$dateien['size'][$i] > MAX_BILD_GROESSE) {
            $abgelehnt++;
            continue;
        }
        $typ = $finfo->file($tmp);
        if (!isset($bildTypen[$typ])) {
            $abgelehnt++;
            continue;
        }
        $ziel = sprintf('%s/%s-%s-%s.%s', BILDER_DIR, $praefix, date('Ymd-His'), bin2hex(random_bytes(3)), $bildTypen[$typ]);
        if (move_uploaded_file($tmp, $ziel)) {
            @chmod($ziel, 0644);
            thumbErzeugen($ziel, thumbVerzeichnis() . '/' . basename($ziel) . '.jpg');
            $hochgeladen++;
            $gespeichert[] = basename($ziel);
        } else {
            $abgelehnt++;
        }
    }
    bildUploaderMerken($gespeichert);
    return [$hochgeladen, $abgelehnt];
}

function uploadText(int $hochgeladen, int $abgelehnt): string
{
    $text = $hochgeladen . ' Foto(s) hochgeladen.';
    if ($abgelehnt > 0) {
        $text .= ' ' . $abgelehnt . ' Datei(en) übersprungen (kein Bild, zu groß oder Fehler).';
    }
    return $text;
}

/**
 * Etikett per KI lesen (Claude API). Liefert ['name'=>..,'weingut'=>..] –
 * leere Strings, wenn kein API-Schlüssel hinterlegt ist oder nichts erkannt wurde.
 */
function etikettErkennen(string $fotoName): array
{
    $leer = ['name' => '', 'weingut' => '', 'rebsorte' => ''];
    $keyDatei = __DIR__ . '/daten/apikey.php';
    if (!is_file($keyDatei)) {
        return $leer;
    }
    $key = (string)(require $keyDatei);
    if ($key === '' || !function_exists('curl_init')) {
        return $leer;
    }
    // Die kleine Vorschau reicht der KI und spart Übertragung
    $thumb = thumbVerzeichnis() . '/' . $fotoName . '.jpg';
    if (!is_file($thumb) && !thumbErzeugen(BILDER_DIR . '/' . $fotoName, $thumb, 1024)) {
        return $leer;
    }
    $bild = base64_encode((string)file_get_contents($thumb));
    $body = json_encode([
        'model'      => 'claude-haiku-4-5',
        'max_tokens' => 200,
        'messages'   => [[
            'role'    => 'user',
            'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $bild]],
                ['type' => 'text', 'text' => 'Auf dem Foto ist eine Getränkeflasche (Champagner, Wein oder Bier). Lies das Etikett und antworte NUR mit JSON in genau dieser Form: {"weingut":"...","name":"...","rebsorte":"..."} – weingut ist der Erzeuger (Champagnerhaus, Weingut oder Brauerei), name die Bezeichnung des Getränks (mit Cuvée und Jahrgang, falls lesbar, aber ohne Erzeugername), rebsorte die Rebsorte(n) bzw. beim Bier der Bierstil (nur wenn auf dem Etikett lesbar). Was du nicht erkennst, lässt du als leeren String.'],
            ],
        ]],
    ]);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'content-type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $antwort = curl_exec($ch);
    curl_close($ch);
    if (!is_string($antwort)) {
        return $leer;
    }
    $j = json_decode($antwort, true);
    $text = (string)($j['content'][0]['text'] ?? '');
    if (preg_match('/\{.*\}/s', $text, $m)) {
        $e = json_decode($m[0], true);
        if (is_array($e)) {
            return [
                'name'     => mb_substr(trim((string)($e['name'] ?? '')), 0, 60),
                'weingut'  => mb_substr(trim((string)($e['weingut'] ?? '')), 0, 60),
                'rebsorte' => mb_substr(trim((string)($e['rebsorte'] ?? '')), 0, 80),
            ];
        }
    }
    return $leer;
}

/**
 * Kontaktdaten eines Weinguts per KI-Websuche ermitteln.
 * Liefert einen kurzen Textblock oder '' (kein Schlüssel/kein Treffer).
 */
function weingutKontaktErmitteln(string $name): string
{
    $keyDatei = __DIR__ . '/daten/apikey.php';
    if (!is_file($keyDatei)) {
        return '';
    }
    $key = (string)(require $keyDatei);
    if ($key === '' || !function_exists('curl_init')) {
        return '';
    }
    @set_time_limit(120);
    $body = json_encode([
        'model'      => 'claude-haiku-4-5',
        'max_tokens' => 700,
        'tools'      => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 3]],
        'messages'   => [[
            'role'    => 'user',
            'content' => 'Suche im Web die Kontaktdaten des Champagner-Erzeugers/Weinguts "' . $name . '" (Region Champagne, Frankreich). '
                . 'Antworte NUR mit einem kurzen Textblock in diesem Format (Zeilen ohne gesicherte Angabe einfach weglassen, keine Einleitung, keine Erklärungen):' . "\n"
                . 'Adresse: …' . "\n" . 'Telefon: …' . "\n" . 'E-Mail: …' . "\n" . 'Website: …' . "\n" . 'Besuch/Verkostung: …',
        ]],
    ]);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_HTTPHEADER     => [
            'content-type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $antwort = curl_exec($ch);
    curl_close($ch);
    if (!is_string($antwort)) {
        return '';
    }
    $j = json_decode($antwort, true);
    // Bei Websuchen zerfällt die Antwort in mehrere Textblöcke (Zitate) –
    // alles hinter dem letzten Suchergebnis zusammensetzen.
    $bloecke = (array)($j['content'] ?? []);
    $letzteSuche = -1;
    foreach ($bloecke as $i => $block) {
        if (($block['type'] ?? '') === 'web_search_tool_result') {
            $letzteSuche = $i;
        }
    }
    $teile = [];
    foreach ($bloecke as $i => $block) {
        if ($i > $letzteSuche && ($block['type'] ?? '') === 'text') {
            $teile[] = (string)($block['text'] ?? '');
        }
    }
    return mb_substr(trim(implode('', $teile)), 0, 900);
}

/** GPS-Koordinaten aus den EXIF-Daten eines Fotos lesen (oder null). */
function gpsAusFoto(string $pfad): ?array
{
    if (!function_exists('exif_read_data') || !is_file($pfad)) {
        return null;
    }
    $exif = @exif_read_data($pfad);
    if (!is_array($exif) || !isset($exif['GPSLatitude'], $exif['GPSLongitude'])) {
        return null;
    }
    $umrechnen = static function (array $teile, string $ref): float {
        $wert = static function ($r): float {
            $p = explode('/', (string)$r);
            return isset($p[1]) && (float)$p[1] !== 0.0 ? (float)$p[0] / (float)$p[1] : (float)$r;
        };
        $dez = $wert($teile[0] ?? '0') + $wert($teile[1] ?? '0') / 60 + $wert($teile[2] ?? '0') / 3600;
        return in_array(strtoupper($ref), ['S', 'W'], true) ? -$dez : $dez;
    };
    $lat = $umrechnen((array)$exif['GPSLatitude'], (string)($exif['GPSLatitudeRef'] ?? 'N'));
    $lon = $umrechnen((array)$exif['GPSLongitude'], (string)($exif['GPSLongitudeRef'] ?? 'E'));
    if (abs($lat) < 0.0001 && abs($lon) < 0.0001) {
        return null;
    }
    return [$lat, $lon];
}

/** Adresse/Ort zu Koordinaten ermitteln (OpenStreetMap Nominatim); '' wenn nichts gefunden. */
function standortErmitteln(float $lat, float $lon): string
{
    if (!function_exists('curl_init')) {
        return '';
    }
    $url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=18&accept-language=de'
        . '&lat=' . rawurlencode((string)$lat) . '&lon=' . rawurlencode((string)$lon);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['User-Agent: fruthzeug.de Champagne26 (post@fruthzeug.de)'],
    ]);
    $antwort = curl_exec($ch);
    curl_close($ch);
    if (!is_string($antwort)) {
        return '';
    }
    $j = json_decode($antwort, true);
    $name    = trim((string)($j['name'] ?? ''));
    $anzeige = trim((string)($j['display_name'] ?? ''));
    if ($anzeige === '') {
        return '';
    }
    return $name !== '' && !str_starts_with($anzeige, $name) ? $name . ', ' . $anzeige : $anzeige;
}

/** Kurzer Ortsname (Ort, Land) zu Koordinaten; '' wenn nicht ermittelbar. */
function ortKurz(float $lat, float $lon): string
{
    if (!function_exists('curl_init')) {
        return '';
    }
    $url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=12&accept-language=de'
        . '&lat=' . rawurlencode((string)$lat) . '&lon=' . rawurlencode((string)$lon);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_HTTPHEADER     => ['User-Agent: fruthzeug.de Champagne26 (tasting@fruthzeug.de)'],
    ]);
    $antwort = curl_exec($ch);
    curl_close($ch);
    if (!is_string($antwort)) {
        return '';
    }
    $a = json_decode($antwort, true)['address'] ?? [];
    $ort = (string)($a['village'] ?? $a['town'] ?? $a['city'] ?? $a['municipality'] ?? $a['county'] ?? '');
    $land = (string)($a['country'] ?? '');
    if ($ort === '' && $land === '') {
        return '';
    }
    return $ort . ($ort !== '' && $land !== '' ? ', ' : '') . $land;
}

/** Aktuelles Wetter am Standort (Open-Meteo, ohne Schlüssel); '' wenn nicht ermittelbar. */
function wetterErmitteln(float $lat, float $lon): string
{
    if (!function_exists('curl_init')) {
        return '';
    }
    $url = 'https://api.open-meteo.com/v1/forecast?latitude=' . rawurlencode((string)$lat)
        . '&longitude=' . rawurlencode((string)$lon) . '&current=temperature_2m,weather_code';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $antwort = curl_exec($ch);
    curl_close($ch);
    if (!is_string($antwort)) {
        return '';
    }
    $j = json_decode($antwort, true);
    $temp = $j['current']['temperature_2m'] ?? null;
    if ($temp === null) {
        return '';
    }
    $c = (int)($j['current']['weather_code'] ?? -1);
    $text = match (true) {
        $c === 0            => '☀️ klar',
        $c >= 1 && $c <= 2  => '🌤️ heiter',
        $c === 3            => '☁️ bewölkt',
        $c >= 45 && $c <= 48 => '🌫️ neblig',
        $c >= 51 && $c <= 57 => '🌦️ Niesel',
        $c >= 61 && $c <= 67 => '🌧️ Regen',
        $c >= 71 && $c <= 77 => '🌨️ Schnee',
        $c >= 80 && $c <= 82 => '🌦️ Schauer',
        $c >= 85 && $c <= 86 => '🌨️ Schneeschauer',
        $c >= 95            => '⛈️ Gewitter',
        default             => '🌡️',
    };
    return $text . ', ' . round((float)$temp) . ' °C';
}

/** Gespeicherte Koordinaten eines Weinguts (aus dem Karten-Link im Kontakt) oder null. */
function weingutKoordinaten(array $w): ?array
{
    if (preg_match('/mlat=(-?[0-9.]+)&(?:amp;)?mlon=(-?[0-9.]+)/', (string)($w['kontakt'] ?? ''), $m)) {
        return [(float)$m[1], (float)$m[2]];
    }
    return null;
}

/** Das gespeicherte Weingut, dessen Koordinaten zum Punkt passen (Umkreis in Metern), oder null. */
function weingutPerKoordinaten(array $daten, float $lat, float $lon, int $maxMeter = 150): ?array
{
    $bestes = null;
    $besteDistanz = PHP_INT_MAX;
    foreach ($daten['weingueter'] as $w) {
        $k = weingutKoordinaten($w);
        if ($k === null) {
            continue;
        }
        $dx = ($k[1] - $lon) * cos(deg2rad($lat)) * 111320;
        $dy = ($k[0] - $lat) * 110540;
        $dist = (int)round(sqrt($dx * $dx + $dy * $dy));
        if ($dist <= $maxMeter && $dist < $besteDistanz) {
            $bestes = $w;
            $besteDistanz = $dist;
        }
    }
    return $bestes;
}

/** In OpenStreetMap verzeichnete Weingüter/Weinhändler im Umkreis (sortiert nach Entfernung). */
function weingutKandidatenOSM(float $lat, float $lon): array
{
    if (!function_exists('curl_init')) {
        return [];
    }
    $q = '[out:json][timeout:15];('
        . 'nwr(around:200,' . $lat . ',' . $lon . ')["craft"="winery"];'
        . 'nwr(around:200,' . $lat . ',' . $lon . ')["shop"~"^(wine|alcohol)$"];'
        . 'nwr(around:200,' . $lat . ',' . $lon . ')["name"~"[Cc]hampagne"];'
        . ');out center tags 20;';
    $ch = curl_init('https://overpass-api.de/api/interpreter');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'data=' . rawurlencode($q),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['User-Agent: fruthzeug.de Champagne26 (post@fruthzeug.de)'],
    ]);
    $antwort = curl_exec($ch);
    curl_close($ch);
    if (!is_string($antwort)) {
        return [];
    }
    $j = json_decode($antwort, true);
    $kandidaten = [];
    foreach ((array)($j['elements'] ?? []) as $e) {
        $name = trim((string)($e['tags']['name'] ?? ''));
        $eLat = (float)($e['lat'] ?? ($e['center']['lat'] ?? 0));
        $eLon = (float)($e['lon'] ?? ($e['center']['lon'] ?? 0));
        if ($name === '' || ($eLat === 0.0 && $eLon === 0.0)) {
            continue;
        }
        // grobe Distanz in Metern (für kleine Abstände ausreichend)
        $dx = ($eLon - $lon) * cos(deg2rad($lat)) * 111320;
        $dy = ($eLat - $lat) * 110540;
        $dist = (int)round(sqrt($dx * $dx + $dy * $dy));
        // Riesige Regions-Polygone (z. B. Landschaft "Champagne crayeuse") aussortieren
        if ($dist > 250) {
            continue;
        }
        $kandidaten[] = ['name' => $name, 'dist' => $dist];
    }
    usort($kandidaten, static fn(array $a, array $b): int => $a['dist'] <=> $b['dist']);
    return $kandidaten;
}

/** Per KI-Websuche ermitteln, welcher Erzeuger an einem Standort sitzt; '' wenn unklar. */
function weingutNameErmitteln(string $standort, float $lat, float $lon, array $kandidaten = []): string
{
    $keyDatei = __DIR__ . '/daten/apikey.php';
    if ($standort === '' || !is_file($keyDatei)) {
        return '';
    }
    $key = (string)(require $keyDatei);
    if ($key === '' || !function_exists('curl_init')) {
        return '';
    }
    @set_time_limit(120);
    $hinweise = '';
    if ($kandidaten !== []) {
        $teile = array_map(
            static fn(array $k): string => '„' . $k['name'] . '“ (' . $k['dist'] . ' m entfernt)',
            array_slice($kandidaten, 0, 5)
        );
        $hinweise = ' Laut OpenStreetMap gibt es in der Nähe: ' . implode(', ', $teile) . '.';
    }
    $body = json_encode([
        'model'      => 'claude-haiku-4-5',
        'max_tokens' => 400,
        'tools'      => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 3]],
        'messages'   => [[
            'role'    => 'user',
            'content' => 'An diesem Ort wurde ein Foto aufgenommen: "' . $standort . '" (GPS ' . round($lat, 5) . ', ' . round($lon, 5) . ').' . $hinweise
                . ' Welcher Champagner-Erzeuger (Weingut/Champagnerhaus) hat an genau dieser Adresse oder unmittelbar daneben seinen Sitz bzw. seine Verkaufsstelle? '
                . 'In so einem kleinen Umkreis gibt es in der Regel genau einen Erzeuger – suche im Web nach der Adresse und nenne den wahrscheinlichsten, auch wenn du nicht hundertprozentig sicher bist. '
                . 'Antworte NUR mit JSON in genau dieser Form: {"weingut":"..."} – leer nur dann, wenn wirklich nichts dafür spricht, dass dort ein Erzeuger sitzt.',
        ]],
    ]);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_HTTPHEADER     => [
            'content-type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $antwort = curl_exec($ch);
    curl_close($ch);
    if (!is_string($antwort)) {
        return '';
    }
    $j = json_decode($antwort, true);
    $bloecke = (array)($j['content'] ?? []);
    $letzteSuche = -1;
    foreach ($bloecke as $i => $block) {
        if (($block['type'] ?? '') === 'web_search_tool_result') {
            $letzteSuche = $i;
        }
    }
    $text = '';
    foreach ($bloecke as $i => $block) {
        if ($i > $letzteSuche && ($block['type'] ?? '') === 'text') {
            $text .= (string)($block['text'] ?? '');
        }
    }
    if (preg_match('/\{.*\}/s', $text, $m)) {
        $e = json_decode($m[0], true);
        if (is_array($e)) {
            return mb_substr(trim((string)($e['weingut'] ?? '')), 0, 60);
        }
    }
    return '';
}

/** Allgemeine KI-Websuche: liefert die Textantwort oder '' (kein Schlüssel/kein Ergebnis). */
function claudeWebsuche(string $auftrag, int $maxTokens = 800): string
{
    $keyDatei = __DIR__ . '/daten/apikey.php';
    if (!is_file($keyDatei)) {
        return '';
    }
    $key = (string)(require $keyDatei);
    if ($key === '' || !function_exists('curl_init')) {
        return '';
    }
    @set_time_limit(120);
    $body = json_encode([
        'model'      => 'claude-haiku-4-5',
        'max_tokens' => $maxTokens,
        'tools'      => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 4]],
        'messages'   => [['role' => 'user', 'content' => $auftrag]],
    ]);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_HTTPHEADER     => [
            'content-type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $antwort = curl_exec($ch);
    curl_close($ch);
    if (!is_string($antwort)) {
        return '';
    }
    $j = json_decode($antwort, true);
    $bloecke = (array)($j['content'] ?? []);
    $letzteSuche = -1;
    foreach ($bloecke as $i => $block) {
        if (($block['type'] ?? '') === 'web_search_tool_result') {
            $letzteSuche = $i;
        }
    }
    $text = '';
    foreach ($bloecke as $i => $block) {
        if ($i > $letzteSuche && ($block['type'] ?? '') === 'text') {
            $text .= (string)($block['text'] ?? '');
        }
    }
    return mb_substr(trim($text), 0, 1500);
}

/** Kontakt-Text fürs Anzeigen: escapen, Links klickbar machen, Zeilenumbrüche. */
function verlinken(string $text): string
{
    $esc = e($text);
    $esc = preg_replace('~(https?://[^\s<]+)~', '<a href="$1" target="_blank" rel="noopener">$1</a>', $esc) ?? $esc;
    $esc = preg_replace('~(?<![/\w])(www\.[^\s<]+)~', '<a href="https://$1" target="_blank" rel="noopener">$1</a>', $esc) ?? $esc;
    return nl2br($esc);
}

/** Anmeldeformular; $weiter = Query der aktuellen Seite (z. B. "?ergebnis=abc"), um dorthin zurückzukehren. */
function loginFormular(string $weiter = ''): string
{
    $csrf = e((string)$_SESSION['csrf']);
    $weiterFeld = $weiter === '' ? '' : '<input type="hidden" name="weiter" value="' . e($weiter) . '">';
    return '<h2>Zum Mitmachen anmelden</h2>
      <form method="post">
        <input type="hidden" name="csrf" value="' . $csrf . '">
        <input type="hidden" name="aktion" value="login">' . $weiterFeld . '
        <input type="password" name="passwort" placeholder="Passwort" autocomplete="current-password" required>
        <label style="display:flex; align-items:flex-start; gap:0.55rem; margin:0.6rem 0 0.8rem; font-size:0.92rem; cursor:pointer;">
          <input type="checkbox" name="agb" value="ja" required style="width:auto; margin-top:0.2rem; flex-shrink:0;">
          <span>Ich bin mindestens 18 Jahre alt und akzeptiere die <a href="?nutzung=1" target="_blank">Nutzungsbedingungen</a> von TasteLog. <b>(Pflicht)</b></span>
        </label>
        <button class="knopf" type="submit">Anmelden</button>
      </form>';
}

function preisZeile(array $c): string
{
    $teile = [];
    $rebsorte = trim((string)($c['rebsorte'] ?? ''));
    if ($rebsorte !== '') {
        $teile[] = e($rebsorte);
    }
    $preis = trim((string)($c['preis'] ?? ''));
    if ($preis !== '') {
        $teile[] = e($preis);
    }
    return $teile === [] ? '' : ' &middot; ' . implode(' &middot; ', $teile);
}

function champagnerHolen(array $daten, string $id): ?array
{
    foreach ($daten['champagner'] as $c) {
        if ($c['id'] === $id) {
            return $c;
        }
    }
    return null;
}

/**
 * Leitet aus den geführten Detail-Antworten die 8 Kategorie-Sterne (1–5) ab.
 * Muss deckungsgleich mit der JS-Vorschau (sterneAusDetailJS) sein.
 */
function sterneAusDetail(array $d): array
{
    $smiley = ['top' => 5, 'gut' => 4, 'ok' => 3, 'geht' => 2];
    $anzahlAromen = is_array($d['aromen'] ?? null) ? count($d['aromen']) : 0;
    // Korkfehler → alles Minimum
    if (($d['sauber'] ?? '') === 'kork') {
        return array_fill_keys(['duft','perlage','geschmack','balance','komplexitaet','abgang','besonderheit','trinkfreude'], 1);
    }
    $komplex = $anzahlAromen >= 8 ? 5 : ($anzahlAromen >= 6 ? 4 : ($anzahlAromen >= 3 ? 3 : ($anzahlAromen >= 1 ? 2 : 3)));
    return [
        'duft'         => $smiley[$d['duft'] ?? ''] ?? 3,
        'perlage'      => ['fein' => 5, 'mittel' => 4, 'grob' => 2][$d['perlage'] ?? ''] ?? 3,
        'geschmack'    => $smiley[$d['geschmack'] ?? ''] ?? 3,
        'balance'      => ['perfekt' => 5, 'stimmig' => 4, 'unrund' => 3, 'schlecht' => 2][$d['balance'] ?? ''] ?? 3,
        'komplexitaet' => $komplex,
        'abgang'       => ['lang' => 5, 'mittel' => 4, 'kurz' => 2][$d['abgang'] ?? ''] ?? 3,
        'besonderheit' => ['unverwechselbar' => 5, 'hatwas' => 4, 'austauschbar' => 2][$d['charakter'] ?? ''] ?? 3,
        'trinkfreude'  => ['sofort' => 5, 'gerne' => 4, 'muss_nicht' => 3, 'nein' => 1][$d['nochmal'] ?? ''] ?? 3,
    ];
}

// Welche Ansicht?
$ansicht = 'verkosten'; // Startseite: Wo verkostest du?
$aktiverChampagner = null;
$aktivesWeingut = null;
$kontextWeingut = null;   // Arbeitsfläche: aktives Weingut ('ohne' = zu Hause/ohne Weingut)
$kontextOhne = false;
if (isset($_GET['bewerten'])) {
    $aktiverChampagner = champagnerHolen($daten, (string)$_GET['bewerten']);
    if ($aktiverChampagner !== null) {
        $ansicht = 'bewerten';
    }
} elseif (isset($_GET['ergebnis'])) {
    $aktiverChampagner = champagnerHolen($daten, (string)$_GET['ergebnis']);
    if ($aktiverChampagner !== null) {
        $ansicht = 'ergebnis';
    }
} elseif (isset($_GET['weingut'])) {
    $aktivesWeingut = weingutHolen($daten, (string)$_GET['weingut']);
    if ($aktivesWeingut !== null) {
        $ansicht = 'weingut';
    }
} elseif (isset($_GET['weingueter'])) {
    $ansicht = 'weingueter';
} elseif (isset($_GET['fotos'])) {
    $ansicht = 'fotos';
} elseif (isset($_GET['neu'])) {
    $ansicht = 'neu';
} elseif (isset($_GET['wneu'])) {
    $ansicht = 'wneu';
} elseif (isset($_GET['vk'])) {
    if ((string)$_GET['vk'] === 'ohne') {
        $kontextOhne = true;
        $ansicht = 'werkstatt';
        $_SESSION['vk_zuletzt'] = 'ohne'; // fürs „aktuelle Weingut“ in der Status-Zeile
    } else {
        $kontextWeingut = weingutHolen($daten, (string)$_GET['vk']);
        if ($kontextWeingut !== null) {
            $ansicht = 'werkstatt';
            $_SESSION['vk_zuletzt'] = $kontextWeingut['id'];
        }
    }
} elseif (isset($_GET['liste'])) {
    $ansicht = 'liste';
} elseif (isset($_GET['tasting'])) {
    $ansicht = 'tastingplatz';
    $aktivesTasting = null;
    if (preg_match('/^[a-f0-9]{8}$/', (string)$_GET['tasting'])) {
        foreach ($daten['tastings'] as $t) {
            if ($t['id'] === (string)$_GET['tasting']) {
                $aktivesTasting = $t;
                break;
            }
        }
    }
    // Ein geöffnetes Tasting wird zum „aktuellen“ – Header und Listen folgen
    if ($aktivesTasting !== null && (string)($_COOKIE['tasting_aktuell'] ?? '') !== $aktivesTasting['id']) {
        tastingWaehlen($aktivesTasting['id']);
    }
    // Ältere Tastings ohne Beitritts-Code nachrüsten
    if ($aktivesTasting !== null && ($aktivesTasting['beitritt'] ?? '') === '') {
        $nachruestToken = bin2hex(random_bytes(8));
        $nachruestId = $aktivesTasting['id'];
        datenAendern(function (array $d) use ($nachruestId, $nachruestToken): array {
            foreach ($d['tastings'] as &$t) {
                if ($t['id'] === $nachruestId && ($t['beitritt'] ?? '') === '') {
                    $t['beitritt'] = $nachruestToken;
                }
            }
            return $d;
        });
        $aktivesTasting['beitritt'] = $nachruestToken;
    }
} elseif (isset($_GET['beitritt'])) {
    $ansicht = 'beitreten';
} elseif (isset($_GET['verwaltung'])) {
    $ansicht = 'verwaltung';
} elseif (isset($_GET['meine'])) {
    $ansicht = 'meine';
} elseif (isset($_GET['konto'])) {
    $ansicht = 'konto';
} elseif (isset($_GET['ueber'])) {
    $ansicht = 'ueber';
} elseif (isset($_GET['nutzung'])) {
    $ansicht = 'nutzung';
} elseif (isset($_GET['impressum'])) {
    $ansicht = 'impressum';
}
if ($einladungFrage !== null) {
    $ansicht = 'einladung_frage'; // Einladung zuerst bestätigen lassen
}

// Bereich für die Tab-Leiste unten
$bereich = match ($ansicht) {
    'verkosten', 'werkstatt', 'neu', 'wneu', 'bewerten' => 'verkosten',
    'tastingplatz', 'beitreten', 'verwaltung' => 'tasting',
    default => 'entdecken',
};

$personVorschlag = (string)($_SESSION['person'] ?? '');
$sortierung = ($_GET['sort'] ?? 'datum') === 'name' ? 'name' : 'datum';

// Getränke-Kategorien: Champagner ist aktiv, die anderen sind vorbereitet
$KATEGORIEN_GETRAENKE = [
    'champagner' => ['🍾', 'Champagner', true],
    'rotwein'    => ['🍷', 'Rotwein', true],
    'weisswein'  => ['🥂', 'Weißwein', true],
    'bier'       => ['🍺', 'Bier', true],
];
$kategorie = (string)($_GET['kat'] ?? 'champagner');
if ($kategorie !== 'alle' && !isset($KATEGORIEN_GETRAENKE[$kategorie])) {
    $kategorie = 'champagner';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title>TasteLog</title>
  <link rel="icon" type="image/png" sizes="192x192" href="icon-192.png">
  <link rel="icon" type="image/png" sizes="512x512" href="icon-512.png">
  <link rel="apple-touch-icon" sizes="180x180" href="icon-180.png">
  <link rel="manifest" href="manifest.webmanifest">
  <meta name="apple-mobile-web-app-title" content="TasteLog">
  <meta name="theme-color" content="#faf6ee">
  <style>
    :root {
      --bg: #faf6ee;
      --text: #2b2620;
      --muted: #8a8073;
      --card: #ffffff;
      --border: #ecdfc8;
      --gold: #c8900f;
      --gold-hell: #fdf3dc;
      --violett: #7a5cc4;
      --violett-hell: #f1ecfb;
      --gruen: #14997d;
      --gruen-hell: #e3f6f0;
      --accent: var(--gold);
      --accent-hell: var(--gold-hell);
      --stern: #e0a411;
      --ok: #14997d;
      --warn: #cf3f2e;
      --schatten: 0 2px 10px rgba(90, 70, 30, 0.07);
    }
    @media (prefers-color-scheme: dark) {
      :root {
        --bg: #191613;
        --text: #efe9df;
        --muted: #a59a89;
        --card: #242019;
        --border: #3c352a;
        --gold: #e8b544;
        --gold-hell: #33290f;
        --violett: #a98ff0;
        --violett-hell: #251e38;
        --gruen: #3cc9a7;
        --gruen-hell: #10312a;
        --stern: #e8b544;
        --ok: #3cc9a7;
        --warn: #ef8a80;
        --schatten: 0 2px 10px rgba(0, 0, 0, 0.35);
      }
    }
    body[data-bereich="entdecken"] { --accent: var(--violett); --accent-hell: var(--violett-hell); }
    body[data-bereich="tasting"]   { --accent: var(--gruen); --accent-hell: var(--gruen-hell); }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
      background: var(--bg);
      color: var(--text);
      line-height: 1.6;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }
    a { color: var(--accent); }

    /* ---------- Tab-Leiste unten ---------- */
    .tab-leiste {
      position: fixed; left: 0; right: 0; bottom: 0; z-index: 50;
      display: flex; justify-content: space-around;
      background: var(--card); border-top: 1px solid var(--border);
      padding: 0.35rem 0 calc(0.35rem + env(safe-area-inset-bottom));
      box-shadow: 0 -2px 10px rgba(0,0,0,0.06);
    }
    .tab-leiste a {
      display: flex; flex-direction: column; align-items: center; gap: 1px;
      flex: 1; text-decoration: none; color: var(--muted);
      font-size: 0.72rem; padding: 0.25rem 0.4rem; border-radius: 12px;
      -webkit-tap-highlight-color: transparent;
    }
    .tab-leiste a .tab-icon { font-size: 1.45rem; line-height: 1.2; }
    .tab-leiste a.aktiv { color: var(--accent); }
    .tab-leiste a.aktiv .tab-icon {
      background: var(--accent-hell); border-radius: 999px; padding: 2px 14px;
    }
    main { padding-bottom: 5.5rem !important; }
    body.listenansicht main { padding-bottom: 4.2rem !important; }

    /* ---------- Verkosten-Startseite & Arbeitsfläche ---------- */
    .kachel {
      display: flex; align-items: center; gap: 1rem;
      background: var(--card); border: 1px solid var(--border); border-radius: 16px;
      box-shadow: var(--schatten);
      padding: 1.1rem 1.2rem; margin-bottom: 0.8rem;
      text-decoration: none; color: var(--text); font-size: 1.05rem;
      -webkit-tap-highlight-color: transparent;
    }
    .kachel:active { transform: scale(0.98); }
    .kachel .k-icon {
      font-size: 1.7rem; width: 52px; height: 52px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      background: var(--accent-hell); border-radius: 14px;
    }
    .kachel .k-text b { font-weight: 600; display: block; }
    .kachel .k-text small { color: var(--muted); }

    /* ---------- Overlay (Weingut-Details) ---------- */
    #overlay {
      position: fixed; inset: 0; z-index: 90;
      background: rgba(0,0,0,0.45);
      display: flex; align-items: flex-end; justify-content: center;
    }
    #overlay[hidden] { display: none; }
    #overlay .blatt {
      background: var(--bg); width: 100%; max-width: 46rem;
      max-height: 92dvh; overflow-y: auto; -webkit-overflow-scrolling: touch;
      border-radius: 18px 18px 0 0; padding: 1rem 1.2rem 3rem;
    }
    #overlay .blatt-kopf {
      display: flex; justify-content: space-between; align-items: center;
      position: sticky; top: 0; background: var(--bg); padding: 0.3rem 0 0.6rem; z-index: 2;
    }
    #overlay .blatt-kopf button {
      background: var(--card); border: 1px solid var(--border); border-radius: 50%;
      width: 40px; height: 40px; font-size: 1.15rem; cursor: pointer; color: var(--text);
    }

    .site-header {
      position: sticky; top: 0; z-index: 10;
      display: flex; align-items: center; gap: 1rem;
      padding: 0.85rem 1.2rem;
      background: var(--card); border-bottom: 1px solid var(--border);
    }
    .brand { margin-right: auto; font-size: 1.15rem; color: var(--text); text-decoration: none; }
    .brand strong { color: var(--accent); font-weight: normal; }
    /* Status-Zeile: Wer · Tasting · Weingut · Getränk – bleibt immer oben stehen */
    .status-zeile {
      flex: 1; min-width: 0; display: flex; gap: 0.8rem; align-items: center;
      overflow-x: auto; white-space: nowrap; font-size: 0.88rem;
      scrollbar-width: none; -webkit-overflow-scrolling: touch;
    }
    .status-zeile::-webkit-scrollbar { display: none; }
    .status-zeile span, .status-zeile a { flex-shrink: 0; color: var(--text); text-decoration: none; }
    .status-zeile a { color: var(--accent); }
    /* Burger-Menü auf allen Bildschirmgrößen */
    #nav-toggle { display: none; }
    .burger { display: flex; flex-direction: column; justify-content: center; gap: 5px; padding: 8px; cursor: pointer; -webkit-tap-highlight-color: transparent; }
    .burger span { display: block; width: 26px; height: 3px; background: var(--text); border-radius: 2px; transition: transform 0.25s, opacity 0.25s; }
    nav.site-nav {
      display: none; position: absolute; top: 100%; left: 0; right: 0;
      flex-direction: column; gap: 0; background: var(--card);
      border-bottom: 1px solid var(--border); box-shadow: 0 8px 16px rgba(0,0,0,0.08);
      max-height: calc(100vh - 70px); max-height: calc(100dvh - 70px);
      overflow-y: auto; -webkit-overflow-scrolling: touch;
    }
    nav.site-nav a { color: var(--text); text-decoration: none; padding: 0.95rem 1.4rem; border-bottom: 1px solid var(--border); }
    nav.site-nav a:hover { color: var(--accent); }
    /* Untermenüs im Burger-Menü: native Klappgruppen */
    nav.site-nav details { border-bottom: 1px solid var(--border); }
    nav.site-nav details summary {
      list-style: none; cursor: pointer; padding: 0.95rem 1.4rem; color: var(--text);
      display: flex; justify-content: space-between; align-items: center;
    }
    nav.site-nav details summary::-webkit-details-marker { display: none; }
    nav.site-nav details summary::after { content: '▸'; color: var(--muted); font-size: 0.85em; }
    nav.site-nav details[open] summary::after { content: '▾'; }
    nav.site-nav details a { padding-left: 2.6rem; background: var(--bg); display: block; }
    nav.site-nav details a:last-child { border-bottom: none; }
    #nav-toggle:checked ~ nav.site-nav { display: flex; }
    #nav-toggle:checked ~ .burger span:nth-child(1) { transform: translateY(8px) rotate(45deg); }
    #nav-toggle:checked ~ .burger span:nth-child(2) { opacity: 0; }
    #nav-toggle:checked ~ .burger span:nth-child(3) { transform: translateY(-8px) rotate(-45deg); }

    main { flex: 1; width: 100%; max-width: 46rem; margin: 0 auto; padding: 1rem 1rem 4.2rem; }
    .zurueck { margin-bottom: 0.7rem; }
    .zurueck a { text-decoration: none; font-size: 1.05rem; }
    h1 { font-size: clamp(1.35rem, 4.5vw, 1.9rem); font-weight: normal; margin-bottom: 0.25rem; }
    .untertitel { color: var(--muted); font-style: italic; margin-bottom: 0.8rem; }

    .hinweis { border-radius: 8px; padding: 0.8rem 1.1rem; margin-bottom: 1.4rem; border: 1px solid var(--border); background: var(--card); }
    .hinweis.ok { border-left: 4px solid var(--ok); }
    .hinweis.fehler { border-left: 4px solid var(--warn); }

    .card {
      background: var(--card); border: 1px solid var(--border);
      border-radius: 10px; padding: 0.95rem 1.05rem; margin-bottom: 0.6rem;
    }
    .card h2 { font-size: 1.15rem; font-weight: normal; color: var(--accent); margin-bottom: 0.5rem; }
    /* Einheitlich: jede Karten-Überschrift klappt ihre Karte auf/zu */
    .card > h2 { cursor: pointer; -webkit-tap-highlight-color: transparent; user-select: none; }
    .card > h2::before { content: '▾ '; font-size: 0.85em; }
    .card.zu > h2 { margin-bottom: 0; }
    .card.zu > h2::before { content: '▸ '; }
    .card.zu > :not(h2) { display: none !important; }

    .champagner-zeile { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }
    .champagner-zeile .info { flex: 1; min-width: 12rem; }
    .champagner-zeile .name { font-size: 1.15rem; }
    .sterne-anzeige { color: var(--stern); letter-spacing: 2px; white-space: nowrap; }
    .sterne-anzeige .wert { color: var(--muted); font-size: 0.9rem; letter-spacing: normal; }
    .leer-sterne { color: var(--muted); font-style: italic; font-size: 0.9rem; letter-spacing: normal; }
    .anzahl { color: var(--muted); font-size: 0.9rem; }

    .knopfreihe { display: flex; gap: 0.6rem; flex-wrap: wrap; }
    a.knopf, button.knopf {
      display: inline-block; text-decoration: none; text-align: center;
      background: var(--accent); color: #fff; border: none; border-radius: 8px;
      padding: 0.55rem 1.1rem; font-size: 0.95rem; cursor: pointer; font-family: inherit;
    }
    a.knopf.zweit, button.knopf.zweit {
      background: transparent; color: var(--accent); border: 1px solid var(--accent);
    }
    a.knopf:active, button:active {
      transform: scale(0.96);
      filter: brightness(0.85);
    }
    a.knopf, button { transition: transform 0.08s, filter 0.08s; }
    button[disabled] { opacity: 0.55; cursor: wait; }
    @keyframes pulsieren { 50% { opacity: 0.4; } }
    button.laedt { animation: pulsieren 1s infinite; }
    button.loeschen { background: none; border: none; color: var(--muted); text-decoration: underline; cursor: pointer; font-size: 0.85rem; font-family: inherit; }

    /* ---------- Geführte Bewertung ---------- */
    .wz-kopf { display: flex; align-items: center; justify-content: space-between; gap: 0.6rem; margin-bottom: 0.8rem; }
    .wz-kopf .wz-titel { font-size: 1.05rem; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    #wz-info-knopf {
      flex-shrink: 0; background: var(--accent-hell); color: var(--accent);
      border: none; border-radius: 999px; padding: 0.4rem 0.9rem;
      font-size: 0.9rem; font-weight: 600; cursor: pointer; font-family: inherit;
    }
    #anleitung-box, #tasting-bild-box, #profil-box { position: fixed; inset: 0; z-index: 95; background: rgba(0,0,0,0.45); display: flex; align-items: flex-end; justify-content: center; }
    #anleitung-box[hidden], #tasting-bild-box[hidden], #profil-box[hidden] { display: none !important; }
    #anleitung-box .blatt, #tasting-bild-box .blatt, #profil-box .blatt {
      background: var(--bg); width: 100%; max-width: 46rem; max-height: 90dvh;
      overflow-y: auto; -webkit-overflow-scrolling: touch; border-radius: 18px 18px 0 0; padding: 1rem 1.2rem 3rem;
    }
    #anleitung-box .blatt-kopf, #tasting-bild-box .blatt-kopf, #profil-box .blatt-kopf { display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; background: var(--bg); padding: 0.3rem 0 0.6rem; }
    #anleitung-box .blatt-kopf button, #tasting-bild-box .blatt-kopf button, #profil-box .blatt-kopf button { background: var(--card); border: 1px solid var(--border); border-radius: 50%; width: 40px; height: 40px; font-size: 1.15rem; cursor: pointer; color: var(--text); }
    .anleitung h3 { margin: 1.1rem 0 0.2rem; font-size: 1.1rem; color: var(--accent); }
    .anleitung p { margin-bottom: 0.4rem; }
    .anleitung-chips { display: flex; gap: 0.45rem; flex-wrap: wrap; margin-bottom: 0.8rem; }
    .anleitung-chips button {
      background: var(--card); color: var(--text); border: 1px solid var(--border);
      border-radius: 999px; padding: 0.4rem 0.85rem; font-size: 0.9rem; cursor: pointer; font-family: inherit;
    }
    .anleitung-chips button.gewaehlt { background: var(--accent-hell); color: var(--accent); border-color: var(--accent); font-weight: 600; }
    .bewerter-chip {
      display: inline-block; background: var(--accent-hell); color: var(--text);
      border-radius: 999px; padding: 0.25rem 0.75rem; margin: 0 0.3rem 0.35rem 0; font-size: 0.92rem;
    }
    .bewerter-chip.offen { background: var(--card); border: 1px dashed var(--border); color: var(--muted); }
    .sorten-chips { margin: -0.3rem 0 0.8rem; }
    .sorten-chips button.sorte {
      background: var(--card); color: var(--text); border: 1px solid var(--border);
      border-radius: 999px; padding: 0.35rem 0.8rem; margin: 0 0.35rem 0.4rem 0;
      font-size: 0.9rem; cursor: pointer; font-family: inherit;
    }
    .sorten-chips button.sorte.gewaehlt { background: var(--accent-hell); color: var(--accent); border-color: var(--accent); font-weight: 600; }
    .sorten-info { color: var(--muted); font-size: 0.88rem; margin: 0.2rem 0 0.4rem; }
    .kachel-raster { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.6rem; }
    .kachel-raster .kachel { margin: 0; }
    /* 📖 Der Trip: Zeitstrahl des Tastings */
    .trip { position: relative; padding-left: 1.3rem; }
    .trip::before { content: ''; position: absolute; left: 8px; top: 6px; bottom: 6px; width: 2px; background: var(--border); border-radius: 1px; }
    .trip-tag-zeile { position: relative; margin: 0.7rem 0 0.35rem; }
    details.trip-tag summary { list-style: none; cursor: pointer; padding: 0.15rem 1.8rem 0.15rem 0; font-size: 1rem; font-weight: 600; color: var(--accent); }
    details.trip-tag summary::-webkit-details-marker { display: none; }
    details.trip-tag summary::before { content: '▸ '; font-size: 0.85em; }
    details.trip-tag[open] summary::before { content: '▾ '; }
    .trip-karte { position: absolute; right: 0; top: 0.1rem; text-decoration: none; font-size: 1.15rem; }
    .trip-eintrag { position: relative; display: flex; gap: 0.6rem; align-items: center; margin-bottom: 0.55rem; text-decoration: none; color: var(--text); }
    .trip-eintrag::before { content: ''; position: absolute; left: -1.02rem; top: 50%; transform: translateY(-50%); width: 9px; height: 9px; border-radius: 50%; background: var(--accent); border: 2px solid var(--card); }
    .trip-eintrag img { width: 52px; height: 52px; border-radius: 10px; object-fit: cover; flex-shrink: 0; }
    .trip-icon { width: 52px; text-align: center; font-size: 1.5rem; flex-shrink: 0; }
    .trip-eintrag .k-text { min-width: 0; flex: 1; }
    .trip-eintrag .k-text small { display: block; color: var(--muted); font-size: 0.82rem; }
    .avatar { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; border: 1.5px solid var(--accent); vertical-align: middle; }
    details.card summary { cursor: pointer; color: var(--accent); font-size: 1.15rem; }
    details.card summary::-webkit-details-marker { display: none; }
    details.card summary::before { content: '▸ '; }
    details.card[open] summary { margin-bottom: 0.8rem; }
    details.card[open] summary::before { content: '▾ '; }
    #info-knopf {
      flex-shrink: 0; background: var(--accent-hell); color: var(--accent);
      border: none; border-radius: 999px; width: 34px; height: 34px;
      font-size: 1.05rem; cursor: pointer; font-family: inherit;
    }
    .wz-fortschritt { height: 6px; background: var(--border); border-radius: 4px; margin-bottom: 1.2rem; overflow: hidden; }
    #wz-balken { display: block; height: 100%; width: 20%; background: var(--accent); border-radius: 4px; transition: width 0.3s; }
    .wz-schritt h2 { font-size: 1.35rem; margin-bottom: 0.3rem; }
    .wz-frage { font-weight: 600; margin: 1.1rem 0 0.5rem; }
    .wz-frage small { font-weight: 400; color: var(--muted); }
    .wz-kacheln { display: grid; grid-template-columns: repeat(auto-fill, minmax(92px, 1fr)); gap: 0.5rem; }
    .wz-kacheln button {
      display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 3px;
      background: var(--card); border: 2px solid var(--border); border-radius: 14px;
      padding: 0.7rem 0.4rem; cursor: pointer; font-size: 1.4rem; color: var(--text);
      font-family: inherit; -webkit-tap-highlight-color: transparent; min-height: 74px;
    }
    .wz-kacheln button span { font-size: 0.72rem; line-height: 1.15; text-align: center; }
    .wz-kacheln button:active { transform: scale(0.95); }
    .wz-kacheln button.gewaehlt { border-color: var(--accent); background: var(--accent-hell); }
    .wz-label { display: block; font-weight: 600; margin: 0.9rem 0 0.3rem; }
    .wz-label small { font-weight: 400; color: var(--muted); }
    .wz-nav { display: flex; gap: 0.6rem; margin-top: 1.6rem; }
    .wz-nav .knopf { flex: 1; }
    #wz-sterne-vorschau .wz-stern-zeile {
      display: flex; align-items: center; justify-content: space-between; gap: 0.6rem;
      padding: 0.45rem 0; border-bottom: 1px solid var(--border);
    }
    #wz-sterne-vorschau .wz-stern-zeile:last-child { border-bottom: none; }
    #wz-sterne-vorschau .wz-sterne { font-size: 1.5rem; letter-spacing: 2px; white-space: nowrap; cursor: pointer; }
    #wz-sterne-vorschau .wz-sterne .an { color: var(--stern); }
    #wz-sterne-vorschau .wz-sterne .aus { color: var(--border); }

    .filter-feld {
      margin-bottom: 0.7rem;
      position: sticky; top: var(--filter-top, 0px); z-index: 5;
      background: var(--bg);
      box-shadow: 0 4px 8px -4px rgba(0,0,0,0.15);
    }
    input[type="password"], input[type="text"], input[type="number"], input[type="search"], input[type="file"], textarea, select {
      width: 100%; padding: 0.65rem; border: 1px solid var(--border); border-radius: 8px;
      background: var(--bg); color: var(--text); font-size: 1rem; margin-bottom: 0.8rem;
      font-family: inherit;
    }
    textarea { resize: vertical; }
    .thumb {
      width: 72px; height: 72px; object-fit: cover; border-radius: 8px;
      border: 1px solid var(--border); display: block; flex-shrink: 0;
    }
    .thumb.platzhalter {
      display: flex; align-items: center; justify-content: center;
      font-size: 1.9rem; background: var(--bg);
    }
    a.knopf.gross {
      display: block; text-align: center; font-size: 1.1rem;
      padding: 0.9rem 1.2rem; margin-bottom: 1.2rem;
    }
    a.knopf.klein { padding: 0.45rem 0.95rem; font-size: 0.9rem; white-space: nowrap; }
    .card.flasche {
      display: flex; align-items: center; gap: 0.9rem;
      padding: 0.9rem 1.1rem; margin-bottom: 0.7rem;
    }
    .flasche-link {
      flex: 1; min-width: 0; display: flex; align-items: center; gap: 0.9rem;
      text-decoration: none; color: var(--text);
    }
    .flasche-info { display: flex; flex-direction: column; min-width: 0; line-height: 1.45; }
    .f-name { font-size: 1.12rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .f-meta { color: var(--muted); font-size: 0.88rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .f-wertung { font-size: 0.95rem; }
    .foto-galerie {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
      gap: 0.6rem;
    }
    .album-foto .album-text {
      display: block; margin-top: 2px;
      font-size: 0.72rem; color: var(--muted); text-decoration: none;
      overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .foto {
      position: relative; border: 1px solid var(--border); border-radius: 8px;
      overflow: hidden; aspect-ratio: 1; background: var(--bg);
    }
    .foto img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .foto form { position: absolute; top: 6px; right: 6px; }
    .foto button {
      border: none; border-radius: 6px; padding: 4px 9px; cursor: pointer;
      background: rgba(0,0,0,0.55); color: #fff; font-size: 0.85rem;
    }
    .foto form.titelbild-form { left: 6px; right: auto; }
    .foto .titelbild-marke {
      position: absolute; top: 6px; left: 6px;
      background: var(--stern); color: #fff; border-radius: 6px;
      padding: 4px 9px; font-size: 0.85rem;
    }
    .sortier-leiste { color: var(--muted); font-size: 0.9rem; margin-bottom: 0.7rem; }
    .sortier-leiste a {
      color: var(--muted); text-decoration: none;
      padding: 2px 10px; border-radius: 999px; border: 1px solid var(--border);
      margin-left: 0.3rem; white-space: nowrap;
    }
    .sortier-leiste a.aktiv { color: var(--accent); border-color: var(--accent); }

    /* Listenansicht: Kopf bleibt stehen, nur die Einträge scrollen */
    body.listenansicht { height: 100vh; height: 100dvh; overflow: hidden; }
    body.listenansicht main {
      flex: 1 1 auto; min-height: 0;
      display: flex; flex-direction: column;
      padding-bottom: 0.5rem;
    }
    body.listenansicht main > * { flex-shrink: 0; }
    body.listenansicht h1 { font-size: clamp(1.4rem, 4vw, 1.9rem); }
    body.listenansicht .untertitel { margin-bottom: 1rem; }
    body.listenansicht .liste-scroll {
      flex: 1 1 auto; min-height: 0;
      overflow-y: auto;
      -webkit-overflow-scrolling: touch;
      margin: 0 -0.3rem;
      padding: 0.3rem 0.3rem 2rem;
    }
    body.listenansicht footer { display: none; }

    .foto-wahl { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.8rem; }
    .foto-wahl-item input { display: none; }
    .foto-wahl-item img {
      width: 72px; height: 72px; object-fit: cover; border-radius: 8px;
      border: 2px solid var(--border); cursor: pointer; display: block;
    }
    .foto-wahl-item input:checked + img {
      border-color: var(--accent);
      box-shadow: 0 0 0 2px var(--accent);
    }
    /* Großansicht (Lightbox) */
    #grossansicht {
      position: fixed; inset: 0; z-index: 100;
      background: rgba(0, 0, 0, 0.92);
      display: flex; align-items: center; justify-content: center;
      padding: 1.2rem;
    }
    #grossansicht[hidden] { display: none; }
    #grossansicht img {
      max-width: 100%; max-height: 100%;
      object-fit: contain; border-radius: 6px;
    }
    #grossansicht .schliessen {
      position: absolute; top: max(0.8rem, env(safe-area-inset-top)); right: 1rem;
      background: rgba(255, 255, 255, 0.15); color: #fff;
      border: none; border-radius: 50%;
      width: 44px; height: 44px; font-size: 1.5rem; line-height: 1;
      cursor: pointer;
    }
    #grossansicht .bild-info-knopf {
      position: absolute; bottom: max(1rem, env(safe-area-inset-bottom)); left: 50%; transform: translateX(-50%);
      background: rgba(255, 255, 255, 0.18); color: #fff;
      border: none; border-radius: 999px;
      padding: 0.55rem 1.1rem; font-size: 0.95rem; font-family: inherit;
      cursor: pointer;
    }
    #grossansicht .bild-info-blatt {
      position: absolute; left: 50%; transform: translateX(-50%);
      bottom: calc(max(1rem, env(safe-area-inset-bottom)) + 3.2rem);
      width: min(92vw, 26rem); max-height: 55vh; overflow-y: auto;
      background: var(--card); color: var(--text);
      border-radius: 14px; padding: 0.9rem 1rem;
      box-shadow: 0 8px 30px rgba(0, 0, 0, 0.4);
      text-align: left; font-size: 0.92rem;
    }
    #grossansicht .bild-info-blatt[hidden] { display: none; }
    #grossansicht .bild-info-blatt .bi-zeile { padding: 0.25rem 0; border-bottom: 1px solid var(--border); }
    #grossansicht .bild-info-blatt .bi-zeile:last-child { border-bottom: none; }
    #grossansicht .bild-info-blatt b { color: var(--accent); font-weight: 600; margin-right: 0.3rem; }

    .notiz { padding: 0.4rem 0; border-bottom: 1px solid var(--border); }
    .notiz:last-of-type { border-bottom: none; }
    .notiz b { font-weight: normal; color: var(--accent); }

    /* Sterne-Eingabe (5 Radios, rückwärts angeordnet) */
    .kategorie { padding: 0.9rem 0; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }
    .kategorie:last-of-type { border-bottom: none; }
    .kategorie .frage { flex: 1; min-width: 14rem; }
    .kategorie .frage b { font-weight: normal; font-size: 1.05rem; }
    .kategorie .frage small { display: block; color: var(--muted); line-height: 1.45; }
    .sterne { display: inline-flex; flex-direction: row-reverse; }
    .sterne input { display: none; }
    .sterne label { font-size: 2rem; color: var(--border); cursor: pointer; padding: 0 0.12rem; transition: color 0.1s; user-select: none; -webkit-tap-highlight-color: transparent; }
    .sterne input:checked ~ label { color: var(--stern); }
    @media (hover: hover) {
      .sterne label:hover, .sterne label:hover ~ label { color: var(--stern); }
    }

    table { border-collapse: collapse; width: 100%; font-size: 0.92rem; }
    th, td { padding: 0.45rem 0.6rem; border-bottom: 1px solid var(--border); text-align: center; white-space: nowrap; }
    th:first-child, td:first-child { text-align: left; }
    thead th { color: var(--muted); font-weight: normal; font-style: italic; }
    .tabelle-scroll { overflow-x: auto; }

    .ergebnis-kategorie { display: flex; justify-content: space-between; gap: 1rem; padding: 0.45rem 0; border-bottom: 1px solid var(--border); }
    .ergebnis-kategorie:last-of-type { border-bottom: none; }

    .gesamt { text-align: center; padding: 1rem 0 0.6rem; }
    .gesamt .sterne-anzeige { font-size: 1.7rem; }
    .gesamt .anzahl { display: block; }

    .abmelden { margin-top: 0.9rem; font-size: 0.9rem; }
    .abmelden button { background: none; border: none; color: var(--muted); text-decoration: underline; cursor: pointer; font-size: 0.9rem; font-family: inherit; }

    footer { text-align: center; padding: 2rem 1.5rem; color: var(--muted); font-size: 0.9rem; border-top: 1px solid var(--border); }
  </style>
</head>
<body data-bereich="<?= e($bereich) ?>"<?= in_array($ansicht, ['liste', 'weingueter'], true) ? ' class="listenansicht"' : '' ?>>
  <header class="site-header">
    <?php if ($eingeloggt): ?>
      <?php
        // Immer sichtbare Status-Zeile: Wer bin ich, mein Tasting, mein Weingut, mein Getränk
        $kopfTasting = meinTasting($daten);
        $kopfGlas = $kopfTasting !== null ? champagnerHolen($daten, (string)($kopfTasting['aktiv_cid'] ?? '')) : null;
        $kopfVk = (string)($_SESSION['vk_zuletzt'] ?? '');
        $kopfWeingut = $kopfVk !== '' && $kopfVk !== 'ohne' ? weingutHolen($daten, $kopfVk) : null;
        $kopfPerson = trim((string)($_SESSION['person'] ?? ''));
      ?>
      <div class="status-zeile">
        <?php $kopfProfil = $kopfPerson !== '' ? profilFoto($daten, $kopfPerson) : ''; ?>
        <a href="?meine=1" style="display:inline-flex; align-items:center; gap:0.3rem;">
          <?php if ($kopfProfil !== ''): ?>
            <img class="avatar" src="<?= e(thumbUrl($kopfProfil)) ?>" alt="" style="width:26px; height:26px;">
          <?php else: ?>
            👤
          <?php endif; ?><?= e($kopfPerson !== '' ? $kopfPerson : 'Gast') ?></a>
        <?php if ($kopfTasting !== null): ?>
          <a href="?tasting=<?= e(rawurlencode($kopfTasting['id'])) ?>">👥&nbsp;<?= e($kopfTasting['titel']) ?></a>
        <?php endif; ?>
        <?php if ($kopfWeingut !== null): ?>
          <a href="?vk=<?= e(rawurlencode($kopfWeingut['id'])) ?>">🍇&nbsp;<?= e($kopfWeingut['name']) ?></a>
        <?php elseif ($kopfVk === 'ohne'): ?>
          <a href="?vk=ohne">🏠&nbsp;ohne Weingut</a>
        <?php endif; ?>
        <?php if ($kopfGlas !== null): ?>
          <a href="?bewerten=<?= e(rawurlencode($kopfGlas['id'])) ?>">🥂&nbsp;<?= e($kopfGlas['name']) ?></a>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <a class="brand" href="./" style="display:flex; align-items:center; gap:0.45rem; text-decoration:none;"><img src="icon-192.png" alt="" style="width:30px; height:30px;"><strong>TasteLog</strong></a>
    <?php endif; ?>
    <button type="button" id="info-knopf" aria-label="Anleitung: So wird verkostet" title="So wird verkostet">ℹ️</button>
    <input type="checkbox" id="nav-toggle" aria-hidden="true">
    <label for="nav-toggle" class="burger" aria-label="Menü öffnen">
      <span></span><span></span><span></span>
    </label>
    <nav class="site-nav">
      <a href="./">🥂 Verkosten</a>
      <a href="?meine=1">📖 Meine Liste</a>
      <a href="?liste=1">🔍 Entdecken</a>
      <a href="?tasting=1">👥 Tastings</a>
      <a href="?konto=1">👤 Benutzerkonto</a>
      <details>
        <summary>ℹ️ Hilfe &amp; Info</summary>
        <a href="#" class="anleitung-oeffnen">ℹ️ So wird verkostet</a>
        <a href="?ueber=1">💛 Über TasteLog</a>
        <a href="mailto:tasting@fruthzeug.de">✉️ Kontakt</a>
      </details>
      <details>
        <summary>⚖️ Rechtliches</summary>
        <a href="?nutzung=1">📜 Nutzungsbedingungen</a>
        <a href="?impressum=1">⚖️ Impressum &amp; Datenschutz</a>
      </details>
      <?php if ($istAdmin): ?>
        <details>
          <summary>🛠️ Administration</summary>
          <a href="?weingueter=1&amp;alle=1">🍇 Alle Weingüter</a>
          <a href="?fotos=1&amp;alle=1">📸 Alle Fotos</a>
          <a href="?verwaltung=1">🛠️ Verwaltung</a>
        </details>
      <?php endif; ?>
      <a href="#" id="neu-laden">&#10227; Neu laden</a>
      <a href="/">fruthzeug.de</a>
    </nav>
  </header>

  <main>
    <?php if ($ansicht === 'verkosten'): ?>
      <div style="text-align:center; margin:0.4rem 0 0.9rem;">
        <img src="logo.png" alt="TasteLog" style="width:min(52vw, 15rem); height:auto; background:#fff; border-radius:16px; padding:0.7rem 1rem; box-shadow:0 2px 10px rgba(0,0,0,0.05);">
      </div>
      <p class="untertitel" style="text-align:center;">Dein Verkostungsbuch – wo verkostest du gerade?</p>
    <?php elseif ($ansicht === 'werkstatt'): ?>
      <p class="zurueck"><a href="./">&larr; Anderes Weingut w&auml;hlen</a></p>
      <h1><?= $kontextOhne ? 'Ohne Weingut 🏠' : e($kontextWeingut['name']) . ' 🍇' ?></h1>
      <p class="untertitel"><?= $kontextOhne ? 'Zu Hause oder unterwegs verkosten.' : 'Du bist hier – verkoste und erfasse die Weine.' ?></p>
    <?php elseif ($ansicht === 'tastingplatz'): ?>
      <?php if (($aktivesTasting ?? null) !== null): ?>
        <p class="zurueck"><a href="?tasting=1">&larr; Alle Tastings</a></p>
        <?php $tsKopfFotos = mitTitelbild(tastingFotos($aktivesTasting['id']), (string)($aktivesTasting['titelbild'] ?? '')); ?>
        <h1 style="display:flex; align-items:center; gap:0.6rem;">
          <button type="button" id="tasting-bild-knopf" title="Bild & Fotos vom Tasting" style="border:none; background:none; padding:0; cursor:pointer; flex-shrink:0;">
            <?php if ($tsKopfFotos !== []): ?>
              <img src="<?= e(thumbUrl($tsKopfFotos[0])) ?>" alt="" style="width:46px; height:46px; border-radius:50%; object-fit:cover; border:2px solid var(--accent); display:block;">
            <?php else: ?>
              <span style="width:46px; height:46px; border-radius:50%; border:2px dashed var(--border); display:flex; align-items:center; justify-content:center; font-size:1.2rem;">📷</span>
            <?php endif; ?>
          </button>
          <span id="tasting-titel-tipp" style="cursor:pointer;"><?= e($aktivesTasting['titel']) ?> 👥</span>
        </h1>
        <p class="untertitel">📅 angelegt am <?= date('d.m.Y', (int)($aktivesTasting['zeit'] ?? time())) ?></p>
      <?php else: ?>
        <h1>Tasting 👥</h1>
        <p class="untertitel">Gemeinsame Verkostungen mit deiner Gruppe.</p>
      <?php endif; ?>
    <?php elseif ($ansicht === 'beitreten'): ?>
      <h1>Mitmachen 🥂</h1>
      <p class="untertitel">Du wurdest zu einem Tasting eingeladen.</p>
    <?php elseif ($ansicht === 'verwaltung'): ?>
      <p class="zurueck"><a href="?tasting=1">&larr; Zur Tasting-&Uuml;bersicht</a></p>
      <h1>Verwaltung 🛠️</h1>
      <p class="untertitel">Benutzer, Getränke und Weingüter – nur für Administratoren.</p>
    <?php elseif ($ansicht === 'meine'): ?>
      <h1>Meine Verkostungen 📖</h1>
      <p class="untertitel">Dein persönliches Verkostungsbuch – alles, was du probiert und bewertet hast.</p>
    <?php elseif ($ansicht === 'konto'): ?>
      <h1>Benutzerkonto 👤</h1>
      <p class="untertitel">Anmelden, registrieren oder abmelden.</p>
    <?php elseif ($ansicht === 'ueber'): ?>
      <h1>Über TasteLog 🥂</h1>
      <p class="untertitel">Warum es diese App gibt – und was sie für dich tut.</p>
    <?php elseif ($ansicht === 'nutzung'): ?>
      <h1>Nutzungsbedingungen 📜</h1>
      <p class="untertitel">Für TasteLog · Stand: Juli 2026</p>
    <?php elseif ($ansicht === 'impressum'): ?>
      <h1>Impressum &amp; Datenschutz ⚖️</h1>
      <p class="untertitel">Angaben gemäß § 5 DDG · Datenschutzerklärung</p>
    <?php elseif ($ansicht === 'einladung_frage'): ?>
      <h1>Willkommen bei TasteLog 🥂</h1>
      <p class="untertitel">Du wurdest zu einem Tasting eingeladen.</p>
    <?php elseif ($ansicht === 'liste'): ?>
      <h1>Entdecken 🔍</h1>
      <p class="untertitel">Alle verkosteten Getränke im Überblick.</p>
    <?php elseif ($ansicht === 'weingueter'): ?>
      <h1>Weingüter 🍇</h1>
      <p class="untertitel">Die Erzeuger hinter den Flaschen – mit Notizen und Bildern.</p>
    <?php elseif ($ansicht === 'fotos'): ?>
      <h1>Fotoalbum 📸</h1>
      <p class="untertitel">Alle Fotos eurer Verkostungen.</p>
    <?php elseif ($ansicht === 'neu'): ?>
      <p class="zurueck"><a href="<?= isset($_GET['vk']) ? '?vk=' . e(rawurlencode((string)$_GET['vk'])) : '?liste=1' ?>">&larr; Zur&uuml;ck</a></p>
      <h1>Neue Flasche 📷</h1>
      <p class="untertitel">Fotografieren – erkennen – bewerten.</p>
    <?php elseif ($ansicht === 'wneu'): ?>
      <p class="zurueck"><a href="<?= isset($_GET['vkmodus']) ? './' : '?weingueter=1' ?>">&larr; Zur&uuml;ck</a></p>
      <h1>Neues Weingut 📷</h1>
      <p class="untertitel">Fotografieren – Standort erkennen – anlegen.</p>
    <?php elseif ($ansicht === 'weingut'): ?>
      <p class="zurueck"><a href="?weingueter=1">&larr; Zur&uuml;ck zur Weingut-Liste</a></p>
      <h1><?= e($aktivesWeingut['name']) ?></h1>
      <p class="untertitel">Weingut</p>
    <?php elseif ($ansicht === 'bewerten'): ?>
      <p class="zurueck"><a href="?ergebnis=<?= e(rawurlencode($aktiverChampagner['id'])) ?>">&larr; Zur&uuml;ck zu <?= e($aktiverChampagner['name']) ?></a></p>
      <h1><?= e($aktiverChampagner['name']) ?></h1>
      <p class="untertitel">Deine persönliche Bewertung<?= preisZeile($aktiverChampagner) ?></p>
    <?php else: ?>
      <p class="zurueck"><a href="?liste=1">&larr; Zur&uuml;ck zur Liste</a></p>
      <h1><?= e($aktiverChampagner['name']) ?></h1>
      <?php
        $cTastingTitel = '';
        foreach ($daten['tastings'] as $t) {
            if ($t['id'] === (string)($aktiverChampagner['tasting_id'] ?? '')) {
                $cTastingTitel = $t['titel'];
                break;
            }
        }
      ?>
      <p class="untertitel">Ergebnis der Verkostung<?= preisZeile($aktiverChampagner) ?><?= $cTastingTitel !== '' ? ' &middot; 👥 ' . e($cTastingTitel) : '' ?></p>
    <?php endif; ?>

    <?php if ($meldung !== ''): ?>
      <div class="hinweis ok"><?= e($meldung) ?></div>
    <?php endif; ?>
    <?php if ($fehler !== ''): ?>
      <div class="hinweis fehler"><?= e($fehler) ?></div>
    <?php endif; ?>

    <?php
      // "Gerade im Glas" der eigenen Gruppe oben anpinnen (Verkosten + Arbeitsfläche)
      if (in_array($ansicht, ['verkosten', 'werkstatt'], true)) {
          $meins = meinTasting($daten);
          $imGlas = $meins !== null ? champagnerHolen($daten, (string)($meins['aktiv_cid'] ?? '')) : null;
          if ($imGlas !== null) {
              echo '<div class="card" style="border-left:5px solid var(--accent); display:flex; align-items:center; gap:0.8rem; justify-content:space-between; flex-wrap:wrap;">'
                  . '<span>🥂 <b>Ihr trinkt gerade:</b> ' . e($imGlas['name']) . '<br><span class="anzahl">' . e($meins['titel']) . '</span></span>'
                  . '<a class="knopf" href="?bewerten=' . e(rawurlencode($imGlas['id'])) . '">' . (meineBewertung($daten, $imGlas['id']) !== null ? '🔁 Nochmal' : 'Bewerten') . '</a>'
                  . '</div>';
          }
      }
    ?>
    <?php if ($ansicht === 'verkosten'): ?>
      <!-- ==================== VERKOSTEN-START ==================== -->
      <a class="kachel" href="?vk=ohne">
        <span class="k-icon">🏠</span>
        <span class="k-text"><b>Ohne Weingut verkosten</b><small>Zu Hause, im Restaurant, unterwegs</small></span>
      </a>
      <?php
        // Nur die Weingüter des aktuellen Tastings – alles andere gehört ins Archiv
        $tastingWgIds = weingutIdsImTasting($daten, $blickTastingId);
        $wgSortiert = array_values(array_filter($daten['weingueter'], fn($w) => isset($tastingWgIds[$w['id']])));
        usort($wgSortiert, fn(array $x, array $y): int => ((int)($y['zeit'] ?? 0)) <=> ((int)($x['zeit'] ?? 0)));
      ?>
      <?php if ($wgSortiert !== []): ?>
        <p class="untertitel" style="margin-top:1.4rem;">… oder Weingut deines Tastings wählen:</p>
        <?php if (count($wgSortiert) > 3): ?>
          <input type="search" class="filter-feld" placeholder="🔍 Weingut suchen …" data-ziel="#wg-wahl">
        <?php endif; ?>
        <div id="wg-wahl">
        <?php foreach ($wgSortiert as $w): ?>
          <?php $wFotos = mitTitelbild(weingutFotos($w['id']), (string)($w['titelbild'] ?? '')); ?>
          <a class="kachel filterbar" href="?vk=<?= e(rawurlencode($w['id'])) ?>">
            <?php if ($wFotos !== []): ?>
              <img class="thumb" src="<?= e(thumbUrl($wFotos[0])) ?>" alt="" loading="lazy" style="width:52px;height:52px;border-radius:14px;">
            <?php else: ?>
              <span class="k-icon">🍇</span>
            <?php endif; ?>
            <span class="k-text"><b><?= e($w['name']) ?></b>
              <small><?= count(array_filter($daten['champagner'], fn($c) => ($c['weingut_id'] ?? '') === $w['id'] && champagnerInTasting($c, $blickTastingId))) ?> Champagner erfasst</small>
            </span>
          </a>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if ($istAdmin && count($daten['weingueter']) > count($wgSortiert)): ?>
        <p class="anzahl" style="margin-top:0.6rem;"><a href="?weingueter=1&amp;alle=1">Alle Weingüter ansehen (Archiv)</a></p>
      <?php endif; ?>
      <?php if (!$eingeloggt): ?>
        <div class="card" style="margin-top:1.2rem;"><?= loginFormular() ?></div>
      <?php endif; ?>

    <?php elseif ($ansicht === 'werkstatt'): ?>
      <!-- ==================== ARBEITSFLÄCHE (Weingut-Kontext) ==================== -->
      <a class="kachel" href="?neu=1<?= $kontextOhne ? '&amp;vk=ohne' : '&amp;vk=' . e(rawurlencode($kontextWeingut['id'])) ?>" style="border-left:5px solid var(--accent);">
        <span class="k-icon">📷</span>
        <span class="k-text"><b>Champagner verkosten</b><small>Etikett fotografieren – erkennen – bewerten</small></span>
      </a>
      <?php if (!$kontextOhne): ?>
        <a class="kachel" href="#" id="details-oeffnen" data-url="?weingut=<?= e(rawurlencode($kontextWeingut['id'])) ?>">
          <span class="k-icon">ℹ️</span>
          <span class="k-text"><b>Weingut-Details</b><small>Kontakt, Notizen, Bilder, Recherche</small></span>
        </a>
      <?php endif; ?>

      <?php
        $hierChampagner = array_values(array_filter(
            $daten['champagner'],
            fn($c) => champagnerInTasting($c, $blickTastingId)
                && ($kontextOhne ? trim((string)($c['weingut_id'] ?? '')) === '' : ($c['weingut_id'] ?? '') === $kontextWeingut['id'])
        ));
        usort($hierChampagner, fn(array $x, array $y): int => ((int)$y['zeit']) <=> ((int)$x['zeit']));
      ?>
      <?php if ($hierChampagner !== []): ?>
        <p class="untertitel" style="margin-top:1.4rem;"><?= $kontextOhne ? 'Champagner ohne Weingut:' : 'Bisher hier verkostet:' ?></p>
        <?php if (count($hierChampagner) > 3): ?>
          <input type="search" class="filter-feld" placeholder="🔍 Champagner suchen …" data-ziel="#werkstatt-liste">
        <?php endif; ?>
        <div id="werkstatt-liste">
        <?php foreach ($hierChampagner as $c): ?>
          <?php
            $bewertungen = bewertungenFuer($daten, $c['id']);
            $gesamt      = gesamtSchnitt($bewertungen);
            $fotos       = mitTitelbild(fotosFuer($c['id']), (string)($c['titelbild'] ?? ''));
          ?>
          <div class="card flasche filterbar">
            <a class="flasche-link" href="?ergebnis=<?= e(rawurlencode($c['id'])) ?>">
              <?php if ($fotos !== []): ?>
                <img class="thumb" src="<?= e(thumbUrl($fotos[0])) ?>" alt="" loading="lazy">
              <?php else: ?>
                <span class="thumb platzhalter">🍾</span>
              <?php endif; ?>
              <span class="flasche-info">
                <span class="f-name"><?= e($c['name']) ?></span>
                <span class="f-wertung"><?= sterneAnzeige($gesamt) ?><?= $bewertungen !== [] ? ' <span class="anzahl">(' . count($bewertungen) . ')</span>' : '' ?></span>
              </span>
            </a>
            <a class="knopf klein" href="?bewerten=<?= e(rawurlencode($c['id'])) ?>&amp;vk=<?= $kontextOhne ? 'ohne' : e(rawurlencode($kontextWeingut['id'])) ?>"><?= meineBewertung($daten, $c['id']) !== null ? '🔁 Nochmal' : 'Bewerten' ?></a>
          </div>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if (!$eingeloggt): ?>
        <div class="card" style="margin-top:1.2rem;"><?= loginFormular($kontextOhne ? '?vk=ohne' : '?vk=' . rawurlencode($kontextWeingut['id'])) ?></div>
      <?php endif; ?>

    <?php elseif ($ansicht === 'tastingplatz' && ($aktivesTasting ?? null) !== null): ?>
      <!-- ==================== TASTING-DETAIL ==================== -->
      <?php if (!$eingeloggt): ?>
        <div class="card"><?= loginFormular('?tasting=' . rawurlencode($aktivesTasting['id'])) ?></div>
      <?php else: ?>
        <?php
          $glasChampagner = champagnerHolen($daten, (string)($aktivesTasting['aktiv_cid'] ?? ''));
          $tsAlleFotos = $tsKopfFotos; // fürs Overlay (oben beim Titel berechnet)
        ?>
        <div class="card" style="border-left:5px solid var(--accent);">
          <h2>🥂 Was wir gerade trinken</h2>
          <?php if ($glasChampagner !== null): ?>
            <?php
              $glasWg = weingutHolen($daten, (string)($glasChampagner['weingut_id'] ?? ''));
              $glasFotos = mitTitelbild(fotosFuer($glasChampagner['id']), (string)($glasChampagner['titelbild'] ?? ''));
            ?>
            <div style="display:flex; align-items:center; gap:0.7rem;">
              <?php if ($glasFotos !== []): ?>
                <img class="thumb" src="<?= e(thumbUrl($glasFotos[0])) ?>" alt="" style="width:54px; height:54px; border-radius:12px; object-fit:cover; flex-shrink:0;">
              <?php endif; ?>
              <p style="font-size:1.05rem;"><b><?= e($glasChampagner['name']) ?></b>
                <?php if ($glasWg !== null): ?><br><span class="anzahl">🍇 <?= e($glasWg['name']) ?></span><?php endif; ?>
              </p>
            </div>
            <?php
              // Wer hat das Glas schon bewertet, wer fehlt noch? (aktualisiert sich live)
              $glasBewertungen = bewertungenFuer($daten, $glasChampagner['id']);
              $glasBewertetVon = array_map(fn($b) => mb_strtolower(trim((string)$b['person'])), $glasBewertungen);
            ?>
            <p style="margin:0.5rem 0 0.2rem;">
              <?php foreach ($glasBewertungen as $gb): ?>
                <span class="bewerter-chip">✅ <?= e($gb['person']) ?></span>
              <?php endforeach; ?>
              <?php foreach (($aktivesTasting['teilnehmer'] ?? []) as $p): ?>
                <?php if (!in_array(mb_strtolower(trim((string)$p['name'])), $glasBewertetVon, true)): ?>
                  <span class="bewerter-chip offen">⏳ <?= e($p['name']) ?></span>
                <?php endif; ?>
              <?php endforeach; ?>
              <?php if ($glasBewertungen === [] && ($aktivesTasting['teilnehmer'] ?? []) === []): ?>
                <span class="anzahl">Noch keine Bewertung.</span>
              <?php endif; ?>
            </p>
            <div class="knopfreihe" style="margin-top:0.5rem;">
              <?php $meineGlasB = meineBewertung($daten, $glasChampagner['id']); ?>
              <a class="knopf" href="?bewerten=<?= e(rawurlencode($glasChampagner['id'])) ?>"><?= $meineGlasB !== null ? '🔁 Noch einmal bewerten' : 'Jetzt bewerten' ?></a>
              <?php if ($meineGlasB !== null): ?><span class="anzahl" style="align-self:center;">deine Wertung: <?= date('d.m., H:i', (int)($meineGlasB['zeit'] ?? 0)) ?> Uhr</span><?php endif; ?>
              <a class="knopf zweit" href="?ergebnis=<?= e(rawurlencode($glasChampagner['id'])) ?>">Ergebnis ansehen</a>
            </div>
          <?php else: ?>
            <p style="color:var(--muted); font-style:italic;">Noch nichts festgelegt – das zuletzt erfasste Getränk wird automatisch das aktuelle, oder unten von Hand wählen.</p>
          <?php endif; ?>
          <a class="knopf" href="?neu=1" style="margin-top:0.8rem; display:inline-block;">📷&nbsp; Neues Getränk erfassen</a>
          <?php
            // Liste statt Dropdown: Getränke des aktuellen Weinguts, sonst die letzten 7 – chronologisch
            $auswahl = array_values(array_filter($daten['champagner'], fn($c) => champagnerInTasting($c, (string)$aktivesTasting['id'])));
            usort($auswahl, fn(array $x, array $y): int => ((int)$y['zeit']) <=> ((int)$x['zeit']));
            $vkAktuell = (string)($_SESSION['vk_zuletzt'] ?? '');
            $listeTitel = 'Zuletzt erfasst:';
            $glasListe = $auswahl;
            if (preg_match('/^[a-f0-9]{8}$/', $vkAktuell)) {
                $wgAktuell = weingutHolen($daten, $vkAktuell);
                $vomWeingut = $wgAktuell !== null ? array_values(array_filter($auswahl, fn($c) => ($c['weingut_id'] ?? '') === $vkAktuell)) : [];
                if ($vomWeingut !== []) {
                    $glasListe = $vomWeingut;
                    $listeTitel = '🍇 ' . $wgAktuell['name'] . ':';
                }
            }
            $glasListe = array_slice($glasListe, 0, 7);
          ?>
          <?php if ($glasListe !== []): ?>
            <p class="anzahl" style="margin:0.7rem 0 0.3rem;"><?= e($listeTitel) ?> <span style="font-style:italic;">antippen fürs Ergebnis, 🥂 = „trinken wir gerade“</span></p>
            <?php foreach ($glasListe as $gc): ?>
              <?php
                $gcWg = weingutHolen($daten, (string)($gc['weingut_id'] ?? ''));
                $gcFotos = mitTitelbild(fotosFuer($gc['id']), (string)($gc['titelbild'] ?? ''));
                $gcBew = bewertungenFuer($daten, $gc['id']);
                $istAktuell = ($aktivesTasting['aktiv_cid'] ?? '') === $gc['id'];
              ?>
              <div style="display:flex; align-items:center; gap:0.55rem; border-bottom:1px solid var(--border); padding:0.4rem 0;<?= $istAktuell ? ' background:var(--accent-hell); border-radius:8px; padding-left:0.4rem;' : '' ?>">
                <a href="?ergebnis=<?= e(rawurlencode($gc['id'])) ?>" style="display:flex; align-items:center; gap:0.55rem; flex:1; min-width:0; text-decoration:none; color:var(--text);">
                  <?php if ($gcFotos !== []): ?>
                    <img src="<?= e(thumbUrl($gcFotos[0])) ?>" alt="" loading="lazy" style="width:40px; height:40px; border-radius:9px; object-fit:cover; flex-shrink:0;">
                  <?php else: ?>
                    <span style="width:40px; text-align:center; font-size:1.2rem; flex-shrink:0;"><?= $KATEGORIEN_GETRAENKE[(string)($gc['typ'] ?? 'champagner')][0] ?? '🍾' ?></span>
                  <?php endif; ?>
                  <span style="flex:1; min-width:0;"><b><?= e($gc['name']) ?></b><br>
                    <span class="anzahl"><?= date('d.m., H:i', (int)$gc['zeit']) ?><?= $gcWg !== null && $listeTitel === 'Zuletzt erfasst:' ? ' · ' . e($gcWg['name']) : '' ?> · <?= sterneAnzeige(gesamtSchnitt($gcBew)) ?></span>
                  </span>
                </a>
                <?php if ($istAktuell): ?>
                  <span title="trinken wir gerade">🥂</span>
                <?php else: ?>
                  <form method="post" style="margin:0;">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <input type="hidden" name="aktion" value="glas_setzen">
                    <input type="hidden" name="tasting_id" value="<?= e($aktivesTasting['id']) ?>">
                    <input type="hidden" name="champagner_id" value="<?= e($gc['id']) ?>">
                    <button type="submit" title="Als aktuelles Getränk festlegen" style="background:none; border:1px solid var(--border); border-radius:8px; padding:0.25rem 0.5rem; cursor:pointer; font-size:1rem;">🥂</button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <?php
          // Direkter Zugriff auf alles, was zu diesem Tasting gehört
          $tastingWeine = array_values(array_filter($daten['champagner'], fn($c) => champagnerInTasting($c, (string)$aktivesTasting['id'])));
          $tastingWeingutIds = array_unique(array_filter(array_map(fn($c) => trim((string)($c['weingut_id'] ?? '')), $tastingWeine)));
        ?>
        <?php
          // ---------- 📖 Der Trip: der zeitliche Verlauf des Tastings ----------
          // Stationen: Weingut-Ankunft (erstes Getränk dort), jedes Getränk, Gruppenfotos –
          // chronologisch, mit Bildern in der richtigen Reihenfolge.
          $trip = [];
          $wgErsterBesuch = [];
          foreach ($tastingWeine as $c) {
              $cFotos = mitTitelbild(fotosFuer($c['id']), (string)($c['titelbild'] ?? ''));
              $cBew = bewertungenFuer($daten, $c['id']);
              $cWg = weingutHolen($daten, (string)($c['weingut_id'] ?? ''));
              $trip[] = ['zeit' => (int)$c['zeit'], 'typ' => 'getraenk', 'c' => $c, 'foto' => $cFotos[0] ?? '', 'bew' => $cBew, 'wg' => $cWg];
              if ($cWg !== null) {
                  $wid = $cWg['id'];
                  if (!isset($wgErsterBesuch[$wid]) || (int)$c['zeit'] < $wgErsterBesuch[$wid]['zeit']) {
                      $wgErsterBesuch[$wid] = ['zeit' => (int)$c['zeit'] - 1, 'typ' => 'weingut', 'w' => $cWg];
                  }
              }
          }
          foreach ($wgErsterBesuch as $einBesuch) {
              $trip[] = $einBesuch;
          }
          foreach ($tsAlleFotos as $tsF) {
              $pfad = BILDER_DIR . '/' . $tsF;
              $trip[] = ['zeit' => is_file($pfad) ? (int)filemtime($pfad) : 0, 'typ' => 'foto', 'datei' => $tsF];
          }
          usort($trip, fn($x, $y) => $x['zeit'] <=> $y['zeit']);

          // Nach Tagen gruppieren; je Tag den Bewegungsverlauf (Google-Maps-Route)
          // aus Weingut-Standorten, Bewertungs-Orten und Foto-GPS bauen
          $ptFuer = function (array $st): ?array {
              if ($st['typ'] === 'weingut') {
                  return weingutKoordinaten($st['w']);
              }
              if ($st['typ'] === 'getraenk') {
                  foreach ($st['bew'] as $rb) {
                      if (is_numeric($rb['lat'] ?? null) && is_numeric($rb['lon'] ?? null)) {
                          return [(float)$rb['lat'], (float)$rb['lon']];
                      }
                  }
                  return null;
              }
              return gpsAusFoto(BILDER_DIR . '/' . $st['datei']);
          };
          $tripTage = [];
          foreach ($trip as $st) {
              $tripTage[date('d.m.Y', $st['zeit'])][] = $st;
          }
          $tagRoute = [];
          foreach ($tripTage as $tag => $stationen) {
              $punkte = [];
              foreach ($stationen as $st) {
                  $pt = $ptFuer($st);
                  if ($pt === null) {
                      continue;
                  }
                  $letzt = $punkte === [] ? null : $punkte[count($punkte) - 1];
                  if ($letzt !== null && distanzMeter($letzt[0], $letzt[1], $pt[0], $pt[1]) < 100) {
                      continue;
                  }
                  $punkte[] = $pt;
              }
              if (count($punkte) > 23) {
                  $schritt = count($punkte) / 23;
                  $ausgeduennt = [];
                  for ($ri = 0.0; $ri < count($punkte); $ri += $schritt) {
                      $ausgeduennt[] = $punkte[(int)$ri];
                  }
                  $punkte = $ausgeduennt;
              }
              if (count($punkte) >= 2) {
                  $tagRoute[$tag] = 'https://www.google.com/maps/dir/' . implode('/', array_map(fn($pt) => $pt[0] . ',' . $pt[1], $punkte));
              } elseif (count($punkte) === 1) {
                  $tagRoute[$tag] = 'https://www.google.com/maps/search/?api=1&query=' . $punkte[0][0] . ',' . $punkte[0][1];
              }
          }
          $letzterTripTag = array_key_last($tripTage);
        ?>
        <?php if ($trip !== []): ?>
          <div class="card">
            <h2>📖 Der Trip – euer Verlauf</h2>
            <div class="trip">
              <?php foreach ($tripTage as $tag => $stationen): ?>
                <?php $zuAlt = $tag !== $letzterTripTag; // nur der aktuellste Tag startet offen ?>
                <div class="trip-tag-zeile">
                  <details class="trip-tag"<?= $zuAlt ? '' : ' open' ?>>
                    <summary><?= e($tag) ?> <span class="anzahl">(<?= count($stationen) ?>)</span></summary>
                    <div class="trip-stationen">
                <?php foreach ($stationen as $st): ?>
                  <?php if ($st['typ'] === 'weingut'): ?>
                    <?php $wFoto = mitTitelbild(weingutFotos($st['w']['id']), (string)($st['w']['titelbild'] ?? '')); ?>
                    <a class="trip-eintrag" href="?vk=<?= e(rawurlencode($st['w']['id'])) ?>">
                      <?php if ($wFoto !== []): ?><img src="<?= e(thumbUrl($wFoto[0])) ?>" alt="" loading="lazy"><?php else: ?><span class="trip-icon">🍇</span><?php endif; ?>
                      <span class="k-text"><b>🍇 <?= e($st['w']['name']) ?></b><small>Weingut besucht</small></span>
                    </a>
                  <?php elseif ($st['typ'] === 'getraenk'): ?>
                    <?php $g = gesamtSchnitt($st['bew']); ?>
                    <a class="trip-eintrag" href="?ergebnis=<?= e(rawurlencode($st['c']['id'])) ?>">
                      <?php if ($st['foto'] !== ''): ?><img src="<?= e(thumbUrl($st['foto'])) ?>" alt="" loading="lazy"><?php else: ?><span class="trip-icon"><?= $KATEGORIEN_GETRAENKE[(string)($st['c']['typ'] ?? 'champagner')][0] ?? '🍾' ?></span><?php endif; ?>
                      <span class="k-text"><b><?= e($st['c']['name']) ?></b>
                        <small><?= date('H:i', $st['zeit']) ?> Uhr<?= $st['wg'] !== null ? ' · ' . e($st['wg']['name']) : '' ?> · <?= sterneAnzeige($g) ?><?= $st['bew'] !== [] ? ' (' . count($st['bew']) . ')' : '' ?></small>
                      </span>
                    </a>
                  <?php else: ?>
                    <span class="trip-eintrag foto">
                      <a href="bilder/<?= e(rawurlencode($st['datei'])) ?>"><img src="<?= e(thumbUrl($st['datei'])) ?>" alt="" loading="lazy"></a>
                      <span class="k-text"><small>📸 Gruppenfoto, <?= date('H:i', $st['zeit']) ?> Uhr</small></span>
                    </span>
                  <?php endif; ?>
                <?php endforeach; ?>
                    </div>
                  </details>
                  <?php if (isset($tagRoute[$tag])): ?>
                    <a class="trip-karte" target="_blank" rel="noopener" href="<?= e($tagRoute[$tag]) ?>" title="Bewegungsverlauf dieses Tages in Google Maps">🗺️</a>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <details class="card">
            <summary>🍇 Unsere Weingüter (<?= count($tastingWeingutIds) ?>)</summary>
            <a class="knopf zweit" href="?wneu=1&amp;vkmodus=1" style="margin-top:0.5rem; display:inline-block;">📷&nbsp; Neues Weingut per Foto erkennen</a>
            <div style="margin-top:0.5rem;">
              <?php foreach ($daten['weingueter'] as $w): ?>
                <?php if (!isset(array_flip($tastingWeingutIds)[$w['id']])) { continue; } ?>
                <?php
                  $wFotos = mitTitelbild(weingutFotos($w['id']), (string)($w['titelbild'] ?? ''));
                  $wAnzahl = count(array_filter($tastingWeine, fn($c) => ($c['weingut_id'] ?? '') === $w['id']));
                ?>
                <a href="?vk=<?= e(rawurlencode($w['id'])) ?>" style="display:flex; align-items:center; gap:0.6rem; border-bottom:1px solid var(--border); padding:0.45rem 0; text-decoration:none; color:var(--text);">
                  <?php if ($wFotos !== []): ?>
                    <img src="<?= e(thumbUrl($wFotos[0])) ?>" alt="" loading="lazy" style="width:44px; height:44px; border-radius:10px; object-fit:cover; flex-shrink:0;">
                  <?php else: ?>
                    <span style="width:44px; text-align:center; font-size:1.3rem; flex-shrink:0;">🍇</span>
                  <?php endif; ?>
                  <span style="flex:1; min-width:0;"><b><?= e($w['name']) ?></b><br><span class="anzahl"><?= $wAnzahl ?> Getränk(e) hier verkostet · antippen zum Öffnen</span></span>
                </a>
              <?php endforeach; ?>
            </div>
        </details>

        <?php
          // 📸 Album des Tastings: alle Bilder (Flaschen, Weingüter, Gruppenfotos) chronologisch
          $albumBilder = [];
          foreach ($tastingWeine as $c) {
              foreach (fotosFuer($c['id']) as $f) { $albumBilder[$f] = true; }
          }
          foreach (array_keys($wgErsterBesuch) as $wid) {
              foreach (weingutFotos($wid) as $f) { $albumBilder[$f] = true; }
          }
          foreach ($tsAlleFotos as $f) { $albumBilder[$f] = true; }
          $albumBilder = array_keys($albumBilder);
          usort($albumBilder, fn($a, $b) => (is_file(BILDER_DIR . '/' . $a) ? filemtime(BILDER_DIR . '/' . $a) : 0) <=> (is_file(BILDER_DIR . '/' . $b) ? filemtime(BILDER_DIR . '/' . $b) : 0));
        ?>
        <?php if ($albumBilder !== []): ?>
          <details class="card">
            <summary>📸 Fotoalbum des Tastings (<?= count($albumBilder) ?>)</summary>
            <div class="foto-galerie" style="margin-top:0.6rem;">
              <?php foreach ($albumBilder as $ab): ?>
                <div class="foto">
                  <a href="bilder/<?= e(rawurlencode($ab)) ?>" target="_blank">
                    <img src="<?= e(thumbUrl($ab)) ?>" alt="" loading="lazy">
                  </a>
                </div>
              <?php endforeach; ?>
            </div>
          </details>
        <?php endif; ?>

        <?php
          $beitrittUrl = 'https://fruthzeug.de/projekte/champagner/?beitritt=' . (string)($aktivesTasting['beitritt'] ?? '');
          $ansehenUrl = 'https://fruthzeug.de/projekte/champagner/?liste=1&kat=alle&tid=' . rawurlencode($aktivesTasting['id']);
        ?>
        <details class="card">
          <summary>📱 Einladen &amp; Mitschauen (QR-Codes)</summary>
          <div style="display:flex; gap:1rem; flex-wrap:wrap; justify-content:center; text-align:center; margin-top:0.6rem;">
            <div>
              <p class="anzahl" style="margin-bottom:0.4rem;"><b>Mitmachen:</b> abfotografieren, Name eintragen, dabei sein.</p>
              <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&amp;margin=8&amp;data=<?= e(rawurlencode($beitrittUrl)) ?>"
                   alt="QR-Code zum Beitreten" width="200" height="200"
                   style="border-radius:12px; border:1px solid var(--border); background:#fff; max-width:100%;">
              <div class="knopfreihe" style="justify-content:center; margin-top:0.4rem;">
                <button type="button" class="knopf klein zweit link-kopieren" data-link="<?= e($beitrittUrl) ?>">Link kopieren</button>
              </div>
            </div>
            <div>
              <p class="anzahl" style="margin-bottom:0.4rem;"><b>Nur zuschauen:</b> sehen, aber nicht bewerten.</p>
              <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&amp;margin=8&amp;data=<?= e(rawurlencode($ansehenUrl)) ?>"
                   alt="QR-Code zum Mitschauen" width="200" height="200"
                   style="border-radius:12px; border:1px solid var(--border); background:#fff; max-width:100%;">
              <div class="knopfreihe" style="justify-content:center; margin-top:0.4rem;">
                <button type="button" class="knopf klein zweit link-kopieren" data-link="<?= e($ansehenUrl) ?>">Link kopieren</button>
              </div>
            </div>
          </div>
        </details>

        <?php
          // Getränke aus anderen Tastings, die man hierher holen kann
          $fremdeGetraenke = array_values(array_filter(
              $daten['champagner'],
              fn($c) => (string)($c['tasting_id'] ?? '') !== (string)$aktivesTasting['id']
          ));
          usort($fremdeGetraenke, fn(array $x, array $y): int => ((int)$y['zeit']) <=> ((int)$x['zeit']));
          $tastingTitel = [];
          foreach ($daten['tastings'] as $t) {
              $tastingTitel[$t['id']] = $t['titel'];
          }
        ?>
        <?php if ($fremdeGetraenke !== []): ?>
          <details class="card">
            <summary>➕ Getränk aus anderem Tasting hierher holen</summary>
            <form method="post" style="margin-top:0.6rem;">
              <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
              <input type="hidden" name="aktion" value="tasting_zuordnen">
              <input type="hidden" name="tasting_id" value="<?= e($aktivesTasting['id']) ?>">
              <select name="champagner_id" required>
                <option value="">– Getränk wählen –</option>
                <?php foreach (array_slice($fremdeGetraenke, 0, 60) as $fg): ?>
                  <?php $herkunft = $tastingTitel[(string)($fg['tasting_id'] ?? '')] ?? 'ohne Tasting'; ?>
                  <option value="<?= e($fg['id']) ?>"><?= e($fg['name']) ?> (<?= e($herkunft) ?>)</option>
                <?php endforeach; ?>
              </select>
              <button class="knopf zweit" type="submit">Hierher holen</button>
            </form>
          </details>
        <?php endif; ?>

        <!-- Overlay: Bild & Fotos des Tastings – öffnet sich per Tipp auf Titel/Avatar -->
        <div id="tasting-bild-box" hidden>
          <div class="blatt">
            <div class="blatt-kopf">
              <b>📸 Bild &amp; Fotos vom Tasting</b>
              <button type="button" aria-label="Schließen">&#10005;</button>
            </div>
            <?php if ($tsAlleFotos !== []): ?>
              <img src="<?= e(thumbUrl($tsAlleFotos[0])) ?>" alt="" style="width:100%; max-height:280px; object-fit:cover; border-radius:12px; display:block; margin-bottom:0.8rem;">
            <?php else: ?>
              <p class="anzahl" style="margin-bottom:0.8rem;">Noch kein Bild – gib deinem Tasting ein Gesicht, z. B. ein Gruppenbild oder die Flaschenreihe:</p>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data" style="margin-bottom:1rem;">
              <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
              <input type="hidden" name="aktion" value="tasting_foto_upload">
              <input type="hidden" name="tasting_id" value="<?= e($aktivesTasting['id']) ?>">
              <?= fotoUploadFelder('ts-titel') ?>
            </form>
            <?php if ($tsAlleFotos !== []): ?>
              <?php $tsTitelbild = (string)($aktivesTasting['titelbild'] ?? ''); ?>
              <div class="foto-galerie">
                <?php foreach ($tsAlleFotos as $i => $foto): ?>
                  <?php $istTitelbild = $tsTitelbild !== '' ? $foto === $tsTitelbild : $i === 0; ?>
                  <div class="foto">
                    <a href="bilder/<?= e(rawurlencode($foto)) ?>" target="_blank">
                      <img src="<?= e(thumbUrl($foto)) ?>" alt="" loading="lazy">
                    </a>
                    <?php if ($istTitelbild): ?>
                      <span class="titelbild-marke" title="Titelbild – erscheint beim Titel und in der Tasting-Liste">⭐</span>
                    <?php else: ?>
                      <form method="post" class="titelbild-form">
                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                        <input type="hidden" name="aktion" value="titelbild_setzen">
                        <input type="hidden" name="typ" value="tasting">
                        <input type="hidden" name="id" value="<?= e($aktivesTasting['id']) ?>">
                        <input type="hidden" name="datei" value="<?= e($foto) ?>">
                        <button type="submit" title="Als Titelbild festlegen">☆</button>
                      </form>
                    <?php endif; ?>
                    <form method="post" onsubmit="return confirm('Dieses Foto wirklich löschen?');">
                      <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                      <input type="hidden" name="aktion" value="tasting_foto_loeschen">
                      <input type="hidden" name="datei" value="<?= e($foto) ?>">
                      <button type="submit" title="Foto löschen">&#10005;</button>
                    </form>
                  </div>
                <?php endforeach; ?>
              </div>
              <?php if (count($tsAlleFotos) > 1): ?>
                <p class="anzahl" style="margin-top:0.5rem;">⭐ = Titelbild. Mit ☆ legst du ein anderes fest.</p>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>

        <?php
          // Aktiv = hat in diesem Tasting schon bewertet (Name kommt in Bewertungen vor)
          $aktivNamen = [];
          $aktivAnzeige = [];
          foreach ($daten['bewertungen'] as $b) {
              if (($b['tasting_id'] ?? '') === $aktivesTasting['id']) {
                  $ln = mb_strtolower($b['person']);
                  $aktivNamen[$ln] = true;
                  $aktivAnzeige[$ln] = $b['person'];
              }
          }
          $teiln = $aktivesTasting['teilnehmer'] ?? [];
          $teilnNamen = [];
          foreach ($teiln as $p) { $teilnNamen[mb_strtolower($p['name'])] = true; }
          $anzahlAktiv = count($aktivNamen);
          $passiv = array_filter($teiln, fn($p) => !isset($aktivNamen[mb_strtolower($p['name'])]));
          // Bewerter, die (noch) nicht in der Teilnehmerliste stehen
          $externAktiv = array_filter($aktivAnzeige, fn($n, $ln) => !isset($teilnNamen[$ln]), ARRAY_FILTER_USE_BOTH);
        ?>
        <div class="card">
          <h2>Teilnehmer</h2>
          <p class="anzahl" style="margin-bottom:0.8rem;">🟢 <?= $anzahlAktiv ?> aktiv (haben bewertet) · ⚪ <?= count($passiv) ?> passiv</p>
          <?php foreach ($externAktiv as $n): ?>
            <div class="ergebnis-kategorie" style="align-items:center;">
              <span>🟢 <b><?= e($n) ?></b> <span class="anzahl">hat bewertet</span></span>
            </div>
          <?php endforeach; ?>
          <?php foreach ($teiln as $p): ?>
            <?php
              $istAktiv = isset($aktivNamen[mb_strtolower($p['name'])]);
              $pFoto = profilFoto($daten, (string)$p['name']);
            ?>
            <div class="ergebnis-kategorie" style="align-items:center;">
              <span><?php if ($pFoto !== ''): ?><img class="avatar" src="<?= e(thumbUrl($pFoto)) ?>" alt="" style="width:28px;height:28px;"> <?php endif; ?><?= $istAktiv ? '🟢' : '⚪' ?> <b><?= e($p['name']) ?></b><?= $p['email'] !== '' ? ' <span class="anzahl">' . e($p['email']) . '</span>' : '' ?></span>
              <span class="knopfreihe">
                <button type="button" class="knopf klein zweit link-kopieren" data-link="https://fruthzeug.de/projekte/champagner/?einladung=<?= e($p['token']) ?>">Link kopieren</button>
                <?php if ($p['email'] !== ''): ?>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <input type="hidden" name="aktion" value="teilnehmer_mail">
                    <input type="hidden" name="tasting_id" value="<?= e($aktivesTasting['id']) ?>">
                    <input type="hidden" name="token" value="<?= e($p['token']) ?>">
                    <button class="knopf klein zweit" type="submit">✉️ Mail</button>
                  </form>
                <?php endif; ?>
                <form method="post" style="display:inline" onsubmit="return confirm('<?= e($p['name']) ?> entfernen?');">
                  <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                  <input type="hidden" name="aktion" value="teilnehmer_loeschen">
                  <input type="hidden" name="tasting_id" value="<?= e($aktivesTasting['id']) ?>">
                  <input type="hidden" name="token" value="<?= e($p['token']) ?>">
                  <button class="loeschen" type="submit">✕</button>
                </form>
              </span>
            </div>
          <?php endforeach; ?>
          <form method="post" style="margin-top:1rem;">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="aktion" value="teilnehmer_anlegen">
            <input type="hidden" name="tasting_id" value="<?= e($aktivesTasting['id']) ?>">
            <input type="text" name="name" placeholder="Name" maxlength="40" required>
            <input type="text" name="email" placeholder="E-Mail (optional – für die Einladung)" maxlength="80" inputmode="email">
            <button class="knopf" type="submit">Teilnehmer hinzufügen</button>
          </form>
          <p class="anzahl" style="margin-top:0.5rem;">Jeder Teilnehmer bekommt einen persönlichen Link – ein Tipp darauf meldet ihn dauerhaft an (kein Passwort nötig).</p>
        </div>

        <div class="card zu">
          <h2>Tasting verwalten</h2>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="aktion" value="tasting_titel">
            <input type="hidden" name="tasting_id" value="<?= e($aktivesTasting['id']) ?>">
            <input type="text" name="titel" value="<?= e($aktivesTasting['titel']) ?>" maxlength="60" required>
            <button class="knopf zweit" type="submit">Titel speichern</button>
          </form>
          <form method="post" class="abmelden" onsubmit="return confirm('Tasting „<?= e($aktivesTasting['titel']) ?>“ löschen? Bewertungen bleiben erhalten.');">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="aktion" value="tasting_loeschen">
            <input type="hidden" name="tasting_id" value="<?= e($aktivesTasting['id']) ?>">
            <button type="submit">Tasting löschen</button>
          </form>
        </div>
        <p class="zurueck"><a href="?tasting=1">&larr; Alle Tastings</a></p>
      <?php endif; ?>

    <?php elseif ($ansicht === 'beitreten'): ?>
      <!-- ==================== GRUPPEN-BEITRITT (per QR/Link) ==================== -->
      <?php
        $beitrittToken = (string)$_GET['beitritt'];
        $beitrittTasting = null;
        foreach ($daten['tastings'] as $t) {
            if (($t['beitritt'] ?? '') !== '' && hash_equals((string)$t['beitritt'], $beitrittToken)) {
                $beitrittTasting = $t;
                break;
            }
        }
      ?>
      <?php if ($beitrittTasting === null): ?>
        <div class="card"><p>Dieser Beitritts-Code ist nicht (mehr) gültig – frag den Gastgeber nach einem neuen QR-Code.</p></div>
      <?php else: ?>
        <div class="card">
          <h2><?= e($beitrittTasting['titel']) ?></h2>
          <p style="margin-bottom:0.8rem;">Trag deinen Namen ein und du bist dabei – ohne Passwort, dauerhaft auf diesem Gerät.</p>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="aktion" value="beitreten">
            <input type="hidden" name="beitritt" value="<?= e($beitrittToken) ?>">
            <input type="text" name="name" placeholder="Dein Name" maxlength="40" required>
            <input type="text" name="email" placeholder="E-Mail (optional, kannst du weglassen)" maxlength="80" inputmode="email">
            <label style="display:flex; align-items:flex-start; gap:0.55rem; margin:0.6rem 0 0.8rem; font-size:0.92rem; cursor:pointer;">
              <input type="checkbox" name="agb" value="ja" required style="width:auto; margin-top:0.2rem; flex-shrink:0;">
              <span>Ich bin mindestens 18 Jahre alt und akzeptiere die <a href="?nutzung=1" target="_blank">Nutzungsbedingungen</a> von TasteLog. <b>(Pflicht)</b></span>
            </label>
            <button class="knopf" type="submit">🥂 Dabei sein</button>
          </form>
        </div>
      <?php endif; ?>

    <?php elseif ($ansicht === 'tastingplatz'): ?>
      <!-- ==================== TASTING-ÜBERSICHT ==================== -->
      <?php if (!$eingeloggt): ?>
        <div class="card"><?= loginFormular('?tasting=1') ?></div>
      <?php else: ?>
        <?php
          $tastingsSortiert = $daten['tastings'];
          usort($tastingsSortiert, fn(array $x, array $y): int => ((int)($y['zeit'] ?? 0)) <=> ((int)($x['zeit'] ?? 0)));
          // Meine Tastings: überall dort, wo ich Teilnehmer bin oder schon bewertet habe
          $ich = mb_strtolower(trim((string)($_SESSION['person'] ?? '')));
          $meineTastingIds = [];
          if ($ich !== '') {
              foreach ($daten['tastings'] as $t) {
                  foreach (($t['teilnehmer'] ?? []) as $p) {
                      if (mb_strtolower(trim((string)$p['name'])) === $ich) {
                          $meineTastingIds[$t['id']] = true;
                      }
                  }
              }
              foreach ($daten['bewertungen'] as $bw) {
                  if ((string)($bw['tasting_id'] ?? '') !== '' && mb_strtolower(trim((string)$bw['person'])) === $ich) {
                      $meineTastingIds[(string)$bw['tasting_id']] = true;
                  }
              }
          }
          $einladungsTreffer = teilnehmerZuToken($daten, (string)($_COOKIE['einladung'] ?? ''));
          if ($einladungsTreffer !== null) {
              $meineTastingIds[$einladungsTreffer[0]['id']] = true;
          }
          $aktuellesTid = (string)(meinTasting($daten)['id'] ?? '');
          $meine = array_values(array_filter($tastingsSortiert, fn($t) => isset($meineTastingIds[$t['id']])));
          $weitere = array_values(array_filter($tastingsSortiert, fn($t) => !isset($meineTastingIds[$t['id']])));
        ?>
        <?php foreach ([['🥂 Meine Tastings', $meine], ['Weitere Tastings', $weitere]] as [$gruppenTitel, $gruppe]): ?>
          <?php if ($gruppe === []) { continue; } ?>
          <?php if ($meine !== [] && $weitere !== []): ?>
            <p class="untertitel" style="margin:0.8rem 0 0.5rem;"><?= $gruppenTitel ?></p>
          <?php endif; ?>
          <?php foreach ($gruppe as $t): ?>
            <?php $tFotos = mitTitelbild(tastingFotos($t['id']), (string)($t['titelbild'] ?? '')); ?>
            <a class="kachel" href="?tasting=<?= e(rawurlencode($t['id'])) ?>"<?= $t['id'] === $aktuellesTid ? ' style="border-left:5px solid var(--accent);"' : '' ?>>
              <?php if ($tFotos !== []): ?>
                <img class="thumb" src="<?= e(thumbUrl($tFotos[0])) ?>" alt="" loading="lazy" style="width:52px;height:52px;border-radius:14px;">
              <?php else: ?>
                <span class="k-icon">👥</span>
              <?php endif; ?>
              <span class="k-text"><b><?= e($t['titel']) ?></b><?= $t['id'] === $aktuellesTid ? ' <span class="bewerter-chip">✓ aktuell</span>' : '' ?>
                <small>📅 <?= date('d.m.Y', (int)($t['zeit'] ?? time())) ?> · <?= count($t['teilnehmer'] ?? []) ?> Teilnehmer<?= ($t['aktiv_cid'] ?? '') !== '' && ($gc = champagnerHolen($daten, $t['aktiv_cid'])) !== null ? ' · 🥂 gerade: ' . e($gc['name']) : '' ?></small>
              </span>
            </a>
          <?php endforeach; ?>
        <?php endforeach; ?>
        <div class="card" style="margin-top:1rem;">
          <h2>Neues Tasting anlegen</h2>
          <?php if (!darfTastingsAnlegen($benutzerAktiv)): ?>
            <p class="anzahl" style="margin-bottom:0.7rem;">Du kannst beliebig viele eigene Tastings anlegen – jeweils du + 1 eingeladene Person. Unbegrenzte Runden schaltet der Administrator auf Wunsch frei.</p>
          <?php endif; ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="aktion" value="tasting_anlegen">
            <input type="text" name="titel" placeholder="Titel, z. B. Abend mit Lisa" maxlength="60" required>
            <button class="knopf" type="submit">Anlegen</button>
          </form>
        </div>

      <?php endif; ?>

    <?php elseif ($ansicht === 'verwaltung'): ?>
      <!-- ==================== VERWALTUNG (nur Admin) ==================== -->
      <?php if ($benutzerAktiv === null || empty($benutzerAktiv['admin'])): ?>
        <div class="card">
          <p style="margin-bottom:0.8rem;">Die Verwaltung ist nur für Administratoren. Melde dich im Menü unter „👤 Benutzerkonto“ an.</p>
          <a class="knopf" href="?meine=1">Zu Meine Liste</a>
        </div>
      <?php else: ?>

        <?php if (($daten['kontoanfragen'] ?? []) !== []): ?>
          <div class="card" style="border-left:5px solid var(--accent);">
            <h2>📨 Account-Anfragen (<?= count($daten['kontoanfragen']) ?>)</h2>
            <?php foreach ($daten['kontoanfragen'] as $a): ?>
              <div style="border-bottom:1px solid var(--border); padding:0.6rem 0; display:flex; align-items:center; gap:0.6rem; flex-wrap:wrap;">
                <span style="flex:1; min-width:0;">
                  <b><?= e(trim((string)$a['vorname'] . ' ' . (string)$a['name'])) ?></b><br>
                  <span class="anzahl"><?= e((string)$a['email']) ?> · angefragt am <?= date('d.m.Y', (int)($a['zeit'] ?? 0)) ?></span>
                </span>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                  <input type="hidden" name="aktion" value="konto_anfrage_entscheiden">
                  <input type="hidden" name="id" value="<?= e($a['id']) ?>">
                  <input type="hidden" name="entscheidung" value="annehmen">
                  <button class="knopf klein" type="submit">✓ Freischalten</button>
                </form>
                <form method="post" style="display:inline;" onsubmit="return confirm('Anfrage von <?= e((string)$a['vorname']) ?> wirklich ablehnen?');">
                  <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                  <input type="hidden" name="aktion" value="konto_anfrage_entscheiden">
                  <input type="hidden" name="id" value="<?= e($a['id']) ?>">
                  <input type="hidden" name="entscheidung" value="ablehnen">
                  <button class="loeschen" type="submit">ablehnen</button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <details class="card" open>
          <summary>🛡️ Benutzer (<?= count($daten['benutzer']) ?>)</summary>
          <?php foreach ($daten['benutzer'] as $b): ?>
            <div style="border-bottom:1px solid var(--border); padding:0.6rem 0;">
              <p><b><?= e(trim((string)($b['vorname'] ?? '') . ' ' . (string)($b['name'] ?? ''))) ?></b>
                <span class="anzahl"><?= e((string)($b['email'] ?? '')) ?></span></p>
              <p style="margin:0.3rem 0;">
                <?php if (!empty($b['admin'])): ?><span class="bewerter-chip">🛡️ Admin</span><?php endif; ?>
                <?php if (!empty($b['darf_tasting'])): ?><span class="bewerter-chip">✅ große Tastings (unbegrenzt Personen)</span><?php else: ?><span class="bewerter-chip offen">⏳ Tastings bis 2 Personen</span><?php endif; ?>
              </p>
              <div class="knopfreihe" style="align-items:center;">
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                  <input type="hidden" name="aktion" value="benutzer_rechte">
                  <input type="hidden" name="id" value="<?= e($b['id']) ?>">
                  <input type="hidden" name="feld" value="darf_tasting">
                  <button class="knopf klein zweit" type="submit"><?= !empty($b['darf_tasting']) ? 'Auf 2 Personen begrenzen' : 'Große Tastings erlauben' ?></button>
                </form>
                <?php if ($b['id'] !== $benutzerAktiv['id']): ?>
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <input type="hidden" name="aktion" value="benutzer_rechte">
                    <input type="hidden" name="id" value="<?= e($b['id']) ?>">
                    <input type="hidden" name="feld" value="admin">
                    <button class="knopf klein zweit" type="submit"><?= !empty($b['admin']) ? 'Admin entziehen' : 'Zum Admin machen' ?></button>
                  </form>
                  <form method="post" style="display:inline;" onsubmit="return confirm('Benutzer <?= e($b['vorname'] ?? '') ?> wirklich löschen?');">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <input type="hidden" name="aktion" value="benutzer_loeschen">
                    <input type="hidden" name="id" value="<?= e($b['id']) ?>">
                    <button class="loeschen" type="submit">löschen</button>
                  </form>
                <?php endif; ?>
              </div>
              <form method="post" style="display:flex; gap:0.5rem; margin-top:0.5rem; align-items:center;">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="aktion" value="benutzer_passwort">
                <input type="hidden" name="id" value="<?= e($b['id']) ?>">
                <input type="password" name="passwort" placeholder="Neues Passwort setzen" minlength="6" style="margin-bottom:0; flex:1;" required>
                <button class="knopf klein zweit" type="submit">Setzen</button>
              </form>
            </div>
          <?php endforeach; ?>
          <h2 style="margin-top:1rem;">Neuen Benutzer anlegen</h2>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="aktion" value="benutzer_anlegen">
            <input type="text" name="vorname" placeholder="Vorname" maxlength="40" required>
            <input type="text" name="name" placeholder="Nachname (optional)" maxlength="40">
            <input type="text" name="email" placeholder="E-Mail" maxlength="80" inputmode="email" required>
            <input type="password" name="passwort" placeholder="Passwort (mind. 6 Zeichen)" minlength="6" required>
            <label style="display:block; margin-bottom:0.7rem;"><input type="checkbox" name="darf_tasting" value="1" style="width:auto; margin-right:0.4rem;"> Darf eigene Tastings anlegen</label>
            <button class="knopf" type="submit">Benutzer anlegen</button>
          </form>
        </details>

        <?php
          // ---------- Alle Getränke mit Filtern ----------
          $vtid  = (string)($_GET['vtid'] ?? 'alle');
          $vkat  = (string)($_GET['vkat'] ?? 'alle');
          $vsort = (string)($_GET['vsort'] ?? 'datum');
          $tastingTitelListe = [];
          foreach ($daten['tastings'] as $t) {
              $tastingTitelListe[$t['id']] = $t['titel'];
          }
          $vLink = fn(array $aend): string => '?verwaltung=1&amp;vtid=' . e(rawurlencode($aend['vtid'] ?? $vtid)) . '&amp;vkat=' . e(rawurlencode($aend['vkat'] ?? $vkat)) . '&amp;vsort=' . e(rawurlencode($aend['vsort'] ?? $vsort));
          $vGetraenke = array_values(array_filter($daten['champagner'], function ($c) use ($vtid, $vkat) {
              if ($vtid !== 'alle' && (string)($c['tasting_id'] ?? '') !== ($vtid === 'ohne' ? '' : $vtid)) {
                  return false;
              }
              if ($vkat !== 'alle' && ($c['typ'] ?? 'champagner') !== $vkat) {
                  return false;
              }
              return true;
          }));
          if ($vsort === 'name') {
              usort($vGetraenke, fn($x, $y) => strcmp(mb_strtolower($x['name']), mb_strtolower($y['name'])));
          } elseif ($vsort === 'tasting') {
              usort($vGetraenke, fn($x, $y) => strcmp($tastingTitelListe[(string)($x['tasting_id'] ?? '')] ?? '~', $tastingTitelListe[(string)($y['tasting_id'] ?? '')] ?? '~') ?: (((int)$y['zeit']) <=> ((int)$x['zeit'])));
          } elseif ($vsort === 'rebsorte') {
              usort($vGetraenke, fn($x, $y) => strcmp(mb_strtolower((string)($x['rebsorte'] ?? '~')), mb_strtolower((string)($y['rebsorte'] ?? '~'))));
          } else {
              usort($vGetraenke, fn($x, $y) => ((int)$y['zeit']) <=> ((int)$x['zeit']));
          }
        ?>
        <div class="card">
          <h2>🍾 Alle Getränke (<?= count($vGetraenke) ?> von <?= count($daten['champagner']) ?>)</h2>
          <div class="sortier-leiste">Tasting:
            <a class="<?= $vtid === 'alle' ? 'aktiv' : '' ?>" href="<?= $vLink(['vtid' => 'alle']) ?>">Alle</a>
            <?php foreach ($daten['tastings'] as $t): ?>
              <a class="<?= $vtid === $t['id'] ? 'aktiv' : '' ?>" href="<?= $vLink(['vtid' => $t['id']]) ?>"><?= e($t['titel']) ?></a>
            <?php endforeach; ?>
            <a class="<?= $vtid === 'ohne' ? 'aktiv' : '' ?>" href="<?= $vLink(['vtid' => 'ohne']) ?>">ohne Tasting</a>
          </div>
          <div class="sortier-leiste">Kategorie:
            <a class="<?= $vkat === 'alle' ? 'aktiv' : '' ?>" href="<?= $vLink(['vkat' => 'alle']) ?>">Alle</a>
            <?php foreach ($KATEGORIEN_GETRAENKE as $kS => [$kI, $kN]): ?>
              <a class="<?= $vkat === $kS ? 'aktiv' : '' ?>" href="<?= $vLink(['vkat' => $kS]) ?>"><?= $kI ?> <?= e($kN) ?></a>
            <?php endforeach; ?>
          </div>
          <div class="sortier-leiste">Sortieren:
            <a class="<?= $vsort === 'datum' ? 'aktiv' : '' ?>" href="<?= $vLink(['vsort' => 'datum']) ?>">Anlagedatum</a>
            <a class="<?= $vsort === 'name' ? 'aktiv' : '' ?>" href="<?= $vLink(['vsort' => 'name']) ?>">Name</a>
            <a class="<?= $vsort === 'tasting' ? 'aktiv' : '' ?>" href="<?= $vLink(['vsort' => 'tasting']) ?>">Tasting</a>
            <a class="<?= $vsort === 'rebsorte' ? 'aktiv' : '' ?>" href="<?= $vLink(['vsort' => 'rebsorte']) ?>">Traube/Stil</a>
          </div>
          <input type="search" class="filter-feld" placeholder="🔍 Name, Traube, Weingut suchen …" data-ziel="#vw-getraenke">
          <div id="vw-getraenke">
            <?php foreach ($vGetraenke as $c): ?>
              <?php
                $vWg = weingutHolen($daten, (string)($c['weingut_id'] ?? ''));
                $vTeile = [$KATEGORIEN_GETRAENKE[(string)($c['typ'] ?? 'champagner')][1] ?? 'Champagner'];
                if (trim((string)($c['rebsorte'] ?? '')) !== '') { $vTeile[] = (string)$c['rebsorte']; }
                if ($vWg !== null) { $vTeile[] = '🍇 ' . $vWg['name']; }
                $vTeile[] = '👥 ' . ($tastingTitelListe[(string)($c['tasting_id'] ?? '')] ?? 'ohne Tasting');
                $vTeile[] = count(bewertungenFuer($daten, $c['id'])) . ' Bew.';
              ?>
              <div class="filterbar" style="display:flex; align-items:center; gap:0.6rem; border-bottom:1px solid var(--border); padding:0.5rem 0;">
                <span style="flex:1; min-width:0;">
                  <b><?= $KATEGORIEN_GETRAENKE[(string)($c['typ'] ?? 'champagner')][0] ?? '🍾' ?> <?= e($c['name']) ?></b><br>
                  <span class="anzahl"><?= e(implode(' · ', $vTeile)) ?></span>
                </span>
                <a class="knopf klein zweit" href="?ergebnis=<?= e(rawurlencode($c['id'])) ?>">Ansehen</a>
                <form method="post" onsubmit="return confirm('„<?= e($c['name']) ?>“ samt Bewertungen und Fotos wirklich löschen?');">
                  <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                  <input type="hidden" name="aktion" value="champagner_loeschen">
                  <input type="hidden" name="id" value="<?= e($c['id']) ?>">
                  <button class="loeschen" type="submit">löschen</button>
                </form>
              </div>
            <?php endforeach; ?>
            <?php if ($vGetraenke === []): ?>
              <p style="color:var(--muted); font-style:italic;">Kein Getränk passt zu diesen Filtern.</p>
            <?php endif; ?>
          </div>
        </div>

        <div class="card zu">
          <h2>🍇 Alle Weingüter (<?= count($daten['weingueter']) ?>)</h2>
          <input type="search" class="filter-feld" placeholder="🔍 Weingut suchen …" data-ziel="#vw-weingueter">
          <div id="vw-weingueter">
            <?php
              $vwgListe = $daten['weingueter'];
              usort($vwgListe, fn($x, $y) => strcmp(mb_strtolower($x['name']), mb_strtolower($y['name'])));
            ?>
            <?php foreach ($vwgListe as $w): ?>
              <?php
                $wgGetraenke = array_values(array_filter($daten['champagner'], fn($c) => ($c['weingut_id'] ?? '') === $w['id']));
                $wgTastings = array_unique(array_map(fn($c) => $tastingTitelListe[(string)($c['tasting_id'] ?? '')] ?? 'ohne', $wgGetraenke));
              ?>
              <div class="filterbar" style="display:flex; align-items:center; gap:0.6rem; border-bottom:1px solid var(--border); padding:0.5rem 0;">
                <span style="flex:1; min-width:0;">
                  <b>🍇 <?= e($w['name']) ?></b><br>
                  <span class="anzahl"><?= count($wgGetraenke) ?> Getränk(e)<?= $wgTastings !== [] ? ' · 👥 ' . e(implode(', ', $wgTastings)) : '' ?></span>
                </span>
                <a class="knopf klein zweit" href="?weingut=<?= e(rawurlencode($w['id'])) ?>">Ansehen</a>
                <form method="post" onsubmit="return confirm('„<?= e($w['name']) ?>“ löschen? Die Getränke bleiben, verlieren aber die Zuordnung.');">
                  <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                  <input type="hidden" name="aktion" value="weingut_loeschen">
                  <input type="hidden" name="id" value="<?= e($w['id']) ?>">
                  <button class="loeschen" type="submit">löschen</button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="card">
          <h2>✉️ Kontakt</h2>
          <p>Wünsche und Fragen an: <a href="mailto:tasting@fruthzeug.de">tasting@fruthzeug.de</a></p>
        </div>
      <?php endif; ?>

    <?php elseif ($ansicht === 'ueber'): ?>
      <!-- ==================== ÜBER DIESE APP ==================== -->
      <div class="card">
        <h2>Der Gedanke dahinter</h2>
        <p style="margin-bottom:0.7rem;">Geschmack ist keine feste Eigenschaft eines Getränks. Geschmack entsteht im Zusammenspiel von Produkt, Umgebung und dir.</p>
        <p style="margin-bottom:0.7rem;">Genau hier setzt diese App an. Sie stellt nicht die Frage: „Wie viele Punkte gibst du diesem Getränk?“ Sie stellt die wichtigere Frage: <b>„Wie hast du dieses Getränk genau in diesem Moment erlebt?“</b></p>
        <p style="margin-bottom:0.7rem;">Jede Verkostung wird zu einer Momentaufnahme deiner Wahrnehmung. Neben deinen sensorischen Eindrücken werden – soweit verfügbar – Ort, Uhrzeit, Jahreszeit, Temperatur, Wetter und weitere Umgebungsdaten berücksichtigt. Gleichzeitig fließen Faktoren wie deine Stimmung, die Menschen, mit denen du zusammen bist, oder der Anlass in die Einordnung ein. Denn all das beeinflusst, wie du Aromen wahrnimmst und bewertest.</p>
        <p style="margin-bottom:0.7rem;">Mit jeder Verkostung entsteht ein immer genaueres Bild deines persönlichen Geschmacks. Du erkennst Muster, die dir bisher verborgen geblieben sind. Du verstehst, warum dich derselbe Champagner heute begeistert und Monate später ganz anders erscheint. Du lernst, welche Bedingungen deine Wahrnehmung verändern und welche Eindrücke dauerhaft zu deinem Geschmack gehören.</p>
        <p style="margin-bottom:0.7rem;">Das Ziel dieser App ist nicht, Getränke zu bewerten.</p>
        <p style="margin-bottom:0.7rem; font-size:1.15rem;"><b>Das Ziel bist du.</b></p>
        <p style="margin-bottom:0.7rem;">Du entwickelst mit jeder Verkostung ein präziseres sensorisches Gedächtnis. Du lernst, Aromen bewusster wahrzunehmen, Unterschiede klarer zu erkennen und deine Eindrücke immer genauer zu beschreiben. Aus spontanen Meinungen werden fundierte Urteile. Aus einzelnen Verkostungen entsteht Erfahrung. Aus Erfahrung entsteht Kompetenz.</p>
        <p style="margin-bottom:0.7rem;">Mit der Zeit wird diese App zu deinem persönlichen Geschmacksarchiv. Sie dokumentiert nicht nur, was du getrunken hast, sondern wie sich dein Geschmack entwickelt. Sie hilft dir, dich selbst als Verkoster immer besser kennenzulernen und deine Wahrnehmung systematisch zu schärfen.</p>
        <p style="margin-bottom:0.7rem;">Jedes Getränk erzählt eine Geschichte.</p>
        <p>Diese App hilft dir, sie zu verstehen – und gleichzeitig deine eigene sensorische Geschichte zu schreiben.</p>
      </div>

      <div class="card">
        <h2>Über mich &amp; meine Motivation</h2>
        <p style="margin-bottom:0.7rem;">Diese App ist aus einem ganz persönlichen Bedürfnis entstanden.</p>
        <p style="margin-bottom:0.7rem;">Über viele Jahre hatte ich das Glück, außergewöhnliche Champagner, Weine, Biere und viele andere Getränke zu genießen. Doch mit der Zeit wurde mir bewusst, wie wenig davon wirklich geblieben ist. Ich wusste noch, dass ein Getränk großartig war – aber nicht mehr <i>warum</i>. Die Aromen, der Moment, die Menschen, der Ort – vieles war mit der Zeit verloren.</p>
        <p style="margin-bottom:0.7rem;">Ich wollte eine App, die genau dieses Problem löst. Eine App, die Erinnerungen bewahrt, Verkostungen strukturiert dokumentiert und mich dabei unterstützt, ohne dass ich lange Texte schreiben muss. Denn auch ich gehöre zu den Menschen, die lieber genießen als tippen.</p>
        <p style="margin-bottom:0.7rem;"><b>Vielleicht geht es dir genauso.</b></p>
        <p style="margin-bottom:0.7rem;">Wenn diese App dir dabei hilft, deinen Geschmack bewusster zu erleben, deine schönsten Genussmomente festzuhalten und über die Jahre ein persönliches Geschmacksarchiv aufzubauen, dann hat sie ihren Zweck erfüllt.</p>
        <p style="margin-bottom:0.7rem;">Ich wünsche dir viele besondere Entdeckungen, spannende Vergleiche und vor allem unvergessliche Genussmomente.</p>
        <p style="margin-bottom:0.7rem;">Und falls diese App dich tatsächlich begeistert und sie ein fester Begleiter deiner Verkostungen wird, freue ich mich natürlich auch über das eine oder andere Getränk, das seinen Weg zu mir findet. Schließlich muss ich die App ja regelmäßig weiterentwickeln – und das testet sich mit guten Getränken bekanntlich am besten. 🥂</p>
        <p style="text-align:right;"><i>– Carl</i></p>
      </div>

      <div class="card">
        <h2>✉️ Kontakt</h2>
        <p>Wünsche, Ideen oder ein Getränk auf den Weg bringen: <a href="mailto:tasting@fruthzeug.de">tasting@fruthzeug.de</a></p>
      </div>
      <div class="knopfreihe">
        <a class="knopf" href="./">🥂 Loslegen: Verkosten</a>
        <a class="knopf zweit" href="?meine=1">📖 Meine Verkostungen</a>
      </div>
      <p style="text-align:center; margin-top:0.8rem;"><a href="?nutzung=1">📜 Nutzungsbedingungen</a></p>

    <?php elseif ($ansicht === 'nutzung'): ?>
      <!-- ==================== NUTZUNGSBEDINGUNGEN ==================== -->
      <div class="card">
        <p style="margin-bottom:0.7rem;">Willkommen bei TasteLog.</p>
        <p>TasteLog ist eine privat betriebene Plattform zur sensorischen Beschreibung, Dokumentation und zum Austausch über Getränke aller Art. Mit der Registrierung oder Nutzung der Plattform erklärst du dich mit den nachfolgenden Nutzungsbedingungen einverstanden.</p>
      </div>

      <div class="card" style="border-left:5px solid var(--accent);">
        <h2>Kurzfassung</h2>
        <p style="margin-bottom:0.5rem;">Mit der Nutzung von TasteLog bestätigst du insbesondere, dass du:</p>
        <ul style="margin:0 0 0 1.2rem; padding:0;">
          <li>mindestens 18 Jahre alt bist,</li>
          <li>diese Nutzungsbedingungen akzeptierst,</li>
          <li>für deine Inhalte selbst verantwortlich bist,</li>
          <li>wichtige Daten selbst sicherst,</li>
          <li>keinen Anspruch auf dauerhafte Speicherung oder Verfügbarkeit der Plattform hast,</li>
          <li>die Plattform ausschließlich rechtmäßig nutzt.</li>
        </ul>
      </div>

      <details class="card">
        <summary>1. Mindestalter</summary>
        <p style="margin-bottom:0.7rem;">Die Nutzung von TasteLog ist ausschließlich Personen gestattet, die das 18. Lebensjahr vollendet haben.</p>
        <p style="margin-bottom:0.7rem;">Mit der Registrierung bestätigst du, mindestens 18 Jahre alt zu sein.</p>
        <p>Der Betreiber ist berechtigt, Nutzerkonten zu sperren oder zu löschen, wenn begründete Zweifel an der Einhaltung dieser Voraussetzung bestehen.</p>
      </details>

      <details class="card">
        <summary>2. Zweck der Plattform</summary>
        <p style="margin-bottom:0.7rem;">TasteLog dient der persönlichen Dokumentation und Beschreibung sensorischer Eindrücke sowie dem Austausch über Getränke.</p>
        <p style="margin-bottom:0.5rem;">Die Plattform kann insbesondere für folgende Getränkekategorien genutzt werden:</p>
        <ul style="margin:0 0 0.7rem 1.2rem; padding:0;">
          <li>Champagner</li>
          <li>Wein</li>
          <li>Bier</li>
          <li>Spirituosen</li>
          <li>Kaffee</li>
          <li>Tee</li>
          <li>Cocktails</li>
          <li>alkoholfreie Getränke</li>
          <li>weitere Genussmittel</li>
        </ul>
        <p>TasteLog ist keine wissenschaftliche Datenbank und kein objektives Bewertungssystem. Sämtliche Inhalte spiegeln persönliche Wahrnehmungen und subjektive Geschmackseindrücke wider.</p>
      </details>

      <details class="card">
        <summary>3. Registrierung</summary>
        <p style="margin-bottom:0.7rem;">Für bestimmte Funktionen ist die Erstellung eines Nutzerkontos erforderlich.</p>
        <p style="margin-bottom:0.5rem;">Der Nutzer ist verpflichtet,</p>
        <ul style="margin:0 0 0.7rem 1.2rem; padding:0;">
          <li>wahrheitsgemäße Angaben zu machen,</li>
          <li>seine Zugangsdaten vertraulich zu behandeln und</li>
          <li>sein Konto nicht an Dritte weiterzugeben.</li>
        </ul>
        <p>Für sämtliche Aktivitäten unter dem eigenen Benutzerkonto ist der Nutzer selbst verantwortlich.</p>
      </details>

      <details class="card">
        <summary>4. Nutzerinhalte</summary>
        <p style="margin-bottom:0.7rem;">Der Nutzer bleibt Eigentümer der von ihm eingestellten Inhalte.</p>
        <p style="margin-bottom:0.5rem;">Hierzu gehören insbesondere</p>
        <ul style="margin:0 0 0.7rem 1.2rem; padding:0;">
          <li>Bewertungen</li>
          <li>Verkostungsnotizen</li>
          <li>Bilder</li>
          <li>Audioaufnahmen</li>
          <li>Videos</li>
          <li>Kommentare</li>
          <li>Listen</li>
          <li>Sammlungen</li>
          <li>persönliche Beschreibungen</li>
          <li>KI-generierte Inhalte</li>
        </ul>
        <p style="margin-bottom:0.7rem;">Mit dem Hochladen räumt der Nutzer dem Betreiber ein unentgeltliches, nicht ausschließliches, räumlich unbeschränktes Recht ein, diese Inhalte innerhalb von TasteLog zu speichern, zu verarbeiten, darzustellen und für den Betrieb der Plattform zu verwenden.</p>
        <p>Eine Nutzung außerhalb der Plattform erfolgt ausschließlich im Rahmen der gesetzlichen Bestimmungen oder mit Zustimmung des Nutzers.</p>
      </details>

      <details class="card">
        <summary>5. KI-Unterstützung</summary>
        <p style="margin-bottom:0.5rem;">TasteLog kann künstliche Intelligenz einsetzen, um beispielsweise</p>
        <ul style="margin:0 0 0.7rem 1.2rem; padding:0;">
          <li>Geschmacksbeschreibungen vorzuschlagen,</li>
          <li>Verkostungsnotizen zusammenzufassen,</li>
          <li>Aromaprofile zu erstellen,</li>
          <li>Getränke zu vergleichen,</li>
          <li>Trainingsaufgaben zu erzeugen,</li>
          <li>Blindverkostungen auszuwerten,</li>
          <li>Empfehlungen auszusprechen.</li>
        </ul>
        <p style="margin-bottom:0.7rem;">Diese Inhalte dienen ausschließlich der Unterstützung.</p>
        <p>Es besteht kein Anspruch auf Richtigkeit, Vollständigkeit oder wissenschaftliche Genauigkeit.</p>
      </details>

      <details class="card">
        <summary>6. Verhaltensregeln</summary>
        <p style="margin-bottom:0.5rem;">Nicht zulässig sind insbesondere</p>
        <ul style="margin:0 0 0.7rem 1.2rem; padding:0;">
          <li>rechtswidrige Inhalte,</li>
          <li>beleidigende oder diskriminierende Inhalte,</li>
          <li>pornografische Inhalte,</li>
          <li>Spam,</li>
          <li>Werbung ohne Zustimmung,</li>
          <li>automatisierte Datensammlung,</li>
          <li>Manipulation von Bewertungen,</li>
          <li>missbräuchliche Nutzung der Plattform.</li>
        </ul>
        <p>Der Betreiber kann entsprechende Inhalte oder Nutzerkonten jederzeit entfernen oder sperren.</p>
      </details>

      <details class="card">
        <summary>7. Datenspeicherung</summary>
        <p style="margin-bottom:0.7rem;">TasteLog dient nicht als Datensicherung.</p>
        <p style="margin-bottom:0.7rem;">Der Betreiber übernimmt keine Gewähr dafür, dass Daten dauerhaft gespeichert werden.</p>
        <p style="margin-bottom:0.7rem;">Der Nutzer ist selbst dafür verantwortlich, wichtige Inhalte regelmäßig zu sichern.</p>
        <p>Ein Anspruch auf Wiederherstellung gelöschter Daten besteht nicht.</p>
      </details>

      <details class="card">
        <summary>8. Verfügbarkeit</summary>
        <p style="margin-bottom:0.7rem;">TasteLog wird als private Plattform betrieben.</p>
        <p style="margin-bottom:0.5rem;">Der Betreiber übernimmt keine Gewähr für</p>
        <ul style="margin:0 0 0.7rem 1.2rem; padding:0;">
          <li>eine unterbrechungsfreie Erreichbarkeit,</li>
          <li>bestimmte Funktionen,</li>
          <li>eine Mindestverfügbarkeit,</li>
          <li>Kompatibilität mit bestimmten Geräten oder Betriebssystemen.</li>
        </ul>
        <p>Wartungsarbeiten, technische Änderungen oder vorübergehende Ausfälle können jederzeit erfolgen.</p>
      </details>

      <details class="card">
        <summary>9. Haftung</summary>
        <p style="margin-bottom:0.7rem;">Die Nutzung von TasteLog erfolgt auf eigenes Risiko.</p>
        <p style="margin-bottom:0.5rem;">Der Betreiber haftet insbesondere nicht für</p>
        <ul style="margin:0 0 0.7rem 1.2rem; padding:0;">
          <li>Datenverlust,</li>
          <li>Verlust von Bildern,</li>
          <li>Verlust von Bewertungen,</li>
          <li>Verlust von Notizen,</li>
          <li>Verlust von Sammlungen,</li>
          <li>Serverausfälle,</li>
          <li>Softwarefehler,</li>
          <li>Synchronisationsfehler,</li>
          <li>Übertragungsfehler,</li>
          <li>technische Störungen,</li>
          <li>fehlerhafte KI-Auswertungen,</li>
          <li>indirekte Schäden,</li>
          <li>Folgeschäden,</li>
          <li>entgangenen Gewinn,</li>
          <li>entgangene Nutzungsmöglichkeiten.</li>
        </ul>
        <p style="margin-bottom:0.7rem;">Soweit gesetzlich zulässig, haftet der Betreiber ausschließlich bei Vorsatz oder grober Fahrlässigkeit.</p>
        <p>Die gesetzlich zwingende Haftung, insbesondere für Schäden aus der Verletzung von Leben, Körper oder Gesundheit sowie nach dem Produkthaftungsgesetz, bleibt unberührt.</p>
      </details>

      <details class="card">
        <summary>10. Änderungen der Plattform</summary>
        <p style="margin-bottom:0.7rem;">TasteLog befindet sich in kontinuierlicher Weiterentwicklung.</p>
        <p style="margin-bottom:0.5rem;">Der Betreiber ist berechtigt,</p>
        <ul style="margin:0 0 0.7rem 1.2rem; padding:0;">
          <li>Funktionen zu ändern,</li>
          <li>Funktionen hinzuzufügen,</li>
          <li>Funktionen einzustellen,</li>
          <li>Inhalte zu löschen,</li>
          <li>Datenstrukturen anzupassen,</li>
          <li>Benutzeroberflächen zu ändern,</li>
          <li>Schnittstellen zu ändern.</li>
        </ul>
        <p>Ein Anspruch auf unveränderte Funktionen besteht nicht.</p>
      </details>

      <details class="card">
        <summary>11. Einstellung des Dienstes</summary>
        <p style="margin-bottom:0.7rem;">Der Betreiber kann TasteLog jederzeit ganz oder teilweise dauerhaft oder vorübergehend einstellen.</p>
        <p>Ein Anspruch auf Fortführung der Plattform, Export von Daten oder Wiederherstellung gespeicherter Inhalte besteht nicht.</p>
      </details>

      <details class="card">
        <summary>12. Kündigung und Sperrung</summary>
        <p style="margin-bottom:0.7rem;">Der Nutzer kann sein Konto jederzeit löschen.</p>
        <p>Der Betreiber kann Nutzerkonten jederzeit sperren oder löschen, insbesondere bei Verstößen gegen diese Nutzungsbedingungen oder wenn dies für den sicheren Betrieb der Plattform erforderlich ist.</p>
      </details>

      <details class="card">
        <summary>13. Geistiges Eigentum</summary>
        <p style="margin-bottom:0.7rem;">Die Software, das Design, Logos, Datenbanken, Grafiken und sonstigen Bestandteile von TasteLog sind urheberrechtlich geschützt.</p>
        <p>Eine Vervielfältigung, Verbreitung oder Nutzung außerhalb der von TasteLog vorgesehenen Funktionen ist ohne vorherige Zustimmung des Betreibers nicht gestattet.</p>
      </details>

      <details class="card">
        <summary>14. Änderungen der Nutzungsbedingungen</summary>
        <p style="margin-bottom:0.7rem;">Der Betreiber kann diese Nutzungsbedingungen jederzeit mit Wirkung für die Zukunft ändern.</p>
        <p style="margin-bottom:0.7rem;">Die jeweils aktuelle Fassung wird innerhalb der Plattform veröffentlicht.</p>
        <p>Die weitere Nutzung der Plattform nach Veröffentlichung gilt als Zustimmung zu den geänderten Nutzungsbedingungen.</p>
      </details>

      <details class="card">
        <summary>15. Schlussbestimmungen</summary>
        <p style="margin-bottom:0.7rem;">Es gilt das Recht der Bundesrepublik Deutschland.</p>
        <p style="margin-bottom:0.7rem;">Gerichtsstand ist – soweit gesetzlich zulässig – der Sitz des Betreibers.</p>
        <p>Sollte eine Bestimmung dieser Nutzungsbedingungen ganz oder teilweise unwirksam oder undurchführbar sein oder werden, bleibt die Wirksamkeit der übrigen Bestimmungen hiervon unberührt. An die Stelle der unwirksamen Bestimmung tritt die gesetzliche Regelung.</p>
      </details>

      <div class="card">
        <h2>✉️ Kontakt</h2>
        <p>Fragen zu diesen Nutzungsbedingungen: <a href="mailto:tasting@fruthzeug.de">tasting@fruthzeug.de</a></p>
      </div>
      <p style="text-align:center; margin-top:0.8rem;"><a href="?impressum=1">⚖️ Impressum &amp; Datenschutz</a></p>

    <?php elseif ($ansicht === 'einladung_frage'): ?>
      <!-- ==================== EINLADUNG: EINMALIGE BESTÄTIGUNG ==================== -->
      <div class="card">
        <h2>👥 <?= e($einladungFrage['tasting']['titel']) ?></h2>
        <p style="margin-bottom:0.8rem;">Hallo <b><?= e($einladungFrage['teilnehmer']['name']) ?></b>! Bevor es losgeht, brauchen wir einmalig deine Bestätigung – danach bist du auf diesem Gerät dauerhaft angemeldet.</p>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
          <input type="hidden" name="aktion" value="einladung_bestaetigen">
          <input type="hidden" name="token" value="<?= e($einladungFrage['token']) ?>">
          <label style="display:flex; align-items:flex-start; gap:0.55rem; margin:0.6rem 0 0.8rem; font-size:0.92rem; cursor:pointer;">
            <input type="checkbox" name="agb" value="ja" required style="width:auto; margin-top:0.2rem; flex-shrink:0;">
            <span>Ich bin mindestens 18 Jahre alt und akzeptiere die <a href="?nutzung=1" target="_blank">Nutzungsbedingungen</a> von TasteLog. <b>(Pflicht)</b></span>
          </label>
          <button class="knopf" type="submit">🥂 Bestätigen &amp; mitmachen</button>
        </form>
      </div>

    <?php elseif ($ansicht === 'impressum'): ?>
      <!-- ==================== IMPRESSUM & DATENSCHUTZ ==================== -->
      <div class="card">
        <h2>Impressum</h2>
        <p class="anzahl" style="margin-bottom:0.7rem;">Angaben gemäß § 5 DDG</p>
        <p style="margin-bottom:0.5rem;"><b>Betreiber</b></p>
        <p style="margin-bottom:0.7rem;">Carl Fruth<br>Ändresenweg 11<br>92331 Lupburg<br>Deutschland</p>
        <p style="margin-bottom:0.7rem;">E-Mail: <a href="mailto:tasting@fruthzeug.de">tasting@fruthzeug.de</a></p>
        <p style="margin-bottom:0.5rem;"><b>Verantwortlich für den Inhalt nach § 18 Abs. 2 MStV</b></p>
        <p>Carl Fruth<br>Ändresenweg 11<br>92331 Lupburg<br>Deutschland</p>
      </div>

      <div class="card">
        <h2>Datenschutzerklärung</h2>
        <p style="margin-bottom:0.7rem;">Der Schutz deiner personenbezogenen Daten ist uns wichtig.</p>
        <p style="margin-bottom:0.5rem;"><b>Verantwortlicher</b></p>
        <p style="margin-bottom:0.7rem;">Carl Fruth<br>Ändresenweg 11<br>92331 Lupburg<br>Deutschland</p>
        <p style="margin-bottom:0.7rem;">E-Mail: <a href="mailto:tasting@fruthzeug.de">tasting@fruthzeug.de</a></p>
        <p style="margin-bottom:0.5rem;"><b>Verarbeitung personenbezogener Daten</b></p>
        <p style="margin-bottom:0.5rem;">TasteLog verarbeitet ausschließlich personenbezogene Daten, die für den Betrieb der Plattform erforderlich sind.</p>
        <p style="margin-bottom:0.5rem;">Hierzu gehören insbesondere:</p>
        <ul style="margin:0 0 0.7rem 1.2rem; padding:0;">
          <li>Benutzerkonto</li>
          <li>E-Mail-Adresse</li>
          <li>freiwillig eingegebene Inhalte</li>
          <li>hochgeladene Bilder</li>
          <li>technisch notwendige Verbindungsdaten</li>
        </ul>
        <p style="margin-bottom:0.7rem;">Die Verarbeitung erfolgt ausschließlich zum Betrieb und zur Bereitstellung der Plattform.</p>
        <p style="margin-bottom:0.5rem;"><b>Cookies</b></p>
        <p style="margin-bottom:0.7rem;">TasteLog verwendet ausschließlich technisch notwendige Cookies. Es werden keine Werbe-, Analyse- oder Tracking-Cookies eingesetzt.</p>
        <p style="margin-bottom:0.5rem;"><b>Löschung von Daten</b></p>
        <p style="margin-bottom:0.7rem;">Der Nutzer kann sein Benutzerkonto und seine Inhalte jederzeit selbst löschen.</p>
        <p>Soweit keine gesetzlichen oder zwingenden technischen Gründe entgegenstehen, werden personenbezogene Daten anschließend gelöscht.</p>
      </div>
      <p style="text-align:center; margin-top:0.8rem;"><a href="?nutzung=1">📜 Nutzungsbedingungen</a></p>

    <?php elseif ($ansicht === 'meine'): ?>
      <!-- ==================== MEIN VERKOSTUNGSBUCH ==================== -->
      <?php if (!$eingeloggt): ?>
        <div class="card"><?= loginFormular('?meine=1') ?></div>
      <?php else: ?>
        <?php $ich = mb_strtolower(trim((string)($_SESSION['person'] ?? ''))); ?>
        <?php if ($ich === ''): ?>
          <div class="card">
            <p style="margin-bottom:0.8rem;">Wir kennen deinen Namen noch nicht. Tritt einem Tasting bei oder gib bei deiner ersten Bewertung deinen Namen an – danach sammelt sich hier dein Verkostungsbuch.</p>
            <a class="knopf" href="?tasting=1">Zu den Tastings</a>
          </div>
        <?php else: ?>
          <?php
            $tastingTitelListe = [];
            foreach ($daten['tastings'] as $t) {
                $tastingTitelListe[$t['id']] = $t['titel'];
            }
            // Meine Bewertungen einsammeln (neueste eigene Bewertung je Getränk)
            $eintraege = [];
            $flaschenSumme = 0;
            foreach ($daten['bewertungen'] as $b) {
                if (mb_strtolower(trim((string)$b['person'])) !== $ich) {
                    continue;
                }
                $c = champagnerHolen($daten, (string)$b['champagner_id']);
                if ($c === null) {
                    continue; // Getränk wurde gelöscht
                }
                $summe = 0;
                $anz = 0;
                foreach ($b['werte'] as $w) { $summe += (int)$w; $anz++; }
                $wg = weingutHolen($daten, (string)($c['weingut_id'] ?? ''));
                $flaschenSumme += (int)($b['flaschen'] ?? 0);
                $eintraege[$c['id']] = [
                    'c'       => $c,
                    'sterne'  => $anz > 0 ? $summe / $anz : null,
                    'zeit'    => (int)($b['zeit'] ?? 0),
                    'notiz'   => trim((string)($b['notiz'] ?? '')),
                    'weingut' => $wg !== null ? $wg['name'] : '',
                    'tasting' => $tastingTitelListe[(string)($c['tasting_id'] ?? '')] ?? '',
                    'ort'     => trim((string)($b['ort'] ?? '')),
                    'wetter'  => trim((string)($b['wetter'] ?? '')),
                ];
            }
            $eintraege = array_values($eintraege);
            $msort = (string)($_GET['msort'] ?? 'datum');
            match ($msort) {
                'name'    => usort($eintraege, fn($x, $y) => strcmp(mb_strtolower($x['c']['name']), mb_strtolower($y['c']['name']))),
                'tasting' => usort($eintraege, fn($x, $y) => strcmp(mb_strtolower($x['tasting']), mb_strtolower($y['tasting'])) ?: ($y['zeit'] <=> $x['zeit'])),
                'weingut' => usort($eintraege, fn($x, $y) => strcmp(mb_strtolower($x['weingut'] !== '' ? $x['weingut'] : '~'), mb_strtolower($y['weingut'] !== '' ? $y['weingut'] : '~')) ?: ($y['zeit'] <=> $x['zeit'])),
                'sterne'  => usort($eintraege, fn($x, $y) => (($y['sterne'] ?? 0) <=> ($x['sterne'] ?? 0))),
                default   => usort($eintraege, fn($x, $y) => $y['zeit'] <=> $x['zeit']),
            };
          ?>
          <div class="card" style="border-left:5px solid var(--accent); padding:0.6rem 0.9rem;">
            <?php $meinProfil = profilFoto($daten, (string)$_SESSION['person']); ?>
            <button type="button" id="profil-knopf" style="display:flex; align-items:center; gap:0.7rem; width:100%; background:none; border:none; padding:0; cursor:pointer; text-align:left; font-family:inherit; font-size:1rem; color:var(--text);">
              <?php if ($meinProfil !== ''): ?>
                <img class="avatar" src="<?= e(thumbUrl($meinProfil)) ?>" alt="" style="width:46px; height:46px; flex-shrink:0;">
              <?php else: ?>
                <span style="width:46px; height:46px; border-radius:50%; border:2px dashed var(--border); display:flex; align-items:center; justify-content:center; font-size:1.3rem; flex-shrink:0;">👤</span>
              <?php endif; ?>
              <span style="flex:1; min-width:0;"><b><?= e(trim((string)$_SESSION['person'])) ?></b> – <b><?= count($eintraege) ?></b> Getränk(e)<?= $flaschenSumme > 0 ? ', <b>' . $flaschenSumme . '</b> Flasche(n)' : '' ?> 🥂<br><span class="anzahl">Antippen: Profilfoto &amp; „So verkostest du“</span></span>
            </button>
          </div>

            <?php
              // 🪞 Selbst-Profil: Wie verkoste ich eigentlich?
              $meineSumme = 0.0;
              $meineAnz = 0;
              $delta = 0.0;
              $deltaAnz = 0;
              $sortenZaehler = [];
              $wgZaehler = [];
              $ortZaehler = [];
              $top = null;
              foreach ($eintraege as $ein) {
                  if ($ein['sterne'] !== null) {
                      $meineSumme += $ein['sterne'];
                      $meineAnz++;
                      if ($top === null || $ein['sterne'] > $top['sterne']) {
                          $top = $ein;
                      }
                      // Vergleich: Wie bewerten die anderen dieselben Getränke?
                      $andereSumme = 0.0;
                      $andereAnz = 0;
                      foreach (bewertungenFuer($daten, $ein['c']['id']) as $ab) {
                          if (mb_strtolower(trim((string)$ab['person'])) === $ich) {
                              continue;
                          }
                          foreach ($ab['werte'] as $w) { $andereSumme += (int)$w; $andereAnz++; }
                      }
                      if ($andereAnz > 0) {
                          $delta += $ein['sterne'] - ($andereSumme / $andereAnz);
                          $deltaAnz++;
                      }
                  }
                  foreach (array_filter(array_map('trim', explode(',', (string)($ein['c']['rebsorte'] ?? '')))) as $sorte) {
                      $sortenZaehler[$sorte] = ($sortenZaehler[$sorte] ?? 0) + 1;
                  }
                  if ($ein['weingut'] !== '') { $wgZaehler[$ein['weingut']] = ($wgZaehler[$ein['weingut']] ?? 0) + 1; }
                  if ($ein['ort'] !== '') { $ortZaehler[$ein['ort']] = ($ortZaehler[$ein['ort']] ?? 0) + 1; }
              }
              arsort($sortenZaehler);
              arsort($wgZaehler);
              arsort($ortZaehler);
              $meinSchnitt = $meineAnz > 0 ? $meineSumme / $meineAnz : null;
              $deltaSchnitt = $deltaAnz > 0 ? $delta / $deltaAnz : null;
            ?>
            <div id="profil-box" hidden>
              <div class="blatt">
                <div class="blatt-kopf">
                  <b>👤 <?= e(trim((string)$_SESSION['person'])) ?></b>
                  <button type="button" aria-label="Schließen">&#10005;</button>
                </div>
                <?php if ($meinProfil !== ''): ?>
                  <img class="avatar" src="<?= e(thumbUrl($meinProfil)) ?>" alt="" style="width:96px; height:96px; display:block; margin:0 auto 0.7rem;">
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data" style="margin-bottom:1rem;">
                  <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                  <input type="hidden" name="aktion" value="profil_foto_upload">
                  <?= fotoUploadFelder('pf-up') ?>
                </form>
                <?php if (count($eintraege) >= 2): ?>
                <h2>🪞 So verkostest du</h2>
              <?php if ($meinSchnitt !== null): ?>
                <p>Deine Durchschnittswertung: <b><?= number_format($meinSchnitt, 1, ',', '') ?> ★</b>
                <?php if ($deltaSchnitt !== null && abs($deltaSchnitt) >= 0.05): ?>
                  – du bewertest <b><?= number_format(abs($deltaSchnitt), 1, ',', '') ?> Sterne <?= $deltaSchnitt < 0 ? 'strenger' : 'großzügiger' ?></b> als deine Mitverkoster. <?= $deltaSchnitt < 0 ? '🧐' : '😄' ?>
                <?php elseif ($deltaSchnitt !== null): ?>
                  – du liegst fast genau auf Linie deiner Mitverkoster. 🎯
                <?php endif; ?></p>
              <?php endif; ?>
              <?php if ($sortenZaehler !== []): ?>
                <p>Deine häufigste Traube / dein häufigster Stil: <b><?= e((string)array_key_first($sortenZaehler)) ?></b> (<?= reset($sortenZaehler) ?>×)</p>
              <?php endif; ?>
              <?php if ($wgZaehler !== []): ?>
                <p>Dein meistbesuchtes Weingut: <b>🍇 <?= e((string)array_key_first($wgZaehler)) ?></b> (<?= reset($wgZaehler) ?>×)</p>
              <?php endif; ?>
              <?php if ($ortZaehler !== []): ?>
                <p>Dein häufigster Verkostungsort: <b>📍 <?= e((string)array_key_first($ortZaehler)) ?></b></p>
              <?php endif; ?>
              <?php if ($top !== null): ?>
                <p>Dein Favorit: <b><?= e($top['c']['name']) ?></b> mit <?= number_format((float)$top['sterne'], 1, ',', '') ?> ★</p>
              <?php endif; ?>
              <?php endif; ?>
              </div>
            </div>
          <?php if ($eintraege !== []): ?>
            <div class="sortier-leiste">Sortieren:
              <a class="<?= $msort === 'datum' ? 'aktiv' : '' ?>" href="?meine=1&amp;msort=datum">Datum</a>
              <a class="<?= $msort === 'name' ? 'aktiv' : '' ?>" href="?meine=1&amp;msort=name">Name</a>
              <a class="<?= $msort === 'tasting' ? 'aktiv' : '' ?>" href="?meine=1&amp;msort=tasting">Tasting</a>
              <a class="<?= $msort === 'weingut' ? 'aktiv' : '' ?>" href="?meine=1&amp;msort=weingut">Weingut</a>
              <a class="<?= $msort === 'sterne' ? 'aktiv' : '' ?>" href="?meine=1&amp;msort=sterne">Meine Sterne</a>
            </div>
            <input type="search" class="filter-feld" placeholder="🔍 Name, Traube, Weingut, Tasting suchen …" data-ziel="#meine-liste">
            <div id="meine-liste">
              <?php foreach ($eintraege as $ein): ?>
                <?php
                  $c = $ein['c'];
                  $fotos = mitTitelbild(fotosFuer($c['id']), (string)($c['titelbild'] ?? ''));
                  $meta = [];
                  $meta[] = date('d.m.Y', $ein['zeit']);
                  if (trim((string)($c['rebsorte'] ?? '')) !== '') { $meta[] = (string)$c['rebsorte']; }
                  if ($ein['weingut'] !== '') { $meta[] = '🍇 ' . $ein['weingut']; }
                  if ($ein['tasting'] !== '') { $meta[] = '👥 ' . $ein['tasting']; }
                  if ($ein['ort'] !== '') { $meta[] = '📍 ' . $ein['ort']; }
                  if ($ein['wetter'] !== '') { $meta[] = $ein['wetter']; }
                ?>
                <div class="card flasche filterbar">
                  <a class="flasche-link" href="?ergebnis=<?= e(rawurlencode($c['id'])) ?>">
                    <?php if ($fotos !== []): ?>
                      <img class="thumb" src="<?= e(thumbUrl($fotos[0])) ?>" alt="" loading="lazy">
                    <?php else: ?>
                      <span class="thumb platzhalter"><?= $KATEGORIEN_GETRAENKE[(string)($c['typ'] ?? 'champagner')][0] ?? '🍾' ?></span>
                    <?php endif; ?>
                    <span class="flasche-info">
                      <span class="f-name"><?= $KATEGORIEN_GETRAENKE[(string)($c['typ'] ?? 'champagner')][0] ?? '' ?> <?= e($c['name']) ?></span>
                      <span class="f-meta"><?= e(implode(' · ', $meta)) ?></span>
                      <span class="f-wertung"><?= sterneAnzeige($ein['sterne']) ?></span>
                      <?php if ($ein['notiz'] !== ''): ?>
                        <span class="f-meta" style="font-style:italic;">„<?= e(mb_strlen($ein['notiz']) > 90 ? mb_substr($ein['notiz'], 0, 90) . ' …' : $ein['notiz']) ?>“</span>
                      <?php endif; ?>
                    </span>
                  </a>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="card"><p style="color:var(--muted); font-style:italic;">Noch keine Bewertungen von dir – ab zum Verkosten! 🥂</p></div>
          <?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>
    <?php elseif ($ansicht === 'konto'): ?>
      <!-- ==================== BENUTZERKONTO ==================== -->
        <?php if ($benutzerAktiv === null): ?>
          <div class="card">
            <h2>👤 Anmelden</h2>
            <p class="anzahl" style="margin-bottom:0.7rem;">Kostenfrei, Anmeldung läuft nie ab. Registrierte legen sofort Tastings an (du + 1 Person); größere Runden schaltet der Administrator frei.</p>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
              <input type="hidden" name="aktion" value="benutzer_login">
              <input type="text" name="email" placeholder="E-Mail" maxlength="80" inputmode="email" autocomplete="email" required>
              <input type="password" name="passwort" placeholder="Passwort" autocomplete="current-password" required>
              <button class="knopf zweit" type="submit">Anmelden</button>
            </form>
          </div>
          <details class="card" open>
            <summary>✍️ Neu hier? Jetzt registrieren (kostenfrei)</summary>
            <p class="anzahl" style="margin:0.5rem 0 0.7rem;">Stammdaten vollständig ausfüllen – dein Konto ist sofort nutzbar, ganz ohne Wartezeit.</p>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
              <input type="hidden" name="aktion" value="benutzer_registrieren">
              <input type="text" name="vorname" placeholder="Vorname" maxlength="40" required>
              <input type="text" name="name" placeholder="Nachname" maxlength="40" required>
              <input type="text" name="email" placeholder="E-Mail" maxlength="80" inputmode="email" autocomplete="email" required>
              <input type="password" name="passwort" placeholder="Passwort (mind. 6 Zeichen)" minlength="6" required>
              <label style="display:flex; align-items:flex-start; gap:0.55rem; margin:0.6rem 0 0.8rem; font-size:0.92rem; cursor:pointer;">
                <input type="checkbox" name="agb" value="ja" required style="width:auto; margin-top:0.2rem; flex-shrink:0;">
                <span>Ich bin mindestens 18 Jahre alt und akzeptiere die <a href="?nutzung=1" target="_blank">Nutzungsbedingungen</a> von TasteLog. <b>(Pflicht)</b></span>
              </label>
              <button class="knopf" type="submit">Registrieren &amp; loslegen</button>
            </form>
          </details>
        <?php else: ?>
          <div class="card">
            <h2>👤 Dein Konto</h2>
            <p>Angemeldet als <b><?= e(trim((string)($benutzerAktiv['vorname'] ?? '') . ' ' . (string)($benutzerAktiv['name'] ?? ''))) ?></b>
              <span class="anzahl">(<?= e((string)($benutzerAktiv['email'] ?? '')) ?>)</span>
              <?php if (!empty($benutzerAktiv['admin'])): ?><span class="bewerter-chip">🛡️ Administrator</span><?php endif; ?>
            </p>
            <p class="anzahl" style="margin:0.3rem 0 0.6rem;">Dauerhaft angemeldet – die Anmeldung läuft nicht ab.</p>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
              <input type="hidden" name="aktion" value="benutzer_logout">
              <button class="loeschen" type="submit">Vom Konto abmelden</button>
            </form>
          </div>
        <?php endif; ?>

    <?php elseif ($ansicht === 'bewerten'): ?>
      <!-- ==================== BEWERTUNGSFORMULAR ==================== -->
      <?php if (!$eingeloggt): ?>
        <div class="card">
          <?= loginFormular('?bewerten=' . rawurlencode($aktiverChampagner['id'])) ?>
        </div>
      <?php else: ?>
        <?php
          // Bestehende Bewertung der Person vorbelegen (Name aus ?person=… oder aus der Sitzung)
          $formPerson = trim((string)($_GET['person'] ?? $personVorschlag));
          $vorhandene = null;
          if ($formPerson !== '') {
              foreach (bewertungenFuer($daten, $aktiverChampagner['id']) as $b) {
                  if (mb_strtolower($b['person']) === mb_strtolower($formPerson)) {
                      $vorhandene = $b;
                      break;
                  }
              }
          }
        ?>
        <?php if ($vorhandene !== null): ?>
          <div class="hinweis ok">Du änderst die Bewertung von <b><?= e($vorhandene['person']) ?></b> – die Antworten sind vorbelegt.</div>
        <?php endif; ?>
        <div class="wz-kopf">
          <span class="wz-titel"><?= e($aktiverChampagner['name']) ?></span>
          <button type="button" id="wz-info-knopf" aria-label="Verkostungs-Tipps">ℹ️ So geht's</button>
        </div>
        <form method="post" id="wizard-form">
          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
          <input type="hidden" name="aktion" value="bewerten">
          <input type="hidden" name="champagner_id" value="<?= e($aktiverChampagner['id']) ?>">
          <input type="hidden" name="vk" value="<?= e((string)($_GET['vk'] ?? '')) ?>">
          <input type="hidden" name="detail" id="detail-feld" value="">
          <input type="hidden" name="geo_lat" id="geo-lat" value="">
          <input type="hidden" name="geo_lon" id="geo-lon" value="">
          <?php foreach ($KATEGORIEN as $schluessel => $info): ?>
            <input type="hidden" name="<?= e($schluessel) ?>" id="stern-<?= e($schluessel) ?>" value="">
          <?php endforeach; ?>

          <div class="wz-fortschritt"><span id="wz-balken"></span></div>

          <?php
            $typAktiv = (string)($aktiverChampagner['typ'] ?? 'champagner');
            $schritte = wizardSchritte($typAktiv);
            $letzterSchritt = count($schritte) + 1;
          ?>
          <?php foreach ($schritte as $sIndex => $schritt): ?>
            <div class="wz-schritt" data-schritt="<?= $sIndex + 1 ?>"<?= $sIndex > 0 ? ' hidden' : '' ?>>
              <h2><?= $schritt['titel'] ?></h2>
              <?php $blockOffen = false; ?>
              <?php foreach ($schritt['fragen'] as $f): ?>
                <?php
                  if (!empty($f['block']) && !$blockOffen) { echo '<div id="aromen-block">'; $blockOffen = true; }
                  elseif (empty($f['block']) && $blockOffen) { echo '</div>'; $blockOffen = false; }
                ?>
                <p class="wz-frage"><?= $f['frage'] ?></p>
                <div class="wz-kacheln<?= !empty($f['mehrfach']) ? ' mehrfach' : '' ?>" data-feld="<?= e($f['feld']) ?>">
                  <?php foreach ($f['optionen'] as [$wert, $emoji, $label]): ?>
                    <button type="button" data-wert="<?= e($wert) ?>"><?= $emoji ?><span><?= $label ?></span></button>
                  <?php endforeach; ?>
                </div>
              <?php endforeach; ?>
              <?php if ($blockOffen) { echo '</div>'; } ?>
            </div>
          <?php endforeach; ?>

          <div class="wz-schritt" data-schritt="<?= $letzterSchritt ?>" hidden>
            <h2>✅ Fertig!</h2>
            <label class="wz-label">Dein Name</label>
            <input type="text" id="wz-person" placeholder="Dein Name" value="<?= e($formPerson) ?>" maxlength="40">
            <label class="wz-label">Flaschen mitgenommen/gekauft</label>
            <input type="number" id="wz-flaschen" min="0" max="99" inputmode="numeric" value="<?= (int)($vorhandene['flaschen'] ?? 0) ?>">
            <label class="wz-label">Notiz <small>(optional)</small></label>
            <textarea id="wz-notiz" placeholder="Eigene Eindrücke …" maxlength="500" rows="2"><?= e((string)($vorhandene['notiz'] ?? '')) ?></textarea>
            <p class="wz-frage" style="margin-top:1rem;">Daraus ergibt sich deine Wertung – antippen zum Feinjustieren:</p>
            <div id="wz-sterne-vorschau"></div>
          </div>

          <div class="wz-nav">
            <button type="button" class="knopf zweit" id="wz-zurueck" hidden>Zurück</button>
            <button type="button" class="knopf" id="wz-weiter">Weiter</button>
            <button type="submit" class="knopf" id="wz-fertig" hidden>💾 Speichern</button>
          </div>
          <p class="abmelden" style="text-align:center;"><a href="#" id="wz-ueberspringen">Diesen Schritt überspringen</a></p>
        </form>

        <script id="wz-vorbelegt" type="application/json"><?= json_encode([
            'detail' => $vorhandene['detail'] ?? new stdClass(),
            'werte'  => $vorhandene['werte'] ?? new stdClass(),
            'kategorien' => array_keys($KATEGORIEN),
            'titel' => array_map(fn(array $i): string => $i[0], kategorienFuer($typAktiv, $KATEGORIEN)),
        ], JSON_UNESCAPED_UNICODE) ?></script>
      <?php endif; ?>

    <?php elseif ($ansicht === 'ergebnis'): ?>
      <!-- ==================== ERGEBNISSEITE ==================== -->
      <?php
        $bewertungen = bewertungenFuer($daten, $aktiverChampagner['id']);
        $gesamt      = gesamtSchnitt($bewertungen);
        $typAktiv    = (string)($aktiverChampagner['typ'] ?? 'champagner');
        $KATS_TYP    = kategorienFuer($typAktiv, $KATEGORIEN);
        $schnitte    = kategorieSchnitte($bewertungen, $KATS_TYP);
      ?>
      <?php $flaschenGesamt = array_sum(array_map(fn($b) => (int)($b['flaschen'] ?? 0), $bewertungen)); ?>
      <div class="card">
        <div class="gesamt">
          <?= sterneAnzeige($gesamt) ?>
          <span class="anzahl"><?= count($bewertungen) ?> Bewertung(en)<?= $flaschenGesamt > 0 ? ' &middot; ' . $flaschenGesamt . ' Flasche(n)' : '' ?></span>
        </div>
        <?php
          // Sofort sichtbar: wer hat schon bewertet, wer aus dem Tasting fehlt noch?
          $bewertetVon = array_map(fn($b) => mb_strtolower(trim((string)$b['person'])), $bewertungen);
          $getraenkTasting = null;
          foreach ($daten['tastings'] as $t) {
              if ($t['id'] === (string)($aktiverChampagner['tasting_id'] ?? '')) {
                  $getraenkTasting = $t;
                  break;
              }
          }
        ?>
        <p style="margin-top:0.7rem;">
          <?php foreach ($bewertungen as $b): ?>
            <?php $s = 0; foreach ($KATEGORIEN as $bk => $bi) { $s += (int)($b['werte'][$bk] ?? 0); } ?>
            <span class="bewerter-chip">✅ <?= e($b['person']) ?> <b><?= number_format($s / count($KATEGORIEN), 1, ',', '') ?></b></span>
          <?php endforeach; ?>
          <?php if ($getraenkTasting !== null): ?>
            <?php foreach (($getraenkTasting['teilnehmer'] ?? []) as $p): ?>
              <?php if (!in_array(mb_strtolower(trim((string)$p['name'])), $bewertetVon, true)): ?>
                <span class="bewerter-chip offen">⏳ <?= e($p['name']) ?></span>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php endif; ?>
          <?php if ($bewertungen === [] && $getraenkTasting === null): ?>
            <span class="anzahl">Noch keine Bewertungen – sei die/der Erste!</span>
          <?php endif; ?>
        </p>
        <?php $meineB = meineBewertung($daten, $aktiverChampagner['id']); ?>
        <div class="knopfreihe" style="margin-top:0.4rem; align-items:center;">
          <a class="knopf" href="?bewerten=<?= e(rawurlencode($aktiverChampagner['id'])) ?>"><?= $meineB !== null ? '🔁 Noch einmal bewerten' : 'Jetzt selbst bewerten' ?></a>
          <?php if ($meineB !== null): ?>
            <span class="anzahl">Du hast am <?= date('d.m.Y \u\m H:i', (int)($meineB['zeit'] ?? 0)) ?> Uhr bewertet.</span>
          <?php endif; ?>
        </div>
      </div>

      <?php
        $fotos = mitTitelbild(fotosFuer($aktiverChampagner['id']), (string)($aktiverChampagner['titelbild'] ?? ''));
        $erkanntVorschlag = null;
        if (($_SESSION['erkannt']['cid'] ?? '') === $aktiverChampagner['id']) {
            $erkanntVorschlag = $_SESSION['erkannt'];
        }
      ?>
      <?php if ($bewertungen !== []): ?>
        <div class="card">
          <h2>Durchschnitt je Kategorie</h2>
          <?php foreach ($KATS_TYP as $schluessel => [$titel, $frage]): ?>
            <div class="ergebnis-kategorie">
              <span><?= e($titel) ?></span>
              <?= sterneAnzeige($schnitte[$schluessel]) ?>
            </div>
          <?php endforeach; ?>
        </div>

        <?php if ($bewertungen !== []): ?>
          <div class="card">
            <h2>Notizen &amp; Umfeld</h2>
            <?php foreach ($bewertungen as $b): ?>
              <?php
                $umfeld = [date('d.m.Y, H:i', (int)($b['zeit'] ?? 0)) . ' Uhr'];
                if (trim((string)($b['ort'] ?? '')) !== '') { $umfeld[] = '📍 ' . (string)$b['ort']; }
                if (trim((string)($b['wetter'] ?? '')) !== '') { $umfeld[] = (string)$b['wetter']; }
                $dabei = mitDabei($bewertungen, $b);
                if ($dabei !== []) { $umfeld[] = '👥 zusammen mit ' . implode(', ', $dabei); }
                $warLive = bewertungWarLive($b, $aktiverChampagner);
              ?>
              <p class="notiz" style="margin-bottom:0.7rem;">
                <b><?= e($b['person']) ?></b> <span class="anzahl">(<?= e(implode(' · ', $umfeld)) ?>)</span>
                <?php if (!$warLive): ?>
                  <span class="bewerter-chip offen" title="Nicht im Verkostungs-Moment bewertet – Ort und Wetter beschreiben den Bewertungs-Zeitpunkt">⏳ nachträglich bewertet</span>
                <?php endif; ?>
                <?php if (trim((string)($b['notiz'] ?? '')) !== ''): ?><br><?= nl2br(e((string)$b['notiz'])) ?><?php endif; ?>
              </p>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="card zu">
          <h2>Einzelbewertungen</h2>
          <div class="tabelle-scroll">
            <table>
              <thead>
                <tr>
                  <th>Person</th>
                  <?php foreach ($KATS_TYP as [$titel, $frage]): ?>
                    <th><?= e(mb_substr($titel, 0, 4)) ?>.</th>
                  <?php endforeach; ?>
                  <th>Ø</th>
                  <th>Fl.</th>
                  <?php if ($eingeloggt): ?><th></th><?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($bewertungen as $b): ?>
                  <tr>
                    <td><?= e($b['person']) ?></td>
                    <?php $summe = 0; foreach ($KATEGORIEN as $schluessel => $info): $w = (int)($b['werte'][$schluessel] ?? 0); $summe += $w; ?>
                      <td><?= $w ?></td>
                    <?php endforeach; ?>
                    <td><b><?= number_format($summe / count($KATEGORIEN), 1, ',', '') ?></b></td>
                    <td><?= (int)($b['flaschen'] ?? 0) ?></td>
                    <?php if ($eingeloggt): ?>
                      <td>
                        <a href="?bewerten=<?= e(rawurlencode($aktiverChampagner['id'])) ?>&amp;person=<?= e(rawurlencode($b['person'])) ?>">ändern</a>
                        <form method="post" style="display:inline" onsubmit="return confirm('Bewertung von <?= e($b['person']) ?> wirklich löschen?');">
                          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                          <input type="hidden" name="aktion" value="bewertung_loeschen">
                          <input type="hidden" name="champagner_id" value="<?= e($aktiverChampagner['id']) ?>">
                          <input type="hidden" name="person" value="<?= e($b['person']) ?>">
                          <button class="loeschen" type="submit">löschen</button>
                        </form>
                      </td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="anzahl" style="margin-top:0.5rem;">Spalten: <?php $t = []; foreach ($KATS_TYP as [$titel, $frage]) { $t[] = mb_substr($titel, 0, 4) . '. = ' . $titel; } echo e(implode(', ', $t)); ?>, Fl. = Flaschen</p>
        </div>
      <?php endif; ?>

      <div class="card">
        <h2>Fotos</h2>
        <?php if ($fotos === []): ?>
          <p style="color:var(--muted); font-style:italic;">Noch keine Fotos zu diesem Getränk.</p>
        <?php else: ?>
          <?php $titelbildAktuell = (string)($aktiverChampagner['titelbild'] ?? ''); ?>
          <div class="foto-galerie">
            <?php foreach ($fotos as $i => $foto): ?>
              <?php $istTitelbild = $titelbildAktuell !== '' ? $foto === $titelbildAktuell : $i === 0; ?>
              <div class="foto">
                <a href="bilder/<?= e(rawurlencode($foto)) ?>" target="_blank">
                  <img src="<?= e(thumbUrl($foto)) ?>" alt="" loading="lazy">
                </a>
                <?php if ($istTitelbild): ?>
                  <span class="titelbild-marke" title="Titelbild – erscheint als Vorschau in den Listen">⭐</span>
                <?php elseif ($eingeloggt): ?>
                  <form method="post" class="titelbild-form">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <input type="hidden" name="aktion" value="titelbild_setzen">
                    <input type="hidden" name="typ" value="champagner">
                    <input type="hidden" name="id" value="<?= e($aktiverChampagner['id']) ?>">
                    <input type="hidden" name="datei" value="<?= e($foto) ?>">
                    <button type="submit" title="Als Titelbild festlegen">☆</button>
                  </form>
                <?php endif; ?>
                <?php if ($eingeloggt): ?>
                  <form method="post" onsubmit="return confirm('Dieses Foto wirklich löschen?');">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <input type="hidden" name="aktion" value="foto_loeschen">
                    <input type="hidden" name="datei" value="<?= e($foto) ?>">
                    <button type="submit" title="Foto löschen">&#10005;</button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
          <?php if ($eingeloggt && count($fotos) > 1): ?>
            <p class="anzahl" style="margin-top:0.5rem;">⭐ = Titelbild (Vorschau in den Listen). Mit ☆ legst du ein anderes fest.</p>
          <?php endif; ?>
        <?php endif; ?>
        <?php if ($eingeloggt): ?>
          <form method="post" enctype="multipart/form-data" style="margin-top:1rem;">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="aktion" value="foto_upload">
            <input type="hidden" name="champagner_id" value="<?= e($aktiverChampagner['id']) ?>">
            <?= fotoUploadFelder('c-up') ?>
          </form>
        <?php else: ?>
          <div style="margin-top:1rem;">
            <?= loginFormular('?ergebnis=' . rawurlencode($aktiverChampagner['id'])) ?>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($eingeloggt): ?>
        <details class="card"<?= $erkanntVorschlag !== null ? ' open' : '' ?>>
          <summary>✏️ Stammdaten bearbeiten</summary>
          <?php if ($fotos !== []): ?>
            <form method="post" style="margin-bottom:0.9rem;">
              <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
              <input type="hidden" name="aktion" value="erkennen_bestehend">
              <input type="hidden" name="champagner_id" value="<?= e($aktiverChampagner['id']) ?>">
              <?php if (count($fotos) > 1): ?>
                <p class="anzahl" style="margin-bottom:0.4rem;">Welches Foto zeigt das Etikett? Antippen:</p>
                <div class="foto-wahl">
                  <?php foreach ($fotos as $i => $f): ?>
                    <label class="foto-wahl-item">
                      <input type="radio" name="foto" value="<?= e($f) ?>"<?= $i === 0 ? ' checked' : '' ?>>
                      <img src="<?= e(thumbUrl($f)) ?>" alt="">
                    </label>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <button class="knopf zweit" type="submit">📷&nbsp; Etikett vom Foto erkennen</button>
            </form>
          <?php endif; ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="aktion" value="champagner_bearbeiten">
            <input type="hidden" name="champagner_id" value="<?= e($aktiverChampagner['id']) ?>">
            <input type="text" name="name" value="<?= e($erkanntVorschlag !== null && $erkanntVorschlag['name'] !== '' ? $erkanntVorschlag['name'] : $aktiverChampagner['name']) ?>" maxlength="60" required>
            <select name="kat">
              <?php foreach ($KATEGORIEN_GETRAENKE as $kS => [$kI, $kN]): ?>
                <option value="<?= e($kS) ?>"<?= $typAktiv === $kS ? ' selected' : '' ?>><?= $kI ?> <?= e($kN) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="text" name="rebsorte" id="rebsorte-edit" value="<?= e($erkanntVorschlag !== null && ($erkanntVorschlag['rebsorte'] ?? '') !== '' ? $erkanntVorschlag['rebsorte'] : (string)($aktiverChampagner['rebsorte'] ?? '')) ?>" placeholder="<?= e(sortenLabel($typAktiv)) ?>" maxlength="80">
            <?= sortenChips('rebsorte-edit', $typAktiv) ?>
            <input type="text" name="preis" value="<?= e((string)($aktiverChampagner['preis'] ?? '')) ?>" placeholder="Preis, z. B. 39,90 € (optional)" maxlength="20">
            <?php if ($daten['tastings'] !== []): ?>
              <select name="tasting_id">
                <option value="">👥 ohne Tasting</option>
                <?php foreach ($daten['tastings'] as $t): ?>
                  <option value="<?= e($t['id']) ?>"<?= (string)($aktiverChampagner['tasting_id'] ?? '') === $t['id'] ? ' selected' : '' ?>>👥 <?= e($t['titel']) ?></option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
            <button class="knopf zweit" type="submit">Speichern</button>
          </form>
        </details>
      <?php endif; ?>

      <?php $recherche = trim((string)($aktiverChampagner['recherche'] ?? '')); ?>
      <?php if ($recherche !== '' || $eingeloggt): ?>
        <div class="card zu">
          <h2>Recherche</h2>
          <?php if ($recherche !== ''): ?>
            <p><?= verlinken($recherche) ?></p>
          <?php else: ?>
            <p style="color:var(--muted); font-style:italic;">Noch keine Recherche zu diesem Champagner.</p>
          <?php endif; ?>
          <?php if ($eingeloggt): ?>
            <form method="post" style="margin-top:0.8rem;">
              <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
              <input type="hidden" name="aktion" value="champagner_recherche">
              <input type="hidden" name="champagner_id" value="<?= e($aktiverChampagner['id']) ?>">
              <button class="knopf zweit" type="submit">🔎&nbsp; Im Internet recherchieren</button>
            </form>
            <p class="anzahl" style="margin-top:0.4rem;">Dauert bis zu einer halben Minute; erneutes Recherchieren ersetzt den Text.</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php $zugeordnet = weingutHolen($daten, (string)($aktiverChampagner['weingut_id'] ?? '')); ?>
      <div class="card zu">
        <h2>Weingut</h2>
        <?php if ($zugeordnet !== null): ?>
          <p><a href="?weingut=<?= e(rawurlencode($zugeordnet['id'])) ?>"><?= e($zugeordnet['name']) ?></a></p>
        <?php else: ?>
          <p style="color:var(--muted); font-style:italic;">Noch keinem Weingut zugeordnet.</p>
        <?php endif; ?>
        <?php if ($eingeloggt): ?>
          <?php
            // Von der Erkennung vorgeschlagenes Weingut: vorhandenes vorauswählen, sonst als neues vorschlagen
            $vorschlagWeingutId = (string)($aktiverChampagner['weingut_id'] ?? '');
            $vorschlagWeingutNeu = '';
            if ($erkanntVorschlag !== null && $erkanntVorschlag['weingut'] !== '') {
                $gefundenes = null;
                foreach ($daten['weingueter'] as $w) {
                    if (mb_strtolower($w['name']) === mb_strtolower($erkanntVorschlag['weingut'])) {
                        $gefundenes = $w;
                        break;
                    }
                }
                if ($gefundenes !== null) {
                    $vorschlagWeingutId = $gefundenes['id'];
                } else {
                    $vorschlagWeingutNeu = $erkanntVorschlag['weingut'];
                }
            }
          ?>
          <form method="post" style="margin-top:0.6rem;">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="aktion" value="champagner_weingut">
            <input type="hidden" name="champagner_id" value="<?= e($aktiverChampagner['id']) ?>">
            <?php if ($daten['weingueter'] !== []): ?>
              <select name="weingut_id">
                <option value="">– kein Weingut –</option>
                <?php foreach ($daten['weingueter'] as $w): ?>
                  <option value="<?= e($w['id']) ?>" <?= $vorschlagWeingutId === $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
            <input type="text" name="weingut_neu" value="<?= e($vorschlagWeingutNeu) ?>" placeholder="… oder neues Weingut eintragen" maxlength="60">
            <button class="knopf zweit" type="submit">Zuordnung speichern</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="knopfreihe">
        <a class="knopf" href="?bewerten=<?= e(rawurlencode($aktiverChampagner['id'])) ?>">Jetzt selbst bewerten</a>
        <a class="knopf zweit" href="?liste=1">Zur Liste</a>
      </div>
      <?php if ($eingeloggt): ?>
        <form method="post" onsubmit="return confirm('„<?= e($aktiverChampagner['name']) ?>“ samt aller Bewertungen und Fotos löschen?');" style="margin-top:0.8rem;">
          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
          <input type="hidden" name="aktion" value="champagner_loeschen">
          <input type="hidden" name="id" value="<?= e($aktiverChampagner['id']) ?>">
          <button class="loeschen" type="submit">Diesen Champagner löschen</button>
        </form>
      <?php endif; ?>

    <?php elseif ($ansicht === 'neu'): ?>
      <!-- ==================== SCHNELL-ERFASSUNG ==================== -->
      <?php if (!$eingeloggt): ?>
        <div class="card"><?= loginFormular('?neu=1') ?></div>
      <?php elseif ((int)$_GET['neu'] === 2): ?>
        <?php
          $neu = $_SESSION['neu'] ?? ['foto' => '', 'name' => '', 'weingut' => '', 'vk' => ''];
          $vkAktiv = (string)($neu['vk'] ?? '');
          if ($vkAktiv === '') {
              $vkAktiv = (string)($_GET['vk'] ?? '');
          }
          if ($vkAktiv !== 'ohne' && !preg_match('/^[a-f0-9]{8}$/', $vkAktiv)) {
              $vkAktiv = '';
          }
          $erkanntesWeingut = null;
          if ($neu['weingut'] !== '') {
              foreach ($daten['weingueter'] as $w) {
                  if (mb_strtolower($w['name']) === mb_strtolower($neu['weingut'])) {
                      $erkanntesWeingut = $w;
                      break;
                  }
              }
          }
          if ($erkanntesWeingut === null && preg_match('/^[a-f0-9]{8}$/', $vkAktiv)) {
              // Verkosten-Kontext: das Weingut, an dem wir gerade sind
              $erkanntesWeingut = weingutHolen($daten, $vkAktiv);
          }
        ?>
        <?php if ($neu['name'] !== '' || $neu['weingut'] !== ''): ?>
          <div class="hinweis ok">Etikett erkannt – bitte kurz prüfen und ggf. korrigieren.</div>
        <?php elseif ($neu['foto'] !== ''): ?>
          <div class="hinweis ok">Foto gespeichert. Trag Name und Weingut ein – dann geht es direkt zur Bewertung.</div>
        <?php endif; ?>
        <?php
          $neuKat = (string)($neu['kat'] ?? ($_GET['kat'] ?? 'champagner'));
          if (!isset($KATEGORIEN_GETRAENKE[$neuKat])) {
              $neuKat = 'champagner';
          }
        ?>
        <form method="post" class="card">
          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
          <input type="hidden" name="aktion" value="schnell_anlegen">
          <input type="hidden" name="vk" value="<?= e($vkAktiv) ?>">
          <input type="hidden" name="foto" value="<?= e($neu['foto']) ?>">
          <?php if ($neu['foto'] !== ''): ?>
            <img src="<?= e(thumbUrl($neu['foto'])) ?>" alt="" style="max-width:180px; border-radius:10px; border:1px solid var(--border); display:block; margin-bottom:1rem;">
          <?php endif; ?>
          <h2>Getränk</h2>
          <select name="kat">
            <?php foreach ($KATEGORIEN_GETRAENKE as $kS => [$kI, $kN]): ?>
              <option value="<?= e($kS) ?>"<?= $neuKat === $kS ? ' selected' : '' ?>><?= $kI ?> <?= e($kN) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="name" value="<?= e($neu['name']) ?>" placeholder="Name des Getränks" maxlength="60" required>
          <input type="text" name="rebsorte" id="rebsorte-neu" value="<?= e((string)($neu['rebsorte'] ?? '')) ?>" placeholder="<?= e(sortenLabel($neuKat)) ?>" maxlength="80">
          <?= sortenChips('rebsorte-neu', $neuKat) ?>
          <input type="text" name="preis" placeholder="Preis, z. B. 39,90 € (optional)" maxlength="20">
          <h2>Weingut</h2>
          <?php if ($daten['weingueter'] !== []): ?>
            <select name="weingut_id">
              <option value="">– vorhandenes Weingut wählen –</option>
              <?php foreach ($daten['weingueter'] as $w): ?>
                <option value="<?= e($w['id']) ?>"<?= $erkanntesWeingut !== null && $erkanntesWeingut['id'] === $w['id'] ? ' selected' : '' ?>><?= e($w['name']) ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
          <input type="text" name="weingut_neu" value="<?= e($erkanntesWeingut === null ? $neu['weingut'] : '') ?>" placeholder="… oder neues Weingut eintragen" maxlength="60">
          <div class="knopfreihe" style="margin-top:0.6rem;">
            <button class="knopf" type="submit">Speichern &amp; bewerten</button>
            <a class="knopf zweit" href="?neu=1">Anderes Foto</a>
          </div>
        </form>
      <?php else: ?>
        <form method="post" enctype="multipart/form-data" class="card" id="schnellfoto">
          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
          <input type="hidden" name="aktion" value="schnell_foto">
          <input type="hidden" name="vk" value="<?= e((string)($_GET['vk'] ?? '')) ?>">
          <input type="hidden" name="kat" value="<?= e(isset($KATEGORIEN_GETRAENKE[(string)($_GET['kat'] ?? '')]) ? (string)$_GET['kat'] : 'champagner') ?>">
          <h2>1. Etikett fotografieren</h2>
          <p style="margin-bottom:0.9rem;">Mach ein Foto vom Etikett – oder wähl ein vorhandenes Bild aus. Danach geht es automatisch weiter.</p>
          <input type="file" name="fotos[]" accept="image/*" capture="environment" id="foto-kamera" style="display:none;">
          <input type="file" name="fotos[]" accept="image/*" multiple id="foto-galerie" style="display:none;">
          <div class="knopfreihe">
            <label class="knopf" for="foto-kamera" id="kamera-label">📷&nbsp; Foto aufnehmen</label>
            <label class="knopf zweit" for="foto-galerie" id="galerie-label">🖼️&nbsp; Aus Galerie wählen</label>
            <a class="knopf zweit" href="?neu=2<?= isset($_GET['vk']) ? '&amp;vk=' . e(rawurlencode((string)$_GET['vk'])) : '' ?><?= isset($_GET['kat']) ? '&amp;kat=' . e(rawurlencode((string)$_GET['kat'])) : '' ?>">Ohne Foto</a>
          </div>
          <noscript>
            <p style="margin-top:0.8rem;">Bitte Datei oben wählen und dann:</p>
            <button class="knopf" type="submit">Weiter</button>
          </noscript>
        </form>
        <script>
          ['foto-kamera', 'foto-galerie'].forEach(function (id) {
            var input = document.getElementById(id);
            input.addEventListener('change', function () {
              if (!input.files || input.files.length === 0) { return; }
              document.getElementById('kamera-label').textContent = 'Wird hochgeladen …';
              document.getElementById('galerie-label').style.display = 'none';
              document.getElementById('schnellfoto').submit();
            });
          });
        </script>
      <?php endif; ?>

    <?php elseif ($ansicht === 'wneu'): ?>
      <!-- ==================== WEINGUT-SCHNELL-ERFASSUNG ==================== -->
      <?php if (!$eingeloggt): ?>
        <div class="card"><?= loginFormular('?wneu=1') ?></div>
      <?php elseif ((int)$_GET['wneu'] === 2): ?>
        <?php
          $wneu = $_SESSION['wneu'] ?? ['foto' => '', 'name' => '', 'kontakt' => '', 'alternativen' => [], 'bekannt' => ''];
          $bekanntesWeingut = weingutHolen($daten, (string)($wneu['bekannt'] ?? ''));
        ?>
        <?php if ($bekanntesWeingut !== null): ?>
          <div class="card" style="border-left:5px solid var(--accent);">
            <h2>Kennst du schon! 🍇</h2>
            <p>Dieses Weingut hast du bereits erfasst: <b><?= e($bekanntesWeingut['name']) ?></b>.</p>
            <div class="knopfreihe" style="margin-top:0.6rem;">
              <a class="knopf" href="?vk=<?= e(rawurlencode($bekanntesWeingut['id'])) ?>">Dort weiterverkosten</a>
            </div>
            <p class="anzahl" style="margin-top:0.5rem;">… oder lege es unten trotzdem neu an (nur nötig, wenn es ein anderes ist).</p>
          </div>
        <?php elseif ($wneu['name'] !== ''): ?>
          <div class="hinweis ok">Erzeuger am Standort gefunden – bitte kurz prüfen und ggf. korrigieren.</div>
        <?php elseif ($wneu['kontakt'] !== ''): ?>
          <div class="hinweis ok">Standort aus dem Foto erkannt – der Name ließ sich nicht sicher bestimmen, bitte eintragen.</div>
        <?php endif; ?>
        <form method="post" class="card">
          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
          <input type="hidden" name="aktion" value="weingut_foto_anlegen">
          <input type="hidden" name="foto" value="<?= e($wneu['foto']) ?>">
          <?php if ($wneu['foto'] !== ''): ?>
            <img src="<?= e(thumbUrl($wneu['foto'])) ?>" alt="" style="max-width:180px; border-radius:10px; border:1px solid var(--border); display:block; margin-bottom:1rem;">
          <?php endif; ?>
          <h2>Weingut</h2>
          <input type="text" name="name" id="wneu-name" value="<?= e($wneu['name']) ?>" placeholder="Name des Weinguts" maxlength="60" required>
          <?php if (($wneu['alternativen'] ?? []) !== []): ?>
            <p class="anzahl" style="margin:-0.3rem 0 0.3rem;">Oder war es eines davon? Antippen:</p>
            <div class="knopfreihe" style="margin-bottom:0.8rem;">
              <?php foreach ($wneu['alternativen'] as $alt): ?>
                <button type="button" class="knopf zweit alternative" data-name="<?= e($alt) ?>"><?= e($alt) ?></button>
              <?php endforeach; ?>
            </div>
            <script>
              document.querySelectorAll('.alternative').forEach(function (k) {
                k.addEventListener('click', function () {
                  document.getElementById('wneu-name').value = k.dataset.name;
                });
              });
            </script>
          <?php endif; ?>
          <textarea name="kontakt" maxlength="900" rows="4" placeholder="Kontakt/Standort (füllt sich aus dem Foto-GPS)"><?= e($wneu['kontakt']) ?></textarea>
          <textarea name="notiz" maxlength="1000" rows="3" placeholder="Notiz, z. B. Besuch am … (optional)"></textarea>
          <div class="knopfreihe" style="margin-top:0.6rem;">
            <button class="knopf" type="submit">Weingut anlegen</button>
            <a class="knopf zweit" href="?wneu=1">Anderes Foto</a>
          </div>
        </form>
      <?php else: ?>
        <form method="post" enctype="multipart/form-data" class="card" id="wneufoto">
          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
          <input type="hidden" name="aktion" value="weingut_foto_neu">
          <input type="hidden" name="vkmodus" value="<?= isset($_GET['vkmodus']) ? '1' : '' ?>">
          <h2>1. Weingut fotografieren</h2>
          <p style="margin-bottom:0.9rem;">Mach vor Ort ein Foto (Gebäude, Hof, Schild) – aus den GPS-Daten des Fotos ermitteln wir Standort und Erzeuger. Danach geht es automatisch weiter.</p>
          <input type="file" name="fotos[]" accept="image/*" capture="environment" id="wfoto-kamera" style="display:none;">
          <input type="file" name="fotos[]" accept="image/*" multiple id="wfoto-galerie" style="display:none;">
          <div class="knopfreihe">
            <label class="knopf" for="wfoto-kamera" id="wkamera-label">📷&nbsp; Foto aufnehmen</label>
            <label class="knopf zweit" for="wfoto-galerie" id="wgalerie-label">🖼️&nbsp; Aus Galerie wählen</label>
            <a class="knopf zweit" href="?wneu=2">Ohne Foto</a>
          </div>
          <p class="anzahl" style="margin-top:0.8rem;">iPhone-Tipp: Im Auswahldialog oben „Optionen" → „Standort" einschalten, sonst fehlen die GPS-Daten.</p>
          <noscript>
            <button class="knopf" type="submit">Weiter</button>
          </noscript>
        </form>
        <script>
          ['wfoto-kamera', 'wfoto-galerie'].forEach(function (id) {
            var input = document.getElementById(id);
            input.addEventListener('change', function () {
              if (!input.files || input.files.length === 0) { return; }
              document.getElementById('wkamera-label').textContent = 'Wird hochgeladen und Standort ermittelt …';
              document.getElementById('wgalerie-label').style.display = 'none';
              document.getElementById('wneufoto').submit();
            });
          });
        </script>
      <?php endif; ?>

    <?php elseif ($ansicht === 'fotos'): ?>
      <!-- ==================== FOTOALBUM ==================== -->
      <div class="knopfreihe" style="margin-bottom:1.2rem;">
        <a class="knopf zweit" href="?liste=1">Champagner</a>
        <a class="knopf zweit" href="?weingueter=1">Weingüter</a>
        <a class="knopf" href="?fotos=1">Fotos</a>
      </div>

      <?php
        // Fotoalbum des aktuellen Tastings (mit „Alle“ als Archiv):
        // zugeordnet (Flasche/Weingut/Tasting) und nicht zugeordnet (div-)
        $alleFotosZeigen = isset($_GET['alle']) && $istAdmin; // Archiv über alle Tastings: Admin-Funktion
        $albumTasting = null;
        foreach ($daten['tastings'] as $tt) {
            if ($tt['id'] === $blickTastingId) {
                $albumTasting = $tt;
                break;
            }
        }
        $albumWgIds = weingutIdsImTasting($daten, $blickTastingId);
        $alleDateien = glob(BILDER_DIR . '/*.{jpg,jpeg,png,gif,webp,JPG,JPEG,PNG,GIF,WEBP}', GLOB_BRACE) ?: [];
        usort($alleDateien, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $zugeordnet = [];
        $offen = [];
        $ausgeblendet = 0;
        foreach ($alleDateien as $pfad) {
            $basis = basename($pfad);
            if (preg_match('/^wg-([a-f0-9]{8})-/', $basis, $m)) {
                if (!$alleFotosZeigen && !isset($albumWgIds[$m[1]])) { $ausgeblendet++; continue; }
                $w = weingutHolen($daten, $m[1]);
                $zugeordnet[] = ['datei' => $basis, 'text' => '🍇 ' . ($w['name'] ?? 'Weingut'), 'link' => '?weingut=' . rawurlencode($m[1])];
            } elseif (preg_match('/^ts-([a-f0-9]{8})-/', $basis, $m)) {
                if (!$alleFotosZeigen && $m[1] !== $blickTastingId) { $ausgeblendet++; continue; }
                $t = null;
                foreach ($daten['tastings'] as $tt) { if ($tt['id'] === $m[1]) { $t = $tt; break; } }
                $zugeordnet[] = ['datei' => $basis, 'text' => '👥 ' . ($t['titel'] ?? 'Tasting'), 'link' => '?tasting=' . rawurlencode($m[1])];
            } elseif (preg_match('/^([a-f0-9]{8})-/', $basis, $m)) {
                $c = champagnerHolen($daten, $m[1]);
                if (!$alleFotosZeigen && ($c === null || !champagnerInTasting($c, $blickTastingId))) { $ausgeblendet++; continue; }
                $zugeordnet[] = ['datei' => $basis, 'text' => '🍾 ' . ($c['name'] ?? 'Champagner'), 'link' => '?ergebnis=' . rawurlencode($m[1])];
            } elseif (str_starts_with($basis, 'div-')) {
                $offen[] = $basis; // Unzugeordnetes immer zeigen – es wartet auf Zuordnung
            }
            // neu-/wneu-Zwischendateien bleiben außen vor
        }
        $vorschlag = $_SESSION['foto_vorschlag'] ?? null;
      ?>
      <?php if ($istAdmin): ?>
        <div class="sortier-leiste">Zeigen:
          <a class="<?= !$alleFotosZeigen ? 'aktiv' : '' ?>" href="?fotos=1">👥 <?= e($albumTasting['titel'] ?? 'Mein Tasting') ?></a>
          <a class="<?= $alleFotosZeigen ? 'aktiv' : '' ?>" href="?fotos=1&amp;alle=1">Alle<?= $ausgeblendet > 0 && !$alleFotosZeigen ? ' (+' . $ausgeblendet . ')' : '' ?></a>
        </div>
      <?php endif; ?>

      <?php if ($offen !== []): ?>
        <div class="card" style="border-left:5px solid var(--accent);">
          <h2>Nicht zugeordnet (<?= count($offen) ?>)</h2>
          <p class="anzahl" style="margin-bottom:0.8rem;">Diese Fotos gehören noch zu keiner Flasche/keinem Weingut. Tipp auf „Zuordnen“ – die App liest Etikett und Standort und schlägt etwas vor.</p>
          <?php if ($eingeloggt): ?>
            <form method="post" style="margin-bottom:1rem;">
              <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
              <input type="hidden" name="aktion" value="doppelte_entfernen">
              <button class="knopf zweit" type="submit">🧹 Doppelte Fotos entfernen</button>
            </form>
          <?php endif; ?>
          <div class="foto-galerie album">
            <?php foreach ($offen as $foto): ?>
              <?php $hatVorschlag = $vorschlag !== null && $vorschlag['datei'] === $foto; ?>
              <div class="album-foto">
                <div class="foto">
                  <a href="bilder/<?= e(rawurlencode($foto)) ?>" target="_blank">
                    <img src="<?= e(thumbUrl($foto)) ?>" alt="" loading="lazy">
                  </a>
                  <?php if ($eingeloggt): ?>
                    <form method="post" onsubmit="return confirm('Dieses Foto wirklich löschen?');">
                      <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                      <input type="hidden" name="aktion" value="div_foto_loeschen">
                      <input type="hidden" name="datei" value="<?= e($foto) ?>">
                      <button type="submit" title="Foto löschen">&#10005;</button>
                    </form>
                  <?php endif; ?>
                </div>
                <?php if ($eingeloggt): ?>
                  <?php if ($hatVorschlag && ($vorschlag['champagner'] !== '' || $vorschlag['weingut'] !== '')): ?>
                    <div class="hinweis ok" style="font-size:0.8rem; padding:0.4rem 0.6rem; margin:4px 0;"><?= e($vorschlag['text']) ?></div>
                    <form method="post">
                      <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                      <input type="hidden" name="aktion" value="foto_uebernehmen">
                      <input type="hidden" name="datei" value="<?= e($foto) ?>">
                      <input type="hidden" name="champagner_id" value="<?= e($vorschlag['champagner']) ?>">
                      <input type="hidden" name="weingut_id" value="<?= e($vorschlag['weingut']) ?>">
                      <button class="knopf klein" type="submit">✓ Übernehmen</button>
                    </form>
                  <?php else: ?>
                    <form method="post">
                      <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                      <input type="hidden" name="aktion" value="foto_analysieren">
                      <input type="hidden" name="datei" value="<?= e($foto) ?>">
                      <button class="knopf klein zweit" type="submit">🔎 Zuordnen</button>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($zugeordnet === [] && $offen === []): ?>
        <div class="card"><p style="color:var(--muted); font-style:italic;">Noch keine Fotos – sie sammeln sich hier automatisch, sobald ihr Flaschen und Weingüter fotografiert.</p></div>
      <?php elseif ($zugeordnet !== []): ?>
        <p class="untertitel">✅ <?= count($zugeordnet) ?> Foto(s) sind zugeordnet – tippe auf die Beschriftung unter dem Bild, um zur Flasche oder zum Weingut zu springen:</p>
        <div class="foto-galerie album" style="margin-bottom:1rem;">
          <?php foreach ($zugeordnet as $af): ?>
            <div class="album-foto">
              <div class="foto">
                <a href="bilder/<?= e(rawurlencode($af['datei'])) ?>" target="_blank">
                  <img src="<?= e(thumbUrl($af['datei'])) ?>" alt="" loading="lazy">
                </a>
              </div>
              <a class="album-text" href="<?= e($af['link']) ?>"><?= e($af['text']) ?></a>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="card">
        <?php if ($eingeloggt): ?>
          <h2>Fotos hochladen</h2>
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="aktion" value="div_foto_upload">
            <?= fotoUploadFelder('div-up') ?>
          </form>
          <p class="abmelden">Mehrere Fotos auf einmal möglich (max. 25&nbsp;MB pro Foto).</p>
        <?php else: ?>
          <?= loginFormular('?fotos=1') ?>
        <?php endif; ?>
      </div>

    <?php elseif ($ansicht === 'weingueter'): ?>
      <!-- ==================== WEINGUT-LISTE ==================== -->
      <div class="knopfreihe" style="margin-bottom:1.2rem;">
        <a class="knopf zweit" href="?liste=1">Champagner</a>
        <a class="knopf" href="?weingueter=1">Weingüter</a>
        <a class="knopf zweit" href="?fotos=1">Fotos</a>
      </div>

      <a class="knopf gross" href="?wneu=1">📷&nbsp; Neues Weingut per Foto</a>
      <?php
        // Standard: nur die Weingüter des aktuellen Tastings; „Alle“ = Archiv (Admin-Funktion)
        $alleWgZeigen = isset($_GET['alle']) && $istAdmin;
        $wgTastingIds = weingutIdsImTasting($daten, $blickTastingId);
        $wgTastingTitel = '';
        foreach ($daten['tastings'] as $tt) {
            if ($tt['id'] === $blickTastingId) {
                $wgTastingTitel = $tt['titel'];
                break;
            }
        }
        $weingutListe = $alleWgZeigen
            ? $daten['weingueter']
            : array_values(array_filter($daten['weingueter'], fn($w) => isset($wgTastingIds[$w['id']])));
        if ($sortierung === 'name') {
            usort($weingutListe, fn(array $x, array $y): int => strcmp(mb_strtolower($x['name']), mb_strtolower($y['name'])));
        } else {
            usort($weingutListe, fn(array $x, array $y): int => ((int)($y['zeit'] ?? 0)) <=> ((int)($x['zeit'] ?? 0)));
        }
      ?>
      <?php if ($istAdmin): ?>
        <div class="sortier-leiste">Zeigen:
          <a class="<?= !$alleWgZeigen ? 'aktiv' : '' ?>" href="?weingueter=1&amp;sort=<?= e($sortierung) ?>">👥 <?= e($wgTastingTitel !== '' ? $wgTastingTitel : 'Mein Tasting') ?></a>
          <a class="<?= $alleWgZeigen ? 'aktiv' : '' ?>" href="?weingueter=1&amp;sort=<?= e($sortierung) ?>&amp;alle=1">Alle (<?= count($daten['weingueter']) ?>)</a>
        </div>
      <?php endif; ?>
      <div class="sortier-leiste">Sortieren:
        <a class="<?= $sortierung === 'datum' ? 'aktiv' : '' ?>" href="?weingueter=1&amp;sort=datum<?= $alleWgZeigen ? '&amp;alle=1' : '' ?>">Anlagedatum</a>
        <a class="<?= $sortierung === 'name' ? 'aktiv' : '' ?>" href="?weingueter=1&amp;sort=name<?= $alleWgZeigen ? '&amp;alle=1' : '' ?>">Name</a>
      </div>
      <input type="search" class="filter-feld" placeholder="🔍 Weingut suchen …" data-ziel=".liste-scroll">
      <div class="liste-scroll">
      <?php if ($weingutListe === []): ?>
        <div class="card"><p style="color:var(--muted); font-style:italic;"><?= $alleWgZeigen ? 'Noch kein Weingut angelegt – unten das erste eintragen!' : 'In diesem Tasting wurde noch an keinem Weingut verkostet – oben auf „Alle" umschalten oder ein neues per Foto anlegen.' ?></p></div>
      <?php endif; ?>
      <?php foreach ($weingutListe as $w): ?>
        <?php
          $fotos = mitTitelbild(weingutFotos($w['id']), (string)($w['titelbild'] ?? ''));
          $anzahlChampagner = count(array_filter($daten['champagner'], fn($c) => ($c['weingut_id'] ?? '') === $w['id']));
          $kurznotiz = trim((string)($w['notiz'] ?? ''));
          if (mb_strlen($kurznotiz) > 120) {
              $kurznotiz = mb_substr($kurznotiz, 0, 120) . ' …';
          }
        ?>
        <div class="card filterbar">
          <div class="champagner-zeile">
            <?php if ($fotos !== []): ?>
              <a href="?weingut=<?= e(rawurlencode($w['id'])) ?>">
                <img class="thumb" src="<?= e(thumbUrl($fotos[0])) ?>" alt="" loading="lazy">
              </a>
            <?php endif; ?>
            <div class="info">
              <div class="name"><?= e($w['name']) ?></div>
              <span class="anzahl"><?= $anzahlChampagner ?> Champagner zugeordnet</span>
              <?php if ($kurznotiz !== ''): ?>
                <p class="anzahl" style="font-style:italic;"><?= e($kurznotiz) ?></p>
              <?php endif; ?>
            </div>
            <div class="knopfreihe">
              <a class="knopf" href="?weingut=<?= e(rawurlencode($w['id'])) ?>">Ansehen</a>
            </div>
          </div>
        </div>
      <?php endforeach; ?>

      <div class="card">
        <?php if ($eingeloggt): ?>
          <h2>Neues Weingut anlegen</h2>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="aktion" value="weingut_anlegen">
            <input type="text" name="name" placeholder="Name, z. B. Champagne Taittinger" maxlength="60" required>
            <textarea name="notiz" placeholder="Notiz, z. B. Besuch am 12.08., nette Führung … (optional)" maxlength="1000" rows="3"></textarea>
            <button class="knopf" type="submit">Anlegen</button>
          </form>
          <form method="post" class="abmelden">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="aktion" value="logout">
            <button type="submit">Abmelden</button>
          </form>
        <?php else: ?>
          <?= loginFormular('?weingueter=1') ?>
        <?php endif; ?>
      </div>
      </div>

    <?php elseif ($ansicht === 'weingut'): ?>
      <!-- ==================== WEINGUT-DETAIL ==================== -->
      <div class="card">
        <h2>Notiz</h2>
        <?php $wNotiz = trim((string)($aktivesWeingut['notiz'] ?? '')); ?>
        <?php if (!$eingeloggt): ?>
          <?php if ($wNotiz !== ''): ?>
            <p><?= nl2br(e($wNotiz)) ?></p>
          <?php else: ?>
            <p style="color:var(--muted); font-style:italic;">Noch keine Notiz.</p>
          <?php endif; ?>
        <?php else: ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="aktion" value="weingut_notiz">
            <input type="hidden" name="id" value="<?= e($aktivesWeingut['id']) ?>">
            <input type="text" name="name" value="<?= e($aktivesWeingut['name']) ?>" maxlength="60" required>
            <textarea name="notiz" maxlength="1000" rows="4" placeholder="Notiz zum Weingut …"><?= e($wNotiz) ?></textarea>
            <textarea name="kontakt" maxlength="900" rows="4" placeholder="Kontaktdaten (Adresse, Telefon, Website …)"><?= e(trim((string)($aktivesWeingut['kontakt'] ?? ''))) ?></textarea>
            <button class="knopf zweit" type="submit">Speichern</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="card">
        <h2>Kontakt</h2>
        <?php $wKontakt = trim((string)($aktivesWeingut['kontakt'] ?? '')); ?>
        <?php if ($wKontakt !== ''): ?>
          <p><?= verlinken($wKontakt) ?></p>
        <?php else: ?>
          <p style="color:var(--muted); font-style:italic;">Noch keine Kontaktdaten hinterlegt.</p>
        <?php endif; ?>
        <?php if ($eingeloggt): ?>
          <form method="post" style="margin-top:0.8rem;">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="aktion" value="weingut_kontakt">
            <input type="hidden" name="id" value="<?= e($aktivesWeingut['id']) ?>">
            <button class="knopf zweit" type="submit">🔎&nbsp; Kontaktdaten automatisch suchen</button>
          </form>
          <p class="anzahl" style="margin-top:0.4rem;">Die Suche dauert bis zu einer halben Minute; das Ergebnis kannst du oben im Bearbeiten-Feld anpassen.</p>
        <?php endif; ?>
      </div>

      <?php $wRecherche = trim((string)($aktivesWeingut['recherche'] ?? '')); ?>
      <?php if ($wRecherche !== '' || $eingeloggt): ?>
        <div class="card zu">
          <h2>Recherche</h2>
          <?php if ($wRecherche !== ''): ?>
            <p><?= verlinken($wRecherche) ?></p>
          <?php else: ?>
            <p style="color:var(--muted); font-style:italic;">Noch keine Recherche zu diesem Weingut.</p>
          <?php endif; ?>
          <?php if ($eingeloggt): ?>
            <form method="post" style="margin-top:0.8rem;">
              <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
              <input type="hidden" name="aktion" value="weingut_recherche">
              <input type="hidden" name="id" value="<?= e($aktivesWeingut['id']) ?>">
              <button class="knopf zweit" type="submit">🔎&nbsp; Im Internet recherchieren</button>
            </form>
            <p class="anzahl" style="margin-top:0.4rem;">Dauert bis zu einer halben Minute; erneutes Recherchieren ersetzt den Text.</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php $fotos = mitTitelbild(weingutFotos($aktivesWeingut['id']), (string)($aktivesWeingut['titelbild'] ?? '')); ?>
      <div class="card">
        <h2>Bilder</h2>
        <?php if ($fotos === []): ?>
          <p style="color:var(--muted); font-style:italic;">Noch keine Bilder zu diesem Weingut.</p>
        <?php else: ?>
          <?php $titelbildAktuell = (string)($aktivesWeingut['titelbild'] ?? ''); ?>
          <div class="foto-galerie">
            <?php foreach ($fotos as $i => $foto): ?>
              <?php $istTitelbild = $titelbildAktuell !== '' ? $foto === $titelbildAktuell : $i === 0; ?>
              <div class="foto">
                <a href="bilder/<?= e(rawurlencode($foto)) ?>" target="_blank">
                  <img src="<?= e(thumbUrl($foto)) ?>" alt="" loading="lazy">
                </a>
                <?php if ($istTitelbild): ?>
                  <span class="titelbild-marke" title="Titelbild – erscheint als Vorschau in den Listen">⭐</span>
                <?php elseif ($eingeloggt): ?>
                  <form method="post" class="titelbild-form">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <input type="hidden" name="aktion" value="titelbild_setzen">
                    <input type="hidden" name="typ" value="weingut">
                    <input type="hidden" name="id" value="<?= e($aktivesWeingut['id']) ?>">
                    <input type="hidden" name="datei" value="<?= e($foto) ?>">
                    <button type="submit" title="Als Titelbild festlegen">☆</button>
                  </form>
                <?php endif; ?>
                <?php if ($eingeloggt): ?>
                  <form method="post" onsubmit="return confirm('Dieses Bild wirklich löschen?');">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <input type="hidden" name="aktion" value="weingut_foto_loeschen">
                    <input type="hidden" name="datei" value="<?= e($foto) ?>">
                    <button type="submit" title="Bild löschen">&#10005;</button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
          <?php if ($eingeloggt && count($fotos) > 1): ?>
            <p class="anzahl" style="margin-top:0.5rem;">⭐ = Titelbild (Vorschau in den Listen). Mit ☆ legst du ein anderes fest.</p>
          <?php endif; ?>
        <?php endif; ?>
        <?php if ($eingeloggt): ?>
          <form method="post" enctype="multipart/form-data" style="margin-top:1rem;">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="aktion" value="weingut_foto_upload">
            <input type="hidden" name="weingut_id" value="<?= e($aktivesWeingut['id']) ?>">
            <?= fotoUploadFelder('wg-up') ?>
          </form>
        <?php endif; ?>
      </div>

      <div class="card">
        <h2>Champagner dieses Weinguts</h2>
        <?php $eigene = array_values(array_filter($daten['champagner'], fn($c) => ($c['weingut_id'] ?? '') === $aktivesWeingut['id'])); ?>
        <?php if ($eigene === []): ?>
          <p style="color:var(--muted); font-style:italic;">Noch kein Champagner zugeordnet. Die Zuordnung machst du auf der Ergebnisseite des jeweiligen Champagners.</p>
        <?php else: ?>
          <?php foreach ($eigene as $c): ?>
            <?php $g = gesamtSchnitt(bewertungenFuer($daten, $c['id'])); ?>
            <div class="ergebnis-kategorie">
              <span><a href="?ergebnis=<?= e(rawurlencode($c['id'])) ?>"><?= e($c['name']) ?></a><?= preisZeile($c) ?></span>
              <?= sterneAnzeige($g) ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <?php if (!$eingeloggt): ?>
        <div class="card">
          <?= loginFormular('?weingut=' . rawurlencode($aktivesWeingut['id'])) ?>
        </div>
      <?php endif; ?>

      <div class="knopfreihe">
        <a class="knopf zweit" href="?weingueter=1">Zur Weingut-Liste</a>
        <a class="knopf zweit" href="?liste=1">Zur Champagner-Liste</a>
      </div>
      <?php if ($eingeloggt): ?>
        <form method="post" onsubmit="return confirm('„<?= e($aktivesWeingut['name']) ?>“ löschen? Die Champagner bleiben erhalten, verlieren aber die Zuordnung.');" style="margin-top:0.8rem;">
          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
          <input type="hidden" name="aktion" value="weingut_loeschen">
          <input type="hidden" name="id" value="<?= e($aktivesWeingut['id']) ?>">
          <button class="loeschen" type="submit">Weingut löschen</button>
        </form>
      <?php endif; ?>

    <?php else: ?>
      <!-- ==================== ÜBERSICHT ==================== -->
      <div class="knopfreihe" style="margin-bottom:1.2rem;">
        <a class="knopf" href="?liste=1">Getränke</a>
        <a class="knopf zweit" href="?weingueter=1">Weingüter</a>
        <a class="knopf zweit" href="?fotos=1">Fotos</a>
      </div>

      <div class="sortier-leiste" style="margin-bottom:0.9rem;">
        <a class="<?= $kategorie === 'alle' ? 'aktiv' : '' ?>" href="?liste=1&amp;kat=alle">⭐ Alle</a>
        <?php foreach ($KATEGORIEN_GETRAENKE as $kSchluessel => [$kIcon, $kName, $kAktiviert]): ?>
          <a class="<?= $kategorie === $kSchluessel ? 'aktiv' : '' ?>" href="?liste=1&amp;kat=<?= e($kSchluessel) ?>"><?= $kIcon ?> <?= e($kName) ?></a>
        <?php endforeach; ?>
      </div>

      <?php if ($kategorie !== 'alle' && !$KATEGORIEN_GETRAENKE[$kategorie][2]): ?>
        <div class="card">
          <h2><?= $KATEGORIEN_GETRAENKE[$kategorie][0] ?> <?= e($KATEGORIEN_GETRAENKE[$kategorie][1]) ?> – bald verfügbar</h2>
          <p>Die Kategorie ist schon vorbereitet – die Verkostung startet hier, sobald ihr sie braucht. Bis dahin: <a href="?liste=1">zurück zum Champagner</a>. 🥂</p>
        </div>
      <?php else: ?>
      <a class="knopf gross" href="?neu=1&amp;kat=<?= e($kategorie === 'alle' ? 'champagner' : $kategorie) ?>">📷&nbsp; Neue Flasche erfassen</a>
      <?php
        // Tasting-Umschalter: eigenes Tasting ist Standard, „Alle“ (Archiv) nur für Admins
        $tidWahl = (string)($_GET['tid'] ?? '');
        $tidGueltig = $tidWahl === 'alle' && $istAdmin;
        foreach ($daten['tastings'] as $t) {
            if ($t['id'] === $tidWahl) { $tidGueltig = true; break; }
        }
        if (!$tidGueltig) {
            $tidWahl = $blickTastingId !== '' ? $blickTastingId : 'alle';
        }
        $tastingsSortiert = $daten['tastings'];
        usort($tastingsSortiert, fn(array $x, array $y): int => ((int)($y['zeit'] ?? 0)) <=> ((int)($x['zeit'] ?? 0)));
      ?>
      <?php if (count($daten['tastings']) > 1): ?>
        <div class="sortier-leiste">Tasting:
          <?php foreach ($tastingsSortiert as $t): ?>
            <a class="<?= $tidWahl === $t['id'] ? 'aktiv' : '' ?>" href="?liste=1&amp;kat=<?= e($kategorie) ?>&amp;sort=<?= e($sortierung) ?>&amp;tid=<?= e(rawurlencode($t['id'])) ?>"><?= e($t['titel']) ?></a>
          <?php endforeach; ?>
          <?php if ($istAdmin): ?>
            <a class="<?= $tidWahl === 'alle' ? 'aktiv' : '' ?>" href="?liste=1&amp;kat=<?= e($kategorie) ?>&amp;sort=<?= e($sortierung) ?>&amp;tid=alle">Alle</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <div class="sortier-leiste">Sortieren:
        <a class="<?= $sortierung === 'datum' ? 'aktiv' : '' ?>" href="?liste=1&amp;kat=<?= e($kategorie) ?>&amp;sort=datum&amp;tid=<?= e(rawurlencode($tidWahl)) ?>">Anlagedatum</a>
        <a class="<?= $sortierung === 'name' ? 'aktiv' : '' ?>" href="?liste=1&amp;kat=<?= e($kategorie) ?>&amp;sort=name&amp;tid=<?= e(rawurlencode($tidWahl)) ?>">Name</a>
      </div>
      <input type="search" class="filter-feld" placeholder="🔍 Champagner oder Weingut suchen …" data-ziel=".liste-scroll">
      <div class="liste-scroll">
      <?php
        $champagnerListe = array_values(array_filter(
            $daten['champagner'],
            fn($c) => ($kategorie === 'alle' || ($c['typ'] ?? 'champagner') === $kategorie)
                && ($tidWahl === 'alle' || champagnerInTasting($c, $tidWahl))
        ));
        if ($sortierung === 'name') {
            usort($champagnerListe, fn(array $x, array $y): int => strcmp(mb_strtolower($x['name']), mb_strtolower($y['name'])));
        } else {
            // Anlagedatum, neueste zuerst
            usort($champagnerListe, fn(array $x, array $y): int => ((int)$y['zeit']) <=> ((int)$x['zeit']));
        }
      ?>
      <?php $katName = $kategorie === 'alle' ? 'Getränk' : $KATEGORIEN_GETRAENKE[$kategorie][1]; ?>
      <?php if ($champagnerListe === []): ?>
        <div class="card"><p style="color:var(--muted); font-style:italic;"><?= $tidWahl === 'alle' ? 'Noch kein ' . e($katName) . ' angelegt – oben auf „Neue Flasche erfassen" tippen!' : 'In diesem Tasting ist noch kein ' . e($katName) . ' erfasst – oben auf „Neue Flasche erfassen" tippen oder oben auf „Alle" umschalten.' ?></p></div>
      <?php endif; ?>
      <?php foreach ($champagnerListe as $c): ?>
        <?php
          $bewertungen = bewertungenFuer($daten, $c['id']);
          $gesamt      = gesamtSchnitt($bewertungen);
          $fotos       = fotosFuer($c['id']);
          $wg          = weingutHolen($daten, (string)($c['weingut_id'] ?? ''));
          $meta        = [];
          if ($wg !== null) { $meta[] = $wg['name']; }
          if (trim((string)($c['rebsorte'] ?? '')) !== '') { $meta[] = (string)$c['rebsorte']; }
          if (trim((string)($c['preis'] ?? '')) !== '') { $meta[] = (string)$c['preis']; }
        ?>
        <div class="card flasche filterbar">
          <a class="flasche-link" href="?ergebnis=<?= e(rawurlencode($c['id'])) ?>">
            <?php if ($fotos !== []): ?>
              <img class="thumb" src="<?= e(thumbUrl($fotos[0])) ?>" alt="" loading="lazy">
            <?php else: ?>
              <span class="thumb platzhalter"><?= $KATEGORIEN_GETRAENKE[(string)($c['typ'] ?? 'champagner')][0] ?? '🍾' ?></span>
            <?php endif; ?>
            <span class="flasche-info">
              <span class="f-name"><?= $kategorie === 'alle' ? ($KATEGORIEN_GETRAENKE[(string)($c['typ'] ?? 'champagner')][0] ?? '') . ' ' : '' ?><?= e($c['name']) ?></span>
              <?php if ($meta !== []): ?>
                <span class="f-meta"><?= e(implode(' · ', $meta)) ?></span>
              <?php endif; ?>
              <span class="f-wertung"><?= sterneAnzeige($gesamt) ?><?= $bewertungen !== [] ? ' <span class="anzahl">(' . count($bewertungen) . ')</span>' : '' ?></span>
            </span>
          </a>
          <a class="knopf klein" href="?bewerten=<?= e(rawurlencode($c['id'])) ?>"><?= meineBewertung($daten, $c['id']) !== null ? '🔁 Nochmal' : 'Bewerten' ?></a>
        </div>
      <?php endforeach; ?>

      <?php if ($eingeloggt): ?>
        <form method="post" class="abmelden">
          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
          <input type="hidden" name="aktion" value="logout">
          <button type="submit">Abmelden</button>
        </form>
      <?php else: ?>
        <div class="card">
          <?= loginFormular() ?>
        </div>
      <?php endif; ?>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </main>

  <footer>
    <p>&copy; 2026 Carl &middot; <a href="mailto:tasting@fruthzeug.de">✉️ tasting@fruthzeug.de</a> &middot; <a href="/">Zur&uuml;ck zur Startseite</a></p>
  </footer>

  <nav class="tab-leiste">
    <a href="./" class="<?= $bereich === 'verkosten' ? 'aktiv' : '' ?>"><span class="tab-icon">🥂</span>Verkosten</a>
    <a href="?liste=1" class="<?= $bereich === 'entdecken' ? 'aktiv' : '' ?>"><span class="tab-icon">🔍</span>Entdecken</a>
    <a href="?tasting=1" class="<?= $bereich === 'tasting' ? 'aktiv' : '' ?>"><span class="tab-icon">👥</span>Tasting</a>
  </nav>

  <div id="anleitung-box" hidden>
    <div class="blatt">
      <div class="blatt-kopf">
        <b>ℹ️ So wird verkostet</b>
        <button type="button" aria-label="Schließen">&#10005;</button>
      </div>
      <div class="anleitung-chips">
        <button type="button" data-art="champagner">🍾 Champagner</button>
        <button type="button" data-art="rotwein">🍷 Rotwein</button>
        <button type="button" data-art="weisswein">🥂 Weißwein</button>
        <button type="button" data-art="bier">🍺 Bier</button>
      </div>

      <div class="anleitung anleitung-teil" data-art="champagner">
        <p>Nimm dir kurz Zeit und geh in Ruhe durch die vier Sinne – die App führt dich Schritt für Schritt.</p>
        <h3>👁 Das Auge</h3>
        <p>Halte das Glas gegen einen hellen Hintergrund. <b>Farbe:</b> von zartem Zitronengelb (jung) über Gold (gereift) bis Kupfer; Rosé von Lachs bis Himbeer. <b>Perlage</b> sind die Bläschen – je feiner und beständiger, desto edler das Mundgefühl. (Die reine Bläschen-<i>Menge</i> sagt übrigens wenig über die Qualität.)</p>
        <h3>👃 Die Nase</h3>
        <p>Zuerst <b>ohne Schwenken</b> schnuppern, dann leicht schwenken – so öffnen sich die Aromen. Zwischendurch die Nase kurz ausruhen. <b>Sauber?</b> Riecht es nach feuchtem Karton/Keller, hat der Wein einen Korkfehler. <b>Aromen:</b> Wähl alles, was du erkennst – Frucht (Apfel, Zitrus, Beeren), Hefe &amp; Gebäck (Brioche, Toast, Nuss), Reife (Honig, Butter). Faustregel: 5–7 Aromen = komplex, 8+ = sehr komplex.</p>
        <h3>👅 Der Mund</h3>
        <p>Ein mittelgroßer Schluck, mit der Zunge im ganzen Mund verteilen. <b>Säure</b> erkennst du am Speichelfluss – sie macht den Champagner frisch. <b>Mousse</b> ist das Prickeln: cremig-fein ist feiner als aggressiv. <b>Balance:</b> Passen Säure, Frucht, Kraft und Süße harmonisch zusammen?</p>
        <h3>⏱ Der Abgang</h3>
        <p>Nach dem Schlucken innerlich zählen, wie lange der Geschmack angenehm nachklingt: unter 5 Sek. = kurz, 5–15 = mittel, über 15 = lang. <b>Charakter:</b> Hat der Wein etwas Eigenes, Unverwechselbares? Und ganz ehrlich: <b>Würdest du ein zweites Glas nehmen?</b></p>
        <p style="color:var(--muted);">Am Ende rechnet die App aus deinen Antworten eine Sterne-Wertung – die kannst du mit einem Tipp noch anpassen. Es gibt kein „falsch": Dein Eindruck zählt. 🍾</p>
      </div>

      <div class="anleitung anleitung-teil" data-art="rotwein" hidden>
        <h3>👁 Das Auge</h3>
        <p>Glas leicht kippen und gegen Weiß halten. <b>Farbe vom Kern zum Rand:</b> Purpur/Violett = jung, Rubin = auf dem Punkt, Ziegel bis Braun = gereift. Je durchsichtiger der Rand, desto reifer der Wein.</p>
        <h3>👃 Die Nase</h3>
        <p>Kräftig schwenken – Rotwein braucht Luft. <b>Frucht:</b> Kirsche, Beeren, Pflaume. <b>Würze:</b> Pfeffer, Lakritz, Tabak. <b>Fassnoten:</b> Vanille, Röstaromen, Schokolade. Muffig/feuchter Keller = Korkfehler.</p>
        <h3>👅 Der Mund</h3>
        <p>Schluck im Mund bewegen. <b>Tannin</b> ist das pelzige Gefühl am Zahnfleisch – bei gutem Wein fein, nicht kratzig. <b>Körper:</b> leicht wie Wasser oder dicht wie Sahne? <b>Balance:</b> Frucht, Säure, Tannin und Alkohol im Einklang?</p>
        <h3>⏱ Der Abgang</h3>
        <p>Wie lange trägt der Geschmack? Wärmt der Alkohol angenehm oder brennt er? Und: <b>Lust auf ein zweites Glas?</b></p>
        <p style="color:var(--muted);">Die geführte Bewertung für Rotwein folgt bald – verkosten und Notizen machen geht schon jetzt. 🍷</p>
      </div>

      <div class="anleitung anleitung-teil" data-art="weisswein" hidden>
        <h3>👁 Das Auge</h3>
        <p>Farbe gegen hellen Hintergrund: blasses Grüngelb = jung und frisch, Strohgelb = klassisch, Goldgelb = gereift oder im Holzfass ausgebaut.</p>
        <h3>👃 Die Nase</h3>
        <p>Erst ruhig riechen, dann schwenken. <b>Frucht:</b> Zitrus, grüner Apfel, Pfirsich, exotische Früchte. <b>Dazu:</b> Blüten, Kräuter, Mineralik (nasser Stein). Muffige Töne = Fehler.</p>
        <h3>👅 Der Mund</h3>
        <p><b>Säure</b> macht den Weißwein lebendig – sie zeigt sich am Speichelfluss. <b>Süße:</b> knochentrocken bis fruchtsüß, wichtig ist die Balance mit der Säure. <b>Körper:</b> leicht und schlank oder cremig und kraftvoll?</p>
        <h3>⏱ Der Abgang</h3>
        <p>Klingt Frucht oder Mineralik nach? Je länger und angenehmer, desto besser. Und wie immer: <b>Würdest du nachschenken?</b></p>
        <p style="color:var(--muted);">Die geführte Bewertung für Weißwein folgt bald – verkosten und Notizen machen geht schon jetzt. 🥂</p>
      </div>

      <div class="anleitung anleitung-teil" data-art="bier" hidden>
        <h3>👁 Das Auge</h3>
        <p><b>Farbe:</b> strohgelb (Pils) über bernstein (Ale) bis tiefschwarz (Stout). <b>Schaum:</b> feinporig, stabil, hinterlässt er Ringe am Glas? Trübung ist bei Weizen und Kellerbier gewollt.</p>
        <h3>👃 Die Nase</h3>
        <p><b>Hopfen:</b> blumig, grasig, Zitrus, tropisch. <b>Malz:</b> brotig, Karamell, Kaffee, Schokolade. <b>Hefe:</b> Banane und Nelke beim Weizen. Pappe-Geruch = altes Bier.</p>
        <h3>👅 Der Mund</h3>
        <p><b>Antrunk:</b> spritzig oder weich? <b>Kohlensäure:</b> feinperlig oder stechend? <b>Balance:</b> Malzsüße gegen Hopfenbitterkeit – beides darf da sein, keins soll erschlagen.</p>
        <h3>⏱ Der Abgang</h3>
        <p>Bleibt die Bitterkeit angenehm oder wird sie kratzig? Trocknet der Mund oder will er den nächsten Schluck? <b>Noch eins bestellen?</b></p>
        <p style="color:var(--muted);">Die geführte Bewertung für Bier folgt bald – verkosten und Notizen machen geht schon jetzt. 🍺</p>
      </div>
    </div>
  </div>

  <div id="overlay" hidden>
    <div class="blatt">
      <div class="blatt-kopf">
        <b>Details</b>
        <button type="button" aria-label="Schließen">&#10005;</button>
      </div>
      <div class="blatt-inhalt"><p>Lade …</p></div>
    </div>
  </div>

  <div id="grossansicht" hidden>
    <button class="schliessen" type="button" aria-label="Schließen">&#10005;</button>
    <img src="" alt="">
    <button class="bild-info-knopf" type="button" aria-label="Bildinfo">ℹ️ Bildinfo</button>
    <div class="bild-info-blatt" hidden></div>
  </div>

  <script>
    // Auf- und Zuklappen der Karten – bewusst ein eigener, winziger Block:
    // selbst wenn im großen Skript darunter irgendetwas schiefgeht, bleibt
    // das Klappen auf jedem Gerät funktionsfähig.
    (function () {
      document.querySelectorAll('.card > h2').forEach(function (h) {
        h.addEventListener('click', function () { h.parentNode.classList.toggle('zu'); });
      });
      // Klappzustände über automatische Neuladungen hinweg merken
      var schluessel = 'klapp:' + location.pathname + location.search;
      window.klappZustandSichern = function () {
        var z = { details: [], karten: [], tage: [] };
        document.querySelectorAll('details.card').forEach(function (d, i) { if (d.open) { z.details.push(i); } });
        document.querySelectorAll('.card > h2').forEach(function (h, i) { if (!h.parentNode.classList.contains('zu')) { z.karten.push(i); } });
        document.querySelectorAll('details.trip-tag').forEach(function (d, i) { if (d.open) { z.tage.push(i); } });
        try { sessionStorage.setItem(schluessel, JSON.stringify(z)); } catch (e) {}
      };
      try {
        var z = JSON.parse(sessionStorage.getItem(schluessel) || 'null');
        if (z) {
          sessionStorage.removeItem(schluessel);
          document.querySelectorAll('details.card').forEach(function (d, i) { d.open = z.details.indexOf(i) !== -1; });
          document.querySelectorAll('.card > h2').forEach(function (h, i) { h.parentNode.classList.toggle('zu', z.karten.indexOf(i) === -1); });
          document.querySelectorAll('details.trip-tag').forEach(function (d, i) { d.open = (z.tage || []).indexOf(i) !== -1; });
        }
      } catch (e) {}
    })();
  </script>

  <script>
    // Weingut-Details als Overlay laden (holt die Detailseite und zeigt deren Inhalt)
    (function () {
      var ausloeser = document.getElementById('details-oeffnen');
      if (!ausloeser) { return; }
      var overlay = document.getElementById('overlay');
      var inhalt = overlay.querySelector('.blatt-inhalt');
      ausloeser.addEventListener('click', function (e) {
        e.preventDefault();
        overlay.hidden = false;
        document.body.style.overflow = 'hidden';
        inhalt.innerHTML = '<p>Lade …</p>';
        fetch(ausloeser.dataset.url).then(function (r) { return r.text(); }).then(function (html) {
          var doc = new DOMParser().parseFromString(html, 'text/html');
          var main = doc.querySelector('main');
          if (main) { inhalt.replaceChildren.apply(inhalt, Array.prototype.slice.call(main.childNodes)); }
        }).catch(function () { inhalt.innerHTML = '<p>Konnte nicht geladen werden.</p>'; });
      });
      function zu() { overlay.hidden = true; document.body.style.overflow = ''; }
      overlay.querySelector('.blatt-kopf button').addEventListener('click', zu);
      overlay.addEventListener('click', function (e) { if (e.target === overlay) { zu(); } });
    })();

    // Geführte Bewertung (Wizard)
    (function () {
      var form = document.getElementById('wizard-form');
      if (!form) { return; }
      var vor = JSON.parse(document.getElementById('wz-vorbelegt').textContent);
      var KAT = vor.kategorien;
      var antwort = { aromen: [] };
      // Vorbelegung aus bestehender Bewertung
      if (vor.detail && typeof vor.detail === 'object') {
        Object.keys(vor.detail).forEach(function (k) { antwort[k] = vor.detail[k]; });
        if (!Array.isArray(antwort.aromen)) { antwort.aromen = []; }
      }
      var schritte = form.querySelectorAll('.wz-schritt');
      var aktiv = 1, maxSchritt = schritte.length;
      var justiert = {}; // von Hand justierte Sterne

      // Kachel-Auswahl
      form.querySelectorAll('.wz-kacheln').forEach(function (gruppe) {
        var feld = gruppe.dataset.feld;
        var mehrfach = gruppe.classList.contains('mehrfach');
        gruppe.querySelectorAll('button').forEach(function (b) {
          // Vorbelegung anzeigen
          if (mehrfach ? (antwort[feld] || []).indexOf(b.dataset.wert) !== -1 : antwort[feld] === b.dataset.wert) {
            b.classList.add('gewaehlt');
          }
          b.addEventListener('click', function () {
            if (mehrfach) {
              var arr = antwort[feld] || [];
              var i = arr.indexOf(b.dataset.wert);
              if (i === -1) { arr.push(b.dataset.wert); b.classList.add('gewaehlt'); }
              else { arr.splice(i, 1); b.classList.remove('gewaehlt'); }
              antwort[feld] = arr;
            } else {
              gruppe.querySelectorAll('button').forEach(function (x) { x.classList.remove('gewaehlt'); });
              b.classList.add('gewaehlt');
              antwort[feld] = b.dataset.wert;
              if (feld === 'sauber') { aromenSichtbar(); }
            }
          });
        });
      });
      function aromenSichtbar() {
        var block = document.getElementById('aromen-block');
        if (block) { block.style.display = antwort.sauber === 'kork' ? 'none' : ''; }
      }
      aromenSichtbar();

      // Sterne-Ableitung (deckungsgleich mit PHP sterneAusDetail)
      function sterneAusDetailJS(d) {
        var sm = { top: 5, gut: 4, ok: 3, geht: 2 };
        var n = (d.aromen || []).length;
        if (d.sauber === 'kork') {
          var r = {}; KAT.forEach(function (k) { r[k] = 1; }); return r;
        }
        var komplex = n >= 8 ? 5 : n >= 6 ? 4 : n >= 3 ? 3 : n >= 1 ? 2 : 3;
        return {
          duft: sm[d.duft] || 3,
          perlage: ({ fein: 5, mittel: 4, grob: 2 })[d.perlage] || 3,
          geschmack: sm[d.geschmack] || 3,
          balance: ({ perfekt: 5, stimmig: 4, unrund: 3, schlecht: 2 })[d.balance] || 3,
          komplexitaet: komplex,
          abgang: ({ lang: 5, mittel: 4, kurz: 2 })[d.abgang] || 3,
          besonderheit: ({ unverwechselbar: 5, hatwas: 4, austauschbar: 2 })[d.charakter] || 3,
          trinkfreude: ({ sofort: 5, gerne: 4, muss_nicht: 3, nein: 1 })[d.nochmal] || 3
        };
      }
      var TITEL = vor.titel || { duft: 'Duft', perlage: 'Perlage', geschmack: 'Geschmack', balance: 'Balance', komplexitaet: 'Komplexität', abgang: 'Abgang', besonderheit: 'Besonderheit', trinkfreude: 'Trinkfreude' };
      function aktuelleSterne() {
        var abg = sterneAusDetailJS(antwort);
        KAT.forEach(function (k) { if (justiert[k]) { abg[k] = justiert[k]; } });
        return abg;
      }
      function vorschauZeichnen() {
        var s = aktuelleSterne();
        var html = '';
        KAT.forEach(function (k) {
          html += '<div class="wz-stern-zeile"><span>' + TITEL[k] + '</span><span class="wz-sterne" data-kat="' + k + '">';
          for (var i = 1; i <= 5; i++) { html += '<span class="' + (i <= s[k] ? 'an' : 'aus') + '" data-n="' + i + '">★</span>'; }
          html += '</span></div>';
        });
        var box = document.getElementById('wz-sterne-vorschau');
        box.innerHTML = html;
        box.querySelectorAll('.wz-sterne span').forEach(function (st) {
          st.addEventListener('click', function () {
            var zeile = st.parentNode;
            justiert[zeile.dataset.kat] = parseInt(st.dataset.n, 10);
            vorschauZeichnen();
          });
        });
      }

      function zeige(n) {
        schritte.forEach(function (s) { s.hidden = parseInt(s.dataset.schritt, 10) !== n; });
        document.getElementById('wz-balken').style.width = Math.round(n / maxSchritt * 100) + '%';
        document.getElementById('wz-zurueck').hidden = n === 1;
        document.getElementById('wz-weiter').hidden = n === maxSchritt;
        document.getElementById('wz-fertig').hidden = n !== maxSchritt;
        document.getElementById('wz-ueberspringen').parentNode.style.display = n === maxSchritt ? 'none' : '';
        if (n === maxSchritt) { vorschauZeichnen(); }
        window.scrollTo(0, 0);
      }
      document.getElementById('wz-weiter').addEventListener('click', function () { if (aktiv < maxSchritt) { aktiv++; zeige(aktiv); } });
      document.getElementById('wz-zurueck').addEventListener('click', function () { if (aktiv > 1) { aktiv--; zeige(aktiv); } });
      document.getElementById('wz-ueberspringen').addEventListener('click', function (e) { e.preventDefault(); if (aktiv < maxSchritt) { aktiv++; zeige(aktiv); } });

      form.addEventListener('submit', function (e) {
        var name = document.getElementById('wz-person').value.trim();
        if (!name) { e.preventDefault(); alert('Bitte trag noch deinen Namen ein.'); return; }
        var verstecktPerson = document.createElement('input');
        verstecktPerson.type = 'hidden'; verstecktPerson.name = 'person'; verstecktPerson.value = name;
        form.appendChild(verstecktPerson);
        var vn = document.createElement('input'); vn.type = 'hidden'; vn.name = 'notiz'; vn.value = document.getElementById('wz-notiz').value; form.appendChild(vn);
        var vf = document.createElement('input'); vf.type = 'hidden'; vf.name = 'flaschen'; vf.value = document.getElementById('wz-flaschen').value; form.appendChild(vf);
        document.getElementById('detail-feld').value = JSON.stringify(antwort);
        var s = aktuelleSterne();
        KAT.forEach(function (k) { document.getElementById('stern-' + k).value = s[k]; });
      });

      // Standort des Geräts leise erfassen – Ort und Wetter kommen in die
      // Bewertung (das Gerät fragt einmal nach der Erlaubnis)
      if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(function (pos) {
          var la = document.getElementById('geo-lat');
          var lo = document.getElementById('geo-lon');
          if (la && lo) {
            la.value = pos.coords.latitude.toFixed(5);
            lo.value = pos.coords.longitude.toFixed(5);
          }
        }, function () { /* abgelehnt oder nicht verfügbar – kein Problem */ }, { timeout: 8000, maximumAge: 600000 });
      }

      zeige(1);
    })();

    // Anleitung „So wird verkostet“: überall über den ℹ️-Knopf erreichbar,
    // mit eigener Erklärung je Getränkeart
    (function () {
      var box = document.getElementById('anleitung-box');
      if (!box) { return; }
      function waehle(art) {
        box.querySelectorAll('.anleitung-teil').forEach(function (t) { t.hidden = t.dataset.art !== art; });
        box.querySelectorAll('.anleitung-chips button').forEach(function (b) { b.classList.toggle('gewaehlt', b.dataset.art === art); });
      }
      function oeffne() { box.hidden = false; document.body.style.overflow = 'hidden'; }
      function zu() { box.hidden = true; document.body.style.overflow = ''; }
      <?php
        // Passende Anleitung vorwählen: beim Bewerten/Ergebnis die des Getränks, sonst die Liste-Kategorie
        $anleitungArt = in_array($ansicht, ['bewerten', 'ergebnis'], true) && $aktiverChampagner !== null
            ? (string)($aktiverChampagner['typ'] ?? 'champagner')
            : $kategorie;
        if (!isset($KATEGORIEN_GETRAENKE[$anleitungArt])) {
            $anleitungArt = 'champagner';
        }
      ?>
      waehle(<?= json_encode($anleitungArt) ?>);
      box.querySelectorAll('.anleitung-chips button').forEach(function (b) {
        b.addEventListener('click', function () { waehle(b.dataset.art); });
      });
      ['info-knopf', 'wz-info-knopf'].forEach(function (id) {
        var k = document.getElementById(id);
        if (k) { k.addEventListener('click', oeffne); }
      });
      document.querySelectorAll('.anleitung-oeffnen').forEach(function (k) {
        k.addEventListener('click', function (e) { e.preventDefault(); oeffne(); });
      });
      box.querySelector('.blatt-kopf button').addEventListener('click', zu);
      box.addEventListener('click', function (e) { if (e.target === box) { zu(); } });
    })();

    // Suchfilter über Listen: tippen filtert die Einträge sofort; Feld bleibt oben stehen
    (function () {
      var header = document.querySelector('.site-header');
      var top = header ? header.getBoundingClientRect().height : 0;
      document.documentElement.style.setProperty('--filter-top', top + 'px');
    })();
    document.querySelectorAll('.filter-feld').forEach(function (feld) {
      var ziel = document.querySelector(feld.dataset.ziel);
      if (!ziel) { return; }
      feld.addEventListener('input', function () {
        var q = feld.value.toLowerCase().trim();
        ziel.querySelectorAll('.filterbar').forEach(function (el) {
          el.style.display = el.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
        });
      });
    });

    // Zurück-Links: wirklich dahin zurück, wo man herkam
    document.querySelectorAll('.zurueck a').forEach(function (a) {
      a.addEventListener('click', function (e) {
        if (history.length > 1 && document.referrer.indexOf(location.host) !== -1) {
          e.preventDefault();
          history.back();
        }
      });
    });

    // Profil: Tipp auf Name/Avatar öffnet Foto & „So verkostest du“
    (function () {
      var box = document.getElementById('profil-box');
      var knopf = document.getElementById('profil-knopf');
      if (!box || !knopf) { return; }
      function zu() { box.hidden = true; document.body.style.overflow = ''; }
      knopf.addEventListener('click', function () { box.hidden = false; document.body.style.overflow = 'hidden'; });
      box.querySelector('.blatt-kopf button').addEventListener('click', zu);
      box.addEventListener('click', function (e) { if (e.target === box) { zu(); } });
    })();

    // Tasting-Bild: Tipp auf Titel oder Avatar öffnet das Foto-Overlay
    (function () {
      var box = document.getElementById('tasting-bild-box');
      if (!box) { return; }
      function zu() { box.hidden = true; document.body.style.overflow = ''; }
      ['tasting-bild-knopf', 'tasting-titel-tipp'].forEach(function (id) {
        var k = document.getElementById(id);
        if (k) { k.addEventListener('click', function () { box.hidden = false; document.body.style.overflow = 'hidden'; }); }
      });
      box.querySelector('.blatt-kopf button').addEventListener('click', zu);
      box.addEventListener('click', function (e) { if (e.target === box) { zu(); } });
    })();

    // Rebsorten/Stile antippen statt tippen: Chips füllen das Textfeld
    document.querySelectorAll('.sorten-chips').forEach(function (box) {
      var ziel = document.getElementById(box.dataset.ziel);
      if (!ziel) { return; }
      var form = box.closest('form');
      var katWahl = form ? form.querySelector('select[name="kat"]') : null;
      function markiere() {
        var teile = ziel.value.split(',').map(function (t) { return t.trim().toLowerCase(); });
        box.querySelectorAll('button.sorte').forEach(function (b) {
          b.classList.toggle('gewaehlt', teile.indexOf(b.textContent.trim().toLowerCase()) !== -1);
        });
      }
      var infoZeile = box.querySelector('.sorten-info');
      box.querySelectorAll('button.sorte').forEach(function (b) {
        b.addEventListener('click', function () {
          var wert = b.textContent.trim();
          var teile = ziel.value.split(',').map(function (t) { return t.trim(); }).filter(Boolean);
          var i = teile.map(function (t) { return t.toLowerCase(); }).indexOf(wert.toLowerCase());
          if (i === -1) { teile.push(wert); } else { teile.splice(i, 1); }
          ziel.value = teile.join(', ');
          markiere();
          if (infoZeile && b.dataset.info) {
            infoZeile.innerHTML = '💡 <b>' + wert + ':</b> ' + b.dataset.info;
            infoZeile.hidden = false;
          }
        });
      });
      if (katWahl) {
        katWahl.addEventListener('change', function () {
          box.querySelectorAll('.sorten-set').forEach(function (s) { s.hidden = s.dataset.kat !== katWahl.value; });
          ziel.placeholder = katWahl.value === 'bier' ? 'Sorte/Stil, z. B. Pils, IPA (optional)' : 'Rebsorte, z. B. Chardonnay (optional)';
        });
      }
      markiere();
    });

    // Direkte Foto-Upload-Knöpfe: nach der Auswahl sofort hochladen
    document.querySelectorAll('.upload-direkt').forEach(function (input) {
      input.addEventListener('change', function () {
        if (!input.files || input.files.length === 0) { return; }
        var form = input.closest('form');
        if (!form) { return; }
        var labels = form.querySelectorAll('label.knopf');
        labels.forEach(function (l, i) {
          if (i === 0) { l.textContent = 'Wird hochgeladen …'; l.classList.add('laedt'); }
          else { l.style.display = 'none'; }
        });
        form.submit();
      });
    });

    // Einladungslinks in die Zwischenablage kopieren
    document.querySelectorAll('.link-kopieren').forEach(function (k) {
      k.addEventListener('click', function () {
        var text = k.dataset.link;
        var versuch = navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject();
        versuch.then(function () {
          k.textContent = 'Kopiert ✓';
          setTimeout(function () { k.textContent = 'Link kopieren'; }, 2500);
        }).catch(function () {
          window.prompt('Link zum Kopieren:', text);
        });
      });
    });

    // "Neu laden": Seite garantiert frisch am Zwischenspeicher vorbei holen
    (function () {
      var nl = document.getElementById('neu-laden');
      if (!nl) { return; }
      nl.addEventListener('click', function (e) {
        e.preventDefault();
        var u = new URL(location.href);
        u.searchParams.set('_r', Date.now());
        location.replace(u.toString());
      });
    })();

    // Fotos in der Großansicht öffnen statt die Seite zu verlassen –
    // mit ℹ️-Knopf für die Bildinfo (Ort, Aufnahmedatum, Kamera, Zuordnung …)
    (function () {
      var box = document.getElementById('grossansicht');
      if (!box) { return; }
      var bild = box.querySelector('img');
      var infoKnopf = box.querySelector('.bild-info-knopf');
      var infoBlatt = box.querySelector('.bild-info-blatt');
      var dateiname = '';
      document.querySelectorAll('.foto > a, .bild > a').forEach(function (link) {
        link.addEventListener('click', function (e) {
          e.preventDefault();
          var href = link.getAttribute('href');
          bild.src = href;
          try { dateiname = decodeURIComponent(href.split('/').pop().split('?')[0]); } catch (err) { dateiname = ''; }
          infoBlatt.hidden = true;
          infoBlatt.innerHTML = '';
          box.hidden = false;
          document.body.style.overflow = 'hidden';
        });
      });
      infoKnopf.addEventListener('click', function (e) {
        e.stopPropagation();
        if (!infoBlatt.hidden) { infoBlatt.hidden = true; return; }
        infoBlatt.hidden = false;
        infoBlatt.innerHTML = '<p>Lade Bildinfo …</p>';
        fetch('?bildinfo=' + encodeURIComponent(dateiname), { cache: 'no-store' })
          .then(function (r) { return r.json(); })
          .then(function (info) {
            if (info.fehler) { infoBlatt.innerHTML = '<p>' + info.fehler + '</p>'; return; }
            var html = '';
            info.zeilen.forEach(function (z) {
              var b = document.createElement('b'); b.textContent = z[0];
              var s = document.createElement('span'); s.textContent = z[1];
              html += '<div class="bi-zeile">' + b.outerHTML + ' ' + s.outerHTML + '</div>';
            });
            if (info.karte) {
              html += '<div class="bi-zeile"><a href="' + info.karte.replace(/"/g, '&quot;') + '" target="_blank" rel="noopener">🗺️ Aufnahmeort in Google Maps öffnen</a></div>';
            }
            infoBlatt.innerHTML = html;
          })
          .catch(function () { infoBlatt.innerHTML = '<p>Bildinfo konnte nicht geladen werden.</p>'; });
      });
      infoBlatt.addEventListener('click', function (e) { e.stopPropagation(); });
      function schliessen() {
        box.hidden = true;
        bild.src = '';
        infoBlatt.hidden = true;
        infoBlatt.innerHTML = '';
        document.body.style.overflow = '';
      }
      box.addEventListener('click', schliessen);
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { schliessen(); }
      });
    })();

    <?php if (in_array($ansicht, ['verkosten', 'werkstatt', 'tastingplatz', 'ergebnis', 'liste'], true)): ?>
    // Live-Aktualisierung: alle 20 Sekunden im Hintergrund nachsehen, ob es
    // Neuigkeiten gibt (Beitritte, Bewertungen, „im Glas“) – dann neu laden.
    (function () {
      var letzterStand = document.querySelector('main') ? document.querySelector('main').innerHTML : '';
      setInterval(function () {
        if (document.hidden) { return; } // Tab nicht sichtbar → nichts tun
        var aktiv = document.activeElement;
        if (aktiv && (aktiv.tagName === 'INPUT' || aktiv.tagName === 'TEXTAREA' || aktiv.tagName === 'SELECT')) { return; }
        var tippt = false;
        document.querySelectorAll('.filter-feld').forEach(function (f) { if (f.value.trim() !== '') { tippt = true; } });
        document.querySelectorAll('input[type="file"]').forEach(function (f) { if (f.files && f.files.length > 0) { tippt = true; } });
        if (tippt) { return; } // Suchfilter oder gewähltes Foto → nicht wegreloaden
        var offenes = ['overlay', 'grossansicht', 'anleitung-box', 'tasting-bild-box', 'profil-box'].some(function (id) {
          var el = document.getElementById(id);
          return el && !el.hidden;
        });
        if (offenes) { return; }
        var u = new URL(location.href);
        u.searchParams.set('_live', Date.now());
        fetch(u.toString(), { cache: 'no-store' }).then(function (r) { return r.text(); }).then(function (html) {
          var doc = new DOMParser().parseFromString(html, 'text/html');
          var main = doc.querySelector('main');
          if (main && main.innerHTML !== letzterStand) { if (window.klappZustandSichern) { window.klappZustandSichern(); } location.reload(); }
        }).catch(function () { /* offline o. ä. – nächster Versuch in 20 s */ });
      }, 20000);
    })();
    <?php endif; ?>

    // Beim Absenden sichtbar machen, dass gearbeitet wird (v. a. Foto-Upload)
    document.querySelectorAll('form').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        var knopf = form.querySelector('button[type="submit"]');
        if (!knopf || knopf.disabled) { return; }
        setTimeout(function () {
          if (e.defaultPrevented) { return; } // z. B. Sicherheitsabfrage abgebrochen
          knopf.disabled = true;
          knopf.classList.add('laedt');
          if (knopf.classList.contains('knopf')) {
            knopf.textContent = form.querySelector('input[type="file"]')
              ? 'Wird hochgeladen …'
              : 'Bitte warten …';
          }
        }, 0);
      });
    });
  </script>
</body>
</html>
