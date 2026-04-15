## Apache Virtual Host Development Configuration

DocumentRoot "/home/my-microservice"
ServerName my-microservice
<Directory /home/my-microservice>
Options -Indexes +FollowSymLinks
AllowOverride All
Require all granted
</Directory>
<FilesMatch "\.php$">
SetHandler "proxy:unix:/run/php/php-fpm.sock|fcgi://localhost"
</FilesMatch>
Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "SAMEORIGIN"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
ErrorLog /home/errors/my-microservice.log
CustomLog /var/log/apache2/my-microservice-access.log "combined"
LogLevel error
