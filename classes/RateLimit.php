<?php
namespace Grav\Plugin\AksoBridge;

use Grav\Common\Uri;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

class RateLimit {
    private $cache;

    public function __construct() {
        $this->cache = new FilesystemAdapter('akso_ratelimit');
    }

    function getKey() {
        $ip = Uri::ip();
        if ($ip === 'UNKNOWN') {
            // I don't know why this happens, so if it does just fall back to the $_SERVER value
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return $ip;
    }

    public function shouldLimitClient(string $key, int $reqsPerInterval) {
        $key = $this->getKey() . '__' . $key;
        $item = $this->cache->getItem($key);

        if (!$item->isHit()) {
            return false;
        } else {
            return $item->get() >= $reqsPerInterval;
        }
    }

    public function addClientHit(string $key, int $timeInterval) {
        $key = $this->getKey() . '__' . $key;
        $item = $this->cache->getItem($key);
        $item->expiresAfter($timeInterval);
        $item->set($item->get() + 1);
        $this->cache->save($item);
    }
}
