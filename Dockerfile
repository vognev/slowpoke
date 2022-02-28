FROM php:8.0

RUN docker-php-ext-install sockets
RUN docker-php-ext-install pcntl

RUN apt-get update\
 && apt-get install -y watch net-tools vim jq\
 && rm -rf /var/lib/apt/lists/*

RUN mkdir /code
COPY slowpoke.php /code
WORKDIR /code
