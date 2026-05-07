# 1. Use the official PHP image with Apache
FROM php:8.2-apache

# 2. Install PostgreSQL & MySQL extensions
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql mysqli

# 3. Enable Apache mod_rewrite
RUN a2enmod rewrite

# 4. Set Document Root to the project root (where index.html is)
ENV APACHE_DOCUMENT_ROOT /var/www/html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# 5. Fix permissions and allow overrides (Prevents the "Forbidden" error)
RUN echo "<Directory /var/www/html/> \n\
    Options Indexes FollowSymLinks \n\
    AllowOverride All \n\
    Require all granted \n\
</Directory>" >> /etc/apache2/apache2.conf

# 6. Copy all project files
COPY . /var/www/html/

# 7. Ensure correct ownership
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

# 8. Tell Render to look at port 80
EXPOSE 80
