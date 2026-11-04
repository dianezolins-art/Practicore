FROM php:8.2-apache

# I-install ang MySQL extensions para gumana ang db.php mo
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Kopyahin ang lahat ng files papunta sa Apache web directory
COPY . /var/www/html/

# I-set ang tamang permissions
RUN chown -R www-data:www-data /var/www/html