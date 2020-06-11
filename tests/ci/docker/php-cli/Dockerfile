ARG PHP_BUILD_VERSION
ARG INFORMIX_BUILD_VERSION
FROM php:${PHP_BUILD_VERSION}-cli as php-cli-base

RUN apt-get update \
    && apt-get install -y --no-install-recommends git

# Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Copy the required Informix libraries for the PDO extension into the host
FROM ibmcom/${INFORMIX_BUILD_VERSION} as ifx-src
FROM php-cli-base
COPY --from=ifx-src /opt/ibm/informix /opt/ibm/informix

ARG PDO_INFORMIX_FILE
ARG PDO_INFORMIX_URL
ARG PDO_INFORMIX_PATCH_URL
# Compile and enable the Informix PDO extension including the multibyte patch (https://bugs.php.net/bug.php?id=64008)
RUN curl -fsSL ${PDO_INFORMIX_URL} -o ${PDO_INFORMIX_FILE}.tgz \
    && tar -xf ${PDO_INFORMIX_FILE}.tgz -C /tmp/ \
    && rm ${PDO_INFORMIX_FILE}.tgz \
    && cd /tmp/${PDO_INFORMIX_FILE} \
    && curl $PDO_INFORMIX_PATCH_URL | git apply -v \
    && docker-php-ext-configure /tmp/${PDO_INFORMIX_FILE} --with-pdo-informix=/opt/ibm/informix \
    && docker-php-ext-install /tmp/${PDO_INFORMIX_FILE} \
    && rm -r /tmp/${PDO_INFORMIX_FILE}

COPY custom-php.ini "$PHP_INI_DIR/conf.d/custom-php.ini"

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
