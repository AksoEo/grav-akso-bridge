<?php
namespace Grav\Plugin\AksoBridge;

use \DiDom\Document;
use \DiDom\Element;
use Grav\Common\Grav;
use Grav\Plugin\AksoBridge\CodeholderLists;
use Grav\Plugin\AksoBridge\Utils;
use Grav\Plugin\AksoBridgePlugin;
use Grav\Plugin\AksoBridge\MarkdownExt;

class Intermediaries {
    private $plugin, $bridge, $user;

    public function __construct($plugin, $bridge) {
        $this->plugin = $plugin;
        $this->bridge = $bridge;
        $this->user = $plugin->aksoUser ? $plugin->bridge : null;
    }

    function createError($doc) {
        $el = $doc->createElement('div', $this->plugin->locale['content']['render_error']);
        $el->class = 'md-render-error';
        return $el;
    }

    function handleHTMLIntermediaries($doc) {
        $aksoIntermediaries = $doc->find('.akso-intermediaries');
        $isError = false;

        if (count($aksoIntermediaries)) {
            $totalCount = 1;
            $codeholders = [];
            $dataOrgIds = [];
            $dataOrgs = [];
            while (count($codeholders) < $totalCount) {
                $res = $this->bridge->get('/intermediaries', array(
                    'offset' => count($codeholders),
                    'fields' => ['countryCode', 'codeholders'],
                    'limit' => 100
                ), 60);

                if (!$res['k']) {
                    Grav::instance()['log']->error('failed to fetch intermediaries: ' . $res['b']);
                    $isError = true;
                    break;
                }
                $totalCount = $res['h']['x-total-items'];

                $intermediaries = $res['b'];
                $codeholderIds = [];
                foreach ($intermediaries as $entry) {
                    foreach ($entry['codeholders'] as $ch) $codeholderIds[] = $ch['codeholderId'];
                }

                $res = $this->bridge->get('/codeholders', array(
                    'filter' => array('id' => array('$in' => $codeholderIds)),
                    'fields' => CodeholderLists::FIELDS,
                    'limit' => 100,
                ), 60);
                if (!$res['k']) {
                    Grav::instance()['log']->error('failed to fetch intermediary codeholders: ' . $res['b']);
                    $isError = true;
                    break;
                }
                $chData = array();
                foreach ($res['b'] as $ch) {
                    $chData[$ch['id']] = $ch;
                }

                foreach ($intermediaries as $entry) {
                    foreach ($entry['codeholders'] as $codeholder) {
                        $id = $codeholder['codeholderId'];
                        if (!isset($chData[$id])) continue;
                        $ch = $chData[$id];
                        $ch['activeRoles'] = [];

                        $res2 = $this->bridge->get("/codeholders/$id/roles", array(
                            'fields' => ['role.name', 'dataCountry', 'dataOrg', 'dataString'],
                            'filter' => array('isActive' => true, 'role.public' => true),
                            'order' => [['role.name', 'asc']],
                            'limit' => 100
                        ), 240);
                        if ($res2['k']) {
                            foreach ($res2['b'] as $role) {
                                $ch['activeRoles'][] = $role;
                                if ($role['dataOrg'] && !in_array($role['dataOrg'], $dataOrgIds)) {
                                    $dataOrgIds[] = $role['dataOrg'];
                                }
                            }
                        } else {
                            Grav::instance()['log']->warn("Failed to fetch roles for codeholder $id: " . $res2['b']);
                        }

                        $ch['intermediary_country'] = $entry['countryCode'];
                        $ch['intermediary_description'] = $codeholder['paymentDescription'];
                        $codeholders[] = $ch;
                    }
                }
            }

            for ($i = 0; $i < count($dataOrgIds); $i += 100) {
                $ids = array_slice($dataOrgIds, $i, 100);
                $res = $this->bridge->get('/codeholders', array(
                    'fields' => ['id', 'fullName', 'nameAbbrev'],
                    'filter' => array('id' => array('$in' => $ids)),
                    'limit' => 100
                ), 60);
                if (!$res['k']) {
                    $isError = true;
                    Grav::instance()['log']->error('Failed to fetch data org codeholders: ' . $res['b']);
                    break;
                }
                foreach ($res['b'] as $ch) {
                    $dataOrgs[$ch['id']] = $ch;
                }
            }

            $isMember = false;
            if ($this->plugin->aksoUser !== null) {
                $isMember = $this->plugin->aksoUser['member'];
            }

            if ($isError) {
                $node = $this->createError($doc);
            } else {
                $node = new Element('ul');
                $node->class = 'codeholder-list';

                $countries = [];

                foreach ($codeholders as $codeholder) {
                    $country = $codeholder['intermediary_country'];

                    if (!isset($countries[$country])) $countries[$country] = [];
                    $countries[$country][] = CodeholderLists::renderCodeholder($this->bridge, $codeholder, $dataOrgs, $isMember, 'div');

                    if ($codeholder['intermediary_description']) {
                        $paymentDescription = new Element('blockquote');
                        $paymentDescription->class = 'infobox category-inner-quote';
                        $doc = new Document();
                        $doc->loadHtml($this->bridge->renderMarkdown(
                            $codeholder['intermediary_description'],
                            ['emphasis', 'strikethrough', 'link', 'list', 'table'],
                        )['c']);
                        $paymentDescription->appendChild($doc->toElement());
                        $countries[$country][] = $paymentDescription;
                    }
                }

                $countryNames = [];
                foreach (array_keys($countries) as $country) {
                    $countryNames[$country] = Utils::formatCountry($this->bridge, $country);
                }

                $countryKeys = array_keys($countries);
                usort($countryKeys, function ($a, $b) use ($countryNames) {
                    return $countryNames[$a] <=> $countryNames[$b];
                });

                foreach ($countryKeys as $country) {
                    $li = new Element('li');
                    $li->class = 'codeholder-list-category';
                    $countryLabel = new Element('div');
                    $countryLabel->class = 'category-label';

                    $emoji = Utils::getEmojiForFlag($country);
                    $flag = new Element('img');
                    $flag->class = 'inline-flag-icon';
                    $flag->src = $emoji['src'];
                    $flag->alt = $emoji['alt'];
                    $countryLabel->appendChild($flag);

                    $label = new Element('span', htmlspecialchars(' ' . Utils::formatCountry($this->bridge, $country)));
                    $countryLabel->appendChild($label);

                    $li->appendChild($countryLabel);

                    foreach ($countries[$country] as $item) {
                        $li->appendChild($item);
                    }

                    $node->appendChild($li);
                }
            }
        }

        foreach ($aksoIntermediaries as $intermediary) {
            $intermediary->appendChild($node);
        }
    }
}
