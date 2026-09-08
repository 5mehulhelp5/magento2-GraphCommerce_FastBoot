<?php
declare(strict_types=1);

namespace GraphCommerce\FastBootCache\Model;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;

/**
 * Values as PHP files under var/fastboot/<version>/<group>/, which opcache
 * keeps in shared memory: an include of a cached file returns the value in
 * microseconds, where a cache entry costs a network read, a decompress and
 * a deserialise on every request. A write goes to a temporary file and
 * renames, so a request never includes a half file, and invalidates the
 * path in opcache, so a rewritten entry is read anew whatever the timestamp
 * validation says. A bump of the version
 * sweeps the directories; opcache keeps the compiled copies of dropped files
 * as wasted memory until its own restart, so opcache.max_wasted_percentage
 * sets how many config invalidations a php-fpm master lives through.
 */
class PhpFiles
{
    private const DIR = 'fastboot';

    private ?string $root = null;

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Version $version,
        private readonly DeploymentConfig $deploymentConfig,
    ) {
    }

    private const EXPIRES = 'fastboot_expires';

    /** The lifetime Magento's frontends give an entry saved with `false`. */
    private const DEFAULT_LIFETIME = 7200;

    /**
     * The lifetime of a file written from a load, whose cache lifetime the
     * frontend does not tell: the entry is read from the cache again after it.
     */
    public const LOADED_LIFETIME = self::DEFAULT_LIFETIME;

    /**
     * The seconds an entry stays valid as the frontend understands them:
     * null for no lifetime, false for the frontend's default.
     */
    public static function lifetime($lifeTime): ?int
    {
        if ($lifeTime === null) {
            return null;
        }

        return $lifeTime === false ? self::DEFAULT_LIFETIME : max(0, (int)$lifeTime);
    }

    public function read(string $group, string $id): mixed
    {
        $file = $this->path($group, $id);
        if (!is_file($file)) {
            return null;
        }
        $value = include $file;
        if (is_array($value) && isset($value[self::EXPIRES])) {
            return $value[self::EXPIRES] > time() ? $value['value'] : null;
        }

        return $value;
    }

    /**
     * @param int|null $lifeTime seconds the value stays valid; a value with a lifetime is
     *   wrapped with its expiry, and a read past it answers null
     */
    public function write(string $group, string $id, mixed $value, ?int $lifeTime = null): void
    {
        if (!self::exportable($value)) {
            return;
        }
        if ($lifeTime !== null) {
            $value = [self::EXPIRES => time() + $lifeTime, 'value' => $value];
        }
        $file = $this->path($group, $id);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $temporary = $file . '.' . getmypid() . '.tmp';
        if (file_put_contents($temporary, "<?php\nreturn " . var_export($value, true) . ";\n") !== false) {
            rename($temporary, $file);
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($file, true);
            }
        }
    }

    /** Only scalars, null and arrays of them become a file: an object would export as code. */
    private static function exportable(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::exportable($item)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_scalar($value);
    }

    /**
     * Drops every version directory; the process that bumped the version calls
     * it, and a request still reading a file of the old version falls back to
     * the cache when the file is gone.
     */
    public function sweep(): void
    {
        foreach (glob($this->root() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $this->removeDirectory($dir);
        }
    }

    public function remove(string $group, string $id): void
    {
        $file = $this->path($group, $id);
        if (is_file($file)) {
            unlink($file);
        }
    }

    private function path(string $group, string $id): string
    {
        return $this->root() . '/' . $this->version->current() . '/'
            . preg_replace('/[^A-Za-z0-9_.-]/', '_', $group) . '/'
            . preg_replace('/[^A-Za-z0-9_.-]/', '_', $id) . '.php';
    }

    /**
     * One tree per cache: two installations that share var/ but not their
     * cache, such as a php-fpm host and a worker container, keep their
     * versions apart.
     */
    private function root(): string
    {
        if ($this->root === null) {
            $var = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR)->getAbsolutePath();
            $backend = (array)$this->deploymentConfig->get('cache/frontend/default/backend_options', []);
            $this->root = rtrim($var, '/') . '/' . self::DIR . '/' . substr(hash('sha256', json_encode([
                $this->deploymentConfig->get('cache/frontend/default/id_prefix'),
                $backend['server'] ?? '',
                $backend['port'] ?? '',
                $backend['database'] ?? '',
            ])), 0, 8);
        }

        return $this->root;
    }

    private function removeDirectory(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            is_dir($file) ? $this->removeDirectory($file) : unlink($file);
        }
        @rmdir($dir);
    }
}
