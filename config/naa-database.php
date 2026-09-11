<?php
/**
 * NAA · Banco de dados SQL
 * ---------------------------------------------------------
 * O NAA 7 pode trabalhar em dois modos:
 *  - JSON: compatibilidade/offline e instalação sem MySQL.
 *  - MySQL/MariaDB: persistência principal no servidor, com espelho JSON.
 *
 * Para ativar, altere enabled para true e informe os dados do banco.
 * Em produção, prefira variáveis de ambiente NAA_DB_*.
 */
return [
    'enabled' => filter_var(getenv('NAA_DB_ENABLED') ?: '0', FILTER_VALIDATE_BOOLEAN),
    'host' => getenv('NAA_DB_HOST') ?: 'localhost',
    'port' => (int)(getenv('NAA_DB_PORT') ?: 3306),
    'database' => getenv('NAA_DB_NAME') ?: 'naa',
    'username' => getenv('NAA_DB_USER') ?: '',
    'password' => getenv('NAA_DB_PASS') ?: '',
    'charset' => 'utf8mb4',
    'mirror_json' => true,
    'fallback_json' => true,
    'auto_migrate' => true,
];
