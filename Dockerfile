FROM uselagoon/php-8.0-cli:latest

# Needed for `dig` etc.
RUN apk update && apk --no-cache add bind-tools

# Intl PHP extension.
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/bin/
RUN install-php-extensions intl

# Fixes "PHP Warning:  Module "yaml" is already loaded in Unknown on line 0"
RUN rm -f /usr/local/etc/php/conf.d/yaml.ini

COPY composer.json composer.lock /app/
RUN COMPOSER_ALLOW_XDEBUG=1 composer install --prefer-dist --no-dev --no-progress --no-suggest --optimize-autoloader --apcu-autoloader
COPY . /app
ADD ./tmp/ /tmp/

ENV TEMP_FOLDER=./tmp/

ADD entrypoint.sh /
RUN chmod +x /entrypoint.sh

#Temp: keep container running for debugging
#ENTRYPOINT ["tail", "-f", "/dev/null"]

CMD ["/entrypoint.sh"]
