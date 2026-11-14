// Administració (admin.html). Comptes personals (§31): l'usuari i la contrasenya es demanen a cada càrrega, es guarden
// només en aquestes variables i s'envien a cada acció. Amb un compte de només lectura es veu tot però els botons de canvi
// no hi són (i el servidor ho refusa).
'use strict';

(function () {
  var h = UI.h;
  var usuari = null, contrasenya = null;
  var estat = null;              // resposta d'admin.estat
  var pestanya = 'lloc';
  var sel = null;                // lloc seleccionat i la seva informació
  var visorCarregat = null;
  var afegint = null;            // tipus de figura que l'administració està afegint (persona, animal, casa, element) o null
  var TIPUS = [{ t: 'persona', nom: 'Persones' }, { t: 'animal', nom: 'Animals' }, { t: 'casa', nom: 'Cases' }, { t: 'element', nom: 'Arbres i elements' }];
  var cont = document.getElementById('contingut');

  function admin() { return estat && estat.rol === 'admin'; }
  function D() { return Visor.disseny(); }

  function api(accio, dades) {
    var cos = { a: accio, usuari: usuari, contrasenya: contrasenya };
    Object.keys(dades || {}).forEach(function (k) { cos[k] = dades[k]; });
    return UI.api(cos).catch(function (e) {
      if (e.codi === 'contrasenya') surt('L\'usuari o la contrasenya ja no són vàlids.');
      throw e;
    });
  }

  function mostra(fills) {
    cont.textContent = '';
    fills.filter(Boolean).forEach(function (f) { cont.appendChild(f); });
    cont.scrollTop = 0;
  }

  function fila(etiqueta, valor) {
    return h('div', { class: 'fila-dada' }, [h('span', { class: 'etiqueta', text: etiqueta }), h('span', { text: valor === null || valor === undefined ? '—' : String(valor) })]);
  }

  function boto(text, clic, classe) {
    return h('button', { type: 'button', class: 'boto ' + (classe || ''), text: text, clic: clic });
  }

  function refresca() {
    return Promise.all([api('admin.estat').then(function (r) { estat = r; }), Visor.refresca()]);
  }

  // ── entrada i sortida

  function surt(missatge) {
    usuari = contrasenya = null;
    estat = null;
    document.getElementById('panell').hidden = true;
    document.getElementById('sortir').hidden = true;
    document.getElementById('avis-mode').hidden = true;
    document.getElementById('entrada').hidden = false;
    document.getElementById('error-entrada').textContent = missatge || '';
    document.getElementById('contrasenya').value = '';
    document.getElementById('usuari').focus();
  }

  document.getElementById('form-entrada').addEventListener('submit', function (e) {
    e.preventDefault();
    usuari = document.getElementById('usuari').value.trim().toLowerCase();
    contrasenya = document.getElementById('contrasenya').value;
    document.getElementById('contrasenya').value = '';
    UI.api({ a: 'admin.estat', usuari: usuari, contrasenya: contrasenya }).then(function (r) {
      estat = r;
      document.getElementById('entrada').hidden = true;
      document.getElementById('panell').hidden = false;
      document.getElementById('sortir').hidden = false;
      var b = document.getElementById('avis-mode');
      b.textContent = r.nom + ' · ' + (r.rol === 'admin' ? 'administració' : 'només lectura');
      b.hidden = false;
      if (!visorCarregat) {
        visorCarregat = Visor.inicia({ font: 'snapshot.json', mode: 'admin', refresc: 60000,
          // Es poden tocar els llocs ocupats o retinguts i les cases i els elements (també els lliures, que es veuen transparents)
          // Afegint figures, només els llocs del tipus triat (els lliures dels altres tipus no es dibuixen)
          esCandidat: function (l, s) {
            if (afegint) return l.tipus === afegint;
            return !!s.o[l.id] || (s.r || []).indexOf(l.id) >= 0 || l.tipus === 'casa' || l.tipus === 'element';
          },
          enToc: function (l) { if (l) tria(l); } });
      }
      obre('estat');
    }).catch(function (err) {
      usuari = contrasenya = null;
      document.getElementById('error-entrada').textContent = err.message;
    });
  });
  document.getElementById('sortir').addEventListener('click', function () { location.reload(); });

  Array.prototype.forEach.call(document.querySelectorAll('.pestanyes button'), function (b) {
    b.addEventListener('click', function () { obre(b.getAttribute('data-p')); });
  });

  function obre(p) {
    pestanya = p;
    Array.prototype.forEach.call(document.querySelectorAll('.pestanyes button'), function (b) {
      b.setAttribute('aria-selected', String(b.getAttribute('data-p') === p));
    });
    ({ lloc: pLloc, estat: pEstat, codis: pCodis, enllacos: pEnllacos, config: pConfig, fites: pFites, registre: pRegistre, comptes: pComptes })[p]();
  }

  // ── Lloc

  function tria(l) {
    api('admin.lloc', { lloc: l.id }).then(function (r) {
      sel = r;
      Visor.destaca(l);
      // Afegint figures: en tocar una silueta lliure del tipus triat, s'obre directament l'editor
      if (afegint && !r.ocupacio && l.tipus === afegint && afegible(l)) { pestanya = 'lloc'; edita(l, null, true); return; }
      obre('lloc');
    }).catch(function (e) { UI.avis(e.message); });
  }

  // Un lloc lliure on l'administració pot afegir una figura: el que podria triar una família
  function afegible(l) {
    var s = Visor.snapshot();
    return !!s && !s.tancat && s.zones.indexOf(l.zona) >= 0 && (l.grup === 'general' || (l.grup === 'claustre' && s.claustre_alliberat));
  }

  function afegeix(tipus) {
    afegint = tipus;
    Visor.filtre(tipus);
    sel = null;
    Visor.destaca(null);
    obre('lloc');
  }

  // Afegir figures (compten com a participació): primer el tipus, després es toca una silueta
  function blocAfegir() {
    var s = Visor.snapshot();
    if (!admin() || !s || s.tancat) return null;
    if (afegint) {
      var nom = TIPUS.filter(function (t) { return t.t === afegint; })[0].nom.toLowerCase();
      return h('div', { class: 'afegir' }, [
        h('h3', { text: 'Afegint ' + nom }),
        h('p', { text: 'Toca una silueta lliure del pessebre per posar-hi una figura. Compta com a participació.' }),
        h('div', { class: 'botons' }, [boto('Deixar d\'afegir', function () { afegint = null; Visor.filtre(null); pLloc(); }, 'suau')])
      ]);
    }
    return h('div', { class: 'afegir' }, [
      h('h3', { text: 'Afegir figures' }),
      h('p', { class: 'suau', text: 'Per si hi ha poca participació: les figures que afegeix l\'administració compten com a participació (les automàtiques no).' }),
      h('div', { class: 'botons' }, TIPUS.map(function (t) {
        var n = Visor.lliures(t.t).length;
        var b = boto(t.nom + ' (' + n + ')', function () { afegeix(t.t); });
        if (!n) b.disabled = true;
        return b;
      }))
    ]);
  }

  function triaPerId(id) {
    var l = D().mapa.llocs.filter(function (x) { return x.id === id; })[0];
    if (l) { Visor.centra(l.x, l.y - 20, 500); tria(l); }
  }

  function descriuLloc(l) {
    var tipus = { casa: 'Casa', element: 'Element', persona: 'Persona', animal: 'Animal' }[l.tipus];
    var grup = { poble: 'casa de classe', claustre: 'claustre', general: 'famílies' }[l.grup];
    return tipus + ' · ' + grup + ' · zona ' + l.zona + (l.pastor ? ' · pastor' : '');
  }

  function pLloc() {
    if (!sel) { mostra([h('h2', { text: 'Lloc' }), h('p', { text: 'Toca un lloc del pessebre per veure qui l\'ocupa i gestionar-lo.' }), blocAfegir()]); return; }
    var l = sel.lloc, o = sel.ocupacio;
    var lm = D().mapa.llocs.filter(function (x) { return x.id === l.id; })[0];
    var fills = [h('h2', { text: 'Lloc ' + l.id }), h('p', { class: 'suau', text: descriuLloc(lm) })];
    if (!o) {
      fills.push(h('p', { text: 'Lliure.' }));
      if (admin() && l.grup === 'poble') fills.push(h('div', { class: 'botons' }, [boto('Posar-hi una casa sense placa', function () { edita(lm, null); }, 'primari')]));
      else if (admin() && afegible(lm)) fills.push(h('div', { class: 'botons' }, [boto('Afegir-hi una figura', function () { edita(lm, null, true); }, 'primari')]));
    } else {
      // Participació anònima: d'una família no se sap el codi
      var qui = o.classe ? 'Classe «' + o.classe + '»' : ({ familia: 'Una família', claustre: 'Claustre', admin: 'Administració', auto: 'Automàtica' }[o.origen] || o.origen);
      fills.push(fila('Estat', o.estat === 'confirmat' ? 'Confirmat' : 'Retingut fins a les ' + (o.caduca || '').slice(11)));
      fills.push(fila('Qui', qui));
      fills.push(fila('Peça', UI.nomPeca(o.peca || '') + (o.mirall ? ' (girada)' : '')));
      fills.push(fila('Hora', o.hora));
      if (admin()) {
        var botons = [];
        if (o.estat === 'confirmat') botons.push(boto('Canviar peça o colors', function () { edita(lm, o); }, 'primari'));
        botons.push(boto(o.estat === 'confirmat' ? 'Alliberar' : 'Alliberar la retenció', function () {
          if (!window.confirm('Segur que vols alliberar el lloc ' + l.id + '?')) return;
          api('admin.alliberar', { lloc: l.id }).then(function () {
            UI.avis('Lloc ' + l.id + ' alliberat.');
            return refresca().then(function () { tria(lm); });
          }).catch(function (e) { UI.avis(e.message); });
        }));
        fills.push(h('div', { class: 'botons' }, botons));
        if (o.origen === 'familia') fills.push(h('p', { class: 'suau', text: 'Si la família ha de tornar a participar, demana-li el codi i reactiva\'l a «Codis».' }));
      }
    }
    if (sel.registre.length) {
      fills.push(h('h3', { text: 'Registre d\'aquest lloc' }));
      fills.push(taulaRegistre(sel.registre, false));
    }
    if (afegint) fills.push(blocAfegir());
    mostra(fills);
  }

  // Editor de peça i colors (canviar una ocupació, posar una casa sense placa o afegir una figura en un lloc lliure)
  function edita(l, o, nova) {
    var sensePlaca = !o && !nova;
    var possibles = UI.pecesPossibles(D(), l);
    var est = nova ? { peca: possibles.indexOf(l.codi) >= 0 ? l.codi : possibles[0], colors: null, mirall: l.mirall ? 1 : 0, finestres: 0 }
      : sensePlaca ? { peca: l.codi.replace(/-P$/, '-SP'), colors: null, mirall: l.mirall ? 1 : 0, finestres: 0 }
      : { peca: o.peca, colors: o.colors ? o.colors.slice() : null, mirall: o.mirall, finestres: o.finestres || 0 };
    // Ocupacions antigues poden tenir menys colors que parts personalitzables té ara la peça: es completen amb 0
    var n = D().peces[est.peca].pers.length;
    if (est.colors) while (est.colors.length < n) est.colors.push(0);
    var ed = UI.editorPeca(D(), l, est, function () {
      Visor.posa(l.id, { p: est.peca, c: est.colors, m: est.mirall, f: est.finestres, n: o && o.classe && /-P$/.test(est.peca) ? o.classe : undefined });
      Visor.redibuixa();
    });
    var tanca = function () { Visor.treu(l.id); Visor.redibuixa(); if (nova && afegint) sel = null; pLloc(); };
    if (nova) Visor.centra(l.x, l.y - 20, 240);
    mostra([
      h('h2', { text: nova ? 'Afegir una figura · ' + l.id : sensePlaca ? 'Casa sense placa · ' + l.id : 'Canviar · ' + l.id }),
      ed.galeria, ed.tria,
      h('div', { class: 'botons' }, [
        boto('Desar', function () {
          api(nova ? 'admin.afegir' : sensePlaca ? 'admin.casa_sense_placa' : 'admin.canvia',
              { lloc: l.id, peca: est.peca, colors: est.colors, mirall: est.mirall, finestres: est.finestres }).then(function (r) {
            Visor.treu(l.id);
            UI.avis(nova ? (r.zona_nova ? 'Afegida! S\'ha obert una zona nova.' : 'Afegida! Compta com a participació.') : 'Desat.');
            // Afegint figures, es torna a la llista per posar-ne una altra
            return refresca().then(function () { if (nova && afegint) { sel = null; Visor.destaca(null); pLloc(); } else tria(l); });
          }).catch(function (e) { UI.avis(e.message); });
        }, 'primari'),
        boto('Girar ↔', ed.girar),
        boto('Cancel·lar', tanca, 'suau')
      ])
    ]);
  }

  // ── Estat

  function pEstat() {
    var e = estat;
    var tipus = [['classes', 'Classes'], ['casa', 'Cases'], ['element', 'Elements'], ['persona', 'Persones'], ['animal', 'Animals']];
    var cap = h('tr', {}, [h('th', { text: 'Zona' })].concat(tipus.map(function (t) { return h('th', { text: t[1] }); })));
    var files = Object.keys(e.zones).sort().map(function (z) {
      var oberta = e.config.zones.split(',').indexOf(z) >= 0;
      return h('tr', {}, [h('td', { text: z + (oberta ? '' : ' (tancada)') })].concat(tipus.map(function (t) {
        var v = e.zones[z][t[0]];
        return h('td', { text: v ? v.ocupats + '/' + v.total : '—' });
      })));
    });
    var origens = Object.keys(e.origens).map(function (k) { return ({ classe: 'classes', familia: 'famílies', claustre: 'claustre', admin: 'administració', auto: 'automàtiques' }[k] || k) + ' ' + e.origens[k]; }).join(' · ');
    var aturada = e.config.aturada === '1';
    var fills = [
      h('h2', { text: 'Estat' }),
      aturada ? h('p', { class: 'error', text: 'La participació està ATURADA.' }) : null,
      blocRitme(),
      admin() ? h('div', { class: 'botons' }, [boto(aturada ? 'Reprendre la participació' : 'Aturar la participació', function () {
        if (!aturada && !window.confirm('Aturar totes les reserves (tutors, claustre i famílies)?')) return;
        desaConfig('aturada', aturada ? '0' : '1');
      }, aturada ? 'primari' : '')]) : null,
      h('table', { class: 'taula' }, [h('thead', {}, [cap]), h('tbody', {}, files)]),
      fila('Ocupacions', origens || 'cap'),
      fila('Claustre (torronada)', e.claustre.fetes + ' figures'),
      fila('Figures per codi', e.config.usos_codi),
      h('h3', { text: 'Retencions actives (' + e.retencions.length + ')' }),
      e.retencions.length ? h('ul', { class: 'llista' }, e.retencions.map(function (r) {
        return h('li', {}, [enllacLloc(r.lloc), ' · ' + r.origen + ' · fins a les ' + r.caduca.slice(11)]);
      })) : h('p', { class: 'suau', text: 'Cap.' }),
      h('h3', { text: 'Classes' })
    ];
    Object.keys(e.etapes).forEach(function (et) {
      var cl = e.classes[et] || [];
      var fetes = cl.filter(function (c) { return c.lloc; }).length;
      fills.push(h('details', {}, [h('summary', { text: e.etapes[et] + ': ' + fetes + ' de ' + cl.length + ' amb casa' }),
        h('ul', { class: 'llista' }, cl.map(function (c) { return h('li', {}, [c.nom + ' · ', c.lloc ? enllacLloc(c.lloc) : 'sense casa']); }))]));
    });
    mostra(fills);
  }

  function enllacLloc(id) {
    return h('button', { type: 'button', class: 'enllac', text: id, clic: function () { triaPerId(id); } });
  }

  // ── Codis i etiquetes

  function pCodis() {
    var cerca = h('input', { type: 'text', class: 'camp', placeholder: 'K7M-QX4-P9TR', autocomplete: 'off', 'aria-label': 'Codi a cercar' });
    var resultat = h('div', {});
    var quants = h('input', { type: 'number', class: 'camp curt', min: '1', max: '1000', value: '24', 'aria-label': 'Quants codis' });
    var lots = estat.lots.map(function (l) { return h('li', { text: 'Lot ' + l.lot + ': ' + l.codis + ' codis, ' + (+l.usats || 0) + ' usats' }); });
    mostra([
      h('h2', { text: 'Codis de família' }),
      h('h3', { text: 'Cercar o reactivar un codi' }),
      h('p', { class: 'suau', text: 'La participació és anònima: no es pot saber quina figura ha posat cada codi.' }),
      h('form', { class: 'form-codi' }, [cerca, h('button', { type: 'submit', class: 'boto', text: 'Cercar' })]),
      resultat,
      admin() ? h('h3', { text: 'Generar codis i etiquetes' }) : null,
      admin() ? h('p', { class: 'suau', text: 'Els codis es veuen UN SOL COP: imprimeix les etiquetes de seguida (A4, 24 per full).' }) : null,
      admin() ? h('div', { class: 'form-codi' }, [quants, boto('Generar', function () { genera(+quants.value); }, 'primari')]) : null,
      h('h3', { text: 'Lots' }),
      lots.length ? h('ul', { class: 'llista' }, lots) : h('p', { class: 'suau', text: 'Encara no hi ha codis.' })
    ]);
    cont.querySelector('form').addEventListener('submit', function (ev) {
      ev.preventDefault();
      api('admin.cercar', { codi: cerca.value }).then(function (r) {
        resultat.textContent = '';
        if (!r.trobat) { resultat.appendChild(h('p', { text: 'Aquest codi no existeix.' })); return; }
        resultat.appendChild(fila('Lot', r.lot));
        resultat.appendChild(fila('Fet servir', r.usos + ' de ' + r.permesos + (r.usos ? ' (figures posades)' : '')));
        if (admin() && r.usos > 0) resultat.appendChild(h('div', { class: 'botons' }, [boto('Reactivar (una figura més)', function () {
          if (!window.confirm('Reactivar aquest codi? Se li torna una figura. Abans cal haver alliberat la figura de la família.')) return;
          api('admin.reactivar', { codi: cerca.value }).then(function () { UI.avis('Codi reactivat.'); resultat.textContent = ''; })
            .catch(function (e) { UI.avis(e.message); });
        })]));
      }).catch(function (e) { UI.avis(e.message); });
    });
  }

  function genera(n) {
    if (!(n >= 1 && n <= 1000)) { UI.avis('Entre 1 i 1000 codis.'); return; }
    if (!window.confirm('Generar ' + n + ' codis nous? Es veuran un sol cop.')) return;
    api('admin.generar_codis', { quants: n }).then(function (r) {
      prepara(r);
      api('admin.estat').then(function (e) { estat = e; });    // només dades: la pantalla del lot es queda fins que es torni
      UI.avis('Lot ' + r.lot + ': ' + r.codis.length + ' codis. Imprimeix ara les etiquetes.');
      mostra([
        h('h2', { text: 'Lot ' + r.lot + ' a punt' }),
        h('p', { text: r.codis.length + ' codis. Imprimeix les etiquetes ara: un cop surtis d\'aquí no es poden tornar a veure.' }),
        h('p', { class: 'suau', text: 'Al diàleg d\'impressió tria mida real (100 %) i sense marges.' }),
        h('div', { class: 'botons' }, [boto('Imprimir les etiquetes', function () { window.print(); }, 'primari'), boto('Tornar', pCodis, 'suau')])
      ]);
    }).catch(function (e) { UI.avis(e.message); });
  }

  // Full d'etiquetes per imprimir (només es veu en imprimir). El QR (SVG) el genera el servidor.
  function prepara(r) {      // r: { url_base, codis: [{codi, qr, classe?}], usos?, classes? }
    var zona = document.getElementById('impressio');
    zona.textContent = '';
    var host = r.url_base.replace(/^https?:\/\//, '').replace(/\/.*$/, '');
    for (var i = 0; i < r.codis.length; i += 24) {
      var full = h('div', { class: 'full-etiquetes' });
      r.codis.slice(i, i + 24).forEach(function (c) {
        var qr = new DOMParser().parseFromString(c.qr, 'image/svg+xml').documentElement;
        full.appendChild(h('div', { class: 'etiqueta' }, [document.importNode(qr, true), h('div', { class: 'tx' }, [
          h('p', { class: 't', text: r.classes ? 'Pessebre · ' + c.classe : 'Pessebre de l\'escola' }),
          h('p', { class: 'c', text: c.codi }),
          h('p', { class: 'p', text: 'Escaneja el QR o entra a ' + host + ', toca «Participar» i escriu el codi. '
            + (r.classes ? 'Serveix per triar i pintar la casa de la classe.' : 'Pots posar-hi fins a ' + r.usos + (r.usos === 1 ? ' figura.' : ' figures.')) })
        ])]));
      });
      zona.appendChild(full);
    }
  }

  // ── Enllaços

  // Claustre (un enllaç amb QR per a la torronada) i codis de classe (un per classe, §30)
  function pEnllacos() {
    var claustre = estat.enllacos.filter(function (e) { return e.tipus === 'claustre'; })[0];
    var classes = [];
    Object.keys(estat.etapes).forEach(function (et) { (estat.classes[et] || []).forEach(function (c) { classes.push(c); }); });
    var senseCodi = classes.filter(function (c) { return !c.codi; }).length;
    var sortida = h('div', {});
    mostra([
      h('h2', { text: 'Claustre i classes' }),
      h('h3', { text: 'Enllaç del claustre (torronada)' }),
      h('p', { class: 'suau', text: 'Un enllaç comú amb QR: qui el té pot posar tantes figures com vulgui el dia de la torronada. '
        + 'Un enllaç nou fa que l\'anterior deixi de funcionar. Es mostra un sol cop: imprimeix o projecta el QR.' }),
      fila('Estat', claustre ? 'actiu des de ' + claustre.creat : 'encara no n\'hi ha'),
      admin() ? h('div', { class: 'botons' }, [boto(claustre ? 'Regenerar l\'enllaç' : 'Crear l\'enllaç', function () { nouEnllac(sortida); }, 'primari')]) : null,
      sortida,
      h('h3', { text: 'Codis de classe' }),
      h('p', { class: 'suau', text: 'Cada classe té el seu codi (amb QR) per triar la casa: el tutor l\'escriu al botó «Participar» o escaneja el QR. '
        + 'Es mostren un sol cop: imprimeix el full i dona a cada tutor el de la seva classe.' }),
      fila('Classes', classes.length + ' · amb codi ' + (classes.length - senseCodi) + ' · amb casa ' + classes.filter(function (c) { return c.lloc; }).length),
      admin() && senseCodi ? h('div', { class: 'botons' }, [boto('Generar els codis que falten (' + senseCodi + ')', function () { codisClasse('pendents'); }, 'primari')]) : null,
      h('table', { class: 'taula' }, [h('tbody', {}, classes.map(function (c) {
        return h('tr', {}, [h('td', { text: c.nom }), h('td', { class: 'suau', text: (c.codi ? 'codi' : 'sense codi') + (c.lloc ? ' · casa ' + c.lloc : '') }),
          h('td', {}, [admin() && c.codi ? boto('Regenerar', function () { codisClasse(c.id, c.nom); }) : null])]);
      }))])
    ]);
  }

  function nouEnllac(sortida) {
    if (!window.confirm('Crear un enllaç nou del claustre? L\'anterior deixarà de funcionar.')) return;
    api('admin.enllac', { tipus: 'claustre' }).then(function (r) {
      var camp = h('input', { type: 'text', class: 'camp', readonly: 'readonly', value: r.url, 'aria-label': 'Enllaç nou' });
      var qr = document.importNode(new DOMParser().parseFromString(r.qr, 'image/svg+xml').documentElement, true);
      qr.setAttribute('class', 'qr-gran');
      sortida.textContent = '';
      sortida.appendChild(h('p', { text: 'Enllaç nou del claustre:' }));
      sortida.appendChild(h('div', { class: 'form-codi' }, [camp, boto('Copiar', function () {
        camp.select();
        if (navigator.clipboard) navigator.clipboard.writeText(r.url).then(function () { UI.avis('Copiat.'); });
      })]));
      sortida.appendChild(qr);
      sortida.appendChild(h('div', { class: 'botons' }, [boto('Imprimir el QR', function () { imprimeixQR(r.qr); window.print(); })]));
      return api('admin.estat').then(function (e) { estat = e; });
    }).catch(function (e) { UI.avis(e.message); });
  }

  // Full per imprimir amb el QR del claustre en gran
  function imprimeixQR(svg) {
    var zona = document.getElementById('impressio');
    zona.textContent = '';
    var qr = document.importNode(new DOMParser().parseFromString(svg, 'image/svg+xml').documentElement, true);
    zona.appendChild(h('div', { class: 'full-qr' }, [h('h1', { text: 'Torronada: posa la teva figura al pessebre!' }), qr,
      h('p', { text: 'Escaneja el codi QR amb el mòbil. Pots posar-ne tantes com vulguis.' })]));
  }

  function codisClasse(quines, nom) {
    var pregunta = quines === 'pendents' ? 'Generar els codis de les classes que encara no en tenen? Es veuran un sol cop.'
      : 'Regenerar el codi de ' + nom + '? L\'anterior deixarà de funcionar.';
    if (!window.confirm(pregunta)) return;
    api('admin.codis_classe', { quines: quines }).then(function (r) {
      prepara({ url_base: r.url_base, codis: r.codis, classes: true });
      mostra([
        h('h2', { text: r.codis.length === 1 ? 'Codi nou de ' + r.codis[0].classe : r.codis.length + ' codis de classe' }),
        h('p', { class: 'error', text: 'Es mostren UN SOL COP. Imprimeix-los ara (A4, 24 per full).' }),
        h('div', { class: 'botons' }, [boto('Imprimir', function () { window.print(); }, 'primari'), boto('Fet', function () { refresca().then(function () { obre('enllacos'); }); })]),
        h('table', { class: 'taula' }, [h('tbody', {}, r.codis.map(function (c) { return h('tr', {}, [h('td', { text: c.classe }), h('td', { class: 'codi', text: c.codi })]); }))])
      ]);
    }).catch(function (e) { UI.avis(e.message); });
  }

  // ── Configuració

  function desaConfig(clau, valor) {
    return api('admin.config', { opcio: clau, valor: valor }).then(function () {
      UI.avis('Desat.');
      return refresca().then(function () { obre(pestanya); });
    }).catch(function (e) { UI.avis(e.message); });
  }

  function pConfig() {
    var c = estat.config;
    var noms = { classes_tanca: 'Tancament de les classes', claustre_des: 'Obertura del claustre', claustre_tanca: 'Tancament del claustre',
                 families_des: 'Inici de les famílies', families_fins: 'Final de la participació (Reis)' };
    var dates = Object.keys(noms).map(function (k) {
      var camp = h('input', { type: 'datetime-local', class: 'camp', value: (c[k] || '').replace(' ', 'T'), 'aria-label': noms[k] });
      if (!admin()) camp.disabled = true;
      return h('div', { class: 'fila-config' }, [h('span', { class: 'etiqueta', text: noms[k] }), camp,
        admin() ? boto('Desar', function () { desaConfig(k, camp.value); }) : null]);
    });
    var numeric = function (k, nom, max) {
      var camp = h('input', { type: 'number', class: 'camp curt', min: '1', max: String(max), value: c[k], 'aria-label': nom });
      if (!admin()) camp.disabled = true;
      return h('div', { class: 'fila-config' }, [h('span', { class: 'etiqueta', text: nom }), camp,
        admin() ? boto('Desar', function () { desaConfig(k, camp.value); }) : null]);
    };
    var zonesObertes = c.zones.split(',');
    var zones = ['Z1', 'Z2', 'Z3'].map(function (z, i) {
      var oberta = zonesObertes.indexOf(z) >= 0;
      return admin() ? boto(z + (oberta ? ' (oberta) · tancar' : ' (tancada) · obrir'), function () {
        var noves = oberta ? ['Z0', 'Z1', 'Z2', 'Z3'].slice(0, i + 1) : ['Z0', 'Z1', 'Z2', 'Z3'].slice(0, i + 2);
        desaConfig('zones', noves.join(','));
      }) : h('span', { text: z + (oberta ? ' oberta ' : ' tancada ') });
    });
    var classes = h('textarea', { class: 'camp area', rows: '6', placeholder: 'primaria;3r A\nsecundaria;1r ESO B', 'aria-label': 'Llista de classes' });
    mostra([
      h('h2', { text: 'Configuració' }),
      h('h3', { text: 'Dates (hora de Barcelona)' })
    ].concat(dates).concat([
      h('h3', { text: 'Participació' }),
      numeric('llindar_zona', 'Obrir zona nova al (%)', 100),
      numeric('usos_codi', 'Figures per codi de família', 10),
      h('h3', { text: 'Zones' }),
      h('p', { class: 'suau', text: 'S\'obren soles en arribar al llindar; aquí es poden forçar o endarrerir (sempre en ordre).' }),
      h('div', { class: 'botons' }, zones),
      admin() ? h('h3', { text: 'Llista de classes' }) : null,
      admin() ? h('p', { class: 'suau', text: 'Una línia per classe: etapa;nom (llar, infantil, primaria, secundaria, cicles; nom de 8 caràcters com a molt).' }) : null,
      admin() ? classes : null,
      admin() ? h('div', { class: 'botons' }, [boto('Carregar la llista', function () {
        api('admin.classes', { text: classes.value }).then(function (r) {
          UI.avis(r.classes + ' classes carregades.' + (r.avisos.length ? ' ' + r.avisos.join(' ') : ''));
          return refresca();
        }).catch(function (e) { UI.avis(e.message); });
      })]) : null
    ]));
  }

  // ── Fites, moments, participació automàtica i tancament

  var NOM_MOMENT = { dia: 'Dia', posta: 'Posta de sol', nit: 'Nit', neu: 'Neu' };

  // Ritme de participació cap a la propera fita: el que tocaria tenir si es repartís igual cada dia, el que hi ha i la previsió
  function blocRitme() {
    var r = estat.ritme;
    if (!r || estat.config.tancat === '1') return null;
    var unitat = r.base === 'classes' ? ' cases de classe' : ' participacions';
    var fills = [h('h3', { text: 'Ritme cap a la fita «' + NOM_MOMENT[r.moment] + '»' }),
      fila('Llindar', r.llindar + unitat + ' · data límit ' + r.data_limit)];
    if (r.abans) {
      fills.push(h('p', { class: 'suau', text: 'Aquest tram comença el ' + r.inici + '.' }));
      return h('div', { class: 'ritme' }, fills);
    }
    var barra = h('div', { class: 'ritme-barra', role: 'img', 'aria-label': r.fet + ' de ' + r.llindar + '; avui en tocarien ' + r.esperat }, [h('span'), h('i')]);
    barra.firstChild.style.setProperty('width', Math.min(100, 100 * r.fet / r.llindar) + '%');
    barra.lastChild.style.setProperty('left', Math.min(100, 100 * r.esperat / r.llindar) + '%');
    fills.push(barra);
    fills.push(fila('Ara', r.fet + unitat + ' · avui en tocarien ' + r.esperat + (r.percent !== null ? ' (' + r.percent + ' % del previst des del ' + r.inici.slice(0, 10) + ')' : '')));
    if (r.falten_fita > 0) fills.push(fila('Per arribar-hi', 'en falten ' + r.falten_fita + (r.necessari_dia ? ', unes ' + r.necessari_dia + ' al dia' : '')));
    fills.push(fila('Ritme', (r.per_dia !== null ? r.per_dia + ' al dia de mitjana · ' : '') + r.darreres_24h + ' les darreres 24 hores'));
    fills.push(h('p', { class: 'previsio', text: r.falten_fita === 0 ? 'Ja s\'ha arribat al llindar: la fita saltarà aviat.'
      : r.previsio === 'llindar' ? 'Al ritme d\'ara, saltarà per participació cap al ' + r.data_prevista + '.'
      : 'Al ritme d\'ara, saltarà per data el ' + r.data_limit + '. Aleshores s\'hi afegiran a poc a poc unes ' + r.automatiques + ' figures automàtiques (que no compten com a participació).' }));
    if (r.falten_ritme > 0 && r.base !== 'classes') {
      fills.push(h('p', { class: 'destacat', text: 'Per anar al ritme previst falten ' + r.falten_ritme + ' figures.' }));
      if (admin()) fills.push(h('div', { class: 'botons' }, [boto('Afegir figures', function () { sel = null; Visor.destaca(null); obre('lloc'); }, 'primari')]));
    }
    return h('div', { class: 'ritme' }, fills);
  }

  function campNumeric(k, nom, min, max) {
    var camp = h('input', { type: 'number', class: 'camp curt', min: String(min), max: String(max), value: estat.config[k], 'aria-label': nom });
    if (!admin()) camp.disabled = true;
    return h('div', { class: 'fila-config' }, [h('span', { class: 'etiqueta', text: nom }), camp,
      admin() ? boto('Desar', function () { desaConfig(k, camp.value); }) : null]);
  }

  function pFites() {
    var e = estat, c = e.config;
    var files = e.fites.map(function (f) {
      var data = h('input', { type: 'datetime-local', class: 'camp', value: (f.data_limit || '').replace(' ', 'T'), 'aria-label': 'Data límit ' + f.moment });
      var inc = h('input', { type: 'number', class: 'camp curt', min: '1', max: '100', value: String(f.increment), 'aria-label': 'Increment ' + f.moment });
      var editable = admin() && !f.assolida;
      if (!editable) { data.disabled = true; inc.disabled = true; }
      var que = f.base === 'classes' ? '% de les cases de classe' : '% de l\'objectiu, a sumar a la participació quan salta l\'anterior';
      return h('div', { class: 'fita' + (f.assolida ? ' assolida' : '') }, [
        h('h4', { text: f.numero + '. ' + NOM_MOMENT[f.moment] + (f.assolida ? ' · assolida ' + f.assolida + (f.per === 'data' ? ' (per data)' : ' (per participació)') : '') }),
        h('div', { class: 'fila-config' }, [h('span', { class: 'etiqueta', text: 'Llindar' }),
          h('span', { text: f.llindar === null ? 'es fixarà quan salti l\'anterior' : f.llindar + (f.base === 'classes' ? ' cases de classe' : ' participacions') })]),
        h('div', { class: 'fila-config' }, [h('span', { class: 'etiqueta', text: 'Increment' }), inc, h('span', { class: 'suau', text: que })]),
        h('div', { class: 'fila-config' }, [h('span', { class: 'etiqueta', text: 'Data límit (no es mostra al públic)' }), data]),
        editable ? h('div', { class: 'botons' }, [boto('Desar la fita', function () {
          api('admin.fita', { numero: f.numero, data: data.value, increment: +inc.value }).then(function () {
            UI.avis('Desat.');
            return refresca().then(function () { obre('fites'); });
          }).catch(function (er) { UI.avis(er.message); });
        })]) : null
      ]);
    });
    var tancament;
    if (c.tancat === '1') tancament = h('p', { text: 'El pessebre està tancat i les dades s\'han esborrat.' });
    else if (e.pot_tancar && admin()) tancament = h('div', { class: 'botons' }, [boto('Tancar i esborrar', function () {
      if (!window.confirm('Tancar el pessebre? S\'esborraran els codis, els enllaços, els intents i el registre. No es pot desfer.')) return;
      if (!window.confirm('Segur? Després ja no es podrà fer cap canvi.')) return;
      api('admin.tancar').then(function () { UI.avis('Pessebre tancat.'); return refresca().then(function () { obre('fites'); }); })
        .catch(function (er) { UI.avis(er.message); });
    }, 'perill')]);
    else tancament = h('p', { class: 'suau', text: 'Es podrà tancar a partir del final de la participació (' + c.families_fins + '). Aleshores ja no s\'accepten participacions.' });
    mostra([
      h('h2', { text: 'Fites i moments del dia' }),
      fila('Moment actual', NOM_MOMENT[c.moment] || c.moment),
      fila('Participació real', e.participacio.reals + ' (cases de classe: ' + e.participacio.classes + ')'),
      blocRitme(),
      h('h3', { text: 'Fites' }),
      h('p', { class: 'suau', text: 'Cada fita salta amb el primer que arribi: el llindar o la data límit. No es desfà mai.' })
    ].concat(files).concat([
      campNumeric('objectiu', 'Objectiu de participació', 10, 5000),
      campNumeric('minim_dies', 'Dies mínims entre fites', 0, 10),
      h('h3', { text: 'Participació automàtica' }),
      h('p', { class: 'suau', text: 'Només quan una fita salta per data. Omple fins a aquest % de les zones obertes, sense passar del màxim per zona, a poc a poc.' }),
      fila('Ara n\'hi ha', e.automatiques + (c.auto_fase ? ' (omplint la fase «' + (NOM_MOMENT[c.auto_fase] || c.auto_fase) + '»)' : '')),
      campNumeric('auto_posta', 'Objectiu a la posta (%)', 0, 100),
      campNumeric('auto_nit', 'Objectiu a la nit (%)', 0, 100),
      campNumeric('auto_neu', 'Objectiu a la neu (%)', 0, 100),
      campNumeric('auto_max_zona', 'Màxim d\'automàtiques per zona (%)', 0, 100),
      admin() && e.automatiques ? h('div', { class: 'botons' }, [boto('Retirar totes les automàtiques', function () {
        if (!window.confirm('Retirar les ' + e.automatiques + ' figures automàtiques?')) return;
        api('admin.retirar_auto').then(function (r) { UI.avis(r.retirades + ' retirades.'); return refresca().then(function () { obre('fites'); }); })
          .catch(function (er) { UI.avis(er.message); });
      })]) : null,
      h('h3', { text: 'Tancament (Reis)' }),
      tancament
    ]));
  }

  // ── Registre

  function taulaRegistre(files, ambLloc) {
    return h('table', { class: 'taula registre' }, [h('tbody', {}, files.map(function (r) {
      return h('tr', {}, [h('td', { text: r.hora }), h('td', { text: r.qui }), h('td', { text: r.accio }),
        ambLloc ? h('td', {}, [r.lloc_id ? enllacLloc(r.lloc_id) : '']) : null, h('td', { class: 'suau', text: r.detall || '' })].filter(Boolean));
    }))]);
  }

  function pRegistre() {
    mostra([h('h2', { text: 'Registre' }), h('p', { class: 'suau', text: 'Carregant…' })]);
    api('admin.registre', { quants: 200 }).then(function (r) {
      mostra([h('h2', { text: 'Registre' }), h('p', { class: 'suau', text: 'Les 200 darreres accions (sense dades personals).' }), taulaRegistre(r.registre, true)]);
    }).catch(function (e) { UI.avis(e.message); });
  }

  // ── Comptes (§31): cada persona el seu. Tothom pot canviar la seva contrasenya; l'administració crea i treu comptes.

  function pComptes() {
    var nova = h('input', { type: 'password', class: 'camp', autocomplete: 'new-password', 'aria-label': 'Contrasenya nova' });
    var fills = [
      h('h2', { text: 'Comptes' }),
      fila('Has entrat com a', estat.nom + ' (' + (admin() ? 'administració' : 'només lectura') + ')'),
      h('h3', { text: 'Canviar la meva contrasenya' }),
      h('div', { class: 'form-codi' }, [nova, boto('Desar', function () {
        api('admin.contrasenya_propia', { nova: nova.value }).then(function () {
          contrasenya = nova.value;
          nova.value = '';
          UI.avis('Contrasenya canviada.');
        }).catch(function (e) { UI.avis(e.message); });
      })]),
      h('p', { class: 'suau', text: 'Com a mínim 10 caràcters.' })
    ];
    if (admin()) {
      var nom = h('input', { type: 'text', class: 'camp', autocapitalize: 'none', spellcheck: 'false', placeholder: 'nom (p. ex. marta)', 'aria-label': 'Nom d\'usuari' });
      var clau = h('input', { type: 'password', class: 'camp', autocomplete: 'new-password', placeholder: 'contrasenya inicial', 'aria-label': 'Contrasenya inicial' });
      var rol = h('select', { class: 'camp', 'aria-label': 'Nivell' }, [h('option', { value: 'lectura', text: 'Només lectura' }), h('option', { value: 'admin', text: 'Administració' })]);
      fills.push(h('h3', { text: 'Comptes de l\'equip' }));
      fills.push(h('table', { class: 'taula' }, [h('tbody', {}, (estat.usuaris || []).map(function (u) {
        return h('tr', {}, [h('td', { text: u.nom }), h('td', { class: 'suau', text: u.rol === 'admin' ? 'administració' : 'només lectura' }),
          h('td', {}, [u.nom === estat.nom ? null : boto('Treure', function () {
            if (!window.confirm('Treure el compte «' + u.nom + '»? Ja no podrà entrar.')) return;
            api('admin.usuari_treu', { nom: u.nom }).then(function (r) { estat.usuaris = r.usuaris; UI.avis('Compte tret.'); pComptes(); })
              .catch(function (e) { UI.avis(e.message); });
          })])]);
      }))]));
      fills.push(h('h3', { text: 'Afegir un compte (o canviar-ne la contrasenya)' }));
      fills.push(h('div', { class: 'form-compte' }, [nom, clau, rol, boto('Desar', function () {
        api('admin.usuari', { nom: nom.value, nova: clau.value, rol: rol.value }).then(function (r) {
          estat.usuaris = r.usuaris;
          UI.avis(r.nou ? 'Compte creat: ' + r.nom + '. Fes-li arribar la contrasenya en persona.' : 'Compte actualitzat: ' + r.nom + '.');
          pComptes();
        }).catch(function (e) { UI.avis(e.message); });
      }, 'primari')]));
      fills.push(h('p', { class: 'suau', text: 'Administració: pot fer canvis. Només lectura: ho veu tot però no pot canviar res (equip de valors, direcció). '
        + 'Si algú ha oblidat la contrasenya, escriu el seu nom amb una contrasenya nova.' }));
    }
    mostra(fills);
  }

  document.getElementById('usuari').focus();
})();
