# NAA preparado para LS Runtime 2.0

O NAA possui manifesto assinado da LS System Ltda. em `/.well-known/ls-runtime.json`.

## Identidade

- App ID: `naa-lssystem`
- Package/Bundle ID: `br.com.lssystem.naa`
- Nome: `NAA — Núcleo de Avaliação da Aprendizagem`
- Nome curto: `NAA`
- Ícone: `naa-icon-512.png`
- Cor principal: `#0066CC`

## GitHub Pages

No GitHub Pages o LS Runtime pode validar a assinatura, baixar os arquivos declarados no manifesto e empacotar o NAA para uso offline. Como GitHub Pages não executa PHP/MySQL, a sincronização central fica opcional.

## Servidor PHP/MySQL

Para ativar LS Sync no domínio próprio, publique `api/ls-runtime/`, importe `sql/ls_runtime.sql` e integre a autenticação ao NAA. O exemplo é seguro por padrão e não habilita sincronização pública.

## Branding nativo

O LS Runtime Builder lê o manifesto e gera builds dedicados com nome e identidade NAA para Windows, macOS, Android e iOS. O nome/ícone do arquivo executável é definido no momento do build, não por alteração posterior do binário.
