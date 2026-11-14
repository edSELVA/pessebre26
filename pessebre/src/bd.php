<?php
// Base de dades: connexió PDO (SQLite o MySQL/MariaDB), esquema i configuració.
// Les hores es desen en UTC com a text 'AAAA-MM-DD hh:mm:ss' (es poden comparar com a text).
declare(strict_types=1);

class ErrorPessebre extends Exception
{
    public $estat;
    public $codi;
    public function __construct(string $missatge, int $estat = 400, string $codi = 'error')
    {
        parent::__construct($missatge);
        $this->estat = $estat;
        $this->codi = $codi;
    }
}

function bd_connecta(array $cfg): PDO
{
    $opcions = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false];
    if (($cfg['bd_tipus'] ?? 'sqlite') === 'mysql') {
        $m = $cfg['bd_mysql'];
        $pdo = new PDO($m['dsn'], $m['usuari'], $m['clau'], $opcions);
        $pdo->exec("SET time_zone = '+00:00'");
    } else {
        $pdo = new PDO('sqlite:' . $cfg['bd_sqlite'], null, null, $opcions);
        $pdo->exec('PRAGMA busy_timeout = 5000');
    }
    return $pdo;
}

function bd_es_mysql(PDO $pdo): bool { return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'; }

/** Crea les taules si no existeixen (es pot executar tantes vegades com calgui). */
function bd_esquema(PDO $pdo): void
{
    $mysql = bd_es_mysql($pdo);
    $auto = $mysql ? 'INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY';
    $fi = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '';
    $taules = [
        "llocs (id VARCHAR(8) NOT NULL PRIMARY KEY, tipus VARCHAR(10) NOT NULL, zona VARCHAR(2) NOT NULL,
                grup VARCHAR(10) NOT NULL, x INTEGER NOT NULL, y INTEGER NOT NULL, codi VARCHAR(30) NULL, mirall INTEGER NOT NULL DEFAULT 0)",
        "classes (id $auto, etapa VARCHAR(12) NOT NULL, nom VARCHAR(40) NOT NULL UNIQUE, ordre INTEGER NOT NULL, codi_hash CHAR(64) NULL UNIQUE)",
        "enllacos (id $auto, tipus VARCHAR(10) NOT NULL, etapa VARCHAR(12) NULL, hash CHAR(64) NOT NULL UNIQUE,
                   activa INTEGER NOT NULL DEFAULT 1, creat VARCHAR(19) NOT NULL)",
        // lloc_id és la clau: la base de dades mateixa impedeix dues ocupacions del mateix lloc;
        // classe_id és únic: una classe no pot tenir dues cases (ni retingudes ni confirmades).
        "ocupacions (lloc_id VARCHAR(8) NOT NULL PRIMARY KEY, estat VARCHAR(10) NOT NULL, origen VARCHAR(10) NOT NULL,
                     classe_id INTEGER NULL UNIQUE, codi_id INTEGER NULL, peca VARCHAR(30) NULL, mirall INTEGER NULL,
                     colors VARCHAR(40) NULL, finestres INTEGER NULL, hash_retencio CHAR(64) NULL, caduca VARCHAR(19) NULL, hora VARCHAR(19) NOT NULL)",
        "codis (id $auto, hash CHAR(64) NOT NULL UNIQUE, lot INTEGER NOT NULL, usos INTEGER NOT NULL DEFAULT 0, creat VARCHAR(19) NOT NULL)",
        "registre (id $auto, hora VARCHAR(19) NOT NULL, qui VARCHAR(60) NOT NULL, accio VARCHAR(30) NOT NULL,
                   lloc_id VARCHAR(8) NULL, detall VARCHAR(200) NULL)",
        "config (clau VARCHAR(40) NOT NULL PRIMARY KEY, valor VARCHAR(200) NOT NULL)",
        // Fites: posta (llindar de cases de classe), nit i neu (llindar de participacions reals). increment en % (de les 74 cases
        // o de l'objectiu); llindar es fixa quan salta l'anterior; assolida = hora UTC; per = 'llindar' o 'data'.
        "fites (numero INTEGER NOT NULL PRIMARY KEY, moment VARCHAR(10) NOT NULL, base VARCHAR(15) NOT NULL, increment INTEGER NOT NULL,
                data_limit VARCHAR(19) NOT NULL, llindar INTEGER NULL, assolida VARCHAR(19) NULL, per VARCHAR(10) NULL)",
        "intents (empremta CHAR(64) NOT NULL PRIMARY KEY, finestra VARCHAR(19) NOT NULL, fallades INTEGER NOT NULL)",
        // Comptes d'administració personals (§31): nom, password_hash i nivell ('admin' o 'lectura')
        "usuaris (id $auto, nom VARCHAR(30) NOT NULL UNIQUE, hash VARCHAR(255) NOT NULL, rol VARCHAR(10) NOT NULL, creat VARCHAR(19) NOT NULL)",
    ];
    foreach ($taules as $t) $pdo->exec("CREATE TABLE IF NOT EXISTS $t$fi");
    // Bases de dades creades abans d'afegir les finestres (11 nov)
    try { $pdo->query('SELECT finestres FROM ocupacions LIMIT 1'); }
    catch (PDOException $e) { $pdo->exec('ALTER TABLE ocupacions ADD COLUMN finestres INTEGER NULL'); }
    // … i abans dels codis de classe (14 nov)
    try { $pdo->query('SELECT codi_hash FROM classes LIMIT 1'); }
    catch (PDOException $e) {
        $pdo->exec('ALTER TABLE classes ADD COLUMN codi_hash CHAR(64) NULL');
        $pdo->exec('CREATE UNIQUE INDEX classes_codi ON classes (codi_hash)');
    }
}

/** Hora UTC en format de base de dades. */
function bd_hora(DateTimeImmutable $t): string
{
    return $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function config_llegeix(PDO $pdo, string $clau, ?string $defecte = null): ?string
{
    $st = $pdo->prepare('SELECT valor FROM config WHERE clau = ?');
    $st->execute([$clau]);
    $v = $st->fetchColumn();
    return $v === false ? $defecte : (string)$v;
}

function config_desa(PDO $pdo, string $clau, string $valor): void
{
    $pdo->prepare('DELETE FROM config WHERE clau = ?')->execute([$clau]);
    $pdo->prepare('INSERT INTO config (clau, valor) VALUES (?, ?)')->execute([$clau, $valor]);
}

/** Qui fa les accions d'administració d'aquesta petició (el nom del compte), perquè el registre ho digui. */
function registre_qui(?string $nom = null, bool $posa = false): ?string
{
    static $qui = null;
    if ($posa) $qui = $nom;
    return $qui;
}

function registre_afegeix(PDO $pdo, DateTimeImmutable $ara, string $qui, string $accio, ?string $lloc = null, ?string $detall = null): void
{
    if ($qui === 'administració' && registre_qui() !== null) $qui = 'administració · ' . registre_qui();
    $pdo->prepare('INSERT INTO registre (hora, qui, accio, lloc_id, detall) VALUES (?, ?, ?, ?, ?)')
        ->execute([bd_hora($ara), mb_substr($qui, 0, 60), $accio, $lloc, $detall === null ? null : mb_substr($detall, 0, 200)]);
}
