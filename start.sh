#!/bin/sh
set -e

echo "PORT is: ${PORT:-8080}"
php -S 0.0.0.0:${PORT:-8080} -t public public/_router.php
