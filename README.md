# NAA — Núcleo de Avaliação da Aprendizagem

Sistema institucional do Núcleo de Avaliação da Aprendizagem, powered by **LS System**.

## Estrutura preservada

Este repositório contém o código completo do NAA, incluindo:

- CNCA — Anos Iniciais, com Ciclos I, II e III e bases independentes por Município, Escola, Turma e Habilidades;
- Anos Finais, com Ciclos I, II e III e bases independentes;
- SABE, com Ciclo Único e bases independentes por Município, Escola, Turma e Habilidades;
- dashboards, intervenções pedagógicas, BNCC, uploads, PWA/offline, PDF institucional;
- usuários, permissões, mensagens, notificações e LS Meet;
- persistência JSON e suporte opcional a MySQL/MariaDB;
- comparação entre modalidades em modal independente.

## Comparação entre modalidades — NAA 7.6.0

O cabeçalho possui o atalho **Comparar modalidades**. A janela não altera o dashboard nem os uploads existentes e oferece:

- SABE 2025 — 2º ano × CNCA 2026 — 3º ano, com escolha do Ciclo I, II ou III;
- SABE 2025 — 5º ano × Anos Finais 2026 — 6º ano, com escolha do ciclo;
- SABE 2025 — 9º ano × Anos Finais 2026 — 9º ano como comparação de etapa (não da mesma coorte).

A comparação usa indicadores homólogos. Proficiência SABE e acerto das avaliações municipais são exibidos lado a lado, sem tratá-los como a mesma escala.

## Publicação e dados

Os arquivos de **dados de produção, sessões, mensagens, transcrições, anexos, chaves Push e credenciais** não fazem parte do repositório público. As pastas e APIs permanecem no projeto e são recriadas/preenchidas pela instalação.

Para executar todas as funcionalidades (sincronização multiusuário, mensagens, LS Meet, PDFs de servidor e MySQL), publique o projeto em um servidor com **PHP**. O GitHub hospeda o código-fonte; GitHub Pages, sozinho, não executa PHP.

## Banco SQL

O schema está em `sql/naa.sql`. Configure `NAA_DB_*` por variáveis de ambiente conforme `config/naa-database.php`.

## Transcrição LS Meet

Configure as variáveis `NAA_TRANSCRIBE_*`. Consulte `api/transcription-config.example.php`.
