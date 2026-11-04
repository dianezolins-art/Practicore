#!/bin/sh
set -eu

port="${PORT:-8080}"
case "$port" in
    ''|*[!0-9]*)
        echo "Invalid PORT value: $port" >&2
        exit 1
        ;;
esac

sed -i "s/^Listen 80$/Listen ${port}/" /etc/apache2/ports.conf
sed -i "s#<VirtualHost \\*:80>#<VirtualHost *:${port}>#" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
