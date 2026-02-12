FROM php:7.4-cli-alpine

RUN apk add git openssh-client bash

WORKDIR /var/www/html

COPY index.php .
COPY nginx.log .

ENTRYPOINT ["php", "/var/www/html/index.php"]