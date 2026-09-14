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
  // Nunca deixe sincronização pública em produção.
  'allow_public_sync' => false,
  // O Runtime reutiliza a sessão autenticada normal do NAA.
  'auth_mode' => getenv('LS_RUNTIME_AUTH_MODE') ?: 'naa_session',
  // Usado somente se auth_mode for bearer_sha256.
  'bearer_token_sha256' => getenv('LS_RUNTIME_BEARER_SHA256') ?: ''
];
