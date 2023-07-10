<?php
namespace Grav\Plugin\AksoBridge;

use Grav\Common\Grav;

class OneTimeToken {
    private $plugin, $app;

    public function __construct($plugin, $app) {
        $this->plugin = $plugin;
        $this->app = $app;
    }

    public function run(): array {
        $uri = Grav::instance()['uri'];
        $ctx = $uri->query('ctx') ?? '';
        $token = $uri->query('token') ?? '';

        $ctx = strtolower($ctx);

        $res = $this->app->bridge->get('/tokens', array(
            'ctx' => $ctx,
            'token' => $token,
        ));
        if (!$res['k']) {
            if ($res['sc'] === 404 || $res['sc'] === 400) {
                $this->plugin->getGrav()->fireEvent('onPageNotFound');
                return [];
            }
            Grav::instance()['log']->error("failed to get token $ctx $token: " . $res['b']);
            throw new \Exception("failed to get token data: " . $res['b']);
        }
        $data = $res['b'];

        $out = array('ctx' => $ctx, 'token' => $token);
        if ($uri->method() && ($_POST['action'] ?? '') === 'submit') {
            $res = $this->app->bridge->put('/tokens', [], array(
                'ctx' => $ctx,
                'token' => $token,
            ), []);
            if (!$res['k']) {
                $out['error'] = true;
            } else {
                $out['done'] = true;
            }
        }

        if ($ctx === 'delete_email_address') {
            Grav::instance()['page']->title($this->plugin->locale['ott']['del_email_page_title']);
            $out = array_merge($out, $this->runDeleteEmailAddress($data));
        } else if ($ctx === 'unsubscribe_newsletter') {
            Grav::instance()['page']->title($this->plugin->locale['ott']['unsub_newsletter_page_title']);
            $out = array_merge($out, $this->runUnsubscribeNewsletter($data));
        } else {
            $this->plugin->getGrav()->fireEvent('onPageNotFound');
            return [];
        }

        return $out;
    }

    public function runDeleteEmailAddress($data): array {
        return array('email' => $data['email']);
    }

    public function runUnsubscribeNewsletter($data): array {
        $newsletterId = $data['newsletterId'];
        $res = $this->app->bridge->get("/newsletters/$newsletterId", array(
            'fields' => ['name'],
        ));

        $out = array('name' => '?');
        if ($res['k']) $out['name'] = $res['b']['name'];
        return $out;
    }
}
