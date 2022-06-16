<?php
namespace Grav\Plugin\AksoBridge;

use Grav\Plugin\AksoBridge\Form;
use Grav\Plugin\AksoBridge\CodeholderLists;
use Grav\Plugin\AksoBridge\Utils;

// TODO: use form nonce

// The membership registration page
class Registration extends Form {
    private const STEP = 'p';
    private const STEP_OFFERS = '1';
    private const STEP_SUMMARY = '2';
    private const STEP_ENTRY_CREATE = '3';
    private const DATAID = 'dataId';

    // $_GET parameter where addons associated with dataIds are stored
    private const ADDONS = 'aldonebloj';

    // see AKSO Pay
    private const PAYMENT_SUCCESS_RETURN = 'payment_success_return';

    // $_SESSION key where ephemeral state will be stored
    private const SESSION_KEY_NAME = 'akso_alighilo_sess';

    private const CODEHOLDER_FIELDS = [
        'firstNameLegal', 'lastNameLegal', 'honorific', 'birthdate', 'email',
        'cellphone', 'feeCountry', 'address.country', 'address.countryArea',
        'address.city', 'address.cityArea', 'address.postalCode', 'address.sortingCode',
        'address.streetAddress',
    ];

    private $plugin, $isDonation;

    public function __construct($plugin, $app, $isDonation) {
        parent::__construct($app);
        $this->plugin = $plugin;
        $this->locale = $plugin->locale['registration'];
        $this->isDonation = $isDonation;
    }

    // if registration is disabled, will return an error. otherwise null
    private function getDisabledError() {
        // orgs can't sign up
        if ($this->plugin->aksoUser && str_starts_with($this->plugin->aksoUser['uea'], 'xx')) {
            return $this->locale['codeholder_cannot_be_org'];
        }
        return null;
    }

    // state array: stores the current state
    private $state;

    // Reads a key string like 'a.b.c' inside $obj and checks for its type.
    public static function readSafe($typechk, $obj, $key) {
        $keyParts = explode('.', $key);
        $keyPart = $keyParts[0];
        if (!isset($obj[$keyPart])) return null;
        if (count($keyParts) > 1) {
            if (gettype($obj[$keyPart]) !== 'array') return null;
            return self::readSafe($typechk, $obj[$keyPart], implode('.', array_slice($keyParts, 1)));
        }
        if (gettype($obj[$keyPart]) !== $typechk) return null;
        return $obj[$keyPart];
    }

    private function getPriceScriptCtx() {
        $scriptCtx = new FormScriptExecCtx($this->app);
        $codeholder = $this->state['codeholder'];
        if (isset($codeholder['birthdate'])) {
            // codeholder data exists; we can set form variables

            $scriptCtx->setFormVar('birthdate', $codeholder['birthdate']);

            $age = null;
            $agePrimo = null;
            {
                $birthdate = \DateTime::createFromFormat('Y-m-d', $codeholder['birthdate']);
                if ($birthdate) {
                    $now = new \DateTime();
                    $age = (int) $birthdate->diff($now)->format('y');

                    $beginningOfYear = new \DateTime();
                    $beginningOfYear->setISODate($now->format('Y'), 1, 1);
                    $agePrimo = (int) $birthdate->diff($beginningOfYear)->format('y');
                }
            }
            $scriptCtx->setFormVar('age', $age);
            $scriptCtx->setFormVar('agePrimo', $agePrimo);
            $scriptCtx->setFormVar('feeCountry', $codeholder['feeCountry']);

            $fcgRes = $this->app->bridge->get('/country_groups', array(
                'fields' => 'code',
                'filter' => array('countries' => array('$hasAny' => [$codeholder['feeCountry']])),
                'limit' => 100,
            ));
            $feeCountryGroups = [];
            if ($fcgRes['k']) {
                foreach ($fcgRes['b'] as $group) $feeCountryGroups[] = $group['code'];
            }
            $scriptCtx->setFormVar('feeCountryGroups', $feeCountryGroups);

            $isActiveMember = $this->plugin->aksoUser['member'];
            $scriptCtx->setFormVar('isActiveMember', $isActiveMember);
            return $scriptCtx;
        }
        return null;
    }

    private function scriptCtxFmtCurrency($scriptCtx, $currency, $value) {
        $scriptCtx->pushScript(array(
            'currency' => array('t' => 's', 'v' => $currency),
            'value' => array('t' => 'n', 'v' => $value),
        ));
        $formatted = $scriptCtx->eval(array(
            't' => 'c',
            'f' => 'currency_fmt',
            'a' => ['currency', 'value'],
        ))['v'];
        $scriptCtx->popScript();
        return $formatted;
    }

    // Loads all available offer years.
    private function loadAllOffers($skipOffers = false, $codeholder = null) {
        $registeredOffers = [];
        if ($codeholder && !$this->isDonation) {
            $res = $this->app->bridge->get("/codeholders/$codeholder/membership", array(
                'fields' => ['year', 'categoryId'],
                'order' => [['year', 'desc']],
                'limit' => 100, // fetch 100 items; probably enough
            ));
            if ($res['k']) {
                foreach ($res['b'] as $item) {
                    $year = $item['year'];
                    if (!isset($registeredOffers[$year])) {
                        $registeredOffers[$year] = [];
                    }
                    $registeredOffers[$year][] = $item['categoryId'];
                }
            }
        }

        $fields = ['year', 'paymentOrgId', 'currency'];
        $currentYear = (int) date('Y');
        if (!$skipOffers) $fields[] = 'offers';
        $res = $this->app->bridge->get('/registration/options', array(
            'limit' => 100,
            'filter' => ['enabled' => true, 'year' => ['$gte' => $currentYear]],
            'fields' => $fields,
            'order' => [['year', 'desc']],
        ));
        if ($res['k']) {
            $offerYears = array_reverse($res['b']);

            $scriptCtx = $this->getPriceScriptCtx();
            if ($scriptCtx) {
                $currency = $this->state['currency'];

                // compute all prices for the current codeholder
                foreach ($offerYears as &$offerYear) {
                    if (!isset($offerYear['offers'])) continue;
                    $yearCurrency = $offerYear['currency'];

                    foreach ($offerYear['offers'] as &$offerGroup) {
                        if (gettype($offerGroup['description']) === 'string') {
                            $offerGroup['description'] = $this->app->bridge->renderMarkdown(
                                $offerGroup['description'],
                                ['emphasis', 'strikethrough', 'link'],
                            )['c'];
                        }

                        foreach ($offerGroup['offers'] as &$offer) {
                            if (!isset($offer['price'])) continue;

                            $offer['is_duplicate'] = isset($registeredOffers[$offerYear['year']])
                                && in_array($offer['id'], $registeredOffers[$offerYear['year']]);

                            if ($offer['price']) {
                                if (gettype($offer['price']['description']) === 'string') {
                                    $offer['price']['description'] = $this->app->bridge->renderMarkdown(
                                        $offer['price']['description'],
                                        ['emphasis', 'strikethrough'],
                                    )['c'];
                                }

                                $scriptCtx->pushScript($offer['price']['script']);
                                $result = $scriptCtx->eval(array(
                                    't' => 'c',
                                    'f' => 'id',
                                    'a' => [$offer['price']['var']],
                                ));
                                $scriptCtx->popScript();

                                if ($result['s']) {
                                    $convertedValue = $this->convertCurrency(
                                        $yearCurrency,
                                        $currency,
                                        $result['v']
                                    );
                                    $offer['price']['value'] = $convertedValue;
                                    $offer['price']['amount'] = $this->scriptCtxFmtCurrency($scriptCtx, $currency, $convertedValue);
                                } else {
                                    $offer['price']['value'] = null;
                                    $offer['price']['amount'] = '(Eraro)';
                                }
                            }
                        }

                        if ($this->isDonation) {
                            $offerGroup['offers'] = array_filter($offerGroup['offers'], function ($offer) {
                                return $offer['type'] === 'addon';
                            });
                        }
                    }
                    if ($this->isDonation) {
                        $offerYear['offers'] = array_filter($offerYear['offers'], function ($group) {
                            return !empty($group['offers']);
                        });
                    }
                }
                if ($this->isDonation) {
                    // keep only the current year, if it's not empty
                    $offerYears = array_filter($offerYears, function ($year) use ($currentYear) {
                        return !empty($year['offers']) && $year['year'] === $currentYear;
                    });
                }
            }

            return $offerYears;
        }
        return [];
    }

    private function getOfferCategoryIds($offerYears) {
        $ids = new \Ds\Set();
        foreach ($offerYears as $year) {
            foreach ($year['offers'] as $group) {
                foreach ($group['offers'] as $offer) {
                    if ($offer['type'] === 'membership') {
                        $ids->add($offer['id']);
                    }
                }
            }
        }
        return $ids;
    }
    private function getRegisteredOfferCategoryIds($offerYears) {
        $ids = new \Ds\Set();
        foreach ($offerYears as $yearItems) {
            foreach ($yearItems as $offer) {
                if ($offer['type'] === 'membership') $ids->add($offer['id']);
            }
        }
        return $ids;
    }
    private function getOfferMagazineIds($offerYears) {
        $ids = new \Ds\Set();
        foreach ($offerYears as $year) {
            foreach ($year['offers'] as $group) {
                foreach ($group['offers'] as $offer) {
                    if ($offer['type'] === 'magazine') {
                        $ids->add($offer['id']);
                    }
                }
            }
        }
        return $ids;
    }
    private function getRegisteredOfferMagazineIds($offerYears) {
        $ids = new \Ds\Set();
        foreach ($offerYears as $yearItems)  {
            foreach ($yearItems as $offer) {
                if ($offer['type'] === 'magazine') $ids->add($offer['id']);
            }
        }
        return $ids;
    }
    private function getOfferAddonIds($offerYears) {
        $orgs = new \Ds\Map();
        foreach ($offerYears as $year) {
            $ids = new \Ds\Set();
            foreach ($year['offers'] as $group) {
                foreach ($group['offers'] as $offer) {
                    if ($offer['type'] === 'addon') {
                        $ids->add($offer['id']);
                    }
                }
            }
            if (!$ids->isEmpty()) {
                $orgId = $year['paymentOrgId'];
                if (!$orgs->hasKey($orgId)) $orgs->put($orgId, new \Ds\Set());
                $orgs->put($orgId, $orgs->get($orgId)->union($ids));
            }
        }
        return $orgs;
    }
    private function getRegisteredOfferAddonIds($offerYears) {
        $orgs = new \Ds\Map();
        foreach ($offerYears as $year => $yearItems) {
            $ids = new \Ds\Set();
            foreach ($yearItems as $offer) {
                if ($offer['type'] === 'addon') $ids->add($offer['id']);
            }
            if (!$ids->isEmpty()) {
                $orgId = $this->offersByYear[$year]['paymentOrgId'];
                if (!$orgs->hasKey($orgId)) $orgs->put($orgId, new \Ds\Set());
                $orgs->put($orgId, $orgs->get($orgId)->union($ids));
            }
        }
        return $orgs;
    }
    private function loadAllCategories($ids) {
        $categories = [];
        if ($ids->isEmpty()) return $categories;
        for ($i = 0; true; $i += 100) {
            $res = $this->app->bridge->get("/membership_categories", array(
                'fields' => ['id', 'nameAbbrev', 'name', 'description', 'lifetime', 'givesMembership'],
                'filter' => ['id' => ['$in' => $ids->slice(0, 100)->toArray()]],
                'limit' => 100,
                'offset' => $i,
            ), 120);
            if (!$res['k']) {
                // TODO: emit error
                break;
            }
            foreach ($res['b'] as $cat) {
                if (gettype($cat['description']) === 'string') {
                    $cat['description'] = $this->app->bridge->renderMarkdown(
                        $cat['description'],
                        ['emphasis', 'strikethrough', 'link'],
                    )['c'];
                }

                $categories[$cat['id']] = $cat;
                $ids->remove($cat['id']);
            }
            if ($ids->isEmpty()) break;
        }

        return $categories;
    }
    private function loadAllMagazines($ids) {
        $magazines = [];
        if ($ids->isEmpty()) return $magazines;
        for ($i = 0; true; $i += 100) {
            $res = $this->app->bridge->get("/magazines", array(
                'fields' => ['id', 'org', 'name', 'description'],
                'filter' => ['id' => ['$in' => $ids->slice(0, 100)->toArray()]],
                'limit' => 100,
                'offset' => $i,
            ), 120);
            if (!$res['k']) {
                // TODO: emit error
                break;
            }
            foreach ($res['b'] as $mag) {
                $magazines[$mag['id']] = $mag;
                $ids->remove($mag['id']);
            }
            if ($ids->isEmpty()) break;
        }

        return $magazines;
    }
    private function loadAllAddons($orgs) {
        $result = [];
        foreach ($orgs->keys()->toArray() as $orgId) {
            $ids = $orgs->get($orgId);
            $addons = [];
            if ($ids->isEmpty()) {
                $result[$orgId] = $addons;
                continue;
            }
            for ($i = 0; true; $i += 100) {
                $res = $this->app->bridge->get("/aksopay/payment_orgs/$orgId/addons", array(
                    'fields' => ['id', 'name', 'description'],
                    'filter' => ['id' => ['$in' => $ids->slice(0, 100)->toArray()]],
                    'limit' => 100,
                    'offset' => $i,
                ), 120);
                if (!$res['k']) {
                    // TODO: emit error
                    break;
                }
                foreach ($res['b'] as $addon) {
                    if (gettype($addon['description']) === 'string') {
                        $addon['description'] = $this->app->bridge->renderMarkdown(
                            $addon['description'],
                            ['emphasis', 'strikethrough', 'link', 'list', 'table'],
                        )['c'];
                    }

                    $addons[$addon['id']] = $addon;
                    $ids->remove($addon['id']);
                }
                if ($ids->isEmpty()) break;
            }
            $result[$orgId] = $addons;
        }
        return $result;
    }
    private function loadPaymentOrgs($orgs) {
        $result = [];
        foreach ($orgs->toArray() as $orgId) {
            $res = $this->app->bridge->get("/aksopay/payment_orgs/$orgId", array(
                'fields' => ['id', 'org', 'name'],
            ));
            if (!$res['k']) {
                // TODO: error?
                break;
            }
            $orgInfo = $res['b'];
            $methods = [];

            for ($i = 0; true; $i += 100) {
                $res = $this->app->bridge->get("/aksopay/payment_orgs/$orgId/methods", array(
                    'fields' => ['id', 'type', 'name', 'description', 'currencies', 'prices', 'feePercent', 'feeFixed.val', 'feeFixed.cur', 'maxAmount'],
                    'filter' => array('internal' => false),
                    'limit' => 100,
                    'offset' => $i,
                ), 120);
                if (!$res['k']) {
                    throw new \Exception('Failed to load payment methods');
                    break;
                }
                foreach ($res['b'] as $method) {
                    if (gettype($method['description']) === 'string') {
                        $method['description'] = $this->app->bridge->renderMarkdown(
                            $method['description'],
                            ['emphasis', 'strikethrough', 'link', 'list', 'table'],
                        )['c'];
                    }

                    $methods[$method['id']] = $method;
                }
                if (count($methods) >= $res['h']['x-total-items']) {
                    break;
                }
            }
            $orgInfo['methods'] = $methods;
            $result[$orgId] = $orgInfo;
        }
        return $result;
    }

    public function loadRegistered($dataIds, $addons, $membershipAddons) {
        $needsLogin = false;
        $currency = '';
        $hasCodeholder = false;
        $codeholder = [];
        $offersByYear = [];
        $yearDataIds = [];
        $yearStatuses = [];

        $scriptCtx = new FormScriptExecCtx($this->app);

        foreach ($dataIds as $id) {
            if (!$id) continue;
            $res = $this->app->bridge->get("/registration/entries/$id", array(
                'fields' => ['year', 'currency', 'codeholderData', 'offers', 'status'],
            ));
            if ($res['k']) {
                // ignore duplicate year
                if (isset($offersByYear[$res['b']['year']])) continue;
                if (!$hasCodeholder) {
                    $hasCodeholder = $res['b']['codeholderData'];
                    if (gettype($res['b']['codeholderData']) === 'integer') {
                        if (!$this->plugin->aksoUser || $this->plugin->aksoUser['id'] != $res['b']['codeholderData']) {
                            $needsLogin = true;
                        } else {
                            $chId = $res['b']['codeholderData'];
                            $chRes = $this->app->bridge->get("/codeholders/$chId", array(
                                'fields' => self::CODEHOLDER_FIELDS,
                            ));
                            if ($chRes['k']) $codeholder = $chRes['b'];
                        }
                    } else {
                        $codeholder = $res['b']['codeholderData'];
                    }
                } else {
                    // ignore incorrect codeholder
                    if ($res['b']['codeholderData'] != $hasCodeholder) continue;
                }

                $currency = $res['b']['currency'];
                $selectedItems = [];
                foreach ($res['b']['offers'] as $offer) {
                    $offer['amount_original'] = $offer['amount'];
                    $offer['amount_addon'] = null;

                    if ($offer['type'] === 'magazine') {
                        $offer['paper'] = $offer['paperVersion'];
                    }

                    if ($offer['type'] === 'membership' || $offer['type'] === 'magazine') {
                        if (isset($membershipAddons[$res['b']['year']][$offer['id']])) {
                            $addon = $membershipAddons[$res['b']['year']][$offer['id']];
                            $offer['amount_original'] = $offer['amount'] - $addon['amount'];
                            $offer['amount_addon'] = $addon['amount'];
                        }
                    }
                    $selectedItems[] = $offer;
                }

                if (isset($addons[$res['b']['year']])) {
                    foreach ($addons[$res['b']['year']] as $addon) {
                        $selectedItems[] = $addon;
                    }
                }

                $offersByYear[$res['b']['year']] = $selectedItems;
                $yearDataIds[$res['b']['year']] = $id;
                $yearStatuses[$res['b']['year']] = $res['b']['status'];
            } else {
                $this->plugin->getGrav()->fireEvent('onPageNotFound');
            }
        }

        if ($codeholder) {
            $codeholder['locked'] = true;
            $codeholder['splitCountry'] = true;
            $codeholder['splitName'] = true;
        }

        return array(
            'needs_login' => $needsLogin,
            'currency' => $currency,
            'codeholder' => $codeholder,
            'offers' => $offersByYear,
            'year_statuses' => $yearStatuses,
            'dataIds' => $yearDataIds,
        );
    }

    private $offers = null;
    private $offersByYear = null;
    private $paymentOrgs = null;

    // Returns the target path for the current page
    private function getEditTarget() {
        $editTarget = $this->plugin->getGrav()['uri']->path();
        if (count($this->state['dataIds'])) {
            $dataIds = implode('-', $this->state['dataIds']);

            $addonsSerialized = '';
            foreach ($this->state['addons'] as $year => $addons) {
                if ($addonsSerialized) $addonsSerialized .= '~';
                $addonsSerialized .= $year;
                foreach ($addons as $addon) {
                    $addonsSerialized .= '+';
                    if ($addon['type'] === 'membership') $addonsSerialized .= 'm';
                    else if ($addon['type'] === 'magazine') $addonsSerialized .= 'r';
                    else $addonsSerialized .= 'a';
                    $addonsSerialized .= $addon['id'] . '-' . $addon['amount'];
                }
            }

            $editTarget .= '?' . self::DATAID . '=' . $dataIds .
                '&' . self::ADDONS . '=' . $addonsSerialized;
        }
        return $editTarget;
    }

    // Reads form state
    private function readFormState() {
        $this->state['dataIds'] = [];
        if (isset($_GET[self::DATAID]) && gettype($_GET[self::DATAID]) === 'string') {
            $this->state['dataIds'] = explode('-', $_GET[self::DATAID]);
        }

        if (isset($_GET[self::PAYMENT_SUCCESS_RETURN])) {
            $_SESSION[self::PAYMENT_SUCCESS_RETURN] = true;
            // redirect to the same page without the payment_success_return url parameter
            $this->plugin->getGrav()->redirectLangSafe($this->getEditTarget(), 302);
            // which leads us...
        }
        if (isset($_SESSION[self::PAYMENT_SUCCESS_RETURN])) {
            // ...here!
            // show a payment success message
            $this->state['payment_success'] = true;
            unset($_SESSION[self::PAYMENT_SUCCESS_RETURN]);
        }

        // read the current registration step
        if (isset($_GET[self::STEP]) && gettype($_GET[self::STEP]) === 'string') {
            $step = $_GET[self::STEP];
            if ($step === self::STEP_OFFERS) $this->state['step'] = 1;
            if ($step === self::STEP_SUMMARY) $this->state['step'] = 2;
            if ($step === self::STEP_ENTRY_CREATE) $this->state['step'] = 3;
        }
        if (count($this->state['dataIds'])) {
            // there are registered items!
            $this->state['step'] = 3; // force step 3
        }

        // load saved state
        $this->state['unsafe_deserialized'] = [];
        if (isset($_POST['state_serialized'])) {
            try {
                $decoded = json_decode($_POST['state_serialized'], true);
                if (gettype($decoded) === 'array') $this->state['unsafe_deserialized'] = $decoded;
            } catch (\Exception $e) {}
        } else if (isset($_SESSION[self::SESSION_KEY_NAME]) && gettype($_SESSION[self::SESSION_KEY_NAME]) === 'array') {
            $this->state['unsafe_deserialized'] = $_SESSION[self::SESSION_KEY_NAME];
        }
    }
    private function updateCurrencyState() {
        $serializedState = $this->state['unsafe_deserialized'];
        $currencies = $this->getCachedCurrencies();
        if (!isset($this->state['currency'])) {
            $this->state['currency'] = 'EUR'; // default

            if (isset($this->state['codeholder']['feeCountry'])) {
                // default to fee country
                $this->state['currency'] = $this->getDefaultFeeCountryCurrency($this->state['codeholder']['feeCountry']);
            }

            if (isset($_POST['currency'])
                && gettype($_POST['currency']) === 'string'
                && isset($currencies[$_POST['currency']])) {
                $this->state['currency'] = $_POST['currency'];
            } else if (isset($serializedState['currency'])
                && gettype($serializedState['currency']) === 'string'
                && isset($currencies[$serializedState['currency']])) {
                $this->state['currency'] = $serializedState['currency'];
            }
        }

        // render a “0,00” placeholder for currency inputs
        {
            // kinda hacky but it works
            $this->state['currency_placeholder'] = '0';
            $mult = 0;
            if (isset($currencies[$this->state['currency']])) $mult = $currencies[$this->state['currency']];
            if ($mult > 1) $this->state['currency_placeholder'] .= ',';
            while ($mult > 1) {
                $mult /= 10;
                $this->state['currency_placeholder'] .= '0';
            }
        }

        $this->state['currency_mult'] = isset($currencies[$this->state['currency']]) ? $currencies[$this->state['currency']] : 1;
    }

    public static function readCodeholderStateSafe($bridge, $ch) {
        $cellphone = self::readSafe('string', $ch, 'cellphone');
        if ($cellphone) {
            $cellphone = preg_replace('/[^+0-9]/u', '', $cellphone);
        }
        $officePhone = self::readSafe('string', $ch, 'officePhone');
        if ($officePhone) {
            $officePhone = preg_replace('/[^+0-9]/u', '', $officePhone);
        }
        $landlinePhone = self::readSafe('string', $ch, 'landlinePhone');
        if ($landlinePhone) {
            $landlinePhone = preg_replace('/[^+0-9]/u', '', $landlinePhone);
        }

        $isOrg = isset($ch['codeholderType']) && $ch['codeholderType'] === 'org';

        $codeholder = array();
        if ($isOrg) {
            $codeholder = array_merge($codeholder, array(
                'fullName' => self::readSafe('string', $ch, 'fullName'),
                'fullNameLocal' => self::readSafe('string', $ch, 'fullNameLocal'),
                'nameAbbrev' => self::readSafe('string', $ch, 'nameAbbrev'),
                'careOf' => self::readSafe('string', $ch, 'careOf'),
            ));
        } else {
            $codeholder = array_merge($codeholder, array(
                'firstName' => ((isset($ch['splitName']) && $ch['splitName'])
                    ? self::readSafe('string', $ch, 'firstName')
                    : null) ?: null,
                'lastName' => ((isset($ch['splitName']) && $ch['splitName'])
                    ? self::readSafe('string', $ch, 'lastName')
                    : null) ?: null,
                'firstNameLegal' => ((isset($ch['splitName']) && $ch['splitName'])
                    ? self::readSafe('string', $ch, 'firstNameLegal')
                    : self::readSafe('string', $ch, 'firstName')) ?: null,
                'lastNameLegal' => (isset($ch['splitName']) && $ch['splitName']
                    ? self::readSafe('string', $ch, 'lastNameLegal')
                    : self::readSafe('string', $ch, 'lastName')) ?: null,
                'honorific' => self::readSafe('string', $ch, 'honorific') ?: null,
                'birthdate' => self::readSafe('string', $ch, 'birthdate') ?: null,
                'cellphone' => $cellphone ?: null,
                'landlinePhone' => $landlinePhone ?: null,
            ));
        }

        $codeholder = array_merge($codeholder, array(
            'email' => self::readSafe('string', $ch, 'email') ?: null,
            'officePhone' => $officePhone ?: null,
            'feeCountry' => (isset($ch['splitCountry']) && $ch['splitCountry'])
                ? self::readSafe('string', $ch, 'feeCountry')
                : self::readSafe('string', $ch, 'address.country'),
            'address' => array(
                'country' => self::readSafe('string', $ch, 'address.country'),
                'countryArea' => self::readSafe('string', $ch, 'address.countryArea') ?: null,
                'city' => self::readSafe('string', $ch, 'address.city') ?: null,
                'cityArea' => self::readSafe('string', $ch, 'address.cityArea') ?: null,
                'postalCode' => self::readSafe('string', $ch, 'address.postalCode') ?: null,
                'sortingCode' => self::readSafe('string', $ch, 'address.sortingCode') ?: null,
                'streetAddress' => self::readSafe('string', $ch, 'address.streetAddress') ?: null,
            ),
        ));

        // phone number post-processing: if the number does not start with a +, try to parse it as a
        // local number
        if (isset($codeholder['cellphone']) && $codeholder['cellphone'] && !str_starts_with($codeholder['cellphone'], '+')) {
            $res = $bridge->parsePhoneLocal($codeholder['cellphone'], $codeholder['address']['country']);
            if ($res['s']) $codeholder['cellphone'] = $res['n'];
        }
        if ($codeholder['officePhone'] && !str_starts_with($codeholder['officePhone'], '+')) {
            $res = $bridge->parsePhoneLocal($codeholder['officePhone'], $codeholder['address']['country']);
            if ($res['s']) $codeholder['officePhone'] = $res['n'];
        }
        if (isset($codeholder['landlinePhone']) && $codeholder['landlinePhone'] && !str_starts_with($codeholder['landlinePhone'], '+')) {
            $res = $bridge->parsePhoneLocal($codeholder['landlinePhone'], $codeholder['address']['country']);
            if ($res['s']) $codeholder['landlinePhone'] = $res['n'];
        }

        return $codeholder;
    }

    private function updateCodeholderState() {
        if ($this->state['needs_login']) return;

        $ch = [];
        if (!isset($this->state['codeholder']['locked'])) {
            $serializedState = $this->state['unsafe_deserialized'];

            if ($this->plugin->aksoUser) {
                $codeholderId = $this->plugin->aksoUser['id'];
                $res = $this->app->bridge->get("/codeholders/$codeholderId", array(
                    'fields' => self::CODEHOLDER_FIELDS,
                ));
                if ($res['k']) {
                    $ch = $res['b'];
                    $ch['splitCountry'] = true; // always read address and fee country separately
                    $ch['splitName'] = true;
                } else {
                    throw new \Exception("could not fetch codeholder");
                }
            } else if (isset($_POST['codeholder']) && gettype($_POST['codeholder']) === 'array') {
                $ch = $_POST['codeholder'];
            } else if (isset($serializedState['codeholder']) && gettype($serializedState['codeholder'] === 'array')) {
                $ch = $serializedState['codeholder'];
                $ch['splitCountry'] = true;
                $ch['splitName'] = true;
            }
            if ($this->isDonation) $ch['splitName'] = true;

            $this->state['codeholder'] = self::readCodeholderStateSafe($this->app->bridge, $ch);
        }

        $addressFmt = '';
        try {
            $addr = $this->state['codeholder']['address'];
            $countryName = '';
            foreach ($this->getCachedCountries() as $entry) {
                if ($entry['code'] == $addr['country']) {
                    $countryName = $entry['name_eo'];
                    break;
                }
            }
            $addressFmt = $this->app->bridge->renderAddress(array(
                'countryCode' => $addr['country'],
                'countryArea' => $addr['countryArea'],
                'city' => $addr['city'],
                'cityArea' => $addr['cityArea'],
                'streetAddress' => $addr['streetAddress'],
                'postalCode' => $addr['postalCode'],
                'sortingCode' => $addr['sortingCode'],
            ), $countryName)['c'];
            $addressFmt = implode(', ', explode("\n", $addressFmt));
        } catch (\Exception $e) {
            $addressFmt = '(Nevalida adreso)';
        }

        $feeCountryName = '';
        foreach ($this->getCachedCountries() as $entry) {
            if ($entry['code'] == $this->state['codeholder']['feeCountry']) {
                $feeCountryName = $entry['name_eo'];
                break;
            }
        }

        $cellphoneFmt = null;
        if (isset($this->state['codeholder']['cellphone'])) {
            $cellphoneFmt = $this->app->bridge->evalScript([array(
                'number' => array('t' => 's', 'v' => $this->state['codeholder']['cellphone']),
            )], [], array('t' => 'c', 'f' => 'phone_fmt', 'a' => ['number']));
            if ($cellphoneFmt['s']) $cellphoneFmt = $cellphoneFmt['v'];
            else $cellphoneFmt = null;
            if ($cellphoneFmt === null) $cellphoneFmt = $this->state['codeholder']['cellphone'];
        }

        $landlinePhoneFmt = null;
        if (isset($this->state['codeholder']['landlinePhone'])) {
            $landlinePhoneFmt = $this->app->bridge->evalScript([array(
                'number' => array('t' => 's', 'v' => $this->state['codeholder']['landlinePhone']),
            )], [], array('t' => 'c', 'f' => 'phone_fmt', 'a' => ['number']));
            if ($landlinePhoneFmt['s']) $landlinePhoneFmt = $landlinePhoneFmt['v'];
            else $landlinePhoneFmt = null;
            if ($landlinePhoneFmt === null) $landlinePhoneFmt = $this->state['codeholder']['landlinePhone'];
        }

        $this->state['codeholder_derived'] = array(
            'birthdate' => Utils::formatDate($this->state['codeholder']['birthdate']),
            'address' => $addressFmt,
            'fee_country' => $feeCountryName,
            'cellphone' => $cellphoneFmt,
            'landline_phone' => $landlinePhoneFmt,
        );
    }

    private function updateOffersState() {
        $serializedState = $this->state['unsafe_deserialized'];
        $this->state['offers'] = [];

        if (isset($_POST['offers']) && gettype($_POST['offers']) === 'array') {
            foreach ($_POST['offers'] as $_year => $yearItems) {
                $year = (int) $_year;
                if (isset($this->state['locked_offers'][$year])) continue;
                $this->state['offers'][$year] = [];

                // key format: [year][group][offer][type-id]
                foreach ($yearItems as $groupIndex => $groupItems) {
                    if ($groupIndex === 'membership') {
                        // this is the givesMembership radio selection
                        if (gettype($groupItems) !== 'string') continue;
                        $offerKeyParts = explode('-', $groupItems);
                        if (count($offerKeyParts) != 4) continue;
                        $groupIndex = (int) $offerKeyParts[0];
                        $offerIndex = (int) $offerKeyParts[1];
                        $type = $offerKeyParts[2];
                        $id = (int) $offerKeyParts[3];

                        if ($type !== 'membership' && $type !== 'addon' && $type !== 'magazine') continue;

                        $amount = null;
                        $amountAddon = 0;
                        if (isset($_POST['offer_amount'])) {
                            $k = "$year-$groupIndex-$offerIndex";
                            if (isset($_POST['offer_amount'][$k])) {
                                $amount = (int) (self::floatval($_POST['offer_amount'][$k]) * $this->state['currency_mult']);
                            }
                            if (isset($_POST['offer_amount_addon'][$k])) {
                                $amountAddon = (int) (self::floatval($_POST['offer_amount_addon'][$k]) * $this->state['currency_mult']);
                            }
                        }

                        $this->state['offers'][$year]["$groupIndex-$offerIndex"] = array(
                            'type' => $type,
                            'id' => $id,
                            'amount' => $amount,
                            'amount_addon' => $amountAddon,
                        );

                        continue;
                    }

                    if (gettype($groupItems) !== 'array') continue;
                    foreach ($groupItems as $offerIndex => $offerData) {
                        if (gettype($offerData) !== 'array') continue;

                        $offerKeys = array_keys($offerData);
                        if (count($offerKeys) != 1) continue;
                        $offerKey = $offerKeys[0];

                        $offerKeyParts = explode('-', $offerKey);
                        if (count($offerKeyParts) != 3) continue;
                        $type = $offerKeyParts[0];
                        $id = (int) $offerKeyParts[1];
                        $variant = $offerKeyParts[2];

                        if ($type !== 'membership' && $type !== 'addon' && $type !== 'magazine') continue;

                        $amount = null;
                        $amountAddon = 0;
                        if (isset($_POST['offer_amount'])) {
                            $k = "$year-$groupIndex-$offerIndex";
                            if (isset($_POST['offer_amount'][$k])) {
                                $amount = (int) (self::floatval($_POST['offer_amount'][$k]) * $this->state['currency_mult']);
                            }
                            if (isset($_POST['offer_amount_addon'][$k])) {
                                $amountAddon = (int) (self::floatval($_POST['offer_amount_addon'][$k]) * $this->state['currency_mult']);
                            }
                        }

                        $stateData = array(
                            'type' => $type,
                            'id' => $id,
                            'amount' => $amount,
                            'amount_addon' => $amountAddon,
                            'paper' => $variant === 'p',
                        );

                        $this->state['offers'][$year]["$groupIndex-$offerIndex"] = $stateData;
                    }
                }
            }
        } else if (isset($serializedState['offers']) && gettype($serializedState['offers']) === 'array') {
            foreach ($serializedState['offers'] as $year => $yearItems) {
                if (isset($this->state['locked_offers'][$year])) continue;
                if (gettype($yearItems) !== 'array') continue;
                $items = [];
                foreach ($yearItems as $key => $item) {
                    if (gettype($item) !== 'array') continue 2;
                    $keyParts = explode('-', $key);
                    if (count($keyParts) != 2) continue;
                    $group = $keyParts[0];
                    $idx = $keyParts[1];
                    if (!isset($item['type']) || gettype($item['type']) !== 'string') continue;
                    if (!isset($item['id']) || gettype($item['id']) !== 'integer') continue;
                    $items["$group-$idx"] = array(
                        'type' => $item['type'],
                        'id' => $item['id'],
                        'amount' => isset($item['amount']) ? ((float) $item['amount']) : null,
                        'amount_addon' => isset($item['amount_addon']) ? ((float) $item['amount_addon']) : 0,
                        'paper' => isset($item['paper']) ? $item['paper'] : false,
                    );
                }
                if (!empty($items)) $this->state['offers'][$year] = $items;
            }
        }

        foreach ($this->state['locked_offers'] as $year => $items) {
            $this->state['offers'][$year] = $items;
        }

        if ($this->state['step'] >= 1) {
            $codeholderId = $this->plugin->aksoUser ? $this->plugin->aksoUser['id'] : null;
            $this->offers = $this->loadAllOffers(false, $codeholderId);
            $this->offersByYear = [];
            foreach ($this->offers as $offerYear) {
                $this->offersByYear[$offerYear['year']] = $offerYear;
            }

            $this->state['offers_indexed'] = [];
            $this->state['offers_indexed_amounts'] = [];
            $this->state['offers_indexed_amount_addons'] = [];
            $this->state['offers_sum'] = 0;
            $scriptCtx = new FormScriptExecCtx($this->app);
            foreach ($this->state['offers'] as $year => &$yearItems) {
                $isLocked = isset($this->state['locked_offers'][$year]);
                foreach ($yearItems as $key => &$offer) {
                    $group = -1;
                    $id = -1;
                    if (!$isLocked) {
                        $keyParts = explode('-', $key);
                        $group = $keyParts[0];
                        $id = $keyParts[1];
                    }

                    if (!isset($offer['amount_original'])) $offer['amount_original'] = $offer['amount'];
                    if (!isset($offer['amount_addon'])) $offer['amount_addon'] = 0;

                    if (!$isLocked) {
                        // verify amounts from api data
                        if ($offer['type'] === 'membership') {
                            $apiOffer = null;
                            if (isset($this->offersByYear[$year]['offers'][$group]['offers'][$id])) {
                                $apiOffer = $this->offersByYear[$year]['offers'][$group]['offers'][$id];
                            }
                            if ($apiOffer) {
                                $offer['amount_addon'] = max(0, $offer['amount_addon']);
                                $offer['amount_original'] = $apiOffer['price']['value'];
                                $offer['amount'] = $apiOffer['price']['value'] + $offer['amount_addon'];
                            } else $offer['amount'] = 2147483647; // FIXME: offer doesnt exist! what to do?
                        } else if ($offer['type'] === 'addon') {
                            $offer['amount'] = max(1, $offer['amount']);
                            $offer['amount_original'] = $offer['amount'];
                        } else if ($offer['type'] === 'magazine') {
                            $apiOffer = null;
                            if (isset($this->offersByYear[$year]['offers'][$group]['offers'][$id])) {
                                $apiOffer = $this->offersByYear[$year]['offers'][$group]['offers'][$id];
                            }
                            if ($apiOffer) {
                                $offer['amount_addon'] = max(0, $offer['amount_addon']);
                                $offer['amount_original'] = $apiOffer['price']['value'];
                                $offer['amount'] = $apiOffer['price']['value'] + $offer['amount_addon'];
                            } else $offer['amount'] = 2147483647; // FIXME: same deal as above
                        }
                    }

                    $this->state['offers_indexed_amounts']["$year-$group-$id"] = ((float) $offer['amount']) / $this->state['currency_mult'];
                    $this->state['offers_indexed_amount_addons']["$year-$group-$id"] = ((float) $offer['amount_addon']) / $this->state['currency_mult'];
                    $this->state['offers_indexed']["$year-$group-$id"] = $offer;

                    $scriptCtx->pushScript(array(
                        'currency' => array('t' => 's', 'v' => $this->state['currency']),
                        'value' => array('t' => 'n', 'v' => $offer['amount']),
                        'value_orig' => array('t' => 'n', 'v' => $offer['amount_original']),
                        'value_addon' => array('t' => 'n', 'v' => $offer['amount_addon']),
                    ));
                    $offer['amount_rendered'] = $scriptCtx->eval(array(
                        't' => 'c',
                        'f' => 'currency_fmt',
                        'a' => ['currency', 'value'],
                    ))['v'];
                    $offer['amount_original_rendered'] = $scriptCtx->eval(array(
                        't' => 'c',
                        'f' => 'currency_fmt',
                        'a' => ['currency', 'value_orig'],
                    ))['v'];
                    $offer['amount_addon_rendered'] = $scriptCtx->eval(array(
                        't' => 'c',
                        'f' => 'currency_fmt',
                        'a' => ['currency', 'value_addon'],
                    ))['v'];
                    $scriptCtx->popScript();

                    $this->state['offers_sum'] += $offer['amount'];
                }
            }

            {
                $scriptCtx->pushScript(array(
                    'currency' => array('t' => 's', 'v' => $this->state['currency']),
                    'value' => array('t' => 'n', 'v' => $this->state['offers_sum']),
                ));
                $this->state['offers_sum_rendered'] = $scriptCtx->eval(array(
                    't' => 'c',
                    'f' => 'currency_fmt',
                    'a' => ['currency', 'value'],
                ))['v'];
                $scriptCtx->popScript();
            }
        }
    }

    private function updatePaymentsState() {
        if ($this->state['step'] >= 2) {
            $paymentOrgIds = new \Ds\Set();
            foreach ($this->offers as $offerYear) {
                $paymentOrgIds->add($offerYear['paymentOrgId']);
            }
            $this->paymentOrgs = $this->loadPaymentOrgs($paymentOrgIds);
            $scriptCtx = new FormScriptExecCtx($this->app);

            // add additional derived data to payment orgs
            foreach ($this->paymentOrgs as &$org) {
                $org['years'] = [];
                $org['statuses'] = [];
                $org['can_pay'] = false;
                $org['offers_sum'] = 0;
                foreach ($this->offers as $offerYear) {
                    if ($offerYear['paymentOrgId'] == $org['id']) {
                        $org['years'][] = $offerYear['year'];
                        $yearStatus = '';
                        if (isset($this->state['year_statuses'][$offerYear['year']])) {
                            $yearStatus = $this->state['year_statuses'][$offerYear['year']];
                        }
                        $org['statuses'][$offerYear['year']] = $yearStatus;
                        if (!$yearStatus) $org['can_pay'] = true;
                        else if ($yearStatus === 'pending') {
                            // TODO: we need to filter payment methods to only the selected one
                            $org['can_pay'] = true;
                        }

                        if (isset($this->state['offers'][$offerYear['year']])) {
                            $offers = $this->state['offers'][$offerYear['year']];
                            foreach ($offers as $offer) {
                                $org['offers_sum'] += $offer['amount'];
                            }
                        }
                    }
                }

                $scriptCtx->pushScript(array(
                    'currency' => array('t' => 's', 'v' => $this->state['currency']),
                    'value' => array('t' => 'n', 'v' => $org['offers_sum']),
                ));
                $org['offers_sum_rendered'] = $scriptCtx->eval(array(
                    't' => 'c',
                    'f' => 'currency_fmt',
                    'a' => ['currency', 'value'],
                ))['v'];
                $scriptCtx->popScript();

                if ($org['can_pay']) {
                    foreach ($org['methods'] as &$method) {
                        $method = $this->deriveMethodState($org, $method);
                    }
                    $origCount = count($org['methods']);
                    $org['methods'] = array_filter($org['methods'], function ($method) {
                        return $method['currency_available'];
                    });
                    $org['methods_currency_unavailable'] = $origCount - count($org['methods']);
                }
            }

            if (isset($_POST['payment_org']) && isset($_POST['payment_method_id'])) {
                // the user clicked on the 'pay' button
                $paymentOrg = (int) $_POST['payment_org'];
                $paymentMethodId = (int) $_POST['payment_method_id'];
                $currency = $this->state['currency'];

                $this->createIntent($paymentOrg, $paymentMethodId, $currency);
            }
        }
    }

    private function deriveMethodState($org, $method) {
        $scriptCtx = $this->getPriceScriptCtx();
        if (!$scriptCtx) {
            $method['available'] = false;
            $method['error'] = '(Eraro)';
            return $method;
        }

        $currency = $this->state['currency'];
        $method['currency_available'] = in_array($currency, $method['currencies']);
        $method['total_sum'] = $org['offers_sum'];
        $method['fee_total'] = 0;
        $method['fee_total_rendered'] = '';

        if ($method['feeFixed'] && $method['feeFixed']['val']) {
            $method['fee_total'] += $this->convertCurrency($method['feeFixed']['cur'], $currency, $method['feeFixed']['val']);
        }

        if ($method['type'] === 'intermediary') {
            $res = $this->app->bridge->get('/intermediaries', array(
                'fields' => ['codeholderId', 'paymentDescription'],
                'filter' => array(
                    'countryCode' => $this->state['codeholder']['feeCountry'],
                ),
                'limit' => 1,
            ));
            if (!$res['k']) {
                $method['available'] = false;
                $method['error'] = '(Eraro)';
                return $method;
            }

            if (empty($res['b'])) {
                $method['available'] = false;
                $method['error'] = $this->locale['payment_intermediary_err_none_for_country'];
                return $method;
            }

            $method['intermediary'] = $res['b'][0];

            $intermediary = $method['intermediary']['codeholderId'];
            $intermediary = $this->app->bridge->get("/codeholders/$intermediary", array(
                'fields' => CodeholderLists::FIELDS,
            ));
            if (!$intermediary['k']) {
                $method['available'] = false;
                $method['error'] = '(Eraro)';
                return $method;
            }
            $intermediary = $intermediary['b'];
            $method['intermediary']['codeholder'] = $intermediary;
            if ($method['intermediary']['paymentDescription']) {
                $method['intermediary']['desc_rendered'] = $this->app->bridge->renderMarkdown(
                    $method['intermediary']['paymentDescription'],
                    ['emphasis', 'strikethrough', 'link', 'list', 'table'],
                )['c'];
            }

            $isMember = $this->plugin->aksoUser ? $this->plugin->aksoUser['member'] : false;
            $method['intermediary_rendered'] = CodeholderLists::renderCodeholder($this->app->bridge, $intermediary, null, $isMember)->html();

            $scriptCtx->setFormVar('currency', $currency);

            if (count($org['years']) != 1) {
                $method['available'] = false;
                $method['error'] = $this->locale['payment_intermediary_err_multiple_years'];
                return $method;
            }
            $method['year'] = $org['years'][0];

            foreach ($this->offers as $offerYear) {
                if ($offerYear['paymentOrgId'] != $org['id']) {
                    continue;
                }
                if (isset($method['prices'][$offerYear['year']])) {
                    $method['available'] = true;
                    $method['offers_sum'] = 0;
                    $method['offers'] = [];

                    $regEntries = $method['prices'][$offerYear['year']]['registrationEntries'];
                    $categories = array();
                    $magazines = array();
                    foreach ($regEntries['membershipCategories'] as $category) {
                        $categories[$category['id']] = $category;
                    }
                    foreach ($regEntries['magazines'] as $magazine) {
                        $magazines[$magazine['id']] = $magazine;
                    }

                    if (isset($this->state['offers'][$offerYear['year']])) {
                        $offers = $this->state['offers'][$offerYear['year']];
                        foreach ($offers as $offer) {
                            $priceScript = null;
                            $priceDesc = null;
                            if ($offer['type'] === 'membership') {
                                if (isset($categories[$offer['id']])) {
                                    $priceScript = $categories[$offer['id']]['price'];
                                    $priceDesc = $categories[$offer['id']]['description'];
                                }
                            } else if ($offer['type'] === 'magazine') {
                                if (isset($magazines[$offer['id']])) {
                                    $priceScript = $magazines[$offer['id']]['prices'][$offer['paper'] ? 'paper' : 'access'];
                                }
                            }
                            if (!$priceScript) {
                                $method['available'] = false;
                                $method['error'] = $this->locale['payment_intermediary_offer_not_available'];
                                return $method;
                            }

                            if ($priceScript) {
                                $offerId = implode('-', [$offer['type'], $offer['id']]);
                                $method['offers'][$offerId] = [];
                                if (gettype($priceDesc) === 'string') {
                                    $method['offers'][$offerId]['price_desc'] = $this->app->bridge->renderMarkdown(
                                        $priceDesc,
                                        ['emphasis', 'strikethrough'],
                                    )['c'];
                                }

                                $scriptCtx->pushScript($priceScript['script']);
                                $result = $scriptCtx->eval(array(
                                    't' => 'c',
                                    'f' => 'id',
                                    'a' => [$priceScript['var']],
                                ));
                                $scriptCtx->popScript();

                                if ($result['s']) {
                                    // i dont think intermediaries need currency conversion?
                                    $convertedValue = $result['v'];
                                    $method['offers'][$offerId]['value'] = $convertedValue;
                                    $method['offers'][$offerId]['amount'] = $this->scriptCtxFmtCurrency($scriptCtx, $currency, $convertedValue);
                                    $method['offers_sum'] += $convertedValue;
                                } else {
                                    $method['offers'][$offerId]['value'] = null;
                                    $method['offers'][$offerId]['amount'] = '(Eraro)';
                                }
                            }
                        }
                    }

                    $method['total_sum'] = $method['offers_sum'];
                    $method['offers_sum_rendered'] = $this->scriptCtxFmtCurrency($scriptCtx, $currency, $method['offers_sum']);
                } else {
                    $method['available'] = false;
                    $method['error'] = $this->locale['payment_intermediary_year_not_available'];
                    return $method;
                }
            }

            if ($method['feePercent']) $method['fee_total'] += $method['offers_sum'] * $method['feePercent'] / 100;
        } else {
            $method['fee_total'] = 0;
            if ($method['feePercent']) $method['fee_total'] += $org['offers_sum'] * $method['feePercent'] / 100;
        }

        if ($method['fee_total']) {
            $method['fee_total_rendered'] = $this->scriptCtxFmtCurrency($scriptCtx, $currency, $method['fee_total']);
            $method['total_sum'] += $method['fee_total'];
        }

        $method['total_sum_rendered'] = $this->scriptCtxFmtCurrency($scriptCtx, $currency, $method['total_sum']);

        $totalUsd = $this->convertCurrency($currency, 'USD', $method['total_sum']);
        $methodMaxAmount = 50000000; // (default to hard limit)
        if ($method['maxAmount']) {
            $methodMaxAmount = $method['maxAmount'];
        }
        $method['above_max_amount'] = $totalUsd > $methodMaxAmount;

        return $method;
    }

    private function createEntries($years, $priceOverrides = null) {
        $errors = [];
        foreach ($years as $year) {
            if (isset($this->state['locked_offers'][$year])) continue;
            $yearItems = [];
            if (isset($this->state['offers'][$year])) {
                $yearItems = &$this->state['offers'][$year];
            }
            $options = [];
            $options['year'] = (int) $year;
            $options['currency'] = $this->state['currency'];

            if ($this->plugin->aksoUser) {
                $options['codeholderData'] = $this->plugin->aksoUser['id'];
            } else {
                $options['codeholderData'] = $this->state['codeholder'];
                foreach (array_keys($options['codeholderData']['address']) as $k) {
                    if (empty($options['codeholderData']['address'][$k])) {
                        unset($options['codeholderData']['address'][$k]);
                    }
                }
                foreach (array_keys($options['codeholderData']) as $k) {
                    // codeholder fields generally default to null instead of empty-string
                    if (gettype($options['codeholderData'][$k]) === 'string' && empty($options['codeholderData'][$k])) {
                        $options['codeholderData'][$k] = null;
                    }
                }
            }

            $options['offers'] = [];
            $addons = [];
            foreach ($yearItems as $itemId => $itemData) {
                $itemIdParts = explode('-', $itemId);
                $groupIndex = $itemIdParts[0];
                $offerIndex = $itemIdParts[1];

                $data = array(
                    'type' => $itemData['type'],
                    'id' => $itemData['id'],
                    'amount' => $itemData['amount'],
                );
                if ($itemData['type'] === 'magazine') {
                    $data['paperVersion'] = $itemData['paper'];
                }
                if ($priceOverrides) {
                    $data['amount'] = $priceOverrides[implode('-', [$data['type'], $data['id']])]['value'];
                }

                if ($itemData['type'] === 'addon') {
                    $addons[] = $data;
                } else {
                    $options['offers'][] = $data;

                    if ($itemData['amount_addon']) {
                        $addons[] = array(
                            'type' => $itemData['type'],
                            'id' => $itemData['id'],
                            'amount' => $itemData['amount_addon'],
                        );
                    }
                }
            }

            if (empty($options['offers'])) {
                $this->state['addons'][$year] = $addons;
                continue;
            }
            $res = $this->app->bridge->post('/registration/entries', $options, [], []);
            if ($res['k']) {
                $this->state['dataIds'][$year] = $res['h']['x-identifier'];
                $this->state['addons'][$year] = $addons;
            } else if (!$res['k']) {
                if ($res['sc'] === 400) $errors[$year] = $this->localize('create_entry_bad_request');
                else $errors[$year] = $this->localize('create_entry_internal_error');
            }
        }

        if (count($errors) > 0) {
            $this->state['form_error'] = '';
            foreach ($errors as $year => $err) {
                $this->state['form_error'] .= '<div>' .
                    $this->localize('create_entry_year_failed', $year) .
                    htmlspecialchars($err) . '</div>';
            }
        } else {
            return true;
        }
    }

    private function createIntent($paymentOrg, $paymentMethodId, $currency) {
        if ($this->state['needs_login']) {
            $this->state['form_error'] = $this->localize('payment_error_needs_login');
            return;
        }

        if (!isset($this->paymentOrgs[$paymentOrg]) || !isset($this->paymentOrgs[$paymentOrg]['methods'][$paymentMethodId])) {
            // invalid
            // TODO: handle error?
            return;
        }
        $org = &$this->paymentOrgs[$paymentOrg];
        $method = $org['methods'][$paymentMethodId];
        $priceOverrides = null;
        if ($method['type'] === 'intermediary') $priceOverrides = $method['offers'];

        // TODO: validate method id (esp. internal) and currency, maybe?

        if (!$this->createEntries($org['years'], $priceOverrides)) {
            return;
        }

        $codeholderId = null;
        $customerName = '';
        if ($this->plugin->aksoUser) {
            $codeholderId = $this->plugin->aksoUser['id'];
            $customerName = $this->plugin->aksoUserFormattedName;
        } else {
            $customerName = [
                $this->state['codeholder']['honorific'],
                $this->state['codeholder']['firstNameLegal'],
                $this->state['codeholder']['lastNameLegal'],
            ];
            $customerName = implode(' ', array_filter($customerName, function ($v) {
                return !empty($v);
            }));
        }

        $purposes = [];
        $addonPurposes = [];
        $categories = $this->loadAllCategories($this->getRegisteredOfferCategoryIds($this->state['offers']));
        $magazines = $this->loadAllMagazines($this->getRegisteredOfferMagazineIds($this->state['offers']));
        foreach ($org['years'] as $year) {
            $purposeTitle = $this->locale['payment_purpose_title_singular'] . ' ' . $org['years'][0];
            $purposeDescription = '';

            if (!isset($this->state['offers'][$year])) continue;
            if (isset($this->state['year_statuses'][$year]) && $this->state['year_statuses'][$year] !== 'submitted') continue;
            $yearItems = $this->state['offers'][$year];
            $sum = 0;
            $originalAmountSum = 0;
            foreach ($yearItems as $offer) {
                if ($offer['type'] === 'membership' || $offer['type'] === 'magazine') {
                    if ($purposeDescription) $purposeDescription .= "\n";
                    $purposeDescription .= '- '; // render a list
                    if ($offer['type'] === 'membership') {
                        if (isset($categories[$offer['id']])) {
                            $cat = $categories[$offer['id']];
                            $purposeDescription .= $cat['nameAbbrev'] . ' ' . $cat['name'];
                        } else {
                            $purposeDescription .= '(Eraro)';
                        }
                    } else if ($offer['type'] === 'magazine') {
                        if (isset($magazines[$offer['id']])) {
                            $purposeDescription .= $this->localize('payment_label_magazine') . ' ' . $magazines[$offer['id']]['name'];
                        } else {
                            $purposeDescription .= '(Eraro)';
                        }
                    }

                    if ($priceOverrides) {
                        $value = $priceOverrides[implode('-', [$offer['type'], $offer['id']])]['value'];
                        $sum += $value;
                        $originalAmountSum += $value;
                    } else {
                        $sum += $offer['amount'];
                        $originalAmountSum += $offer['amount_original'];
                    }

                    if ($offer['amount_addon'] > 0) {
                        $purposeDescription .= "\n    - ";
                        $purposeDescription .= $this->localize('offers_price_addon_label');
                        $purposeDescription .= ': ';
                        $purposeDescription .= $offer['amount_addon_rendered'];
                    }
                } else if ($offer['type'] === 'addon') {
                    $addonAmount = $this->convertCurrency($this->state['currency'], $currency, $offer['amount']);
                    $addonPurposes[] = array(
                        'type' => 'addon',
                        'paymentAddonId' => $offer['id'],
                        'amount' => $addonAmount,
                    );
                } else {
                    $purposeDescription .= '(Eraro)';
                }
            }

            if (!$sum) continue;

            $convertedAmount = $this->convertCurrency($this->state['currency'], $currency, $sum);
            $originalAmount = null;
            if ($sum !== $originalAmountSum) {
                $originalAmount = $this->convertCurrency($this->state['currency'], $currency, $originalAmountSum);
            }

            $purposes[] = array(
                'type' => 'trigger',
                'title' => $purposeTitle,
                'description' => $purposeDescription,
                'amount' => $convertedAmount,
                'originalAmount' => $originalAmount,
                'triggerAmount' => array(
                    'currency' => $this->state['currency'],
                    'amount' => $sum,
                ),
                'triggers' => 'registration_entry',
                'registrationEntryId' => Utils::base32_decode($this->state['dataIds'][$year]),
            );
        }

        $intentData = array(
            'codeholderId' => $codeholderId,
            'customer' => array(
                'name' => $customerName,
                'email' => $this->state['codeholder']['email'],
            ),
            'paymentOrgId' => $paymentOrg,
            'paymentMethodId' => $paymentMethodId,
            'currency' => $currency,
            'customerNotes' => null,
            'purposes' => array_merge($purposes, $addonPurposes),
        );

        if ($method['type'] === 'intermediary') {
            $country = $this->state['codeholder']['feeCountry'];
            $year = $org['years'][0];
            $latestIntent = $this->app->bridge->get('/aksopay/payment_intents', array(
                'filter' => array(
                    'intermediaryCountryCode' => $country,
                    'intermediaryIdentifier.year' => $year,
                ),
                'fields' => ['intermediaryIdentifier.number'],
                'order' => [['timeCreated', 'desc']],
                'limit' => 1,
            ));
            if (!$latestIntent['k']) {
                // TODO: error
                return;
            }
            $number = 1;
            if (!empty($latestIntent['b'])) $number = $latestIntent['b'][0]['intermediaryIdentifier']['number'] + 1;
            $intentData['intermediaryIdentifier'] = array(
                'number' => $number,
                'year' => $year,
            );
            $intentData['intermediaryCountryCode'] = $country;
        }

        $res = $this->app->bridge->post('/aksopay/payment_intents', $intentData, array(), []);

        if ($res['k']) {
            $paymentId = $res['h']['x-identifier'];

            $returnTarget = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") .
                "://$_SERVER[HTTP_HOST]" . $this->getEditTarget();

            if ($method['type'] === 'intermediary') {
                $this->plugin->getGrav()->redirectLangSafe($returnTarget, 303);
            } else {
                $paymentsHost = $this->plugin->getGrav()['config']->get('plugins.akso-bridge.payments_host');
                $redirectTarget = $paymentsHost . '/i/' . $paymentId . '?return=' . urlencode($returnTarget);
                $this->plugin->getGrav()->redirectLangSafe($redirectTarget, 303);
            }
        } else {
            if ($res['sc'] === 400) $this->state['form_error'] = $this->locale['payment_error_bad_request'];
            else if ($res['sc'] === 417) $this->state['form_error'] = $this->locale['payment_error_too_high'];
            else if ($res['sc'] === 500) $this->state['form_error'] = $this->locale['payment_error_server_error'];
            else $this->state['form_error'] = $this->locale['payment_error_generic'];
        }
    }

    public function update() {
        $this->state = array(
            'step' => 0,
            'needs_login' => false,
            'codeholder' => [],
            'offers' => [],
            'locked_offers' => [],
            'addons' => [],
        );
        $this->readFormState();

        if (count($this->state['dataIds']) > 0) {
            // there are submitted entries!
            $indexedMembershipAddons = [];
            $addons = [];
            if (isset($_GET[self::ADDONS]) && gettype($_GET[self::ADDONS]) === 'string') {
                $yearStrings = explode('~', $_GET[self::ADDONS]);
                foreach ($yearStrings as $yearString) {
                    // + turns into spaces
                    $parts = explode(' ', $yearString);
                    if (count($parts) < 2) continue;
                    $year = (int) $parts[0];
                    $yearItems = [];
                    $membershipYearItems = [];
                    foreach (array_slice($parts, 1) as $addonString) {
                        $addonData = explode('-', $addonString);
                        if (count($addonData) != 2) continue;
                        $addonType = substr($addonData[0], 0, 1);
                        $addonId = (int) substr($addonData[0], 1);
                        $addonAmount = (int) $addonData[1];
                        if ($addonType === 'a') {
                            $yearItems[] = array('type' => 'addon', 'id' => $addonId, 'amount' => $addonAmount);
                        } else if ($addonType === 'm') {
                            $membershipYearItems[$addonId] = array('type' => 'membership', 'id' => $addonId, 'amount' => $addonAmount);
                        } else if ($addonType === 'r') {
                            $membershipYearItems[$addonId] = array('type' => 'magazine', 'id' => $addonId, 'amount' => $addonAmount);
                        }
                    }
                    $addons[$year] = $yearItems;
                    $indexedMembershipAddons[$year] = $membershipYearItems;
                }
            }

            $res = $this->loadRegistered($this->state['dataIds'], $addons, $indexedMembershipAddons);
            $this->state['codeholder'] = $res['codeholder'];
            $this->state['currency'] = $res['currency'];
            $this->state['needs_login'] = $res['needs_login'];
            $this->state['dataIds'] = $res['dataIds'];
            $this->state['year_statuses'] = $res['year_statuses'];
            $this->state['locked_offers'] = $res['offers'];
        }

        $this->updateCodeholderState();
        $this->updateCurrencyState();
        $this->updateOffersState();

        if ($this->state['step'] > 0) {
            $err = $this->getThisCodeholderError();

            if ($err) {
                $this->state['form_error'] = $err;
                $this->state['step'] = 0;
            } else if ($this->state['step'] > 1) {
                $err = $this->getOfferError();

                if ($err) {
                    $this->state['form_error'] = $err;
                    $this->state['step'] = 1;
                }
            }
        }

        $this->updatePaymentsState();

        $serializedState = array(
            'currency' => $this->state['currency'],
            'codeholder' => $this->state['codeholder'],
            'offers' => $this->state['offers'],
            'dataIds' => $this->state['dataIds'],
        );
        $this->state['serialized'] = json_encode($serializedState);
        $_SESSION[self::SESSION_KEY_NAME] = $serializedState;
    }

    private function getThisCodeholderError() {
        $disErr = $this->getDisabledError();
        if ($disErr) return $disErr;

        if ($this->state['needs_login']) return null;
        $ch = $this->state['codeholder'];
        if (isset($ch['locked'])) return null;

        return self::getCodeholderError($this->app->bridge, $this->plugin->locale, $ch, false, $this->plugin->aksoUser != null, $this->isDonation);
    }

    // Returns a best-effort error message for the codeholder data.
    public static function getCodeholderError($bridge, $locale, $ch, $isOrg = false, $isNewUser = false, $isDonation = false) {
        if (!$isOrg && empty(trim($ch['firstNameLegal']))) {
            return $locale['registration']['codeholder_error_name_required'];
        } else if ($isOrg && (empty(trim($ch['fullName'])) || empty(trim($ch['fullNameLocal'])))) {
            return $locale['registration']['codeholder_error_name_required'];
        }

        // HTML (and later, AKSO API) will take care of validating email

        if ($isDonation) return null;

        if (!$isOrg) {
            $birthdate = \DateTime::createFromFormat('Y-m-d', $ch['birthdate']);
            $now = new \DateTime();
            if (!$birthdate) {
                return $locale['registration']['codeholder_error_birthdate_required'];
            }
            if ($birthdate->diff($now)->invert) {
                // this is a future date
                return $locale['registration']['codeholder_error_invalid_birthdate'];
            }
        }
        // no need to check if countries are in the country set because it's a <select>

        // validate phone numbers
        if (isset($ch['cellphone'])) {
            $phone = $ch['cellphone'];
            if ($phone) {
                if (!preg_match('/^\+[a-z0-9]{1,49}$/u', $phone)) {
                    return $locale['registration']['codeholder_error_invalid_phone_format_cell'];
                }
            }
        }
        $phone = $ch['officePhone'];
        if ($phone) {
            if (!preg_match('/^\+[a-z0-9]{1,49}$/u', $phone)) {
                return $locale['registration']['codeholder_error_invalid_phone_format_office'];
            }
        }
        if (isset($ch['landlinePhone'])) {
            $phone = $ch['landlinePhone'];
            if ($phone) {
                if (!preg_match('/^\+[a-z0-9]{1,49}$/u', $phone)) {
                    return $locale['registration']['codeholder_error_invalid_phone_format_landline'];
                }
            }
        }

        if (!$ch['feeCountry']) {
            if ($isNewUser) {
                return $locale['registration']['codeholder_error_no_fee_country'];
            } else {
                return $locale['registration']['codeholder_error_new_user_no_fee_country'];
            }
        }

        // validate address
        $addr = $ch['address'];
        $addr['countryCode'] = $ch['address']['country'];
        if (!$bridge->validateAddress($addr)) {
            // TODO: more granular validation?
            return $locale['registration']['codeholder_error_invalid_address'];
        }

        return null;
    }

    private function getOfferError() {
        $categories = $this->loadAllCategories($this->getOfferCategoryIds($this->offers));
        $magazines = $this->loadAllMagazines($this->getOfferMagazineIds($this->offers));
        $addons = $this->loadAllAddons($this->getOfferAddonIds($this->offers));

        $membershipLikeCount = 0;
        $addonCount = 0;
        foreach ($this->state['offers'] as $year => $yearItems) {
            if (isset($this->state['locked_offers'][$year])) {
                $membershipLikeCount++;
                continue;
            }
            if (!isset($this->offersByYear[$year])) return $this->localize('offers_error_inconsistent');
            $offerYear = $this->offersByYear[$year];

            foreach ($yearItems as $offerKey => $offer) {
                $keyParts = explode('-', $offerKey);
                $groupIndex = $keyParts[0];
                $offerIndex = $keyParts[1];

                if (!isset($offerYear['offers'][$groupIndex])) return $this->localize('offers_error_inconsistent');
                $group = $offerYear['offers'][$groupIndex];
                if (!isset($group['offers'][$offerIndex])) return $this->localize('offers_error_inconsistent');
                $originalOffer = $group['offers'][$offerIndex];
                if ($originalOffer['type'] !== $offer['type']) return $this->localize('offers_error_inconsistent');
                if ($originalOffer['id'] !== $offer['id']) return $this->localize('offers_error_inconsistent');
                if ($offer['type'] === 'magazine') {
                    if ($originalOffer['paperVersion'] !== $offer['paper']) return $this->localize('offers_error_inconsistent');
                }

                $minPrice = 1;
                $offerName = '';
                if ($offer['type'] === 'membership') {
                    if (!$originalOffer['price']) return $this->localize('offers_error_inconsistent');
                    $minPrice = $originalOffer['price']['amount'];
                    $offerName = $categories[$offer['id']]['name'];
                    $membershipLikeCount++;
                } else if ($offer['type'] === 'addon') {
                    $offerName = $addons[$offerYear['paymentOrgId']][$offer['id']]['name'];
                    $addonCount++;
                } else if ($offer['type'] === 'magazine') {
                    $offerName = $magazines[$offer['id']]['name'];
                    $membershipLikeCount++;
                } else {
                    return $this->localize('offers_error_inconsistent');
                }

                if ($offer['amount'] < $minPrice) {
                    return $this->localize('offers_error_min_price', $offerName);
                }
            }
        }

        if ($membershipLikeCount == 0 && !$this->isDonation) {
            return $this->localize('offers_error_no_membership_like');
        }
        if ($addonCount == 0 && $this->isDonation) {
            return $this->localize('offers_error_donating_no_addon');
        }
    }

    public function run() {
        $this->update();

        $path = $this->plugin->getGrav()['uri']->path();
        $targets = [
            'codeholder' => $path,
            'offers' => $path . '?' . self::STEP . '=' . self::STEP_OFFERS,
            'summary' => $path . '?' . self::STEP . '=' . self::STEP_SUMMARY,
            'entry_create' => $path . '?' . self::STEP . '=' . self::STEP_ENTRY_CREATE,
        ];

        $offers = $this->offers;
        $offersIndexed = $this->offersByYear;
        $categories = [];
        $magazines = [];
        $addons = [];
        if ($this->offers) {
            $categories = $this->loadAllCategories($this->getOfferCategoryIds($this->offers));
            $magazines = $this->loadAllMagazines($this->getOfferMagazineIds($this->offers));
            $addons = $this->loadAllAddons($this->getOfferAddonIds($this->offers));
        }

        $thisYear = (int) (new \DateTime())->format('Y');

        return array(
            'disabled' => $this->getDisabledError(),
            'countries' => $this->getCachedCountries(),
            'currencies' => $this->getCachedCurrencies(),
            'state' => $this->state,
            'offers' => $offers,
            'offers_indexed' => $offersIndexed,
            'categories' => $categories,
            'magazines' => $magazines,
            'addons' => $addons,
            'targets' => $targets,
            'thisYear' => $thisYear,
            'payment_orgs' => $this->paymentOrgs,
            'is_donation' => $this->isDonation,
        );
    }

    static function floatval($n) {
        if (gettype($n) === 'float' || gettype($n) === 'integer') return $n;
        if (gettype($n) === 'string') {
            if (empty($n)) return 0.0;
            return floatval(str_replace(',', '.', $n));
        }
        return 0.0;
    }

    private function getDefaultFeeCountryCurrency($country) {
        $country = strtolower($country);
        $currencies = $this->getCachedCurrencies();
        if ($country == 'no') $country = 'no_';
        $currency = $this->plugin->country_currencies[$country] ?? '';
        if (isset($currencies[$currency])) return $currency;
        return 'EUR'; // hard-coded default
    }
}
