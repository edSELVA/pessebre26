// Zoom i desplaçament: un dit per moure, dos dits per fer zoom, roda del ratolí, doble toc i botons.
// Mentre dura el gest només es mou amb transform (ràpid); en acabar es fixa la mida real de l'SVG
// perquè es torni a dibuixar nítid.
'use strict';

var Zoom = (function () {
  var AMPLE = 2400, ALT = 800, K_MAX = 6, CENTRE_INICIAL = 1400;
  var cont, svg;
  var v = { k: 1, x: 0, y: 0 };    // escala (px per unitat) i posició de l'origen de l'escena (px)
  var kDibuix = 1;                 // escala a què està dibuixat l'SVG
  var dits = {}, gest = null, temps = null;
  var opc = {}, toc = null;        // toc: on i quan ha començat un toc d'un sol dit

  function mida() { return { w: cont.clientWidth, h: cont.clientHeight }; }
  function kMin() { var m = mida(); return Math.min(m.w / AMPLE, m.h / ALT); }

  function limita() {
    var m = mida();
    v.k = Math.max(kMin(), Math.min(K_MAX, v.k));
    var w = AMPLE * v.k, h = ALT * v.k;
    v.x = w <= m.w ? (m.w - w) / 2 : Math.min(0, Math.max(m.w - w, v.x));
    v.y = h <= m.h ? (m.h - h) / 2 : Math.min(0, Math.max(m.h - h, v.y));
  }

  function aplica() {
    limita();
    svg.style.transform = 'translate(' + v.x + 'px,' + v.y + 'px) scale(' + (v.k / kDibuix) + ')';
    clearTimeout(temps);
    temps = setTimeout(fixa, 150);
  }

  function fixa() {
    if (gest) return;
    kDibuix = v.k;
    svg.setAttribute('width', Math.round(AMPLE * kDibuix));
    svg.setAttribute('height', Math.round(ALT * kDibuix));
    svg.style.transform = 'translate(' + v.x + 'px,' + v.y + 'px)';
  }

  // Zoom mantenint quiet el punt (px, py) de la pantalla.
  function zoomA(px, py, k) {
    var sx = (px - v.x) / v.k, sy = (py - v.y) / v.k;
    v.k = Math.max(kMin(), Math.min(K_MAX, k));
    v.x = px - sx * v.k;
    v.y = py - sy * v.k;
    aplica();
  }

  function vistaInicial() {
    var m = mida();
    if (opc.vista) { centra(opc.vista.x, opc.vista.y, opc.vista.amplada); return; }
    v.k = Math.max(m.w / AMPLE, m.h / ALT);         // omple la pantalla
    v.x = m.w / 2 - CENTRE_INICIAL * v.k;
    v.y = (m.h - ALT * v.k) / 2;
    aplica();
    fixa();
  }

  // Centra la vista al punt (x, y) de l'escena, amb una amplada visible d'«amplada» unitats (opcional).
  // Si el panell inferior és obert, el punt queda a la part de dalt (30 %) perquè no quedi tapat.
  function centra(x, y, amplada) {
    var m = mida();
    var alcada = document.body.classList.contains('amb-full') ? 0.3 : 0.5;
    if (amplada) v.k = Math.max(m.h / ALT, m.w / amplada);
    v.x = m.w / 2 - x * v.k;
    v.y = m.h * alcada - y * v.k;
    aplica();
    fixa();
  }

  function punt(e) { var r = cont.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; }

  function iniciGest() {
    var ids = Object.keys(dits);
    if (ids.length === 1) {
      gest = { tipus: 'mou', p: dits[ids[0]], x: v.x, y: v.y };
    } else if (ids.length >= 2) {
      var a = dits[ids[0]], b = dits[ids[1]];
      var c = { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
      gest = { tipus: 'pinça', d: Math.hypot(a.x - b.x, a.y - b.y) || 1, k: v.k,
               sx: (c.x - v.x) / v.k, sy: (c.y - v.y) / v.k };
    } else {
      gest = null;
      fixa();
    }
  }

  // opcions: { vista: {x, y, amplada}, enToc(sx, sy) en coordenades de l'escena }
  function inicia(contenidor, element, opcions) {
    cont = contenidor;
    svg = element;
    opc = opcions || {};
    vistaInicial();

    cont.addEventListener('pointerdown', function (e) {
      cont.setPointerCapture(e.pointerId);
      dits[e.pointerId] = punt(e);
      toc = Object.keys(dits).length === 1 ? { p: punt(e), t: Date.now(), id: e.pointerId } : null;
      iniciGest();
    });
    cont.addEventListener('pointermove', function (e) {
      if (!dits[e.pointerId] || !gest) return;
      dits[e.pointerId] = punt(e);
      var ids = Object.keys(dits);
      if (gest.tipus === 'mou') {
        var p = dits[ids[0]];
        v.x = gest.x + p.x - gest.p.x;
        v.y = gest.y + p.y - gest.p.y;
      } else {
        var a = dits[ids[0]], b = dits[ids[1]];
        var c = { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
        v.k = Math.max(kMin(), Math.min(K_MAX, gest.k * Math.hypot(a.x - b.x, a.y - b.y) / gest.d));
        v.x = c.x - gest.sx * v.k;
        v.y = c.y - gest.sy * v.k;
      }
      aplica();
    });
    ['pointerup', 'pointercancel'].forEach(function (nom) {
      cont.addEventListener(nom, function (e) {
        var p = punt(e);
        // Toc curt sense moure's: es passa a la pàgina en coordenades de l'escena
        if (nom === 'pointerup' && toc && toc.id === e.pointerId && opc.enToc && Date.now() - toc.t < 600
            && Math.hypot(p.x - toc.p.x, p.y - toc.p.y) < 8) {
          opc.enToc((p.x - v.x) / v.k, (p.y - v.y) / v.k);
        }
        toc = null;
        delete dits[e.pointerId];
        iniciGest();
      });
    });
    cont.addEventListener('wheel', function (e) {
      e.preventDefault();
      var p = punt(e);
      zoomA(p.x, p.y, v.k * Math.exp(-e.deltaY * 0.0015));
    }, { passive: false });
    cont.addEventListener('dblclick', function (e) { var p = punt(e); zoomA(p.x, p.y, v.k * 2); });
    window.addEventListener('resize', function () { aplica(); });
  }

  function botons(mes, menys, tot) {
    var centre = function (f) { var m = mida(); zoomA(m.w / 2, m.h / 2, v.k * f); };
    mes.addEventListener('click', function () { centre(1.6); });
    menys.addEventListener('click', function () { centre(1 / 1.6); });
    tot.addEventListener('click', function () { v.k = kMin(); aplica(); });
  }

  return { inicia: inicia, botons: botons, centra: centra };
})();
