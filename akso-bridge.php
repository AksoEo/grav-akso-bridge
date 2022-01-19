<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Uri;
use RocketTheme\Toolbox\Event\Event;
use Grav\Common\Page\Page;
use Grav\Common\Helpers\Excerpts;
use Grav\Plugin\AksoBridge\MarkdownExt;
use Grav\Plugin\AksoBridge\AppBridge;
use Grav\Plugin\AksoBridge\CongressInstance;
use Grav\Plugin\AksoBridge\CongressLocations;
use Grav\Plugin\AksoBridge\CongressPrograms;
use Grav\Plugin\AksoBridge\CongressRegistration;
use Grav\Plugin\AksoBridge\CountryLists;
use Grav\Plugin\AksoBridge\Delegates;
use Grav\Plugin\AksoBridge\DelegationApplications;
use Grav\Plugin\AksoBridge\Magazines;
use Grav\Plugin\AksoBridge\Registration;
use Grav\Plugin\AksoBridge\UserAccount;

// FIXME: this needs to be required manually for some reason
require_once('classes/AKSOTNTSearch.php');

// TODO: pass host to bridge as Host header

/**
 * Class AksoBridgePlugin
 * @package Grav\Plugin
 */
class AksoBridgePlugin extends Plugin {
    public static function getSubscribedEvents() {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
            'onMarkdownInitialized' => ['onMarkdownInitialized', 0],
            'onOutputGenerated' => ['onOutputGenerated', 0],
            'onPageNotFound' => ['onPageNotFound', 0],
        ];
    }

    const RESOURCE_PATH = '/_';
    const CONGRESS_REGISTRATION_PATH = 'alighilo';
    const CODEHOLDER_PICTURE_PATH = self::RESOURCE_PATH . '/membro/bildo';
    const CONGRESS_LOC_THUMBNAIL_PATH = self::RESOURCE_PATH . '/kongresa_loko/bildo';
    const MAGAZINE_COVER_PATH = self::RESOURCE_PATH . '/revuo/bildo';
    const MAGAZINE_DOWNLOAD_PATH = self::RESOURCE_PATH . '/revuo/elshuto';

    // allow access to protected property
    public function getGrav() {
        return $this->grav;
    }

    public $locale;
    public $country_currencies;

    function pathStartsWithComponent($path, $component) {
        return str_starts_with($path, $component) && (strlen($path) === strlen($component) || substr($path, strlen($component), 1) === '/');
    }

    // called at the beginning at some point
    public function onPluginsInitialized() {
        require_once __DIR__ . '/vendor/autoload.php';
        require_once __DIR__ . '/aksobridged/php/vendor/autoload.php';
        require_once __DIR__ . '/aksobridged/php/src/AksoBridge.php';

        $this->locale = parse_ini_file(dirname(__FILE__) . '/locale.ini', true);
        $this->country_currencies = parse_ini_file(dirname(__FILE__) . '/country_currencies.ini', true);

        // get request uri
        $uri = $this->grav['uri'];
        $this->path = $uri->path();

        // get config variables
        $this->loginPath = $this->grav['config']->get('plugins.akso-bridge.login_path');
        $this->logoutPath = $this->grav['config']->get('plugins.akso-bridge.logout_path');
        $this->registrationPath = $this->grav['config']->get('plugins.akso-bridge.registration_path');
        $this->accountPath = $this->grav['config']->get('plugins.akso-bridge.account_path');
        $this->apiHost = $this->grav['config']->get('plugins.akso-bridge.api_host');

        // Don't proceed if we are in the admin plugin
        if ($this->isAdmin()) {
            $this->adminInterceptGkSendToSubscribers();

            $this->enable([
                'onGetPageBlueprints' => ['onGetPageBlueprints', 0],
                'onGetPageTemplates' => ['onGetPageTemplates', 0],
                'onPagesInitialized' => ['onAdminPageInitialized', 0],
            ]);
            return;
        }
        $this->enable([
            'onPagesInitialized' => ['onPagesInitialized', 0],
            'onPageInitialized' => ['onPageInitialized', 0],
        ]);
    }

    // add blueprints and page templates (admin page)
    public function onGetPageBlueprints(Event $event) {
        $types = $event->types;
        $types->scanBlueprints('plugin://' . $this->name . '/blueprints');
    }
    public function onGetPageTemplates(Event $event) {
        $types = $event->types;
        $types->scanTemplates('plugin://' . $this->name . '/public_templates');
    }

    public function onPageNotFound(Event $event) {
        $page = $this->grav['pages']->dispatch('/error', true);
        if ($page) {
            $this->grav['page']->routable(false); // override existing page if it already exists
            $event->page = $page;
            $event->stopPropagation();
        }
    }

    // akso bridge connection
    public $bridge = null;
    // if set, will contain info on the akso user
    // an array with keys 'id', 'uea'
    public $aksoUser = null;
    public $aksoUserFormattedName = null;

    // will redirect after closing the bridge and setting cookies if not null
    private $redirectStatus = null;
    private $redirectTarget = null;

    // page state for twig variables; see impl for details
    private $pageState = null;
    // TODO: merge pageState with pageVars
    private $pageVars = [];

    public function onPagesInitialized(Event $event) {
        $this->runUserBridge();

        if ($this->path === $this->loginPath) {
            $this->addLoginPage();
        } else if ($this->aksoUser && $this->pathStartsWithComponent($this->path, $this->accountPath)) {
            $this->addAccountPage();
        } else if ($this->path === self::CONGRESS_LOC_THUMBNAIL_PATH) {
            $app = new AppBridge($this->grav);
            $app->open();
            $loc = new CongressLocations($this, $app, null, null);
            $loc->runThumbnail();
            $app->close();
        } else if ($this->path === self::CODEHOLDER_PICTURE_PATH) {
            $md = new MarkdownExt($this);
            $md->runListPicture();
        } else if ($this->path === self::MAGAZINE_COVER_PATH) {
            $app = new AppBridge($this->grav);
            $app->open();
            $mag = new Magazines($this, $app->bridge);
            $mag->runThumbnail();
            $app->close();
        } else if ($this->pathStartsWithComponent($this->path, self::MAGAZINE_DOWNLOAD_PATH)) {
            $this->grav->redirectLangSafe($this->loginPath, 302);
        }

        $this->addPages();
    }

    public function onPageInitialized(Event $event) {
        $post = !empty($_POST) ? $_POST : [];
        $templateId = $this->grav['page']->template();
        $state = [];
        if ($templateId === 'akso_congress_instance' || $templateId === 'akso_congress_registration') {
            $head = $this->grav['page']->header();
            $congressId = null;
            $instanceId = null;
            $paymentOrg = null;
            if (isset($head->congress_instance)) {
                $parts = explode("/", $head->congress_instance, 2);
                $congressId = intval($parts[0], 10);
                $instanceId = intval($parts[1], 10);
            }
            if (isset($head->payment_org)) {
                $paymentOrg = intval($head->payment_org, 10);
            }
            if ($congressId == null || $instanceId == null) {
                $state['akso_congress_error'] = 'Kongresa okazigo ne ekzistas';
            } else {
                $isRegistration = $templateId === 'akso_congress_registration';
                $app = new AppBridge($this->grav);
                $app->open();
                $instance = new CongressInstance($this, $app, $congressId, $instanceId);
                $state = $instance->run($paymentOrg, $isRegistration);
                $app->close();
            }
        } else if ($templateId === 'akso_congress_locations') {
            $head = $this->grav['page']->header();
            $congressId = null;
            $instanceId = null;
            if (isset($head->congress_instance)) {
                $parts = explode("/", $head->congress_instance, 2);
                $congressId = intval($parts[0], 10);
                $instanceId = intval($parts[1], 10);
            }
            $programsPath = null;
            if (isset($head->congress_programs_path)) {
                $programsPath = $head->congress_programs_path;
            }

            if ($congressId == null || $instanceId == null) {
                $state['akso_congress_error'] = 'Kongresa okazigo ne ekzistas';
            } else {
                $this->grav['assets']->add('plugin://akso-bridge/js/dist/congress-loc.css');
                $this->grav['assets']->add('plugin://akso-bridge/js/dist/congress-loc.js');
                $app = new AppBridge($this->grav);
                $app->open();
                $locations = new CongressLocations($this, $app, $congressId, $instanceId);
                $locations->programsPath = $programsPath;
                $state['akso_congress'] = $locations->run();
                $app->close();
            }
        } else if ($templateId === 'akso_congress_programs') {
            $head = $this->grav['page']->header();
            $congressId = null;
            $instanceId = null;
            if (isset($head->congress_instance)) {
                $parts = explode("/", $head->congress_instance, 2);
                $congressId = intval($parts[0], 10);
                $instanceId = intval($parts[1], 10);
            }
            $locationsPath = null;
            if (isset($head->congress_locations_path)) {
                $locationsPath = $head->congress_locations_path;
            }

            if ($congressId == null || $instanceId == null) {
                $state['akso_congress_error'] = 'Kongresa okazigo ne ekzistas';
            } else {
                $this->grav['assets']->add('plugin://akso-bridge/js/dist/congress-prog.css');
                $this->grav['assets']->add('plugin://akso-bridge/js/dist/congress-prog.js');
                $app = new AppBridge($this->grav);
                $app->open();
                $programs = new CongressPrograms($this, $app, $congressId, $instanceId);
                $programs->locationsPath = $locationsPath;
                $state['akso_congress'] = $programs->run();
                $app->close();
            }
        } else if (str_starts_with($templateId, 'akso_account')) {
            $state['account'] = $this->pageState;
        } else if ($templateId === 'akso_registration') {
            $this->grav['assets']->add('plugin://akso-bridge/js/dist/registration.css');
            $this->grav['assets']->add('plugin://akso-bridge/js/dist/registration.js');
            $state['akso_login_path'] = $this->loginPath;
            $state['akso_account_path'] = $this->accountPath;
            $state['uea_hide_support_button'] = true;

            $app = new AppBridge($this->grav);
            $app->open();
            $registration = new Registration($this, $app);
            $state['akso_registration'] = $registration->run();
            $app->close();
        } else if (str_starts_with($templateId, 'akso_magazines')) {
            $this->grav['assets']->add('plugin://akso-bridge/js/dist/magazines.css');
            $this->grav['assets']->add('plugin://akso-bridge/js/dist/magazines.js');
            $app = new AppBridge($this->grav);
            $app->open();
            $magazines = new Magazines($this, $app->bridge);
            $state['akso_magazine_cover_path'] = self::MAGAZINE_COVER_PATH;
            $state['akso_magazines'] = $magazines->run();
            $app->close();
        } else if ($templateId === 'akso_country_org_list') {
            $this->grav['assets']->add('plugin://akso-bridge/js/dist/country-org-lists.css');
            $this->grav['assets']->add('plugin://akso-bridge/js/dist/country-org-lists.js');
            $app = new AppBridge($this->grav);
            $app->open();
            $countryLists = new CountryLists($this, $app->bridge);
            $state['akso_login_path'] = $this->loginPath;
            $state['akso_registration_path'] = $this->registrationPath;
            $state['akso_clist'] = $countryLists->run();
            $app->close();
        } else if ($templateId === 'akso_delegates') {
            $this->grav['assets']->add('plugin://akso-bridge/js/dist/delegates.css');
            $this->grav['assets']->add('plugin://akso-bridge/js/dist/delegates.js');
            $app = new AppBridge($this->grav);
            $app->open();
            $delegates = new Delegates($this, $app->bridge);
            $state['akso_delegates'] = $delegates->run();
            $app->close();
        } else if ($templateId === 'akso_delegation_application') {
            $this->grav['assets']->add('plugin://akso-bridge/js/dist/delegation-applications.css');
            $this->grav['assets']->add('plugin://akso-bridge/js/dist/delegation-applications.js');
            $app = new AppBridge($this->grav);
            $app->open();
            $appl = new DelegationApplications($this, $app->bridge);
            $state['akso_delegates'] = $appl->run();
            $app->close();
        }

        if ($this->grav['uri']->path() === $this->loginPath) {
            // add login css
            $this->grav['assets']->add('plugin://akso-bridge/css/login.css');

            $state['akso_login_path'] = $this->loginPath;

            $resetPasswordPathComponent = $this->locale['login']['forgot_password_path'];
            $isResettingPassword = isset($_GET[$resetPasswordPathComponent]);
            $state['akso_login_is_pw_reset'] = $isResettingPassword;

            $forgotLoginPathComponent = $this->locale['login']['forgot_login_path'];
            $didForgetLogin = isset($_GET[$forgotLoginPathComponent]);
            $state['akso_login_forgot_login'] = $didForgetLogin;

            $lostCodePathComponent = $this->locale['login']['lost_code_path'];
            $lostCode = isset($_GET[$lostCodePathComponent]);
            $state['akso_login_lost_code'] = $lostCode;

            // set return path
            $rpath = '/';
            if (isset($post['return'])) {
                // keep return path if it already exists
                $rpath = $post['return'];
            } else {
                $rpath = $this->getReferrerPath();
            }
            $state['akso_login_return_path'] = $rpath;

            $state['akso_login_forgot_password_path'] = $this->loginPath . '?' . $resetPasswordPathComponent;
            $state['akso_login_forgot_login_path'] = $this->loginPath . '?' . $forgotLoginPathComponent;
            $state['akso_login_lost_code_path'] = $this->loginPath . '?' . $lostCodePathComponent;
        }

        $state['akso_auth'] = $this->aksoUser !== null;
        $state['akso_full_auth'] = $this->aksoUser ? !$this->aksoUser['totp'] : false;
        $state['akso_user_is_member'] = $this->aksoUser ? $this->aksoUser['member'] : false;
        if ($this->aksoUser !== null) {
            $state['akso_user_fmt_name'] = $this->aksoUserFormattedName;
            $state['akso_uea_code'] = $this->aksoUser['uea'];

            if ($this->aksoUser['totp']) {
                // user still needs to log in with totp
                $state['akso_login_totp'] = true;

                if ($this->aksoUser['needs_totp']) {
                    $state['akso_login_totp_setup'] = true;
                    if (isset($this->aksoUser['totp_setup_data'])) {
                        $state['akso_login_totp_secrets'] = $this->aksoUser['totp_setup_data'];
                    } else {
                        $state['akso_login_totp_secrets'] = $this->bridge->generateTotp($this->aksoUser['uea']);
                    }
                    $secrets = $state['akso_login_totp_secrets'];
                    $secrets['secret'] = base64_encode($secrets['secret']);
                    $state['akso_login_totp_secrets_enc'] = base64_encode(json_encode($secrets));
                }
            }
        }

        if (isset($this->pageState['login_state'])) {
            $istate = $this->pageState;
            if ($istate['login_state'] === 'login-error') {
                $state['akso_login_username'] = $istate['username'];
                if (isset($istate['isBad'])) {
                    $state['akso_login_error'] = 'loginbad';
                } else if ($istate['noPassword']) {
                    $state['akso_login_error'] = 'nopw';
                } else if ($istate['isEmail']) {
                    $state['akso_login_error'] = 'authemail';
                } else {
                    $state['akso_login_error'] = 'authuea';
                }
            } else if ($istate['login_state'] === 'totp-error') {
                if ($istate['nosx']) {
                    $state['akso_login_error'] = 'totpnosx';
                } else if ($istate['bad']) {
                    $state['akso_login_error'] = 'totpbad';
                } else {
                    $state['akso_login_error'] = 'totpauth';
                }
            } else if ($istate['login_state'] === 'reset-error') {
                $state['akso_login_error'] = 'reset-error';
            } else if ($istate['login_state'] === 'reset-success') {
                $state['akso_login_pw_reset_success'] = true;
            }
        }

        $this->pageVars = $state;
    }

    private function runUserBridge() {
        $this->bridge = new \AksoBridge(__DIR__ . '/aksobridged/run/aksobridge');

        // basic default state so stuff doesn’t error
        $this->pageState = array('state' => '');

        $ip = Uri::ip();
        if ($ip === 'UNKNOWN') {
            // i don't know why this happens
            // so if it does just fall back to the $_SERVER value
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        $cookies = $_COOKIE;

        // php renames cookies with a . in the name
        // so we need to fix that before passing cookies to the api
        if (isset($cookies['akso_session_sig'])) {
            $cookies['akso_session.sig'] = $cookies['akso_session_sig'];
            unset($cookies['akso_session_sig']);
        }

        $isLogin = $this->path === $this->loginPath && $_SERVER['REQUEST_METHOD'] === 'POST';
        if (isset($cookies['akso_session']) || $isLogin) {
            // run akso user bridge if this is the login page or if there's a session cookie

            $aksoUserState = $this->bridge->open($this->apiHost, $ip, $cookies);
            if ($aksoUserState['auth']) {
                $this->aksoUser = $aksoUserState;
            }

            $this->updateAksoState();
            $this->updateFormattedName();

            // FIXME: better state management
            // $this->bridge->close();
            $this->bridge->flushCookies();
        }

        foreach ($this->bridge->setCookies as $cookie) {
            header('Set-Cookie: ' . $cookie, FALSE);
        }

        if ($this->redirectTarget !== null) {
            $this->grav->redirectLangSafe($this->redirectTarget, $this->redirectStatus);
        }
    }

    // updates AKSO state, handling the current page/action etc
    private function updateAksoState() {
        if ($this->aksoUser !== null && $this->aksoUser['totp']) {
            // user is logged in and needs to still use totp
            if ($this->path !== $this->loginPath && $this->path !== $this->logoutPath) {
                // redirect to login path if the user isn’t already there
                $this->redirectTarget = $this->loginPath;
                $this->redirectStatus = 303;
                return;
            }
        }

        if ($this->path === $this->loginPath && $_SERVER['REQUEST_METHOD'] === 'POST') {
            // user login
            $post = !empty($_POST) ? $_POST : [];
            // TODO: use a form nonce

            $canonUsername = '';
            if (isset($post['username'])) {
                $canonUsername = $post['username'];
                if (preg_match('/^\w{4}-\w$/', $canonUsername)) {
                    $canonUsername = substr($canonUsername, 0, 4);
                }
            }

            if (isset($post['reset_password'])) {
                // we're resetting the password actually

                $res = $this->bridge->forgotPassword($canonUsername);
                if (!$res['k']) {
                    $this->pageState = array(
                        'login_state' => 'reset-error',
                    );
                } else {
                    $this->pageState = array(
                        'login_state' => 'reset-success',
                    );
                }
                return;
            }

            $rpath = '/';
            if (isset($post['return'])) {
                // if return is a valid-ish path, set it as the return path
                if (strpos($post['return'], '/') == 0) $rpath = $post['return'];
            }

            if (isset($post['termsofservice']) || (isset($post['email']) && $post['email'] !== '')) {
                // these inputs were invisible and shouldn't've been triggered
                // so this was probably a spam bot
                $this->pageState = array(
                    'login_state' => 'login-error',
                    'isBad' => true,
                    'isEmail' => false,
                    'username' => 'roboto',
                    'noPassword' => false,
                );
                return;
            }

            if ($this->aksoUser !== null && $this->aksoUser['totp'] && isset($post['totp'])) {
                $remember = isset($post['remember']);

                $result = null;
                if ($this->aksoUser['needs_totp'] && isset($post['totpSetup'])) {
                    $setupData = null;
                    try {
                        $setupData = json_decode(base64_decode($post['totpSetup']), true);
                        $setupData['secret'] = base64_decode($setupData['secret']);
                    } catch (\Exception $e) {
                        $result = array('s' => false, 'bad' => true, 'nosx' => false);
                    }
                    $result = $this->bridge->totpSetup($post['totp'], $setupData['secret'], $remember);
                    $this->aksoUser['totp_setup_data'] = $setupData;
                } else {
                    $result = $this->bridge->totp($post['totp'], $remember);
                }

                if ($result['s']) {
                    $this->redirectTarget = $rpath;
                    $this->redirectStatus = 303;
                } else {
                    $this->pageState = array(
                        'login_state' => 'totp-error',
                        'bad' => $result['bad'],
                        'nosx' => $result['nosx'],
                    );
                }
            } else {
                if (!isset($post['username']) || !isset($post['password'])) {
                    die(401);
                }

                $result = $this->bridge->login($canonUsername, $post['password']);

                if ($result['s']) {
                    $this->aksoUser = $result;

                    if (!$result['totp']) {
                        // redirect to return page unless user still needs to use totp
                        $this->redirectTarget = $rpath;
                        $this->redirectStatus = 303;
                    }
                } else {
                    $this->pageState = array(
                        'login_state' => 'login-error',
                        'isEmail' => strpos($post['username'], '@') !== false,
                        'username' => $post['username'],
                        'noPassword' => $result['nopw']
                    );
                }
            }
        } else if ($this->path === $this->logoutPath) {
            $result = $this->bridge->logout();
            if ($result['s']) {
                $this->aksoUser = null;
                $this->redirectTarget = $this->getReferrerPath();
                $this->redirectStatus = 303;
            }
        } else if ($this->pathStartsWithComponent($this->path, $this->accountPath)) {
            $this->grav['assets']->add('plugin://akso-bridge/js/dist/account.js');
            $this->grav['assets']->add('plugin://akso-bridge/js/dist/account.css');
            $app = new AppBridge($this->grav);
            $app->open();
            $acc = new UserAccount($this, $app, $this->bridge, $this->path);
            $this->pageState = $acc->run();
            $app->close();
        } else if ($this->pathStartsWithComponent($this->path, self::MAGAZINE_DOWNLOAD_PATH)) {
            $magazines = new Magazines($this, $this->bridge);
            $magazines->runDownload();
        }
    }

    public function updateFormattedName() {
        if ($this->aksoUser && !$this->aksoUserFormattedName) {
            $res = $this->bridge->get('codeholders/self', array(
                'fields' => [
                    'firstName',
                    'lastName',
                    'firstNameLegal',
                    'lastNameLegal',
                    'honorific',
                    'fullName',
                    'fullNameLocal',
                    'nameAbbrev'
                ]
            ));
            if ($res['k']) {
                $data = $res['b'];
                $isOrg = isset($data['fullName']);
                if ($isOrg) {
                    $this->aksoUserFormattedName = $data['fullName'];
                    if (isset($data['nameAbbrev']) && strlen($data['fullName']) > 16) {
                        $this->aksoUserFormattedName = $data['nameAbbrev'];
                    }
                } else {
                    $this->aksoUserFormattedName = '';
                    if (isset($data['honorific'])) {
                        $this->aksoUserFormattedName .= $data['honorific'] . ' ';
                    }
                    if (isset($data['firstName'])) {
                        $this->aksoUserFormattedName .= $data['firstName'] . ' ';
                    } else {
                        $this->aksoUserFormattedName .= $data['firstNameLegal'] . ' ';
                    }
                    if (isset($data['lastName'])) {
                        $this->aksoUserFormattedName .= $data['lastName'];
                    } else if (isset($data['lastNameLegal'])) {
                        $this->aksoUserFormattedName .= $data['lastNameLegal'];
                    }
                }
            } else {
                $this->aksoUserFormattedName = $this->aksoUser['uea'];
            }
        }
    }

    // add various pages to grav
    // i’m not sure why these work the way they do
    public function addPages() {
        $pages = $this->grav['pages'];
        $currentPath = $this->grav['uri']->path();

        $userIsOrg = $this->aksoUser && str_starts_with($this->aksoUser['uea'], 'xx');

        foreach ($pages->all() as $page) {
            if ($userIsOrg && $page->template() === 'akso_registration') {
                // orgs can't sign up!
                $page->visible(false);
            }

            if ($page->template() === 'akso_congress_instance') {
                // you can't add more than 1 page with the same SplFileInfo, otherwise *weird*
                // *things* will happen.
                // so we'll only add the registration page if we're currently *on* that page
                if (!isset($page->header()->congress_instance) || !isset($page->header()->payment_org)) {
                    // no page params; skip
                    continue;
                }
                $regPath = $page->route() . '/' . self::CONGRESS_REGISTRATION_PATH;
                if (!str_starts_with($currentPath, $regPath)) continue;
                $page->activeChild = true;

                $regPage = new Page();
                $regPage->init(new \SplFileInfo(__DIR__ . '/pages/akso_congress_registration.md'));
                $regPage->slug(basename($regPath));
                $regPageHeader = $regPage->header();
                // copy congress instance id from the congress page into the sign-up page
                $regPageHeader->congress_instance = $page->header()->congress_instance;
                $regPageHeader->payment_org = $page->header()->payment_org;
                $pages->addPage($regPage, $regPath);
                break;
            }
            if ($page->template() === 'akso_magazines') {
                $generatedPagePrefix = $page->route() . '/' . Magazines::MAGAZINE . '/';
                $page->header()->path_base = $page->route();
                $page->header()->path_subroute = '';
                if (!str_starts_with($currentPath, $generatedPagePrefix)) continue;
                $page->activeChild = true;

                $generatedPage = new Page();
                $generatedPage->init(new \SplFileInfo(__DIR__ . '/pages/akso_magazines/akso_magazines_magazine.md'));
                $generatedPage->slug(basename($currentPath));
                $generatedPage->header()->path_base = $page->route();
                $generatedPage->header()->path_subroute = substr($currentPath, strlen($generatedPagePrefix));
                $pages->addPage($generatedPage, $currentPath);
            }
        }
    }

    public function addLoginPage() {
        $this->addVirtualPage($this->loginPath, '/pages/akso_login.md');
    }
    public function addAccountPage() {
        $loginsPath = $this->grav['config']->get('plugins.akso-bridge.account_logins_path');
        $votesPath = $this->grav['config']->get('plugins.akso-bridge.account_votes_path');
        $this->addVirtualPage($this->accountPath . $loginsPath, '/pages/akso_account/logins/akso_account_logins.md');
        $this->addVirtualPage($this->accountPath . $votesPath, '/pages/akso_account/votes/akso_account_votes.md');
        $this->addVirtualPage($this->accountPath, '/pages/akso_account/akso_account.md');
    }

    function addVirtualPage($path, $template) {
        $pages = $this->grav['pages'];
        $page = $pages->dispatch($path);

        if (!$page) {
            $page = new Page();
            $page->init(new \SplFileInfo(__DIR__ . $template));
            $page->slug(basename($path));
            $pages->addPage($page, $path);
        }
    }

    // adds twig templates because grav
    public function onTwigTemplatePaths() {
        $twig = $this->grav['twig'];
        $twig->twig_paths[] = __DIR__ . '/templates';
        $twig->twig_paths[] = __DIR__ . '/public_templates';
    }

    private function getReferrerPath() {
        if (!isset($_SERVER['HTTP_REFERER'])) return '/';
        $ref = $_SERVER['HTTP_REFERER'];
        $refp = parse_url($ref);
        $rpath = '/';
        if ($refp != false && isset($refp['path'])) {
            $rpath = $refp['path'];
            if (isset($refp['query'])) $rpath .= '?' . $refp['query'];
            if (isset($refp['anchor'])) $rpath .= '#' + $refp['anchor'];
        }
        return $rpath;
    }

    // sets twig variables for rendering
    public function onTwigSiteVariables() {
        if ($this->isAdmin()) {
            // add admin js
            $this->grav['assets']->add('plugin://akso-bridge/locale-admin.js');
            $this->grav['assets']->add('plugin://akso-bridge/css/akso-bridge-admin.css');
            $this->grav['assets']->add('plugin://akso-bridge/js/akso-bridge-admin.js');
            return;
        }

        if ($this->bridge === null) {
            // there is no bridge on this page; skip
            return;
        }

        $twig = $this->grav['twig'];

        $twig->twig_vars['akso_locale'] = $this->locale;
        foreach($this->pageVars as $key => $value) {
            $twig->twig_vars[$key] = $value;
        }
    }

    /**
     * Admin page endpoint at /admin/akso_bridge, for various JS stuff.
     */
    public function onAdminPageInitialized() {
        $auth = $this->grav["user"]->authorize('admin.login');
        $uri = $this->grav["uri"];
        $path = $uri->path();
        if ($auth && $path === "/admin/akso_bridge") {
            header('Content-Type: application/json;charset=utf-8');
            $task = $uri->query('task');
            $app = new AppBridge($this->grav);
            $app->open();

            if ($task === "list_congresses") {
                $offset = $uri->query('offset');
                $limit = $uri->query('limit');

                $res = $app->bridge->get('/congresses', array(
                    'offset' => $offset,
                    'limit' => $limit,
                    'fields' => ['id', 'name', 'org'],
                    'order' => ['name.asc'],
                ));
                if ($res['k']) {
                    echo json_encode(array('result' => $res['b']));
                } else {
                    echo json_encode(array('error' => $res['b']));
                }
            } else if ($task === "list_congress_instances") {
                $congress = $uri->query('congress');
                $offset = $uri->query('offset');
                $limit = $uri->query('limit');

                $res = $app->bridge->get('/congresses/' . $congress . '/instances', array(
                    'offset' => $offset,
                    'limit' => $limit,
                    'fields' => ['id', 'name', 'humanId'],
                    'order' => ['humanId.desc'],
                ));
                if ($res['k']) {
                    echo json_encode(array('result' => $res['b']));
                } else {
                    echo json_encode(array('error' => $res['b']));
                }
            } else if ($task === "name_congress_instance") {
                $congress = $uri->query('congress');
                $instance = $uri->query('instance');
                $offset = $uri->query('offset');
                $limit = $uri->query('limit');

                $res = $app->bridge->get('/congresses/' . $congress, array('fields' => ['name']));
                if (!$res['k']) {
                    echo json_encode(array('error' => $res['b']));
                } else {
                    $congressName = $res['b']['name'];
                    $res = $app->bridge->get('/congresses/' . $congress . '/instances/' . $instance, array('fields' => ['name']));
                    if (!$res['k']) {
                        echo json_encode(array('error' => $res['b']));
                    } else {
                        $instanceName = $res['b']['name'];
                        echo json_encode(array('result' => array(
                            'congress' => $congressName,
                            'instance' => $instanceName,
                        )));
                    }
                }
            }

            $app->close();
            die();
        }
    }

    // loads MarkdownExt (see classes/MarkdownExt.php)
    private function loadMarkdownExt() {
        if (!isset($this->markdownExt)) {
            $this->markdownExt = new MarkdownExt($this);
        }
        return $this->markdownExt;
    }

    public function onMarkdownInitialized(Event $event) {
        if ($this->isAdmin()) return;
        $markdownExt = $this->loadMarkdownExt();
        $markdownExt->onMarkdownInitialized($event);
    }
    public function onOutputGenerated(Event $event) {
        if ($this->isAdmin()) {
            // stop showing the “Grav Premium” ad
            header('Set-Cookie: gp-premium=1', FALSE);
            return;
        }
        $markdownExt = $this->loadMarkdownExt();
        $nonces = $markdownExt->onOutputGenerated($event);

        $scriptNonces = '';
        foreach ($nonces['scripts'] as $sn) {
            $scriptNonces .= " 'nonce-" . $sn . "'";
        }
        $styleNonces = '';
        foreach ($nonces['styles'] as $sn) {
            $styleNonces .= " 'nonce-" . $sn . "'";
        }
        $extraImgSrc = $this->grav['config']->get('plugins.akso-bridge.csp_img');
        $extraChildSrc = $this->grav['config']->get('plugins.akso-bridge.csp_child');
        if ($extraImgSrc) $extraImgSrc = implode(' ', $extraImgSrc);
        else $extraImgSrc = '';
        if ($extraChildSrc) $extraChildSrc = implode(' ', $extraChildSrc);
        else $extraChildSrc = '';

        $csp = [
            "default-src 'self'",
            "img-src 'self' data: " . $this->apiHost . " https://tile.openstreetmap.org " . $extraImgSrc,
            "script-src 'self' " . $scriptNonces,
            "style-src 'self' 'unsafe-inline' " . $styleNonces,
            "child-src 'self' " . $extraChildSrc,
        ];
        header('Content-Security-Policy: ' . implode(';', $csp), FALSE);
    }

    function adminInterceptGkSendToSubscribers() {
        if (isset($_POST['akso_gk_send_to_subs'])) {
            $title = $_POST['data']['header']['title'];
            $content = $_POST['data']['content'];
            $url = $_POST['data']['header']['canonical'];
            var_dump("this is the part where the newsletter is sent out");
            die();
        }
    }
}
