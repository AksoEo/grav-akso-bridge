<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Plugin\AksoBridge\AksoTwigExt;
use Grav\Plugin\AksoBridge\CodeholderLists;
use Grav\Plugin\AksoBridge\Payments;
use Grav\Plugin\AksoBridge\UserLogin;
use RocketTheme\Toolbox\Event\Event;
use Grav\Common\Page\Page;
use Grav\Plugin\AksoBridge\MarkdownExt;
use Grav\Plugin\AksoBridge\AppBridge;
use Grav\Plugin\AksoBridge\CongressInstance;
use Grav\Plugin\AksoBridge\CongressLocations;
use Grav\Plugin\AksoBridge\CongressPrograms;
use Grav\Plugin\AksoBridge\Delegates;
use Grav\Plugin\AksoBridge\DelegationApplications;
use Grav\Plugin\AksoBridge\GkSendToSubscribers;
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
            'onTwigInitialized' => ['onTwigInitialized', 0],
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
            'onMarkdownInitialized' => ['onMarkdownInitialized', 0],
            'onOutputGenerated' => ['onOutputGenerated', 0],
            'onPageNotFound' => ['onPageNotFound', 0],
        ];
    }

    const RESOURCE_PATH = '/_';
    const CONGRESS_REGISTRATION_PATH = 'alighilo';
    public const CODEHOLDER_PICTURE_PATH = self::RESOURCE_PATH . '/membro/bildo';
    public const CONGRESS_LOC_THUMBNAIL_PATH = self::RESOURCE_PATH . '/kongresa_loko/bildo';
    public const MAGAZINE_COVER_PATH = self::RESOURCE_PATH . '/revuo/bildo';
    public const MAGAZINE_DOWNLOAD_PATH = self::RESOURCE_PATH . '/revuo/elshuto';
    public const PAYMENT_METHOD_THUMBNAIL_PATH = self::RESOURCE_PATH . '/pagmetodo/bildo';

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

    public $userLogin = null;
    // akso bridge connection
    public $bridge = null;
    // if set, will contain info on the akso user
    // an array with keys 'id', 'uea'
    public $aksoUser = null;
    public $aksoUserFormattedName = null;

    // will redirect after closing the bridge and setting cookies if not null
    private $redirectStatus = null;
    private $redirectTarget = null;

    // will be passed to twig
    private $pageVars = [];

    public function onPagesInitialized(Event $event) {
        if (!AppBridge::getApiKey()) return;

        $this->userLogin = new UserLogin($this);
        $this->userLogin->tryLogin();
        $this->bridge = $this->userLogin->bridge;
        $this->aksoUser = $this->userLogin->aksoUser;
        $unsetAksoCookies = false;

        {
            if ($this->aksoUser !== null && $this->aksoUser['totp']) {
                // user is logged in and needs to still use totp
                if ($this->path !== $this->loginPath && $this->path !== $this->logoutPath) {
                    // redirect to login path if the user isn’t already there
                    $this->redirectTarget = $this->loginPath;
                    $this->redirectStatus = 303;
                }
            }
            $_SESSION['akso_is_probably_logged_in'] = !!$this->aksoUser;

            if ($this->path === $this->logoutPath) {
                $result = $this->bridge->logout();
                if ($result['s']) {
                    $_SESSION['akso_is_probably_logged_in'] = false;
                    $_SESSION['akso_unset_cookies'] = true;
                    $this->aksoUser = null;
                    $this->redirectTarget = $this->getReferrerPath();
                    $this->redirectStatus = 303;
                }
            } else if (!$this->aksoUser && (isset($_SESSION['akso_unset_cookies']) || isset($_COOKIE['akso_session']))) {
                $unsetAksoCookies = true;
            }
        }
        $this->updateFormattedName();
        $this->userLogin->flush();

        if ($unsetAksoCookies) {
            unset($_SESSION['akso_unset_cookies']);
            setcookie('akso_session', null, -1, '/');
            setcookie('akso_session.sig', null, -1, '/');
        }

        if ($this->redirectTarget !== null) {
            $this->grav->redirectLangSafe($this->redirectTarget, $this->redirectStatus);
        }

        if ($this->path === $this->loginPath) {
            $this->addLoginPage();
        } else if ($this->pathStartsWithComponent($this->path, $this->accountPath)) {
            if ($this->aksoUser) {
                $this->addAccountPage();
            } else {
                $this->grav->redirectLangSafe($this->loginPath . '?r=' . $this->path, 302);
            }
        } else if ($this->path === self::CONGRESS_LOC_THUMBNAIL_PATH) {
            $app = new AppBridge();
            $app->open();
            $loc = new CongressLocations($this, $app, null, null);
            $loc->runThumbnail();
            $app->close();
        } else if ($this->path === self::PAYMENT_METHOD_THUMBNAIL_PATH) {
            $app = new AppBridge();
            $app->open();
            $payments = new Payments($this, $app);
            $payments->runMethodThumbnail();
            $app->close();
        } else if ($this->path === self::CODEHOLDER_PICTURE_PATH) {
            $app = new AppBridge();
            $app->open();
            CodeholderLists::runListPicture($this, $app->bridge);
            $app->close();
        } else if ($this->path === self::MAGAZINE_COVER_PATH) {
            $app = new AppBridge();
            $app->open();
            $mag = new Magazines($this, $app->bridge);
            $mag->runThumbnail();
            $app->close();
        } else if ($this->pathStartsWithComponent($this->path, self::MAGAZINE_DOWNLOAD_PATH)) {
            $app = new AppBridge();
            $app->open();
            $magazines = new Magazines($this, $app->bridge);
            $magazines->runDownload();
            $app->close();
        }

        $this->addPages();
    }

    public function onPageInitialized(Event $event) {
        if (!AppBridge::getApiKey()) return;

        $this->grav['assets']->add('plugin://akso-bridge/js/dist/md-components.js');
        $this->grav['assets']->add('plugin://akso-bridge/js/dist/md-components.css');

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
                $app = new AppBridge();
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
                $app = new AppBridge();
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
                $app = new AppBridge();
                $app->open();
                $programs = new CongressPrograms($this, $app, $congressId, $instanceId);
                $programs->locationsPath = $locationsPath;
                $state['akso_congress'] = $programs->run();
                $app->close();
            }
        } else if ($this->pathStartsWithComponent($this->path, $this->accountPath) && str_starts_with($templateId, 'akso_account')) {
            $this->grav['assets']->add('plugin://akso-bridge/js/dist/account.js');
            $this->grav['assets']->add('plugin://akso-bridge/js/dist/account.css');
            $app = new AppBridge();
            $app->open();
            $acc = new UserAccount($this, $app, $this->bridge, $this->path);
            $state['account'] = $acc->run();
            $app->close();
        } else if ($templateId === 'akso_registration') {
            $this->grav['assets']->add('plugin://akso-bridge/js/dist/registration.css');
            $this->grav['assets']->add('plugin://akso-bridge/js/dist/registration.js');
            $state['akso_login_path'] = $this->loginPath;
            $state['akso_account_path'] = $this->accountPath;
            $state['akso_account_edit_path'] = $this->accountPath . '?redakti';
            $state['uea_hide_support_button'] = true;

            $head = $this->grav['page']->header();
            $isDonation = isset($head->is_donation) && $head->is_donation;

            $app = new AppBridge();
            $app->open();
            $registration = new Registration($this, $app, $isDonation);
            $state['akso_registration'] = $registration->run();
            $app->close();
        } else if (str_starts_with($templateId, 'akso_magazines')) {
            $this->grav['assets']->add('plugin://akso-bridge/js/dist/magazines.css');
            $this->grav['assets']->add('plugin://akso-bridge/js/dist/magazines.js');
            $app = new AppBridge();
            $app->open();
            $magazines = new Magazines($this, $app->bridge);
            $state['akso_magazine_cover_path'] = self::MAGAZINE_COVER_PATH;
            $state['akso_registration_path'] = $this->registrationPath;
            $state['akso_magazines'] = $magazines->run();

            if (isset($state['akso_magazines']['title'])) {
                $state['page_title_override'] = $state['akso_magazines']['title'];
            }
            $app->close();
        } else if ($templateId === 'akso_delegates') {
            $this->grav['assets']->add('plugin://akso-bridge/js/dist/delegates.css');
            $this->grav['assets']->add('plugin://akso-bridge/js/dist/delegates.js');
            $app = new AppBridge();
            $app->open();
            $delegates = new Delegates($this, $app->bridge);
            $state['akso_delegates'] = $delegates->run();
            $app->close();
        } else if ($templateId === 'akso_delegation_application') {
            $this->grav['assets']->add('plugin://akso-bridge/js/dist/delegation-applications.css');
            $this->grav['assets']->add('plugin://akso-bridge/js/dist/delegation-applications.js');
            $app = new AppBridge();
            $app->open();
            $appl = new DelegationApplications($this, $app->bridge);
            $state['akso_delegates'] = $appl->run();
            $app->close();
        } else if ($this->grav['uri']->path() === $this->loginPath) {
            // add login css
            $this->grav['assets']->add('plugin://akso-bridge/css/login.css');

            $state = $this->userLogin->runLoginPage();
            $this->aksoUser = $this->userLogin->aksoUser;
            $this->updateFormattedName();
        }

        $state['akso_auth_failed'] = $this->userLogin->loginConnFailed;
        $state['akso_auth'] = $this->aksoUser !== null;
        $state['akso_full_auth'] = $this->aksoUser && !$this->aksoUser['totp'];
        $state['akso_user_is_member'] = $this->aksoUser ? $this->aksoUser['member'] : false;

        if ($this->aksoUser !== null) {
            $state['akso_user_fmt_name'] = $this->aksoUserFormattedName;
            $state['akso_uea_code'] = $this->aksoUser['uea'];
        }

        $this->pageVars = $state;
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
                if (!isset($page->header()->congress_instance)) {
                    // no page params; skip
                    continue;
                }

                $routeWithSlash = $page->route();
                if (!str_ends_with($routeWithSlash, '/')) $routeWithSlash .= '/';

                $regPath = $routeWithSlash . self::CONGRESS_REGISTRATION_PATH;
                if (!str_starts_with($currentPath, $regPath)) continue;
                $page->activeChild = true;

                $regPage = new Page();
                $regPage->init(new \SplFileInfo(__DIR__ . '/pages/akso_congress_registration.md'));
                $regPage->slug(basename($regPath));
                $regPageHeader = $regPage->header();
                // copy congress instance id from the congress page into the sign-up page
                $regPageHeader->congress_instance = $page->header()->congress_instance;
                if (isset($page->header()->payment_org)) {
                    $regPageHeader->payment_org = $page->header()->payment_org;
                }
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
                if (isset($page->header()->magazines)) {
                    $generatedPage->header()->magazines = $page->header()->magazines;
                }
                if (isset($page->header()->magazine_access_messages)) {
                    $generatedPage->header()->magazine_access_messages = $page->header()->magazine_access_messages;
                }
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
        $notifsPath = $this->grav['config']->get('plugins.akso-bridge.account_notifs_path');
        $this->addVirtualPage($this->accountPath . $loginsPath, '/pages/akso_account/logins/akso_account_logins.md');
        $this->addVirtualPage($this->accountPath . $votesPath, '/pages/akso_account/votes/akso_account_votes.md');
        $this->addVirtualPage($this->accountPath . $notifsPath, '/pages/akso_account/notifs/akso_account_notifs.md');
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

    public function onTwigInitialized() {
        $this->grav['twig']->twig->addExtension(new AksoTwigExt());
    }

    public function getReferrerPath() {
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
            } else if ($task === "gk_page_route_template") {
                echo json_encode(array('result' => $this->grav['config']->get('plugins.akso-bridge.gk_page_route_template')));
            } else if ($task === "gk_notif_preview") {
                $title = $_POST['title'];
                $content = $_POST['content'];
                $url = $_POST['url'];
                $templateId = GkSendToSubscribers::createNotifTemplate($this, $app, $title, $content, $url);
                $rendered = $app->bridge->get("/notif_templates/$templateId/render", []);
                if (!$rendered['k']) {
                    $app->bridge->delete("/notif_templates/$templateId", []); // we dont really care about result here
                    http_response_code($rendered['sc']);
                    echo($rendered['b']);
                    die();
                }
                $app->bridge->delete("/notif_templates/$templateId", []); // we dont really care about result here
                echo json_encode($rendered['b']);
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
        $nonces = $markdownExt->onOutputGenerated();

        $scriptNonces = '';
        foreach ($nonces['scripts'] as $sn) {
            $scriptNonces .= " 'nonce-" . $sn . "'";
        }
        $styleNonces = '';
        foreach ($nonces['styles'] as $sn) {
            $styleNonces .= " 'nonce-" . $sn . "'";
        }
        $extraImgSrc = $this->grav['config']->get('plugins.akso-bridge.csp_img');
        $extraMediaSrc = $this->grav['config']->get('plugins.akso-bridge.csp_media');
        $extraChildSrc = $this->grav['config']->get('plugins.akso-bridge.csp_child');
        if ($extraImgSrc) $extraImgSrc = implode(' ', $extraImgSrc);
        else $extraImgSrc = '';
        if ($extraMediaSrc) $extraMediaSrc = implode(' ', $extraMediaSrc);
        else $extraMediaSrc = '';
        if ($extraChildSrc) $extraChildSrc = implode(' ', $extraChildSrc);
        else $extraChildSrc = '';

        $csp = [
            "default-src 'self'",
            "img-src 'self' data: " . $this->apiHost . " https://tile.openstreetmap.org " . $extraImgSrc,
            "media-src 'self' " . $extraMediaSrc,
            "script-src 'self' " . $scriptNonces,
            "style-src 'self' 'unsafe-inline' " . $styleNonces,
            "child-src 'self' " . $extraChildSrc,
        ];
        header('Content-Security-Policy: ' . implode(';', $csp), FALSE);
    }

    function adminInterceptGkSendToSubscribers() {
        if (isset($_POST['akso_gk_send_to_subs']) && $_POST['akso_gk_send_to_subs']) {
            $title = $_POST['data']['header']['title'];
            $content = $_POST['data']['content'];
            $url = null;
            if (isset($_POST['data']['route']) && isset($_POST['data']['folder'])) {
                $url = $_POST['data']['route'] . '/' . $_POST['data']['folder'];
            } else if (isset($_POST['data']['header']['routes']['canonical'])) {
                $url = $_POST['data']['header']['routes']['canonical'];
            } else {
                echo('Eraro');
                echo('');
                echo('could not read route');
                die();
            }
            $host = $this->grav['uri']->rootUrl(true);
            $app = new AppBridge($this->grav);
            $app->open();
            $subs = new GkSendToSubscribers($this, $app);
            $subs->run($title, $content, $host . $url . '/');
            $app->close();
        }
    }
}
