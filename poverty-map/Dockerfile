# 1. Gunakan base image resmi PHP yang sudah include Apache web server
FROM php:8.2-apache

# 2. Instal library sistem yang dibutuhkan untuk ekstensi zip
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    && rm -rf /var/lib/apt/lists/*

# 3. Instal ekstensi PHP mysqli (untuk DB) dan zip (untuk SimpleXLSX)
RUN docker-php-ext-install mysqli zip

# 4. Aktifkan modul rewrite Apache
RUN a2enmod rewrite

# 5. Salin seluruh isi folder projek ke dalam folder web Apache di server
COPY . /var/www/html/

# 6. Atur hak akses agar Apache bisa membaca dan menulis file (untuk upload foto)
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# 7. Buka port 80
EXPOSE 80
