# FOSSology Dockerfile
# Copyright Siemens AG 2016, fabio.huser@siemens.com
#
# Copying and distribution of this file, with or without modification,
# are permitted in any medium without royalty provided the copyright
# notice and this notice are preserved.  This file is offered as-is,
# without any warranty.
#
# Description: Docker container image recipe

FROM fossology-base
MAINTAINER Fossology <fossology@fossology.org>
WORKDIR /fossology

RUN /usr/local/lib/fossology/fo-postinstall \
        --agent \
        --database \
        --scheduler-only

ADD docker-entrypoint.scheduler.sh /fossology
RUN chmod +x /fossology/docker-entrypoint.scheduler.sh
ENTRYPOINT ["/fossology/docker-entrypoint.scheduler.sh"]

USER fossy

CMD ["bash"]
