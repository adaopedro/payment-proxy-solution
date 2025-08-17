FROM php:8.5.0alpha4-fpm-alpine3.22

# Set Composer to run as superuser
ENV COMPOSER_ALLOW_SUPER_USER=1

# Copiar o Composer da imagem oficial para o container
COPY --from=composer:lts /usr/bin/composer /usr/bin/composer

# Atualiza e instala dependências para compilar as extensões do PHP
RUN apk update && apk add --no-cache \
    libtool \           
    libuv-dev \         
    libaio-dev \        
    libzip-dev \     
    sqlite-dev \       
    libxml2-dev \      
    pkgconfig \
    bash \
    # Limpeza de cache do APK
    && rm -rf /var/cache/apk/*

# Useful PHP extension installer image, copy binary into your container
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# RUN docker-php-ext-install pdo pdo_mysql
# exit on errors, exit on unset variables, print every command as it is executed
RUN set -eux; \
    install-php-extensions pdo pdo_mysql pcntl uv;

# Após a instalação das extensões, não há necessidade de manter ferramentas de compilação como libtool, pkgconfig, gcc, etc., no contêiner. Você pode removê-las após a instalação das extensões para reduzir o tamanho da imagem.
RUN apk del libtool libuv-dev libaio-dev libzip-dev sqlite-dev libxml2-dev pkgconfig bash

WORKDIR /payment-proxy-solution
COPY . .

# RUN composer install --ignore-platform-reqs