<?php

namespace Grav\Plugin\AksoBridge;

use Grav\Plugin\AksoBridgePlugin;

class Payments {
    protected $plugin, $app;

    public function __construct($plugin, $app) {
        $this->plugin = $plugin;
        $this->app = $app;
    }

    public const TH_ORG = 'o';
    public const TH_METHOD = 'm';
    public const TH_SIZE = 's';
    public function runMethodThumbnail() {
        $org = isset($_GET[self::TH_ORG]) ? $_GET[self::TH_ORG] : '?';
        $method = isset($_GET[self::TH_METHOD]) ? $_GET[self::TH_METHOD] : '?';
        $size = isset($_GET[self::TH_SIZE]) ? $_GET[self::TH_SIZE] : '?';
        $path = "/aksopay/payment_orgs/$org/methods/$method/thumbnail/$size";

        $res = $this->app->bridge->getRaw($path, 60);
        if ($res['k']) {
            header('Content-Type: ' . $res['h']['content-type']);
            try {
                readfile($res['ref']);
            } finally {
                $this->app->bridge->releaseRaw($path);
            }
        } else {
            $this->plugin->getGrav()->fireEvent('onPageNotFound');
        }
        die();
    }

    public function hasThumbnail($org, $method) {
        // very hacky.. alas. PaymentMethods do not have any other indication that they have a thumbnail
        $path = "/aksopay/payment_orgs/$org/methods/$method/thumbnail/32px";
        $res = $this->app->bridge->getRaw($path, 60);
        $this->app->bridge->releaseRaw($path);
        return $res['k'];
    }

    public function getMethodThumbnailSrcSet($org, $method, int $baseSize) {
        $base = AksoBridgePlugin::PAYMENT_METHOD_THUMBNAIL_PATH . "?" . self::TH_ORG . "="
            . $org . "&" . self::TH_METHOD . "=" . $method . "&" . self::TH_SIZE . "=";
        $src = $base . $baseSize . "px";
        $srcset = $base . $baseSize . "px 1x";
        if ($baseSize < 512) {
            $srcset .= ", " . $base . ($baseSize * 2) . "px 2x";

            if ($baseSize < 256) {
                $srcset .= ", " . $base . ($baseSize * 4) . "px 4x";
            }
        }

        return array(
            "src" => $src,
            "srcset" => $srcset,
        );
    }
}
