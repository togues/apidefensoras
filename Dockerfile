# Observatorio API — PHP 8.2 plano, sin framework (ver CLAUDE.md, "Stack").
# Solo para desarrollo local vía docker-compose.dev.yml. El despliegue real
# corre sobre el mismo Apache/PHP de hosting que usa TerroSenser en
# producción; esta imagen existe para no depender de tener PHP instalado
# en la máquina de cada quien.
FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libicu-dev \
    && docker-php-ext-install pdo_pgsql pgsql intl exif \
    && a2enmod rewrite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# El .htaccess de cada carpeta (reenvío de Authorization, igual que
# TerroSenser) no hace nada si Apache no lo lee: la imagen base trae
# AllowOverride None por defecto.
RUN printf '<Directory /var/www/html>\n    AllowOverride All\n</Directory>\n' \
    > /etc/apache2/conf-available/z-allowoverride.conf \
    && a2enconf z-allowoverride
