# Publicação segura no GitHub

O repositório público preserva todas as funcionalidades do código, mas não inclui dados reais de produção.

Nunca versionar:
- `api/data/users.json`, sessões, mensagens, eventos e registros;
- `api/data/push_vapid.json` de produção;
- arquivos de `uploads/`;
- senhas SQL, tokens e chaves de transcrição.

GitHub Pages é hospedagem estática e não executa os endpoints PHP deste sistema. Para manter 100% das funcionalidades, use o GitHub como repositório e faça o deploy do mesmo conteúdo em hospedagem PHP/Apache/Nginx.
