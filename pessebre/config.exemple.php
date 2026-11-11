<?php
// Configuració d'EXEMPLE (valors falsos). Copia-la a config.php i canvia-ho tot.
// config.php NO es puja mai al repositori (és a .gitignore).
// Genera valors aleatoris amb:  php -r 'echo bin2hex(random_bytes(32)), "\n";'
// Hash d'una contrasenya:       php -r 'echo password_hash("la-contrasenya", PASSWORD_DEFAULT), "\n";'

return [
    // Base de dades: 'sqlite' (preferit) o 'mysql'
    'bd_tipus'   => 'sqlite',
    'bd_sqlite'  => __DIR__ . '/dades/pessebre.sqlite',
    'bd_mysql'   => ['dsn' => 'mysql:host=localhost;dbname=pessebre;charset=utf8mb4', 'usuari' => 'CANVIA-AIXO', 'clau' => 'CANVIA-AIXO'],

    // Secrets
    'pebre'      => 'CANVIA-AIXO',          // pebre HMAC per als codis i enllaços (64 caràcters hex)
    'llavor'     => 'CANVIA-AIXO',          // llavor de la participació automàtica
    'hash_admin' => 'CANVIA-AIXO',          // password_hash de la contrasenya d'administració
    'hash_lectura' => 'CANVIA-AIXO',        // password_hash de la contrasenya de només lectura

    // Rutes
    'snapshot'   => __DIR__ . '/../public_html/snapshot.json',
    'bloqueig'   => __DIR__ . '/dades/escriptura.lock',

    // Només a l'entorn de proves: data simulada (AAAA-MM-DD) o null
    'data_simulada' => null,
];
