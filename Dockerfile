FROM php:8.2-apache

# Install packages you need
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    && docker-php-ext-install mysqli pdo pdo_mysql

# Copy your PHP files
COPY . /var/www/html/

EXPOSE 80
