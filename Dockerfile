FROM php:8.2-apache

# 1. Install MySQL extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql

# 2. Enable Apache mod_rewrite (crucial for your .htaccess and router)
RUN a2enmod rewrite

# 3. Change Apache Document Root to your subfolder (e.g., /server)
ENV APACHE_DOCUMENT_ROOT /var/www/html/atlas-bank-backend
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# 4. Copy all files into the container
COPY . /var/www/html/

# 5. Set permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
