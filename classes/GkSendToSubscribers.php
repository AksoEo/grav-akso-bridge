<?php
namespace Grav\Plugin\AksoBridge;

use Grav\Plugin\AksoBridge\MarkdownExt;

class GkSendToSubscribers {
    private $plugin, $app, $user;

    public function __construct($plugin, $app) {
        $this->plugin = $plugin;
        $this->app = $app;
        $this->user = $plugin->aksoUser ? $plugin->bridge : null;
    }

    function run($title, $content, $url) {
        // $md = \Grav\Common\Utils::processMarkdown($content);
        // $md = (new MarkdownExt($this->plugin))->processHTMLComponents($md);
        //
        // we are using markdown notif templates
        $md = $this->app->bridge->absoluteMarkdownUrls($content, $url);
        $extraText = $this->plugin->locale['content']['gk_send_to_subs_extra_text'];

        $notifTemplate = $this->app->bridge->post('/notif_templates', array(
            'base' => 'inherit',
            'org' => 'uea',
            'name' => $title,
            'intent' => 'newsletter',
            'subject' => $title,
            'from' => $this->plugin->getGrav()['config']->get('plugins.akso-bridge.gk.from'),
            'fromName' => $this->plugin->getGrav()['config']->get('plugins.akso-bridge.gk.from_name'),
            'replyTo' => $this->plugin->getGrav()['config']->get('plugins.akso-bridge.gk.reply_to'),
            'modules' => [
                array(
                    'type' => 'text',
                    'columns' => [$md],
                ),
                array(
                    'type' => 'text',
                    'columns' => [$extraText],
                ),
            ],
        ), [], []);
        if (!$notifTemplate['k']) {
            echo('Eraro');
            echo('');
            echo($notifTemplate['b']);
            die();
        }
        $templateId = (int) $notifTemplate['h']['x-identifier'];
        $newsletterId = $this->plugin->getGrav()['config']->get('plugins.akso-bridge.gk.newsletter');

        $res = $this->app->bridge->post("/newsletters/$newsletterId/!send_notif_template", array(
            'notifTemplateId' => $templateId,
            'deleteTemplateOnComplete' => true,
        ), [], []);

        if (!$res['k']) {
            echo('Eraro');
            echo('');
            echo($res['b']);
            die();
        }
    }
}
