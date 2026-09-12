<?php
// Nimmt die Interessens- und Newsletter-Formulare der Rapid.Tech-Seite entgegen
// und verschickt sie per PHP-mail() (wie das TasteLog-Projekt). Antwortet als JSON.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// --- Empfänger & Absender -----------------------------------------------------
const EMPFAENGER = 'carl.fruth@fit.technology';
// Absender MUSS eine Adresse der eigenen Domain sein, sonst lehnt der Server ab.
const ABSENDER   = 'Rapid.Tech 3D <rapidtech@fruthzeug.de>';

// --- Nur POST zulassen --------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'fehler' => 'Nur POST erlaubt.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Kleine Helfer ------------------------------------------------------------
/** Entfernt Zeilenumbrüche (Schutz vor Header-Injection) und trimmt. */
function sauber(string $s): string {
    return trim(str_replace(["\r", "\n"], ' ', $s));
}
function feld(string $name): string {
    return sauber((string)($_POST[$name] ?? ''));
}
function istEmail(string $s): bool {
    return $s !== '' && filter_var($s, FILTER_VALIDATE_EMAIL) !== false;
}

// --- Spam-Schutz: verstecktes Honeypot-Feld muss leer bleiben -----------------
if (feld('website') !== '') {
    // Bot erkannt – tun so, als wäre alles gut, aber nichts senden.
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$art    = feld('art'); // 'interesse' | 'newsletter'
$email  = mb_strtolower(feld('email'));
$name   = feld('name');
$org    = feld('org');
$rollen = feld('rollen');

$kopf =
    "From: " . ABSENDER . "\r\n" .
    ($email !== '' && istEmail($email) ? "Reply-To: " . $email . "\r\n" : '') .
    "Content-Type: text/plain; charset=UTF-8\r\n" .
    "MIME-Version: 1.0";

if ($art === 'newsletter') {
    if (!istEmail($email)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'fehler' => 'Bitte eine gültige E-Mail-Adresse angeben.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $betreff = mb_encode_mimeheader('Rapid.Tech 3D — Newsletter-Anmeldung', 'UTF-8');
    $text    = "Neue Newsletter-Anmeldung auf der Rapid.Tech-3D-Seite:\n\n"
             . "E-Mail: " . $email . "\n"
             . "Zeit:   " . date('d.m.Y H:i') . "\n";
    @mail(EMPFAENGER, $betreff, $text, $kopf);

    // Auto-Bestätigung an den Anmelder
    $bBetreff = mb_encode_mimeheader('Ihre Newsletter-Anmeldung bei Rapid.Tech 3D', 'UTF-8');
    $bText    = "Hallo,\n\n"
              . "vielen Dank für Ihr Interesse an der Rapid.Tech 3D!\n"
              . "Wir haben Ihre Newsletter-Anmeldung notiert und melden uns, sobald es Neuigkeiten gibt "
              . "(Vereinsgründung, Termin, Call for Speakers).\n\n"
              . "Herzliche Grüße\nRapid.Tech 3D — Verein in Gründung\nhttps://fruthzeug.de/rapidtech/\n";
    @mail($email, $bBetreff, $bText, "From: " . ABSENDER . "\r\nContent-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0");

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Interessensbekundung -----------------------------------------------------
$betreff = mb_encode_mimeheader('Rapid.Tech 3D — Interessensbekundung' . ($name !== '' ? ' von ' . $name : ''), 'UTF-8');
$text    = "Neue Interessensbekundung auf der Rapid.Tech-3D-Seite:\n\n"
         . "Name:         " . ($name ?: '—') . "\n"
         . "Organisation: " . ($org ?: '—') . "\n"
         . "E-Mail:       " . ($email ?: '—') . "\n"
         . "Interesse als: " . ($rollen ?: '—') . "\n"
         . "Zeit:         " . date('d.m.Y H:i') . "\n";

$ok = @mail(EMPFAENGER, $betreff, $text, $kopf);

// Auto-Bestätigung an den Interessenten (nur wenn gültige Adresse angegeben)
if (istEmail($email)) {
    $bBetreff = mb_encode_mimeheader('Vielen Dank für Ihr Interesse an der Rapid.Tech 3D', 'UTF-8');
    $bText    = "Hallo " . ($name !== '' ? $name : '') . ",\n\n"
              . "vielen Dank für Ihre Interessensbekundung zur Rapid.Tech 3D!\n"
              . "Wir haben Ihre Angaben notiert und melden uns, sobald es konkret wird.\n\n"
              . ($rollen !== '' ? "Ihr angegebenes Interesse: " . $rollen . "\n\n" : '')
              . "Herzliche Grüße\nRapid.Tech 3D — Verein in Gründung\nhttps://fruthzeug.de/rapidtech/\n";
    @mail($email, $bBetreff, $bText, "From: " . ABSENDER . "\r\nContent-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0");
}

if ($ok) {
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'fehler' => 'Der Versand hat nicht geklappt. Bitte per E-Mail an kontakt@rapidtech-3d.de.'], JSON_UNESCAPED_UNICODE);
}
