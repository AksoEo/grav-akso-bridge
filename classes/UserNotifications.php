<?php
namespace Grav\Plugin\AksoBridge;

use Grav\Plugin\AksoBridgePlugin;
use Grav\Plugin\AksoBridge\Utils;

class UserNotifications {
    private $plugin, $app, $bridge;

    public function __construct($plugin, $app, $bridge, $path) {
        $this->plugin = $plugin;
        $this->app = $app;
        $this->bridge = $bridge;
    }

    function isTelegramLinked() {
        $res = $this->bridge->get("/codeholders/self/telegram", []);
        if (!$res['k']) {
            if ($res['sc'] === 404) {
                return false;
            } else {
                throw new \Exception('Could not fetch telegram state');
            }
        }
        return true;
    }

    function linkTelegram() {
        $res = $this->bridge->post('/codeholders/self/telegram', [], [], []);
        if ($res['k']) {
            return array(
                'page' => 'link-telegram',
                'link' => $res['b'],
            );
        } else if ($res['sc'] === 409) {
            return array(
                'page' => 'link-telegram',
                'already_linked' => true,
            );
        } else {
            throw new \Exception('Could not acquire telegram link');
        }
    }

    function unlinkTelegram() {
        $res = $this->bridge->delete('/codeholders/self/telegram', []);
        if ($res['k']) {
            return array(
                'page' => 'unlink-telegram',
                'path' => $this->plugin->getGrav()['uri']->path(),
                'success' => true,
            );
        } else if ($res['sc'] === 404) {
            return array(
                'page' => 'unlink-telegram',
                'path' => $this->plugin->getGrav()['uri']->path(),
                'success' => false,
            );
        } else {
            throw new \Exception('could not unlink telegram');
        }
    }

    public function run() {
        if (isset($_POST['telegram']) && $_POST['telegram'] === 'link') {
            return $this->linkTelegram();
        } else if (isset($_POST['telegram']) && $_POST['telegram'] === 'unlink') {
            return $this->unlinkTelegram();
        }

        return array(
            'page' => 'notifications',
            'path' => $this->plugin->getGrav()['uri']->path(),
            'account_path' => $this->plugin->accountPath,
            'telegram_linked' => $this->isTelegramLinked(),
        );
    }
}

