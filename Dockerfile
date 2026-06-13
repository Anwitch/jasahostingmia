FROM php:8.2-apache

# Install PHP extensions & enable Apache modules
RUN docker-php-ext-install mysqli pdo pdo_mysql \
    && a2enmod rewrite headers

# Allow .htaccess overrides and enable rewrite for all directories
RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html/

# Set proper permissions for web server
RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;

EXPOSE 80
