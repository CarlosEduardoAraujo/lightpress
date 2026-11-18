# Guia de Implantação em Produção (Deployment Guide) - LightPress

Este guia descreve o passo a passo para colocar o **LightPress** em produção em um servidor Linux (Ubuntu 24.04 LTS ou Debian 12) com **Nginx**, **PHP 8.4-FPM ou PHP 8.5-FPM**, **MariaDB 10.6+ / MySQL 8.0+** e **FastCGI Cache em SSD**.

---

## 1. Requisitos do Sistema

* **SO:** Ubuntu 22.04 / 24.04 LTS ou Debian 12.
* **Hardware Mínimo Recomendado:** 2 vCPU, 4 GiB RAM, SSD/NVMe.
* **Serviços:** Nginx 1.2x+, PHP 8.4-FPM ou PHP 8.5-FPM, MariaDB 10.6+ ou MySQL 8.0+.

---

## 2. Instalação dos Pacotes Essenciais

Atualize o sistema e adicione o repositório oficial do PHP (Ondřej Surý):

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y software-properties-common curl git unzip

# Repositório PHP (8.4 / 8.5)
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

# Opção A — PHP 8.4 com extensões obrigatórias
sudo apt install -y nginx mariadb-server \
    php8.4-fpm php8.4-cli php8.4-mysql php8.4-gd \
    php8.4-mbstring php8.4-curl php8.4-xml php8.4-common php8.4-opcache

# Opção B — PHP 8.5 com extensões obrigatórias
# (No PHP 8.5 o OPcache já é embutido no núcleo; não existe pacote php8.5-opcache nem zend_extension=opcache)
sudo apt install -y nginx mariadb-server \
    php8.5-fpm php8.5-cli php8.5-mysql php8.5-gd \
    php8.5-mbstring php8.5-curl php8.5-xml php8.5-common
```

*(Caso utilize o plugin `redis-cache-pro`, instale também `php8.4-redis` ou `php8.5-redis` conforme a versão ativa.)*

---

## 3. Configuração do Banco de Dados

Acesse o MariaDB e inicialize o banco com a codificação recomendada:

```bash
sudo mysql -u root
```

Execute os comandos SQL:

```sql
CREATE DATABASE lightpress_prod 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

CREATE USER 'lightpress_user'@'localhost' IDENTIFIED BY 'SUA_SENHA_ULTRA_SEGURA_AQUI';
GRANT ALL PRIVILEGES ON lightpress_prod.* TO 'lightpress_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

---

## 4. Clonagem do Repositório e Permissões

Clone o repositório no diretório web do servidor:

```bash
sudo mkdir -p /var/www
cd /var/www
sudo git clone https://github.com/CarlosEduardoAraujo/lightpress.git
cd /var/www/lightpress

# Configurar permissões de escrita nos diretórios de storage e uploads
sudo chown -R www-data:www-data storage public/uploads
sudo chmod -R 775 storage public/uploads
```

---

## 5. Diretório de Cache FastCGI do Nginx

Crie a árvore de cache com o mesmo usuário compartilhado do PHP-FPM (`www-data`):

```bash
sudo mkdir -p /var/cache/nginx/lightpress
sudo chown -R www-data:www-data /var/cache/nginx/lightpress
sudo chmod -R 775 /var/cache/nginx/lightpress
```

---

## 6. Configuração de Ambiente (`config.local.php`)

Copie o modelo de sobreposição local (ignorado pelo Git) e ajuste suas credenciais:

```bash
sudo cp config.local.php.example config.local.php
sudo nano config.local.php
```

Exemplo de conteúdo retornado pelo arquivo:

```php
<?php
declare(strict_types=1);

return [
    'app' => [
        'env' => 'production',
        'debug' => false,
        'canonical_origin' => 'https://seudominio.com.br',
    ],
    'database' => [
        'dbname' => 'lightpress_prod',
        'username' => 'lightpress_user',
        'password' => 'SUA_SENHA_ULTRA_SEGURA_AQUI',
    ],
    'security' => [
        // Gere uma chave de 64 caracteres com: openssl rand -hex 32
        'hmac_secret' => 'CHAVE_ALEATORIA_DE_64_CHARS_GERADA_NO_TERMINAL',
    ],
];
```

Proteja o arquivo de configuração:

```bash
sudo chmod 600 /var/www/lightpress/config.local.php
sudo chown www-data:www-data /var/www/lightpress/config.local.php
```

---

## 7. Instalação do Esquema e Primeiro Administrador

Execute a ferramenta de setup CLI:

```bash
php bin/setup.php
```

> **Atenção:** O instalador exibirá o usuário e a senha aleatória inicial gerada para o primeiro acesso ao painel (`/admin/login.php`). Guarde essas credenciais com segurança.

---

## 8. Configuração do VirtualHost no Nginx

Copie o modelo de configuração fornecido:

```bash
sudo cp nginx/lightpress.conf /etc/nginx/sites-available/lightpress.conf
```

Edite o arquivo substituindo `seudominio.com.br` pelo seu domínio real:

```bash
sudo nano /etc/nginx/sites-available/lightpress.conf
```

Ative o site e emita o certificado SSL gratuito via Certbot:

```bash
sudo ln -s /etc/nginx/sites-available/lightpress.conf /etc/nginx/sites-enabled/
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d seudominio.com.br -d www.seudominio.com.br
sudo nginx -t && sudo systemctl reload nginx
```

---

## 9. Configuração de Tarefas Periódicas (Cron Jobs)

Adicione as rotinas de retentativa de purga de cache e limpeza automática:

```bash
sudo crontab -u www-data -e
```

Adicione as linhas:

```cron
# Retentativa de purgas pendentes de cache (a cada 1 minuto)
* * * * * php /var/www/lightpress/bin/retry-cache-purge.php >> /dev/null 2>&1

# Limpeza periódica de tokens, dedupe e staging temporário (às 03:00 da manhã)
0 3 * * * php /var/www/lightpress/bin/cleanup.php >> /dev/null 2>&1
```

---

## 10. Hardening e Tuning do PHP-FPM (PHP 8.5 / 8.4)

O LightPress não executa binários externos do sistema operacional via web (imagens usam PHP GD nativo e requisições externas usam cURL) e não grava arquivos `.php` em tempo de execução. Por isso, é totalmente compatível com configurações restritivas no pool FPM.

Antes de editar `php.ini`, verifique se não há diretivas duplicadas em `conf.d`:

```bash
grep -rn "opcache\." /etc/php/8.5/fpm/conf.d/ 2>/dev/null
# (No PHP 8.4, cheque também /etc/php/8.4/fpm/conf.d/)
```

### 10.1. Ajustes em `/etc/php/8.5/fpm/php.ini` (ou `8.4/fpm/php.ini`)

```ini
; --- Sessões e Cookies ---
; O LightPress já impõe HttpOnly, Strict Mode e SameSite=Strict em core/Security.php.
; Manter esses padrões no php.ini protege também o runtime global do pool.
session.use_strict_mode = 1
session.cookie_httponly = 1
session.cookie_samesite = "Lax"
; Alinha o garbage collector do PHP ao session_idle_timeout (7200s = 2h) do LightPress:
session.gc_maxlifetime = 7200
; Ative a linha abaixo APENAS se TODOS os sites desse pool FPM operarem exclusivamente em HTTPS:
; session.cookie_secure = 1

; --- Hardening de Execução ---
; O CLI usa /etc/php/8.x/cli/php.ini separado, então scripts bin/*.php não são afetados.
disable_functions = exec,passthru,shell_exec,system,proc_open,popen

; Teto global de memória (recurso nativo do PHP 8.5):
; Impede qualquer script de ultrapassar 512M via ini_set().
; Dica: usando memory_limit = 256M + max_memory_limit = 512M (sem php_admin_value),
; rotas comuns ficam limitadas a 256M enquanto ImageProcessor::processUpload() sobe até 512M sob demanda.
max_memory_limit = 512M

; NÃO ative open_basedir restrito à raiz do projeto:
; 1) Ele desativa o realpath_cache (reduzindo desempenho).
; 2) O CacheManager remove arquivos diretamente em /var/cache/nginx/... fora da pasta do app.

; --- OPcache e Path Cache ---
; No PHP 8.5 o OPcache já é embutido no núcleo (não use zend_extension=opcache).
; Use memory_consumption=128 em servidores com 4 GiB RAM (especialmente se 8.4 e 8.5 rodarem juntos).
opcache.enable = 1
opcache.memory_consumption = 256
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 1
opcache.revalidate_freq = 2
; (Em produção com deploy controlado, revalidate_freq=60 ou validate_timestamps=0 dão máximo ganho,
;  exigindo `sudo systemctl reload php8.5-fpm` após `git pull` ou edição de config.local.php.)

realpath_cache_ttl = 600
```

### 10.2. Ajustes em `/etc/php/8.5/fpm/pool.d/www.conf` (ou `8.4/fpm/pool.d/www.conf`)

```ini
; Restringe explicitamente a execução pelo FPM apenas a arquivos .php (100% compatível com public/)
security.limit_extensions = .php

; Proteção contra sockets/requisições travadas (acima do max_execution_time=300 e do limite de 120s do ImageProcessor)
request_terminate_timeout = 305s

; Slowlog para diagnosticar gargalos (apenas registra stack trace, sem encerrar a requisição).
; Nota: uploads com codificação AVIF, chamadas ao AI Copilot ou Warmup manual podem superar 5s legitimamente.
slowlog = /var/log/php8.5-fpm-slow.log
request_slowlog_timeout = 5s

; Dimensionamento seguro de workers conforme a RAM dedicada ao PHP:
; pm.max_children ≈ (RAM livre para PHP) / (uso médio por worker: ~60-100 MB)
; Em máquinas de 4–8 GiB RAM (ou quando 8.4 e 8.5 coexistem), 10 workers evitam OOM.
; Nota: ImageProcessor::processUpload() eleva memory_limit para 512M sob demanda apenas durante uploads.
pm.max_children = 10
pm.max_requests = 500
```

### 10.3. Criação do Slowlog, Validação e Reload

```bash
sudo touch /var/log/php8.5-fpm-slow.log
sudo chown www-data:www-data /var/log/php8.5-fpm-slow.log
sudo chmod 640 /var/log/php8.5-fpm-slow.log

sudo php-fpm8.5 -t && sudo systemctl reload php8.5-fpm
```

*(Recomenda-se incluir `/var/log/php8.5-fpm-slow.log` no `/etc/logrotate.d/php8.5-fpm` para evitar crescimento indefinido do arquivo de log.)*

Após o reload, acesse `/admin/system.php` para confirmar os indicadores de **OPcache Ativo**, **Sessão Strict Mode**, **GC de Sessão** e **Funções de Shell**.

Pronto! Seu portal **LightPress** estará no ar com altíssimo desempenho, tempo de resposta inferior a 50 ms no cache HIT e segurança completa.
