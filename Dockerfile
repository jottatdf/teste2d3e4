FROM composer:2.0 as composer

ARG TESTING=false
ENV TESTING=$TESTING

WORKDIR /usr/local/src/

COPY composer.lock /usr/local/src/
COPY composer.json /usr/local/src/

RUN composer install --ignore-platform-reqs --optimize-autoloader \
    --no-plugins --no-scripts --prefer-dist \
    `if [ "$TESTING" != "true" ]; then echo "--no-dev"; fi`

FROM node:16.14.2-alpine3.15 as node

COPY app/console /usr/local/src/console

WORKDIR /usr/local/src/console

ARG VITE_GA_PROJECT
ARG VITE_CONSOLE_MODE
ARG VITE_APPWRITE_GROWTH_ENDPOINT=https://growth.appwrite.io/v1

ENV VITE_GA_PROJECT=$VITE_GA_PROJECT
ENV VITE_CONSOLE_MODE=$VITE_CONSOLE_MODE
ENV VITE_APPWRITE_GROWTH_ENDPOINT=$VITE_APPWRITE_GROWTH_ENDPOINT

RUN npm ci
RUN npm run build

FROM appwrite/base:0.1.0 as final

LABEL maintainer="team@appwrite.io"

ARG VERSION=dev
ARG DEBUG=false
ENV DEBUG=$DEBUG

ENV DOCKER_CONFIG=${DOCKER_CONFIG:-$HOME/.docker}
ENV DOCKER_COMPOSE_VERSION=v2.5.0

ENV _APP_SERVER=swoole \
    _APP_ENV=production \
    _APP_LOCALE=en \
    _APP_DOMAIN=localhost \
    _APP_DOMAIN_TARGET=localhost \
    _APP_HOME=https://appwrite.io \
    _APP_EDITION=community \
    _APP_CONSOLE_WHITELIST_ROOT=enabled \
    _APP_OPTIONS_ABUSE=enabled \
    _APP_OPTIONS_FORCE_HTTPS=disabled \
    _APP_OPENSSL_KEY_V1=your-secret-key \
    _APP_STORAGE_LIMIT=10000000 \
    _APP_STORAGE_ANTIVIRUS=enabled \
    _APP_STORAGE_ANTIVIRUS_HOST=clamav \
    _APP_STORAGE_ANTIVIRUS_PORT=3310 \
    _APP_REDIS_HOST=redis \
    _APP_REDIS_PORT=6379 \
    _APP_DB_HOST=mariadb \
    _APP_DB_PORT=3306 \
    _APP_DB_USER=root \
    _APP_DB_PASS=password \
    _APP_DB_SCHEMA=appwrite \
    _APP_FUNCTIONS_SIZE_LIMIT=30000000 \
    _APP_FUNCTIONS_TIMEOUT=900 \
    _APP_FUNCTIONS_CPUS=1 \
    _APP_FUNCTIONS_MEMORY=128 \
    _APP_EXECUTOR_SECRET=a-random-secret \
    _APP_EXECUTOR_HOST=http://exc1/v1 \
    _APP_SETUP=self-hosted \
    _APP_VERSION=$VERSION \
    _APP_USAGE_STATS=enabled \
    # 14 Days = 1209600 s
    _APP_MAINTENANCE_RETENTION_EXECUTION=1209600 \
    _APP_MAINTENANCE_RETENTION_AUDIT=1209600 \
    # 1 Day = 86400 s
    _APP_MAINTENANCE_RETENTION_ABUSE=86400 \
    _APP_MAINTENANCE_RETENTION_USAGE_HOURLY=8640000 \
    _APP_MAINTENANCE_INTERVAL=86400

RUN \
  if [ "$DEBUG" == "true" ]; then \
    apk add boost boost-dev; \
  fi

WORKDIR /usr/src/code

COPY --from=composer /usr/local/src/vendor /usr/src/code/vendor
COPY --from=node /usr/local/src/console/build /usr/src/code/console

# Add Source Code
COPY ./app /usr/src/code/app
COPY ./bin /usr/local/bin
COPY ./docs /usr/src/code/docs
COPY ./src /usr/src/code/src

# Set Volumes
RUN mkdir -p /storage/uploads && \
    mkdir -p /storage/cache && \
    mkdir -p /storage/config && \
    mkdir -p /storage/certificates && \
    mkdir -p /storage/functions && \
    mkdir -p /storage/debug && \
    chown -Rf www-data.www-data /storage/uploads && chmod -Rf 0755 /storage/uploads && \
    chown -Rf www-data.www-data /storage/cache && chmod -Rf 0755 /storage/cache && \
    chown -Rf www-data.www-data /storage/config && chmod -Rf 0755 /storage/config && \
    chown -Rf www-data.www-data /storage/certificates && chmod -Rf 0755 /storage/certificates && \
    chown -Rf www-data.www-data /storage/functions && chmod -Rf 0755 /storage/functions && \
    chown -Rf www-data.www-data /storage/debug && chmod -Rf 0755 /storage/debug

# Executables
RUN chmod +x /usr/local/bin/doctor && \
    chmod +x /usr/local/bin/patch-create-missing-schedules && \
    chmod +x /usr/local/bin/maintenance && \
    chmod +x /usr/local/bin/volume-sync && \
    chmod +x /usr/local/bin/install && \
    chmod +x /usr/local/bin/migrate && \
    chmod +x /usr/local/bin/realtime && \
    chmod +x /usr/local/bin/schedule && \
    chmod +x /usr/local/bin/sdks && \
    chmod +x /usr/local/bin/specs && \
    chmod +x /usr/local/bin/ssl && \
    chmod +x /usr/local/bin/test && \
    chmod +x /usr/local/bin/vars && \
    chmod +x /usr/local/bin/worker-audits && \
    chmod +x /usr/local/bin/worker-certificates && \
    chmod +x /usr/local/bin/worker-databases && \
    chmod +x /usr/local/bin/worker-deletes && \
    chmod +x /usr/local/bin/worker-functions && \
    chmod +x /usr/local/bin/worker-builds && \
    chmod +x /usr/local/bin/worker-mails && \
    chmod +x /usr/local/bin/worker-messaging && \
    chmod +x /usr/local/bin/worker-webhooks && \
    chmod +x /usr/local/bin/worker-usage && \
    chmod +x /usr/local/bin/backup && \
    chmod +x /usr/local/bin/restore

# Letsencrypt Permissions
RUN mkdir -p /etc/letsencrypt/live/ && chmod -Rf 755 /etc/letsencrypt/live/

# Enable Extensions
RUN if [ "$DEBUG" == "true" ]; then printf "zend_extension=yasd \nyasd.debug_mode=remote \nyasd.init_file=/usr/local/dev/yasd_init.php \nyasd.remote_port=9005 \nyasd.log_level=-1" >> /usr/local/etc/php/conf.d/yasd.ini; fi

RUN if [ "$DEBUG" == "true" ]; then echo "opcache.enable=0" >> /usr/local/etc/php/conf.d/appwrite.ini; fi
RUN echo "opcache.preload_user=www-data" >> /usr/local/etc/php/conf.d/appwrite.ini
RUN echo "opcache.preload=/usr/src/code/app/preload.php" >> /usr/local/etc/php/conf.d/appwrite.ini
RUN echo "opcache.enable_cli=1" >> /usr/local/etc/php/conf.d/appwrite.ini
RUN echo "default_socket_timeout=-1" >> /usr/local/etc/php/conf.d/appwrite.ini
RUN echo "opcache.jit_buffer_size=100M" >> /usr/local/etc/php/conf.d/appwrite.ini
RUN echo "opcache.jit=1235" >> /usr/local/etc/php/conf.d/appwrite.ini

EXPOSE 80

CMD [ "php", "app/http.php", "-dopcache.preload=opcache.preload=/usr/src/code/app/preload.php" ]