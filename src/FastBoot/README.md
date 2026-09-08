# GraphCommerce_FastBoot

The work Magento repeats on every php-fpm request, remembered: the system configuration and the theme's view.xml as PHP arrays, the scopes and the stores of a website from the cache, the default store of a group from the store repository, the deployment config check from the cache, quoting without a database connection, and only the entries an area changes for the object manager. `bin/magento fastboot:preload` writes an opcache preload script from the classes real requests declared. The repository README holds the design, the numbers and the rollout.
