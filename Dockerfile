FROM wordpress:latest

RUN curl -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp

# Static-asset cache policy (Core Web Vitals). mod_expires is enabled here so a
# rebuild can't drift, and the conf lives in the image (not the volume), so it
# survives a wordpress-files volume wipe — unlike a .htaccess edit.
RUN a2enmod expires
COPY apache/rosably-cache.conf /etc/apache2/conf-enabled/rosably-cache.conf

COPY mu-plugins/ /usr/src/wordpress/wp-content/mu-plugins/

# Gated eBook PDF (lead magnet). Lives in a directory blocked from direct HTTP
# access by the bundled .htaccess; served only via /wp-json/rosably/v1/ebook-download
# after the lead form. Baked into the image for the fresh-volume case; also
# docker-cp'd into the live volume on deploy (the entrypoint never re-copies an
# existing volume).
COPY assets/beyond-the-chatbot.pdf /usr/src/wordpress/wp-content/uploads/ebook-assets/beyond-the-chatbot.pdf
COPY assets/ebook-assets.htaccess /usr/src/wordpress/wp-content/uploads/ebook-assets/.htaccess
