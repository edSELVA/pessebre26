// Visor públic del pessebre: carrega el disseny i snapshot.json, dibuixa l'escena i gestiona el zoom tàctil.
// Mode participar: #c=CODI (famílies) o #k=ENLLAÇ (claustre). El que va després del # no arriba mai al servidor.
// Només per a proves: #s=nom carrega exemples/nom.json en lloc de snapshot.json.
'use strict';

(function () {
  var NS = 'http://www.w3.org/2000/svg';
  var AMPLE = 2400, ALT = 800;
  var REFRESC_MS = 60000;

  var hash = llegeixHash();
  var mode = hash.c ? 'familia' : (hash.k ? 'claustre' : null);
  var fontSnapshot = /^[a-z0-9-]{1,30}$/.test(hash.s || '') ? 'exemples/' + hash.s + '.json' : 'snapshot.json';

  var D = {};          // disseny: mapa, peces, paletes, fixa, reis
  var capes = {};      // grups SVG on es dibuixa
  var svg;

  function llegeixHash() {
    var out = {};
    location.hash.replace(/^#/, '').split('&').forEach(function (p) {
      var i = p.indexOf('=');
      if (i > 0) out[p.slice(0, i)] = decodeURIComponent(p.slice(i + 1));
    });
    return out;
  }

  function carrega(url, tipus) {
    return fetch(url, { cache: 'no-cache', credentials: 'omit' }).then(function (r) {
      if (!r.ok) throw new Error(url + ': ' + r.status);
      return tipus === 'json' ? r.json() : r.text();
    });
  }

  function svgDe(text) {
    var doc = new DOMParser().parseFromString(text, 'image/svg+xml');
    if (doc.querySelector('parsererror')) throw new Error('SVG no vàlid');
    return doc.documentElement;
  }

  function el(nom, atributs, pare) {
    var e = document.createElementNS(NS, nom);
    Object.keys(atributs || {}).forEach(function (k) { e.setAttribute(k, atributs[k]); });
    if (pare) pare.appendChild(e);
    return e;
  }

  // Copia els fills d'un SVG carregat dins d'un grup de l'escena.
  function incrusta(origen, desti) {
    Array.prototype.slice.call(origen.childNodes).forEach(function (n) { desti.appendChild(document.importNode(n, true)); });
  }

  // ── construcció de l'escena (una sola vegada)

  function construeix(t) {
    svg = el('svg', { viewBox: '0 0 ' + AMPLE + ' ' + ALT, 'shape-rendering': 'crispEdges', id: 'pessebre', role: 'img',
                      'aria-label': 'Pessebre de l\'escola' });
    var defs = el('defs', {}, svg);
    incrusta(svgDe(t.peces).querySelector('defs'), defs);
    capes.terreny = el('g', { id: 'capa-terreny' }, svg);
    incrusta(svgDe(t.terreny), capes.terreny);
    capes.terra = el('g', { id: 'capa-llum-terra' }, svg);
    incrusta(svgDe(t.terra), capes.terra);
    capes.peces = el('g', { id: 'capa-peces' }, svg);
    capes.aire = el('g', { id: 'capa-llum-aire' }, svg);
    incrusta(svgDe(t.aire), capes.aire);
    capes.tolls = svg.querySelector('#llum-terra-tolls');
    capes.halos = svg.querySelector('#llum-halos');
    var escena = document.getElementById('escena');
    escena.textContent = '';
    escena.appendChild(svg);
    Zoom.inicia(escena, svg);
    Zoom.botons(document.getElementById('zoom-mes'), document.getElementById('zoom-menys'), document.getElementById('zoom-tot'));
    document.getElementById('zoom').hidden = false;
  }

  // Un <use> d'una peça del sprite, col·locada per l'ancoratge.
  function usa(codi, x, y, mirall, vars, classe) {
    var peca = D.peces[codi];
    var c = Compon.colloca(peca, x, y, mirall);
    var u = el('use', { href: '#pc-' + codi, x: c.x, y: c.y, width: c.w, height: c.h });
    if (c.transform) u.setAttribute('transform', c.transform);
    if (classe) u.setAttribute('class', classe);
    Object.keys(vars || {}).forEach(function (k) { u.style.setProperty(k, vars[k]); });
    return u;
  }

  // ── dibuix de l'estat (es refà quan canvia el snapshot)

  function dibuixa(s) {
    svg.setAttribute('class', 'moment-' + s.moment);
    [capes.peces, capes.tolls, capes.halos].forEach(function (g) { g.textContent = ''; });
    var zones = {};
    s.zones.forEach(function (z) { zones[z] = true; });
    var retinguts = {};
    (s.r || []).forEach(function (id) { retinguts[id] = true; });
    var llista = [];

    // Escena fixa (els Reis es mouen segons la data del servidor)
    var reis = Compon.reis(D.reis, s.data), r = 0;
    D.fixa.forEach(function (f) {
      var x = f.x, y = f.y;
      if (f.codi.indexOf('ESC-REI-') === 0) { x = reis[r].x; y = reis[r].y; r++; }
      var vars = {};
      Object.keys(f.colors || {}).forEach(function (k) { vars['--' + k] = f.colors[k]; });
      llista.push({ y: y, ordre: f.ordre, node: usa(f.codi, x, y, !!f.mirall, vars) });
    });

    D.mapa.llocs.forEach(function (l) {
      var o = s.o[l.id];
      if (o) {
        var peca = D.peces[o.p];
        var mirall = o.m !== undefined ? !!o.m : !!l.mirall;
        var vars = Compon.colors(peca, o.c, D.paletes);
        if (s.moment === 'neu' && peca.cat === 'casa') Compon.neuTeulada(vars);
        llista.push({ y: l.y, node: usa(o.p, l.x, l.y, mirall, vars) });
        if (peca.cat === 'casa') capes.tolls.appendChild(usa('LLUM-TOLL', l.x, l.y + 5));
        if (o.p === 'ELE-FANAL') capes.halos.appendChild(usa('LLUM-HALO', l.x, l.y - 22));
        if (o.n) llista.push({ y: l.y, node: textPlaca(peca, l, mirall, o.n) });
        return;
      }
      if (!visibleLliure(l, s, zones)) return;
      if (l.tipus === 'casa' || l.tipus === 'element') {
        llista.push({ y: l.y, node: usa(l.codi, l.x, l.y, !!l.mirall, null, 'fantasma ' + l.tipus) });
      } else if (mode && !retinguts[l.id] && l.grup === (mode === 'claustre' ? 'claustre' : 'general')) {
        llista.push({ y: l.y, node: usa(l.tipus === 'animal' ? 'MARCA-ANI' : 'MARCA-PER', l.x, l.y) });
      }
    });

    var frag = document.createDocumentFragment();
    Compon.ordena(llista).forEach(function (e) { frag.appendChild(e.node); });
    capes.peces.appendChild(frag);
    mostraComptador(s.comptador);
  }

  // Un lloc lliure es dibuixa (en transparent) si la seva zona és oberta, amb excepcions per grup.
  function visibleLliure(l, s, zones) {
    if (l.grup === 'poble') return s.classes;                       // cases de classe: només durant les tutories
    if (l.grup === 'claustre' && mode === 'claustre' && s.claustre) return true;   // el claustre els veu encara que la zona sigui tancada
    return !!zones[l.zona];
  }

  function textPlaca(peca, l, mirall, nom) {
    var p = Compon.placa(peca, l.x, l.y, mirall);
    var t = el('text', { x: p.x + p.w / 2, y: p.y + p.h / 2 + 1.9, class: 'placa-text', 'text-anchor': 'middle' });
    t.textContent = nom;
    if (nom.length > 4) { t.setAttribute('textLength', p.w - 2); t.setAttribute('lengthAdjust', 'spacingAndGlyphs'); }
    return t;
  }

  function mostraComptador(c) {
    var t = Compon.textComptador(c);
    var caixa = document.getElementById('comptador');
    caixa.hidden = !t;
    if (!t) return;
    document.getElementById('comptador-text').textContent = t.text;
    document.getElementById('comptador-barra').style.width = Math.round(t.part * 100) + '%';
  }

  function error(msg) {
    var e = document.getElementById('escena');
    e.textContent = '';
    var p = document.createElement('p');
    p.className = 'missatge';
    p.textContent = msg;
    e.appendChild(p);
  }

  // ── inici

  if (mode) {
    var avis = document.getElementById('avis-mode');
    avis.textContent = mode === 'claustre' ? 'Mode participar · claustre' : 'Mode participar';
    avis.hidden = false;
  }

  var d = 'disseny/';
  Promise.all([
    carrega(d + 'peces.svg'), carrega(d + 'terreny.svg'), carrega(d + 'llum-terra.svg'), carrega(d + 'llum-aire.svg'),
    carrega(d + 'mapa.json', 'json'), carrega(d + 'peces.json', 'json'), carrega(d + 'paletes.json', 'json'),
    carrega(d + 'escena-fixa.json', 'json'), carrega(d + 'reis-cami.json', 'json'), carrega(fontSnapshot, 'json')
  ]).then(function (r) {
    D = { mapa: r[4], peces: r[5].peces, paletes: r[6], fixa: r[7].peces, reis: r[8] };
    construeix({ peces: r[0], terreny: r[1], terra: r[2], aire: r[3] });
    dibuixa(r[9]);
    var versio = r[9].versio;
    if (mode) {
      setInterval(function () {
        carrega(fontSnapshot, 'json').then(function (s) {
          if (s.versio !== versio) { versio = s.versio; dibuixa(s); }
        }).catch(function () { /* es torna a provar al minut següent */ });
      }, REFRESC_MS);
    }
  }).catch(function () {
    error('No s\'ha pogut carregar el pessebre. Torna-ho a provar d\'aquí a una estona.');
  });
})();
