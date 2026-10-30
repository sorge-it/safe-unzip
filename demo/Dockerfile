# VHS records the terminal; PHP, ext-zip and Info-ZIP run the demo app inside it.
FROM ghcr.io/charmbracelet/vhs:v0.12.1

RUN apt-get update \
 && apt-get install -y --no-install-recommends php-cli php-mbstring php-zip unzip git \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

ENTRYPOINT []
