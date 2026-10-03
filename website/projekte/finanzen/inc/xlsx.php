<?php
declare(strict_types=1);

/*
 * Kleiner Excel-Leser (.xlsx): liefert je Tabellenblatt die Zeilen als
 * Listen ['A' => Wert, 'B' => Wert, …]. Nutzt ZipArchive, wenn vorhanden,
 * sonst einen eingebauten Zip-Leser (gzinflate).
 */

/** Alle Dateien eines Zip-Archivs: [pfad => inhalt], nur die gewünschten Pfade (Präfix). */
function zipDateien(string $pfad, string $praefix = ''): array
{
    $erg = [];
    if (class_exists('ZipArchive')) {
        $z = new ZipArchive();
        if ($z->open($pfad) !== true) {
            return [];
        }
        for ($i = 0; $i < $z->numFiles; $i++) {
            $name = (string)$z->getNameIndex($i);
            if ($praefix === '' || str_starts_with($name, $praefix)) {
                $erg[$name] = (string)$z->getFromIndex($i);
            }
        }
        $z->close();
        return $erg;
    }
    $roh = (string)@file_get_contents($pfad);
    // Ende des zentralen Verzeichnisses suchen
    $ende = strrpos($roh, "PK\x05\x06");
    if ($ende === false) {
        return [];
    }
    $e = unpack('vanzahl/Vgroesse/Vstart', substr($roh, $ende + 10, 10));
    $pos = (int)$e['start'];
    for ($i = 0; $i < (int)$e['anzahl']; $i++) {
        if (substr($roh, $pos, 4) !== "PK\x01\x02") {
            break;
        }
        $c = unpack('vmethode/vzeit/vdatum/Vcrc/Vgepackt/Vgross/vnamelen/vextralen/vkommlen/vdisk/vint/Vext/Vlokal', substr($roh, $pos + 10, 36));
        $name = substr($roh, $pos + 46, (int)$c['namelen']);
        $pos += 46 + $c['namelen'] + $c['extralen'] + $c['kommlen'];
        if ($praefix !== '' && !str_starts_with($name, $praefix)) {
            continue;
        }
        $l = unpack('vnamelen/vextralen', substr($roh, (int)$c['lokal'] + 26, 4));
        $daten = substr($roh, (int)$c['lokal'] + 30 + $l['namelen'] + $l['extralen'], (int)$c['gepackt']);
        if ((int)$c['methode'] === 8) {
            $daten = @gzinflate($daten);
        } elseif ((int)$c['methode'] !== 0) {
            $daten = false;
        }
        if (is_string($daten)) {
            $erg[$name] = $daten;
        }
    }
    return $erg;
}

/** Excel-Datei lesen: [blattname => [zeile => ['A' => …]]]; leeres Array, wenn keine gültige xlsx-Datei. */
function xlsxLesen(string $pfad): array
{
    $dateien = zipDateien($pfad, 'xl/');
    if (!isset($dateien['xl/workbook.xml'])) {
        return [];
    }
    $xml = static function (string $s): ?SimpleXMLElement {
        $alt = libxml_use_internal_errors(true);
        $x = simplexml_load_string(preg_replace('/^\xEF\xBB\xBF/', '', $s), SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_use_internal_errors($alt);
        return $x === false ? null : $x;
    };
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $texte = [];
    if (isset($dateien['xl/sharedStrings.xml']) && ($ss = $xml($dateien['xl/sharedStrings.xml']))) {
        foreach ($ss->children($ns)->si as $si) {
            $t = '';
            foreach ($si->xpath('.//*[local-name()="t"]') ?: [] as $teil) {
                $t .= (string)$teil;
            }
            $texte[] = $t;
        }
    }
    $ziele = [];
    if (isset($dateien['xl/_rels/workbook.xml.rels']) && ($rels = $xml($dateien['xl/_rels/workbook.xml.rels']))) {
        foreach ($rels->children('http://schemas.openxmlformats.org/package/2006/relationships')->Relationship as $r) {
            $ra = $r->attributes();
            $ziel = ltrim((string)$ra['Target'], '/');
            $ziele[(string)$ra['Id']] = str_starts_with($ziel, 'xl/') ? $ziel : 'xl/' . $ziel;
        }
    }
    $wb = $xml($dateien['xl/workbook.xml']);
    if ($wb === null) {
        return [];
    }
    $blaetter = [];
    foreach ($wb->children($ns)->sheets->sheet ?? [] as $s) {
        $rid = (string)($s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
        $inhalt = $dateien[$ziele[$rid] ?? ''] ?? null;
        $blatt = $inhalt !== null ? $xml($inhalt) : null;
        $blatt = $blatt?->children($ns);
        if ($blatt === null) {
            continue;
        }
        $zeilen = [];
        foreach ($blatt->sheetData->row ?? [] as $row) {
            $z = [];
            foreach ($row->c as $c) {
                $a = $c->attributes();
                if (!preg_match('/^[A-Z]+/', (string)$a['r'], $m)) {
                    continue;
                }
                $typ = (string)$a['t'];
                if ($typ === 's') {
                    $wert = $texte[(int)$c->v] ?? '';
                } elseif ($typ === 'inlineStr') {
                    $wert = '';
                    foreach ($c->xpath('.//*[local-name()="t"]') ?: [] as $teil) {
                        $wert .= (string)$teil;
                    }
                } else {
                    $wert = (string)$c->v;
                }
                $z[$m[0]] = trim($wert);
            }
            $zeilen[] = $z;
        }
        $blaetter[(string)$s->attributes()['name']] = $zeilen;
    }
    return $blaetter;
}
