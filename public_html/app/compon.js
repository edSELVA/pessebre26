// Composició de l'escena: càlculs purs (sense tocar el DOM).
// Segueix la guia tècnica del paquet de disseny v2.
'use strict';

var Compon = (function () {
  var SUFIX = ['-clar', '', '-ombra', '-fosc'];
  var NEU = '#F2F7FF';
  var GRUP_PALETA = { casa: 'cases', persona: 'persones' };

  // Posició d'una peça amb l'ancoratge a (x, y). Mirall horitzontal: matrix(-1 0 0 1 2x 0).
  function colloca(peca, x, y, mirall) {
    return {
      x: x - peca.a[0], y: y - peca.a[1], w: peca.vb[0], h: peca.vb[1],
      transform: mirall ? 'matrix(-1 0 0 1 ' + (2 * x) + ' 0)' : null
    };
  }

  // Variables CSS d'una ocupació a partir dels índexs de paleta (en l'ordre de peca.pers).
  // grup: 'pastors' als llocs de pastor; si no, el de la categoria de la peça.
  function colors(peca, idx, paletes, grup) {
    var vars = {};
    grup = paletes[grup || GRUP_PALETA[peca.cat]] || {};
    (peca.pers || []).forEach(function (rol, i) {
      var op = grup[rol] && grup[rol].opcions[idx ? idx[i] : 0];
      if (!op) return;
      op.forEach(function (c, j) { vars['--' + rol + SUFIX[j]] = c; });
    });
    return vars;
  }

  // Barreja dos colors #RRGGBB (k = part del segon).
  function barreja(a, b, k) {
    var out = '#';
    for (var i = 1; i < 7; i += 2) {
      var v = Math.round(parseInt(a.substr(i, 2), 16) * (1 - k) + parseInt(b.substr(i, 2), 16) * k);
      out += (v < 16 ? '0' : '') + v.toString(16).toUpperCase();
    }
    return out;
  }

  // Moment «neu»: teulades nevades (tons --teulada* al 82 % de blanc; --teulada-fosc al 60 %).
  function neuTeulada(vars) {
    Object.keys(vars).forEach(function (k) {
      if (k.indexOf('--teulada') === 0) vars[k] = barreja(vars[k], NEU, k === '--teulada-fosc' ? 0.6 : 0.82);
    });
    return vars;
  }

  // Rectangle de la placa en coordenades de l'escena (el text no s'ha de veure en mirall).
  function placa(peca, x, y, mirall) {
    var p = peca.placa;
    if (!p) return null;
    var esq = mirall ? x + peca.a[0] - p[0] - p[2] : x - peca.a[0] + p[0];
    return { x: esq, y: y - peca.a[1] + p[1], w: p[2], h: p[3] };
  }

  // Rectangle opac (caixa del registre) d'una peça col·locada, en coordenades de l'escena.
  function caixa(peca, x, y, mirall) {
    var c = peca.caixa, x0 = x - peca.a[0], y0 = y - peca.a[1];
    var esq = mirall ? x + peca.a[0] - c[2] : x0 + c[0];
    return { x0: esq, y0: y0 + c[1], x1: esq + (c[2] - c[0]), y1: y0 + c[3] };
  }

  // Caixa de toc dels llocs sense peça fixa (persones i animals): la mida d'una figura adulta o d'un animal mitjà.
  var CAIXA_PUNT = { persona: { vb: [18, 27], a: [9, 25], caixa: [2, 0, 16, 26] }, animal: { vb: [22, 16], a: [11, 14], caixa: [0, 0, 22, 16] } };

  // Quin lloc hi ha al punt (sx, sy) de l'escena? Si n'hi ha diversos, el de davant (y més gran). Marge de 2 unitats.
  function toca(llocs, peces, sx, sy) {
    var millor = null;
    llocs.forEach(function (l) {
      var p = peces[l.codi] || CAIXA_PUNT[l.tipus];
      if (!p) return;
      var c = caixa(p, l.x, l.y, !!l.mirall);
      if (sx >= c.x0 - 2 && sx <= c.x1 + 2 && sy >= c.y0 - 2 && sy <= c.y1 + 2 && (!millor || l.y > millor.y)) millor = l;
    });
    return millor;
  }

  // Dies entre dues dates AAAA-MM-DD.
  function dies(desde, fins) {
    var a = desde.split('-'), b = fins.split('-');
    return Math.round((Date.UTC(+b[0], b[1] - 1, +b[2]) - Date.UTC(+a[0], a[1] - 1, +a[2])) / 86400000);
  }

  // Punt del camí a una distància s (interpolació lineal per trams).
  function puntCami(cami, s) {
    var acum = 0;
    for (var i = 1; i < cami.length; i++) {
      var dx = cami[i][0] - cami[i - 1][0], dy = cami[i][1] - cami[i - 1][1];
      var l = Math.sqrt(dx * dx + dy * dy);
      if (acum + l >= s) {
        var t = l ? (s - acum) / l : 0;
        return [cami[i - 1][0] + dx * t, cami[i - 1][1] + dy * t];
      }
      acum += l;
    }
    return cami[cami.length - 1].slice();
  }

  // Posició dels Reis per a una data: avancen un pas cada dia des de primer_dia fins al pas final.
  function reis(rc, data) {
    var pas = Math.max(0, Math.min(rc.passos, dies(rc.primer_dia, data)));
    var primer = Math.max.apply(null, rc.inici);
    var avanc = (rc.final - primer) * pas / rc.passos;
    return rc.inici.map(function (s) {
      var p = puntCami(rc.cami, s + avanc);
      return { x: Math.round(p[0]), y: Math.round(p[1]), pas: pas };
    });
  }

  // Ordre de dibuix: per y creixent (o per «ordre» si la peça en porta); en cas d'empat, l'ordre d'entrada.
  function ordena(llista) {
    return llista
      .map(function (e, i) { return { e: e, k: e.ordre !== undefined ? e.ordre : e.y, i: i }; })
      .sort(function (a, b) { return a.k - b.k || a.i - b.i; })
      .map(function (t) { return t.e; });
  }

  // Text del comptador públic. Mai mostra dates de fites.
  function textComptador(c) {
    if (!c) return null;
    if (c.tipus === 'classes') return { text: c.fet + ' de ' + c.total + ' cases de classe', part: c.total ? c.fet / c.total : 0 };
    var t = c.fet + (c.fet === 1 ? ' participació' : ' participacions');
    if (c.fita) return { text: t + ' · propera fita a ' + c.fita, part: Math.min(1, c.fet / c.fita) };
    return { text: t, part: 1 };
  }

  return {
    colloca: colloca, colors: colors, caixa: caixa, toca: toca, barreja: barreja, neuTeulada: neuTeulada, placa: placa,
    dies: dies, puntCami: puntCami, reis: reis, ordena: ordena, textComptador: textComptador
  };
})();
