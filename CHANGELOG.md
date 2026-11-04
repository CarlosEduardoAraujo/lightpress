# Changelog

Todas as alterações notáveis neste projeto serão documentadas neste arquivo.

O formato é baseado no [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/),
e este projeto adere ao [Versionamento Semântico](https://semver.org/lang/pt-BR/).

## [Unreleased]

### Adicionado
- Especificação técnica inicial detalhada e revisada no `README.md`.
- Estrutura base de diretórios (`core/`, `storage/`, `templates/`, `admin/`, `bin/`, `public/`).
- Arquivo `.editorconfig` e `.gitignore` para padronização e proteção de arquivos temporários, logs e uploads.
- Arquivo central de configuração `config.php` com suporte a sobreposições locais (`config.local.php`).
- Utilitário CLI `bin/diagnose.php` para diagnóstico de ambiente, versões de PHP, extensões (`gd`, `fileinfo`, `pdo`, etc.), capacidades WebP/AVIF e permissões.
- Camada unificada e segura de conexão e transações PDO em `core/Database.php` com suporte a MySQL/MariaDB e SQLite.
- Licença Open Source MIT em `LICENSE`.
