<?php

namespace Grav\Plugin\AksoBridge;

use Grav\Common\Grav;
use Grav\Common\Uri;

class UserLogin {
    private $plugin;
    public $bridge;
    public $aksoUser = null;
    public $loginConnFailed = false;
    public $isOpen = false;

    public function __construct($plugin) {
        $this->plugin = $plugin;
        $this->bridge = new \AksoBridge(realpath(__DIR__ . '/../aksobridged/run/aksobridge'));
    }

    public function tryLogin() {
        $ip = Uri::ip();
        if ($ip === 'UNKNOWN') {
            // I don't know why this happens, so if it does just fall back to the $_SERVER value
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        $cookies = $_COOKIE;

        // php renames cookies with a . in the name, so we need to fix that before passing cookies to the api
        if (isset($cookies['akso_session_sig'])) {
            $cookies['akso_session.sig'] = $cookies['akso_session_sig'];
            unset($cookies['akso_session_sig']);
        }

        $isLogin = Grav::instance()['uri']->path() === $this->plugin->loginPath && $_SERVER['REQUEST_METHOD'] === 'POST';
        if (isset($cookies['akso_session']) || $isLogin) {
            // run akso user bridge if this is the login page or if there's a session cookie

            try {
                $aksoUserState = $this->bridge->open($this->plugin->apiHost, $ip, $cookies);
                $this->isOpen = true;
            } catch (\Exception $e) {
                // connection failed probably
                $this->loginConnFailed = true;
                return;
            }
            if ($aksoUserState['auth']) {
                $this->aksoUser = $aksoUserState;
            }
        }
    }

    public function flush() {
        if ($this->isOpen) {
            $this->bridge->flushCookies();
            foreach ($this->bridge->setCookies as $cookie) {
                header('Set-Cookie: ' . $cookie, FALSE);
            }
        }
    }

    public function runLoginPage() {
        if ($this->loginConnFailed) {
            return array(
                'akso_login_error' => $this->plugin->locale['login']['generic_error'],
            );
        }

        $state = [];
        $state['akso_login_path'] = $this->plugin->loginPath;

        $createPasswordPathComponent = $this->plugin->locale['login']['create_password_path'];
        $resettingPassword = false;
        if (!isset($_GET[$createPasswordPathComponent])) {
            $createPasswordPathComponent = $this->plugin->locale['login']['reset_password_path'];
            $resettingPassword = true;
        }
        $createPasswordData = isset($_GET[$createPasswordPathComponent]) && gettype($_GET[$createPasswordPathComponent]) === 'string'
            ? $_GET[$createPasswordPathComponent]
            : null;
        $createPasswordData = explode('/', $createPasswordData);
        if (count($createPasswordData) == 2) {
            $state['akso_login_create_password_data'] = array(
                'login' => $createPasswordData[0],
                'token' => $createPasswordData[1],
            );
            $state['akso_login_creating_password'] = $createPasswordData != null;
            $state['akso_login_resetting_password'] = $resettingPassword;
        }

        $resetPasswordPathComponent = $this->plugin->locale['login']['forgot_password_path'];
        $isResettingPassword = isset($_GET[$resetPasswordPathComponent]);
        $state['akso_login_is_pw_reset'] = $isResettingPassword;

        $forgotLoginPathComponent = $this->plugin->locale['login']['forgot_login_path'];
        $didForgetLogin = isset($_GET[$forgotLoginPathComponent]);
        $state['akso_login_forgot_login'] = $didForgetLogin;

        $lostCodePathComponent = $this->plugin->locale['login']['lost_code_path'];
        $lostCode = isset($_GET[$lostCodePathComponent]);
        $state['akso_login_lost_code'] = $lostCode;

        $post = !empty($_POST) ? $_POST : [];

        // set return path
        if (isset($post['return'])) {
            // keep return path if it already exists
            $returnPath = $post['return'];
        } else if (isset($_GET['r']) && gettype($_GET['r']) === 'string' && str_starts_with($_GET['r'], '/')) {
            $returnPath = $_GET['r'];
        } else {
            $returnPath = $this->plugin->getReferrerPath();
        }
        $state['akso_login_return_path'] = $returnPath;

        $state['akso_login_forgot_password_path'] = $this->plugin->loginPath . '?' . $resetPasswordPathComponent;
        $state['akso_login_forgot_login_path'] = $this->plugin->loginPath . '?' . $forgotLoginPathComponent;
        $state['akso_login_lost_code_path'] = $this->plugin->loginPath . '?' . $lostCodePathComponent;

        if ($this->aksoUser && $this->aksoUser['totp']) {
            // user still needs to log in with totp
            $state['akso_login_totp'] = true;

            if ($this->aksoUser['needs_totp']) {
                $state['akso_login_totp_setup'] = true;
                $state['akso_totp_setup'] = $this->getTotpSetup();
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // user login
            $res = $this->handleAuthRequest($post);
            foreach ($res as $k => $v) $state[$k] = $v;
        }

        return $state;
    }

    private function handleAuthRequest($post): array {
        $canonUsername = '';
        if (isset($post['username'])) {
            $canonUsername = mb_strtolower($post['username']);
            if (preg_match('/^\w{4}-\w$/', $canonUsername)) {
                $canonUsername = substr($canonUsername, 0, 4);
            }
        }

        if (isset($post['reset_password'])) {
            // we're resetting the password actually

            $app = new AppBridge();
            $app->open();
            $res = $app->bridge->get("/codeholders", array(
                'filter' => array(
                    '$or' => [
                        array('newCode' => $canonUsername),
                        array('oldCode' => $canonUsername),
                        array('email' => $canonUsername),
                    ],
                ),
                'fields' => ['hasPassword'],
                'limit' => 2,
            ));
            if (!$res['k']) {
                Grav::instance()['log']->error('Failed to fetch codeholders for reset password: ' . $res['b']);
                return array(
                    'akso_login_error' => $this->plugin->locale['login']['generic_error'],
                );
            }
            if (count($res['b']) === 1) {
                $hasPassword = $res['b'][0]['hasPassword'];
                $org = Grav::instance()['config']->get('plugins.akso-bridge.account_org') ?? 'akso';
                $res = $this->bridge->forgotPassword($canonUsername, $org, !$hasPassword);
            }

            if (!$res['k']) {
                return array(
                    'akso_login_error' => $this->plugin->locale['login']['generic_error'],
                );
            } else {
                return array(
                    'akso_login_pw_reset_success' => true,
                );
            }
        } else if (isset($post['create_password_token'])) {
            $password = $post['password'];
            $password2 = $post['password2'];
            if ($password !== $password2) {
                return array(
                    'akso_login_error' => $this->plugin->locale['login']['error_create_mismatch'],
                );
            }
            $canonUsername = $post['create_password_username'];
            $token = $post['create_password_token'];
            $res = $this->bridge->createPassword($canonUsername, $password, $token);
            if (!$res['k']) {
                return array(
                    'akso_login_error' => $this->plugin->locale['login']['generic_error'],
                );
            }
            // fall through to normal login on success
        }

        $rpath = '/';
        if (isset($post['return'])) {
            // if return is a valid-ish path, set it as the return path
            if (strpos($post['return'], '/') == 0) $rpath = $post['return'];
        }

        if (isset($post['termsofservice']) || (isset($post['email']) && $post['email'] !== '')) {
            // these inputs were invisible and shouldn't've been triggered
            // so this was probably a spam bot
            return array(
                'akso_login_error' => $this->plugin->locale['login']['generic_error'],
                'akso_login_username' => 'roboto',
            );
        }

        if ($this->aksoUser !== null && $this->aksoUser['totp'] && isset($post['totp'])) {
            $remember = isset($post['remember']);

            $result = null;
            if ($this->aksoUser['needs_totp'] && isset($post['totpSetup'])) {
                $setupData = self::readTotpSetup($post['totpSetup']);
                if ($setupData) {
                    $result = $this->bridge->totpSetup($post['totp'], $setupData['secret'], $remember);
                } else {
                    $result = array('s' => false, 'bad' => true, 'nosx' => false);
                }
                $this->aksoUser['totp_setup_data'] = $setupData;
            } else {
                $result = $this->bridge->totp($post['totp'], $remember);
            }

            if ($result['s']) {
                $this->flush();
                Grav::instance()->redirectLangSafe($rpath, 303);
            } else if ($result['nosx'] || $result['bad']) {
                // no session / bad state
                return array(
                    'akso_login_error' => $this->plugin->locale['login']['generic_error'],
                );
            } else if (isset($post['totpSetup'])) {
                $this->pageState = array(
                    'akso_login_error' => $this->plugin->locale['login']['error_invalid_totp_setup'],
                );
            } else {
                $this->pageState = array(
                    'akso_login_error' => $this->plugin->locale['login']['error_invalid_totp'],
                );
            }
        } else {
            if (!isset($post['username']) || !isset($post['password'])) {
                http_response_code(400);
                die();
            }

            $result = $this->bridge->login($canonUsername, $post['password']);

            if ($result['s']) {
                $_SESSION['akso_is_probably_logged_in'] = true;
                $this->aksoUser = $result;
                $this->flush();

                if (!$result['totp']) {
                    // redirect to return page unless user still needs to use totp
                    Grav::instance()->redirectLangSafe($rpath, 303);
                } else {
                    return array(
                        'akso_login_totp' => true,
                    );
                }
            } else if ($result['nopw']) {
                return array(
                    'akso_login_error' =>
                        $this->plugin->locale['login']['error_no_password_0']
                        . $post['username']
                        . $this->plugin->locale['login']['error_no_password_1'],
                    'akso_login_username' => $post['username'],
                );
            } else if (strpos($post['username'], '@') !== false) {
                return array(
                    'akso_login_error' => $this->plugin->locale['login']['error_invalid_email'],
                    'akso_login_username' => $post['username'],
                );
            } else {
                return array(
                    'akso_login_error' => $this->plugin->locale['login']['error_invalid_uea'],
                    'akso_login_username' => $post['username'],
                );
            }
        }

        return [];
    }

    public static function readTotpSetup($data) {
        try {
            $setupData = json_decode(base64_decode($data), true);
            $setupData['secret'] = base64_decode($setupData['secret']);
            return $setupData;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getTotpSetup() {
        $secrets = $this->plugin->aksoUser['totp_setup_data'] ?? $this->bridge->generateTotp($this->plugin->aksoUser['uea']);
        $secrets['secret'] = base64_encode($secrets['secret']);

        return array(
            'secrets' => $secrets,
            'secrets_enc' => base64_encode(json_encode($secrets)),
        );
    }
}