// Visor públic (index.html) i mode participar.
//   #c=CODI  famílies (el codi de l'etiqueta; amb el botó «Participar» també es pot escriure)
//   #k=ENLLAÇ claustre
// El que va després del # no arriba mai al servidor en carregar la pàgina; només va dins les peticions POST a api.php.
// Flux: tocar un lloc lliure (retingut 5 minuts) → triar la figura, els colors i cap on mira → confirmar.
'use strict';

(function () {
  var h = UI.h;
  var hash = Visor.hash();
  var mode = hash.c ? 'familia' : (hash.k ? 'claustre' : null);
  var credencial = mode === 'familia' ? { c: hash.c } : (mode === 'claustre' ? { k: hash.k } : {});
  var prefix = mode === 'familia' ? 'codi.' : 'claustre.';
  var tria = null;            // { lloc, retencio, peca, colors, mirall }
  var aturaCompte = null;
  var pot = false;

  function api(accio, dades) {
    var cos = { a: accio };
    Object.keys(credencial).forEach(function (k) { cos[k] = credencial[k]; });
    Object.keys(dades || {}).forEach(function (k) { cos[k] = dades[k]; });
    return UI.api(cos);
  }

  function D() { return Visor.disseny(); }

  // ── qui pot triar què

  function esCandidat(l, s) {
    if (l.grup === 'poble') return false;
    if (mode === 'claustre') return l.grup === 'claustre';
    var zona = s.zones.indexOf(l.zona) >= 0;
    return zona && (l.grup === 'general' || (l.grup === 'claustre' && s.claustre_alliberat));
  }

  function enToc(l, s) {
    if (!pot || tria) return;
    if (!l) return;
    if (s.o[l.id]) { UI.avis('Aquest lloc ja és ocupat. Tria un lloc lliure.'); return; }
    if ((s.r || []).indexOf(l.id) >= 0) { UI.avis('Algú altre està triant aquest lloc ara mateix.'); return; }
    api(prefix + 'retenir', { lloc: l.id }).then(function (r) {
      var peces = pecesPossibles(l);
      tria = { lloc: l, retencio: r.retencio, fins: Date.now() + r.segons * 1000, peca: peces[0], mirall: l.mirall ? 1 : 0, colors: null };
      tria.colors = colorsInicials(tria.peca);
      Visor.centra(l.x, l.y - 20, 240);
      pasTria();
      return Visor.refresca();
    }).catch(function (e) {
      UI.avis(e.message);
      Visor.refresca().catch(function () {});
    });
  }

  // Peces que es poden posar en un lloc: les cases i els elements són els del mapa; persones i animals, a triar.
  function pecesPossibles(l) {
    if (l.tipus === 'casa' || l.tipus === 'element') return [l.codi];
    return Object.keys(D().peces).filter(function (k) { return D().peces[k].cat === l.tipus; }).sort();
  }

  function grupPaleta(peca) {
    var cat = D().peces[peca].cat;
    if (cat === 'casa') return 'cases';
    if (cat === 'animal') return 'animals';
    return tria && tria.lloc.pastor ? 'pastors' : 'persones';
  }

  function paleta(grup, rol) {
    return D().paletes[grup][rol] || D().paletes.persones[rol];
  }

  function colorsInicials(peca) {
    var p = D().peces[peca];
    return p.pers.length ? p.pers.map(function () { return 0; }) : null;
  }

  function mostraALescena() {
    Visor.posa(tria.lloc.id, { p: tria.peca, c: tria.colors, m: tria.mirall });
    Visor.redibuixa();
  }

  // ── panells

  function pasInici() {
    if (mode === 'claustre') setTimeout(function () { Visor.centra(345, 380, 520); }, 0);
    var s = mode === 'claustre'
      ? 'Toca un pastor (les marques de la clariana) o un lloc lliure a prop seu.'
      : 'Toca un lloc lliure: les marques a terra són per a persones o animals; les cases i els arbres transparents també es poden triar.';
    UI.mostra([
      h('h2', { text: mode === 'claustre' ? 'Claustre: tria un lloc' : 'Tria un lloc lliure' }),
      h('p', { text: s }),
      h('p', { class: 'suau', text: 'Pots moure el pessebre amb el dit i apropar-t\'hi amb dos dits.' })
    ]);
  }

  function pasTria() {
    var l = tria.lloc;
    var peces = pecesPossibles(l);
    var prev = UI.previsualitzacio(D().peces);
    var zona = h('div', { class: 'colors' });
    var compte = h('span', { class: 'compte' });

    function pinta() {
      prev.pinta(tria.peca, Compon.colors(D().peces[tria.peca], tria.colors, D().paletes, grupPaleta(tria.peca)), tria.mirall);
      mostraALescena();
    }

    function filesColors() {
      zona.textContent = '';
      var p = D().peces[tria.peca];
      var noms = { facana: 'Façana', teulada: 'Teulada', porta: 'Porta i fusta', flor: 'Flors', roba: 'Roba', mocador: 'Mocador',
                   roba2: 'Roba', pell: 'Pell', cabell: 'Cabells', palla: 'Barret i cistell',
                   camell: 'Pelatge', blanc: 'Pelatge', bru: 'Pelatge', gris: 'Pelatge', rosa: 'Pelatge' };
      p.pers.forEach(function (rol, i) {
        var opcions = paleta(grupPaleta(tria.peca), rol).opcions;
        zona.appendChild(UI.filaColors(noms[rol], opcions, tria.colors[i], function (j) { tria.colors[i] = j; pinta(); }));
      });
    }

    var galeria = null;
    if (peces.length > 1) {
      galeria = h('div', { class: 'galeria', role: 'group', 'aria-label': l.tipus === 'animal' ? 'Animals' : 'Persones' });
      peces.forEach(function (codi) {
        var mini = UI.previsualitzacio(D().peces);
        mini.pinta(codi, Compon.colors(D().peces[codi], colorsInicials(codi), D().paletes, grupPaleta(codi)), false);
        var b = h('button', { type: 'button', class: 'peca-mini', 'aria-pressed': String(codi === tria.peca), 'aria-label': nomPeca(codi) }, [mini.svg]);
        b.addEventListener('click', function () {
          Array.prototype.forEach.call(galeria.children, function (x) { x.setAttribute('aria-pressed', String(x === b)); });
          tria.peca = codi;
          tria.colors = colorsInicials(codi);
          filesColors();
          pinta();
        });
        galeria.appendChild(b);
      });
    }
    filesColors();
    pinta();

    var titol = { casa: 'Pinta la teva casa', element: 'Aquest lloc serà vostre', persona: 'Tria la teva figura', animal: 'Tria el teu animal' }[l.tipus];
    var botons = [h('button', { type: 'button', class: 'boto primari', text: 'Confirmar', clic: confirma })];
    if (l.tipus === 'persona' || l.tipus === 'animal') {
      botons.push(h('button', { type: 'button', class: 'boto', text: 'Girar ↔', clic: function () { tria.mirall = tria.mirall ? 0 : 1; pinta(); } }));
    }
    botons.push(h('button', { type: 'button', class: 'boto suau', text: 'Un altre lloc', clic: deixa }));   // tres botons en una fila
    UI.mostra([
      h('h2', { text: titol }),
      h('p', { class: 'suau' }, ['El lloc és teu durant ', compte, '.']),
      galeria,
      h('div', { class: 'tria' }, [h('div', { class: 'previsualitzacio' }, [prev.svg]), zona]),
      h('div', { class: 'botons' }, botons)
    ]);
    if (aturaCompte) aturaCompte();
    aturaCompte = UI.compteEnrere(compte, tria.fins, caducada);
  }

  function nomPeca(codi) {
    return codi.replace(/^(PER|ANI)-/, '').replace(/-D$/, '').toLowerCase().replace(/-/g, ' ');
  }

  function neteja() {
    if (aturaCompte) aturaCompte();
    aturaCompte = null;
    if (tria) Visor.treu(tria.lloc.id);
    tria = null;
  }

  function caducada() {
    neteja();
    Visor.refresca().catch(function () {});
    UI.avis('S\'ha acabat el temps. Torna a tocar el lloc.');
    pasInici();
  }

  function deixa() {
    var t = tria;
    neteja();
    pasInici();
    api(prefix + 'deixar', { lloc: t.lloc.id, retencio: t.retencio })
      .catch(function () { /* si falla, la retenció caduca sola en 5 minuts */ })
      .then(function () { return Visor.refresca(); }).catch(function () {});
  }

  function confirma() {
    var avisConf = mode === 'familia'
      ? 'Confirmes? Amb aquest codi només es pot posar una figura.'
      : 'Confirmes aquesta figura per al pessebre?';
    if (!window.confirm(avisConf)) return;
    api(prefix + 'confirmar', { lloc: tria.lloc.id, retencio: tria.retencio, peca: tria.peca, mirall: tria.mirall, colors: tria.colors })
      .then(function (r) {
        var id = tria.lloc.id;
        if (aturaCompte) aturaCompte();
        return Visor.refresca().then(function () {
          Visor.treu(id);
          tria = null;
          Visor.redibuixa();
          pasFet(r.zona_nova);
        });
      })
      .catch(function (e) {
        UI.avis(e.message);
        if (e.codi === 'retencio') { neteja(); Visor.refresca().catch(function () {}); pasInici(); }
      });
  }

  function pasFet(zonaNova) {
    pot = false;
    var fills = [
      h('h2', { text: 'Gràcies! Ja sou al pessebre.' }),
      zonaNova ? h('p', { text: 'Amb la vostra participació s\'ha obert una zona nova del pessebre!' }) : null,
      h('p', { class: 'suau', text: mode === 'familia'
        ? 'Si cal canviar alguna cosa, demaneu-ho a porteria o per correu.'
        : 'Podeu posar més figures mentre quedin llocs del claustre.' }),
      h('div', { class: 'botons' }, [h('a', { class: 'boto', href: './', text: 'Veure el pessebre' })])
    ];
    if (mode === 'claustre') { pot = true; fills[3].insertBefore(h('button', { type: 'button', class: 'boto primari', text: 'Posar-ne una altra', clic: pasInici }), fills[3].firstChild); }
    UI.mostra(fills);
  }

  function noPot(missatge) {
    pot = false;
    UI.mostra([h('h2', { text: 'Pessebre de l\'escola' }), h('p', { text: missatge }),
               h('div', { class: 'botons' }, [h('a', { class: 'boto', href: './', text: 'Veure el pessebre' })])]);
  }

  // ── escriure el codi (des del visor públic)

  function demanaCodi(error) {
    var camp = h('input', { type: 'text', id: 'camp-codi', class: 'camp', autocomplete: 'off', autocapitalize: 'characters',
                            spellcheck: 'false', maxlength: '14', placeholder: 'K7M-QX4-P9TR', 'aria-describedby': 'ajuda-codi' });
    var enviar = function (e) {
      e.preventDefault();
      var c = camp.value.toUpperCase().replace(/[\s.\-]/g, '');
      UI.api({ a: 'codi.validar', c: c }).then(function () {
        location.hash = 'c=' + c;
        location.reload();
      }).catch(function (err) { demanaCodi(err.message); });
    };
    var form = h('form', { class: 'form-codi' }, [camp, h('button', { type: 'submit', class: 'boto primari', text: 'Continuar' })]);
    form.addEventListener('submit', enviar);
    UI.mostra([
      h('h2', { text: 'Escriu el codi de la teva etiqueta' }),
      h('label', { for: 'camp-codi', id: 'ajuda-codi', class: 'suau', text: 'Són 10 lletres i xifres. Els guions no cal escriure\'ls.' }),
      form,
      error ? h('p', { class: 'error', role: 'alert', text: error }) : null,
      h('div', { class: 'botons' }, [h('button', { type: 'button', class: 'boto suau', text: 'Tancar', clic: function () { UI.mostra([]); } })])
    ]);
    camp.focus();
  }

  // ── inici

  var boto = document.getElementById('participar');
  if (mode) {
    var avisMode = document.getElementById('avis-mode');
    avisMode.textContent = mode === 'claustre' ? 'Participar · claustre' : 'Participar';
    avisMode.hidden = false;
    boto.hidden = true;
    api(mode === 'familia' ? 'codi.validar' : 'claustre.estat').then(function (r) {
      if (!r.pot) { noPot(r.missatge); return; }
      pot = true;
      pasInici();
    }).catch(function (e) {
      if (mode === 'familia') demanaCodi(e.message); else noPot(e.message);
    });
  } else {
    boto.hidden = false;
    boto.addEventListener('click', function () { demanaCodi(); });
  }

  Visor.inicia({
    font: /^[a-z0-9-]{1,30}$/.test(hash.s || '') ? 'exemples/' + hash.s + '.json' : 'snapshot.json',
    mode: mode,
    vista: mode === 'claustre' ? { x: 345, y: 390, amplada: 520 } : null,   // el claustre comença a la clariana dels pastors
    refresc: mode ? 30000 : 0,
    esCandidat: mode ? esCandidat : null,
    enToc: mode ? enToc : null
  }).catch(function () { /* el missatge ja es mostra */ });
})();
