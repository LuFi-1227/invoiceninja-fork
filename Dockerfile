FROM invoiceninja/invoiceninja-debian:latest

USER root

# Instala composer temporariamente para mapear as novas classes do fork (Asaas, WhatsApp, etc)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copia os arquivos do repositório local
COPY --chown=www-data:www-data . /var/www/html/

# Mapeia autoload de classes do Laravel/Composer
RUN chown -R www-data:www-data /var/www/html \
    && cd /var/www/html \
    && composer dump-autoload --optimize --no-dev \
    && rm -f /usr/bin/composer

# Atualiza /tmp/public e garante o symlink para a interface React
RUN rm -rf /tmp/public/* \
    && cp -r /var/www/html/public/* /tmp/public/ \
    && ln -sf /var/www/html/resources/views/react/index.blade.php /tmp/public/index.html \
    && ln -sf /var/www/html/resources/views/react/index.blade.php /var/www/html/public/index.html

# Copia configurações e script de inicialização
COPY docker/php.ini /usr/local/etc/php/conf.d/invoiceninja.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/invoiceninja.conf
COPY docker/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/programs.conf /etc/supervisor/conf.d/programs.conf
COPY --chmod=0755 docker/init.sh /usr/local/bin/init.sh

ENTRYPOINT ["/usr/local/bin/init.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf"]
