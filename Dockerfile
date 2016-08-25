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

ENV _update="apt-get update"
ENV _install="apt-get install -y --no-install-recommends"
ENV _cleanup="eval apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*"

WORKDIR /fossology

ADD . .

RUN set -x \
 && $_update && $_install \
       lsb-release sudo postgresql php5-curl libpq-dev libdbd-sqlite3-perl libspreadsheet-writeexcel-perl \
       openssh-server \
       curl \
 && /fossology/utils/fo-installdeps -e -y \
 && $_cleanup

RUN set -x \
 && mkdir /var/run/sshd \
 && sed 's@session\s*required\s*pam_loginuid.so@session optional pam_loginuid.so@g' -i /etc/pam.d/sshd \
 && sed -i 's/.*Port 22/Port 2222/' /etc/ssh/sshd_config

RUN curl -sS https://getcomposer.org/installer | php \
 && mv composer.phar /usr/local/bin/composer

RUN /fossology/install/scripts/install-spdx-tools.sh
# RUN /fossology/install/scripts/install-ninka.sh
RUN set -x \
 && make install \
 && make clean \
 && /usr/local/lib/fossology/fo-postinstall --common \
 && useradd -r -m -g fossy sw360 \
 && mkdir /home/sw360/.ssh \
 && touch /home/sw360/.ssh/authorized_keys \
 && chmod 700 /home/sw360/.ssh \
 && chmod 600 /home/sw360/.ssh/authorized_keys \
 && chown sw360 /home/sw360/.ssh \
 && echo 'sw360:sw360fossy' | chpasswd

RUN cp /fossology/install/src-install-apache-example.conf \
        /etc/apache2/conf-available/fossology.conf \
 && ln -s /etc/apache2/conf-available/fossology.conf \
        /etc/apache2/conf-enabled/fossology.conf

RUN /fossology/install/scripts/php-conf-fix.sh --overwrite

VOLUME /srv/fossology/repository/
VOLUME /home/sw360

EXPOSE 80
EXPOSE 2222

RUN chmod +x /fossology/docker-entrypoint.sh
ENTRYPOINT ["/fossology/docker-entrypoint.sh"]
CMD ["bash"]
