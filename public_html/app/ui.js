// Peces d'interfície comunes de classes.html i del mode participar: panell inferior, avisos, crides a l'API,
// files de colors i previsualització d'una peça amb llum neutra.
'use strict';

var UI = (function () {
  var NS = 'http://www.w3.org/2000/svg';
  var temporitzador = null;

  // Crea un element HTML. props: atributs, i a més «text» (contingut) i «clic» (funció).
  function h(nom, props, fills) {
    var e = document.createElement(nom);
    Object.keys(props || {}).forEach(function (k) {
      if (k === 'text') e.textContent = props[k];
      else if (k === 'clic') e.addEventListener('click', props[k]);
      else e.setAttribute(k, props[k]);
    });
    (fills || []).forEach(function (f) { if (f) e.appendChild(typeof f === 'string' ? document.createTextNode(f) : f); });
    return e;
  }

  // Omple el panell inferior (o l'amaga si no hi ha res a mostrar).
  function mostra(fills) {
    var full = document.getElementById('full');
    full.textContent = '';
    fills = (fills || []).filter(Boolean);
    fills.forEach(function (f) { full.appendChild(f); });
    full.hidden = fills.length === 0;
    document.body.classList.toggle('amb-full', fills.length > 0);
    full.scrollTop = 0;
  }

  function avis(text) {
    var a = document.getElementById('avis');
    a.textContent = text;
    a.hidden = false;
    clearTimeout(temporitzador);
    temporitzador = setTimeout(function () { a.hidden = true; }, 4500);
  }

  // POST a api.php. Rebutja amb un Error que porta el missatge per a l'usuari i el codi d'error.
  function api(cos) {
    return fetch('api.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(cos),
      credentials: 'omit', cache: 'no-store'
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'El servidor no respon bé. Torna-ho a provar.' }; });
    }, function () {
      return { ok: false, error: 'No hi ha connexió. Comprova la xarxa i torna-ho a provar.' };
    }).then(function (r) {
      if (!r.ok) { var e = new Error(r.error || 'Error'); e.codi = r.codi; throw e; }
      return r;
    });
  }

  // Fila de mostres de color (una per opció de paleta). enTria(j) quan se'n tria una.
  function filaColors(etiqueta, opcions, valor, enTria) {
    var botons = opcions.map(function (tons, j) {
      var b = h('button', { type: 'button', class: 'mostra', 'aria-label': etiqueta + ', opció ' + (j + 1), 'aria-pressed': String(valor === j) });
      b.style.setProperty('background', tons[1]);
      b.addEventListener('click', function () {
        Array.prototype.forEach.call(b.parentNode.children, function (x, k) { x.setAttribute('aria-pressed', String(k === j)); });
        enTria(j);
      });
      return b;
    });
    return h('div', { class: 'fila' }, [h('span', { class: 'etiqueta', text: etiqueta }), h('div', { class: 'mostres' }, botons)]);
  }

  // SVG petit amb una peça del sprite (fora de l'escena: llum neutra). Retorna {svg, pinta(codi, vars, mirall)}.
  function previsualitzacio(peces) {
    var svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('shape-rendering', 'crispEdges');
    svg.setAttribute('aria-hidden', 'true');
    var u = document.createElementNS(NS, 'use');
    svg.appendChild(u);
    function pinta(codi, vars, mirall) {
      var p = peces[codi];
      svg.setAttribute('viewBox', '0 0 ' + p.vb[0] + ' ' + p.vb[1]);
      u.setAttribute('href', '#pc-' + codi);
      u.setAttribute('width', p.vb[0]);
      u.setAttribute('height', p.vb[1]);
      if (mirall) u.setAttribute('transform', 'matrix(-1 0 0 1 ' + p.vb[0] + ' 0)'); else u.removeAttribute('transform');
      u.removeAttribute('style');
      Object.keys(vars || {}).forEach(function (k) { u.style.setProperty(k, vars[k]); });
    }
    return { svg: svg, pinta: pinta };
  }

  // Previsualització gran d'una casa, retallada a la part dibuixada, amb les finestres que es toquen per treure-les
  // (hi queda paret) o tornar-les a posar. estat: {mirall, finestres (màscara de bits)}; vars(): variables de color;
  // enCanvi(): es crida quan es toca una finestra. Retorna {svg, pinta()}.
  function previsualitzacioCasa(peca, codi, estat, vars, enCanvi) {
    var finestres = peca.finestres || [];
    var dib = peca.dibuix || [0, 0, peca.vb[0], peca.vb[1]];
    var svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('shape-rendering', 'crispEdges');
    var g = document.createElementNS(NS, 'g');
    var zones = document.createElementNS(NS, 'g');
    svg.appendChild(g);
    svg.appendChild(zones);
    function pinta() {
      var v = vars();
      var x0 = estat.mirall ? peca.vb[0] - dib[0] - dib[2] : dib[0];
      svg.setAttribute('viewBox', (x0 - 1) + ' ' + (dib[1] - 1) + ' ' + (dib[2] + 2) + ' ' + (dib[3] + 2));
      g.setAttribute('transform', estat.mirall ? 'matrix(-1 0 0 1 ' + peca.vb[0] + ' 0)' : '');
      g.textContent = '';
      [codi].concat(Compon.finestresTapades(estat.finestres).map(function (i) { return codi + '-F' + i; })).forEach(function (sim) {
        var u = document.createElementNS(NS, 'use');
        u.setAttribute('href', '#pc-' + sim);
        u.setAttribute('width', peca.vb[0]);
        u.setAttribute('height', peca.vb[1]);
        Object.keys(v).forEach(function (k) { u.style.setProperty(k, v[k]); });
        g.appendChild(u);
      });
      zones.textContent = '';
      finestres.forEach(function (f, i) {
        var treta = (estat.finestres & (1 << i)) !== 0;
        var r = document.createElementNS(NS, 'rect');
        r.setAttribute('x', (estat.mirall ? peca.vb[0] - f[0] - f[2] : f[0]) - 0.5);
        r.setAttribute('y', f[1] - 0.5);
        r.setAttribute('width', f[2] + 1);
        r.setAttribute('height', f[3] + 1);
        r.setAttribute('class', 'zona-finestra' + (treta ? ' treta' : ''));
        r.setAttribute('role', 'button');
        r.setAttribute('aria-label', 'Finestra ' + (i + 1) + (treta ? ', treta' : ''));
        r.addEventListener('click', function () { estat.finestres ^= (1 << i); enCanvi(); });
        zones.appendChild(r);
      });
    }
    return { svg: svg, pinta: pinta, teFinestres: finestres.length > 0 };
  }

  // ── Editor d'una peça (famílies, claustre i administració)

  var NOMS_ROL = { facana: 'Façana', teulada: 'Teulada', porta: 'Porta i fusta', flor: 'Flors', roba: 'Roba', mocador: 'Mocador',
                   roba2: 'Roba', pell: 'Pell', cabell: 'Cabells', palla: 'Barret i cistell', camell: 'Pelatge', blanc: 'Pelatge',
                   bru: 'Pelatge', gris: 'Pelatge', rosa: 'Pelatge', fruit: 'Fruits', groc: 'Fruits' };

  // Peces que es poden posar en un lloc: la casa és la del mapa (o la que ja hi ha); els arbres i les mates, del seu grup;
  // persones i animals, qualsevol de la seva categoria.
  function pecesPossibles(D, l, actual) {
    if (l.tipus === 'casa') return [actual || l.codi];
    if (l.tipus === 'element') return D.peces[l.codi].alternatives || [l.codi];
    return Object.keys(D.peces).filter(function (k) { return D.peces[k].cat === l.tipus; }).sort();
  }

  function grupPaleta(D, l, codi) {
    var cat = D.peces[codi].cat;
    return { casa: 'cases', animal: 'animals', element: 'elements' }[cat] || (l.pastor ? 'pastors' : 'persones');
  }

  function colorsInicials(D, codi) {
    var p = D.peces[codi];
    return p.pers.length ? p.pers.map(function () { return 0; }) : null;
  }

  function nomPeca(codi) {
    return codi.replace(/^(PER|ANI|ELE|CASA)-/, '').replace(/-D$/, '').toLowerCase().replace(/-/g, ' ');
  }

  // est: {peca, colors, mirall, finestres}. enCanvi() es crida a cada canvi (per actualitzar l'escena).
  // Retorna {galeria, tria, pinta, girar, peces}: galeria (o null) i tria són elements per posar al panell.
  function editorPeca(D, l, est, enCanvi) {
    var peces = pecesPossibles(D, l, l.tipus === 'casa' ? est.peca : null);
    if (!est.colors) est.colors = colorsInicials(D, est.peca);
    var zona = h('div', { class: 'colors' });
    var colorsAra = function () { return Compon.colors(D.peces[est.peca], est.colors, D.paletes, grupPaleta(D, l, est.peca)); };
    // Les cases es veuen grans i amb les finestres que es poden treure; la resta, en una previsualització petita
    var casa = l.tipus === 'casa' ? previsualitzacioCasa(D.peces[est.peca], est.peca, est, colorsAra, function () { pinta(); }) : null;
    var prev = casa ? null : previsualitzacio(D.peces);

    function pinta() {
      if (casa) casa.pinta(); else prev.pinta(est.peca, colorsAra(), est.mirall);
      enCanvi();
    }

    function filesColors() {
      zona.textContent = '';
      var grup = grupPaleta(D, l, est.peca);
      D.peces[est.peca].pers.forEach(function (rol, i) {
        var paleta = D.paletes[grup][rol] || D.paletes.persones[rol];
        zona.appendChild(filaColors(NOMS_ROL[rol] || rol, paleta.opcions, est.colors[i], function (j) { est.colors[i] = j; pinta(); }));
      });
    }

    var galeria = null;
    if (peces.length > 1) {
      galeria = h('div', { class: 'galeria', role: 'group', 'aria-label': { animal: 'Animals', persona: 'Persones', element: 'Arbres' }[l.tipus] });
      peces.forEach(function (codi) {
        var mini = previsualitzacio(D.peces);
        mini.pinta(codi, Compon.colors(D.peces[codi], colorsInicials(D, codi), D.paletes, grupPaleta(D, l, codi)), false);
        var b = h('button', { type: 'button', class: 'peca-mini', 'aria-pressed': String(codi === est.peca), 'aria-label': nomPeca(codi) }, [mini.svg]);
        b.addEventListener('click', function () {
          Array.prototype.forEach.call(galeria.children, function (x) { x.setAttribute('aria-pressed', String(x === b)); });
          est.peca = codi;
          est.colors = colorsInicials(D, codi);
          filesColors();
          pinta();
        });
        galeria.appendChild(b);
      });
    }
    filesColors();
    pinta();

    var tria = h('div', { class: 'tria' }, [
      casa ? h('div', { class: 'columna-casa' }, [
        h('div', { class: 'previsualitzacio gran' }, [casa.svg]),
        casa.teFinestres ? h('p', { class: 'peu-casa', text: 'Toca una finestra per treure-la (hi quedarà paret) o tornar-la a posar.' }) : null
      ]) : h('div', { class: 'previsualitzacio' }, [prev.svg]),
      zona
    ]);
    return { galeria: galeria, tria: tria, pinta: pinta, peces: peces,
             girar: function () { est.mirall = est.mirall ? 0 : 1; pinta(); } };
  }

  // Compte enrere en m:ss dins d'un element. Crida enFi() quan arriba a zero. Retorna una funció per aturar-lo.
  function compteEnrere(el, fins, enFi) {
    var tic = function () {
      var s = Math.max(0, Math.round((fins - Date.now()) / 1000));
      el.textContent = Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
      if (s <= 0) { clearInterval(id); enFi(); }
    };
    var id = setInterval(tic, 1000);
    tic();
    return function () { clearInterval(id); };
  }

  return { h: h, mostra: mostra, avis: avis, api: api, filaColors: filaColors, previsualitzacio: previsualitzacio,
           previsualitzacioCasa: previsualitzacioCasa, editorPeca: editorPeca, pecesPossibles: pecesPossibles,
           nomPeca: nomPeca, compteEnrere: compteEnrere };
})();
