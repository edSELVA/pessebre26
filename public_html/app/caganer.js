// Joc del caganer (visor públic, index.html): cada dia s'amaga en un lloc diferent del pessebre.
// Botó «Cerca el caganer» → es busca movent-se i fent zoom; quan es toca, «L'has trobat!».
// Tres pistes: 1 s'enfosqueix la meitat on no és · 2 tot menys el quadrant on és · 3 es marca on és.
// No es desa res (ni al servidor ni al dispositiu): si es recarrega la pàgina, es torna a començar.
'use strict';

var Caganer = (function () {
  var h = UI.h;
  var boto = null;
  var nivell = 0;
  var TEXT_PISTA = ['Una pista', 'Una altra pista', 'Ensenya-me\'l'];

  function inicia() {
    boto = document.getElementById('caganer');
    if (!boto || !Visor.caganer.hiEs()) return;
    var mini = UI.previsualitzacio(Visor.disseny().peces);
    mini.pinta('ESC-CAGANER', {}, false);
    boto.insertBefore(mini.svg, boto.firstChild);
    boto.addEventListener('click', comenca);
    boto.hidden = false;
  }

  function comenca() {
    nivell = 0;
    boto.hidden = true;
    Visor.caganer.joc(trobat);
    panell();
  }

  function panell() {
    UI.mostra([
      h('h2', { text: 'On és el caganer?' }),
      h('p', { text: 'Avui s\'ha amagat en algun racó del pessebre. Mou-te, apropa\'t i, quan el vegis, toca\'l!' }),
      h('p', { class: 'suau', text: nivell === 0 ? 'Cada dia s\'amaga en un lloc diferent.'
        : nivell === 1 ? 'No és a la part fosca del pessebre.'
        : nivell === 2 ? 'És en aquest tros del pessebre.' : 'És dins del cercle. Toca\'l!' }),
      h('div', { class: 'botons' }, [
        nivell < 3 ? h('button', { type: 'button', class: 'boto primari', text: TEXT_PISTA[nivell], clic: function () {
          nivell++;
          Visor.caganer.pista(nivell);
          panell();
        } }) : null,
        h('button', { type: 'button', class: 'boto suau', text: 'Deixar-ho', clic: atura })
      ])
    ]);
  }

  function trobat() {
    Visor.caganer.joc(null);
    Visor.caganer.pista('trobat');
    UI.mostra([
      h('h2', { text: 'L\'has trobat!' }),
      h('p', { text: nivell === 3 ? 'Ara ja saps on era. Demà s\'amagarà en un altre lloc: el trobaràs sense pistes?'
        : nivell ? 'Molt bé! Demà s\'amagarà en un altre lloc.' : 'I sense cap pista! Demà s\'amagarà en un altre lloc.' }),
      h('div', { class: 'botons' }, [h('button', { type: 'button', class: 'boto primari', text: 'Tancar', clic: atura })])
    ]);
  }

  // Acaba el joc (també quan es vol participar)
  function atura() {
    if (!boto) return;
    Visor.caganer.joc(null);
    UI.mostra([]);
    boto.hidden = false;
  }

  return { inicia: inicia, atura: atura };
})();
