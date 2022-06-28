<?php
namespace Grav\Plugin\AksoBridge;

use \DiDom\Document;
use \DiDom\Element;
use Grav\Plugin\AksoBridgePlugin;
use Grav\Plugin\AksoBridge\MarkdownExt;
use Grav\Plugin\AksoBridge\CodeholderLists;
use Grav\Plugin\AksoBridge\Utils;

class Intermediaries {
    private $plugin, $bridge, $user;

    public function __construct($plugin, $bridge) {
        $this->plugin = $plugin;
        $this->bridge = $bridge;
        $this->user = $plugin->aksoUser ? $plugin->bridge : null;
    }

    function handleHTMLIntermediaries($doc) {
        $aksoIntermediaries = $doc->find('.akso-intermediaries');

        if (count($aksoIntermediaries)) {
            $totalCount = 1;
            $codeholders = [];
            $dataOrgIds = [];
            $dataOrgs = [];
            while (count($codeholders) < $totalCount) {
                $res = $this->bridge->get('/intermediaries', array(
                    'offset' => count($codeholders),
                    'fields' => ['codeholderId', 'countryCode', 'paymentDescription'],
                    'limit' => 100
                ), 60);

                if (!$res['k']) {
                    // error :(
                    // TODO: better error handling
                    return;
                }
                $totalCount = $res['h']['x-total-items'];

                $intermediaries = $res['b'];
                $codeholderIds = [];
                foreach ($intermediaries as $intermediary) $codeholderIds[] = $intermediary['codeholderId'];

                $res = $this->bridge->get('/codeholders', array(
                    'filter' => array('id' => array('$in' => $codeholderIds)),
                    'fields' => CodeholderLists::FIELDS,
                    'limit' => 100,
                ), 60);
                if (!$res['k']) {
                    $error = '[internal error while fetching list: ' . $res['b'] . ']';
                    break;
                }
                $chData = array();
                foreach ($res['b'] as $ch) {
                    $chData[$ch['id']] = $ch;
                }

                foreach ($intermediaries as $intermediary) {
                    $id = $intermediary['codeholderId'];
                    if (!isset($chData[$id])) continue;
                    $ch = $chData[$id];
                    $ch['activeRoles'] = [];

                    // FIXME: do not do this (sending a request for each codeholder)
                    $res2 = $this->bridge->get("/codeholders/$id/roles", array(
                        'fields' => ['role.name', 'role.public', 'dataCountry', 'dataOrg', 'dataString'],
                        'filter' => array('isActive' => true),
                        'order' => [['role.name', 'asc']],
                        'limit' => 100
                    ), 240);
                    if ($res2['k']) {
                        foreach ($res2['b'] as $role) {
                            if (!$role['role']['public']) continue;

                            $ch['activeRoles'][] = $role;
                            if ($role['dataOrg'] && !in_array($role['dataOrg'], $dataOrgIds)) {
                                $dataOrgIds[] = $role['dataOrg'];
                            }
                        }
                    }

                    $ch['intermediary_country'] = $intermediary['countryCode'];
                    $ch['intermediary_description'] = $intermediary['paymentDescription'];
                    $codeholders[] = $ch;
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
                    $error = '[internal error while fetching list: ' . $res['b'] . ']';
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

            $node = new Element('ul');
            $node->class = 'codeholder-list';

            foreach ($codeholders as $codeholder) {
                $li = new Element('li');
                $li->class = 'codeholder-list-category';
                $countryLabel = new Element('div');
                $countryLabel->class = 'category-label';

                {
                    $country = $codeholder['intermediary_country'];
                    $emoji = Utils::getEmojiForFlag($country);
                    $flag = new Element('img');
                    $flag->class = 'inline-flag-icon';
                    $flag->src = $emoji['src'];
                    $flag->alt = $emoji['alt'];
                    $countryLabel->appendChild($flag);

                    $label = new Element('span', ' ' . Utils::formatCountry($this->bridge, $country));
                    $countryLabel->appendChild($label);
                }

                $li->appendChild($countryLabel);
                $li->appendChild(CodeholderLists::renderCodeholder($this->bridge, $codeholder, $dataOrgs, $isMember, 'div'));

                $paymentDescription = new Element('blockquote');
                $paymentDescription->class = 'infobox category-inner-quote';
                $doc = new Document();
                $doc->loadHtml($this->bridge->renderMarkdown(
                    $codeholder['intermediary_description'],
                    ['emphasis', 'strikethrough', 'link', 'list', 'table'],
                )['c']);
                $paymentDescription->appendChild($doc->toElement());
                $li->appendChild($paymentDescription);
                $node->appendChild($li);
            }
        }

        foreach ($aksoIntermediaries as $intermediary) {
            $intermediary->appendChild($node);
        }
    }
}
