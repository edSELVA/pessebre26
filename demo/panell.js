// Panell de la DEMOSTRACIÓ (només al servidor de demo/router.php; mai a l'hosting).
// Pestanya «DEMO» a l'esquerra: fase, dia i hora simulats, «Veure com», deixar passar el temps i reinicis.
'use strict';

(function () {
  var CONTRASENYA = 'demo';                 // contrasenya d'administració de la demo (demo/prepara.php)
  var obert = false;
  try { obert = sessionStorage.getItem('demo-obert') === '1'; } catch (e) { /* sense emmagatzematge: tancat */ }
  if (location.pathname === '/demo' || location.pathname === '/demo.html') obert = true;

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

  function api(cos) {
    return fetch('/api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(cos) })
      .then(function (r) { return r.json(); })
      .then(function (r) { if (!r.ok) throw new Error(r.error || 'Error'); return r; });
  }

  function desaObert(v) {
    obert = v;
    try { sessionStorage.setItem('demo-obert', v ? '1' : '0'); } catch (e) { /* res */ }
    panell.hidden = !v;
    pestanya.setAttribute('aria-expanded', v ? 'true' : 'false');
  }

  // Quin rol s'està veient ara
  function rolActual() {
    var p = location.pathname;
    if (/admin\.html$/.test(p)) return 'admin';
    if (/classes\.html$/.test(p)) return 'tutor';
    if (/[#&]c=/.test(location.hash)) return 'familia';
    if (/[#&]k=/.test(location.hash)) return 'claustre';
    return 'public';
  }

  // Va a una pàgina i la torna a carregar encara que només canviï el que va després del #
  function ves(url) {
    var desti = new URL(url, location.href);
    if (window.innerWidth < 700) desaObert(false);       // al mòbil, el panell es tanca per deixar veure el pessebre
    if (desti.pathname === location.pathname) {
      location.replace(desti.href);
      location.reload();
    } else {
      location.href = desti.href;
    }
  }

  function recarrega() {
    // Després de canviar de fase, els enllaços i codis són nous: es torna a entrar amb el mateix rol
    return api({ a: 'demo.estat' }).then(function (r) {
      var rol = rolActual();
      var mateix = r.rols.filter(function (x) { return x.rol === rol; })[0];
      ves(mateix ? mateix.url : 'index.html');
    });
  }

  function fes(cos, missatge) {
    estat.textContent = missatge;
    panell.classList.add('ocupat');
    api(cos).then(recarrega).catch(function (e) {
      panell.classList.remove('ocupat');
      estat.textContent = e.message;
    });
  }

  var pestanya = h('button', { type: 'button', class: 'demo-pestanya', 'aria-controls': 'demo-panell', text: 'DEMO' });
  var panell = h('aside', { id: 'demo-panell', class: 'demo-panell', 'aria-label': 'Panell de la demostració' });
  var estat = h('p', { class: 'demo-estat', 'aria-live': 'polite' });
  pestanya.addEventListener('click', function () { desaObert(!obert); });
  document.body.appendChild(pestanya);
  document.body.appendChild(panell);
  desaObert(obert);

  function pinta(r) {
    panell.textContent = '';
    var rol = rolActual();
    panell.appendChild(h('div', { class: 'demo-cap' }, [
      h('strong', { text: 'Demostració' }),
      h('button', { type: 'button', class: 'demo-tanca', 'aria-label': 'Amaga el panell', text: '×', clic: function () { desaObert(false); } })
    ]));
    panell.appendChild(h('p', { class: 'demo-hora' }, [h('span', { text: r.hora }), h('br'), h('span', { class: 'demo-suau', text: r.moment + (r.tancat ? ' · tancat' : '') })]));

    panell.appendChild(h('h3', { text: 'Fase' }));
    var fases = h('div', { class: 'demo-fases' });
    r.fases.forEach(function (f) {
      fases.appendChild(h('button', {
        type: 'button', 'aria-pressed': f.n === r.fase ? 'true' : 'false', text: f.n + ' · ' + f.nom,
        clic: function () { fes({ a: 'demo.fase', fase: f.n }, 'Muntant la fase ' + f.n + '…'); }
      }));
    });
    panell.appendChild(fases);

    panell.appendChild(h('h3', { text: 'Veure com' }));
    var rols = h('div', { class: 'demo-botons' });
    r.rols.forEach(function (x) {
      rols.appendChild(h('button', { type: 'button', 'aria-pressed': x.rol === rol ? 'true' : 'false', text: x.nom, clic: function () { ves(x.url); } }));
    });
    panell.appendChild(rols);
    if (rol === 'admin') panell.appendChild(h('p', { class: 'demo-suau', text: 'Contrasenya de la demo: ' + CONTRASENYA }));

    if (!r.tancat) {
      panell.appendChild(h('h3', { text: 'Deixar passar el temps' }));
      panell.appendChild(h('div', { class: 'demo-botons' }, [
        h('button', { type: 'button', text: '+1 hora', clic: function () { fes({ a: 'demo.temps', hores: 1 }, 'Passa una hora…'); } }),
        h('button', { type: 'button', text: '+1 dia', clic: function () { fes({ a: 'demo.temps', hores: 24 }, 'Passa un dia…'); } })
      ]));
    }

    panell.appendChild(h('h3', { text: 'Reiniciar' }));
    panell.appendChild(h('div', { class: 'demo-botons' }, [
      h('button', { type: 'button', text: 'Aquesta fase', clic: function () { fes({ a: 'demo.fase', fase: r.fase }, 'Tornant a l\'inici de la fase…'); } }),
      h('button', { type: 'button', text: 'Famílies', clic: function () { fes({ a: 'demo.reinicia_families' }, 'Traient les figures de les famílies…'); } }),
      h('button', { type: 'button', class: 'demo-perill', text: 'Tot', clic: function () {
        if (confirm('Segur que vols reiniciar-ho tot? La demo torna a la fase 1, sense cap figura.')) fes({ a: 'demo.fase', fase: 1 }, 'Reiniciant-ho tot…');
      } })
    ]));
    panell.appendChild(estat);
  }

  api({ a: 'demo.estat' }).then(pinta).catch(function (e) { panell.textContent = 'Demo: ' + e.message; });

  // Administració: entra sola amb la contrasenya de la demo
  var form = document.getElementById('form-entrada');
  var camp = document.getElementById('contrasenya');
  if (form && camp) {
    setTimeout(function () {
      if (document.getElementById('entrada').hidden) return;
      camp.value = CONTRASENYA;
      if (form.requestSubmit) form.requestSubmit(); else form.dispatchEvent(new Event('submit', { cancelable: true }));
    }, 300);
  }
})();
