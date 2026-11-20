# From official php image.
FROM php:8.5-fpm-alpine
RUN addgroup -g 1000 -S user && adduser -u 1000 -D user -G user
# Install postgres pdo driver.
# RUN apk add --no-cache postgresql-dev && docker-php-ext-install pdo_pgsql
# Install redis driver.
# RUN mkdir -p /usr/src/php/ext/redis; \
#     curl -fsSL --ipv4 https://github.com/phpredis/phpredis/archive/6.0.2.tar.gz | tar xvz -C "/usr/src/php/ext/redis" --strip 1; \
#     docker-php-ext-install redis

RUN apk add --no-cache \
  curl \
  git \
  nodejs \
  npm \
  imagemagick-dev \
  chromium \
  libzip-dev \
  freetype-dev \
  libpng-dev \
  libjpeg-turbo-dev \
  icu-dev


ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium
ENV PUPPETEER_DOCKER=1

RUN mkdir -p /usr/src/php/ext/imagick
RUN chmod 777 /usr/src/php/ext/imagick
RUN curl -fsSL https://github.com/Imagick/imagick/archive/refs/tags/3.8.0.tar.gz | tar xvz -C "/usr/src/php/ext/imagick" --strip 1

RUN docker-php-ext-configure gd --with-freetype --with-jpeg

# Install PHP extensions
RUN docker-php-ext-install imagick zip gd intl

RUN rm -f /usr/bin/php85
RUN ln -s /usr/local/bin/php /usr/bin/php85
