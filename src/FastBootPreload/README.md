# GraphCommerce_FastBootPreload

Opcache preload from the classes real requests declare. The list in `var/fastboot/classes.txt` records itself for fifteen minutes after every compile, and `preload.php` in this directory loads it at php-fpm start: `opcache.preload=<path to preload.php>` is the one line a deployment adds, with `opcache.preload_user` where php-fpm runs as root. Without a list the script loads nothing, so a master always starts. `FASTBOOT_RECORD=1` in the environment records outside the window. The repository README holds the numbers and the rollout.
