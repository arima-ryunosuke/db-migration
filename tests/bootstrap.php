<?php
require __DIR__ . '/../vendor/autoload.php';

// force interactive (Application::configureIO)
putenv('SHELL_INTERACTIVE=1');

if (DIRECTORY_SEPARATOR === '\\') {
    setlocale(LC_CTYPE, 'C');
}

if (!function_exists('posix_geteuid')) {
    function posix_geteuid()
    {
        return 999;
    }
}

if (!function_exists('posix_getpwuid')) {
    function posix_getpwuid($uid)
    {
        return ['name' => $uid === 999 ? 'dummyuser' : 'unknownuser'];
    }
}
