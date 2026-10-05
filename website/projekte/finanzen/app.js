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

  // ---------- KI-Einschätzung ----------
  $$('[data-ki]').forEach(function (bereich) {
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
      fetch('?api=ki', { method: 'POST', body: daten, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          clearInterval(uhr);
          if (j && j.ok) {
            status.textContent = 'Fertig – wird angezeigt …';
            location.hash = 'f-ki';
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

  // ---------- Liniendiagramm (Kurs und Depotverlauf) ----------
  // Gleitender Durchschnitt über die letzten „tage“ Kalendertage (je Punkt [Zeit, Wert])
  function gleitend(punkte, tage) {
    var erg = [], summe = 0, anfang = 0;
    for (var i = 0; i < punkte.length; i++) {
      summe += punkte[i][1];
      while (punkte[anfang][0] <= punkte[i][0] - tage * 86400) { summe -= punkte[anfang][1]; anfang++; }
      // erst zeigen, wenn der Zeitraum annähernd gefüllt ist
      erg.push(punkte[i][0] - punkte[0][0] >= tage * 86400 * 0.9 ? [punkte[i][0], summe / (i - anfang + 1)] : [punkte[i][0], null]);
    }
    return erg;
  }

  function linienChart(chart, punkte, fmt, datumFmt, aendEl, zusatz, marker, prognose) {
    marker = marker || [];
    zusatz = (zusatz || []).filter(function (z) { return z.punkte.some(function (p) { return p[1] !== null; }); });
    chart.innerHTML = '';
    if (!punkte || punkte.length < 2) { chart.innerHTML = '<p class="leer">Kein Kursverlauf verfügbar.</p>'; if (aendEl) { aendEl.textContent = ''; } return; }
    var B = chart.clientWidth || 600, H = chart.clientHeight || 210, oben = 10, unten = 22, links = 0, rechtsRand = 52;
    var werte = punkte.map(function (p) { return p[1]; });
    var alleWerte = werte.slice();
    zusatz.forEach(function (z) { z.punkte.forEach(function (p) { if (p[1] !== null) { alleWerte.push(p[1]); } }); });
    if (prognose) { ['hoch', 'mittel', 'tief'].forEach(function (k) { if (prognose[k]) { alleWerte.push(prognose[k]); } }); }
    var min = Math.min.apply(null, alleWerte), max = Math.max.apply(null, alleWerte);
    if (max === min) { max += 1; min -= 1; }
    var puffer = (max - min) * 0.08, nieNegativ = min >= 0; min -= puffer; max += puffer;
    if (nieNegativ && min < 0) { min = 0; }
    // mit Prognose: rechts Platz für die nächsten 12 Monate lassen
    var prognoseBreite = prognose ? Math.round((B - links - rechtsRand) * 0.24) : 0;
    var x = function (i) { return links + i / (punkte.length - 1) * (B - links - rechtsRand - prognoseBreite); };
    var y = function (v) { return oben + (1 - (v - min) / (max - min)) * (H - oben - unten); };
    var erster = werte[0], letzter = werte[werte.length - 1];
    var steigt = letzter >= erster;
    var farbe = steigt ? '#13814a' : '#c4302b';
    if (aendEl) {
      var pz = (letzter - erster) / erster * 100;
      aendEl.textContent = (pz > 0 ? '+' : (pz < 0 ? '−' : '')) + Math.abs(pz).toLocaleString('de-DE', { maximumFractionDigits: 1, minimumFractionDigits: 1 }) + ' %';
      aendEl.style.color = farbe;
    }
    var ns = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', '0 0 ' + B + ' ' + H);
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', 'Kursverlauf');
    var defs = document.createElementNS(ns, 'defs');
    defs.innerHTML = '<linearGradient id="fz-flaeche" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' + farbe + '" stop-opacity=".16"/><stop offset="1" stop-color="' + farbe + '" stop-opacity="0"/></linearGradient>';
    svg.appendChild(defs);
    for (var g = 0; g <= 3; g++) {
      var wert = min + (max - min) * g / 3;
      var yy = y(wert);
      var linie = document.createElementNS(ns, 'line');
      linie.setAttribute('x1', 0); linie.setAttribute('x2', B - rechtsRand + 4); linie.setAttribute('y1', yy); linie.setAttribute('y2', yy);
      linie.setAttribute('class', 'gitter');
      svg.appendChild(linie);
      var t = document.createElementNS(ns, 'text');
      t.setAttribute('x', B - rechtsRand + 8); t.setAttribute('y', yy + 4); t.setAttribute('class', 'achse');
      t.textContent = wert.toLocaleString('de-DE', { maximumFractionDigits: Math.abs(wert) >= 100 ? 0 : 2 });
      svg.appendChild(t);
    }
    var d = '';
    punkte.forEach(function (p, i) { d += (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(p[1]).toFixed(1); });
    var flaeche = document.createElementNS(ns, 'path');
    flaeche.setAttribute('d', d + 'L' + x(punkte.length - 1).toFixed(1) + ' ' + (H - unten) + 'L' + x(0) + ' ' + (H - unten) + 'Z');
    flaeche.setAttribute('fill', 'url(#fz-flaeche)');
    svg.appendChild(flaeche);
    var pfad = document.createElementNS(ns, 'path');
    pfad.setAttribute('d', d);
    pfad.setAttribute('class', 'linie');
    pfad.setAttribute('stroke', farbe);
    svg.appendChild(pfad);
    zusatz.forEach(function (z) {
      var dz = '', offen = false;
      z.punkte.forEach(function (p, i) {
        if (p[1] === null) { offen = false; return; }
        dz += (offen ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(p[1]).toFixed(1); offen = true;
      });
      var lz = document.createElementNS(ns, 'path');
      lz.setAttribute('d', dz); lz.setAttribute('class', 'linie zusatz'); lz.setAttribute('stroke', z.farbe);
      svg.appendChild(lz);
    });
    [0, Math.floor((punkte.length - 1) / 2), punkte.length - 1].forEach(function (i, n) {
      var t2 = document.createElementNS(ns, 'text');
      t2.setAttribute('x', x(i)); t2.setAttribute('y', H - 5); t2.setAttribute('class', 'achse');
      t2.setAttribute('text-anchor', n === 0 ? 'start' : (n === 1 ? 'middle' : 'end'));
      t2.textContent = datumFmt(punkte[i][0]);
      svg.appendChild(t2);
    });
    // Analysten-Prognose: Trichter vom letzten Kurs zu Kursziel tief/mittel/hoch in 12 Monaten
    if (prognose && prognose.mittel) {
      var x0 = x(punkte.length - 1), y0 = y(punkte[punkte.length - 1][1]), x1 = B - rechtsRand;
      var hoch = prognose.hoch || prognose.mittel, tief = prognose.tief || prognose.mittel;
      var kegel = document.createElementNS(ns, 'path');
      kegel.setAttribute('d', 'M' + x0 + ' ' + y0 + 'L' + x1 + ' ' + y(hoch) + 'L' + x1 + ' ' + y(tief) + 'Z');
      kegel.setAttribute('class', 'prognose-flaeche');
      svg.appendChild(kegel);
      [['hoch', hoch], ['mittel', prognose.mittel], ['tief', tief]].forEach(function (z) {
        var l = document.createElementNS(ns, 'path');
        l.setAttribute('d', 'M' + x0 + ' ' + y0 + 'L' + x1 + ' ' + y(z[1]));
        l.setAttribute('class', 'prognose-linie ' + z[0]);
        svg.appendChild(l);
        var pkt = document.createElementNS(ns, 'circle');
        pkt.setAttribute('cx', x1); pkt.setAttribute('cy', y(z[1])); pkt.setAttribute('r', z[0] === 'mittel' ? 3.5 : 2.5);
        pkt.setAttribute('class', 'prognose-punkt');
        svg.appendChild(pkt);
      });
      var tz = document.createElementNS(ns, 'text');
      tz.setAttribute('x', x1); tz.setAttribute('y', H - 5); tz.setAttribute('class', 'achse'); tz.setAttribute('text-anchor', 'end');
      tz.textContent = '+12 Mon.';
      svg.appendChild(tz);
      var tl = document.createElementNS(ns, 'text');
      tl.setAttribute('x', x1 - 4); tl.setAttribute('y', y(prognose.mittel) - 6); tl.setAttribute('class', 'achse prognose-text'); tl.setAttribute('text-anchor', 'end');
      tl.textContent = 'Ziel ' + fmt(prognose.mittel);
      svg.appendChild(tl);
    }
    // Kauf ▲ / Verkauf ▼ am ersten Rasterpunkt ab dem Geschäftstag
    var jePunkt = {};
    marker.forEach(function (m) {
      var i = 0;
      while (i < punkte.length - 1 && punkte[i][0] < m.t) { i++; }
      (jePunkt[i] = jePunkt[i] || []).push(m);
    });
    Object.keys(jePunkt).forEach(function (i) {
      var liste = jePunkt[i], kauf = liste.some(function (m) { return m.typ === 'kauf'; }), verkauf = liste.some(function (m) { return m.typ === 'verkauf'; });
      [kauf ? 'kauf' : null, verkauf ? 'verkauf' : null].filter(Boolean).forEach(function (typ) {
        var mx = x(+i), my = y(punkte[i][1]);
        var p = document.createElementNS(ns, 'path');
        p.setAttribute('d', typ === 'kauf'
          ? 'M' + mx + ' ' + (my + 6) + 'l-5 9h10z'
          : 'M' + mx + ' ' + (my - 6) + 'l-5 -9h10z');
        p.setAttribute('class', 'marke ' + typ);
        svg.appendChild(p);
      });
    });
    var senkrecht = document.createElementNS(ns, 'line');
    senkrecht.setAttribute('class', 'gitter');
    senkrecht.setAttribute('y1', oben); senkrecht.setAttribute('y2', H - unten);
    senkrecht.style.display = 'none';
    svg.appendChild(senkrecht);
    var punkt = document.createElementNS(ns, 'circle');
    punkt.setAttribute('r', 4); punkt.setAttribute('fill', farbe); punkt.setAttribute('stroke', '#fff'); punkt.setAttribute('stroke-width', 2);
    punkt.style.display = 'none';
    svg.appendChild(punkt);
    chart.appendChild(svg);
    var tip = document.createElement('div');
    tip.className = 'chart-tip';
    tip.hidden = true;
    chart.appendChild(tip);
    var zeigen = function (ev) {
      var r = svg.getBoundingClientRect();
      var px = (ev.clientX - r.left) / r.width * B;
      var i = Math.max(0, Math.min(punkte.length - 1, Math.round((px - links) / (B - links - rechtsRand - prognoseBreite) * (punkte.length - 1))));
      var xx = x(i), yy2 = y(punkte[i][1]);
      senkrecht.setAttribute('x1', xx); senkrecht.setAttribute('x2', xx); senkrecht.style.display = '';
      punkt.setAttribute('cx', xx); punkt.setAttribute('cy', yy2); punkt.style.display = '';
      tip.hidden = false;
      tip.innerHTML = '<b>' + fmt(punkte[i][1]) + '</b>' + datumFmt(punkte[i][0])
        + zusatz.map(function (z) { var v = z.punkte[i] && z.punkte[i][1]; return v === null || v === undefined ? '' : '<small style="color:' + z.farbe + '">' + z.name + ': ' + fmt(v) + '</small>'; }).join('')
        + (jePunkt[i] || []).slice(0, 4).map(function (m) { return '<small class="' + (m.typ === 'kauf' ? 'plus' : 'minus') + '">' + m.text + '</small>'; }).join('');
      tip.style.left = Math.max(60, Math.min(r.width - 60, xx / B * r.width)) + 'px';
    };
    var weg = function () { senkrecht.style.display = 'none'; punkt.style.display = 'none'; tip.hidden = true; };
    svg.addEventListener('pointermove', zeigen);
    svg.addEventListener('pointerdown', zeigen);
    svg.addEventListener('pointerleave', weg);
  }

  // ---------- Kurs-Chart ----------
  var chart = $('[data-chart]');
  var datenEl = $('#chart-daten');
  if (chart && datenEl) {
    var roh = {};
    try { roh = JSON.parse(datenEl.textContent || '{}'); } catch (e) { roh = {}; }
    var waehrung = roh.w || '';
    var zeichen = { EUR: '€', USD: '$', GBP: '£', GBp: 'p', JPY: '¥', CHF: 'CHF' }[waehrung] || waehrung;
    var fmt = function (z) { return z.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + zeichen; };
    var datumFmt = function (t) { return new Date(t * 1000).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' }); };
    var aendEl = $('.chart-aend');

    var spannen = {
      '1m': function () { return (roh.j1 || []).slice(-22); },
      '6m': function () { return (roh.j1 || []).slice(-126); },
      '1j': function () { return roh.j1 || []; },
      '5j': function () { return (roh.j5 && roh.j5.length ? roh.j5 : roh.j1) || []; }
    };

    var schnitte = { s30: speicher.lesen('fz-s30') === '1', s100: speicher.lesen('fz-s100') === '1',
      ziel: speicher.lesen('fz-ziel') === '1', marken: speicher.lesen('fz-marken') !== '0' };
    var zeichnen = function () {
      // Durchschnitte aus dem ganzen verfügbaren Verlauf berechnen, dann auf die gewählte Spanne kürzen
      var punkte = spannen[aktiv]();
      var basis = aktiv === '5j' ? spannen['5j']() : (roh.j1 || []);
      var ab = basis.length - punkte.length;
      var zusatz = [];
      if (schnitte.s30) { zusatz.push({ name: 'Ø 30 Tage', farbe: '#d97706', punkte: gleitend(basis, 30).slice(ab) }); }
      if (schnitte.s100) { zusatz.push({ name: 'Ø 100 Tage', farbe: '#7c3aed', punkte: gleitend(basis, 100).slice(ab) }); }
      var marken = [];
      if (schnitte.marken && roh.k && punkte.length) {
        var von = punkte[0][0], bis = punkte[punkte.length - 1][0] + 86400;
        marken = roh.k.filter(function (m) { return m.t >= von && m.t <= bis; });
      }
      linienChart(chart, punkte, fmt, datumFmt, aendEl, zusatz, marken, schnitte.ziel && roh.ziel ? roh.ziel : null);
    };
    $$('[data-schnitt]').forEach(function (k) {
      var n = k.getAttribute('data-schnitt');
      k.classList.toggle('aktiv', schnitte[n]);
      k.addEventListener('click', function () { schnitte[n] = !schnitte[n]; k.classList.toggle('aktiv', schnitte[n]); speicher.schreiben('fz-' + n, schnitte[n] ? '1' : '0'); zeichnen(); });
    });

    var aktiv = speicher.lesen('fz-spanne') || '1j';
    if (!spannen[aktiv]) { aktiv = '1j'; }
    var knoepfe = $$('[data-spanne]');
    var wechseln = function (s) {
      aktiv = s;
      knoepfe.forEach(function (k) { k.classList.toggle('aktiv', k.getAttribute('data-spanne') === s); });
      zeichnen();
      speicher.schreiben('fz-spanne', s);
    };
    knoepfe.forEach(function (k) { k.addEventListener('click', function () { wechseln(k.getAttribute('data-spanne')); }); });
    wechseln(aktiv);
    var breite = chart.clientWidth;
    window.addEventListener('resize', function () { if (Math.abs(chart.clientWidth - breite) > 20) { breite = chart.clientWidth; zeichnen(); } });
  }

  // ---------- Depot-Verlauf: Zeitraum und Titel frei wählen ----------
  var vb = $('[data-verlauf]');
  if (vb) {
    var vZustand = { zeitraum: '1j', von: '', bis: '', quelle: 'alle', aus: {} };
    try { var gesp = JSON.parse(speicher.lesen('fz-verlauf') || 'null'); if (gesp) { vZustand = Object.assign(vZustand, gesp); } } catch (e) { /* egal */ }
    var vDaten = null, ladeNummer = 0;
    var heute = new Date();
    var iso = function (d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
    var euro = function (z) { return z.toLocaleString('de-DE', { style: 'currency', currency: 'EUR', maximumFractionDigits: Math.abs(z) >= 1000 ? 0 : 2 }); };
    var vorz = function (z) { return (z > 0 ? '+' : '') + euro(z); };
    var prozent = function (z) { return z === null || !isFinite(z) ? '–' : (z > 0 ? '+' : '') + (z * 100).toLocaleString('de-DE', { maximumFractionDigits: 1, minimumFractionDigits: 1 }) + ' %'; };
    var klasseVon = function (z) { return z > 0.004 ? 'plus' : (z < -0.004 ? 'minus' : ''); };
    var tag = function (t) { return new Date(t * 1000).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' }); };
    var beginn = vb.getAttribute('data-beginn') || iso(new Date(heute.getFullYear() - 1, heute.getMonth(), heute.getDate()));
    var vonFeld = $('[name=von]', vb.parentNode), bisFeld = $('[name=bis]', vb.parentNode);

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
    var liste = $('[data-verlauf-liste]'), sucheFeld = $('[data-verlauf-suche]');

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
      // Rendite bezogen auf das durchschnittlich eingesetzte Kapital (Anfangswert plus Käufe minus Verkäufe, über die Zeit gemittelt)
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
    var sichtbar = function () {
      return vDaten.titel.filter(function (t) { return vZustand.quelle === 'alle' || t.quellen.indexOf(vZustand.quelle) >= 0; });
    };
    var gewaehlt = function () { return sichtbar().filter(function (t) { return !vZustand.aus[t.id]; }); };

    var nurDiesen = function (id) {
      vZustand.aus = {};
      vDaten.titel.forEach(function (x) { if (x.id !== id) { vZustand.aus[x.id] = 1; } });
      speichern(); zeigen();
    };
    var zeigen = function () {
      if (!vDaten) { return; }
      var alle = sichtbar(), auswahl = gewaehlt();
      var k = auswerten(auswahl);
      var s0 = vDaten.start || 0;
      var alleP = vDaten.punkte.map(function (t, i) { return [t, Math.round(k.summe[i] * 100) / 100]; });
      var punkte = alleP.slice(s0);
      var zusatz = [];
      if (vZustand.s30) { zusatz.push({ name: 'Ø 30 Tage', farbe: '#d97706', punkte: gleitend(alleP, 30).slice(s0) }); }
      if (vZustand.s100) { zusatz.push({ name: 'Ø 100 Tage', farbe: '#7c3aed', punkte: gleitend(alleP, 100).slice(s0) }); }
      if (vZustand.einsatz !== false) {
        // Wert am Anfang plus Käufe minus Verkäufe: zeigt, was davon eingezahlt und was Kursentwicklung ist
        var eingesetzt = [], summeF = k.v0;
        for (var ei = s0; ei < vDaten.punkte.length; ei++) {
          if (ei > s0) { auswahl.forEach(function (t) { summeF += t.f[ei] || 0; }); }
          eingesetzt.push([vDaten.punkte[ei], Math.round(summeF * 100) / 100]);
        }
        zusatz.push({ name: 'Eingesetzt', farbe: '#64748b', punkte: eingesetzt });
      }
      // Käufe und Verkäufe der Auswahl (bei bis zu 10 Titeln, sonst wird es unübersichtlich)
      var geschaefte = [];
      if (auswahl.length <= 10) {
        auswahl.forEach(function (t) {
          (t.k || []).forEach(function (g) {
            if (g[3] === 'massnahme') { return; }
            geschaefte.push({ t: g[0], typ: g[3], titel: t.name, stueck: g[1], betrag: g[2], quelle: g[4],
              text: (g[3] === 'kauf' ? '▲ Kauf ' : '▼ Verkauf ') + (auswahl.length > 1 ? t.name + ' ' : '') + euro(Math.abs(g[2])) });
          });
        });
      }
      geschaefte.sort(function (a, b) { return a.t - b.t; });
      linienChart(diagramm, punkte, euro, tag, null, zusatz, geschaefte);
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
              + (auswahl.length > 1 ? ' · ' + g.titel.replace(/[<>&]/g, '') : '') + '<small class="leise" style="display:block">'
              + Math.abs(g.stueck).toLocaleString('de-DE', { maximumFractionDigits: 4 }) + ' Stück · ' + (g.quelle === 'etoro' ? 'eToro' : 'Trade Republic') + '</small></span><b>' + euro(Math.abs(g.betrag)) + '</b></li>';
          });
          gl.innerHTML = html + '</ul>';
        }
      }
      var kachel = function (titel, wert, unter) { return '<div class="kachel"><span class="k-titel">' + titel + '</span><span class="k-wert">' + wert + '</span>' + (unter ? '<span class="k-unter">' + unter + '</span>' : '') + '</div>'; };
      kennz.innerHTML =
        kachel('Wert ' + tag(vDaten.punkte[s0]), euro(k.v0)) +
        kachel('Wert ' + tag(vDaten.punkte[vDaten.punkte.length - 1]), euro(k.v1)) +
        kachel('Gewinn im Zeitraum', '<span class="' + klasseVon(k.gewinn) + '">' + vorz(k.gewinn) + '</span>', '<span class="' + klasseVon(k.gewinn) + '">' + prozent(k.rendite) + '</span>') +
        kachel('Käufe − Verkäufe', vorz(k.fluss), k.div ? 'Dividenden ' + euro(k.div) : '');
      stand.textContent = (auswahl.length === 1 ? auswahl[0].name + ' – ' : '') + auswahl.length + ' von ' + alle.length + ' Titeln ausgewählt · ' + tag(vDaten.punkte[s0]) + ' bis ' + tag(vDaten.punkte[vDaten.punkte.length - 1]);
      var q = (sucheFeld && sucheFeld.value || '').toLowerCase();
      liste.innerHTML = '';
      var passt = function (t) { return !q || (t.name + ' ' + t.symbol).toLowerCase().indexOf(q) >= 0; };
      var ueberschrift = function (text) { var li = document.createElement('li'); li.className = 'gruppe'; li.textContent = text; liste.appendChild(li); };
      var zeile = function (t) {
        var e = auswerten([t]);
        var li = document.createElement('li');
        li.innerHTML = '<label><input type="checkbox"' + (vZustand.aus[t.id] ? '' : ' checked') + '><span><strong></strong><small></small></span></label>' +
          '<span class="rechts"><b>' + euro(e.v1) + '</b><small class="' + klasseVon(e.gewinn) + '">' + vorz(e.gewinn) + ' (' + prozent(e.rendite) + ')</small></span>' +
          '<button type="button" class="knopf-text klein" title="Nur diesen Titel zeigen">nur</button>';
        $('strong', li).textContent = t.name;
        $('small', li).textContent = t.symbol + ' · ' + t.quellen.map(function (x) { return x === 'etoro' ? 'eToro' : 'Trade Republic'; }).join(' + ') + (t.luecke ? ' · Kurse lückenhaft' : '');
        $('input', li).addEventListener('change', function (ev) { if (ev.target.checked) { delete vZustand.aus[t.id]; } else { vZustand.aus[t.id] = 1; } speichern(); zeigen(); });
        $('button', li).addEventListener('click', function () { nurDiesen(t.id); vb.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
        liste.appendChild(li);
      };
      var letzter = vDaten.punkte.length - 1;
      // Sortieren: fehlende Werte immer ans Ende
      var feld = vZustand.sort || 'wert', richtung = vZustand.richtung || (feld === 'name' ? 'auf' : 'ab');
      var wertVon = {};
      alle.forEach(function (t) {
        var e = feld === 'gewinn' || feld === 'rendite' ? auswerten([t]) : null;
        wertVon[t.id] = feld === 'name' ? t.name.toLowerCase() : feld === 'pot' ? t.pot : feld === 'gewinn' ? e.gewinn : feld === 'rendite' ? e.rendite : t.w[letzter];
      });
      var sortiere = function (liste) {
        return liste.sort(function (a, b) {
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
          li.innerHTML = '<span><strong></strong><small></small></span><button type="button" class="knopf-text klein">anzeigen</button>';
          $('strong', li).textContent = t.name;
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
      var versuche = 0, nummer = ++ladeNummer;
      var schritt = function () {
        var daten = new FormData();
        daten.append('csrf', csrf); daten.append('von', g[0]); daten.append('bis', g[1]); daten.append('vorlauf', '100');
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
            vDaten = j;
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
    $$('[data-verlauf-schnitt]').forEach(function (k) {
      var n = k.getAttribute('data-verlauf-schnitt');
      if (n === 'einsatz' && vZustand.einsatz === undefined) { vZustand.einsatz = true; }
      k.classList.toggle('aktiv', !!vZustand[n]);
      k.addEventListener('click', function () { vZustand[n] = !vZustand[n]; k.classList.toggle('aktiv', vZustand[n]); speichern(); zeigen(); });
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
    laden();
  }
})();
