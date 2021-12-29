<?php
namespace Grav\Plugin\AksoBridge;

/// Opens an AKSO Bridge with app access.
class AppBridge {
    private $grav = null;
    public $bridge = null;

    public function __construct($grav) {
        $this->grav = $grav;
        $this->apiHost = $this->grav['config']->get('plugins.akso-bridge.api_host');
    }

    public function open() {
        $grav = $this->grav;
        $apiKey = $grav['config']->get('plugins.akso-bridge.api_key');
        $apiSecret = $grav['config']->get('plugins.akso-bridge.api_secret');

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
