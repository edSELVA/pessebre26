<?php
// El paquet v2 es converteix sense errors, de forma determinista, i el resultat és el que hi ha a public_html/disseny/.
require_once $ARREL . '/eines/conversio.php';

[$fitxers, $avisos] = converteix("$ARREL/disseny-origen/pessebre-disseny-v2.zip", $ARREL);
igual(['escena-fixa.json', 'escena.css', 'llum-aire.svg', 'llum-terra.svg', 'mapa.json', 'moments.css', 'paletes.json',
       'peces.json', 'peces.svg', 'reis-cami.json', 'terreny.svg'], array_keys($fitxers), 'fitxers generats');
foreach ($fitxers as $nom => $contingut) {
    $disc = @file_get_contents("$ARREL/public_html/disseny/$nom");
    comprova($disc === $contingut, "public_html/disseny/$nom està al dia (torna a executar php eines/converteix.php)");
}
[$dues] = converteix("$ARREL/disseny-origen/pessebre-disseny-v2.zip", $ARREL);
comprova($dues === $fitxers, 'la conversió és determinista');
igual(2, count($avisos), 'només hi ha els avisos coneguts (pastors amb zona Z0, CASA-BAIXA-P sense placa)');
$pj = json_decode($fitxers['peces.json'], true)['peces'];
igual(1, $pj['CASA-BAIXA-P']['placa_propia'] ?? null, 'CASA-BAIXA-P: el visor hi dibuixa la placa');
igual([25, 52, 14, 5], $pj['CASA-BAIXA-P']['placa'], 'CASA-BAIXA-P: placa de la mateixa mida que les altres');
igual([17, 52, 14, 5], $pj['CASA-FRONTAL-P']['placa'], 'CASA-FRONTAL-P: la placa es pren del dibuix, no del registre');
foreach ($pj as $codi => $p) if (substr($codi, 0, 5) === 'CASA-' && substr($codi, -2) === '-P') igual([14, 5], [$p['placa'][2], $p['placa'][3]], "$codi: placa de 14×5");

// Res de contingut actiu als SVG generats
foreach (['terreny.svg', 'llum-terra.svg', 'llum-aire.svg', 'peces.svg'] as $nom) {
    comprova(!preg_match('/<script|\son[a-z]+=|javascript:|href="(?!#)|style=|<foreignObject|url\((?!#)/i', $fitxers[$nom]), "$nom no té contingut actiu ni estils en línia");
}
$peces = json_decode($fitxers['peces.json'], true)['peces'];
igual(105, count($peces), '102 peces + 2 de llum + 1 pròpia (rei amb turbant)');
foreach ($peces as $codi => $p) {
    comprova(strpos($fitxers['peces.svg'], 'id="pc-' . $codi . '"') !== false, "el sprite té $codi");
}
comprova(strpos($fitxers['escena.css'], '.fantasma {') !== false, 'escena.css té la silueta fantasma');
foreach (['dia', 'posta', 'nit', 'neu'] as $m) comprova(strpos($fitxers['moments.css'], ".moment-$m {") !== false, "moments.css té $m");

// La llista blanca rebutja SVG perillosos
$dolents = [
    'script' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    'onload' => '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>',
    'href extern' => '<svg xmlns="http://www.w3.org/2000/svg"><use href="https://exemple.cat/x.svg#a"/></svg>',
    'xlink' => '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><use xlink:href="#a"/></svg>',
    'url externa' => '<svg xmlns="http://www.w3.org/2000/svg"><path fill="url(http://exemple.cat/a)"/></svg>',
    'estil amb url' => '<svg xmlns="http://www.w3.org/2000/svg"><g id="a" style="opacity:url(x)"/></svg>',
    'entitats' => '<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>',
    'foreignObject' => '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject/></svg>',
    'text' => '<svg xmlns="http://www.w3.org/2000/svg"><g>hola</g></svg>',
    'sense espai de noms' => '<svg><path d="M0 0"/></svg>',
];
foreach ($dolents as $que => $svg) llança(function () use ($svg) { neteja_svg($svg, 'prova'); }, "rebutja SVG amb $que");
comprova(neteja_svg('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 2 2"><path fill="var(--a, #FFFFFF)" d="M0 0h1v1h-1z"/></svg>', 'prova') instanceof DOMDocument, 'accepta un SVG net');
llança(function () { estil_declaracions('background: red', 'prova'); }, 'rebutja propietats d\'estil no previstes');
