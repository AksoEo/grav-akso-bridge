<?php
namespace Grav\Plugin\AksoBridge;

use Grav\Common\Grav;
use Grav\Plugin\AksoBridgePlugin;
use Grav\Plugin\AksoBridge\Utils;

class UserNotifications {
    private $plugin, $app, $bridge;

    public function __construct($plugin, $app, $bridge) {
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

    function getSubscribedNewslettersSummary() {
        $newsletterOrgs = $this->plugin->getGrav()['config']->get('plugins.akso-bridge.newsletter_orgs');
        $res = $this->bridge->get('/codeholders/self/newsletter_subscriptions', array(
            'fields' => ['id', 'org', 'name'],
            'filter' => array(
                'org' => array('$in' => $newsletterOrgs),
            ),
            'order' => [['name', 'desc']],
            'offset' => 0,
            'limit' => 10,
        ));
        if (!$res['k']) {
            Grav::instance()['log']->warn('Failed to fetch codeholder newsletter subscriptions: ' . $res['b']);
            return array(
                'total' => -1,
                'items' => [],
            );
        }
        return array(
            'total' => $res['h']['x-total-items'],
            'items' => $res['b'],
        );
    }

    private $newsletters = null;
    function getNewsletters() {
        if ($this->newsletters) {
            return $this->newsletters;
        }
        $newsletterOrgs = $this->plugin->getGrav()['config']->get('plugins.akso-bridge.newsletter_orgs');

        $newsletters = array();
        $totalNewsletters = 1;
        while (count($newsletters) < $totalNewsletters) {
            $res = $this->app->bridge->get('/newsletters', array(
                'fields' => ['id', 'org', 'name', 'description'],
                'filter' => array(
                    'public' => true,
                    'org' => array('$in' => $newsletterOrgs),
                ),
                'order' => [['name', 'desc']],
                'offset' => count($newsletters),
                'limit' => 100,
            ));
            if (!$res['k']) {
                throw new \Exception('could not load newsletters');
            }
            if (empty($res['b'])) break;
            $totalNewsletters = $res['h']['x-total-items'];
            foreach ($res['b'] as $item) {
                $newsletters[$item['id']] = $item;
            }
        }

        $subscriptions = array();
        $totalSubscriptions = 1;
        while (count($subscriptions) < $totalSubscriptions) {
            $res = $this->bridge->get('/codeholders/self/newsletter_subscriptions', array(
                'fields' => ['id', 'org', 'name', 'time'],
                'filter' => array(
                    'org' => array('$in' => $newsletterOrgs),
                ),
                'offset' => count($subscriptions),
                'limit' => 100,
            ));
            if (!$res['k']) {
                throw new \Exception('could not load subscriptions');
            }
            if (empty($res['b'])) break;
            $totalSubscriptions = $res['h']['x-total-items'];
            foreach ($res['b'] as $item) {
                $subscriptions[$item['id']] = $item;
            }
        }

        foreach ($subscriptions as $sub) {
            if (!isset($newsletters[$sub['id']])) {
                // for some reason the user is subscribed to a newsletter that isn't listed..?
                $newsletters[$sub['id']] = $sub;
            }
            $newsletters[$sub['id']]['time'] = $sub['time'];
            $newsletters[$sub['id']]['subscribed'] = true;
        }

        foreach ($newsletters as &$newsletter) {
            $newsletter['description_rendered'] = $this->app->bridge->renderMarkdown(
                $newsletter['description'] ?: '',
                ['emphasis', 'strikethrough', 'link'],
            )['c'];
        }

        $this->newsletters = $newsletters;
        return $newsletters;
    }

    function getNewsletter($newsletter) {
        $res = $this->app->bridge->get("/newsletters/$newsletter", array(
            'fields' => ['id', 'org', 'name', 'description', 'public'],
        ));
        if ($res['sc'] === 404) return null;
        if (!$res['k']) {
            throw new \Exception('could not load newsletter');
        }
        if (!$res['b']['public']) return null;

        return $res['b'];
    }

    function subscribeNewsletter($newsletter) {
        $newsletterData = $this->getNewsletter($newsletter);
        $newsletterName = $newsletterData ? $newsletterData['name'] : null;

        $res = $this->bridge->post('/codeholders/self/newsletter_subscriptions', array(
            'id' => $newsletter,
        ), [], []);
        if ($res['k']) {
            return array(
                'success' => true,
                'message' => $this->plugin->locale['account_notifs']['newsletter_subscribed_0']
                    . $newsletterName . $this->plugin->locale['account_notifs']['newsletter_subscribed_1'],
            );
        } else {
            $error = $this->plugin->locale['account_notifs']['newsletter_failed_unknown'];
            if ($res['sc'] === 404) {
                $error = $this->plugin->locale['account_notifs']['newsletter_subscribe_failed_notfound'];
            } else if ($res['sc'] === 409) {
                $error = $this->plugin->locale['account_notifs']['newsletter_subscribe_failed_exists'];
            }
            return array(
                'success' => false,
                'message' => $this->plugin->locale['account_notifs']['newsletter_subscribe_failed_0']
                    . $newsletterName . $this->plugin->locale['account_notifs']['newsletter_subscribe_failed_1']
                    . $error,
            );
        }
    }

    function unsubscribeNewsletter($newsletter, $reason) {
        $newsletterData = $this->getNewsletter($newsletter);
        $newsletterName = $newsletterData ? $newsletterData['name'] : null;

        $res = $this->bridge->delete("/codeholders/self/newsletter_subscriptions/$newsletter", array(
            'reason' => $reason,
        ));
        if ($res['k']) {
            return array(
                'success' => true,
                'message' => $this->plugin->locale['account_notifs']['newsletter_unsubscribed_0']
                    . $newsletterName . $this->plugin->locale['account_notifs']['newsletter_unsubscribed_1'],
            );
        } else {
            $error = $this->plugin->locale['account_notifs']['newsletter_failed_unknown'];
            if ($res['sc'] === 404) {
                $error = $this->plugin->locale['account_notifs']['newsletter_unsubscribe_failed_notfound'];
            }
            return array(
                'success' => false,
                'message' => $this->plugin->locale['account_notifs']['newsletter_unsubscribe_failed_0']
                    . $newsletterName . $this->plugin->locale['account_notifs']['newsletter_unsubscribe_failed_1']
                    . $error,
            );
        }
    }

    function getGlobalNotifPrefs() {
        $res = $this->bridge->get("/codeholders/self/notif_prefs/global", []);
        if (!$res['k']) {
            throw new \Exception('could not load notif prefs');
        }
        return $this->notifPrefToStr($res['b']['pref']);
    }

    function setGlobalNotifPrefs($value) {
        $res = $this->bridge->put("/codeholders/self/notif_prefs/global", array(
            'pref' => $value,
        ), [], []);
        if (!$res['k']) {
            return array(
                'success' => false,
                'message' => $this->plugin->locale['account_notifs']['notif_pref_update_failed'],
            );
        }
        return array(
            'success' => true,
            'message' => $this->plugin->locale['account_notifs']['notif_pref_updated'],
        );
    }

    function getBuiltinNames() {
        $names = [];
        foreach ($this->plugin->locale['notif_pref_builtin_categories'] as $key => $value) {
            if (str_ends_with($key, '_desc')) continue;
            $names[] = $key;
        }
        return $names;
    }

    function getBuiltinNotifPrefs() {
        $names = $this->getBuiltinNames();
        $prefs = [];
        foreach ($names as $name) {
            $res = $this->bridge->get("/codeholders/self/notif_prefs/builtin:$name", []);
            if ($res['sc'] === 404) {
                $prefs[$name] = null;
                continue;
            }
            if (!$res['k']) {
                throw new \Exception('could not load built-in notif prefs');
            }
            $prefs[$name] = $this->notifPrefToStr($res['b']['pref']);
        }
        return $prefs;
    }

    function setBuiltinNotifPrefs($values) {
        $names = $this->getBuiltinNames();
        $prefs = [];
        foreach ($names as $name) {
            if (!isset($values[$name])) continue;
            $value = $values[$name];

            $res = null;
            if (empty($value)) {
                $res = $this->bridge->delete("/codeholders/self/notif_prefs/builtin:$name", []);
            } else {
                $res = $this->bridge->put("/codeholders/self/notif_prefs/builtin:$name", array(
                    'pref' => $values[$name],
                ), [], []);
            }

            if ($res['sc'] === 404) continue;
            if (!$res['k']) {
                return array(
                    'success' => false,
                    'message' => $this->plugin->locale['account_notifs']['notif_pref_update_failed'],
                );
            }
        }
        return array(
            'success' => true,
            'message' => $this->plugin->locale['account_notifs']['notif_pref_updated'],
        );
    }

    function notifPrefToStr($pref) {
        if (in_array('email', $pref) && in_array('telegram', $pref)) {
            return 'et';
        } else if (in_array('telegram', $pref)) {
            return 't';
        } else {
            return 'e';
        }
    }

    function strToNotifPref($str) {
        if ($str === 'x') return [];
        if ($str === 'e') return ['email'];
        if ($str === 't') return ['telegram'];
        if ($str === 'et') return ['email', 'telegram'];
        throw new \Exception('invalid notif pref');
    }

    public function run() {
        $message = null;

        if (isset($_POST['telegram']) && $_POST['telegram'] === 'link') {
            return $this->linkTelegram();
        } else if (isset($_POST['telegram']) && $_POST['telegram'] === 'unlink') {
            return $this->unlinkTelegram();
        } else if (isset($_POST['action']) && gettype($_POST['action']) === 'string') {
            if ($_POST['action'] === 'sub') {
                $newsletter = (int) $_POST['newsletter'];
                if (isset($_POST['confirmed'])) {
                    $message = $this->subscribeNewsletter($newsletter);
                } else {
                    return array(
                        'page' => 'confirm-sub',
                        'path' => $this->plugin->getGrav()['uri']->path(),
                        'newsletter' => $this->getNewsletter($newsletter),
                    );
                }
            } else if ($_POST['action'] === 'unsub') {
                $newsletter = (int) $_POST['newsletter'];
                if (isset($_POST['reason'])) {
                    $reason = (int) $_POST['reason'];
                    $message = $this->unsubscribeNewsletter($newsletter, $reason);
                } else {
                    return array(
                        'page' => 'confirm-unsub',
                        'path' => $this->plugin->getGrav()['uri']->path(),
                        'newsletter' => $this->getNewsletter($newsletter),
                    );
                }
            } else if ($_POST['action'] === 'set_notif_builtin') {
                $message = $this->setGlobalNotifPrefs($this->strToNotifPref($_POST['notif_global']));
                if ($message['success']) {
                    $builtins = [];
                    foreach ($_POST['notif_builtin'] as $key => $value) {
                        $builtins[$key] = $this->strToNotifPref($value);
                    }
                    $message = $this->setBuiltinNotifPrefs($builtins);
                }
            }
        }

        return array(
            'page' => 'notifications',
            'path' => $this->plugin->getGrav()['uri']->path(),
            'account_path' => $this->plugin->accountPath,
            'telegram_linked' => $this->isTelegramLinked(),
            'newsletters' => $this->getNewsletters(),
            'global_prefs' => $this->getGlobalNotifPrefs(),
            'builtin_prefs' => $this->getBuiltinNotifPrefs(),
            'message' => $message,
        );
    }
}

