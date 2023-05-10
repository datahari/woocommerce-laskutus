#!/bin/bash

#############################################################################
#                                                                           #
#  THIS SCRIPT GENERATES THE PLUGIN ZIP FILE FROM THE REMOTE MASTER BRANCH  #
#                                                                           #
#############################################################################

cd "$(dirname "$BASH_SOURCE")"

# if folder with git repository name already exists, quit
# be cause we don't want to delete or overwrite anything!
if [ -e "woocommerce-laskuhari-payment-gateway" ]; then
    echo "Folder woocommerce-laskuhari-payment-gateway already exists!"
    exit 0
fi

# clone git repository
git clone https://github.com/datahari/woocommerce-laskutus.git woocommerce-laskuhari-payment-gateway

# remove git folder and files
rm -rf woocommerce-laskuhari-payment-gateway/.git
rm -rf woocommerce-laskuhari-payment-gateway/.gitignore

# remove README.md
rm -rf woocommerce-laskuhari-payment-gateway/README.md

# remove tests
rm -rf woocommerce-laskuhari-payment-gateway/test

# remove scripts
rm -rf woocommerce-laskuhari-payment-gateway/scripts

# remove config
rm -rf woocommerce-laskuhari-payment-gateway/config

# remove composer files
rm -f woocommerce-laskuhari-payment-gateway/composer.json
rm -f woocommerce-laskuhari-payment-gateway/composer.lock

# get version number
VERSION=`grep "Version: " woocommerce-laskuhari-payment-gateway/woocommerce-laskuhari-payment-gateway.php | awk '{print $2}'`

# create zip arhive named with version number
mkdir package
zip -r package/woocommerce-laskuhari-payment-gateway.$VERSION.zip woocommerce-laskuhari-payment-gateway

# remove original folder
rm -rf woocommerce-laskuhari-payment-gateway
