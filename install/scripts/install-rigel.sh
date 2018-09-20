#!/bin/bash
set -ex

###
# This script installs rigel agent for fossology
# Optional arguments:
# 1: user to set the ownership of directory with rigel data
# 2: group to set the ownership of directory with rigel data
# If called without arguments the ownership will be taken from caller
###

CR_USER=${1-$USER}
CR_GROUP=${2-$USER}
CR_HOME=/home/${CR_USER}
ENV_HOME=${CR_HOME}/rigel/rigelenv
RIGEL_PACKAGE=git+https://github.com/mcjaeger/rigel.git@dev

# Install python3.6(deadsnakes repo) and mod_wsgi dependencies
sudo add-apt-repository ppa:deadsnakes/ppa -y
sudo apt-get update -y
sudo apt-get install python3.6 -y
sudo apt-get install python3.6-dev -y
sudo apt-get install apache2-dev -y
sudo apt-get remove libapache2-mod-wsgi

# Use the newly installed python version and install dependencies
sudo update-alternatives --install /usr/bin/python python /usr/bin/python3.6 100
wget https://bootstrap.pypa.io/get-pip.py
sudo python get-pip.py
sudo ln -sfn /usr/local/bin/pip /usr/local/bin/pip3
sudo pip install virtualenv

# Create and activate env
mkdir -p ${CR_HOME}/rigel
python -m virtualenv ${ENV_HOME}
source ${ENV_HOME}/bin/activate

# Fetch and install requirements
pip install --upgrade $RIGEL_PACKAGE
pip install mod_wsgi
sudo chown -R ${CR_USER}:${CR_GROUP} ${CR_HOME}/rigel/

# Adjust Apache configs to include rigel and use the new mod_wsgi
echo "# Needed for rigel wsgi server" | sudo tee -a  /etc/apache2/apache2.conf
mod_wsgi-express module-config | sudo tee -a  /etc/apache2/apache2.conf
echo "# Port used by rigel" | sudo tee -a /etc/apache2/ports.conf
echo "Listen 8082" | sudo tee -a /etc/apache2/ports.conf

cat > /etc/apache2/sites-enabled/rigel_apache.conf << EOF
<VirtualHost *:8082>

    WSGIDaemonProcess rigelapp python-home=${ENV_HOME}/
    WSGIScriptAlias / ${ENV_HOME}/lib/python3.6/site-packages/rigel/server/rigelapp.wsgi

    SetEnv RIGEL_DIR ${CR_HOME}/rigel

    <Directory ${CR_HOME}/rigel/>
        WSGIProcessGroup rigelapp
        WSGIApplicationGroup %{GLOBAL}
        Require local
    </Directory>
</VirtualHost>
EOF

sudo /etc/init.d/apache2 restart

# Download default model and preprocessor language libraries for rigel
rigel-download-data
