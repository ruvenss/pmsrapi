#!/bin/bash
# Author: Ruvenss G. Wilches
# Description: This script installs and deploys a basic LAP stack (Linux, Apache, PHP8.3) on a Hetzner server. including a Git Repository clone of your project.
clear
if [ "$EUID" -eq 0 ]; then
    echo "Starting installation of public microservice..."
else
    echo "You are NOT running as root. Please run as root."
    exit 1
fi
CURRENT_DIR=$(pwd)
PARENT_DIR=$(dirname "$CURRENT_DIR")
TOKEN=$(openssl rand -base64 24 | tr -d '=+/')
git_secret_token=$(openssl rand -base64 32 | tr -d '=+/')
echo "Installing necessary packages..."
apt update && apt upgrade -y
apt update && sudo apt install -y apache2
a2dismod mpm_prefork
a2enmod mpm_event
a2enmod ssl http2 deflate headers expires rewrite
apt install -y php8.3-fpm php8.3-cli php8.3-common php8.3-mysql php8.3-redis php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-intl php8.3-gd
apt install -y mc git curl unzip ncdu
# Restart Apache to apply changes
systemctl restart apache2
a2enmod proxy_fcgi setenvif
a2enconf php8.3-fpm
systemctl restart apache2
sudo tee /etc/php/8.3/fpm/pool.d/www.conf > /dev/null <<EOL
[www]
user = www-data
group = www-data
listen = /run/php/php8.3-fpm.sock
listen.backlog = 65535
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = 25
pm.start_servers = 4
pm.min_spare_servers = 3
pm.max_spare_servers = 8
pm.max_requests = 500
slowlog = /var/log/php8.3-fpm-slow.log
request_slowlog_timeout = 5
php_admin_value[memory_limit] = 128M
php_admin_value[max_execution_time] = 30
php_admin_value[upload_max_filesize] = 32M
php_admin_value[post_max_size] = 32M
EOL
sudo tee /etc/php/8.3/fpm/conf.d/99-perf.ini > /dev/null <<EOL
; OPcache
opcache.enable = 1
opcache.memory_consumption = 256       ; MB — increase for large apps
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 20000
opcache.revalidate_freq = 0            ; 0 = never recheck (production)
opcache.validate_timestamps = 0        ; Fastest — disable in prod, enable in dev
opcache.save_comments = 1

; JIT (PHP 8.x)
opcache.jit = tracing
opcache.jit_buffer_size = 64M

; General
memory_limit = 256M
max_execution_time = 30
realpath_cache_size = 4096K
realpath_cache_ttl = 600
EOL
apt install -y snapd
apt install -y gh
snap install --classic certbot
ln -s /snap/bin/certbot /usr/bin/certbot
clear
echo "Enabling Apache modules..."
a2enmod rewrite headers ssl
systemctl restart apache2
clear



