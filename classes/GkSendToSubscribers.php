<?php
namespace Grav\Plugin\AksoBridge;

use Grav\Plugin\AksoBridge\MarkdownExt;

class GkSendToSubscribers {
    private $plugin, $bridge, $user;

    public function __construct($plugin, $bridge) {
        $this->plugin = $plugin;
        $this->bridge = $bridge;
        $this->user = $plugin->aksoUser ? $plugin->bridge : null;
    }

    function run($title, $content, $url) {
        $md = \Grav\Common\Utils::processMarkdown($content);
        $md = (new MarkdownExt($this->plugin))->processHTMLComponents($md);
        var_dump($md);
        // TODO
    }
}
