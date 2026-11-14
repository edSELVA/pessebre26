// Pàgina dels tutors (classes.html#c=CODI D'ETAPA, §30): el mateix codi per a tots els tutors de l'etapa. 1) triar la classe
// de la llista de l'etapa, 2) tocar una casa transparent del casc antic, 3) triar façana, teulada i porta amb llum neutra,
// 4) confirmar. La casa queda retinguda 10 minuts mentre es tria. S'hi arriba escrivint el codi al botó «Participar» del
// pessebre (o amb el QR o l'enllaç del codi).
// El codi va després del #: el navegador no l'envia mai en carregar la pàgina; només va dins les peticions POST a api.php.
'use strict';

(function () {
  var NS = 'http://www.w3.org/2000/svg';
  var codi = Visor.hash().c || '';
  var full = document.getElementById('full');
  var estat = { etapa: '', classes: [], classe: null, lloc: null, retencio: null, caduca: 0, colors: [], mirall: 0, finestres: 0 };
  var rellotge = null, temporitzadorAvis = null;

  // ── utilitats

  function h(nom, props, fills) {
    var e = document.createElement(nom);
    Object.keys(props || {}).forEach(function (k) {
      if (k === 'text') e.textContent = props[k];
      else if (k === 'clic') e.addEventListener('click', props[k]);
      else e.setAttribute(k, props[k]);
    });
    (fills || []).forEach(function (f) { if (f) e.appendChild(f); });
    return e;
  }

  function mostra(fills) {
    full.textContent = '';
    fills.forEach(function (f) { if (f) full.appendChild(f); });
    full.scrollTop = 0;
  }

  function avis(text) {
    var a = document.getElementById('avis');
    a.textContent = text;
    a.hidden = false;
    clearTimeout(temporitzadorAvis);
    temporitzadorAvis = setTimeout(function () { a.hidden = true; }, 4000);
  }

  function api(accio, dades) {
    var cos = { a: accio, c: codi };
    Object.keys(dades || {}).forEach(function (k) { cos[k] = dades[k]; });
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

  function lloc(id) {
    return Visor.disseny().mapa.llocs.filter(function (l) { return l.id === id; })[0];
  }

  // ── pas 1: triar la classe

  function pasClasse() {
    estat.classe = null;
    var pendents = estat.classes.filter(function (c) { return !c.feta; });
    var botons = estat.classes.map(function (c) {
      var b = h('button', { type: 'button', class: 'boto', text: c.nom + (c.feta ? ' ✓' : '') });
      if (c.feta) { b.disabled = true; b.setAttribute('aria-label', c.nom + ', ja té casa'); }
      else b.addEventListener('click', function () { estat.classe = c; pasCasa(); });
      return b;
    });
    mostra([
      h('h2', { text: pendents.length ? 'Quina és la teva classe?' : 'Totes les classes ja tenen casa' }),
      h('p', { class: 'suau', text: pendents.length
        ? 'Tria-la de la llista. Les que porten ✓ ja tenen la casa al pessebre.'
        : 'Aquesta etapa ja ha acabat. Gràcies!' }),
      h('div', { class: 'llista-classes' }, botons),
      h('div', { class: 'botons' }, [h('a', { class: 'boto suau', href: './', text: 'Veure el pessebre' })])
    ]);
  }

  // ── pas 2: tocar una casa

  function pasCasa() {
    mostra([
      h('h2', { text: estat.classe.nom + ': tria una casa' }),
      h('p', { text: 'Toca una casa transparent del poble, al mig del pessebre. Pots apropar-t\'hi amb dos dits.' }),
      h('div', { class: 'botons' }, [h('button', { type: 'button', class: 'boto suau', text: 'Canviar de classe', clic: pasClasse })])
    ]);
    Visor.centra(1130, 400, 620);
  }

  function enToc(l, s) {
    if (!estat.classe || estat.retencio) return;          // primer cal triar la classe; durant la tria de colors no
    if (!l) return;
    if (s.o[l.id]) { avis('Aquesta casa ja és d\'una altra classe.'); return; }
    // Si la casa és retinguda, ho decideix el servidor: pot ser la mateixa classe que torna (p. ex. després de recarregar).
    api('classes.retenir', { classe: estat.classe.id, lloc: l.id }).then(function (r) {
      estat.lloc = l;
      estat.retencio = r.retencio;
      estat.caduca = Date.now() + r.segons * 1000;
      estat.colors = Visor.disseny().peces[l.codi].pers.map(function () { return 0; });
      estat.mirall = l.mirall ? 1 : 0;
      estat.finestres = 0;
      Visor.centra(l.x, l.y - 30, 260);
      pasColors();
      return Visor.refresca();
    }).catch(function (e) {
      avis(e.message);
      Visor.refresca().catch(function () {});
    });
  }

  // ── pas 3: triar els colors

  function pasColors() {
    var l = estat.lloc;
    var D = Visor.disseny();
    var peca = D.peces[l.codi];
    var finestres = peca.finestres || [];
    actualitzaLocal();

    // Previsualització gran amb llum neutra (fora de l'escena), amb les finestres que es poden treure
    var casa = UI.previsualitzacioCasa(peca, l.codi, estat, function () { return Compon.colors(peca, estat.colors, D.paletes); }, function () { pinta(); });
    var prev = casa.svg;
    function pinta() { casa.pinta(); actualitzaLocal(); }
    pinta();

    var noms = { facana: 'Façana', teulada: 'Teulada', porta: 'Porta i fusta', flor: 'Flors' };
    var files = peca.pers.map(function (rol, i) {
      return UI.filaColors(noms[rol] || rol, D.paletes.cases[rol].opcions, estat.colors[i], function (j) { estat.colors[i] = j; pinta(); });
    });

    var compte = h('span', { class: 'compte' });
    mostra([
      h('h2', { text: 'La casa de ' + estat.classe.nom }),
      h('p', { class: 'suau' }, [document.createTextNode('Tria-la al vostre gust. La casa és vostra durant '), compte, document.createTextNode('.')]),
      h('div', { class: 'tria' }, [
        h('div', { class: 'columna-casa' }, [
          h('div', { class: 'previsualitzacio gran' }, [prev]),
          finestres.length ? h('p', { class: 'peu-casa', text: 'Toca una finestra per treure-la (hi quedarà paret) o tornar-la a posar.' }) : null
        ]),
        h('div', { class: 'colors' }, files)
      ]),
      h('div', { class: 'botons' }, [
        h('button', { type: 'button', class: 'boto primari', text: 'Confirmar', clic: confirma }),
        h('button', { type: 'button', class: 'boto', text: 'Girar ↔', clic: function () { estat.mirall = estat.mirall ? 0 : 1; pinta(); } }),
        h('button', { type: 'button', class: 'boto suau', text: 'Una altra casa', clic: deixa })
      ])
    ]);
    clearInterval(rellotge);
    var tic = function () {
      var s = Math.max(0, Math.round((estat.caduca - Date.now()) / 1000));
      compte.textContent = Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
      if (s <= 0) caducada();
    };
    tic();
    rellotge = setInterval(tic, 1000);
  }

  // Mostra la casa triada a l'escena tal com va quedant (encara no està confirmada)
  function actualitzaLocal() {
    if (!estat.lloc) return;
    Visor.posa(estat.lloc.id, { p: estat.lloc.codi, c: estat.colors.slice(), n: estat.classe.nom, m: estat.mirall, f: estat.finestres });
    Visor.redibuixa();
  }

  function netejaTria() {
    clearInterval(rellotge);
    if (estat.lloc) Visor.treu(estat.lloc.id);
    estat.lloc = null;
    estat.retencio = null;
  }

  function caducada() {
    netejaTria();
    Visor.refresca().catch(function () {});
    avis('S\'ha acabat el temps per triar. Torna a tocar la casa.');
    pasCasa();
  }

  function deixa() {
    var l = estat.lloc, t = estat.retencio;
    netejaTria();
    pasCasa();
    api('classes.deixar', { classe: estat.classe.id, lloc: l.id, retencio: t })
      .catch(function () { /* si falla, la retenció caduca sola en 10 minuts */ })
      .then(function () { return Visor.refresca(); }).catch(function () {});
  }

  function confirma() {
    if (!window.confirm('Confirmes la casa de ' + estat.classe.nom + '? Després només la coordinació la pot canviar.')) return;
    api('classes.confirmar', { classe: estat.classe.id, lloc: estat.lloc.id, retencio: estat.retencio, colors: estat.colors,
                               mirall: estat.mirall, finestres: estat.finestres })
      .then(function () {
        var nom = estat.classe.nom;
        clearInterval(rellotge);
        estat.retencio = null;
        estat.classe.feta = true;
        return Visor.refresca().then(function () {
          if (estat.lloc) Visor.treu(estat.lloc.id);
          estat.lloc = null;
          Visor.redibuixa();
          pasFet(nom);
        });
      })
      .catch(function (e) {
        avis(e.message);
        if (e.codi === 'retencio' || e.codi === 'tancat') { netejaTria(); Visor.refresca().catch(function () {}); pasCasa(); }
      });
  }

  // ── pas 4: fet

  function pasFet(nom) {
    mostra([
      h('h2', { text: 'Fet! La casa de ' + nom + ' ja és al pessebre.' }),
      h('p', { class: 'suau', text: 'Gràcies. Si cal canviar alguna cosa, demaneu-ho a la coordinació.' }),
      h('div', { class: 'botons' }, [
        h('button', { type: 'button', class: 'boto', text: 'Fer la casa d\'una altra classe', clic: pasClasse }),
        h('a', { class: 'boto suau', href: './', text: 'Veure el pessebre' })
      ])
    ]);
  }

  // ── inici

  function errorEnllac(missatge) {
    mostra([h('h2', { text: 'No es pot continuar' }), h('p', { text: missatge }),
            h('div', { class: 'botons' }, [h('a', { class: 'boto suau', href: './', text: 'Veure el pessebre' })])]);
  }

  var carregat = Visor.inicia({
    font: 'snapshot.json',
    mode: 'classes',
    vista: { x: 1130, y: 420, amplada: 900 },
    refresc: 30000,
    esCandidat: function (l) { return l.grup === 'poble'; },
    enToc: enToc
  });
  carregat.catch(function () { /* el missatge ja es mostra */ });

  if (!codi) {
    errorEnllac('Falta el codi de l\'etapa. Escriu-lo al botó «Participar» del pessebre.');
  } else {
    Promise.all([api('classes.estat'), carregat]).then(function (r) {
      estat.etapa = r[0].etapa;
      estat.classes = r[0].classes;
      var avisMode = document.getElementById('avis-mode');
      avisMode.textContent = 'Tutors · ' + r[0].etapa;
      avisMode.hidden = false;
      if (!r[0].obert) { errorEnllac('El període per triar les cases de classe ja s\'ha tancat.'); return; }
      pasClasse();
    }).catch(function (e) { errorEnllac(e.message); });
  }
})();
