FROM ubuntu:24.04

LABEL org.opencontainers.image.authors="Montala Ltd"

ENV DEBIAN_FRONTEND="noninteractive"

# Video masters: upstream's 100M cap rejects them outright.
ARG PHP_UPLOAD_LIMIT=4096M

RUN apt-get update && apt-get install -y \
    nano \
    imagemagick \
    apache2 \
    subversion \
    ghostscript \
    antiword \
    poppler-utils \
    libimage-exiftool-perl \
    cron \
    postfix \
    wget \
    php \
    php-apcu \
    php-curl \
    php-dev \
    php-gd \
    php-intl \
    php-mysqlnd \
    php-mbstring \
    php-zip \
    libapache2-mod-php \
    ffmpeg \
    libopencv-dev \
    python3-opencv \
    python3 \
    python3-pip \
 && apt-get clean \
 && rm -rf /var/lib/apt/lists/*

RUN sed -i -e "s/upload_max_filesize\s*=\s*2M/upload_max_filesize = ${PHP_UPLOAD_LIMIT}/g" /etc/php/8.3/apache2/php.ini \
 && sed -i -e "s/post_max_size\s*=\s*8M/post_max_size = ${PHP_UPLOAD_LIMIT}/g" /etc/php/8.3/apache2/php.ini \
 && sed -i -e "s/max_execution_time\s*=\s*30/max_execution_time = 600/g" /etc/php/8.3/apache2/php.ini \
 && sed -i -e "s/memory_limit\s*=\s*128M/memory_limit = 1G/g" /etc/php/8.3/apache2/php.ini

RUN printf '<Directory /var/www/>\n\
\tOptions FollowSymLinks\n\
</Directory>\n'\
>> /etc/apache2/sites-enabled/000-default.conf

ADD cronjob /etc/cron.daily/resourcespace

# ResourceSpace has no release tags: releases/11.0 is a moving SVN branch. Pinning the
# revision keeps a rebuild identical to the build that was tested. Bump it deliberately.
ARG RS_SVN_REVISION=29772

WORKDIR /var/www/html

RUN rm -f index.html \
 && svn co -q -r ${RS_SVN_REVISION} https://svn.resourcespace.com/svn/rs/releases/11.0 . \
 && mkdir -p filestore \
 && chmod 777 filestore \
 && chmod -R 777 include/

# The installer writes include/config.php. Point it at a volume so the configuration
# outlives the container; otherwise every redeploy hands you the installer again.
RUN mkdir -p /config \
 && chmod 777 /config \
 && ln -sf /config/config.php /var/www/html/include/config.php
 

# Copy custom entrypoint script
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Start both cron and Apache
CMD ["/entrypoint.sh"]
