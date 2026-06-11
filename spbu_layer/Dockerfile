# 1. Gunakan base image resmi PHP yang sudah include Apache web server
FROM php:8.2-apache

# 2. Instal ekstensi PHP mysqli
RUN docker-php-ext-install mysqli

# 3. Aktifkan modul rewrite Apache
RUN a2enmod rewrite

# 4. Salin seluruh isi folder projek ke dalam folder web Apache di server
COPY . /var/www/html/

# 5. Atur hak akses agar Apache bisa membaca dan menulis file
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# 6. Buka port 80
EXPOSE 80
