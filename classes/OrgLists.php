<?php
namespace Grav\Plugin\AksoBridge;

use Grav\Plugin\AksoBridgePlugin;
use Grav\Plugin\AksoBridge\MarkdownExt;
use Grav\Plugin\AksoBridge\Utils;

class OrgLists {
    const TAG_NAME = 'fako';
    const VIEW_ALL = '*';

    private $plugin, $bridge, $user;

    public function __construct($plugin, $bridge) {
        $this->plugin = $plugin;
        $this->bridge = $bridge;
        $this->user = $plugin->aksoUser ? $plugin->bridge : null;
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

    public function run() {
        $listId = $this->plugin->getGrav()['page']->header()->org_list_name;

        $res = $this->bridge->get("/org_lists/$listId", array(
            'fields' => ['list'],
        ), 60);
        if (!$res['k']) {
            if ($res['sc'] === 404) {
                $this->plugin->getGrav()->fireEvent('onPageNotFound');
                return;
            } else {
                throw new \Exception("Failed to load country list $listId");
            }
        }

        $orgList = $res['b']['list'];
        $tags = array_keys($orgList);
        $collator = new \Collator('fr_FR');
        usort($tags, function ($a, $b) use ($collator) {
            return $collator->compare($a, $b);
        });

        $view = isset($_GET[self::TAG_NAME]) ? $_GET[self::TAG_NAME] : self::VIEW_ALL;

        $tagLinks = [];
        foreach ($tags as $tag) {
            $tagLinks[$tag] = $this->plugin->getGrav()['uri']->path() . '?' . self::TAG_NAME . '=' . $tag
                . '#fakoj';
        }

        if ($view !== self::VIEW_ALL && !in_array($view, $tags)) {
            $this->plugin->getGrav()->fireEvent('onPageNotFound');
            return;
        }

        $orgIds = [];
        foreach ($orgList as $tag => $orgs) {
            if ($tag === $view) {
                foreach ($orgs as $org) {
                    if (!in_array($org, $orgIds)) $orgIds[] = $org;
                }
            }
        }
        $orgs = $this->getOrgs($orgIds);

        return array(
            'view' => $view,
            'view_all' => $view === self::VIEW_ALL,
            'list_tag_links' => $tagLinks,
            'list_tags' => $orgList,
            'orgs' => $orgs,
        );
    }

    function getOrgs($orgIds) {
        $orgs = [];
        for ($i = 0; $i < count($orgIds); $i += 100) {
            $batch = array_slice($orgIds, $i, 100);

            $res = $this->bridge->get("/codeholders", array(
                'fields' => [
                    'id', 'fullName', 'fullNameLocal', 'nameAbbrev',
                    'mainDescriptor', 'website', 'factoids', 'biography', 'publicEmail',
                    'email', 'emailPublicity', 'officePhone', 'officePhonePublicity',
                    'address.country', 'address.countryArea', 'address.city', 'address.cityArea',
                    'address.postalCode', 'address.sortingCode', 'address.streetAddress',
                    'addressPublicity',
                ],
                'filter' => ['id' => ['$in' => $batch]],
                'offset' => 0,
                'limit' => 100,
            ), 120);

            if (!$res['k']) {
                throw new \Exception("Failed to fetch orgs");
            }
            foreach ($res['b'] as $org) {
                if (!is_array($org['factoids'])) $org['factoids'] = [];

                $org['data_factoids'] = [];

                if ($org['publicEmail'] || $org['email']) {
                    $org['data_factoids'][$this->plugin->locale['country_org_lists']['public_email_field']] = array(
                        'type' => 'email',
                        'publicity' => $org['publicEmail'] ? 'public' : $org['emailPublicity'],
                        'val' => $org['publicEmail'] ?: $org['email'],
                    );
                }

                if ($org['website']) {
                    $org['data_factoids'][$this->plugin->locale['country_org_lists']['website_field']] = array(
                        'type' => 'url',
                        'publicity' => 'public',
                        'val' => $org['website'],
                    );
                }

                if ($org['officePhone']) {
                    $org['data_factoids'][$this->plugin->locale['country_org_lists']['office_phone_field']] = array(
                        'type' => 'tel',
                        'publicity' => $org['officePhonePublicity'],
                        'val' => $org['officePhone'],
                    );
                }

                if ($org['address']) {
                    $addr = $org['address'];
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
                    $org['data_factoids'][$this->plugin->locale['country_org_lists']['address_field']] = array(
                        'type' => 'text',
                        'show_plain' => true,
                        'publicity' => $org['addressPublicity'],
                        'val' => $rendered,
                    );
                }

                {
                    foreach ($org['factoids'] as &$fact) {
                        $fact['publicity'] = 'public';
                        $this->renderFactoid($fact);
                    }
                    foreach ($org['data_factoids'] as &$fact) {
                        $this->renderFactoid($fact);
                    }
                }
                $orgs[$org['id']] = $org;
            }
        }
        return $orgs;
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
