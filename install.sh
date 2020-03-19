#!/bin/bash

# Watch TV install script
# https://github.com/phpbg/watchtv

# -e option instructs bash to immediately exit if any command has a non-zero exit status
set -e

command_exists () {
    command -v $1 >/dev/null 2>&1;
}

if [[ $EUID -ne 0 ]]; then
   echo "This script must be run as root" 
   exit 1
fi

if ! command_exists apt; then
   echo "This script is compatible only with debian and its derivatives (e.g. raspbian,ubuntu)" 
   exit 1
fi

echo "Updating packages metadata"
apt update &> /dev/null

echo "Installing dependencies"
apt -y install php-cli dvb-tools

echo "Creating watchtv user and group"
if id watchtv >/dev/null 2>&1; then
        echo "User already exists"
else
        useradd -U watchtv -M -G video
fi

echo "Copying to /opt/watchtv"
rm -rf /opt/watchtv
cp -r ./ /opt/watchtv

echo "Installing systemd service"
cp -f watchtv.service /etc/systemd/system/
systemctl daemon-reload
systemctl restart watchtv
systemctl enable watchtv

echo "Success, you may now remove current directory. Open you browser and browse http://localhost:8080/"