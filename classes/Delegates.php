<?php
namespace Grav\Plugin\AksoBridge;

use Grav\Plugin\AksoBridgePlugin;
use Grav\Plugin\AksoBridge\MarkdownExt;
use Grav\Plugin\AksoBridge\Utils;

class Delegates {
    const COUNTRY_NAME = 'lando';
    const SUBJECT_ID = 'fako';
    const SUBJECT_NAME = 'q';
    const CODEHOLDER_NAME = 'nomo';
    const VIEW_ALL = '*';
    const PAGE = 'p';

    private $plugin, $bridge, $user;

    public function __construct($plugin, $bridge) {
        $this->plugin = $plugin;
        $this->bridge = $bridge;
        $this->user = $plugin->aksoUser ? $plugin->bridge : null;
    }

    // TODO: deduplicate with country lists...
    function getAutoCountry($availableCountries) {
        if ($this->plugin->aksoUser) {
            $id = $this->plugin->aksoUser['id'];
            $res = $this->bridge->get("/codeholders/$id", array('fields' => ['address.country']));
            if ($res['k']) {
                $country = $res['b']['address']['country'];
                if (in_array($country, $availableCountries)) return $country;
            }
        }

        return self::VIEW_ALL;
    }

    private $countryNames = [];
    function getCountryNames() {
        if (empty($this->countryNames)) {
            $res = $this->bridge->get('/countries', array(
                'limit' => 300,
                'fields' => ['name_eo', 'code'],
                'order' => [['name_eo', 'asc']]
            ), 300);
            if (!$res['k']) throw new \Exception('could not fetch countries');
            $this->countryNames = [];
            foreach ($res['b'] as $item) {
                $this->countryNames[$item['code']] = $item['name_eo'];
            }
        }
        return $this->countryNames;
    }

    const DELEGATE_FIELDS = [
        'codeholderId',
        'subjects',
        'cities',
        'cityCountries',
        'countries',
        'hosting.maxDays',
        'hosting.maxPersons',
        'hosting.description',
        'hosting.psProfileURL',
    ];

    public function run() {
        $org = $this->plugin->getGrav()['page']->header()->org;
        $page = 0;
        if (isset($_GET[self::PAGE]) && gettype($_GET[self::PAGE]) == 'string') {
            $page = ((int) $_GET[self::PAGE]) - 1;
        }

        $countryNames = $this->getCountryNames();
        $countryCodes = array_keys($countryNames);
        $collator = new \Collator('fr_FR');
        usort($countryCodes, function ($a, $b) use ($countryNames, $collator) {
            $ca = $countryNames[$a];
            $cb = $countryNames[$b];
            return $collator->compare($ca, $cb);
        });

        $viewMode = 'country';
        if (isset($_GET[self::CODEHOLDER_NAME])) {
            $viewMode = 'codeholder';
        } else if (isset($_GET[self::SUBJECT_ID])) {
            $viewMode = 'subject';
        }

        $viewModeLinks = array(
            'country' => $this->plugin->getGrav()['uri']->path() . '?' . self::COUNTRY_NAME . '=' . self::VIEW_ALL,
            'subject' => $this->plugin->getGrav()['uri']->path() . '?' . self::SUBJECT_ID . '=' . self::VIEW_ALL,
            'codeholder' => $this->plugin->getGrav()['uri']->path() . '?' . self::CODEHOLDER_NAME . '=' . self::VIEW_ALL,
        );

        $common = array(
            'view_mode' => $viewMode,
            'view_mode_links' => $viewModeLinks,
            'filter_subject_link' => $this->plugin->getGrav()['uri']->path() . '?' . self::SUBJECT_ID . '=',
        );
        $itemsPerPage = 100;

        if ($viewMode === 'country') {
            $viewCountry = isset($_GET[self::COUNTRY_NAME]) ? $_GET[self::COUNTRY_NAME] : null;
            if (!in_array($viewCountry, $countryCodes)) {
                $viewCountry = $this->getAutoCountry($countryCodes);
            }

            $countryEmoji = [];
            $countryLinks = [];
            foreach ($countryCodes as $code) {
                $countryLinks[$code] = $this->plugin->getGrav()['uri']->path() . '?' . self::COUNTRY_NAME . '=' . $code
                    . '#landoj';
                $countryEmoji[$code] = MarkdownExt::getEmojiForFlag($code);
            }

            if ($viewCountry == self::VIEW_ALL) {
                return array(
                    'common' => $common,
                    'country_names' => $countryNames,
                    'country_emoji' => $countryEmoji,
                    'view_mode' => 'overview',
                    'list_country_codes' => $countryCodes,
                    'list_country_links' => $countryLinks,
                );
            } else if ($viewCountry != self::VIEW_ALL) {
                $filter = array(
                    'org' => $org,
                    '$or' => [
                        array('cityCountries' => array('$hasAny' => $viewCountry)),
                        array('$countries' => array('country' => $viewCountry)),
                    ],
                );
                $res = $this->bridge->get("/delegations/delegates", array(
                    'fields' => self::DELEGATE_FIELDS,
                    'filter' => $filter,
                    'offset' => $page * $itemsPerPage,
                    'limit' => $itemsPerPage,
                ), 60);
                if (!$res['k']) {
                    if ($res['sc'] === 404) {
                        $this->plugin->getGrav()->fireEvent('onPageNotFound');
                        return;
                    } else {
                        throw new \Exception("Failed to load delegates" . $res['b']);
                    }
                }
                $totalDelegates = $res['h']['x-total-items'];
                $delegates = [];
                foreach ($res['b'] as $delegate) {
                    $delegates[$delegate['codeholderId']] = $delegate;
                }

                $countryDelegates = [];
                $cityDelegates = [];
                foreach ($delegates as $k => $delegate) {
                    $countries = array_map(function ($c) { return $c['country']; }, $delegate['countries']);
                    if (in_array($viewCountry, $countries)) {
                        $countryDelegates[] = $delegate['codeholderId'];
                        $countryLevel = -1;
                        foreach ($delegate['countries'] as $country) {
                            if ($country['country'] == $viewCountry) {
                                $countryLevel = $country['level'];
                                break;
                            }
                        }
                        $delegate['country_level'] = $countryLevel;
                        $delegates[$k] = $delegate;
                    }
                    if (in_array($viewCountry, $delegate['cityCountries'])) {
                        $cityDelegates[] = $delegate['codeholderId'];
                    }
                }

                if (!in_array($viewCountry, $countryCodes)) {
                    $this->plugin->getGrav()->fireEvent('onPageNotFound');
                    return;
                }

                $subjectIds = [];
                foreach ($delegates as $item) {
                    foreach ($item['subjects'] as $subjectId) {
                        if (!in_array($subjectId, $subjectIds)) $subjectIds[] = $subjectId;
                    }
                }
                $subjects = $this->getSubjects($subjectIds);

                $codeholderIds = [];
                foreach ($delegates as $item) {
                    $codeholderId = $item['codeholderId'];
                    if (!in_array($codeholderId, $codeholderIds)) $codeholderIds[] = $codeholderId;
                }
                $codeholders = $this->getCodeholders($codeholderIds);

                $delegatesByLevel = [];
                foreach ($countryDelegates as $delegateId) {
                    $delegate = $delegates[$delegateId];
                    $level = $delegate['country_level'];
                    if (!isset($delegatesByLevel[$level])) $delegatesByLevel[$level] = [];
                    $delegatesByLevel[$level][] = $delegate;
                }

                $delegatesByCity = [];
                foreach ($cityDelegates as $delegateId) {
                    $delegate = $delegates[$delegateId];
                    foreach ($delegate['cities'] as $city) {
                        if (!isset($delegatesByCity[$city])) $delegatesByCity[$city] = [];
                        $delegatesByCity[$city][] = $delegate;
                    }
                }
                $cities = $this->getCities(array_keys($delegatesByCity), $viewCountry);
                usort($cities, function ($a, $b) {
                    return strcmp($a['eoLabel'], $b['eoLabel']);
                });

                $pageLinkFirst = $this->plugin->getGrav()['uri']->path() . '?'
                    . self::COUNTRY_NAME . '=' . $viewCountry;
                $pageLinkStub = $pageLinkFirst . '&' . self::PAGE . '=';

                return array(
                    'common' => $common,
                    'country_names' => $countryNames,
                    'country_emoji' => $countryEmoji,
                    'view_mode' => 'country',
                    'view' => $viewCountry,
                    'list_country_codes' => $countryCodes,
                    'list_country_links' => $countryLinks,
                    'page' => $page,
                    'codeholders' => $codeholders,
                    'subjects' => $subjects,
                    'cities' => $cities,
                    'total_delegates' => $totalDelegates,
                    'delegates' => $delegates,
                    'delegates_by_city' => $delegatesByCity,
                    'delegates_by_level' => $delegatesByLevel,
                    'should_paginate' => $totalDelegates > $itemsPerPage,
                    'max_page' => ceil((float) $totalDelegates / $itemsPerPage),
                    'page_link_first' => $pageLinkFirst,
                    'page_link_stub' => $pageLinkStub,
                );
            }
        } else if ($viewMode == 'subject') {
            $form_target = $this->plugin->getGrav()['uri']->path();
            $subjectId = gettype($_GET[self::SUBJECT_ID]) == 'string' ? $_GET[self::SUBJECT_ID] : self::VIEW_ALL;
            $search_query = isset($_GET[self::SUBJECT_NAME]) && gettype($_GET[self::SUBJECT_NAME]) == 'string'
                ? $_GET[self::SUBJECT_NAME]
                : '';

            $subjectFilter = null;
            if (!empty($search_query)) {
                // TODO: sort by popularity
                $res = $this->bridge->get('/delegations/subjects', array(
                    'fields' => ['id', 'name', 'description'],
                    'search' => array('cols' => ['name'], 'str' => $search_query),
                    'limit' => $itemsPerPage,
                ));
                if (!$res['k']) throw new \Exception('failed to search subjects');
                $subjectResults = $res['b'];
                $subjectFilter = array_map(function ($sub) { return $sub['id']; }, $subjectResults);
            } else if ($subjectId != self::VIEW_ALL) {
                $subjectFilter = array_map(function ($id) { return (int) $id; }, explode(',', $subjectId));
            }

            $shouldPaginate = false;
            $maxPage = 0;
            $delegations = [];
            $subjects = [];
            $codeholders = [];

            if ($subjectFilter) {
                $res = $this->bridge->get('/delegations/delegates', array(
                    'fields' => self::DELEGATE_FIELDS,
                    'filter' => array('subjects' => array('$hasAny' => $subjectFilter)),
                    'offset' => $page * $itemsPerPage,
                    'limit' => $itemsPerPage,
                ));
                if (!$res['k']) throw new \Exception('failed to load delegates');
                $shouldPaginate = $res['h']['x-total-items'] > $itemsPerPage;
                $maxPage = ceil((float) $res['h']['x-total-items'] / $itemsPerPage);
                $delegations = [];
                $subjectIds = [];
                foreach ($res['b'] as $delegation) {
                    $delegations[] = $delegation;
                    foreach ($delegation['subjects'] as $id) if (!in_array($id, $subjectIds)) $subjectIds[] = $id;
                }
                $subjects = $this->getSubjects($subjectIds);
                $codeholders = $this->getCodeholders(array_map(function ($d) { return $d['codeholderId']; }, $delegations));
            }

            return array(
                'common' => $common,
                'form_target' => $form_target,
                'search_query' => $search_query,
                'delegations' => $delegations,
                'codeholders' => $codeholders,
                'subjects' => $subjects,
                'page' => $page,
                'max_page' => $maxPage,
                'should_paginate' => $shouldPaginate,
            );
        } else if ($viewMode == 'codeholder') {
            $form_target = $this->plugin->getGrav()['uri']->path();
            $search_query = gettype($_GET[self::CODEHOLDER_NAME]) == 'string'
                ? $_GET[self::CODEHOLDER_NAME]
                : self::VIEW_ALL;
            if (empty($search_query) || $search_query == self::VIEW_ALL) {
                return array(
                    'common' => $common,
                    'form_target' => $form_target,
                    'search_query' => '',
                );
            }

            $res = $this->bridge->get('/codeholders', array(
                'search' => array('cols' => ['searchName'], 'str' => $search_query . '*'),
                'filter' => array('$delegations' => array('org' => $org)),
                'offset' => $page * $itemsPerPage,
                'fields' => self::CODEHOLDER_FIELDS,
                'limit' => $itemsPerPage,
            ));
            if (!$res['k']) throw new \Exception('failed to load codeholders');
            $codeholders = $res['b'];

            $shouldPaginate = $res['h']['x-total-items'] > $itemsPerPage;
            $maxPage = ceil((float) $res['h']['x-total-items'] / $itemsPerPage);

            $res = $this->bridge->get('/delegations/delegates', array(
                'fields' => self::DELEGATE_FIELDS,
                'filter' => array('codeholderId' => array(
                    '$in' => array_map(function ($ch) { return $ch['id']; }, $codeholders),
                )),
                'limit' => max(count($codeholders), 1),
            ));
            if (!$res['k']) throw new \Exception('failed to load delegates');
            $delegations = [];
            $subjectIds = [];
            foreach ($res['b'] as $delegation) {
                $delegations[$delegation['codeholderId']] = $delegation;
                foreach ($delegation['subjects'] as $id) if (!in_array($id, $subjectIds)) $subjectIds[] = $id;
            }
            $codeholdersById = [];
            $orderedDelegations = [];
            foreach ($codeholders as $codeholder) {
                // use "relevance" sorting returned by codeholder search
                $orderedDelegations[] = $delegations[$codeholder['id']];
                $codeholdersById[$codeholder['id']] = $this->postProcessCodeholder($codeholder);
            }

            $subjects = $this->getSubjects($subjectIds);

            return array(
                'common' => $common,
                'form_target' => $form_target,
                'search_query' => $search_query,
                'delegations' => $orderedDelegations,
                'codeholders' => $codeholdersById,
                'subjects' => $subjects,
                'page' => $page,
                'max_page' => $maxPage,
                'should_paginate' => $shouldPaginate,
            );
        }
    }

    function getCities($cityIds, $country) {
        $cities = [];
        for ($i = 0; $i < count($cityIds); $i += 100) {
            $batch = array_map(function ($id) { return (int) substr($id, 1); }, array_slice($cityIds, $i, 100));

            $res = $this->bridge->get("/geodb/cities", array(
                'fields' => ['id', 'nativeLabel', 'eoLabel', 'subdivision_nativeLabel', 'subdivision_eoLabel', 'population'],
                'filter' => ['id' => ['$in' => $batch], 'country' => $country],
                'offset' => 0,
                'limit' => 100,
            ), 120);

            if (!$res['k']) {
                throw new \Exception("Failed to fetch subjects");
            }
            foreach ($res['b'] as $city) {
                $city['label'] = $city['eoLabel'] ?: $city['nativeLabel'];
                $city['subdivision'] = $city['subdivision_eoLabel'] ?: $city['subdivision_nativeLabel'];
                $cities[$city['id']] = $city;
            }
        }
        return $cities;
    }

    function getSubjects($subjectIds) {
        $subjects = [];
        for ($i = 0; $i < count($subjectIds); $i += 100) {
            $batch = array_slice($subjectIds, $i, 100);

            $res = $this->bridge->get("/delegations/subjects", array(
                'fields' => ['id', 'name', 'description'],
                'filter' => ['id' => ['$in' => $batch]],
                'offset' => 0,
                'limit' => 100,
            ), 120);

            if (!$res['k']) {
                throw new \Exception("Failed to fetch subjects");
            }
            foreach ($res['b'] as $subject) {
                $subjects[$subject['id']] = $subject;
            }
        }
        return $subjects;
    }

    const CODEHOLDER_FIELDS = [
        'id',
        'codeholderType',
        'firstNameLegal', 'lastNameLegal', 'firstName', 'lastName', 'honorific',
        'lastNamePublicity',
        'mainDescriptor', 'website', 'factoids', 'biography', 'publicEmail',
        'email', 'emailPublicity', 'officePhone', 'officePhonePublicity',
        'address.country', 'address.countryArea', 'address.city', 'address.cityArea',
        'address.postalCode', 'address.sortingCode', 'address.streetAddress',
        'addressPublicity',
        'profilePictureHash', 'profilePicturePublicity',
    ];

    function getCodeholders($codeholderIds) {
        $codeholders = [];
        for ($i = 0; $i < count($codeholderIds); $i += 100) {
            $batch = array_slice($codeholderIds, $i, 100);

            $res = $this->bridge->get("/codeholders", array(
                'fields' => self::CODEHOLDER_FIELDS,
                'filter' => ['id' => ['$in' => $batch]],
                'offset' => 0,
                'limit' => 100,
            ), 120);

            if (!$res['k']) {
                throw new \Exception("Failed to fetch codeholders");
            }
            $isMember = $this->plugin->aksoUser ? $this->plugin->aksoUser['member'] : false;
            foreach ($res['b'] as $codeholder) {
                $codeholders[$codeholder['id']] = $this->postProcessCodeholder($codeholder);
            }
        }
        return $codeholders;
    }

    // FIXME: deduplicate code with countrylists
    private function postProcessCodeholder($codeholder) {
        // TODO: deduplicate this code too..
        if ($codeholder['codeholderType'] === 'human') {
            $canSeeLastName = $codeholder['lastNamePublicity'] === 'public'
                || ($codeholder['lastNamePublicity'] === 'members' && $isMember);

            $codeholder['fmt_name'] = implode(' ', array_filter([
                $codeholder['honorific'],
                $codeholder['firstName'] ?: $codeholder['firstNameLegal'],
                $canSeeLastName ? ($codeholder['lastName'] ?: $codeholder['lastNameLegal']) : null,
            ]));
        } else if ($codeholder['codeholderType'] === 'org') {
            $codeholder['fmt_name'] = $codeholder['fullName'];
            if ($codeholder['nameAbbrev']) {
                $codeholder['fmt_name'] .= ' (' . $codeholder['nameAbbrev'] . ')';
            }
        }
        if ($codeholder['profilePictureHash'] && ($codeholder['profilePicturePublicity'] === 'public'
            || ($codeholder['profilePicturePublicity'] === 'members' && $isMember))) {
            $picPrefix = AksoBridgePlugin::CODEHOLDER_PICTURE_PATH . '?'
                . 'c=' . $codeholder['id']
                . '&s=';
            $codeholder['icon_src'] = $picPrefix . '64px';
            $codeholder['icon_srcset'] = $picPrefix . '64px 1x, ' . $picPrefix . '128px 2x, ' . $picPrefix . '256px 3x';
        }

        if (!is_array($codeholder['factoids'])) $codeholder['factoids'] = [];

        $codeholder['data_factoids'] = [];

        if ($codeholder['publicEmail'] || $codeholder['email']) {
            $codeholder['data_factoids'][$this->plugin->locale['country_org_lists']['public_email_field']] = array(
                'type' => 'email',
                'publicity' => $codeholder['publicEmail'] ? 'public' : $codeholder['emailPublicity'],
                'val' => $codeholder['publicEmail'] ?: $codeholder['email'],
            );
        }

        if ($codeholder['website']) {
            $codeholder['data_factoids'][$this->plugin->locale['country_org_lists']['website_field']] = array(
                'type' => 'url',
                'publicity' => 'public',
                'val' => $codeholder['website'],
            );
        }

        if ($codeholder['officePhone']) {
            $codeholder['data_factoids'][$this->plugin->locale['country_org_lists']['office_phone_field']] = array(
                'type' => 'tel',
                'publicity' => $codeholder['officePhonePublicity'],
                'val' => $codeholder['officePhone'],
            );
        }

        if ($codeholder['address'] && isset($codeholder['address']['country'])) {
            $addr = $codeholder['address'];
            $countryName = $this->getCountryNames()[$addr['country']];
            $rendered = $this->bridge->renderAddress(array(
                'countryCode' => $addr['country'],
                'countryArea' => $addr['countryArea'],
                'city' => $addr['city'],
                'cityArea' => $addr['cityArea'],
                'streetAddress' => $addr['streetAddress'],
                'postalCode' => $addr['postalCode'],
                'sortingCode' => $addr['sortingCode'],
            ), $countryName)['c'];
            $codeholder['data_factoids'][$this->plugin->locale['country_org_lists']['address_field']] = array(
                'type' => 'text',
                'show_plain' => true,
                'publicity' => $codeholder['addressPublicity'],
                'val' => $rendered,
            );
        }

        {
            foreach ($codeholder['factoids'] as &$fact) {
                $fact['publicity'] = 'public';
                $this->renderFactoid($fact);
            }
            foreach ($codeholder['data_factoids'] as &$fact) {
                $this->renderFactoid($fact);
            }
        }
        return $codeholder;
    }

    private function renderFactoid(&$fact) {
        if ($fact['type'] == 'text') {
            $fact['val_rendered'] = $this->bridge->renderMarkdown('' . $fact['val'], ['emphasis', 'strikethrough', 'link'])['c'];
        } else if ($fact['type'] == 'email') {
            $fact['val_rendered'] = Utils::obfuscateEmail('' . $fact['val'])->html();
        } else if ($fact['type'] == 'tel') {
            $phoneFmt = $this->bridge->evalScript([array(
                'number' => array('t' => 's', 'v' => $fact['val']),
            )], [], array('t' => 'c', 'f' => 'phone_fmt', 'a' => ['number']));
            if ($phoneFmt['s']) $fact['val_rendered'] = $phoneFmt['v'];
            else $fact['val_rendered'] = $fact['val'];
        }
    }
}
