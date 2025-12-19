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
apt install -y apache2 php libapache2-mod-php php-mysql php-intl php-xml php-mbstring php-curl php-zip php-gd php-cli php-bcmath php-soap php-ldap php-imap php-memcached php-redis php-xdebug git unzip
apt install -y snapd
apt install -y gh
snap install --classic certbot
ln -s /snap/bin/certbot /usr/bin/certbot
clear
echo "Enabling Apache modules..."
a2enmod rewrite headers ssl
systemctl restart apache2
clear



