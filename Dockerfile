# 1. Use the official PHP image with Apache
FROM php:8.2-apache

# 2. Install PostgreSQL extensions (needed for Render's free DB)
# Also keeps mysqli just in case you use it elsewhere
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql mysqli

# 3. Enable Apache mod_rewrite for your .htaccess and router
RUN a2enmod rewrite

# 4. Point Apache to your specific subfolder (the one containing index.php)
# IMPORTANT: Replace 'server' with your actual folder name (e.g. 'api' or 'backend')
ENV APACHE_DOCUMENT_ROOT /var/www/html/

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# 5. Copy all project files into the container
COPY . /var/www/html/

# 6. Set correct permissions for Apache
RUN chown -R www-data:www-data /var/www/html

# 7. Tell Render to look at port 80
EXPOSE 80
