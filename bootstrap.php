<?php
const TEST_SERVER = '127.0.0.1:1080';

shell_exec(
    'docker run -dit -p ' .
    TEST_SERVER .
    ':80 -v ' .
    __DIR__ .
    '/var/www/html:/var/www/html --name sharils_curl php:apache'
);
usleep(50000);

register_shutdown_function(function () {
    `docker rm -f sharils_curl`;
});
