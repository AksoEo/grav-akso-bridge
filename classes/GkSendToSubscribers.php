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

        $org = $plugin->getGrav()['config']->get('plugins.akso-bridge.newsletter_send_org');
        $from = $plugin->getGrav()['config']->get('plugins.akso-bridge.gk_from');
        $fromName = $plugin->getGrav()['config']->get('plugins.akso-bridge.gk_from_name');
        $replyTo = $plugin->getGrav()['config']->get('plugins.akso-bridge.gk_reply_to') ?: null;

        if (!$org || !$from || !$fromName) {
            header('Content-Type: text/plain');
            echo("Eraro\n\n");
            echo($plugin->locale['admin']['gk_send_to_subs_missing_config']);
            die(400);
        }

        $notifTemplate = $app->bridge->post('/notif_templates', array(
            'base' => 'inherit',
            'org' => $org,
            'name' => $title,
            'intent' => 'newsletter',
            'subject' => $title,
            'from' => $from,
            'fromName' => $fromName,
            'replyTo' => $replyTo,
            'modules' => $modules,
        ), [], []);
        if (!$notifTemplate['k']) {
            header('Content-Type: text/plain');
            echo("Eraro\n\n");
            echo($notifTemplate['b']);
            die($notifTemplate['sc']);
        }
        return (int) $notifTemplate['h']['x-identifier'];
    }

    function run($title, $content, $url) {
        $templateId = GkSendToSubscribers::createNotifTemplate($this->plugin, $this->app, $title, $content, $url);
        $newsletterId = $this->plugin->getGrav()['config']->get('plugins.akso-bridge.gk_newsletter');

        if (!$newsletterId) {
            header('Content-Type: text/plain');
            echo("Eraro\n\n");
            echo($this->plugin->locale['admin']['gk_send_to_subs_missing_config']);
            die(400);
        }

        $res = $this->app->bridge->post("/newsletters/$newsletterId/!send_notif_template", array(
            'notifTemplateId' => $templateId,
            'deleteTemplateOnComplete' => true,
        ), [], []);

        if (!$res['k']) {
            header('Content-Type: text/plain');
            echo('Eraro');
            echo('');
            echo($res['b']);
            die();
        }
    }
}
