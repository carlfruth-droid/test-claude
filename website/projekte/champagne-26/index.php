<?php
declare(strict_types=1);
session_start();

// Passwort für das Hochladen/Löschen von Bildern.
// Zum Ändern: einfach den Text zwischen den Anführungszeichen austauschen.
const UPLOAD_PASSWORT = 'dontwastewater';

const BILDER_DIR  = __DIR__ . '/bilder';
const MAX_GROESSE = 25 * 1024 * 1024; // 25 MB pro Bild

$erlaubteTypen = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];

if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$eingeloggt = ($_SESSION['upload_ok'] ?? false) === true;

function zurueck(string $query = ''): void
{
    header('Location: ./' . $query);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        zurueck('?fehler=' . rawurlencode('Die Sitzung ist abgelaufen – bitte nochmal versuchen.'));
    }
    $aktion = (string)($_POST['aktion'] ?? '');

    if ($aktion === 'login') {
        if (hash_equals(UPLOAD_PASSWORT, (string)($_POST['passwort'] ?? ''))) {
            $_SESSION['upload_ok'] = true;
            zurueck('?ok=' . rawurlencode('Angemeldet – du kannst jetzt Bilder hochladen.'));
        }
        zurueck('?fehler=' . rawurlencode('Das Passwort stimmt nicht.'));
    }

    if ($aktion === 'logout') {
        unset($_SESSION['upload_ok']);
        zurueck('?ok=' . rawurlencode('Abgemeldet.'));
    }

    if (!$eingeloggt) {
        zurueck('?fehler=' . rawurlencode('Bitte zuerst unten mit dem Passwort anmelden.'));
    }

    if ($aktion === 'upload') {
        $dateien = $_FILES['bilder'] ?? null;
        if ($dateien === null) {
            zurueck('?fehler=' . rawurlencode('Keine Datei ausgewählt.'));
        }
        $hochgeladen = 0;
        $abgelehnt   = 0;
        $finfo       = new finfo(FILEINFO_MIME_TYPE);
        foreach ((array)$dateien['name'] as $i => $originalName) {
            if (($dateien['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $abgelehnt++;
                continue;
            }
            $tmp = (string)$dateien['tmp_name'][$i];
            if (!is_uploaded_file($tmp) || (int)$dateien['size'][$i] > MAX_GROESSE) {
                $abgelehnt++;
                continue;
            }
            $typ = $finfo->file($tmp);
            if (!isset($erlaubteTypen[$typ])) {
                $abgelehnt++;
                continue;
            }
            $basis = preg_replace('/[^A-Za-z0-9_-]+/', '-', pathinfo((string)$originalName, PATHINFO_FILENAME));
            $basis = trim((string)$basis, '-') ?: 'bild';
            $ziel  = sprintf(
                '%s/%s-%s-%s.%s',
                BILDER_DIR,
                date('Ymd-His'),
                bin2hex(random_bytes(3)),
                substr($basis, 0, 40),
                $erlaubteTypen[$typ]
            );
            if (move_uploaded_file($tmp, $ziel)) {
                @chmod($ziel, 0644);
                $hochgeladen++;
            } else {
                $abgelehnt++;
            }
        }
        $text = $hochgeladen . ' Bild(er) hochgeladen.';
        if ($abgelehnt > 0) {
            $text .= ' ' . $abgelehnt . ' Datei(en) übersprungen (kein Bild, zu groß oder Fehler).';
        }
        zurueck('?ok=' . rawurlencode($text));
    }

    if ($aktion === 'loeschen') {
        $name = basename((string)($_POST['datei'] ?? ''));
        $pfad = BILDER_DIR . '/' . $name;
        if ($name !== '' && preg_match('/\.(jpe?g|png|gif|webp)$/i', $name) && is_file($pfad)) {
            unlink($pfad);
            zurueck('?ok=' . rawurlencode('Bild gelöscht.'));
        }
        zurueck('?fehler=' . rawurlencode('Bild nicht gefunden.'));
    }

    zurueck();
}

$meldung = (string)($_GET['ok'] ?? '');
$fehler  = (string)($_GET['fehler'] ?? '');

$bilder = [];
$treffer = glob(BILDER_DIR . '/*.{jpg,jpeg,png,gif,webp,JPG,JPEG,PNG,GIF,WEBP}', GLOB_BRACE);
foreach (($treffer ?: []) as $pfad) {
    $bilder[] = ['name' => basename($pfad), 'zeit' => (int)filemtime($pfad)];
}
usort($bilder, static fn(array $a, array $b): int => $b['zeit'] <=> $a['zeit']);

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title>Champagne 26 – fruthzeug.de</title>
  <style>
    :root {
      --bg: #faf9f7;
      --text: #2a2a28;
      --muted: #6b6b66;
      --accent: #b4532a;
      --card: #ffffff;
      --border: #e8e6e1;
      --ok: #2e7d32;
      --warn: #b3261e;
    }
    @media (prefers-color-scheme: dark) {
      :root {
        --bg: #1c1b19;
        --text: #ece9e4;
        --muted: #a3a09a;
        --accent: #e08050;
        --card: #262421;
        --border: #3a3833;
        --ok: #7cc47f;
        --warn: #ef8a80;
      }
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
      font-family: Georgia, 'Times New Roman', serif;
      background: var(--bg);
      color: var(--text);
      line-height: 1.7;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }
    a { color: var(--accent); }

    .site-header {
      position: sticky;
      top: 0;
      z-index: 10;
      display: flex;
      align-items: center;
      gap: 1rem;
      padding: 0.85rem 1.2rem;
      background: var(--card);
      border-bottom: 1px solid var(--border);
    }
    .brand { margin-right: auto; font-size: 1.15rem; color: var(--text); text-decoration: none; }
    .brand strong { color: var(--accent); font-weight: normal; }
    #nav-toggle { display: none; }
    .burger { display: none; }
    nav.site-nav { display: flex; gap: 1.4rem; }
    nav.site-nav a { color: var(--text); text-decoration: none; padding: 0.15rem 0; border-bottom: 2px solid transparent; }
    nav.site-nav a:hover,
    nav.site-nav a[aria-current="page"] { color: var(--accent); border-bottom-color: var(--accent); }
    @media (max-width: 700px) {
      .burger { display: flex; flex-direction: column; justify-content: center; gap: 5px; padding: 8px; cursor: pointer; -webkit-tap-highlight-color: transparent; }
      .burger span { display: block; width: 26px; height: 3px; background: var(--text); border-radius: 2px; transition: transform 0.25s, opacity 0.25s; }
      nav.site-nav {
        display: none; position: absolute; top: 100%; left: 0; right: 0;
        flex-direction: column; gap: 0; background: var(--card);
        border-bottom: 1px solid var(--border); box-shadow: 0 8px 16px rgba(0,0,0,0.08);
      }
      nav.site-nav a { padding: 0.95rem 1.4rem; border-bottom: 1px solid var(--border); }
      #nav-toggle:checked ~ nav.site-nav { display: flex; }
      #nav-toggle:checked ~ .burger span:nth-child(1) { transform: translateY(8px) rotate(45deg); }
      #nav-toggle:checked ~ .burger span:nth-child(2) { opacity: 0; }
      #nav-toggle:checked ~ .burger span:nth-child(3) { transform: translateY(-8px) rotate(-45deg); }
    }

    main { flex: 1; width: 100%; max-width: 62rem; margin: 0 auto; padding: 2rem 1.2rem 4rem; }
    h1 { font-size: clamp(1.7rem, 5vw, 2.4rem); font-weight: normal; margin-bottom: 0.3rem; }
    .untertitel { color: var(--muted); font-style: italic; margin-bottom: 1.6rem; }

    .hinweis { border-radius: 8px; padding: 0.8rem 1.1rem; margin-bottom: 1.4rem; border: 1px solid var(--border); background: var(--card); }
    .hinweis.ok { border-left: 4px solid var(--ok); }
    .hinweis.fehler { border-left: 4px solid var(--warn); }

    .galerie {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
      gap: 0.7rem;
    }
    .bild {
      position: relative;
      border: 1px solid var(--border);
      border-radius: 8px;
      overflow: hidden;
      background: var(--card);
      aspect-ratio: 1;
    }
    .bild img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .bild form { position: absolute; top: 6px; right: 6px; }
    .bild button {
      border: none; border-radius: 6px; padding: 4px 9px; cursor: pointer;
      background: rgba(0,0,0,0.55); color: #fff; font-size: 0.85rem;
    }
    .leer { color: var(--muted); font-style: italic; padding: 2rem 0; }

    .box {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 1.6rem;
      margin-top: 2.2rem;
    }
    .box h2 { font-size: 1.15rem; font-weight: normal; color: var(--accent); margin-bottom: 0.7rem; }
    input[type="password"], input[type="file"] {
      width: 100%;
      padding: 0.65rem;
      border: 1px solid var(--border);
      border-radius: 8px;
      background: var(--bg);
      color: var(--text);
      font-size: 1rem;
      margin-bottom: 0.8rem;
    }
    button.haupt {
      background: var(--accent);
      color: #fff;
      border: none;
      border-radius: 8px;
      padding: 0.7rem 1.6rem;
      font-size: 1rem;
      cursor: pointer;
      font-family: inherit;
    }
    .abmelden { margin-top: 0.9rem; font-size: 0.9rem; }
    .abmelden button { background: none; border: none; color: var(--muted); text-decoration: underline; cursor: pointer; font-size: 0.9rem; font-family: inherit; }

    footer { text-align: center; padding: 2rem 1.5rem; color: var(--muted); font-size: 0.9rem; border-top: 1px solid var(--border); }
  </style>
</head>
<body>
  <header class="site-header">
    <a class="brand" href="/"><strong>fruthzeug</strong>.de</a>
    <input type="checkbox" id="nav-toggle" aria-hidden="true">
    <label for="nav-toggle" class="burger" aria-label="Menü öffnen">
      <span></span><span></span><span></span>
    </label>
    <nav class="site-nav">
      <a href="/">Start</a>
      <a href="/#projekte">Projekte</a>
      <a href="/projekte/champagne-26/" aria-current="page">Champagne 26</a>
      <a href="/projekte/champagner/">Champagner</a>
      <a href="mailto:post@fruthzeug.de">Kontakt</a>
    </nav>
  </header>

  <main>
    <h1>Champagne 26</h1>
    <p class="untertitel">Bildergalerie &middot; <?= count($bilder) ?> Bild(er)</p>

    <?php if ($meldung !== ''): ?>
      <div class="hinweis ok"><?= e($meldung) ?></div>
    <?php endif; ?>
    <?php if ($fehler !== ''): ?>
      <div class="hinweis fehler"><?= e($fehler) ?></div>
    <?php endif; ?>

    <?php if ($bilder === []): ?>
      <p class="leer">Noch keine Bilder – lade unten das erste hoch!</p>
    <?php else: ?>
      <div class="galerie">
        <?php foreach ($bilder as $bild): ?>
          <div class="bild">
            <a href="bilder/<?= e(rawurlencode($bild['name'])) ?>" target="_blank">
              <img src="bilder/<?= e(rawurlencode($bild['name'])) ?>" alt="" loading="lazy">
            </a>
            <?php if ($eingeloggt): ?>
              <form method="post" onsubmit="return confirm('Dieses Bild wirklich löschen?');">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="aktion" value="loeschen">
                <input type="hidden" name="datei" value="<?= e($bild['name']) ?>">
                <button type="submit" title="Bild löschen">&#10005;</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="box">
      <?php if ($eingeloggt): ?>
        <h2>Bilder hochladen</h2>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
          <input type="hidden" name="aktion" value="upload">
          <input type="file" name="bilder[]" accept="image/*" multiple required>
          <button class="haupt" type="submit">Hochladen</button>
        </form>
        <p class="abmelden">
          Du kannst auch mehrere Bilder auf einmal auswählen (max. 25&nbsp;MB pro Bild).
        </p>
        <form method="post" class="abmelden">
          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
          <input type="hidden" name="aktion" value="logout">
          <button type="submit">Abmelden</button>
        </form>
      <?php else: ?>
        <h2>Zum Hochladen anmelden</h2>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
          <input type="hidden" name="aktion" value="login">
          <input type="password" name="passwort" placeholder="Passwort" autocomplete="current-password" required>
          <button class="haupt" type="submit">Anmelden</button>
        </form>
      <?php endif; ?>
    </div>
  </main>

  <footer>
    <p>&copy; 2026 Carl &middot; <a href="/">Zur&uuml;ck zur Startseite</a></p>
  </footer>
</body>
</html>
