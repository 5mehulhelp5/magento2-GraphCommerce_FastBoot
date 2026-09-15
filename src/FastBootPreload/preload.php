<?php

/**
 * The opcache preload script: `opcache.preload=<path to this file>` in the
 * php-fpm ini. Loads the classes real requests declared, from the list
 * GraphCommerce_FastBootPreload keeps in var/cache/preload/classes.txt, through
 * the installation's autoloader. Without the list it loads nothing, so a
 * php-fpm master always starts.
 */
declare(strict_types=1);

$configuredRoot = getenv('FASTBOOT_MAGENTO_ROOT');
$root = $configuredRoot ?: __DIR__;
if (!$configuredRoot) {
    for ($level = 0; $level < 8 && !is_file($root . '/vendor/autoload.php'); $level++) {
        $root = dirname($root);
    }
} elseif (!is_file($root . '/vendor/autoload.php') || !is_file($root . '/app/bootstrap.php')) {
    throw new \RuntimeException('FASTBOOT_MAGENTO_ROOT must point to the Magento installation being preloaded.');
}
$cache = getenv('FASTBOOT_CACHE_DIR') ?: $root . '/var/cache';
$list = rtrim($cache, '/') . '/preload/classes.txt';
if (!is_file($root . '/vendor/autoload.php') || !is_file($list)) {
    return;
}
require $root . '/vendor/autoload.php';
foreach (array_unique(array_filter(array_map('trim', file($list)))) as $class) {
    if (str_contains($class, '@') || str_contains($class, "\0") || str_starts_with($class, 'PhpParser\\')) {
        continue;
    }
    class_exists($class) || interface_exists($class) || trait_exists($class);
}
