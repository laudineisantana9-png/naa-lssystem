<?php
return [
  'app_id' => 'naa-lssystem',
  'db' => [
    'host' => getenv('NAA_DB_HOST') ?: 'localhost',
    'port' => (int)(getenv('NAA_DB_PORT') ?: 3306),
    'name' => getenv('NAA_DB_NAME') ?: 'naa',
    'user' => getenv('NAA_DB_USER') ?: '',
    'pass' => getenv('NAA_DB_PASS') ?: '',
    'charset' => 'utf8mb4'
  ],
  // SEGURO POR PADRÃO: nunca deixe sincronização pública em produção.
  // Integre com a sessão/token do NAA ou defina um Bearer token forte.
  'allow_public_sync' => false,
  'bearer_token_sha256' => getenv('LS_RUNTIME_BEARER_SHA256') ?: ''
];
