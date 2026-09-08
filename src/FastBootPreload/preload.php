<?php
/**
 * The opcache preload script: `opcache.preload=<path to this file>` in the
 * php-fpm ini. Loads the classes real requests declared, from the list
 * GraphCommerce_FastBootPreload keeps in var/fastboot/classes.txt, through
 * the installation's autoloader. Without the list it loads nothing, so a
 * php-fpm master always starts.
 */
declare(strict_types=1);

$root = __DIR__;
for ($level = 0; $level < 8 && !is_file($root . '/vendor/autoload.php'); $level++) {
    $root = dirname($root);
}
$list = $root . '/var/fastboot/classes.txt';
if (!is_file($root . '/vendor/autoload.php') || !is_file($list)) {
    return;
}
require $root . '/vendor/autoload.php';
foreach (array_unique(array_filter(array_map('trim', file($list)))) as $class) {
    class_exists($class) || interface_exists($class) || trait_exists($class);
}
