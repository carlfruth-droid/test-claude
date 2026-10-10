/* Finanzzentrale – Bedienung im Browser */
(function () {
  'use strict';

  var csrf = document.body.getAttribute('data-csrf') || '';
  var $ = function (sel, el) { return (el || document).querySelector(sel); };
  var $$ = function (sel, el) { return Array.prototype.slice.call((el || document).querySelectorAll(sel)); };
  var speicher = {
    lesen: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
    schreiben: function (k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* privat/gesperrt */ } }
  };

  // ---------- Burgermenü ----------
  var burger = $('.burger');
  var menue = $('#menue');
  var schleier = $('.schleier');
  function menueSetzen(offen) {
    if (!menue) { return; }
    menue.classList.toggle('offen', offen);
    menue.setAttribute('aria-hidden', offen ? 'false' : 'true');
    burger.setAttribute('aria-expanded', offen ? 'true' : 'false');
    if (offen) {
      schleier.hidden = false;
      requestAnimationFrame(function () { schleier.classList.add('sichtbar'); });
      var erster = $('a', menue);
      if (erster) { erster.focus({ preventScroll: true }); }
    } else {
      schleier.classList.remove('sichtbar');
      setTimeout(function () { schleier.hidden = true; }, 200);
    }
    document.documentElement.style.overflow = offen ? 'hidden' : '';
  }
  if (burger && menue) {
    burger.addEventListener('click', function () { menueSetzen(!menue.classList.contains('offen')); });
    schleier.addEventListener('click', function () { menueSetzen(false); });
    $('.menue-zu', menue).addEventListener('click', function () { menueSetzen(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { menueSetzen(false); } });
  }

  // ---------- Infoknöpfe ⓘ: Erklärung als Sprechblase ----------
  var blase = null;
  var blaseZu = function () { if (blase) { blase.remove(); blase = null; } };
  document.addEventListener('click', function (e) {
    var k = e.target.closest ? e.target.closest('[data-info]') : null;
    if (!k) {
      if (blase && !blase.contains(e.target)) { blaseZu(); }
      return;
    }
    // nicht zusätzlich das Akkordeon, den Link oder den Chip auslösen
    e.preventDefault();
    e.stopPropagation();
    if (blase && blase._knopf === k) { blaseZu(); return; }
    blaseZu();
    blase = document.createElement('div');
    blase.className = 'info-blase';
    blase.setAttribute('role', 'tooltip');
    blase.textContent = k.getAttribute('data-info');
    blase._knopf = k;
    document.body.appendChild(blase);
    var sicht = blase._breite = document.documentElement.clientWidth;
    var breite = Math.min(340, sicht - 24);
    blase.style.width = breite + 'px';
    var r = k.getBoundingClientRect();
    var links = Math.max(12, Math.min(sicht - breite - 12, r.left + r.width / 2 - breite / 2));
    var hoehe = blase.offsetHeight;
    var oben = r.bottom + 9;
    if (oben + hoehe > window.innerHeight - 8 && r.top - 9 - hoehe > 8) { oben = r.top - 9 - hoehe; blase.classList.add('oben'); }
    blase.style.left = (links + window.scrollX) + 'px';
    blase.style.top = (oben + window.scrollY) + 'px';
    blase.style.setProperty('--pfeil', Math.max(14, Math.min(breite - 14, r.left + r.width / 2 - links)) + 'px');
  }, true);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { blaseZu(); } });
  // nur bei echter Breitenänderung schließen (beim Scrollen ändert das Handy oft nur die Höhe)
  window.addEventListener('resize', function () { if (blase && blase._breite !== document.documentElement.clientWidth) { blaseZu(); } });

  // ---------- Zurück: die zuletzt besuchten Seiten dieses Tabs ----------
  var zurueckLeiste = $('[data-zurueck]');
  var pfadVorher = null;
  function pfadAktualisieren() {
    var hier = { u: location.pathname + location.search, t: document.title.replace(/\s·\sFinanzzentrale$/, '') };
    var pfad = [];
    try { pfad = JSON.parse(sessionStorage.getItem('fz-pfad') || '[]'); } catch (e) { pfad = []; }
    if (!Array.isArray(pfad)) { pfad = []; }
    var n = pfad.length;
    if (n && pfad[n - 1].u === hier.u) {
      pfad[n - 1] = hier; // neu geladen
    } else if (n > 1 && pfad[n - 2].u === hier.u) {
      pfad.pop(); // zurückgegangen
    } else {
      pfad.push(hier);
    }
    pfad = pfad.slice(-30);
    try { sessionStorage.setItem('fz-pfad', JSON.stringify(pfad)); } catch (e) { /* ohne sessionStorage nur der Ersatzlink */ }
    pfadVorher = pfad.length > 1 ? pfad[pfad.length - 2] : null;
    if (!zurueckLeiste) { return; }
    var ziel = pfadVorher ? pfadVorher.u : zurueckLeiste.getAttribute('data-zurueck-ersatz');
    zurueckLeiste.hidden = !ziel;
    if (!ziel) { return; }
    var a = $('a', zurueckLeiste);
    a.href = ziel;
    $('span', a).textContent = pfadVorher ? pfadVorher.t : zurueckLeiste.getAttribute('data-zurueck-ersatz-titel');
  }
  pfadAktualisieren();
  window.addEventListener('pageshow', function (e) { if (e.persisted) { pfadAktualisieren(); } });
  if (zurueckLeiste) {
    $('a', zurueckLeiste).addEventListener('click', function (e) {
      // echter Schritt zurück: Scrollposition und aufgeklappte Bereiche bleiben erhalten
      if (!pfadVorher || !document.referrer) { return; }
      try {
        var r = new URL(document.referrer);
        if (r.origin === location.origin && r.pathname + r.search === pfadVorher.u) { e.preventDefault(); history.back(); }
      } catch (x) { /* dann eben als normaler Link */ }
    });
  }

  // ---------- Meldungen schließen ----------
  $$('.meldung-zu').forEach(function (k) {
    k.addEventListener('click', function () { k.parentNode.remove(); });
  });
  setTimeout(function () { $$('.meldung.ok').forEach(function (m) { m.style.transition = 'opacity .4s'; m.style.opacity = '0'; setTimeout(function () { m.remove(); }, 450); }); }, 6000);

  // ---------- Akkordeons: Zustand merken und Inhalte nachladen ----------
  var seitenSchluessel = 'fz-akk:' + (new URLSearchParams(location.search).get('seite') || 'start');
  var gemerkt = {};
  try { gemerkt = JSON.parse(speicher.lesen(seitenSchluessel) || '{}') || {}; } catch (e) { gemerkt = {}; }

  function nachladen(akk, frisch) {
    var teil = akk.getAttribute('data-teil');
    if (!teil || (akk.getAttribute('data-geladen') && !frisch)) { return; }
    akk.setAttribute('data-geladen', '1');
    var ziel = $('.akk-inhalt', akk);
    if (frisch) { $$('[data-neu-laden]', ziel).forEach(function (k) { k.disabled = true; k.textContent = 'Wird aktualisiert …'; }); }
    fetch('?teil=' + encodeURIComponent(teil) + '&s=' + encodeURIComponent(akk.getAttribute('data-s') || '') + (frisch ? '&frisch=1' : ''), { credentials: 'same-origin' })
      .then(function (r) {
        if (r.status === 401) { location.reload(); return ''; }
        return r.text();
      })
      .then(function (html) {
        ziel.innerHTML = html;
        aufbauen(ziel);
        kiBinden(ziel);
        var logo = $('[data-logo-url]', ziel);
        if (logo) { logoSetzen(logo.getAttribute('data-logo-url')); }
      })
      .catch(function () {
        akk.removeAttribute('data-geladen');
        ziel.innerHTML = '<p class="leer">Konnte nicht geladen werden – bitte erneut öffnen.</p>';
      });
  }

  $$('details.akk').forEach(function (akk) {
    var id = akk.id;
    if (id && Object.prototype.hasOwnProperty.call(gemerkt, id)) { akk.open = !!gemerkt[id]; }
    if (akk.open) { nachladen(akk); }
    akk.addEventListener('toggle', function () {
      if (akk.open) { nachladen(akk, veraltet(akk)); }
      if (id) { gemerkt[id] = akk.open ? 1 : 0; speicher.schreiben(seitenSchluessel, JSON.stringify(gemerkt)); }
    });
  });

  // Nachrichten: älter als 30 Minuten abgerufen → beim Öffnen bzw. Zurückkehren neu laden
  function veraltet(akk) {
    var k = $('[data-abgerufen]', akk);
    var t = k ? parseInt(k.getAttribute('data-abgerufen'), 10) : 0;
    return !!t && Date.now() / 1000 - t > 1800;
  }
  function vorZeit(ts) {
    var d = Date.now() / 1000 - ts;
    if (d < 90) { return 'gerade eben'; }
    if (d < 3600) { return 'vor ' + Math.round(d / 60) + ' Min.'; }
    if (d < 86400) { return 'vor ' + Math.round(d / 3600) + ' Std.'; }
    var tage = Math.floor(d / 86400);
    return tage === 1 ? 'gestern' : 'vor ' + tage + ' Tagen';
  }
  function zeitenNachfuehren() {
    $$('[data-vor]').forEach(function (el) { el.textContent = '(' + vorZeit(parseInt(el.getAttribute('data-vor'), 10)) + ')'; });
  }
  setInterval(zeitenNachfuehren, 60000);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState !== 'visible') { return; }
    zeitenNachfuehren();
    $$('details.akk[open]').forEach(function (akk) { if (veraltet(akk)) { nachladen(akk, true); } });
  });
  window.addEventListener('pageshow', function (e) { if (e.persisted) { zeitenNachfuehren(); $$('details.akk[open]').forEach(function (akk) { if (veraltet(akk)) { nachladen(akk, true); } }); } });

  // ---------- Depot: Positionen sortieren (alle Depots gleich) ----------
  var depotSort = $('[data-sortierung]');
  if (depotSort) {
    var dsZustand = { feld: 'wert', richtung: 'ab' };
    try { dsZustand = Object.assign(dsZustand, JSON.parse(speicher.lesen('fz-depot-sort') || '{}')); } catch (e) { /* egal */ }
    var dsWahl = $('select', depotSort), dsKnopf = $('button', depotSort);
    var dsAnwenden = function () {
      dsWahl.value = dsZustand.feld; dsKnopf.textContent = dsZustand.richtung === 'auf' ? '↑' : '↓';
      $$('ul[data-sortieren]').forEach(function (ul) {
        var li = Array.prototype.slice.call(ul.children);
        li.sort(function (a, b) {
          var x = a.getAttribute('data-s-' + dsZustand.feld), y = b.getAttribute('data-s-' + dsZustand.feld);
          if (x === '' || y === '') { return (x === '') - (y === ''); }
          var c = dsZustand.feld === 'name' ? x.localeCompare(y, 'de') : parseFloat(x) - parseFloat(y);
          return dsZustand.richtung === 'auf' ? c : -c;
        });
        li.forEach(function (el) { ul.appendChild(el); });
      });
      speicher.schreiben('fz-depot-sort', JSON.stringify(dsZustand));
    };
    dsWahl.addEventListener('change', function () { dsZustand.feld = dsWahl.value; dsZustand.richtung = dsWahl.value === 'name' ? 'auf' : 'ab'; dsAnwenden(); });
    dsKnopf.addEventListener('click', function () { dsZustand.richtung = dsZustand.richtung === 'auf' ? 'ab' : 'auf'; dsAnwenden(); });
    dsAnwenden();
  }

  // ---------- Push-Test direkt vom Gerät (ntfy drosselt das Webhosting) ----------
  $$('[data-ntfy-test]').forEach(function (k) {
    k.addEventListener('click', function () {
      var ziel = $('[data-ntfy-ergebnis]');
      k.disabled = true;
      fetch('https://ntfy.sh/', { method: 'POST', body: JSON.stringify({ topic: k.getAttribute('data-thema'), title: '✅ Push-Test aus deiner Finanzzentrale', message: 'Wenn du das liest, kommen deine Push-Alarme an.', click: k.getAttribute('data-link'), tags: ['chart_with_upwards_trend'] }) })
        .then(function (r) { ziel.textContent = r.ok ? 'Verschickt – kommt gleich in der ntfy-App an.' : 'ntfy meldet HTTP ' + r.status + '.'; ziel.className = 'klein ' + (r.ok ? 'plus' : 'minus'); })
        .catch(function () { ziel.textContent = 'ntfy war nicht erreichbar.'; ziel.className = 'klein minus'; })
        .then(function () { k.disabled = false; });
    });
  });

  // ---------- Depot: importierte Titel schrittweise zuordnen ----------
  var zuordnen = $('[data-zuordnen]');
  if (zuordnen) {
    var gesamt = parseInt(zuordnen.getAttribute('data-zuordnen'), 10) || 1, fehlversuche = 0;
    var schritt = function () {
      var daten = new FormData();
      daten.append('csrf', csrf);
      fetch('?api=zuordnen', { method: 'POST', body: daten, credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
        .then(function (j) {
          fehlversuche = 0;
          $('[data-zuordnen-zahl]', zuordnen).textContent = j.offen;
          $('[data-zuordnen-balken]', zuordnen).style.width = Math.round((1 - j.offen / gesamt) * 100) + '%';
          if (j.offen > 0) { schritt(); } else { location.reload(); }
        })
        .catch(function () {
          if (++fehlversuche < 4) { setTimeout(schritt, 3000); return; }
          $('[data-zuordnen-hinweis]', zuordnen).textContent = 'Die Verbindung zum Server ist abgebrochen. Lade die Seite neu, um weiterzumachen.';
        });
    };
    schritt();
  }

  // Anker (#f-limits) öffnet das passende Akkordeon
  function ankerOeffnen(id) {
    var el = document.getElementById(id);
    if (el && el.tagName === 'DETAILS') { el.open = true; nachladen(el); el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
  }
  if (location.hash.length > 1) { ankerOeffnen(location.hash.slice(1)); }

  // ---------- Bausteine, die auch in nachgeladenen Teilen vorkommen ----------
  function aufbauen(wurzel) {
    $$('[data-reiter]', wurzel).forEach(function (r) {
      $$('[data-reiter-ziel]', r).forEach(function (k) {
        k.addEventListener('click', function () {
          $$('[data-reiter-ziel]', r).forEach(function (x) { x.classList.toggle('aktiv', x === k); });
          $$('[data-reiter-inhalt]', r).forEach(function (x) { x.hidden = x.getAttribute('data-reiter-inhalt') !== k.getAttribute('data-reiter-ziel'); });
        });
      });
    });
    $$('[data-neu-laden]', wurzel).forEach(function (k) {
      k.addEventListener('click', function () { var akk = k.closest('details.akk'); if (akk) { nachladen(akk, true); } });
    });
    if (wurzel !== document) { zeitenNachfuehren(); }
    $$('[data-oeffne]', wurzel).forEach(function (a) {
      a.addEventListener('click', function (e) { e.preventDefault(); ankerOeffnen(a.getAttribute('data-oeffne')); });
    });
    $$('form[data-bestaetigen]', wurzel).forEach(function (f) {
      f.addEventListener('submit', function (e) { if (!window.confirm(f.getAttribute('data-bestaetigen'))) { e.preventDefault(); } });
    });
    $$('form[data-laden]', wurzel).forEach(function (f) {
      f.addEventListener('submit', function () {
        var k = f.querySelector('button[type=submit]');
        if (k) { setTimeout(function () { k.disabled = true; k.textContent = f.getAttribute('data-laden'); }, 0); }
      });
    });
    $$('[data-auto-absenden]', wurzel).forEach(function (el) {
      el.addEventListener('change', function () { el.form.submit(); });
    });
  }
  aufbauen(document);

  // ---------- Erklärungen zu Kennzahlen ----------
  $$('[data-erklaerungen]').forEach(function (k) {
    k.addEventListener('change', function () {
      $$('.erkl', k.closest('.akk-inhalt')).forEach(function (p) { p.hidden = !k.checked; });
    });
  });

  // ---------- Liste filtern ----------
  $$('[data-filter]').forEach(function (feld) {
    var ziel = $(feld.getAttribute('data-filter'));
    feld.addEventListener('input', function () {
      var q = feld.value.toLowerCase().trim();
      $$('.filterbar', ziel).forEach(function (el) { el.style.display = el.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none'; });
    });
  });

  // ---------- Meine Firmen: an dieselbe Stelle zurückkehren ----------
  var firmenliste = $('#firmenliste');
  if (firmenliste) {
    var filterFeld = $('[data-filter="#firmenliste"]');
    try {
      var zurueck = JSON.parse(sessionStorage.getItem('fz-firmenliste') || 'null');
      if (zurueck && zurueck.url === location.pathname + location.search) {
        if (filterFeld && zurueck.suche) { filterFeld.value = zurueck.suche; filterFeld.dispatchEvent(new Event('input')); }
        if ('scrollRestoration' in history) { history.scrollRestoration = 'manual'; }
        var ziel = zurueck.s ? document.getElementById('fz-' + zurueck.s) : null;
        requestAnimationFrame(function () {
          window.scrollTo(0, zurueck.y);
          // falls sich die Liste inzwischen verschoben hat: die zuletzt geöffnete Firma sichtbar machen
          if (ziel) { var r = ziel.getBoundingClientRect(); if (r.top < 60 || r.bottom > window.innerHeight) { ziel.scrollIntoView({ block: 'center' }); } }
        });
      }
    } catch (e) { /* ohne sessionStorage einfach oben beginnen */ }
    firmenliste.addEventListener('click', function (ev) {
      var a = ev.target.closest('a.zeile');
      if (!a) { return; }
      var zeile = a.closest('.filterbar');
      try {
        sessionStorage.setItem('fz-firmenliste', JSON.stringify({ url: location.pathname + location.search, y: window.scrollY,
          suche: filterFeld ? filterFeld.value : '', s: zeile ? zeile.getAttribute('data-symbol') : '' }));
      } catch (e) { /* egal */ }
    });
  }

  // ---------- Kopieren ----------
  $$('[data-kopieren]').forEach(function (k) {
    k.addEventListener('click', function () {
      var text = ($(k.getAttribute('data-kopieren')) || {}).textContent || '';
      var fertig = function () { var alt = k.textContent; k.textContent = 'Kopiert ✓'; setTimeout(function () { k.textContent = alt; }, 1600); };
      if (navigator.clipboard) { navigator.clipboard.writeText(text).then(fertig, fertig); } else { fertig(); }
    });
  });

  // ---------- Suchvorschläge ----------
  $$('input[data-vorschlaege]').forEach(function (feld) {
    var box = feld.parentNode.querySelector('.vorschlaege');
    var zeitgeber = null;
    var letzte = '';
    var markiert = -1;
    function zeigen(liste) {
      markiert = -1;
      if (!liste.length) { box.hidden = true; box.innerHTML = ''; return; }
      box.innerHTML = '';
      liste.forEach(function (t) {
        var a = document.createElement('a');
        a.href = t.url;
        var name = document.createElement('span');
        name.textContent = t.name;
        var info = document.createElement('small');
        info.textContent = t.symbol + (t.boerse ? ' · ' + t.boerse : '');
        a.appendChild(name);
        a.appendChild(info);
        box.appendChild(a);
      });
      box.hidden = false;
    }
    feld.addEventListener('input', function () {
      var q = feld.value.trim();
      clearTimeout(zeitgeber);
      if (q.length < 2) { zeigen([]); return; }
      zeitgeber = setTimeout(function () {
        letzte = q;
        fetch('?api=suche&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
          .then(function (r) { return r.ok ? r.json() : []; })
          .then(function (liste) { if (letzte === q) { zeigen(liste || []); } })
          .catch(function () { /* still */ });
      }, 280);
    });
    feld.addEventListener('keydown', function (e) {
      var eintraege = $$('a', box);
      if (box.hidden || !eintraege.length) { return; }
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        markiert = (markiert + (e.key === 'ArrowDown' ? 1 : -1) + eintraege.length) % eintraege.length;
        eintraege.forEach(function (a, i) { a.classList.toggle('markiert', i === markiert); });
      } else if (e.key === 'Enter' && markiert >= 0) {
        e.preventDefault();
        location.href = eintraege[markiert].href;
      } else if (e.key === 'Escape') {
        zeigen([]);
      }
    });
    document.addEventListener('click', function (e) { if (!feld.parentNode.contains(e.target)) { box.hidden = true; } });
  });

  // ---------- Limits: Prozent-Knöpfe ----------
  $$('form.limit-neu').forEach(function (f) {
    var kurs = parseFloat(f.getAttribute('data-kurs'));
    $$('[data-pz]', f).forEach(function (k) {
      k.addEventListener('click', function () {
        if (!kurs) { return; }
        var pz = parseFloat(k.getAttribute('data-pz'));
        var wert = kurs * (1 + pz / 100);
        f.elements.wert.value = wert.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        f.elements.typ.value = pz < 0 ? 'unter' : 'ueber';
      });
    });
  });

  // ---------- Formulare: „wird gespeichert“ ----------
  $$('form').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      setTimeout(function () {
        if (e.defaultPrevented) { return; }
        var k = f.querySelector('button[type="submit"]:not([name])');
        if (k && f.method.toLowerCase() === 'post' && f.querySelector('input[type="file"]')) {
          k.disabled = true;
          k.textContent = 'Wird verarbeitet …';
        }
      }, 0);
    });
  });

  // ---------- KI-Einschätzung (auch in nachgeladenen Bereichen) ----------
  function kiBinden(wurzel) {
  $$('[data-ki]', wurzel).forEach(function (bereich) {
    var knopf = $('[data-ki-start]', bereich);
    var status = $('.ki-status', bereich);
    if (!knopf) { return; }
    knopf.addEventListener('click', function () {
      var start = Date.now();
      knopf.disabled = true;
      status.hidden = false;
      status.className = 'ki-status hinweis';
      var tick = function () {
        var s = Math.round((Date.now() - start) / 1000);
        status.innerHTML = '<span class="kreisel"></span> Die KI recherchiert im Web … ' + s + ' s';
        status.style.display = 'flex';
        status.style.gap = '10px';
        status.style.alignItems = 'center';
      };
      tick();
      var uhr = setInterval(tick, 1000);
      var daten = new FormData();
      daten.append('csrf', csrf);
      daten.append('s', bereich.getAttribute('data-ki'));
      daten.append('art', bereich.getAttribute('data-ki-art') || 'einschaetzung');
      fetch('?api=ki', { method: 'POST', body: daten, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          clearInterval(uhr);
          if (j && j.ok) {
            status.textContent = 'Fertig – wird angezeigt …';
            location.hash = { szenario: 'f-empfehlung', wettbewerber: 'f-portraet' }[bereich.getAttribute('data-ki-art')] || 'f-ki';
            location.reload();
          } else {
            status.className = 'ki-status fehlerbox';
            status.textContent = (j && j.fehler) || 'Die Analyse ist fehlgeschlagen.';
            knopf.disabled = false;
          }
        })
        .catch(function () {
          clearInterval(uhr);
          status.className = 'ki-status warnbox';
          status.textContent = 'Die Verbindung wurde unterbrochen. Die Analyse läuft auf dem Server weiter – lade die Seite in ein bis zwei Minuten neu.';
          knopf.disabled = false;
        });
    });
  });
  }
  kiBinden(document);

  // ---------- Logo aus Wikidata (falls vorhanden) ----------
  function logoSetzen(url) {
    var platz = $('.fk-logo');
    if (!platz || !url || platz.getAttribute('data-hat-logo')) { return; }
    var bild = new Image();
    bild.alt = '';
    bild.referrerPolicy = 'no-referrer';
    bild.onload = function () { platz.innerHTML = ''; platz.appendChild(bild); platz.setAttribute('data-hat-logo', '1'); speicher.schreiben('fz-logo:' + platz.getAttribute('data-logo'), url); };
    bild.src = url;
  }
  var logoPlatz = $('.fk-logo');
  if (logoPlatz) {
    var bekannt = speicher.lesen('fz-logo:' + logoPlatz.getAttribute('data-logo'));
    if (bekannt) { logoSetzen(bekannt); }
  }

  // ---------- Liniendiagramm (Kurs-Chart und Depot-Verlauf) ----------
  var NS = 'http://www.w3.org/2000/svg';
  var esc = function (t) {
    return String(t === null || t === undefined ? '' : t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  };
  var hat = function (v) { return v !== null && v !== undefined && isFinite(v); };
  var zahlDe = function (z, stellen) { return z.toLocaleString('de-DE', { minimumFractionDigits: stellen, maximumFractionDigits: stellen }); };
  var vorzeichen = function (z) { return z > 0 ? '+' : (z < 0 ? '−' : ''); };
  // Anteil 0,123 → „+12,3 %“
  // geschützte Leerzeichen und Bindestriche: „+12,3 %“ und „%‑Punkte“ brechen nicht um
  var anteilText = function (z, stellen) { return hat(z) ? vorzeichen(z) + zahlDe(Math.abs(z) * 100, stellen === undefined ? 1 : stellen) + '\u00a0%' : '–'; };
  var punkteText = function (z) { return hat(z) ? vorzeichen(z) + zahlDe(Math.abs(z) * 100, 1) + '\u00a0%\u2011Punkte' : '–'; };
  var klasseZahl = function (z) { return !hat(z) ? '' : (z > 1e-9 ? 'plus' : (z < -1e-9 ? 'minus' : '')); };
  var mitVorzeichen = function (fmt, z) { return vorzeichen(z) + fmt(Math.abs(z)); };
  var dauerText = function (sek) {
    var tage = Math.round(Math.abs(sek) / 86400);
    if (tage < 1) { return 'unter 1 Tag'; }
    if (tage < 62) { return tage + (tage === 1 ? ' Tag' : ' Tage'); }
    var monate = Math.round(tage / 30.44), jahre = Math.floor(monate / 12), rest = monate % 12;
    return (jahre ? jahre + (jahre === 1 ? ' Jahr' : ' Jahre') + (rest ? ' ' + rest + (rest === 1 ? ' Monat' : ' Monate') : '') : monate + ' Monate')
      + ' (' + zahlDe(tage, 0) + ' Tage)';
  };
  // Rendite einer Spanne auf ein Jahr umgerechnet (erst ab einem halben Jahr sinnvoll)
  var proJahr = function (rendite, sek) {
    var jahre = sek / (365.25 * 86400);
    return hat(rendite) && rendite > -1 && jahre >= 0.5 ? Math.pow(1 + rendite, 1 / jahre) - 1 : null;
  };
  var datumKurz = function (t) { return new Date(t * 1000).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' }); };
  var geldFmt = function (w) {
    var zeichen = { EUR: '€', USD: '$', GBP: '£', GBp: 'p', JPY: '¥', CHF: 'CHF' }[w] || w || '';
    return function (z) { return zahlDe(z, 2) + (zeichen ? ' ' + zeichen : ''); };
  };
  var prozentFmt = function (z) { return anteilText(z, 1); };
  var prozentAchse = function (z, min, max) { return anteilText(z, max - min < 0.12 ? 1 : 0); };

  var LINIEN_INFO = {
    s30: 'Durchschnittskurs der letzten 30 Handelstage – glättet die täglichen Schwankungen und zeigt den kurzfristigen Trend.',
    s50: 'Durchschnitt der letzten 50 Handelstage – der mittelfristige Trend.',
    s100: 'Durchschnitt der letzten 100 Handelstage – der mittelfristige Trend.',
    s200: 'Durchschnitt der letzten 200 Handelstage – die bekannteste Trendlinie: Liegt der Kurs darüber, gilt der langfristige Trend als intakt.',
    bollinger: '20-Tage-Durchschnitt plus/minus zwei Standardabweichungen. Ein enges Band heißt ruhiger Kurs; Ausbrüche aus dem Band sind ungewöhnlich starke Bewegungen.',
    hochtief: 'Höchster und tiefster Schlusskurs der letzten 52 Wochen.',
    trend: 'Ausgleichskurve durch den sichtbaren Zeitraum: die durchschnittliche Entwicklung mit dem Wachstum pro Jahr.',
    ziel: 'Kursziele der Analysten für die nächsten 12 Monate: Mittelwert sowie höchstes und tiefstes Ziel.',
    szenario: 'Kursziele aus „Meine Empfehlung“: Bullen-, Basis- und Bären-Szenario.',
    marken: 'Deine Käufe (▲) und Verkäufe (▼) aus den importierten Depots.',
    einsatz: 'Wert am Anfang plus alle Käufe minus alle Verkäufe. Der Abstand zur Wertkurve ist der Kursgewinn oder -verlust.'
  };

  // Gleitender Durchschnitt über die letzten „tage“ Kalendertage (je Punkt [Zeit, Wert]); null, solange der Zeitraum nicht gefüllt ist
  function gleitend(punkte, tage) {
    var erg = [], summe = 0, anfang = 0;
    for (var i = 0; i < punkte.length; i++) {
      summe += punkte[i][1];
      while (punkte[anfang][0] <= punkte[i][0] - tage * 86400) { summe -= punkte[anfang][1]; anfang++; }
      erg.push(punkte[i][0] - punkte[0][0] >= tage * 86400 * 0.9 ? [punkte[i][0], summe / (i - anfang + 1)] : [punkte[i][0], null]);
    }
    return erg;
  }
  // Bollinger-Bänder über n Kurspunkte (± k Standardabweichungen)
  function bollinger(punkte, n, k) {
    var oben = [], unten = [];
    for (var i = 0; i < punkte.length; i++) {
      if (i < n - 1) { oben.push([punkte[i][0], null]); unten.push([punkte[i][0], null]); continue; }
      var s = 0, q = 0;
      for (var j = i - n + 1; j <= i; j++) { s += punkte[j][1]; q += punkte[j][1] * punkte[j][1]; }
      var m = s / n, sd = Math.sqrt(Math.max(0, q / n - m * m));
      oben.push([punkte[i][0], m + k * sd]);
      unten.push([punkte[i][0], m - k * sd]);
    }
    return { oben: oben, unten: unten };
  }
  // Exponentielle Ausgleichskurve (lineare Regression auf den Logarithmus)
  function trendlinie(punkte) {
    var t0 = punkte.length ? punkte[0][0] : 0, sx = 0, sy = 0, sxx = 0, sxy = 0, m = 0;
    var jahr = function (t) { return (t - t0) / (365.25 * 86400); };
    punkte.forEach(function (p) { if (p[1] > 0) { var x = jahr(p[0]), y = Math.log(p[1]); sx += x; sy += y; sxx += x * x; sxy += x * y; m++; } });
    var nenner = m * sxx - sx * sx;
    if (m < 3 || Math.abs(nenner) < 1e-12) { return null; }
    var b = (m * sxy - sx * sy) / nenner, a = (sy - b * sx) / m;
    return { punkte: punkte.map(function (p) { return [p[0], Math.exp(a + b * jahr(p[0]))]; }), proJahr: Math.exp(b) - 1 };
  }
  // Höchster und tiefster Schlusskurs der letzten 365 Tage
  function hochTief(reihe) {
    if (!reihe.length) { return null; }
    var ab = reihe[reihe.length - 1][0] - 365 * 86400, hoch = -Infinity, tief = Infinity;
    reihe.forEach(function (p) { if (p[0] >= ab) { hoch = Math.max(hoch, p[1]); tief = Math.min(tief, p[1]); } });
    return { hoch: hoch, tief: tief };
  }
  // Zweite Reihe auf die Zeitpunkte der ersten legen: letzter Kurs am oder vor demselben Tag (höchstens 10 Tage alt)
  function angleichen(haupt, neben) {
    var erg = [], j = 0, tag = function (t) { return Math.floor(t / 86400); };
    for (var i = 0; i < haupt.length; i++) {
      var d = tag(haupt[i][0]);
      while (j + 1 < neben.length && tag(neben[j + 1][0]) <= d) { j++; }
      var passt = neben.length && tag(neben[j][0]) <= d && d - tag(neben[j][0]) <= 10;
      erg.push([haupt[i][0], passt ? neben[j][1] : null]);
    }
    return erg;
  }

  /*
   * Liniendiagramm mit Bedienleiste. o = {
   *   punkte [[Zeit, Wert]], fmt, achseFmt, datumFmt, titel, leer, farbe,
   *   linien: [{name, farbe, punkte}] | {art:'band', oben, unten} | {art:'waagrecht', wert, text, unten},
   *   marker [{t, typ, text}], prognosen [{mittel, hoch, tief, art, name}],
   *   vergleich {name, farbe, punkte (gleiche Zeitpunkte), fmt, achseFmt, gleicheAchse},
   *   nullLinie, ohneFlaeche, hauptInfo(i) → HTML, messung(i0, i1) → [[Titel, HTML, Klasse]], uebernehmen(t0, t1) }
   * Zustand (Cursor, Messpunkte A/B) bleibt beim Neuzeichnen erhalten.
   */
  function linienChart(chart, o) {
    var punkte = o.punkte || [], fmt = o.fmt, datumFmt = o.datumFmt || datumKurz;
    var leiste = chart.nextElementSibling && chart.nextElementSibling.classList.contains('chart-leiste') ? chart.nextElementSibling : null;
    chart.innerHTML = '';
    if (punkte.length < 2) {
      chart.innerHTML = '<p class="leer">' + esc(o.leer || 'Kein Kursverlauf verfügbar.') + '</p>';
      if (leiste) { leiste.innerHTML = ''; }
      return;
    }
    var n = punkte.length;
    var linien = (o.linien || []).filter(function (l) {
      return l.art === 'waagrecht' ? hat(l.wert) : (l.art === 'band' ? l.oben : l.punkte).some(function (p) { return hat(p[1]); });
    });
    var prognosen = (o.prognosen || []).filter(function (pr) { return pr && hat(pr.mittel); });
    var vgl = o.vergleich && o.vergleich.punkte && o.vergleich.punkte.some(function (p) { return hat(p[1]); }) ? o.vergleich : null;
    var eigeneAchse = !!(vgl && !vgl.gleicheAchse);
    var z = chart._zustand || (chart._zustand = {});
    var B = chart.clientWidth || 600, H = chart.clientHeight || 210, oben = 20, unten = 22, links = eigeneAchse ? 54 : 0, rechtsRand = 54;

    var bereich = function (werte) {
      var min = Infinity, max = -Infinity;
      werte.forEach(function (v) { if (hat(v)) { if (v < min) { min = v; } if (v > max) { max = v; } } });
      if (min === Infinity) { return [0, 1]; }
      if (max === min) { var d0 = Math.abs(max) * 0.02 || 1; max += d0; min -= d0; }
      var puffer = (max - min) * 0.08, nieNegativ = min >= 0;
      min -= puffer; max += puffer;
      if (nieNegativ && min < 0) { min = 0; }
      return [min, max];
    };
    var werte = punkte.map(function (p) { return p[1]; });
    linien.forEach(function (l) {
      if (l.art === 'waagrecht') { werte.push(l.wert); return; }
      (l.art === 'band' ? l.oben.concat(l.unten) : l.punkte).forEach(function (p) { werte.push(p[1]); });
    });
    prognosen.forEach(function (pr) { werte.push(pr.hoch, pr.mittel, pr.tief); });
    if (vgl && !eigeneAchse) { vgl.punkte.forEach(function (p) { werte.push(p[1]); }); }
    var wb = bereich(werte), min = wb[0], max = wb[1];
    var vb = eigeneAchse ? bereich(vgl.punkte.map(function (p) { return p[1]; })) : null;
    var prognoseBreite = prognosen.length ? Math.round((B - links - rechtsRand) * 0.24) : 0;
    var breite = B - links - rechtsRand - prognoseBreite, hoehe = H - oben - unten;
    var x = function (i) { return links + i / (n - 1) * breite; };
    var y = function (v) { return oben + (1 - (v - min) / (max - min)) * hoehe; };
    var yv = eigeneAchse ? function (v) { return oben + (1 - (v - vb[0]) / (vb[1] - vb[0])) * hoehe; } : y;
    var farbe = o.farbe || (punkte[n - 1][1] >= punkte[0][1] ? '#13814a' : '#c4302b');

    var svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 ' + B + ' ' + H);
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', o.titel || 'Kursverlauf');
    var el = function (tag, attr, eltern) {
      var e = document.createElementNS(NS, tag);
      Object.keys(attr).forEach(function (k) { e.setAttribute(k, attr[k]); });
      (eltern || svg).appendChild(e);
      return e;
    };
    var schrift = function (inhalt, attr, eltern, farbeText) { var t = el('text', attr, eltern); t.textContent = inhalt; if (farbeText) { t.style.fill = farbeText; } return t; };
    var pfadD = function (reihe, yf) {
      var d = '', offen = false;
      reihe.forEach(function (p, i) {
        if (!hat(p[1])) { offen = false; return; }
        d += (offen ? 'L' : 'M') + x(i).toFixed(1) + ' ' + yf(p[1]).toFixed(1);
        offen = true;
      });
      return d;
    };
    var achsZahl = function (v) { return v.toLocaleString('de-DE', { maximumFractionDigits: Math.abs(v) >= 100 ? 0 : 2 }); };
    var gid = 'fz-fl-' + Math.random().toString(36).slice(2, 9);
    el('defs', {}).innerHTML = '<linearGradient id="' + gid + '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' + farbe + '" stop-opacity=".16"/><stop offset="1" stop-color="' + farbe + '" stop-opacity="0"/></linearGradient>';
    for (var g = 0; g <= 3; g++) {
      var gw = min + (max - min) * g / 3, gy = y(gw);
      el('line', { x1: links, x2: B - rechtsRand + 4, y1: gy, y2: gy, 'class': 'gitter' });
      schrift(o.achseFmt ? o.achseFmt(gw, min, max) : achsZahl(gw), { x: B - rechtsRand + 8, y: gy + 4, 'class': 'achse' });
      if (eigeneAchse) {
        var vw = vb[0] + (vb[1] - vb[0]) * g / 3;
        schrift(vgl.achseFmt ? vgl.achseFmt(vw) : achsZahl(vw), { x: links - 6, y: gy + 4, 'class': 'achse', 'text-anchor': 'end' }, null, vgl.farbe);
      }
    }
    if (o.nullLinie && min < 0 && max > 0) { el('line', { x1: links, x2: B - rechtsRand + 4, y1: y(0), y2: y(0), 'class': 'null-linie' }); }
    // Bänder liegen unter allem anderen
    linien.forEach(function (l) {
      if (l.art !== 'band') { return; }
      var idx = [];
      l.oben.forEach(function (p, i) { if (hat(p[1]) && l.unten[i] && hat(l.unten[i][1])) { idx.push(i); } });
      if (idx.length < 2) { return; }
      el('path', { 'class': 'band', fill: l.farbe, d: idx.map(function (i, k) { return (k ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(l.oben[i][1]).toFixed(1); }).join('')
        + idx.slice().reverse().map(function (i) { return 'L' + x(i).toFixed(1) + ' ' + y(l.unten[i][1]).toFixed(1); }).join('') + 'Z' });
      el('path', { d: pfadD(l.oben, y), 'class': 'linie band-rand', stroke: l.farbe });
      el('path', { d: pfadD(l.unten, y), 'class': 'linie band-rand', stroke: l.farbe });
    });
    var dHaupt = pfadD(punkte, y);
    if (!o.ohneFlaeche) {
      el('path', { d: dHaupt + 'L' + x(n - 1).toFixed(1) + ' ' + (H - unten) + 'L' + x(0).toFixed(1) + ' ' + (H - unten) + 'Z', fill: 'url(#' + gid + ')' });
    }
    el('path', { d: dHaupt, 'class': 'linie', stroke: farbe });
    linien.forEach(function (l) {
      if (l.art === 'band' || l.art === 'waagrecht') { return; }
      el('path', { d: pfadD(l.punkte, y), 'class': 'linie zusatz', stroke: l.farbe });
    });
    linien.forEach(function (l) {
      if (l.art !== 'waagrecht') { return; }
      var wy = y(l.wert);
      el('line', { x1: links, x2: x(n - 1), y1: wy, y2: wy, 'class': 'linie waagrecht', stroke: l.farbe });
      schrift(l.text || l.name, { x: links + 4, y: l.unten ? wy + 12 : wy - 4, 'class': 'achse linien-text' }, null, l.farbe);
    });
    if (vgl) { el('path', { d: pfadD(vgl.punkte, yv), 'class': 'linie vergleich', stroke: vgl.farbe }); }
    [0, Math.floor((n - 1) / 2), n - 1].forEach(function (i, k) {
      schrift(datumFmt(punkte[i][0]), { x: x(i), y: H - 5, 'class': 'achse', 'text-anchor': k === 0 ? 'start' : (k === 1 ? 'middle' : 'end') });
    });
    // Prognose: Trichter vom letzten Wert zu den Zielen tief/mittel/hoch in 12 Monaten
    prognosen.forEach(function (pr, nr) {
      var x0 = x(n - 1), y0 = y(punkte[n - 1][1]), x1 = B - rechtsRand;
      var art = pr.art === 'szenario' ? ' szenario' : '';
      var hoch = hat(pr.hoch) ? pr.hoch : pr.mittel, tief = hat(pr.tief) ? pr.tief : pr.mittel;
      el('path', { d: 'M' + x0 + ' ' + y0 + 'L' + x1 + ' ' + y(hoch) + 'L' + x1 + ' ' + y(tief) + 'Z', 'class': 'prognose-flaeche' + art });
      [['hoch', hoch], ['mittel', pr.mittel], ['tief', tief]].forEach(function (zl) {
        el('path', { d: 'M' + x0 + ' ' + y0 + 'L' + x1 + ' ' + y(zl[1]), 'class': 'prognose-linie ' + zl[0] + art });
        el('circle', { cx: x1, cy: y(zl[1]), r: zl[0] === 'mittel' ? 3.5 : 2.5, 'class': 'prognose-punkt' + art });
      });
      if (nr === 0) { schrift('+12 Mon.', { x: x1, y: H - 5, 'class': 'achse', 'text-anchor': 'end' }); }
      schrift((pr.name || 'Ziel') + ' ' + fmt(pr.mittel), { x: x1 - 4, y: y(pr.mittel) + (nr === 0 ? -6 : 14), 'class': 'achse prognose-text' + art, 'text-anchor': 'end' });
    });
    // Kauf ▲ / Verkauf ▼ am ersten Punkt ab dem Geschäftstag
    var jePunkt = {};
    (o.marker || []).forEach(function (m) {
      var i = 0;
      while (i < n - 1 && punkte[i][0] < m.t) { i++; }
      (jePunkt[i] = jePunkt[i] || []).push(m);
    });
    Object.keys(jePunkt).forEach(function (i) {
      var liste = jePunkt[i];
      ['kauf', 'verkauf'].forEach(function (typ) {
        if (!liste.some(function (m) { return m.typ === typ; })) { return; }
        var mx = x(+i), my = y(punkte[i][1]);
        el('path', { d: typ === 'kauf' ? 'M' + mx + ' ' + (my + 6) + 'l-5 9h10z' : 'M' + mx + ' ' + (my - 6) + 'l-5 -9h10z', 'class': 'marke ' + typ });
      });
    });

    // ---- Bedienung: Zeitpunkt anfahren; im Messmodus zwei Punkte A und B setzen ----
    var spanne = el('rect', { 'class': 'chart-spanne', y: oben, height: hoehe, x: 0, width: 0 });
    var cursorLinie = el('line', { 'class': 'chart-cursor', y1: oben, y2: H - unten });
    var cursorPunkt = el('circle', { r: 5, fill: farbe, stroke: '#fff', 'stroke-width': 2 });
    var cursorVgl = vgl ? el('circle', { r: 4, fill: vgl.farbe, stroke: '#fff', 'stroke-width': 2 }) : null;
    var griffBauen = function (name) {
      var gr = el('g', { 'class': 'chart-griff' });
      el('line', { 'class': 'chart-grenze', x1: 0, x2: 0, y1: oben, y2: H - unten }, gr);
      el('circle', { 'class': 'griff-marke', cx: 0, cy: 9, r: 8.5 }, gr);
      schrift(name, { 'class': 'griff-text', x: 0, y: 13, 'text-anchor': 'middle' }, gr);
      return { g: gr, pkt: el('circle', { 'class': 'griff-punkt', cx: 0, r: 4.5, fill: farbe }, gr), pktV: vgl ? el('circle', { 'class': 'griff-punkt', cx: 0, r: 3.5, fill: vgl.farbe }, gr) : null };
    };
    var griffe = { a: griffBauen('A'), b: griffBauen('B') };
    chart.appendChild(svg);

    if (!leiste) { leiste = document.createElement('div'); leiste.className = 'chart-leiste'; chart.parentNode.insertBefore(leiste, chart.nextSibling); }
    var markenIdx = Object.keys(jePunkt).map(Number).sort(function (p, q) { return p - q; });
    var hatMarken = markenIdx.length > 0;
    leiste.innerHTML = '<div class="cl-info" aria-live="polite"></div>'
      + '<div class="cl-knoepfe">'
      + (hatMarken ? '<button type="button" data-a="handel-zurueck" aria-label="Voriger Kauf oder Verkauf">⏮</button>' : '')
      + '<button type="button" data-a="zurueck" aria-label="Einen Punkt zurück">◀</button><button type="button" data-a="vor" aria-label="Einen Punkt vor">▶</button>'
      + (hatMarken ? '<button type="button" data-a="handel-vor" aria-label="Nächster Kauf oder Verkauf">⏭</button>' : '')
      + '<span class="cl-trenner"></span>'
      + '<button type="button" data-a="messen" aria-pressed="false">⟷ Messen</button>'
      + '</div><div class="cl-mess" hidden></div>';
    var info = $('.cl-info', leiste), messEl = $('.cl-mess', leiste), messKnopf = $('[data-a="messen"]', leiste);

    // Zeitpunkt → nächster Punkt (null, wenn außerhalb des Zeitraums)
    var indexZu = function (t) {
      if (!hat(t) || t < punkte[0][0] - 4 * 86400 || t > punkte[n - 1][0] + 4 * 86400) { return null; }
      var lo = 0, hi = n - 1;
      while (hi - lo > 1) { var mitte = (lo + hi) >> 1; if (punkte[mitte][0] <= t) { lo = mitte; } else { hi = mitte; } }
      return Math.abs(punkte[hi][0] - t) < Math.abs(punkte[lo][0] - t) ? hi : lo;
    };
    var ic = indexZu(z.cursor);
    if (ic === null) { ic = n - 1; }
    var ia = indexZu(z.a), ib = indexZu(z.b);
    if (ia === null) { z.a = null; }
    if (ib === null) { z.b = null; }
    if (z.griff !== 'b') { z.griff = 'a'; }

    var wertInfo = function (i) {
      var html = o.hauptInfo ? o.hauptInfo(i) : '<b>' + esc(fmt(punkte[i][1])) + '</b> <span>' + esc(datumFmt(punkte[i][0])) + '</span>';
      if (vgl && vgl.punkte[i] && hat(vgl.punkte[i][1])) { html += '<small style="color:' + vgl.farbe + '">' + esc(vgl.name) + ': ' + esc((vgl.fmt || fmt)(vgl.punkte[i][1])) + '</small>'; }
      linien.forEach(function (l) {
        if (l.art === 'band' || l.art === 'waagrecht' || !l.punkte[i] || !hat(l.punkte[i][1])) { return; }
        html += '<small style="color:' + l.farbe + '">' + esc(l.name) + ': ' + esc(fmt(l.punkte[i][1])) + '</small>';
      });
      (jePunkt[i] || []).slice(0, 6).forEach(function (m) { html += '<small class="' + (m.typ === 'kauf' ? 'plus' : 'minus') + '">' + esc(m.text) + '</small>'; });
      return html;
    };
    var messungStandard = function (i0, i1) {
      var v0 = punkte[i0][1], v1 = punkte[i1][1], dt = punkte[i1][0] - punkte[i0][0], r = v0 ? v1 / v0 - 1 : null, pj = proJahr(r, dt);
      var zeilen = [['Wert', esc(fmt(v0) + ' → ' + fmt(v1)), ''], ['Δ absolut', esc(mitVorzeichen(fmt, v1 - v0)), klasseZahl(v1 - v0)],
        ['Δ %', anteilText(r), klasseZahl(r)], ['Δt', esc(dauerText(dt)), '']];
      if (pj !== null) { zeilen.push(['pro Jahr', anteilText(pj), klasseZahl(pj)]); }
      return zeilen;
    };

    var zeigen = function () {
      var messen = !!z.messen;
      [cursorLinie, cursorPunkt, cursorVgl].forEach(function (e) { if (e) { e.style.display = messen ? 'none' : ''; } });
      if (!messen) {
        var cx = x(ic);
        cursorLinie.setAttribute('x1', cx); cursorLinie.setAttribute('x2', cx);
        cursorPunkt.setAttribute('cx', cx); cursorPunkt.setAttribute('cy', y(punkte[ic][1]));
        if (cursorVgl) {
          var cv = vgl.punkte[ic] && vgl.punkte[ic][1];
          cursorVgl.style.display = hat(cv) ? '' : 'none';
          if (hat(cv)) { cursorVgl.setAttribute('cx', cx); cursorVgl.setAttribute('cy', yv(cv)); }
        }
      }
      ['a', 'b'].forEach(function (k) {
        var i = k === 'a' ? ia : ib, gr = griffe[k], sicht = messen && i !== null;
        gr.g.style.display = sicht ? '' : 'none';
        gr.g.classList.toggle('aktiv', z.griff === k);
        if (!sicht) { return; }
        gr.g.setAttribute('transform', 'translate(' + x(i).toFixed(1) + ' 0)');
        gr.pkt.setAttribute('cy', y(punkte[i][1]));
        if (gr.pktV) {
          var v = vgl.punkte[i] && vgl.punkte[i][1];
          gr.pktV.style.display = hat(v) ? '' : 'none';
          if (hat(v)) { gr.pktV.setAttribute('cy', yv(v)); }
        }
      });
      var beide = messen && ia !== null && ib !== null;
      spanne.style.display = beide ? '' : 'none';
      if (beide) { var sx0 = x(Math.min(ia, ib)), sx1 = x(Math.max(ia, ib)); spanne.setAttribute('x', sx0); spanne.setAttribute('width', Math.max(1, sx1 - sx0)); }
      messKnopf.classList.toggle('aktiv', messen);
      messKnopf.setAttribute('aria-pressed', messen ? 'true' : 'false');
      var welcher = messen ? z.griff : '';
      var aktivIdx = messen ? (welcher === 'a' ? ia : ib) : ic;
      if (messen && aktivIdx === null) { welcher = ia !== null ? 'a' : 'b'; aktivIdx = ia !== null ? ia : ib; }
      info.innerHTML = aktivIdx === null ? '<span>Tippe in den Chart, um Punkt A zu setzen.</span>'
        : (messen ? '<em class="cl-griff-name">' + welcher.toUpperCase() + '</em> ' : '') + wertInfo(aktivIdx);
      messEl.hidden = !messen;
      if (!messen) { return; }
      if (beide) {
        var i0 = Math.min(ia, ib), i1 = Math.max(ia, ib);
        // A/B antippen wählt den Punkt, den ◀ ▶ und ⏮ ⏭ verschieben
        var griffKnopf = function (k) {
          return '<button type="button" data-a="griff-' + k + '" class="cl-griff' + (z.griff === k ? ' aktiv' : '') + '" aria-label="Punkt ' + k.toUpperCase() + ' verschieben" aria-pressed="' + (z.griff === k) + '">' + k.toUpperCase() + '</button>';
        };
        var erster = ia <= ib ? 'a' : 'b';
        messEl.innerHTML = '<div class="cl-mess-kopf">' + griffKnopf(erster) + ' <b>' + esc(datumFmt(punkte[i0][0])) + '</b> <span class="leise">→</span> '
          + griffKnopf(erster === 'a' ? 'b' : 'a') + ' <b>' + esc(datumFmt(punkte[i1][0])) + '</b></div>'
          + '<dl class="cl-mess-werte">' + (o.messung || messungStandard)(i0, i1).map(function (r) { return '<div><dt>' + esc(r[0]) + '</dt><dd class="' + (r[2] || '') + '">' + r[1] + '</dd></div>'; }).join('') + '</dl>'
          + '<div class="cl-mess-knoepfe">' + (o.uebernehmen ? '<button type="button" data-a="uebernehmen">Als Zeitraum übernehmen</button>' : '') + '<button type="button" data-a="loeschen">✕ Punkte löschen</button></div>';
      } else if (ia === null && ib === null) {
        messEl.innerHTML = '<p class="cl-mess-hinweis">Tippe auf den ersten Punkt (A) und dann auf den zweiten (B). Ziehen verschiebt den nächstgelegenen Punkt, ◀ ▶ fahren ihn genau.</p>';
      } else {
        messEl.innerHTML = '<p class="cl-mess-hinweis">Punkt ' + (ia !== null ? 'A' : 'B') + ' steht am ' + esc(datumFmt(punkte[ia !== null ? ia : ib][0])) + ' – jetzt den zweiten Punkt antippen.</p>'
          + '<div class="cl-mess-knoepfe"><button type="button" data-a="loeschen">✕ Löschen</button></div>';
      }
    };

    // Fingerposition → Punkt; in der Nähe eines Kaufs/Verkaufs dort einrasten (14 px Fangbereich)
    var ausPosition = function (clientX) {
      var r = svg.getBoundingClientRect();
      var px = (clientX - r.left) / r.width * B;
      var i = Math.max(0, Math.min(n - 1, Math.round((px - links) / breite * (n - 1))));
      var fang = 14 / (r.width / B) / (breite / Math.max(1, n - 1));
      var naechste = null;
      markenIdx.forEach(function (m) { if (Math.abs(m - i) <= fang && (naechste === null || Math.abs(m - i) < Math.abs(naechste - i))) { naechste = m; } });
      return naechste !== null ? naechste : i;
    };
    var griffSetzen = function (k, i) {
      i = Math.max(0, Math.min(n - 1, i));
      if (k === 'a') { ia = i; z.a = punkte[i][0]; } else { ib = i; z.b = punkte[i][0]; }
      z.griff = k;
      zeigen();
    };
    var cursorSetzen = function (i) { ic = Math.max(0, Math.min(n - 1, i)); z.cursor = punkte[ic][0]; zeigen(); };
    // erst A, dann B, danach der nähere der beiden
    var griffWaehlen = function (i) { return ia === null ? 'a' : (ib === null ? 'b' : (Math.abs(ia - i) <= Math.abs(ib - i) ? 'a' : 'b')); };
    var zug = null;
    svg.addEventListener('pointerdown', function (ev) {
      if (ev.pointerType === 'mouse' && ev.button !== 0) { return; }
      var i = ausPosition(ev.clientX);
      if (ev.pointerType === 'mouse') { try { svg.setPointerCapture(ev.pointerId); } catch (e) { /* egal */ } }
      if (!z.messen) { zug = { id: ev.pointerId, cursor: true }; cursorSetzen(i); return; }
      // Touch: erst beim Loslassen oder seitlichem Ziehen setzen – senkrechtes Wischen scrollt die Seite
      zug = { id: ev.pointerId, x0: ev.clientX, y0: ev.clientY, griff: griffWaehlen(i), bewegt: ev.pointerType === 'mouse' };
      if (zug.bewegt) { griffSetzen(zug.griff, i); }
    });
    svg.addEventListener('pointermove', function (ev) {
      if (zug && zug.id === ev.pointerId) {
        if (zug.cursor) { cursorSetzen(ausPosition(ev.clientX)); return; }
        var dx = Math.abs(ev.clientX - zug.x0);
        if (!zug.bewegt && dx > 6 && dx >= Math.abs(ev.clientY - zug.y0)) { zug.bewegt = true; }
        if (zug.bewegt) { griffSetzen(zug.griff, ausPosition(ev.clientX)); }
      } else if (!zug && ev.pointerType === 'mouse' && !z.messen) {
        cursorSetzen(ausPosition(ev.clientX));
      }
    });
    svg.addEventListener('pointerup', function (ev) {
      if (zug && zug.id === ev.pointerId && !zug.cursor && !zug.bewegt) { griffSetzen(zug.griff, ausPosition(ev.clientX)); }
      zug = null;
    });
    svg.addEventListener('pointercancel', function () { zug = null; });
    svg.addEventListener('contextmenu', function (ev) { ev.preventDefault(); });

    leiste.onclick = function (ev) {
      var k = ev.target.closest('button[data-a]');
      if (!k) { return; }
      var a = k.getAttribute('data-a');
      var ziel = z.messen ? z.griff : '';
      var jetzt = ziel ? (ziel === 'a' ? ia : ib) : ic;
      if (jetzt === null) { jetzt = ic; }
      var bewegen = function (i) { if (ziel) { griffSetzen(ziel, i); } else { cursorSetzen(i); } };
      if (a === 'zurueck') { bewegen(jetzt - 1); }
      if (a === 'vor') { bewegen(jetzt + 1); }
      if (a === 'handel-zurueck') { var v = markenIdx.filter(function (m) { return m < jetzt; }); if (v.length) { bewegen(v[v.length - 1]); } }
      if (a === 'handel-vor') { var nx = markenIdx.filter(function (m) { return m > jetzt; }); if (nx.length) { bewegen(nx[0]); } }
      if (a === 'messen') { z.messen = !z.messen; zeigen(); }
      if (a === 'griff-a' || a === 'griff-b') {
        var welcher = a.slice(-1);
        if ((welcher === 'a' ? ia : ib) === null) { griffSetzen(welcher, ic); } else { z.griff = welcher; zeigen(); }
      }
      if (a === 'loeschen') { ia = ib = null; z.a = z.b = null; z.griff = 'a'; zeigen(); }
      if (a === 'uebernehmen' && ia !== null && ib !== null && o.uebernehmen) {
        var t0 = punkte[Math.min(ia, ib)][0], t1 = punkte[Math.max(ia, ib)][0];
        z.a = z.b = null;
        z.messen = false;
        o.uebernehmen(t0, t1);
      }
    };
    zeigen();
  }

  // ---------- Linien ein- und ausblenden ----------
  function linienZustand(art, standard, frueher) {
    var zst = Object.assign({}, standard), gesp = null;
    try { gesp = JSON.parse(speicher.lesen('fz-linien-' + art) || 'null'); } catch (e) { gesp = null; }
    if (gesp && typeof gesp === 'object') { Object.assign(zst, gesp); } else if (frueher) { Object.assign(zst, frueher()); }
    return zst;
  }
  function linienChips(wurzel, art, defs, zustand, neu) {
    if (!wurzel) { return; }
    var erklaerung = defs.map(function (d) { return d.name + ': ' + (LINIEN_INFO[d.k] || ''); }).join('\n\n');
    wurzel.innerHTML = '<span class="chips-titel">Linien<button type="button" class="info" data-info="' + esc(erklaerung) + '" aria-label="Erklärung der Linien">i</button></span>'
      + defs.map(function (d) {
        var an = !!zustand[d.k];
        return '<button type="button" class="chip' + (an ? ' aktiv' : '') + '" data-linie="' + d.k + '" aria-pressed="' + an + '"><i style="background:' + d.farbe + '"></i>' + esc(d.name) + '</button>';
      }).join('');
    wurzel.onclick = function (e) {
      var k = e.target.closest('[data-linie]');
      if (!k) { return; }
      var name = k.getAttribute('data-linie');
      zustand[name] = !zustand[name];
      k.classList.toggle('aktiv', zustand[name]);
      k.setAttribute('aria-pressed', zustand[name] ? 'true' : 'false');
      speicher.schreiben('fz-linien-' + art, JSON.stringify(zustand));
      neu(name);
    };
  }

  // ---------- Zweiten Verlauf zum Vergleich wählen ----------
  var VERGLEICHE = [{ s: 'EUNL.DE', n: 'MSCI World' }, { s: 'SXR8.DE', n: 'S&P 500' }, { s: '^GDAXI', n: 'DAX' }, { s: 'SXRV.DE', n: 'Nasdaq 100' }, { s: 'SXRT.DE', n: 'Euro Stoxx 50' }];
  function vergleichFeld(wurzel, art, infoText, neu) {
    var schluessel = 'fz-vergleich-' + art;
    var zst = { s: '', n: '', modus: 'normiert' };
    try { Object.assign(zst, JSON.parse(speicher.lesen(schluessel) || '{}') || {}); } catch (e) { /* egal */ }
    var offen = false, statusText = '', statusFehler = false, suchUhr = null, letzteSuche = '';
    var merken = function () { speicher.schreiben(schluessel, JSON.stringify(zst)); };
    var zeichnen = function () {
      var html = '';
      if (zst.s) {
        html += '<div class="vgl-kopf"><span class="vgl-name"><i></i>Vergleich: <b>' + esc(zst.n || zst.s) + '</b></span>'
          + '<span class="vgl-modus" role="group" aria-label="Darstellung">'
          + '<button type="button" data-vgl="normiert"' + (zst.modus !== 'absolut' ? ' class="aktiv"' : '') + '>Normiert %</button>'
          + '<button type="button" data-vgl="absolut"' + (zst.modus === 'absolut' ? ' class="aktiv"' : '') + '>Absolut</button></span>'
          + '<button type="button" class="info" data-info="' + esc(infoText) + '" aria-label="Erklärung zum Vergleich">i</button>'
          + '<span class="vgl-rechts"><button type="button" class="knopf-text klein" data-vgl="oeffnen">ändern</button>'
          + '<button type="button" class="knopf-text klein" data-vgl="aus" aria-label="Vergleich entfernen">✕</button></span></div>';
      } else if (!offen) {
        html += '<button type="button" class="knopf-text" data-vgl="oeffnen">⇄ Mit Index oder Aktie vergleichen</button>';
      }
      if (statusText) { html += '<p class="vgl-status klein ' + (statusFehler ? 'minus' : 'leise') + '">' + esc(statusText) + '</p>'; }
      if (offen) {
        html += '<div class="vgl-panel"><div class="chips klein-chips">'
          + VERGLEICHE.map(function (v) { return '<button type="button" class="chip" data-vgl-wahl="' + esc(v.s) + '" data-vgl-name="' + esc(v.n) + '">' + esc(v.n) + '</button>'; }).join('')
          + '</div><input type="search" placeholder="Index, ETF oder Aktie suchen …" data-vgl-suche aria-label="Vergleichswert suchen" autocomplete="off">'
          + '<ul class="vgl-treffer"></ul><button type="button" class="knopf-text klein" data-vgl="zu">Abbrechen</button></div>';
      }
      wurzel.innerHTML = html;
      var feld = $('[data-vgl-suche]', wurzel);
      if (!feld) { return; }
      feld.addEventListener('input', function () {
        var q = feld.value.trim(), treffer = $('.vgl-treffer', wurzel);
        clearTimeout(suchUhr);
        if (q.length < 2) { treffer.innerHTML = ''; return; }
        suchUhr = setTimeout(function () {
          letzteSuche = q;
          fetch('?api=suche&typ=alle&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : []; })
            .then(function (liste) {
              if (letzteSuche !== q) { return; }
              treffer.innerHTML = (liste || []).map(function (t) {
                return '<li><button type="button" data-vgl-wahl="' + esc(t.symbol) + '" data-vgl-name="' + esc(t.name) + '"><span>' + esc(t.name) + '</span><small>'
                  + esc(t.symbol + (t.art ? ' · ' + t.art : '') + (t.boerse ? ' · ' + t.boerse : '')) + '</small></button></li>';
              }).join('') || '<li class="leer">Nichts gefunden.</li>';
            })
            .catch(function () { /* still */ });
        }, 300);
      });
    };
    var setzen = function (s, name) {
      zst.s = String(s || '').toUpperCase();
      zst.n = name || zst.s;
      offen = false;
      statusText = '';
      merken();
      zeichnen();
      neu(zst, false);
    };
    wurzel.addEventListener('click', function (e) {
      var w = e.target.closest('[data-vgl-wahl]');
      if (w) { setzen(w.getAttribute('data-vgl-wahl'), w.getAttribute('data-vgl-name')); return; }
      var k = e.target.closest('[data-vgl]');
      if (!k) { return; }
      var a = k.getAttribute('data-vgl');
      if (a === 'oeffnen') { offen = true; zeichnen(); }
      if (a === 'zu') { offen = false; zeichnen(); }
      if (a === 'aus') { zst.s = ''; zst.n = ''; offen = false; statusText = ''; merken(); zeichnen(); neu(zst, false); }
      if ((a === 'normiert' || a === 'absolut') && zst.modus !== a) { zst.modus = a; merken(); zeichnen(); neu(zst, true); }
    });
    zeichnen();
    return {
      zustand: function () { return zst; },
      setzen: setzen,
      status: function (text, fehler) { if (text !== statusText || !!fehler !== statusFehler) { statusText = text || ''; statusFehler = !!fehler; zeichnen(); } }
    };
  }

  // ---------- Kurs-Chart der Firma ----------
  var chart = $('[data-chart]');
  var datenEl = $('#chart-daten');
  var kursVergleich = null;
  if (chart && datenEl) {
    var roh = {};
    try { roh = JSON.parse(datenEl.textContent || '{}'); } catch (e) { roh = {}; }
    var fmtKurs = geldFmt(roh.w || '');
    var aendEl = $('.chart-aend');
    var GRENZEN = { '1m': 31, '6m': 183, '1j': 366, '2j': 731, '5j': 100000 };
    var reiheFuer = function (spanne, quelle) { return spanne === '5j' && quelle.j5 && quelle.j5.length > 1 ? quelle.j5 : (quelle.j1 || []); };
    var KURS_LINIEN = [
      { k: 's30', name: 'Ø 30 Tage', farbe: '#d97706', tage: 30 },
      { k: 's50', name: 'Ø 50 Tage', farbe: '#db2777', tage: 50 },
      { k: 's100', name: 'Ø 100 Tage', farbe: '#7c3aed', tage: 100 },
      { k: 's200', name: 'Ø 200 Tage', farbe: '#334155', tage: 200 },
      { k: 'bollinger', name: 'Bollinger-Bänder', farbe: '#94a3b8' },
      { k: 'hochtief', name: '52W Hoch/Tief', farbe: '#a16207' },
      { k: 'trend', name: 'Trendlinie', farbe: '#0f766e' }
    ];
    if (roh.ziel) { KURS_LINIEN.push({ k: 'ziel', name: 'Analysten-Prognose', farbe: '#0e7490' }); }
    if (roh.szenario) { KURS_LINIEN.push({ k: 'szenario', name: 'Meine Szenarien', farbe: '#9333ea' }); }
    if (roh.k && roh.k.length) { KURS_LINIEN.push({ k: 'marken', name: 'Käufe/Verkäufe', farbe: '#13814a' }); }
    var linienAn = linienZustand('kurs', { s30: false, s50: false, s100: false, s200: false, bollinger: false, hochtief: false, trend: false, ziel: false, szenario: true, marken: true }, function () {
      // frühere Einstellungen übernehmen
      return { s30: speicher.lesen('fz-s30') === '1', s100: speicher.lesen('fz-s100') === '1', ziel: speicher.lesen('fz-ziel') === '1',
        szenario: speicher.lesen('fz-szenario') !== '0', marken: speicher.lesen('fz-marken') !== '0' };
    });
    var vglDaten = {};
    var aktiv = speicher.lesen('fz-spanne') || '1j';
    if (!GRENZEN[aktiv]) { aktiv = '1j'; }

    var zeichnen = function () {
      var basis = reiheFuer(aktiv, roh), ab = 0;
      if (basis.length) { var grenze = basis[basis.length - 1][0] - GRENZEN[aktiv] * 86400; while (ab < basis.length - 1 && basis[ab][0] < grenze) { ab++; } }
      var punkte = basis.slice(ab);
      var vz = kursVergleich ? kursVergleich.zustand() : null;
      var vs = vz && vz.s && vz.s !== roh.symbol ? vz.s : '';
      var vd = vs ? vglDaten[vs] : null;
      var vglRoh = vd && (vd.j1 || vd.j5) && punkte.length ? angleichen(punkte, reiheFuer(aktiv, vd)) : null;
      if (vglRoh && !vglRoh.some(function (p) { return p[1] !== null; })) { vglRoh = null; }
      var normiert = !!(vglRoh && vz.modus !== 'absolut');
      // normiert: beide ab dem ersten Tag, an dem es für beide einen Kurs gibt
      var b0 = 0;
      if (normiert) { while (b0 < punkte.length - 1 && vglRoh[b0][1] === null) { b0++; } }
      var bezug = punkte.length ? punkte[b0][1] : 1, bezugV = normiert ? vglRoh[b0][1] : 1;
      var tf = function (v) { return hat(v) ? (normiert ? v / bezug - 1 : v) : null; };
      var tr = function (reihe) { return reihe.map(function (p) { return [p[0], tf(p[1])]; }); };
      var linien = [];
      KURS_LINIEN.forEach(function (d) {
        if (!linienAn[d.k] || !punkte.length) { return; }
        if (d.tage) { linien.push({ name: d.name, farbe: d.farbe, punkte: tr(gleitend(basis, d.tage * 7 / 5).slice(ab)) }); }
        if (d.k === 'bollinger') { var bb = bollinger(basis, 20, 2); linien.push({ art: 'band', name: d.name, farbe: d.farbe, oben: tr(bb.oben.slice(ab)), unten: tr(bb.unten.slice(ab)) }); }
        if (d.k === 'hochtief') {
          var ht = hochTief(roh.j1 && roh.j1.length ? roh.j1 : basis);
          if (ht) {
            linien.push({ art: 'waagrecht', farbe: d.farbe, wert: tf(ht.hoch), text: '52W-Hoch ' + fmtKurs(ht.hoch) });
            linien.push({ art: 'waagrecht', farbe: d.farbe, wert: tf(ht.tief), text: '52W-Tief ' + fmtKurs(ht.tief), unten: true });
          }
        }
        if (d.k === 'trend') { var tl = trendlinie(punkte); if (tl) { linien.push({ name: 'Trend (' + anteilText(tl.proJahr) + ' pro Jahr)', farbe: d.farbe, punkte: tr(tl.punkte) }); } }
      });
      var prognosen = [];
      var prognose = function (q, art, name) { return { mittel: tf(q.mittel), hoch: tf(q.hoch || q.mittel), tief: tf(q.tief || q.mittel), art: art, name: name }; };
      if (linienAn.ziel && roh.ziel) { prognosen.push(prognose(roh.ziel, '', 'Ziel')); }
      if (linienAn.szenario && roh.szenario) { prognosen.push(prognose(roh.szenario, 'szenario', roh.szenario.name || 'Basis')); }
      var marken = [];
      if (linienAn.marken && roh.k && punkte.length) {
        var von = punkte[0][0], bis = punkte[punkte.length - 1][0] + 86400;
        marken = roh.k.filter(function (m) { return m.t >= von && m.t <= bis; });
      }
      if (aendEl) {
        var aend = punkte.length > 1 ? punkte[punkte.length - 1][1] / punkte[0][1] - 1 : null;
        aendEl.textContent = aend === null ? '' : anteilText(aend);
        aendEl.style.color = aend === null ? '' : (aend >= 0 ? '#13814a' : '#c4302b');
      }
      var vglName = vglRoh ? (vz.n || vs) : '';
      var vergleich = vglRoh ? {
        name: vglName, farbe: '#2563eb', gleicheAchse: normiert, fmt: normiert ? prozentFmt : geldFmt(vd.w || ''),
        punkte: normiert ? vglRoh.map(function (p) { return [p[0], p[1] === null ? null : p[1] / bezugV - 1]; }) : vglRoh
      } : null;
      linienChart(chart, {
        punkte: tr(punkte), fmt: normiert ? prozentFmt : fmtKurs, achseFmt: normiert ? prozentAchse : null, datumFmt: datumKurz,
        titel: 'Kursverlauf ' + (roh.name || ''), linien: linien, marker: marken, prognosen: prognosen, vergleich: vergleich,
        nullLinie: normiert, ohneFlaeche: normiert,
        hauptInfo: normiert ? function (i) { return '<b>' + esc(prozentFmt(tf(punkte[i][1]))) + '</b> <span>' + esc(datumKurz(punkte[i][0]) + ' · ' + fmtKurs(punkte[i][1])) + '</span>'; } : null,
        messung: function (i0, i1) {
          var p0 = punkte[i0][1], p1 = punkte[i1][1], dt = punkte[i1][0] - punkte[i0][0], r = p1 / p0 - 1, pj = proJahr(r, dt);
          var zeilen = [['Kurs', esc(fmtKurs(p0) + ' → ' + fmtKurs(p1)), ''], ['Δ absolut', esc(mitVorzeichen(fmtKurs, p1 - p0)), klasseZahl(p1 - p0)],
            ['Δ %', anteilText(r), klasseZahl(r)], ['Δt', esc(dauerText(dt)), '']];
          if (pj !== null) { zeilen.push(['pro Jahr', anteilText(pj), klasseZahl(pj)]); }
          if (vglRoh && hat(vglRoh[i0][1]) && hat(vglRoh[i1][1])) {
            var rv = vglRoh[i1][1] / vglRoh[i0][1] - 1;
            zeilen.push([vglName + ' Δ %', anteilText(rv), klasseZahl(rv)], ['Vorsprung', punkteText(r - rv), klasseZahl(r - rv)]);
          }
          return zeilen;
        }
      });
    };

    var vglLaden = function (s) {
      if (!kursVergleich) { zeichnen(); return; }
      if (s && s === roh.symbol) { kursVergleich.status('Das ist diese Aktie selbst – bitte einen anderen Wert wählen.', true); zeichnen(); return; }
      if (!s) { kursVergleich.status(''); zeichnen(); return; }
      var vorhanden = vglDaten[s];
      if (vorhanden && !vorhanden.nochmal) {
        kursVergleich.status(vorhanden.laedt ? 'Kursverlauf für den Vergleich wird geladen …' : (vorhanden.fehler || ''), !!vorhanden.fehler);
        zeichnen();
        return;
      }
      vglDaten[s] = { laedt: true };
      kursVergleich.status('Kursverlauf für den Vergleich wird geladen …');
      fetch('?api=kursverlauf&s=' + encodeURIComponent(s), { credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
        .then(function (j) { vglDaten[s] = j && ((j.j1 && j.j1.length) || (j.j5 && j.j5.length)) ? j : { fehler: (j && j.fehler) || 'Zu diesem Wert gibt es keinen Kursverlauf.' }; })
        .catch(function () { vglDaten[s] = { fehler: 'Der Kursverlauf konnte nicht geladen werden – bitte später noch einmal.', nochmal: true }; })
        .then(function () {
          if (kursVergleich.zustand().s !== s) { return; }
          kursVergleich.status(vglDaten[s].fehler || '', !!vglDaten[s].fehler);
          zeichnen();
        });
    };
    var vglWurzel = $('[data-vergleich="kurs"]');
    if (vglWurzel) {
      kursVergleich = vergleichFeld(vglWurzel, 'kurs', 'Legt einen zweiten Kursverlauf über den Chart. „Normiert %“ zeigt beide als prozentuale Veränderung ab dem ersten sichtbaren Tag – so sieht man direkt, was besser gelaufen ist. „Absolut“ zeigt beide Kurse in ihrer Währung, den Vergleich mit eigener Achse links. Mit „⟷ Messen“ siehst du für zwei Zeitpunkte, wie sich beide entwickelt haben.', function (zst) { vglLaden(zst.s); });
    }
    linienChips($('[data-linien="kurs"]'), 'kurs', KURS_LINIEN, linienAn, function () { zeichnen(); });
    var knoepfe = $$('[data-spanne]');
    var wechseln = function (s) {
      aktiv = s;
      knoepfe.forEach(function (k) { k.classList.toggle('aktiv', k.getAttribute('data-spanne') === s); });
      speicher.schreiben('fz-spanne', s);
      zeichnen();
    };
    knoepfe.forEach(function (k) { k.addEventListener('click', function () { wechseln(k.getAttribute('data-spanne')); }); });
    wechseln(aktiv);
    if (kursVergleich && kursVergleich.zustand().s) { vglLaden(kursVergleich.zustand().s); }
    var breite = chart.clientWidth;
    window.addEventListener('resize', function () { if (Math.abs(chart.clientWidth - breite) > 20) { breite = chart.clientWidth; zeichnen(); } });
  }
  // ⇄ bei „Vergleichbare Aktien“: in den Kurs-Chart legen
  document.addEventListener('click', function (e) {
    var k = e.target.closest ? e.target.closest('[data-vergleiche]') : null;
    if (!k || !kursVergleich) { return; }
    e.preventDefault();
    kursVergleich.setzen(k.getAttribute('data-vergleiche'), k.getAttribute('data-vergleiche-name'));
    chart.scrollIntoView({ behavior: 'smooth', block: 'center' });
  });

  // ---------- Depot-Verlauf: Zeitraum und Titel frei wählen ----------
  var vb = $('[data-verlauf]');
  if (vb) {
    var vZustand = { zeitraum: '1j', von: '', bis: '', quelle: 'alle', aus: {} };
    try { var gesp = JSON.parse(speicher.lesen('fz-verlauf') || 'null'); if (gesp) { vZustand = Object.assign(vZustand, gesp); } } catch (e) { /* egal */ }
    var vDaten = null, ladeNummer = 0;
    var heute = new Date();
    var iso = function (d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
    var euro = function (z) { return z.toLocaleString('de-DE', { style: 'currency', currency: 'EUR', maximumFractionDigits: Math.abs(z) >= 1000 ? 0 : 2 }); };
    var vorz = function (z) { return hat(z) ? vorzeichen(z) + euro(Math.abs(z)) : '–'; };
    var prozent = function (z) { return anteilText(z, 1); };
    var klasseVon = function (z) { return !hat(z) ? '' : (z > 0.004 ? 'plus' : (z < -0.004 ? 'minus' : '')); };
    var tag = datumKurz;
    var beginn = vb.getAttribute('data-beginn') || iso(new Date(heute.getFullYear() - 1, heute.getMonth(), heute.getDate()));
    var vonFeld = $('[name=von]', vb.parentNode), bisFeld = $('[name=bis]', vb.parentNode);
    var firmaLink = function (symbol) { return '?seite=firma&s=' + encodeURIComponent(symbol); };

    var zeitraumGrenzen = function (z) {
      var b = new Date(heute), v = new Date(heute);
      var monate = { '1m': 1, '3m': 3, '6m': 6, '1j': 12, '3j': 36, '5j': 60 }[z];
      if (monate) { v.setMonth(v.getMonth() - monate); }
      if (z === '1t') {
        // letzter Handelstag davor (am Montag also Freitag)
        v.setDate(v.getDate() - 1);
        while (v.getDay() === 0 || v.getDay() === 6) { v.setDate(v.getDate() - 1); }
      }
      if (z === '1w') { v.setDate(v.getDate() - 7); }
      if (z === 'ytd') { v = new Date(heute.getFullYear(), 0, 1); }
      var vonIso = z === 'max' ? beginn : iso(v);
      if (vonIso < beginn) { vonIso = beginn; }
      return [vonIso, iso(b)];
    };
    var speichern = function () { speicher.schreiben('fz-verlauf', JSON.stringify(vZustand)); };

    var stand = $('[data-verlauf-stand]', vb), diagramm = $('[data-verlauf-chart]', vb), kennz = $('[data-verlauf-kennzahlen]', vb);
    var liste = $('[data-verlauf-liste]'), sucheFeld = $('[data-verlauf-suche]'), titelEl = $('[data-verlauf-titel]', vb);

    var VERLAUF_LINIEN = [
      { k: 's30', name: 'Ø 30 Tage', farbe: '#d97706', tage: 30 },
      { k: 's50', name: 'Ø 50 Tage', farbe: '#db2777', tage: 50 },
      { k: 's100', name: 'Ø 100 Tage', farbe: '#7c3aed', tage: 100 },
      { k: 's200', name: 'Ø 200 Tage', farbe: '#334155', tage: 200 },
      { k: 'einsatz', name: 'Eingesetzt', farbe: '#64748b' },
      { k: 'marken', name: 'Käufe/Verkäufe', farbe: '#13814a' }
    ];
    var vLinien = linienZustand('verlauf', { s30: false, s50: false, s100: false, s200: false, einsatz: true, marken: true }, function () {
      return { s30: !!vZustand.s30, s100: !!vZustand.s100, einsatz: vZustand.einsatz !== false };
    });
    // Ø 200 Tage braucht einen längeren Vorlauf vor dem sichtbaren Zeitraum
    var vorlaufTage = function () { return vLinien.s200 ? 300 : 100; };
    var linienNeu = function () { if (vDaten && (vDaten.vorlaufTage || 0) < vorlaufTage()) { laden(); } else { zeigen(); } };

    var INFO = {
      gewinn: 'Wert am Ende minus Wert am Anfang minus (Käufe − Verkäufe) plus Dividenden. Die Prozentzahl bezieht den Gewinn auf das im Zeitraum durchschnittlich eingesetzte Kapital.',
      fluss: 'Was du im Zeitraum netto investiert hast: Käufe minus Verkaufserlöse. Negativ heißt: Du hast mehr herausgenommen als eingezahlt.',
      twr: 'Zeitgewichtete Rendite: Für jeden Tag wird die Wertänderung ohne Käufe und Verkäufe berechnet und alles miteinander verkettet – so rechnen auch Fonds. Wann und wie viel du gekauft hast, spielt keine Rolle; deshalb ist diese Zahl fair mit einem Index vergleichbar. Dividenden zählen als Ertrag.',
      vergleich: 'Kursentwicklung des Vergleichswerts im selben Zeitraum, in Euro umgerechnet. Vorsprung = zeitgewichtete Rendite deines Depots minus Rendite des Vergleichswerts.',
      sim: 'Was wäre, wenn du dasselbe Geld zu denselben Zeitpunkten in den Vergleichswert gesteckt hättest (Käufe als Einzahlung, Verkäufe als Entnahme)? Gezeigt wird der Gewinn dieses Musterdepots und der Unterschied zu deinem Gewinn.'
    };
    var vVergleich = null;
    var vglWurzelV = $('[data-vergleich="verlauf"]');
    if (vglWurzelV) {
      vVergleich = vergleichFeld(vglWurzelV, 'verlauf', 'Vergleicht dein Depot mit einem Index, ETF oder einer Aktie (in Euro umgerechnet). „Normiert %“: zeitgewichtete Rendite deines Depots gegen die Kursentwicklung des Vergleichswerts – Käufe und Verkäufe verfälschen den Vergleich so nicht. „Absolut“: was aus deinem Geld geworden wäre, wenn du dieselben Beträge zu denselben Zeitpunkten in den Vergleichswert gesteckt hättest.', function (zst, nurModus) {
        // nur neu laden, wenn ein anderer Vergleichswert gebraucht wird
        if (nurModus || !zst.s || (vDaten && zst.s === vDaten.vergleichSymbol)) { zeigen(); } else { laden(); }
      });
    }

    // Kennzahlen einer Auswahl: Gewinn = Endwert − Anfangswert − Nettokäufe + Dividenden; Rendite auf das durchschnittlich eingesetzte Kapital
    var auswerten = function (titel) {
      var p = vDaten.punkte, n = p.length, s0 = vDaten.start || 0, summe = new Array(n).fill(0), fluesse = new Array(n).fill(0), fluss = 0, div = 0;
      titel.forEach(function (t) {
        for (var i = 0; i < n; i++) {
          summe[i] += t.w[i];
          if (i > s0 && t.f[i]) { fluss += t.f[i]; fluesse[i] += t.f[i]; }
        }
        div += t.div;
      });
      var v0 = summe[s0], v1 = summe[n - 1];
      var gewinn = v1 - v0 - fluss + div;
      // gemittelt nur über die Zeit, in der Kapital gebunden war (Haltedauer)
      var eingesetzt = v0, kapital = 0, gewicht = 0;
      for (var j = s0 + 1; j < n; j++) {
        var dt = p[j] - p[j - 1];
        if (eingesetzt > 0.005 || summe[j - 1] > 0.005) { kapital += Math.max(eingesetzt, summe[j - 1], 0) * dt; gewicht += dt; }
        eingesetzt += fluesse[j];
      }
      var basis = gewicht > 0 ? kapital / gewicht : Math.max(v0, eingesetzt);
      return { summe: summe, v0: v0, v1: v1, fluss: fluss, div: div, gewinn: gewinn, rendite: basis > 1 ? gewinn / basis : null };
    };
    // Zeitgewichtete Rendite als Index (Start = 1): je Abschnitt die Wertänderung ohne Käufe/Verkäufe, Dividenden als Ertrag
    var zeitgewichtet = function (summe, fl, dvP, s0) {
      var n = summe.length, idx = new Array(n).fill(null);
      idx[s0] = 1;
      for (var i = s0 + 1; i < n; i++) {
        var v0 = summe[i - 1], f = fl[i], r = 0;
        var nenner = v0 > 1 ? v0 + Math.max(f, 0) / 2 : (f > 1 ? f : 0);
        if (nenner > 1) { r = (summe[i] - v0 - f + dvP[i]) / nenner; }
        if (!isFinite(r) || r < -0.95 || r > 10) { r = 0; }
        idx[i] = idx[i - 1] * (1 + r);
      }
      return idx;
    };
    // Musterdepot: dieselben Zahlungen zu denselben Zeitpunkten im Vergleichswert (Kurse P in Euro), ab Punkt ab
    var gleicheZahlungen = function (summe, fl, P, ab, bis) {
      var werte = new Array(summe.length).fill(null), preis = P[ab], stueck = summe[ab] / preis;
      werte[ab] = summe[ab];
      for (var i = ab + 1; i <= bis; i++) {
        if (P[i] > 0) { preis = P[i]; }
        stueck += fl[i] / preis;
        werte[i] = stueck * preis;
      }
      return werte;
    };
    var sichtbar = function () {
      return vDaten.titel.filter(function (t) { return vZustand.quelle === 'alle' || t.quellen.indexOf(vZustand.quelle) >= 0; });
    };
    var gewaehlt = function () { return sichtbar().filter(function (t) { return !vZustand.aus[t.id]; }); };

    var nurDiesen = function (id) {
      vZustand.aus = {};
      vDaten.titel.forEach(function (x) { if (x.id !== id) { vZustand.aus[x.id] = 1; } });
      speichern(); zeigen();
    };
    var kachel = function (titel, wert, unter, erklaerung) {
      return '<div class="kachel"><span class="k-titel">' + esc(titel) + (erklaerung ? '<button type="button" class="info" data-info="' + esc(erklaerung) + '" aria-label="Erklärung anzeigen">i</button>' : '') + '</span>'
        + '<span class="k-wert">' + wert + '</span>' + (unter ? '<span class="k-unter">' + unter + '</span>' : '') + '</div>';
    };
    var farbig = function (z, text) { return '<span class="' + klasseVon(z) + '">' + text + '</span>'; };

    var zeigen = function () {
      if (!vDaten) { return; }
      var alle = sichtbar(), auswahl = gewaehlt();
      var k = auswerten(auswahl);
      var s0 = vDaten.start || 0, zeiten = vDaten.punkte, n = zeiten.length;
      // Zahlungen (Käufe − Verkäufe) und Dividenden der Auswahl je Rasterpunkt
      var fl = new Array(n).fill(0), dvP = new Array(n).fill(0);
      auswahl.forEach(function (t) {
        for (var i = 1; i < n; i++) { if (t.f[i]) { fl[i] += t.f[i]; } }
        (t.dv || []).forEach(function (x) { if (x[0] < n) { dvP[x[0]] += x[1]; } });
      });
      var twr = zeitgewichtet(k.summe, fl, dvP, s0);
      // Vergleichswert in Euro je Rasterpunkt
      var vz = vVergleich ? vVergleich.zustand() : null;
      var vgl = vDaten.vergleich && !vDaten.vergleich.fehler && vz && vz.s === vDaten.vergleich.symbol ? vDaten.vergleich : null;
      var P = vgl ? vgl.eur : null, j0 = s0;
      if (P) { while (j0 < n && !(P[j0] > 0)) { j0++; } if (j0 >= n - 1) { P = null; } }
      var vName = P ? (vz.n || vgl.symbol) : '';
      var normiert = !!(P && vz.modus !== 'absolut');
      // Abschnitt A..Z: Gewinn, zeitgewichtete Rendite, Vergleich, Musterdepot
      var abschnitt = function (A, Z) {
        var e = { v0: k.summe[A], v1: k.summe[Z], fluss: 0, div: 0, dt: zeiten[Z] - zeiten[A], rv: null, sim: null };
        for (var q = A + 1; q <= Z; q++) { e.fluss += fl[q]; e.div += dvP[q]; }
        e.gewinn = e.v1 - e.v0 - e.fluss + e.div;
        e.rz = twr[A] && hat(twr[Z]) ? twr[Z] / twr[A] - 1 : null;
        if (P && P[A] > 0 && P[Z] > 0) {
          e.rv = P[Z] / P[A] - 1;
          e.sim = gleicheZahlungen(k.summe, fl, P, A, Z)[Z] - e.v0 - e.fluss;
        }
        return e;
      };

      var alleP = zeiten.map(function (t, i) { return [t, Math.round(k.summe[i] * 100) / 100]; });
      var punkte, vergleich = null, b0 = s0;
      if (normiert) {
        b0 = Math.max(s0, j0);
        punkte = zeiten.slice(s0).map(function (t, q) { return [t, twr[s0 + q] / twr[b0] - 1]; });
        vergleich = { name: vName, farbe: '#2563eb', gleicheAchse: true, fmt: prozentFmt,
          punkte: zeiten.slice(s0).map(function (t, q) { var p = P[s0 + q]; return [t, p > 0 ? p / P[b0] - 1 : null]; }) };
      } else {
        punkte = alleP.slice(s0);
        if (P) {
          var sim = gleicheZahlungen(k.summe, fl, P, j0, n - 1);
          vergleich = { name: 'Gleiche Zahlungen in ' + vName, farbe: '#2563eb', gleicheAchse: true, fmt: euro,
            punkte: zeiten.slice(s0).map(function (t, q) { var v = sim[s0 + q]; return [t, v === null ? null : Math.round(v * 100) / 100]; }) };
        }
      }
      var linien = [];
      VERLAUF_LINIEN.forEach(function (d) {
        if (!vLinien[d.k] || !d.tage) { return; }
        linien.push({ name: d.name, farbe: d.farbe, punkte: normiert ? gleitend(punkte, d.tage * 7 / 5) : gleitend(alleP, d.tage * 7 / 5).slice(s0) });
      });
      if (vLinien.einsatz && !normiert) {
        // Wert am Anfang plus Käufe minus Verkäufe: zeigt, was davon eingezahlt und was Kursentwicklung ist
        var eingesetzt = [], summeF = k.v0;
        for (var ei = s0; ei < n; ei++) {
          if (ei > s0) { summeF += fl[ei]; }
          eingesetzt.push([zeiten[ei], Math.round(summeF * 100) / 100]);
        }
        linien.push({ name: 'Eingesetzt', farbe: '#64748b', punkte: eingesetzt });
      }
      // Käufe und Verkäufe der Auswahl (bei bis zu 10 Titeln, sonst wird es unübersichtlich)
      var geschaefte = [];
      if (auswahl.length <= 10) {
        auswahl.forEach(function (t) {
          (t.k || []).forEach(function (g) {
            if (g[3] === 'massnahme') { return; }
            geschaefte.push({ t: g[0], typ: g[3], titel: t.name, symbol: t.symbol, stueck: g[1], betrag: g[2], quelle: g[4],
              text: (g[3] === 'kauf' ? '▲ Kauf ' : '▼ Verkauf ') + (auswahl.length > 1 ? t.name + ' ' : '') + euro(Math.abs(g[2])) });
          });
        });
      }
      geschaefte.sort(function (a, b) { return a.t - b.t; });
      if (titelEl) {
        titelEl.textContent = normiert ? 'Zeitgewichtete Rendite gegen ' + vName : (P ? 'Wert der Auswahl gegen gleiche Zahlungen in ' + vName : 'Wert der Auswahl');
      }
      linienChips($('[data-linien="verlauf"]'), 'verlauf', VERLAUF_LINIEN.filter(function (d) { return !(normiert && d.k === 'einsatz'); }), vLinien, linienNeu);
      linienChart(diagramm, {
        punkte: punkte, fmt: normiert ? prozentFmt : euro, achseFmt: normiert ? prozentAchse : null, datumFmt: tag,
        titel: normiert ? 'Zeitgewichtete Rendite' : 'Depotwert', leer: 'In diesem Zeitraum waren keine Titel im Depot.',
        linien: linien, marker: vLinien.marken ? geschaefte : [], vergleich: vergleich, nullLinie: normiert, ohneFlaeche: normiert,
        hauptInfo: normiert ? function (q) { return '<b>' + esc(prozentFmt(punkte[q][1])) + '</b> <span>' + esc(tag(punkte[q][0]) + ' · Wert ' + euro(k.summe[s0 + q])) + '</span>'; } : null,
        messung: function (a, b) {
          var e = abschnitt(s0 + a, s0 + b), pj = proJahr(e.rz, e.dt);
          var zeilen = [['Wert', euro(e.v0) + ' → ' + euro(e.v1), ''], ['Käufe − Verkäufe', vorz(e.fluss), '']];
          if (e.div) { zeilen.push(['Dividenden', euro(e.div), '']); }
          zeilen.push(['Gewinn', vorz(e.gewinn), klasseVon(e.gewinn)], ['Rendite (zeitgewichtet)', prozent(e.rz), klasseVon(e.rz)], ['Δt', esc(dauerText(e.dt)), '']);
          if (pj !== null) { zeilen.push(['pro Jahr', prozent(pj), klasseVon(pj)]); }
          if (e.rv !== null) {
            zeilen.push([vName, prozent(e.rv), klasseVon(e.rv)]);
            if (e.rz !== null) { zeilen.push(['Vorsprung', punkteText(e.rz - e.rv), klasseVon(e.rz - e.rv)]); }
            zeilen.push(['Gleiche Zahlungen in ' + vName, vorz(e.sim), klasseVon(e.sim)], ['Unterschied zu dir', vorz(e.gewinn - e.sim), klasseVon(e.gewinn - e.sim)]);
          }
          return zeilen;
        },
        uebernehmen: function (t0, t1) {
          vZustand.zeitraum = 'frei'; vZustand.von = iso(new Date(t0 * 1000)); vZustand.bis = iso(new Date(t1 * 1000));
          speichern(); laden();
        }
      });
      var gl = $('[data-verlauf-geschaefte]');
      if (gl) {
        if (auswahl.length > 10) {
          gl.innerHTML = '<p class="klein leise">Käufe und Verkäufe werden angezeigt, sobald höchstens 10 Titel ausgewählt sind.</p>';
        } else if (!geschaefte.length) {
          gl.innerHTML = '<p class="klein leise">Keine Käufe oder Verkäufe im Zeitraum.</p>';
        } else {
          var html = '<h4>Käufe und Verkäufe im Zeitraum (' + geschaefte.length + ')</h4><ul class="liste-schlicht geschaefte">';
          geschaefte.slice().reverse().slice(0, 200).forEach(function (g) {
            html += '<li><span><b class="' + (g.typ === 'kauf' ? 'plus' : 'minus') + '">' + (g.typ === 'kauf' ? '▲ Kauf' : '▼ Verkauf') + '</b> ' + tag(g.t)
              + (auswahl.length > 1 ? ' · <a class="firmenlink" href="' + esc(firmaLink(g.symbol)) + '">' + esc(g.titel) + '</a>' : '') + '<small class="leise" style="display:block">'
              + Math.abs(g.stueck).toLocaleString('de-DE', { maximumFractionDigits: 4 }) + ' Stück · ' + (g.quelle === 'etoro' ? 'eToro' : 'Trade Republic') + '</small></span><b>' + euro(Math.abs(g.betrag)) + '</b></li>';
          });
          gl.innerHTML = html + '</ul>';
        }
      }
      var ges = abschnitt(s0, n - 1), pjGes = proJahr(ges.rz, ges.dt);
      var kacheln = kachel('Wert ' + tag(zeiten[s0]), euro(k.v0)) + kachel('Wert ' + tag(zeiten[n - 1]), euro(k.v1))
        + kachel('Gewinn im Zeitraum', farbig(k.gewinn, vorz(k.gewinn)), farbig(k.rendite, prozent(k.rendite)) + ' aufs Ø eingesetzte Kapital', INFO.gewinn)
        + kachel('Käufe − Verkäufe', vorz(k.fluss), k.div ? 'Dividenden ' + euro(k.div) : '', INFO.fluss)
        + kachel('Zeitgewichtete Rendite', farbig(ges.rz, prozent(ges.rz)), pjGes !== null ? farbig(pjGes, prozent(pjGes)) + ' pro Jahr' : '', INFO.twr);
      if (P) {
        var ev = abschnitt(j0, n - 1), seit = j0 > s0 ? ' (ab ' + tag(zeiten[j0]) + ')' : '';
        kacheln += kachel(vName + seit, farbig(ev.rv, prozent(ev.rv)), ev.rz !== null ? 'Vorsprung ' + farbig(ev.rz - ev.rv, punkteText(ev.rz - ev.rv)) : '', INFO.vergleich)
          + kachel('Gleiche Zahlungen in ' + vName, farbig(ev.sim, vorz(ev.sim)), 'Unterschied zu dir ' + farbig(ev.gewinn - ev.sim, vorz(ev.gewinn - ev.sim)), INFO.sim);
      }
      kennz.innerHTML = kacheln;
      stand.textContent = (auswahl.length === 1 ? auswahl[0].name + ' – ' : '') + auswahl.length + ' von ' + alle.length + ' Titeln ausgewählt · ' + tag(zeiten[s0]) + ' bis ' + tag(zeiten[n - 1]);
      var q = (sucheFeld && sucheFeld.value || '').toLowerCase();
      liste.innerHTML = '';
      var passt = function (t) { return !q || (t.name + ' ' + t.symbol).toLowerCase().indexOf(q) >= 0; };
      var ueberschrift = function (text) { var li = document.createElement('li'); li.className = 'gruppe'; li.textContent = text; liste.appendChild(li); };
      var zeile = function (t) {
        var e = auswerten([t]);
        var li = document.createElement('li');
        li.innerHTML = '<label><input type="checkbox"' + (vZustand.aus[t.id] ? '' : ' checked') + '><span><strong></strong><small></small></span></label>'
          + '<span class="rechts"><b>' + euro(e.v1) + '</b><small class="' + klasseVon(e.gewinn) + '">' + vorz(e.gewinn) + ' (' + prozent(e.rendite) + ')</small></span>'
          + '<span class="vl-knoepfe"><button type="button" class="knopf-text klein" title="Nur diesen Titel zeigen">nur</button>'
          + '<a class="knopf-text klein" href="' + esc(firmaLink(t.symbol)) + '" title="Firma öffnen" aria-label="' + esc(t.name) + ' öffnen">↗</a></span>';
        $('strong', li).textContent = t.name;
        $('small', li).textContent = t.symbol + ' · ' + t.quellen.map(function (x) { return x === 'etoro' ? 'eToro' : 'Trade Republic'; }).join(' + ') + (t.luecke ? ' · Kurse lückenhaft' : '');
        $('input', li).addEventListener('change', function (ev) { if (ev.target.checked) { delete vZustand.aus[t.id]; } else { vZustand.aus[t.id] = 1; } speichern(); zeigen(); });
        $('button', li).addEventListener('click', function () { nurDiesen(t.id); vb.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
        liste.appendChild(li);
      };
      var letzter = n - 1;
      // Sortieren: fehlende Werte immer ans Ende
      var feld = vZustand.sort || 'wert', richtung = vZustand.richtung || (feld === 'name' ? 'auf' : 'ab');
      var wertVon = {};
      alle.forEach(function (t) {
        var e = feld === 'gewinn' || feld === 'rendite' ? auswerten([t]) : null;
        wertVon[t.id] = feld === 'name' ? t.name.toLowerCase() : feld === 'pot' ? t.pot : feld === 'gewinn' ? e.gewinn : feld === 'rendite' ? e.rendite : t.w[letzter];
      });
      var sortiere = function (l) {
        return l.sort(function (a, b) {
          var x = wertVon[a.id], y = wertVon[b.id];
          if (x === null || x === undefined || y === null || y === undefined) { return (x === null || x === undefined) - (y === null || y === undefined); }
          var c = feld === 'name' ? x.localeCompare(y, 'de') : x - y;
          return richtung === 'auf' ? c : -c;
        });
      };
      var sortWahl = $('[data-verlauf-sort]'), sortKnopf = $('[data-verlauf-richtung]');
      if (sortWahl) { sortWahl.value = feld; sortKnopf.textContent = richtung === 'auf' ? '↑' : '↓'; }
      var aktuell = sortiere(alle.filter(function (t) { return t.w[letzter] > 0.005 && passt(t); }));
      var verkauft = sortiere(alle.filter(function (t) { return !(t.w[letzter] > 0.005) && passt(t); }));
      if (aktuell.length) { ueberschrift('Aktuell im Depot (' + aktuell.length + ')'); aktuell.forEach(zeile); }
      if (verkauft.length) { ueberschrift('Im Zeitraum verkauft (' + verkauft.length + ')'); verkauft.forEach(zeile); }
      // Ehemalige Titel außerhalb des Zeitraums: Tippen springt in die Haltedauer
      var drin = {};
      vDaten.titel.forEach(function (t) { drin[t.id] = 1; });
      var frueher = (vDaten.katalog || []).filter(function (t) { return !drin[t.id] && passt(t) && (vZustand.quelle === 'alle' || t.quellen.indexOf(vZustand.quelle) >= 0); });
      if (frueher.length) {
        ueberschrift('Ehemalige Aktien außerhalb des Zeitraums (' + frueher.length + ')');
        frueher.forEach(function (t) {
          var li = document.createElement('li');
          li.innerHTML = '<span><strong><a class="firmenlink" href="' + esc(firmaLink(t.symbol)) + '"></a></strong><small></small></span><button type="button" class="knopf-text klein">anzeigen</button>';
          $('a', li).textContent = t.name;
          $('small', li).textContent = t.symbol + ' · gehalten ' + tag(t.erster) + ' – ' + (t.aktuell ? 'heute' : tag(t.letzter));
          $('button', li).addEventListener('click', function () {
            var v = new Date((t.erster - 14 * 86400) * 1000), b = new Date(Math.min(Date.now(), (t.aktuell ? Date.now() / 1000 : t.letzter + 14 * 86400) * 1000));
            vZustand.zeitraum = 'frei'; vZustand.von = iso(v) < beginn ? beginn : iso(v); vZustand.bis = iso(b); vZustand.nur = t.id;
            speichern(); laden(); vb.scrollIntoView({ behavior: 'smooth', block: 'start' });
          });
          liste.appendChild(li);
        });
      }
      if (!liste.children.length) { liste.innerHTML = '<li class="leer">Keine Titel gefunden.</li>'; }
    };

    var laden = function () {
      var g = vZustand.zeitraum === 'frei' ? [vZustand.von, vZustand.bis] : zeitraumGrenzen(vZustand.zeitraum);
      vonFeld.value = g[0]; bisFeld.value = g[1];
      $$('[data-zeitraum]').forEach(function (k) { k.classList.toggle('aktiv', k.getAttribute('data-zeitraum') === vZustand.zeitraum); });
      $$('[data-quelle]').forEach(function (k) { k.classList.toggle('aktiv', k.getAttribute('data-quelle') === vZustand.quelle); });
      diagramm.innerHTML = '<p class="leer"><span class="kreisel"></span> Kurse werden geladen …</p>';
      kennz.innerHTML = ''; stand.textContent = ''; vDaten = null;
      // Messmodus und Messpunkte bleiben stehen, sofern sie im neuen Zeitraum liegen
      var bisher = diagramm._zustand || {};
      diagramm._zustand = { messen: bisher.messen, a: bisher.a, b: bisher.b, griff: bisher.griff };
      var vergleichSymbol = vVergleich ? (vVergleich.zustand().s || '') : '';
      var vorlauf = vorlaufTage();
      var versuche = 0, nummer = ++ladeNummer;
      var schritt = function () {
        var daten = new FormData();
        daten.append('csrf', csrf); daten.append('von', g[0]); daten.append('bis', g[1]); daten.append('vorlauf', String(vorlauf)); daten.append('vergleich', vergleichSymbol);
        fetch('?api=verlauf', { method: 'POST', body: daten, credentials: 'same-origin' })
          .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
          .then(function (j) {
            if (nummer !== ladeNummer) { return; } // inzwischen anderer Zeitraum gewählt
            versuche = 0;
            if (!j.fertig) {
              var proz = j.gesamt ? Math.round((1 - j.offen / j.gesamt) * 100) : 0;
              diagramm.innerHTML = '<p class="leer"><span class="kreisel"></span> Historische Kurse werden geladen … ' + Math.max(0, proz) + ' %</p>';
              schritt();
              return;
            }
            j.vorlaufTage = vorlauf;
            j.vergleichSymbol = vergleichSymbol;
            vDaten = j;
            if (vVergleich) { vVergleich.status(j.vergleich && j.vergleich.fehler ? j.vergleich.fehler : '', true); }
            if (vZustand.titelSprung) {
              var ziel = (j.katalog || []).filter(function (t) { return t.id === vZustand.titelSprung; })[0];
              delete vZustand.titelSprung;
              if (ziel) {
                var vonD = new Date((ziel.erster - 14 * 86400) * 1000), bisD = new Date(ziel.aktuell ? Date.now() : Math.min(Date.now(), (ziel.letzter + 14 * 86400) * 1000));
                vZustand.zeitraum = 'frei'; vZustand.von = iso(vonD) < beginn ? beginn : iso(vonD); vZustand.bis = iso(bisD); vZustand.nur = ziel.id;
                speichern(); history.replaceState(null, '', '?seite=verlauf'); laden(); return;
              }
            }
            if (vZustand.nur) { var nur = vZustand.nur; delete vZustand.nur; if (j.titel.some(function (t) { return t.id === nur; })) { vZustand.aus = {}; j.titel.forEach(function (t) { if (t.id !== nur) { vZustand.aus[t.id] = 1; } }); speichern(); } }
            if (!j.titel.length) { diagramm.innerHTML = '<p class="leer">In diesem Zeitraum waren keine Titel im Depot.</p>'; kennz.innerHTML = ''; liste.innerHTML = ''; stand.textContent = ''; return; }
            zeigen();
          })
          .catch(function () {
            if (nummer !== ladeNummer) { return; }
            if (++versuche < 4) { setTimeout(schritt, 3000); return; }
            diagramm.innerHTML = '<p class="leer">Der Verlauf konnte nicht geladen werden. Bitte die Seite neu laden.</p>';
          });
      };
      schritt();
    };

    $$('[data-zeitraum]').forEach(function (k) {
      k.addEventListener('click', function () { vZustand.zeitraum = k.getAttribute('data-zeitraum'); speichern(); laden(); });
    });
    $$('[data-quelle]').forEach(function (k) {
      k.addEventListener('click', function () { vZustand.quelle = k.getAttribute('data-quelle'); speichern(); $$('[data-quelle]').forEach(function (x) { x.classList.toggle('aktiv', x === k); }); zeigen(); });
    });
    var freiForm = $('[data-verlauf-frei]');
    if (freiForm) {
      freiForm.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!vonFeld.value || !bisFeld.value || vonFeld.value > bisFeld.value) { return; }
        vZustand.zeitraum = 'frei'; vZustand.von = vonFeld.value; vZustand.bis = bisFeld.value; speichern(); laden();
      });
    }
    $$('[data-verlauf-alle]').forEach(function (k) {
      k.addEventListener('click', function () {
        var an = k.getAttribute('data-verlauf-alle') === '1';
        sichtbar().forEach(function (t) { if (an) { delete vZustand.aus[t.id]; } else { vZustand.aus[t.id] = 1; } });
        speichern(); zeigen();
      });
    });
    if (sucheFeld) { sucheFeld.addEventListener('input', zeigen); }
    var vSortWahl = $('[data-verlauf-sort]'), vSortKnopf = $('[data-verlauf-richtung]');
    if (vSortWahl) {
      vSortWahl.addEventListener('change', function () { vZustand.sort = vSortWahl.value; vZustand.richtung = vSortWahl.value === 'name' ? 'auf' : 'ab'; speichern(); zeigen(); });
      vSortKnopf.addEventListener('click', function () { var f = vZustand.sort || 'wert'; var r = vZustand.richtung || (f === 'name' ? 'auf' : 'ab'); vZustand.richtung = r === 'auf' ? 'ab' : 'auf'; speichern(); zeigen(); });
    }
    // Aufruf von der Firmenseite (?titel=…): Haltedauer dieses Titels zeigen
    var titelParam = new URLSearchParams(location.search).get('titel');
    if (titelParam) { vZustand.titelSprung = titelParam; }
    var vBreite = diagramm.clientWidth;
    window.addEventListener('resize', function () { if (Math.abs(diagramm.clientWidth - vBreite) > 20) { vBreite = diagramm.clientWidth; zeigen(); } });
    linienChips($('[data-linien="verlauf"]'), 'verlauf', VERLAUF_LINIEN, vLinien, linienNeu);
    laden();
  }
})();
