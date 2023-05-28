<?php

namespace Grav\Plugin\AksoBridge;

use Grav\Common\Grav;
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

        $res = $this->app->bridge->get("/aksopay/payment_orgs/$org/methods/$method", array(
            'fields' => ['thumbnail'],
        ), 240);

        if (!$res['k']) {
            Grav::instance()['log']->error("could not load payment org $org method $method for thumbnail: " . $res['b']);
            $this->plugin->getGrav()->fireEvent('onPageNotFound');
            return;
        }
        if (!$res['b']['thumbnail']) {
            $this->plugin->getGrav()->fireEvent('onPageNotFound');
            return;
        }
        $size = preg_replace('/px$/', '', $size);
        $url = $res['b']['thumbnail'][$size] ?? '';

        http_response_code(302); // Found
        header('Location: ' . $url);
        die();
    }

    public function hasThumbnail($org, $method) {
        $res = $this->app->bridge->get("/aksopay/payment_orgs/$org/methods/$method", array(
            'fields' => ['thumbnail'],
        ), 240);
        return $res['k'] && $res['b']['thumbnail'] !== null;
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
