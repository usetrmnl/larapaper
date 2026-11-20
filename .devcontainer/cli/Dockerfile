# From official php image.
FROM php:8.5-cli-alpine
# Create a user group and account under id 1000.
RUN addgroup -g 1000 -S user && adduser -u 1000 -D user -G user
# Install quality-of-life packages.
RUN apk add --no-cache bash curl git vim
# Install composer for php deps (from the official image, so it runs on the container's php).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
# Add Chromium and Image Magick for puppeteer.
RUN apk add --no-cache \
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
# The tests need more than the 128M default.
RUN echo "memory_limit=1G" > "$PHP_INI_DIR/conf.d/memory-limit.ini"

# Install postgres pdo driver.
# RUN apk add --no-cache postgresql-dev && docker-php-ext-install pdo_pgsql
# Install redis driver.
# RUN mkdir -p /usr/src/php/ext/redis; \
#     curl -fsSL --ipv4 https://github.com/phpredis/phpredis/archive/6.0.2.tar.gz | tar xvz -C "/usr/src/php/ext/redis" --strip 1; \
#     docker-php-ext-install redis
# Install nodejs and npm for frontend.
RUN apk add --no-cache nodejs npm
# Prevent container from exiting early.
CMD ["sleep", "infinity"]
