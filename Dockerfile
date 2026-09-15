# Oficiální PHP v CLI verzi
FROM php:8.2-cli

# Instalace systémových závislostí (přidán curl) a rozšíření pro SQLite a ZIP
RUN apt-get update && apt-get install -y \
    curl \
    unzip \
    libzip-dev \
    sqlite3 \
    libsqlite3-dev \
    && docker-php-ext-install zip pdo pdo_sqlite \
    && rm -rf /var/lib/apt/lists/* # Pročištění cache apt (dobrá praxe pro zmenšení image)

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

# Nastavení spustitelnosti nového bash skriptu pro jistotu (zabrání chybám s oprávněním na Windows)
RUN chmod +x scripts/start.sh

# Startovací příkaz: Volá náš nový komplexní bash skript (stažení -> import -> server)
CMD ["sh", "./scripts/start.sh"]