# Custom Dockerfile extending Laravel Sail PHP 8.5 runtime
# This adds PDF thumbnail generation support via ImageMagick, Ghostscript, and Poppler
#
# IMPORTANT: This extends the base Sail image to add PDF processing capabilities
# without modifying vendor files. All changes are additive and respect the locked
# thumbnail pipeline architecture.
#
# This file is based on vendor/laravel/sail/runtimes/8.5/Dockerfile with PDF support added.

FROM ubuntu:24.04

LABEL maintainer="Taylor Otwell"

ARG WWWGROUP
ARG NODE_VERSION=24
ARG MYSQL_CLIENT="mysql-client"
ARG POSTGRES_VERSION=18

WORKDIR /var/www/html

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=UTC
ENV SUPERVISOR_PHP_COMMAND="/usr/bin/php -d variables_order=EGPCS /var/www/html/artisan serve --host=0.0.0.0 --port=80"
ENV SUPERVISOR_PHP_USER="sail"
ENV PLAYWRIGHT_BROWSERS_PATH=0

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN echo "Acquire::http::Pipeline-Depth 0;" > /etc/apt/apt.conf.d/99custom && \
    echo "Acquire::http::No-Cache true;" >> /etc/apt/apt.conf.d/99custom && \
    echo "Acquire::BrokenProxy    true;" >> /etc/apt/apt.conf.d/99custom

RUN apt-get update && apt-get upgrade -y \
    && mkdir -p /etc/apt/keyrings \
    && apt-get install -y gnupg gosu curl ca-certificates zip unzip git supervisor sqlite3 libcap2-bin libpng-dev python3 dnsutils librsvg2-bin fswatch ffmpeg nano  \
    && curl -sS 'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0xb8dc7e53946656efbce4c1dd71daeaab4ad4cab6' | gpg --dearmor | tee /etc/apt/keyrings/ppa_ondrej_php.gpg > /dev/null \
    && echo "deb [signed-by=/etc/apt/keyrings/ppa_ondrej_php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble main" > /etc/apt/sources.list.d/ppa_ondrej_php.list \
    && apt-get update \
    && apt-get install -y \
        libgd3 \
        php8.5-cli \
        php8.5-dev \
        php8.5-pgsql \
        php8.5-sqlite3 \
        php8.5-gd \
        php8.5-curl \
        php8.5-mongodb \
        php8.5-imap \
        php8.5-mysql \
        php8.5-mbstring \
        php8.5-xml \
        php8.5-zip \
        php8.5-bcmath \
        php8.5-soap \
        php8.5-intl \
        php8.5-readline \
        php8.5-ldap \
        php8.5-msgpack \
        php8.5-igbinary \
        php8.5-redis \
        #php8.5-swoole \
        php8.5-memcached \
        php8.5-pcov \
        php8.5-imagick \
        php8.5-xdebug \
    && curl -sLS https://getcomposer.org/installer | php -- --install-dir=/usr/bin/ --filename=composer \
    && curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_$NODE_VERSION.x nodistro main" > /etc/apt/sources.list.d/nodesource.list \
    && apt-get update \
    && apt-get install -y nodejs \
    && npm install -g npm \
    && npm install -g pnpm \
    && npm install -g bun \
    && npx playwright install-deps \
    && curl -sS https://dl.yarnpkg.com/debian/pubkey.gpg | gpg --dearmor | tee /etc/apt/keyrings/yarn.gpg >/dev/null \
    && echo "deb [signed-by=/etc/apt/keyrings/yarn.gpg] https://dl.yarnpkg.com/debian/ stable main" > /etc/apt/sources.list.d/yarn.list \
    && curl -sS https://www.postgresql.org/media/keys/ACCC4CF8.asc | gpg --dearmor | tee /etc/apt/keyrings/pgdg.gpg >/dev/null \
    && echo "deb [signed-by=/etc/apt/keyrings/pgdg.gpg] http://apt.postgresql.org/pub/repos/apt noble-pgdg main" > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y yarn \
    && apt-get install -y $MYSQL_CLIENT \
    && apt-get install -y postgresql-client-$POSTGRES_VERSION \
    && apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# ============================================================================
# PDF THUMBNAIL SUPPORT - Additive changes only
# ============================================================================
# Install system dependencies for PDF processing
# 
# imagemagick: Core image manipulation library (php8.5-imagick extension already installed above)
#   - Required for converting PDF pages to images via Imagick PHP extension
# ghostscript: Required for ImageMagick to read/write PDF files
#   - ImageMagick uses Ghostscript as a delegate to process PDF content
#   - Without Ghostscript, ImageMagick cannot process PDFs even if policy allows it
# poppler-utils: Optional fallback PDF tools (pdftoppm, pdfinfo)
#   - Provides alternative methods for PDF processing if ImageMagick fails
#   - Useful for extracting text, metadata, or converting PDFs via command-line tools
RUN apt-get update \
    && apt-get install -y \
        imagemagick \
        ghostscript \
        poppler-utils \
    && apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Configure ImageMagick policy to allow PDF read/write
# 
# SECURITY NOTE: This policy allows ImageMagick to process PDF files.
# PDFs can contain embedded scripts, JavaScript, and should be processed with caution.
# 
# In production, consider:
# - Validating PDFs before processing (file type, size limits)
# - Running in isolated containers with resource limits
# - Limiting memory/CPU usage for PDF processing
# - Scanning PDFs for malicious content before processing
# - Using sandboxed environments for untrusted PDFs
#
# The policy.xml file controls what ImageMagick can read/write.
# By default, many distributions disable PDF support for security reasons.
# We enable it here because PDF thumbnail generation is a core feature requirement.
#
# Location: /etc/ImageMagick-6/policy.xml (or /etc/ImageMagick-7/policy.xml for newer versions)
RUN if [ -f /etc/ImageMagick-6/policy.xml ]; then \
        sed -i 's/<policy domain="coder" rights="none" pattern="PDF" \/>/<policy domain="coder" rights="read|write" pattern="PDF" \/>/g' /etc/ImageMagick-6/policy.xml || true; \
        # If the policy doesn't exist or sed didn't work, create/append it \
        if ! grep -q 'pattern="PDF"' /etc/ImageMagick-6/policy.xml 2>/dev/null; then \
            sed -i '/<\/policymap>/i\  <policy domain="coder" rights="read|write" pattern="PDF" />' /etc/ImageMagick-6/policy.xml || \
            echo '  <policy domain="coder" rights="read|write" pattern="PDF" />' >> /etc/ImageMagick-6/policy.xml; \
        fi; \
    elif [ -f /etc/ImageMagick-7/policy.xml ]; then \
        sed -i 's/<policy domain="coder" rights="none" pattern="PDF" \/>/<policy domain="coder" rights="read|write" pattern="PDF" \/>/g' /etc/ImageMagick-7/policy.xml || true; \
        if ! grep -q 'pattern="PDF"' /etc/ImageMagick-7/policy.xml 2>/dev/null; then \
            sed -i '/<\/policymap>/i\  <policy domain="coder" rights="read|write" pattern="PDF" />' /etc/ImageMagick-7/policy.xml || \
            echo '  <policy domain="coder" rights="read|write" pattern="PDF" />' >> /etc/ImageMagick-7/policy.xml; \
        fi; \
    else \
        # Create policy file if it doesn't exist \
        mkdir -p /etc/ImageMagick-6 && \
        echo '<?xml version="1.0" encoding="UTF-8"?>' > /etc/ImageMagick-6/policy.xml && \
        echo '<policymap>' >> /etc/ImageMagick-6/policy.xml && \
        echo '  <policy domain="coder" rights="read|write" pattern="PDF" />' >> /etc/ImageMagick-6/policy.xml && \
        echo '</policymap>' >> /etc/ImageMagick-6/policy.xml; \
    fi

# ============================================================================
# End of PDF thumbnail support additions
# ============================================================================

RUN setcap "cap_net_bind_service=+ep" /usr/bin/php8.5

RUN userdel -r ubuntu
RUN groupadd --force -g $WWWGROUP sail
RUN useradd -ms /bin/bash --no-user-group -g $WWWGROUP -u 1337 sail
RUN git config --global --add safe.directory /var/www/html

COPY start-container /usr/local/bin/start-container
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY php.ini /etc/php/8.5/cli/conf.d/99-sail.ini
RUN chmod +x /usr/local/bin/start-container

EXPOSE 80/tcp

ENTRYPOINT ["start-container"]
