# FOSSology Dockerfile
# Copyright Siemens AG 2016, fabio.huser@siemens.com
#
# Copying and distribution of this file, with or without modification,
# are permitted in any medium without royalty provided the copyright
# notice and this notice are preserved.  This file is offered as-is,
# without any warranty.
#
# Description: Docker container image recipe

FROM debian:stable
MAINTAINER Fossology <fossology@fossology.org>
WORKDIR /fossology

ENV _update="apt-get update"
ENV _install="apt-get install -y --no-install-recommends"
ENV _cleanup="eval apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*"

RUN set -x \
 && $_update && $_install \
       lsb-release curl php5 libpq-dev libdbd-sqlite3-perl libspreadsheet-writeexcel-perl postgresql-client \
 && $_cleanup
RUN curl --insecure -sS https://getcomposer.org/installer | php \
 && mv composer.phar /usr/local/bin/composer

ADD utils/fo-installdeps utils/fo-installdeps
ADD install/scripts/php-conf-fix.sh install/scripts/php-conf-fix.sh
RUN set -x \
 && $_update \
 && /fossology/install/scripts/php-conf-fix.sh --overwrite \
 && /fossology/utils/fo-installdeps -e -y \
 && $_cleanup

ADD . .
RUN set -x \
 && cp /fossology/install/src-install-apache-example.conf \
        /etc/apache2/conf-available/fossology.conf \
 && ln -s /etc/apache2/conf-available/fossology.conf \
        /etc/apache2/conf-enabled/fossology.conf \
 && make install \
 && make clean \
 && /usr/local/lib/fossology/fo-postinstall --common

VOLUME /srv/fossology/repository/
RUN chmod 777 /srv/fossology/repository/ # TODO

EXPOSE 8080
RUN chmod +x /fossology/docker-entrypoint.sh
ENTRYPOINT ["/fossology/docker-entrypoint.sh"]
CMD ["bash"]
