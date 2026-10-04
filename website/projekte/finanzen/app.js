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

    var zeichnen = function (punkte) {
      chart.innerHTML = '';
      if (!punkte || punkte.length < 2) { chart.innerHTML = '<p class="leer">Kein Kursverlauf verfügbar.</p>'; if (aendEl) { aendEl.textContent = ''; } return; }
      var B = chart.clientWidth || 600, H = chart.clientHeight || 210, oben = 10, unten = 22, links = 0, rechtsRand = 52;
      var werte = punkte.map(function (p) { return p[1]; });
      var min = Math.min.apply(null, werte), max = Math.max.apply(null, werte);
      if (max === min) { max += 1; min -= 1; }
      var puffer = (max - min) * 0.08; min -= puffer; max += puffer;
      var x = function (i) { return links + i / (punkte.length - 1) * (B - links - rechtsRand); };
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
        t.textContent = wert.toLocaleString('de-DE', { maximumFractionDigits: wert >= 100 ? 0 : 2 });
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
      [0, Math.floor((punkte.length - 1) / 2), punkte.length - 1].forEach(function (i, n) {
        var t2 = document.createElementNS(ns, 'text');
        t2.setAttribute('x', x(i)); t2.setAttribute('y', H - 5); t2.setAttribute('class', 'achse');
        t2.setAttribute('text-anchor', n === 0 ? 'start' : (n === 1 ? 'middle' : 'end'));
        t2.textContent = datumFmt(punkte[i][0]);
        svg.appendChild(t2);
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
        var i = Math.max(0, Math.min(punkte.length - 1, Math.round((px - links) / (B - links - rechtsRand) * (punkte.length - 1))));
        var xx = x(i), yy2 = y(punkte[i][1]);
        senkrecht.setAttribute('x1', xx); senkrecht.setAttribute('x2', xx); senkrecht.style.display = '';
        punkt.setAttribute('cx', xx); punkt.setAttribute('cy', yy2); punkt.style.display = '';
        tip.hidden = false;
        tip.innerHTML = '<b>' + fmt(punkte[i][1]) + '</b>' + datumFmt(punkte[i][0]);
        tip.style.left = Math.max(60, Math.min(r.width - 60, xx / B * r.width)) + 'px';
      };
      var weg = function () { senkrecht.style.display = 'none'; punkt.style.display = 'none'; tip.hidden = true; };
      svg.addEventListener('pointermove', zeigen);
      svg.addEventListener('pointerdown', zeigen);
      svg.addEventListener('pointerleave', weg);
    };

    var aktiv = speicher.lesen('fz-spanne') || '1j';
    if (!spannen[aktiv]) { aktiv = '1j'; }
    var knoepfe = $$('[data-spanne]');
    var wechseln = function (s) {
      aktiv = s;
      knoepfe.forEach(function (k) { k.classList.toggle('aktiv', k.getAttribute('data-spanne') === s); });
      zeichnen(spannen[s]());
      speicher.schreiben('fz-spanne', s);
    };
    knoepfe.forEach(function (k) { k.addEventListener('click', function () { wechseln(k.getAttribute('data-spanne')); }); });
    wechseln(aktiv);
    var breite = chart.clientWidth;
    window.addEventListener('resize', function () { if (Math.abs(chart.clientWidth - breite) > 20) { breite = chart.clientWidth; zeichnen(spannen[aktiv]()); } });
  }
})();
