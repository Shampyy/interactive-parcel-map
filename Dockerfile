# Oficiální  PHP v CLI verzi
FROM php:8.2-cli

# Instalace systémových závislostí a rozšíření pro SQLite a ZIP
RUN apt-get update && apt-get install -y \
    unzip \
    libzip-dev \
    sqlite3 \
    libsqlite3-dev \
    && docker-php-ext-install zip pdo pdo_sqlite

# Instalace Composeru
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Nastavení pracovní složky
WORKDIR /var/www

# Zkopírování všech souborů projektu do kontejneru
COPY . .

# Stažení PHP závislostí
RUN composer install --no-interaction --optimize-autoloader

# Zpřístupnění portu pro webový server
EXPOSE 8000

# Startovací příkaz: Nejdřív provede import dat, pak spustí lokální PHP server
CMD php scripts/import.php && php -S 0.0.0.0:8000 -t public