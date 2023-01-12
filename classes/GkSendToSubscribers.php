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

    public static function createNotifTemplate($plugin, $app, $title, $content, $url) {
        // $md = \Grav\Common\Utils::processMarkdown($content);
        // $md = (new MarkdownExt($this->plugin))->processHTMLComponents($md);
        //
        // we are using markdown notif templates
        if (!str_ends_with("/", $url)) {
            $url .= "/";
        }
        $md = $app->bridge->absoluteMarkdownUrls($content, $url)['value'];
        $extraText = $plugin->locale['content']['gk_send_to_subs_extra_text'];

        $modules = [
            array(
                'type' => 'text',
                'columns' => [$md],
            ),
        ];
        if ($extraText) {
            $modules[] = array(
                'type' => 'text',
                'columns' => [$extraText],
            );
        }

        $notifTemplate = $app->bridge->post('/notif_templates', array(
            'base' => 'inherit',
            'org' => $plugin->getGrav()['config']->get('plugins.akso-bridge.newsletter_send_org'),
            'name' => $title,
            'intent' => 'newsletter',
            'subject' => $title,
            'from' => $plugin->getGrav()['config']->get('plugins.akso-bridge.gk.from'),
            'fromName' => $plugin->getGrav()['config']->get('plugins.akso-bridge.gk.from_name'),
            'replyTo' => $plugin->getGrav()['config']->get('plugins.akso-bridge.gk.reply_to'),
            'modules' => $modules,
        ), [], []);
        if (!$notifTemplate['k']) {
            echo("Eraro\n\n");
            echo($notifTemplate['b']);
            die($notifTemplate['sc']);
        }
        return (int) $notifTemplate['h']['x-identifier'];
    }

    function run($title, $content, $url) {
        $templateId = GkSendToSubscribers::createNotifTemplate($this->plugin, $this->app, $title, $content, $url);
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
