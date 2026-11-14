<?php
// Configuració d'EXEMPLE (valors falsos). Copia-la a config.php i canvia-ho tot.
// config.php NO es puja mai al repositori (és a .gitignore).
// En local, «php eines/gestio.php installa» crea un config.php de proves amb valors aleatoris.
// Genera valors aleatoris amb:  php -r 'echo bin2hex(random_bytes(32)), "\n";'
// Els comptes d'administració són a la base de dades: php eines/gestio.php usuari NOM admin|lectura

return [
    // Base de dades: 'sqlite' (preferit) o 'mysql'
    'bd_tipus'   => 'sqlite',
    'bd_sqlite'  => __DIR__ . '/dades/pessebre.sqlite',
    'bd_mysql'   => ['dsn' => 'mysql:host=localhost;dbname=pessebre;charset=utf8mb4', 'usuari' => 'CANVIA-AIXO', 'clau' => 'CANVIA-AIXO'],

    // Secrets
    'pebre'      => 'CANVIA-AIXO',          // pebre HMAC per als codis i enllaços (64 caràcters hex)
    'llavor'     => 'CANVIA-AIXO',          // llavor de la participació automàtica

    // Rutes i adreça pública
    'web'        => __DIR__ . '/../public_html',             // carpeta pública (on hi ha disseny/ i snapshot.json)
    'snapshot'   => __DIR__ . '/../public_html/snapshot.json',
    'bloqueig'   => __DIR__ . '/dades/escriptura.lock',
    'url_base'   => 'https://pessebre.exemple.cat/',        // per escriure els enllaços d'etapa

    // Només a l'entorn de proves: data simulada (AAAA-MM-DD) per als Reis, o null
    'data_simulada' => null,
];
