# Guia de Implantação em Produção (Deployment Guide) - LightPress

Este guia descreve o passo a passo para colocar o **LightPress** em produção em um servidor Linux (Ubuntu 24.04 LTS ou Debian 12) com **Nginx**, **PHP 8.4-FPM**, **MariaDB 10.6+ / MySQL 8.0+** e **FastCGI Cache em SSD**.

---

## 1. Requisitos do Sistema

* **SO:** Ubuntu 22.04 / 24.04 LTS ou Debian 12.
* **Hardware Mínimo Recomendado:** 2 vCPU, 4 GiB RAM, SSD/NVMe.
* **Serviços:** Nginx 1.2x+, PHP 8.4-FPM, MariaDB 10.6+ ou MySQL 8.0+.

---

## 2. Instalação dos Pacotes Essenciais

Atualize o sistema e adicione o repositório oficial do PHP 8.4 (Ondřej Surý):

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y software-properties-common curl git unzip

# Repositório PHP 8.4
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

# Instalar Nginx, MariaDB e PHP 8.4 com extensões obrigatórias
sudo apt install -y nginx mariadb-server \
    php8.4-fpm php8.4-cli php8.4-mysql php8.4-gd \
    php8.4-mbstring php8.4-common php8.4-opcache
```

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

Pronto! Seu portal **LightPress** estará no ar com altíssimo desempenho, tempo de resposta inferior a 50 ms no cache HIT e segurança completa.
