# Guia de Contribuição - LightPress / SpeedPress

Agradecemos o seu interesse em contribuir com o **LightPress**! Este é um projeto *open source* desenhado para redefinir a performance de portais de conteúdo e notícias de grande escala.

---

## 1. Princípios e Filosofia do Projeto

Antes de abrir uma issue ou Pull Request, certifique-se de que sua proposta respeita os pilares fundamentais:

1. **Zero Dependências Pesadas:** Não adicione pacotes Composer ao caminho crítico de leitura pública. A entrega deve permanecer em PHP 8.4 puro e nativo.
2. **Modelo Relacional Plano:** Evite modelos EAV (`postmeta`) ou junções desnecessárias. Toda informação central deve residir em tabelas indexadas diretas.
3. **Segurança Proativa por Padrão (Security by Design):** Prepared Statements em 100% das consultas, escape contextual contra XSS (`Security::e()`), validação estrita no `BlockCompiler` e verificação de CSRF em mutações.
4. **Compatibilidade com Nginx FastCGI Cache:** Invalidações de cache devem ser atômicas e respeitar o algoritmo de cálculo de diretórios `levels=1:2`.

---

## 2. Padrões de Código

* **Versão do PHP:** PHP 8.4+ com modo estrito obrigatório no topo de cada arquivo:
  ```php
  <?php
  declare(strict_types=1);
  ```
* **Estilo de Código:** PSR-12, indentação de 4 espaços, classes tipadas, retorno de funções explícito e classes `readonly` quando imutáveis.
* **Segurança:** Nunca execute consultas SQL com concatenação direta de variáveis de entrada. Use sempre `Database::query($sql, [$param])`.
* **Frontend:** Mantenha o JavaScript do editor e do painel estritamente em **Vanilla JS** leve (< 50 KiB gzip). Não utilize React, Vue ou Angular no core.

---

## 3. Como Executar os Testes

Execute a suíte nativa de testes antes de submeter alterações:

```bash
php bin/test.php
```

Todos os testes unitários devem ser aprovados sem falhas. Se você adicionar novos recursos (ex: novo bloco semântico ou utilitário de segurança), inclua o teste correspondente em `bin/test.php`.

---

## 4. Convenção de Commits

Adotamos a convenção do [Conventional Commits](https://www.conventionalcommits.org/):

* `feat:` Uma nova funcionalidade (ex: `feat: adiciona bloco de video incorporado seguro`).
* `fix:` Correção de um bug (ex: `fix: corrige deteccao de orientacao EXIF em JPEG`).
* `perf:` Uma melhoria focada exclusivamente em performance ou I/O.
* `docs:` Alterações na documentação (`README.md`, `CHANGELOG.md`).
* `refactor:` Refatoração de código sem alteração no comportamento externo.

---

## 5. Como Submeter um Pull Request

1. Faça um Fork do repositório.
2. Crie uma branch com o nome da feature (`git checkout -b feature/minha-melhoria`).
3. Faça os commits seguindo a convenção.
4. Execute `php bin/test.php` e garanta aprovação de 100%.
5. Atualize o [CHANGELOG.md](CHANGELOG.md) na seção `[Unreleased]`.
6. Abra o Pull Request detalhando o problema resolvido e as medições de impacto.
