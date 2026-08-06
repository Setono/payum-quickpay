# Minimal Expose *client* image for the e2e harness.
#
# NOTE: the Expose project's own Dockerfile builds the Expose *server*, not the share client — so we
# build our own tiny client image here. It just installs the `exposedev/expose` CLI and runs
# `expose share ...` via the entrypoint.
#
# The tunnel exists for one reason: Quickpay POSTs its signed callback to a public HTTPS url, which
# cannot be localhost. It fronts `composer e2e:listen`.
#
#   docker build -t expose-client examples/e2e/expose
#   docker run --rm -e EXPOSE_TOKEN=<your-token> expose-client share http://host.docker.internal:8000 --server=eu-1
FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip zip libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_HOME=/composer
ENV PATH="/composer/vendor/bin:${PATH}"

RUN composer global require exposedev/expose

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
