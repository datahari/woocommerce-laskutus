#!/bin/bash

#############################################################################
#                                                                           #
#      THIS SCRIPT GENERATES THE PLUGIN ZIP FILE FROM THE LOCAL SOURCE      #
#                                                                           #
#############################################################################

cd "$(dirname "$0")"

# if folder with git repository name already exists, quit
# be cause we don't want to delete or overwrite anything!
if [ -e "woocommerce-laskuhari-payment-gateway" ]; then
    echo "Folder woocommerce-laskuhari-payment-gateway already exists!"
    exit 0
fi

# copy local code
rsync -av ../ woocommerce-laskuhari-payment-gateway/ \
      --exclude="test/" \
      --exclude="vendor/" \
      --exclude=".git/" \
      --exclude="scripts/" \
      --exclude="config/" \
      --exclude="*.zip" \
      --exclude="*.txt"

# remove git folder and files
rm -rf woocommerce-laskuhari-payment-gateway/.git
rm -rf woocommerce-laskuhari-payment-gateway/.gitignore

# remove README.md
rm -rf woocommerce-laskuhari-payment-gateway/README.md

# remove tests
rm -rf woocommerce-laskuhari-payment-gateway/test

# remove scripts
rm -rf woocommerce-laskuhari-payment-gateway/scripts

# get version number
VERSION=`grep "Version: " woocommerce-laskuhari-payment-gateway/woocommerce-laskuhari-payment-gateway.php | awk '{print $2}'`

# create zip arhive named with version number
mkdir package
zip -r package/dev-woocommerce-laskuhari-payment-gateway.$VERSION.zip woocommerce-laskuhari-payment-gateway

# remove original folder
rm -rf woocommerce-laskuhari-payment-gateway
