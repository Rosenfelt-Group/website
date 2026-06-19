FROM wordpress:latest

RUN curl -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp

# Static-asset cache policy (Core Web Vitals). mod_expires is enabled here so a
# rebuild can't drift, and the conf lives in the image (not the volume), so it
# survives a wordpress-files volume wipe — unlike a .htaccess edit.
RUN a2enmod expires
COPY apache/rosably-cache.conf /etc/apache2/conf-enabled/rosably-cache.conf

COPY mu-plugins/ /usr/src/wordpress/wp-content/mu-plugins/

# Downloadable eBook PDF, served at /wp-content/uploads/beyond-the-chatbot.pdf.
# Baked into the image for the fresh-volume case; also docker-cp'd into the live
# volume on deploy (the entrypoint never re-copies an existing volume).
COPY assets/beyond-the-chatbot.pdf /usr/src/wordpress/wp-content/uploads/beyond-the-chatbot.pdf
