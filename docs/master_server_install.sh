#!/bin/bash

# --------------------
# Full Ubuntu 20.04 Server Setup Script (Parameterized + Email + HTTPS Redirect)

# chmod +x master_server_install.sh
# sudo ./master_server_install.sh SITENAME POSTGRES_PASSWORD YOUR_EMAIL

# Example:
# sudo ./master_server_install.sh mycoolsite mypgpass admin@mycoolsite.org
# --------------------

# Check if run as root
if [[ $EUID -ne 0 ]]; then
   echo "This script must be run as root. Try: sudo ./master_server_install.sh SITENAME POSTGRES_PASSWORD YOUR_EMAIL"
   exit 1
fi

# Check if parameters are passed
if [ $# -lt 3 ]; then
    echo "Usage: sudo ./master_server_install.sh SITENAME POSTGRES_PASSWORD YOUR_EMAIL"
    exit 1
fi

# Variables from parameters
SITE_NAME="$1"
DB_PASS="$2"
EMAIL="$3"
USER_NAME="user1"

echo "Setting up server for site: $SITE_NAME"
echo "Postgres password will be set."
echo "Certbot registration email: $EMAIL"

# Update and upgrade
apt update && apt upgrade -y

# Create a user
adduser --gecos "" $USER_NAME
adduser $USER_NAME sudo
adduser $USER_NAME www-data

# Firewall setup
ufw --force enable
ufw allow 22
ufw allow 80
ufw allow 443
ufw allow 8080
ufw allow 8001
ufw allow 5432
ufw allow https

# Install essential packages
apt install -y fail2ban apache2 postgresql zip unzip php-pear php-fpm php-dev php-zip php-curl php-xmlrpc php-gd php-mbstring php-xml libapache2-mod-php php-curl php-json php-imagick php-dom php-pgsql php-cli composer

# Enable mod_rewrite
a2enmod rewrite

# Fail2ban
systemctl enable fail2ban

# SSH Hardening: Disallow root login
cp /etc/ssh/sshd_config /etc/ssh/sshd_config.bak
sed -i 's/^#\?PermitRootLogin .*/PermitRootLogin no/' /etc/ssh/sshd_config
systemctl restart sshd

# PostgreSQL Configuration
cp /etc/postgresql/12/main/postgresql.conf /etc/postgresql/12/main/postgresql.conf.bak
sed -i '/listen_addresses/s/^#//g' /etc/postgresql/12/main/postgresql.conf
sed -i "/listen_addresses =/ s/= .*/= '*'/" /etc/postgresql/12/main/postgresql.conf

# Modify pg_hba.conf for password authentication
cp /etc/postgresql/12/main/pg_hba.conf /etc/postgresql/12/main/pg_hba.conf.bak
sed -i 's/^\(local\s\+all\s\+postgres\s\+\)peer/\1md5/' /etc/postgresql/12/main/pg_hba.conf
sed -i 's/^\(local\s\+all\s\+all\s\+\)peer/\1md5/' /etc/postgresql/12/main/pg_hba.conf
sed -i 's/^\(host\s\+all\s\+all\s\+127\.0\.0\.1\/32\s\+\)ident/\1md5/' /etc/postgresql/12/main/pg_hba.conf'
sed -i 's/^\(host\s\+all\s\+all\s\+::1\/128\s\+\)ident/\1md5/' /etc/postgresql/12/main/pg_hba.conf'
# Allow remote connections
echo "host all all 0.0.0.0/0 md5" >> /etc/postgresql/12/main/pg_hba.conf

systemctl restart postgresql

# PostgreSQL secure setup
sudo -u postgres psql -c "ALTER USER postgres PASSWORD '${DB_PASS}';"
sudo -u postgres createuser -d -l -P -r -s $USER_NAME

# PHP Configuration
cp /etc/php/7.4/apache2/php.ini /etc/php/7.4/apache2/php.ini.bak
sed -i 's/^post_max_size =.*/post_max_size = 50M/' /etc/php/7.4/apache2/php.ini
sed -i 's/^upload_max_filesize =.*/upload_max_filesize = 50M/' /etc/php/7.4/apache2/php.ini
sed -i 's/^;extension=pdo_pgsql/extension=pdo_pgsql/' /etc/php/7.4/apache2/php.ini
sed -i 's/^error_reporting = .*/error_reporting = E_ALL \& ~E_DEPRECATED \& ~E_STRICT \& ~E_WARNING \& ~E_NOTICE/' /etc/php/7.4/apache2/php.ini

# Add user to www-data group
usermod -a -G www-data $USER_NAME

# Web root permissions
chown -R www-data /var/www/
chgrp -R $USER_NAME /var/www
chmod 775 /var/www
chmod g+s /var/www

# Website directory structure
mkdir -p /var/www/html/${SITE_NAME}/{public_html,theme,static_files,config,logs,uploads}
mkdir -p /var/www/html/${SITE_NAME}_test/{public_html,theme,static_files,config,logs,uploads}
rm -f /var/www/html/index.html

chmod -R 755 /var/www/html/${SITE_NAME}/theme
chmod -R 777 /var/www/html/${SITE_NAME}/uploads
chown -R www-data /var/www/html/${SITE_NAME}/uploads

# Apache Virtual Host Setup
cat > /etc/apache2/sites-available/${SITE_NAME}.conf <<EOL
<VirtualHost *:80>
    ServerName ${SITE_NAME}.org
    ServerAlias www.${SITE_NAME}.org
    DocumentRoot /var/www/html/${SITE_NAME}/public_html

    <Directory /var/www/html/${SITE_NAME}/public_html>
        Options -Indexes -FollowSymLinks -MultiViews
        AllowOverride All
        Require all granted

        # Turn mod_rewrite on
        RewriteEngine On
        RewriteBase /

        ## Hide .php extension
        RewriteCond %{THE_REQUEST} ^[A-Z]{3,}\s([^.]+)\.php [NC]
        RewriteRule ^ %1 [R=302,L]

        # Internally forward /dir/foo to /dir/foo.php
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteCond %{REQUEST_FILENAME}.php -f
        RewriteRule ^(.*?)/?\$ \$1.php [L]

        # Redirect www to non-www
        RewriteCond %{HTTP_HOST} ^www\.(.*)\$ [NC]
        RewriteRule ^(.*)\$ http://%1/\$1 [R=301,L]

        # Route everything through serve.php
        RewriteRule ^(.*)\$ serve.php?path=\$1 [QSA]
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/${SITE_NAME}_error.log
    CustomLog \${APACHE_LOG_DIR}/${SITE_NAME}_access.log combined
</VirtualHost>
EOL

# Enable site and disable default
a2ensite ${SITE_NAME}.conf
a2dissite 000-default.conf

systemctl reload apache2
systemctl restart apache2

# Install Certbot
snap install core
snap refresh core
snap install --classic certbot
ln -sf /snap/bin/certbot /usr/bin/certbot

# Attempt to generate SSL certificate with email
certbot --apache -d ${SITE_NAME}.org -d www.${SITE_NAME}.org --non-interactive --agree-tos --email "$EMAIL" || echo "Certbot failed. Please verify DNS."

# Force HTTPS after successful certbot
SSL_CONF="/etc/apache2/sites-available/${SITE_NAME}-le-ssl.conf"
if [ -f "$SSL_CONF" ]; then
    echo "SSL config detected, forcing HTTPS redirect."
    sed -i '/<\/VirtualHost>/i \
# Redirect HTTP to HTTPS\n\
RewriteEngine On\n\
RewriteCond %{HTTPS} off\n\
RewriteRule (.*) https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]\n' /etc/apache2/sites-available/${SITE_NAME}.conf
    systemctl reload apache2
fi

# Composer Installations
cd /home/$USER_NAME
sudo -u $USER_NAME composer require "mailgun/mailgun-php:2.8.1" kriswallsmith/buzz nyholm/psr7
sudo -u $USER_NAME composer require jhut89/mailchimp3php
sudo -u $USER_NAME composer require "stripe/stripe-php:10.16.0"
sudo -u $USER_NAME composer require verot/class.upload.php

echo "✅ Setup complete!"
