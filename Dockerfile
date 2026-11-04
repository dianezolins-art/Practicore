FROM php:8.2-apache

RUN docker-php-ext-install mysqli pdo pdo_mysql
RUN printf '%s\n' 'display_errors=Off' 'log_errors=On' > /usr/local/etc/php/conf.d/production.ini

COPY . /var/www/html/
COPY docker-entrypoint.sh /usr/local/bin/practicore-entrypoint

RUN chown -R www-data:www-data /var/www/html \
    && chmod +x /usr/local/bin/practicore-entrypoint

EXPOSE 8080

CMD ["practicore-entrypoint"]
