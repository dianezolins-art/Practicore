FROM php:8.2-apache

# I-install ang MySQL extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Kopyahin ang lahat ng files papunta sa Apache web directory
COPY . /var/www/html/

# I-set ang tamang permissions
RUN chown -R www-data:www-data /var/www/html

# Piliting patakbuhin ang Apache server sa foreground
CMD ["apache2-foreground"]