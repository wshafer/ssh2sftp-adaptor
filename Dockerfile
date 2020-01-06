FROM php:7.4-cli

RUN apt-get update \
    && apt-get -y install libssh2-1-dev wget unzip zip git;

RUN pecl install xdebug ssh2-beta \
    && docker-php-ext-enable ssh2 xdebug

# Install Composer and setup testing suites
RUN curl -s https://getcomposer.org/installer | php \
    && mv composer.phar /usr/local/bin/composer \
    && COMPOSER_ALLOW_SUPERUSER=1 composer self-update \
    && COMPOSER_ALLOW_SUPERUSER=1 composer global require hirak/prestissimo \
    && COMPOSER_ALLOW_SUPERUSER=1 composer global require phing/phing pdepend/pdepend \
       phploc/phploc phpmd/phpmd phpunit/phpunit sebastian/phpcpd squizlabs/php_codesniffer \
    && ln -s ~/.composer/vendor/bin/* /usr/local/bin

WORKDIR /var/www

CMD ["php", "-m"]
