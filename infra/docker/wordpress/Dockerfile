# ReserveChain.io — WordPress image
#
# Adds to the official image the things a production-grade build needs and the
# stock image does not ship: WP-CLI (migrations, seeding, scheduled jobs),
# Composer (PSR-4 autoloading for the plugin), and the PHP extensions the
# platform uses for document hashing and image handling.

ARG WP_VERSION=6.7
FROM wordpress:${WP_VERSION}-php8.3-apache

# --- System packages ---------------------------------------------------------
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        less \
        git \
        unzip \
        default-mysql-client \
        libzip-dev \
        libicu-dev \
        msmtp \
        ca-certificates; \
    rm -rf /var/lib/apt/lists/*

# --- PHP extensions ----------------------------------------------------------
RUN set -eux; \
    docker-php-ext-configure intl; \
    docker-php-ext-install -j"$(nproc)" zip intl opcache bcmath

# --- Composer ----------------------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# --- WP-CLI ------------------------------------------------------------------
RUN set -eux; \
    curl -fsSL -o /usr/local/bin/wp \
        https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar; \
    chmod +x /usr/local/bin/wp; \
    wp --info --allow-root

# --- Mail: route PHP mail() to the Mailpit container in development ----------
RUN set -eux; \
    printf '%s\n' \
        'defaults' \
        'auth off' \
        'tls off' \
        'syslog LOG_MAIL' \
        'account default' \
        'host mail' \
        'port 1025' \
        'from no-reply@reservechain.io' \
        > /etc/msmtprc; \
    chmod 0644 /etc/msmtprc

# --- PHP configuration -------------------------------------------------------
COPY php.ini /usr/local/etc/php/conf.d/zz-reservechain.ini

# --- Apache hardening --------------------------------------------------------
# Remove the version banner and enable headers/rewrite for the security headers
# and clean URLs the brief requires (HSTS, CSP, canonical URLs).
RUN set -eux; \
    a2enmod headers rewrite expires; \
    printf '%s\n' 'ServerTokens Prod' 'ServerSignature Off' 'TraceEnable Off' \
        > /etc/apache2/conf-available/rc-hardening.conf; \
    a2enconf rc-hardening

WORKDIR /var/www/html
