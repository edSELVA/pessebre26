// Visor del pessebre: carrega el disseny i el snapshot, dibuixa l'escena i gestiona el zoom tàctil.
// El fan servir index.html (visor públic i mode participar, a participa.js) i classes.html (tutors, a classes.js).
'use strict';

var Visor = (function () {
  var NS = 'http://www.w3.org/2000/svg';
  var AMPLE = 2400, ALT = 800;

  var D = {};            // disseny: mapa, peces, paletes, fixa, reis
  var capes = {};        // grups SVG on es dibuixa
  var svg, op = {}, darrer = null;
  var propis = {};       // ocupacions locals encara no confirmades (la tria del tutor): id → {p, c}
  var SILUETA = { persona: 'PER-ADU-F', animal: 'ANI-OVELLA-D' };   // silueta dels llocs sense peça fixa

  function llegeixHash() {
    var out = {};
    location.hash.replace(/^#/, '').split('&').forEach(function (p) {
      var i = p.indexOf('=');
      if (i > 0) { try { out[p.slice(0, i)] = decodeURIComponent(p.slice(i + 1)); } catch (e) { out[p.slice(0, i)] = p.slice(i + 1); } }   // «%» solt no penja la pàgina
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
    posaLogo(t.logo);
    capes.terra = el('g', { id: 'capa-llum-terra' }, svg);
    incrusta(svgDe(t.terra), capes.terra);
    capes.peces = el('g', { id: 'capa-peces' }, svg);
    capes.aire = el('g', { id: 'capa-llum-aire' }, svg);
    incrusta(svgDe(t.aire), capes.aire);
    capes.pista = el('g', { id: 'capa-pista' }, svg);           // pistes del joc del caganer (enfosquir i marcar)
    capes.marques = el('g', { id: 'capa-marques' }, svg);       // punts dels llocs lliures en mode participar
    capes.destacat = el('g', { id: 'capa-destacat' }, svg);     // requadre del lloc seleccionat (administració)
    capes.tolls = svg.querySelector('#llum-terra-tolls');
    capes.halos = svg.querySelector('#llum-halos');
    var escena = document.getElementById('escena');
    escena.textContent = '';
    escena.appendChild(svg);
    Zoom.inicia(escena, svg, { vista: op.vista, enToc: toc });
    Zoom.botons(document.getElementById('zoom-mes'), document.getElementById('zoom-menys'), document.getElementById('zoom-tot'));
    document.getElementById('zoom').hidden = false;
  }

  // Logo de l'escola al cel (disseny/logo.svg, generat per eines/logo.php; no és al repositori públic, pot no existir).
  // Va just després del sol i abans de les muntanyes, que el tapen per baix.
  function posaLogo(text) {
    var logo = text && svgDe(text).querySelector('.logo');
    var sol = capes.terreny.querySelector('#sol');
    if (!logo || !sol) return;
    sol.parentNode.insertBefore(document.importNode(logo, true), sol.nextSibling);
  }

  // Un <use> d'una peça del sprite, col·locada per l'ancoratge. «simbol»: un altre símbol amb la mateixa mida (tapes de finestra).
  function usa(codi, x, y, mirall, vars, classe, simbol) {
    var peca = D.peces[codi];
    var c = Compon.colloca(peca, x, y, mirall);
    var u = el('use', { href: '#pc-' + (simbol || codi), x: c.x, y: c.y, width: c.w, height: c.h });
    if (c.transform) u.setAttribute('transform', c.transform);
    if (classe) u.setAttribute('class', classe);
    Object.keys(vars || {}).forEach(function (k) { u.style.setProperty(k, vars[k]); });
    return u;
  }

  // ── dibuix de l'estat (es refà quan canvia el snapshot o la tria local)

  function dibuixa(s) {
    darrer = s;
    svg.setAttribute('class', 'moment-' + s.moment);
    [capes.peces, capes.tolls, capes.halos, capes.marques].forEach(function (g) { g.textContent = ''; });
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
    // El caganer: cada dia en un amagatall diferent (com els Reis, segons la data del pessebre)
    var cg = llocCaganer(s);
    // El caganer es dibuixa per sobre de tot: amb el pessebre ple, els arbres de les famílies el tapaven del tot molts dies
    if (cg) llista.push({ y: cg.y, ordre: 1e9, node: usa(D.caganer.peca, cg.x, cg.y, false, null, 'caganer') });

    var poble = 0, ocupades = 0;
    D.mapa.llocs.forEach(function (l) {
      if (l.grup === 'poble') { poble++; if (propis[l.id] || s.o[l.id]) ocupades++; }
      nodesLloc(l, s, zones, retinguts).forEach(function (e) {
        if (e.capa === 'peces') llista.push(e); else capes[e.capa].appendChild(e.node);
      });
    });

    // El paviment del casc antic «es construeix» amb les cases de classe: del 35 % (cap) al 100 % (totes)
    svg.style.setProperty('--casc-op', (0.35 + 0.65 * ocupades / poble).toFixed(2));

    var frag = document.createDocumentFragment();
    Compon.ordena(llista).forEach(function (e) { e.node.setAttribute('data-k', e.ordre !== undefined ? e.ordre : e.y); frag.appendChild(e.node); });
    capes.peces.appendChild(frag);
    mostraComptador(s.comptador);
  }

  // Nodes d'un lloc (ocupat, en tria o lliure). capa: 'peces' (ordenats per y), 'tolls', 'halos' o 'marques'.
  function nodesLloc(l, s, zones, retinguts) {
    var out = [];
    var posa = function (capa, node) { node.setAttribute('data-lloc', l.id); out.push({ capa: capa, y: l.y, node: node }); };
    var o = propis[l.id] || s.o[l.id];
    if (o) {
      var peca = D.peces[o.p];
      var mirall = o.m !== undefined ? !!o.m : !!l.mirall;
      var vars = Compon.colors(peca, o.c, D.paletes, l.pastor ? 'pastors' : null);
      if (s.moment === 'neu' && peca.cat === 'casa') Compon.neuTeulada(vars);
      posa('peces', usa(o.p, l.x, l.y, mirall, vars, propis[l.id] ? 'propia' : null));
      Compon.finestresTapades(o.f).forEach(function (i) {       // finestres que la classe ha tret: hi va paret
        if (i < (peca.finestres || []).length) posa('peces', usa(o.p, l.x, l.y, mirall, vars, null, o.p + '-F' + i));
      });
      if (peca.cat === 'casa') posa('tolls', usa('LLUM-TOLL', l.x, l.y + 5));
      if (o.p === 'ELE-FANAL') posa('halos', usa('LLUM-HALO', l.x, l.y - 22));
      if (o.n) posa('peces', textPlaca(peca, l, mirall, o.n));
      return out;
    }
    if (!visibleLliure(l, s, zones)) return out;
    var ret = retinguts[l.id];
    if (op.filtre) {
      // Mode participar amb un tipus triat: només les siluetes d'aquest tipus als llocs que pot triar aquest grup
      if (l.tipus !== op.filtre || !llocDelGrup(l, s)) return out;
      var codi = l.codi || SILUETA[l.tipus];
      posa('peces', usa(codi, l.x, l.y, !!l.mirall, null, 'fantasma ' + l.tipus + (ret ? ' retingut' : '')));
      if (!ret) posa('peces', usa(codi, l.x, l.y, !!l.mirall, null, 'fantasma-vora'));
      if (!ret && l.tipus !== 'casa') marca(l).forEach(function (n) { posa('marques', n); });     // les cases ja es veuen bé
    } else if (l.tipus === 'casa' || l.tipus === 'element') {
      posa('peces', usa(l.codi, l.x, l.y, !!l.mirall, null, 'fantasma ' + l.tipus + (ret ? ' retingut' : '')));
      if (l.tipus === 'casa' && !ret) posa('peces', usa(l.codi, l.x, l.y, !!l.mirall, null, 'fantasma-vora'));
    }
    return out;
  }

  // Redibuixa només un lloc (en triar colors o girar la figura): amb el pessebre ple, refer tota l'escena (≈2.600 nodes)
  // costava ~1 s per toc en un mòbil lent. Si alguna cosa falla, es refà tota l'escena.
  function dibuixaLloc(id) {
    if (!darrer) return;
    try {
      var l = D.mapa.llocs.filter(function (x) { return x.id === id; })[0];
      var zones = {}, retinguts = {};
      darrer.zones.forEach(function (z) { zones[z] = true; });
      (darrer.r || []).forEach(function (x) { retinguts[x] = true; });
      [capes.peces, capes.tolls, capes.halos, capes.marques].forEach(function (g) {
        Array.prototype.slice.call(g.querySelectorAll('[data-lloc="' + id + '"]')).forEach(function (n) { n.remove(); });
      });
      var fills = capes.peces.children;
      nodesLloc(l, darrer, zones, retinguts).forEach(function (e) {
        if (e.capa !== 'peces') { capes[e.capa].appendChild(e.node); return; }
        e.node.setAttribute('data-k', e.y);
        var abans = null;                     // primer node que va per davant (y més gran): s'hi insereix al davant
        for (var i = 0; i < fills.length; i++) if (+fills[i].getAttribute('data-k') > e.y) { abans = fills[i]; break; }
        capes.peces.insertBefore(e.node, abans);
      });
    } catch (err) {
      dibuixa(darrer);
    }
  }

  // ── Joc del caganer

  var joc = null;            // funció a cridar quan es toca el caganer (només mentre es busca)

  // Amagatall d'avui: un per dia a partir de primer_dia, i torna a començar
  function llocCaganer(s) {
    var c = D.caganer;
    if (!c || !c.llocs.length || !s || !s.data || !D.peces[c.peca]) return null;
    var n = c.llocs.length, i = ((Compon.dies(c.primer_dia, s.data) % n) + n) % n;
    return { x: c.llocs[i][0], y: c.llocs[i][1] };
  }

  // El toc és sobre el caganer? (amb un marge de 10 px de pantalla, perquè és petit)
  function tocaCaganer(sx, sy) {
    var cg = llocCaganer(darrer);
    if (!cg) return false;
    var c = Compon.caixa(D.peces[D.caganer.peca], cg.x, cg.y, false), m = Math.max(2, 10 / Zoom.escala());
    return sx >= c.x0 - m && sx <= c.x1 + m && sy >= c.y0 - m && sy <= c.y1 + m;
  }

  // Pistes: 0 res · 1 s'enfosqueix la meitat on no és · 2 tot menys el quadrant on és · 3 a més, es marca on és.
  // «trobat»: només l'anell. Els quadrants: meitat esquerra o dreta, i a dalt o a baix de la y 500 (el terra va de 230 a 800).
  function pista(nivell) {
    capes.pista.textContent = '';
    var cg = llocCaganer(darrer);
    if (!cg || !nivell) return;
    var esq = cg.x < AMPLE / 2, dalt = cg.y - 10 < 500;
    var ratlla = Math.abs(cg.y - 10 - 500) < 30 ? (dalt ? cg.y + 15 : cg.y - 40) : 500;   // que la figura sencera quedi dins del tros il·luminat
    if (nivell === 1) {
      el('rect', { x: esq ? AMPLE / 2 : 0, y: 0, width: AMPLE / 2, height: ALT, class: 'pista-fosc' }, capes.pista);
      Zoom.centra(AMPLE / 2, ALT / 2, AMPLE);
    } else if (nivell === 2 || nivell === 3) {
      var qx = esq ? 0 : AMPLE / 2, qy = dalt ? 0 : ratlla, qh = dalt ? ratlla : ALT - ratlla;
      el('path', { d: 'M0 0H' + AMPLE + 'V' + ALT + 'H0Z M' + qx + ' ' + qy + 'h' + AMPLE / 2 + 'v' + qh + 'h-' + AMPLE / 2 + 'z',
                   'fill-rule': 'evenodd', class: 'pista-fosc' }, capes.pista);
      if (nivell === 2) Zoom.centra(qx + AMPLE / 4, qy + qh / 2, AMPLE / 2);
    }
    if (nivell === 3 || nivell === 'trobat') {
      el('circle', { cx: cg.x, cy: cg.y - 10, r: 40, class: 'pista-anell' + (nivell === 'trobat' ? ' trobat' : '') }, capes.pista);
      Zoom.centra(cg.x, cg.y - 10, 300);
    }
  }

  // Punt lluminós al peu d'un lloc lliure (persones, animals i elements). Fa la mateixa mida a la pantalla a qualsevol
  // zoom (escala --marca, que posa zoom.js), perquè es vegi encara que la figura sigui petita.
  function marca(l) {
    return [el('circle', { cx: l.x, cy: l.y, r: 10, class: 'marca-ona' }), el('circle', { cx: l.x, cy: l.y, r: 5.5, class: 'marca-punt' })];
  }

  // Un lloc lliure es dibuixa (en transparent) si la seva zona és oberta, amb excepcions per grup.
  function visibleLliure(l, s, zones) {
    if (l.grup === 'poble') return s.classes || op.mode === 'admin';   // cases de classe: durant les tutories (i sempre a l'administració)
    if (l.grup === 'claustre') {
      if (op.mode === 'claustre' && s.claustre) return true;       // el claustre els veu encara que la zona sigui tancada
      return !!s.claustre_alliberat && !!zones[l.zona];             // després de les 22:00 del 21 des, són de les famílies
    }
    return !!zones[l.zona];
  }

  // Marca a terra (persona o animal lliure) només en mode participar i als llocs que pot triar aquest grup.
  // El lloc és dels que pot triar el grup que participa (famílies o claustre)?
  function llocDelGrup(l, s) {
    if (op.mode === 'claustre') return !!s.claustre && (l.grup === 'claustre' || l.grup === 'general');   // la torronada: també els llocs de les famílies
    // L'administració pot afegir figures als mateixos llocs que les famílies
    if (op.mode === 'familia' || op.mode === 'admin') return l.grup === 'general' || (l.grup === 'claustre' && !!s.claustre_alliberat);
    return false;
  }

  // Nom de la classe sobre la placa. Si la peça no porta la placa dibuixada, el visor la dibuixa.
  function textPlaca(peca, l, mirall, nom) {
    var p = Compon.placa(peca, l.x, l.y, mirall);
    var g = el('g', { class: 'placa' });
    if (peca.placa_propia) {
      el('rect', { x: p.x - 1, y: p.y - 1, width: p.w + 2, height: p.h + 2, fill: 'var(--contorn, #3A2820)' }, g);
      el('rect', { x: p.x, y: p.y, width: p.w, height: p.h, fill: 'var(--placa, #F6E6C0)' }, g);
      el('rect', { x: p.x, y: p.y + p.h - 1, width: p.w, height: 1, fill: 'var(--placa-ombra, #D4BC8E)' }, g);
    }
    var t = el('text', { x: p.x + p.w / 2, y: p.y + p.h / 2 + 1.9, class: 'placa-text', 'text-anchor': 'middle' }, g);
    t.textContent = nom;
    if (nom.length > 4) { t.setAttribute('textLength', p.w - 2); t.setAttribute('lengthAdjust', 'spacingAndGlyphs'); }
    return g;
  }

  function mostraComptador(c) {
    var t = Compon.textComptador(c);
    var caixa = document.getElementById('comptador');
    if (!caixa) return;
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

  // Toc sobre l'escena: busca el lloc que hi ha a sota entre els candidats que decideix la pàgina.
  function toc(sx, sy) {
    if (!darrer) return;
    if (joc && tocaCaganer(sx, sy)) { joc(); return; }
    if (!op.enToc) return;
    var candidats = D.mapa.llocs.filter(function (l) { return op.esCandidat(l, darrer); });
    var lliures = candidats.filter(function (l) { return !darrer.o[l.id] && (darrer.r || []).indexOf(l.id) < 0; });
    var ocupats = candidats.filter(function (l) { return darrer.o[l.id]; });
    // Si sota el dit hi ha un lloc lliure i un d'ocupat (figures que se superposen), mana el lliure (a l'administració, l'ocupat)
    var primer = op.mode === 'admin' && !op.filtre ? ocupats : lliures;    // afegint figures a l'administració, també el lliure
    var l = Compon.toca(primer, D.peces, sx, sy) || Compon.toca(candidats, D.peces, sx, sy);
    // Si no hi ha cap figura sota el dit, val tocar el punt d'un lloc lliure (el punt pot ser més gran que la figura)
    if (!l && op.filtre && op.filtre !== 'casa') {
      var radi = 16 / Zoom.escala(), millor = radi;
      lliures.forEach(function (c) {
        var d = Math.hypot(c.x - sx, c.y - sy);
        if (c.tipus === op.filtre && d < millor) { millor = d; l = c; }
      });
    }
    op.enToc(l, darrer);
  }

  // ── API

  // op: { font, mode: null|'familia'|'claustre'|'classes', vista: {x, y, amplada}, refresc: ms,
  //       esCandidat(lloc, s) i enToc(lloc|null, s) per als tocs, enSnapshot(s) }
  function inicia(opcions) {
    op = opcions || {};
    var d = 'disseny/';
    return Promise.all([
      carrega(d + 'peces.svg'), carrega(d + 'terreny.svg'), carrega(d + 'llum-terra.svg'), carrega(d + 'llum-aire.svg'),
      carrega(d + 'mapa.json', 'json'), carrega(d + 'peces.json', 'json'), carrega(d + 'paletes.json', 'json'),
      carrega(d + 'escena-fixa.json', 'json'), carrega(d + 'reis-cami.json', 'json'), carrega(op.font, 'json'),
      carrega(d + 'logo.svg').catch(function () { return null; }),         // opcional
      carrega(d + 'caganer.json', 'json').catch(function () { return null; })
    ]).then(function (r) {
      D = { mapa: r[4], peces: r[5].peces, paletes: r[6], fixa: r[7].peces, reis: r[8], caganer: r[11] };
      construeix({ peces: r[0], terreny: r[1], terra: r[2], aire: r[3], logo: r[10] });
      dibuixa(r[9]);
      if (op.enSnapshot) op.enSnapshot(r[9]);
      if (op.refresc) setInterval(function () { refresca().catch(function () { /* es torna a provar més tard */ }); }, op.refresc);
      return r[9];
    }).catch(function (e) {
      error('No s\'ha pogut carregar el pessebre. Torna-ho a provar d\'aquí a una estona.');
      throw e;
    });
  }

  function refresca() {
    return carrega(op.font, 'json').then(function (s) {
      if (!darrer || s.versio !== darrer.versio) { dibuixa(s); if (op.enSnapshot) op.enSnapshot(s); }
      return s;
    });
  }

  return {
    inicia: inicia,
    refresca: refresca,
    redibuixa: function () { if (darrer) dibuixa(darrer); },
    redibuixaLloc: dibuixaLloc,
    // Mode participar: mostra només els llocs lliures d'un tipus ('persona', 'animal', 'casa', 'element') o cap (null)
    filtre: function (tipus) { op.filtre = tipus; if (darrer) dibuixa(darrer); },
    // Llocs lliures d'un tipus que pot triar el grup que participa
    lliures: function (tipus) {
      if (!darrer) return [];
      var zones = {};
      darrer.zones.forEach(function (z) { zones[z] = true; });
      return D.mapa.llocs.filter(function (l) {
        return l.tipus === tipus && !darrer.o[l.id] && (darrer.r || []).indexOf(l.id) < 0 && visibleLliure(l, darrer, zones) && llocDelGrup(l, darrer);
      });
    },
    posa: function (id, o) { propis[id] = o; },
    treu: function (id) { delete propis[id]; },
    disseny: function () { return D; },
    centra: function (x, y, amplada) { if (svg) Zoom.centra(x, y, amplada); },
    snapshot: function () { return darrer; },
    // Marca un lloc amb un requadre (o cap, amb null)
    // Joc del caganer: hi és avui? · començar (enTrobat) o acabar (null) · pistes (0–3 o 'trobat')
    caganer: {
      hiEs: function () { return !!llocCaganer(darrer); },
      joc: function (enTrobat) { joc = enTrobat || null; if (!joc) pista(0); },
      pista: pista
    },
    destaca: function (l) {
      capes.destacat.textContent = '';
      if (!l) return;
      var o = darrer && darrer.o[l.id];
      var p = D.peces[(o && o.p) || l.codi] || { vb: [18, 27], a: [9, 25], caixa: [0, 0, 18, 27] };
      var c = Compon.caixa(p, l.x, l.y, !!l.mirall);
      el('rect', { x: c.x0 - 3, y: c.y0 - 3, width: c.x1 - c.x0 + 6, height: c.y1 - c.y0 + 6, class: 'requadre-destacat' }, capes.destacat);
    },
    hash: llegeixHash,
    error: error
  };
})();
