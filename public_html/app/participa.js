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
  var filtre = null;          // tipus triat a «Què vols posar?»: persona, animal, casa o element
  var TIPUS = [
    { t: 'persona', nom: 'Una persona', peca: 'PER-ADU-F', on: 'Toca una silueta de persona per posar-hi la teva figura.' },
    { t: 'animal', nom: 'Un animal', peca: 'ANI-OVELLA-D', on: 'Toca una silueta d\'animal per posar-hi el teu.' },
    { t: 'casa', nom: 'Una casa', peca: 'CASA-MASIA-SP', on: 'Toca una casa transparent per pintar-la.' },
    { t: 'element', nom: 'Un arbre o element', peca: 'ELE-ARBRE', on: 'Toca un arbre o un element transparent.' }
  ];
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
    if (l.grup === 'poble' || !filtre || l.tipus !== filtre) return false;      // primer cal triar què es vol posar
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
      var peces = UI.pecesPossibles(D(), l);
      var primera = peces.indexOf(l.codi) >= 0 ? l.codi : peces[0];     // als arbres, comença amb l'espècie del mapa
      tria = { lloc: l, retencio: r.retencio, fins: Date.now() + r.segons * 1000, peca: primera, mirall: l.mirall ? 1 : 0, colors: null, finestres: 0 };
      Visor.centra(l.x, l.y - 20, 240);
      pasTria();
      return Visor.refresca();
    }).catch(function (e) {
      UI.avis(e.message);
      Visor.refresca().catch(function () {});
    });
  }





  function mostraALescena() {
    Visor.posa(tria.lloc.id, { p: tria.peca, c: tria.colors, m: tria.mirall, f: tria.finestres });
    Visor.redibuixa();
  }

  // ── panells

  // Pas 1: què vols posar? Quatre botons amb el dibuix i quants llocs lliures en queden.
  function pasInici() {
    filtre = null;
    Visor.filtre(null);
    if (mode === 'claustre') setTimeout(function () { Visor.centra(345, 380, 520); }, 0);
    var botons = TIPUS.map(function (t) {
      var n = Visor.lliures(t.t).length;
      var mini = UI.previsualitzacio(D().peces);
      mini.pinta(t.peca, {}, false);
      var b = h('button', { type: 'button', class: 'boto-tipus' }, [mini.svg, h('span', { class: 'nom', text: t.nom }),
        h('span', { class: 'quants', text: n === 0 ? 'cap lloc lliure' : (n === 1 ? '1 lloc lliure' : n + ' llocs lliures') })]);
      if (n === 0) b.disabled = true;
      else b.addEventListener('click', function () { pasOn(t); });
      return b;
    });
    UI.mostra([
      h('h2', { text: mode === 'claustre' ? 'Claustre: què vols posar al pessebre?' : 'Què vols posar al pessebre?' }),
      h('div', { class: 'tipus' }, botons)
    ]);
  }

  // Pas 2: on? Només es veuen les siluetes del tipus triat.
  function pasOn(t) {
    filtre = t.t;
    Visor.filtre(t.t);
    UI.mostra([
      h('h2', { text: t.nom }),
      h('p', { text: t.on }),
      h('p', { class: 'suau', text: 'Pots moure el pessebre amb el dit i apropar-t\'hi amb dos dits.' }),
      h('div', { class: 'botons' }, [h('button', { type: 'button', class: 'boto suau', text: '← Una altra cosa', clic: pasInici })])
    ]);
  }

  function pasTria() {
    var l = tria.lloc;
    var compte = h('span', { class: 'compte' });
    var ed = UI.editorPeca(D(), l, tria, mostraALescena);
    var titol = { casa: 'Pinta la teva casa', element: ed.peces.length > 1 ? 'Tria el teu arbre' : 'Aquest lloc serà vostre',
                  persona: 'Tria la teva figura', animal: 'Tria el teu animal' }[l.tipus];
    UI.mostra([
      h('h2', { text: titol }),
      h('p', { class: 'suau' }, ['El lloc és teu durant ', compte, '.']),
      ed.galeria,
      ed.tria,
      h('div', { class: 'botons' }, [
        h('button', { type: 'button', class: 'boto primari', text: 'Confirmar', clic: confirma }),
        h('button', { type: 'button', class: 'boto', text: 'Girar ↔', clic: ed.girar }),
        h('button', { type: 'button', class: 'boto suau', text: 'Un altre lloc', clic: deixa })
      ])
    ]);
    if (aturaCompte) aturaCompte();
    aturaCompte = UI.compteEnrere(compte, tria.fins, caducada);
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
    api(prefix + 'confirmar', { lloc: tria.lloc.id, retencio: tria.retencio, peca: tria.peca, mirall: tria.mirall, colors: tria.colors, finestres: tria.finestres })
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
    filtre = null;
    Visor.filtre(null);
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

  // Primer es carrega el pessebre (calen els llocs lliures per als comptadors), i després es comprova el codi o l'enllaç
  var carregat = Visor.inicia({
    font: /^[a-z0-9-]{1,30}$/.test(hash.s || '') ? 'exemples/' + hash.s + '.json' : 'snapshot.json',
    mode: mode,
    vista: mode === 'claustre' ? { x: 345, y: 390, amplada: 520 } : null,   // el claustre comença a la clariana dels pastors
    refresc: mode ? 30000 : 0,
    esCandidat: mode ? esCandidat : null,
    enToc: mode ? enToc : null
  });
  carregat.catch(function () { /* el missatge ja es mostra */ });

  // «Tick»: si fa més de 15 minuts que el pessebre no s'actualitza, el servidor aplica fites, automàtiques i retencions
  // caducades. Si l'hosting no té PHP (p. ex. la pàgina de prova de GitHub), no passa res.
  if (!hash.s) {
    UI.api({ a: 'tick' }).then(function (r) { if (r.fet) return carregat.then(function () { return Visor.refresca(); }); }).catch(function () {});
  }

  // Pessebre tancat (Reis): ja no s'ofereix participar
  carregat.then(function (s) {
    if (!s.tancat) return;
    document.getElementById('participar').hidden = true;
    if (mode) noPot('El pessebre d\'aquest any ja s\'ha tancat. Moltes gràcies per participar!');
  }).catch(function () {});

  var boto = document.getElementById('participar');
  if (mode) {
    var avisMode = document.getElementById('avis-mode');
    avisMode.textContent = mode === 'claustre' ? 'Participar · claustre' : 'Participar';
    avisMode.hidden = false;
    boto.hidden = true;
    Promise.all([api(mode === 'familia' ? 'codi.validar' : 'claustre.estat'), carregat]).then(function (r) {
      if (r[1].tancat) return;
      if (!r[0].pot) { noPot(r[0].missatge); return; }
      pot = true;
      pasInici();
    }).catch(function (e) {
      if (mode === 'familia' && e.codi && e.codi !== 'tancat') demanaCodi(e.message); else noPot(e.message || 'No s\'ha pogut carregar.');
    });
  } else {
    boto.hidden = false;
    boto.addEventListener('click', function () { Caganer.atura(); demanaCodi(); });
    carregat.then(Caganer.inicia).catch(function () {});        // el joc del caganer, només al visor públic
  }
})();
