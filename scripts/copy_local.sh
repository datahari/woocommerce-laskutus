#!/bin/bash

# copies local files to test environment wordpress installation

cd "$(dirname "$0")"

sudo rsync -av --delete ../ ../test/assets/wordpress/wp-content/plugins/woocommerce-laskuhari-payment-gateway/ \
     --exclude="test/" \
     --exclude="vendor/" \
     --exclude=".git/" \
     --exclude="scripts/" \
     --exclude="config/" \
     --exclude="*.zip" \
     --exclude="*.txt"
sudo chown -R --reference=../test/assets/wordpress/wp-content/index.php ../test/assets/wordpress/wp-content/plugins/woocommerce-laskuhari-payment-gateway/
