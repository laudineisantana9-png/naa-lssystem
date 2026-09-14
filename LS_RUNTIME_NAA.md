# NAA + LS Runtime — configuração oficial

URL oficial: `https://naa.lssystem.com.br/`

## Identidade

- App ID LS: `naa-lssystem`
- Application/Bundle ID: `br.com.lssystem.naa`
- Produto instalado: `NAA`
- Publisher: `LS System Ltda.`
- Manifesto assinado: `/.well-known/ls-runtime.json`

## Arquivos do servidor

O domínio oficial precisa publicar:

- `/.well-known/ls-runtime.json`
- `/api/ls-runtime/health.php`
- `/api/ls-runtime/bootstrap.php`
- `/api/ls-runtime/pull.php`
- `/api/ls-runtime/push.php`
- `/api/ls-runtime/common.php`
- `/sql/ls_runtime.sql`

Crie `/api/ls-runtime/config.php` no servidor a partir de `config.example.php`, usando as mesmas credenciais MySQL do NAA. Mantenha `allow_public_sync=false` e `auth_mode=naa_session`. Não envie o `config.php` com senhas para o GitHub.

## Banco

Execute `sql/ls_runtime.sql` no banco do NAA. Ele cria as tabelas de registros, fila de mudanças, confirmações e dispositivos do LS Sync v2.

## Como o offline funciona

O aplicativo mantém o frontend e os dados do navegador localmente. O Runtime espelha localStorage/IndexedDB no SQLite nativo e registra as alterações em uma outbox. Quando a internet retorna, envia as alterações ao servidor e depois recebe as mudanças dos demais dispositivos.

A sincronização só é liberada depois que o usuário entra normalmente no NAA, pois o Runtime reutiliza a sessão NAA para autenticar `push/pull/bootstrap`.

## Gerar os aplicativos

Use o LS Runtime Universal Builder com a URL oficial:

`node builder/ls-runtime-builder.mjs https://naa.lssystem.com.br/ generated/NAA`

O Builder lê o manifesto assinado, baixa `naa-icon-512.png`, copia o pacote offline e configura o nome NAA em Windows, macOS, Android e iOS.

A assinatura final para Google Play e App Store continua dependente das contas de desenvolvedor Google/Apple da LS System Ltda.
