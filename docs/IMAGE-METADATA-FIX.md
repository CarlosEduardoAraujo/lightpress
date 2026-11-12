# Correção dos metadados de imagens

O resolvedor individual e em lote usa `media.alt_text`, depois `caption` e depois o nome do arquivo. A recuperação alternativa usa nomes completos com extensão e exige correspondência de pasta e nome base no manifesto. Homônimos ambíguos não são associados automaticamente. A imagem principal consulta metadados atuais após recuperar o post do cache, usando o ID quando compatível com a imagem.

O namespace `media_desc:v2` ignora descrições antigas. Falhas de banco e vínculos ausentes não persistem fallbacks. Editar metadados manualmente ou por IA limpa memória e os grupos media/posts e registra purgas para Home, paginação, categorias, notícias relacionadas, tags e páginas institucionais identificadas. Lotes acima de 30 URLs são processados pelo worker existente `bin/retry-cache-purge.php`; manter seu cron ativo. Pesquisas com query strings arbitrárias já em cache dependem de expiração ou purga explícita. O HTML das imagens no corpo não é reescrito.

## Validação local isolada

```sh
php bin/test-image-descriptions.php
php bin/test-cache-purge-results.php
```

Requer PHP 8 com PDO SQLite e mbstring. Os testes não carregam credenciais locais, usam SQLite em memória e simulam cache/purga externos.

## Aplicação em produção

1. Publicar os arquivos da correção pelo procedimento habitual.
2. Executar `php bin/audit-media-links.php` e revisar o JSON. O padrão não executa INSERT/UPDATE. O script não cria tabelas e não aciona o bootstrap editorial.
3. Para os vínculos inequívocos, executar `php bin/audit-media-links.php --apply`. A reparação é aditiva e não sobrescreve vínculos conflitantes nem IDs preenchidos. Rever conflitos manualmente. Executar novamente o diagnóstico para confirmar o resultado.
4. Invalidar o HTML antigo com o utilitário existente de purga. Para eliminar também buscas e variantes de URL antigas, a purga total (`php bin/purge-cache.php --all`) deve ser feita na janela de publicação. Não executar o reparo ou a purga apenas para diagnosticar.
5. Conferir a matéria de Felipe Fraga e a Home com cache MISS e HIT. Ambos os atributos devem conter o texto de `media.alt_text`. Editar o texto da mídia e confirmar a atualização após processar a fila.

Nenhuma operação em produção foi executada na implementação local.
