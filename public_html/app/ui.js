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

  return { h: h, mostra: mostra, avis: avis, api: api, filaColors: filaColors, previsualitzacio: previsualitzacio, compteEnrere: compteEnrere };
})();
