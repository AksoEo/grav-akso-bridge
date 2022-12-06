<?php
namespace Grav\Plugin\AksoBridge;

use Grav\Common\Grav;
use Grav\Common\Helpers\Excerpts;
use Grav\Plugin\AksoBridge\CongressRegistration;
use Grav\Plugin\AksoBridge\Utils;
use Grav\Plugin\AksoBridgePlugin;

class CongressInstance {
    private $plugin;
    private $app;
    private $congressId = null;
    private $instanceId = null;

    public function __construct($plugin, $app, $congressId, $instanceId) {
        $this->plugin = $plugin;
        $this->app = $app;
        $this->congressId = $congressId;
        $this->instanceId = $instanceId;
    }

    public function run($paymentOrg, $isRegistration) {
        $congressId = $this->congressId;
        $instanceId = $this->instanceId;
        $app = $this->app;
        $head = Grav::instance()['page']->header();

        $res = $app->bridge->get('/congresses/' . $congressId . '/instances/' . $instanceId, array(
            'fields' => [
                'name',
                'humanId',
                'dateFrom',
                'dateTo',
                'locationName',
                'locationAddress',
                'tz',
            ],
        ), 60);

        $firstEventRes = $app->bridge->get('/congresses/' . $congressId . '/instances/' . $instanceId . '/programs', array(
            'order' => ['timeFrom.asc'],
            'fields' => [
                'timeFrom',
            ],
            'offset' => 0,
            'limit' => 1,
        ), 60);

        $state = [];
        do {
            if (!$res['k']) {
                $state['akso_congress_error'] = $this->plugin->locale['content']['render_error'];
                if ($res['sc'] == 404) {
                    Grav::instance()->fireEvent('onPageNotFound');
                }
                break;
            }

            $congressName = $res['b']['name'];

            $linkBase = Grav::instance()['page']->route();
            if (!str_ends_with($linkBase, '/')) $linkBase .= '/';

            $state['akso_congress_registration_link'] = $linkBase . AksoBridgePlugin::CONGRESS_REGISTRATION_PATH;

            $congressStartTime = null;
            if ($firstEventRes['k'] && sizeof($firstEventRes['b']) > 0) {
                // use the start time of the first event if available
                $firstEvent = $firstEventRes['b'][0];
                $congressStartTime = \DateTime::createFromFormat("U", $firstEvent['timeFrom']);
            } else {
                // otherwise just use noon in local time
                $timeZone = isset($res['b']['tz']) ? new \DateTimeZone($res['b']['tz']) : new \DateTimeZone('+00:00');
                $dateStr = $res['b']['dateFrom'] . ' 12:00:00';
                $congressStartTime = \DateTime::createFromFormat("Y-m-d H:i:s", $dateStr, $timeZone);
            }

            $state['akso_congress_start_time'] = $congressStartTime->getTimestamp();
            $state['akso_congress_id'] = $congressId;
            $state['akso_congress'] = $res['b'];

            if (isset($head->header_url)) {
                $processed = Excerpts::processLinkExcerpt(array(
                    'element' => array(
                        'attributes' => array(
                            'href' => htmlspecialchars(urlencode($head->header_url)),
                        ),
                    ),
                ), Grav::instance()["page"], 'image');
                $imageUrl = $processed['element']['attributes']['href'];
                $state['akso_congress_header_url'] = $imageUrl;
            }
            if (isset($head->logo_url)) {
                $processed = Excerpts::processLinkExcerpt(array(
                    'element' => array(
                        'attributes' => array(
                            'href' => htmlspecialchars(urlencode($head->logo_url)),
                        ),
                    ),
                ), Grav::instance()["page"], 'image');
                $imageUrl = $processed['element']['attributes']['href'];
                $state['akso_congress_logo_url'] = $imageUrl;
            }
        } while (false);

        $regFormFields = ['allowUse', 'allowGuests'];
        if ($isRegistration) {
            $regFormFields []= 'identifierName';
            $regFormFields []= 'identifierEmail';
            $regFormFields []= 'identifierCountryCode';
            $regFormFields []= 'editable';
            $regFormFields []= 'cancellable';
            $regFormFields []= 'price.currency';
            $regFormFields []= 'price.var';
            $regFormFields []= 'price.minUpfront';
            $regFormFields []= 'form';
            $regFormFields []= 'customFormVars';
        }
        $formRes = $app->bridge->get('/congresses/' . $congressId . '/instances/' . $instanceId . '/registration_form', array(
            'fields' => $regFormFields
        ), 60);
        if ($formRes['k']) {
            // registration form exists
            $state['akso_congress_user_is_org'] = $this->plugin->aksoUser && str_starts_with($this->plugin->aksoUser['uea'], 'xx');
            $state['akso_congress_registration_enabled'] = true;
            $state['akso_congress_registration_allowed'] = $formRes['b']['allowUse'];
            $state['akso_congress_registration_guest_not_allowed'] = !$formRes['b']['allowGuests'] && !$this->plugin->aksoUser;

            if (!$isRegistration && $this->plugin->aksoUser) {
                // show "view my registration" instead of "register" if user is logged in & has registered
                $dataId = CongressRegistration::getDataIdForCodeholder($app, $congressId, $instanceId, $this->plugin->aksoUser['id']);
                if ($dataId) {
                    $state['akso_congress_registration_exists'] = true;
                    $state['akso_congress_registration_link'] .= '?' . CongressRegistration::DATAID . '=' . urlencode($dataId);
                }
            }

            if ($isRegistration && !$formRes['b']['allowGuests'] && !$this->plugin->aksoUser) {
                // user needs to log in to use this!
                Grav::instance()->redirectLangSafe($this->plugin->loginPath, 303);
            }

            if ($isRegistration) {
                Grav::instance()['assets']->add('plugin://akso-bridge/css/registration-form.css');
                Grav::instance()['assets']->add('plugin://akso-bridge/js/dist/form.js');

                $registration = new CongressRegistration($this->plugin, $app, $congressId, $instanceId, $paymentOrg, $formRes['b'], $congressName);
                $state['akso_congress_registration'] = $registration->run();
            }
        } else {
            // no registration form
            $state['akso_congress_registration_enabled'] = false;
        }

        return $state;
    }
}
