# NAA — Núcleo de Avaliação da Aprendizagem

Sistema institucional do Núcleo de Avaliação da Aprendizagem, powered by **LS System**.

## NAA 7.6.0

Este repositório preserva o NAA completo e acrescenta apenas uma nova comparação entre modalidades, em modal separada, sem alterar os dashboards, uploads, cálculos ou módulos existentes.

A comparação inclui:
- **SABE 2025 — 2º ano × CNCA 2026 — 3º ano**, com escolha do Ciclo I, II ou III do CNCA;
- **SABE 2025 — 5º ano × Anos Finais 2026 — 6º ano**, com escolha do ciclo;
- **SABE 2025 — 9º ano × Anos Finais 2026 — 9º ano**, apresentada como comparação da etapa/rede, pois não corresponde à mesma coorte de estudantes.

A janela permite escolher Município, Escola ou Turma e mantém indicadores de escalas diferentes lado a lado sem tratá-los como equivalentes.

## Estrutura preservada

- CNCA — Anos Iniciais, Ciclos I, II e III, com Município/Escola/Turma/Habilidades independentes;
- Anos Finais, Ciclos I, II e III, com as mesmas bases independentes;
- SABE, Ciclo Único, com Município/Escola/Turma/Habilidades independentes;
- dashboards, filtros, comparação de ciclos, intervenções, BNCC e planos de ação;
- PDF institucional SABE;
- uploads independentes e exclusão individual;
- PWA/offline e Service Worker;
- usuários, permissões, mensagens, notificações e LS Meet;
- persistência JSON e suporte opcional a MySQL/MariaDB.

## Publicação

GitHub Pages é uma hospedagem estática e não executa PHP. Para manter sincronização multiusuário, SQL, LS Meet, geração de PDF no servidor e demais APIs, publique o mesmo código em uma hospedagem com PHP. O GitHub permanece como repositório oficial do código.

Dados reais de produção, sessões, mensagens, anexos, credenciais, chaves Push e segredos não devem ser versionados em repositório público.
