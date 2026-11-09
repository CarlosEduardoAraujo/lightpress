# Revisão de segurança — LightPress

Data: 9 de novembro de 2026. Código revisado: commit `2ce89be`, com árvore de trabalho inicialmente limpa.

A aplicação tem controles defensivos úteis, mas a revisão identificou uma falha de alto impacto condicional no ciclo de ativação de contas e cinco problemas de gravidade média, alguns dependentes da implantação. Recomenda-se corrigir primeiro a revogação dos tokens e a validação do destino de webhooks. Não foi demonstrada execução remota de código, SQL injection ou XSS nesta revisão; isso não constitui garantia de ausência dessas falhas.

**Escopo e limites**

Foram examinados autenticação, autorização editorial, ativação de contas, sessões, APIs públicas, uploads e biblioteca de mídia, compilação de HTML, plugins, cache, configuração Nginx, scripts operacionais e CI. Esta é uma revisão de código e configuração com verificações isoladas; não é um pentest da implantação em produção. A configuração efetiva de PHP-FPM, TLS, firewall, DNS e banco em produção não foi inspecionada.

O PHP encontrado foi `C:\PHP\php.exe`, versão 7.2.3. Ele não atende ao requisito PHP 8.4 do projeto. A suíte completa e os fluxos HTTP não foram executados. Os testes isolados descritos abaixo não carregam o bootstrap, não acessam o banco da aplicação e não substituem testes em PHP 8.4. Não foram alterados código funcional, credenciais ou dados da aplicação.

| ID | Gravidade | Problema | Evidência |
| --- | --- | --- | --- |
| SEC-01 | Alta, condicional | Token antigo permite redefinir senha e reativar conta após intervenção administrativa | SQL real exercitado em banco isolado |
| SEC-02 | Média, condicional | Defesa SSRF de webhooks não vincula a conexão ao IP validado e ignora AAAA | Revisão do fluxo DNS/cURL |
| SEC-03 | Média | Endpoint direto de visualizações contorna o rate limit Nginx | Revisão de rotas e locations |
| SEC-04 | Média | Salvar rascunho de artigo publicado pode alterar a versão pública e deixar cache divergente | Revisão do fluxo editorial |
| SEC-05 | Média | Prazo de sessão configurado não é imposto pela autenticação no servidor | Método real exercitado com banco simulado |
| SEC-06 | Média, condicional | Cabeçalho Cloudflare pode substituir o IP real atrás de proxy confiável | Método real exercitado com entradas controladas |

**SEC-01 — Revogação de sessão não revoga convites e tokens anteriores**

Evidências: [activate.php](../public/admin/activate.php), linhas 27–33, 55–80; [users.php](../public/admin/users.php), linhas 72–84, 130–140 e 159–167; [profile.php](../public/admin/profile.php), linhas 54–61.

Os tokens emitidos duram 24 horas. Alterar a senha pelo administrador ou bloquear uma conta incrementa `session_version`, mas não invalida os registros pendentes de `user_reset_tokens`. O endpoint de ativação aceita qualquer token não usado e não expirado, sem conferir `purpose`, estado de convite ou sua relação com a versão atual da conta. Em seguida, substitui a senha e define `is_active = 1` incondicionalmente.

Condição de exploração: é necessário possuir um token real, ainda não consumido e dentro do prazo. Não há descoberta de tokens nem tomada arbitrária de qualquer conta demonstrada. Entretanto, quem conserva esse token consegue desfazer uma troca administrativa de senha e um bloqueio posterior; se a conta possuir papel administrativo, o impacto alcança o controle do CMS.

Reprodução isolada: emitir token de ativação para uma conta; executar a consulta real de alteração administrativa de senha; bloquear a conta com a consulta real de alternância; executar o lookup e a atualização usados por `activate.php`. O token continuou aceito e a conta voltou a ficar ativa. Uma fixture com `purpose = 'password_reset'` também foi aceita pelo lookup de ativação. Foi exercitado o SQL extraído do código em SQLite em memória, sem executar o endpoint HTTP.

Correção: invalidar tokens pendentes, na mesma transação, quando houver troca de senha, bloqueio ou cancelamento de convite; separar os estados de conta convidada e conta bloqueada; associar tokens a uma versão da conta; restringir o propósito em ambos os lookups, inclusive no bloqueio transacional. Ativação deve revalidar o estado permitido antes de gravar, para também cobrir concorrência com operações administrativas.

Regressão necessária: token emitido antes de troca de senha ou bloqueio deve ser recusado; token de outra finalidade deve ser recusado; token consumido não pode ser reutilizado; corrida entre bloqueio e ativação deve preservar o bloqueio.

**SEC-02 — A validação SSRF não acompanha a conexão efetiva**

Evidências: [webhook-sender.php](../plugins/webhook-sender/webhook-sender.php), linhas 76–126 e 258–295; acesso à configuração restrito a administradores em [plugin-settings.php](../public/admin/plugin-settings.php), linha 15.

`isSafeUrl()` consulta `gethostbynamel()`, verificando apenas endereços IPv4. Depois, `dispatch()` entrega novamente o hostname ao cURL, sem fixar um IP previamente aprovado, nem restringir a família de endereços. Assim, a resolução efetivamente usada pelo cliente HTTP pode ser diferente da validada: por alteração de DNS entre as consultas ou pela existência de um registro AAAA privado junto a um A público.

Condições: plugin ativo, URL configurada por administrador e hostname sob controle de um adversário, ou comprometimento do DNS/destino. A configuração não está disponível a visitantes ou autores. O servidor também precisa ter conectividade com o endereço interno escolhido. O risco é SSRF cega por POST; o código não retorna o corpo da resposta ao usuário. Não foi realizado acesso a serviços internos nem uma exploração de DNS em rede.

Correção: resolver A e AAAA, rejeitar destinos não globais, selecionar um endereço aprovado e vincular a conexão a ele, por exemplo com `CURLOPT_RESOLVE`, preservando hostname/SNI e verificação TLS. Restringir portas e destinos aos necessários e aplicar filtragem de saída. Manter redirecionamentos desativados. Validar o endereço realmente conectado como defesa adicional.

Regressão necessária: A público com AAAA privado; mudança de DNS entre validação e conexão; loopback, link-local e endereços de metadados; domínio público legítimo. A recomendação de tratar A/AAAA e DNS pinning consta na [orientação SSRF da OWASP](https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html).

**SEC-03 — A rota `.php` do contador não recebe o limite de requisições**

Evidências: [lightpress.conf](../nginx/lightpress.conf), linhas 122–125 e 142–165; [view.php](../public/api/view.php), linhas 6–7, 36–65 e 97–140.

O limite `lp_views` é aplicado somente ao prefixo `/api/view/`. O arquivo público também aceita `POST /api/view.php?id=...`, que é processado pelo location PHP genérico, sem `limit_req`. A deduplicação por IP/artigo limita incrementos, mas cada chamada ainda pode executar consultas e transações; ela não substitui um limite de requisições.

Condição: uso do modelo Nginx fornecido sem outra proteção equivalente. Um cliente automatizado pode fornecer `Origin` válido; validação de origem protege contra chamadas indevidas de navegadores, mas não autentica um bot. O impacto principal é carga evitável no PHP/banco. Não foi realizado teste de carga e não há estimativa demonstrada de indisponibilidade.

Correção: bloquear o acesso direto a `view.php` ou aplicar o mesmo limite a todas as formas de acesso, mantendo somente as rotas necessárias. Testar tanto `/api/view/{id}` como `/api/view.php?id={id}`, inclusive para páginas.

**SEC-04 — Salvar rascunho de artigo publicado modifica o conteúdo público**

Evidências: [editor.php](../public/admin/editor.php), linhas 138–204, 216–244 e 283–300; [lightpress.conf](../nginx/lightpress.conf), linha 157.

Para autores sem permissão de publicação, a edição de artigo publicado é corretamente isolada em revisão pendente. Para editores e administradores, porém, o ramo `save_draft` executa `UPDATE posts`, substituindo o conteúdo público. Com a data de publicação preservada, `$statusVal` continua `published`. Além disso, `$wasPublished` só fica verdadeiro quando a data é removida; no caso normal, não há purga de cache nem registro de job nesse ramo.

Condição: editor/administrador salva como rascunho um artigo já publicado, mantendo a data. Isso pode divulgar texto ainda em preparação e deixa leitores recebendo versões diferentes conforme o cache. Não é uma escalada de privilégio: o usuário já pode publicar; o defeito está na intenção da operação e no ciclo de publicação. No modelo fornecido, a resposta do artigo pode permanecer em cache por até 60 minutos.

Correção: salvar rascunhos de artigos publicados em revisão privada, independentemente do papel; reservar a aplicação ao conteúdo público para uma ação explícita de publicação. Toda mudança efetiva da versão pública deve registrar a invalidação transacional e executar a purga após o commit, incluindo URLs antigas quando houver troca de slug.

Regressão necessária: `save_draft` de artigo publicado não altera sua versão pública; aprovação aplica a revisão e invalida o cache; alteração de slug também remove o cache da URL anterior.

**SEC-05 — `session_lifetime` controla o cookie, sem prazo validado no servidor**

Evidências: [Security.php](../core/Security.php), linhas 86–101 e 401–468; [login.php](../public/admin/login.php), linhas 56–60.

`session_lifetime` é passado como `cookie_lifetime`. O login não grava instantes de criação/última atividade e `requireAuth()` não verifica idade nem inatividade. Se o registro de sessão ainda existir no armazenamento PHP, o identificador continua aceito enquanto usuário, estado ativo e `session_version` forem válidos. Expiração do cookie no navegador não equivale a revogação de uma cópia roubada do identificador.

Teste isolado: o método real `requireAuth()` aceitou sessão ativa de fixture com marcadores de criação/atividade de dois dias atrás, mesmo recebendo `session_lifetime = 1`, usando uma resposta de banco simulada. Esses marcadores não são campos atualmente implementados pelo login. O teste demonstra ausência da checagem no método, não a duração efetiva de sessões no PHP-FPM de produção; a coleta de sessões do PHP pode removê-las antes.

Correção: implementar prazos absoluto e de inatividade no servidor, conferir antes de autorizar cada requisição e destruir a sessão ao expirar; manter também a revogação por versão. A [orientação de sessões da OWASP](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html) recomenda impor a expiração no servidor.

**SEC-06 — Confiança no proxy é estendida a cabeçalhos de origens distintas**

Evidências: [Security.php](../core/Security.php), linhas 215–240; [login.php](../public/admin/login.php), linhas 23–28.

Após reconhecer `REMOTE_ADDR` como proxy confiável, `getClientIp()` prioriza qualquer `CF-Connecting-IP` válido antes de `X-Real-IP`. O código não distingue qual proxy está autorizado a atestar cada cabeçalho. Em uma topologia com proxy local confiável que repasse `CF-Connecting-IP` do cliente, esse valor controlado pelo cliente substitui o IP apurado pelo proxy.

Teste isolado: com peer `127.0.0.1`, `CF-Connecting-IP = 198.51.100.10` e `X-Real-IP = 203.0.113.20`, o método real retornou `198.51.100.10`. Com peer não confiável, ignorou corretamente o cabeçalho forjado. Os endereços usados são exemplos reservados para documentação.

Impacto condicional: variar esse cabeçalho pode contornar o bloqueio de login por IP na aplicação e a deduplicação do contador, ou atribuir bloqueios a outro IP. Isso exige proxy confiável que não sobrescreva/remova o cabeçalho; não foi demonstrado na implantação do usuário. O rate limit Nginx, se baseado no IP real e corretamente aplicado, permanece uma proteção independente.

Correção: definir a origem autorizada para cada cabeçalho; remover cabeçalhos de identidade recebidos de fontes indevidas; preferir um IP normalizado pelo proxy, com cadeia de confiança explícita. Só aceitar o cabeçalho Cloudflare quando a cadeia de proxies comprovar sua procedência.

**Verificações realizadas e resultados**

- `git status --short`: árvore inicialmente limpa; ao final, apenas este relatório foi adicionado ao repositório.
- `npm.cmd audit --json --ignore-scripts`: consulta concluída; 73 dependências contabilizadas, nenhuma vulnerabilidade conhecida retornada. Isso cobre o inventário npm local, não PHP, GD/libavif, Nginx, banco ou plugins externos.
- Métodos reais de `Security.php`: executados de forma isolada com PHP 7.2, sessão de fixture e banco simulado; confirmaram a ausência de prazo em `requireAuth()` e a precedência de `CF-Connecting-IP` no cenário de proxy confiável.
- Consultas SQL reais da ativação/administração: extraídas dos arquivos e executadas em SQLite em memória; confirmaram persistência do token após troca administrativa de senha, aceitação para conta bloqueada, reativação e ausência de restrição por finalidade.
- Suíte integral, fuzzing de HTML, testes HTTP em PHP 8.4, SSRF em rede e testes de carga: não executados. A revisão estática de SSRF, cache e rate limit precisa de regressão no ambiente de homologação.

Scripts reproduzíveis desta revisão estão nos artefatos da conversa: `security-method-checks.php` e `security-token-check.py`, na pasta `C:\Users\Jogos\.codex\visualizations\2026\11\09\01a1c1fc-386d-7803-9676-5974cbe1a2ac`.

**Controles positivos e melhorias complementares**

Foram observados prepared statements com parâmetros tipados, hashing Argon2id no código, regeneração de sessão no login, conferência de estado/versão da conta por requisição, validação CSRF, controles de autoria nos editores, sanitização DOM por lista permitida, validação de MIME/dimensões de imagens e separação da raiz pública. Esses controles reduzem a superfície de ataque, embora não tenham sido submetidos a validação exaustiva.

Há ações mutáveis por GET com token na URL em gerenciamento de páginas, mídia, usuários e dashboard. Migrar para POST evita tokens em histórico e access logs; a presença do token não autoriza concluir que exista CSRF sem conhecê-lo. A CSP permite `unsafe-inline`, reduzindo sua utilidade como defesa adicional contra XSS; migrar gradualmente para nonce/hash. O fallback de CSS executa JavaScript de CDN caso o CSS local esteja ausente; fazer o build parte obrigatória da implantação reduz essa dependência.

O `package-lock.json` existe localmente, mas está ignorado pelo Git: versioná-lo e usar instalação determinística ajuda a reproduzir o inventário auditado. Os testes de webhook atuais esperam aceitação de domínios de exemplo sem simular DNS, incluindo `.test`, o que conflita com a resolução exigida pelo validador; ajustar fixtures e incluir regressões A/AAAA e DNS rebinding. A CI não executa separadamente `bin/test-plugins.php`.

A troca administrativa de senha e o bloqueio devem cancelar vínculos de recuperação ainda válidos, coerentemente com a [orientação de recuperação de contas da OWASP](https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html). As classificações acima consideram os pré-requisitos descritos, sem presumir exposição de produção ou comprometimento prévio.
