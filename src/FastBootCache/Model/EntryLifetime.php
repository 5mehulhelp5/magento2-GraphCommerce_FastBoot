<?php

declare(strict_types=1);

namespace GraphCommerce\FastBootCache\Model;

use Magento\Framework\Cache\FrontendInterface;

/** Read actual backend expiry, never grant a promoted entry another full cache lifetime. */
class EntryLifetime
{
    public function remaining(FrontendInterface $frontend, string $id, mixed $value): int|null|false
    {
        $low = $frontend->getLowLevelFrontend();
        try {
            if ($low instanceof \Magento\Framework\Cache\Frontend\Adapter\Symfony\LowLevelFrontend) {
                $clean = preg_replace('/[^a-zA-Z0-9_]/', '_', str_replace('.', '__', strtoupper($id)));
                $item = $low->getItem($clean);
                $record = $item->isHit() ? $item->get() : null;
                if (!is_array($record) || !array_key_exists('expire', $record) || ($record['data'] ?? null) !== $value) {
                    return false;
                }
                $expiry = $record['expire'];
            } elseif ($low instanceof \Zend_Cache_Core) {
                $record = $low->getMetadatas(strtoupper($id));
                if (!$record || $low->load(strtoupper($id)) !== $value) {
                    return false;
                }
                $expiry = $record['expire'];
            } else {
                return false;
            }
            return $expiry === null || $expiry === false ? null : max(0, (int)floor($expiry - microtime(true)));
        } catch (\Throwable) {
            // The native read already succeeded; inability to prove expiry disables promotion only.
            return false;
        }
    }
}
