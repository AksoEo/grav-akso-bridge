<?php
namespace Grav\Plugin\AksoBridge;

/// Opens an AKSO Bridge with app access.
use Grav\Common\Grav;

class AppBridge {
    public $bridge = null;
    private $apiHost;

    public function __construct() {
        $this->apiHost = Grav::instance()['config']->get('plugins.akso-bridge.api_host') ?? "";
    }

    public static function getApiKey() {
        return Grav::instance()['config']->get('plugins.akso-bridge.api_key');
    }

    public function open() {
        $apiKey = $this::getApiKey() ?? "";
        $apiSecret = Grav::instance()['config']->get('plugins.akso-bridge.api_secret') ?? "";

        // get ..
        $dirname = explode('/', __DIR__);
        array_pop($dirname);
        $dirname = implode('/', $dirname);
        $this->bridge = new \AksoBridge($dirname . '/aksobridged/run/aksobridge');
        $this->bridge->openApp($this->apiHost, $apiKey, $apiSecret);
    }

    public function close() {
        $this->bridge->close();
        $this->bridge = null;
    }
}
