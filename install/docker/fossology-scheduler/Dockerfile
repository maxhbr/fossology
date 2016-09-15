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

ENV _update="apt-get update"
ENV _install="apt-get install -y --no-install-recommends"
ENV _cleanup="eval apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*"

RUN set -x \
 && $_update && $_install sudo && $_cleanup
RUN /usr/local/lib/fossology/fo-postinstall \
        --agent \
        --scheduler-only
        
RUN set -x \
 && echo 'fossy ALL=(ALL) NOPASSWD:ALL' >> /etc/sudoers

ADD docker-entrypoint.scheduler.sh /fossology
RUN chmod +x /fossology/docker-entrypoint.scheduler.sh
ENTRYPOINT ["/fossology/docker-entrypoint.scheduler.sh"]

USER fossy

CMD ["bash"]
