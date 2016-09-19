# FOSSology Dockerfile
# Copyright Siemens AG 2016, fabio.huser@siemens.com, maximilian.huber@tngtech.com
#
# Copying and distribution of this file, with or without modification,
# are permitted in any medium without royalty provided the copyright
# notice and this notice are preserved.  This file is offered as-is,
# without any warranty.
#
# Description: Docker container image recipe

FROM fossology-base
MAINTAINER Fossology <fossology@fossology.org>

RUN /usr/local/lib/fossology/fo-postinstall \
        --web-only \
 && systemctl disable apache2

RUN set -x \
 && chmod -R o+r /etc/apache2 \
 && mkdir -p /var/log/apache2/ \
 && chown -R fossy:fossy /var/log/apache2/ \
 && chown -R fossy:fossy /var/run/apache2/ \
 && chown -R fossy:fossy /var/lock/apache2/


EXPOSE 8080

ADD docker-entrypoint.web.sh /fossology
RUN chmod +x /fossology/docker-entrypoint.web.sh
ENTRYPOINT ["/fossology/docker-entrypoint.web.sh"]

USER fossy

CMD ["bash"]
