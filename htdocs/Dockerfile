FROM php:8.2-apache

RUN docker-php-ext-install mysqli

COPY apache-config.sh /usr/local/bin/apache-config.sh
RUN chmod +x /usr/local/bin/apache-config.sh

COPY . /var/www/html/

RUN { \
    echo '<Directory /var/www/html>'; \
    echo '    AllowOverride All'; \
    echo '    Require all granted'; \
    echo '</Directory>'; \
} >> /etc/apache2/apache2.conf

RUN a2enmod rewrite

CMD ["/usr/local/bin/apache-config.sh"]