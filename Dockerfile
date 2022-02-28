FROM php:8.0

RUN docker-php-ext-install sockets
RUN docker-php-ext-install pcntl

RUN mkdir /code
COPY slowpoke.php /code
WORKDIR /code
