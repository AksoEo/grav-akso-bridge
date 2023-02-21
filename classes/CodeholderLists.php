<?php
namespace Grav\Plugin\AksoBridge;

use \DiDom\Document;
use \DiDom\Element;
use Grav\Common\Grav;
use Grav\Plugin\AksoBridgePlugin;
use Grav\Plugin\AksoBridge\MarkdownExt;
use Grav\Plugin\AksoBridge\Utils;

class CodeholderLists {
    public const FIELDS = [
        'id',
        'codeholderType',
        'honorific',
        'firstName',
        'firstNameLegal',
        'lastName',
        'lastNameLegal',
        'lastNamePublicity',
        'fullName',
        'nameAbbrev',
        'email',
        'emailPublicity',
        'publicEmail',
        'address.country',
        'address.countryArea',
        'address.city',
        'address.cityArea',
        'address.streetAddress',
        'address.postalCode',
        'address.sortingCode',
        'addressPublicity',
        'biography',
        'website',
        'profilePictureHash',
        'profilePicturePublicity',
        'publicCountry'
    ];

    static function fetchCodeholderList($bridge, $listId, $sortBy, $sortByRoles) {
        // array of codeholder data
        $codeholders = [];
        // additional codeholder orgs we need to fetch because they were listed as a dataOrg
        $dataOrgIds = [];
        // the additional codeholder orgs we had to fetch
        $dataOrgCodeholders = [];

        while (true) {
            $currentItemCount = count($codeholders);
            $res = $bridge->get('/lists/' . $listId . '/codeholders', array(
                'offset' => $currentItemCount,
                'limit' => 100
            ), 60);
            if (!$res['k']) {
                Grav::instance()['log']->error('failed to fetch codeholder list: ' . $res['b']);
                return null;
            }

            $codeholderIds = $res['b'];

            $res = $bridge->get('/codeholders', array(
                'filter' => array('id' => array('$in' => $codeholderIds)),
                'fields' => CodeholderLists::FIELDS,
                'limit' => 100,
            ), 60);
            if (!$res['k']) {
                Grav::instance()['log']->error('failed to fetch codeholders in list: ' . $res['b']);
                return null;
            }

            $chDataById = array();
            foreach ($res['b'] as $ch) {
                $chDataById[$ch['id']] = $ch;
            }

            foreach ($codeholderIds as $id) {
                if (!isset($chDataById[$id])) continue;
                $ch = $chDataById[$id];
                $ch['profilePictureHash'] = bin2hex($ch['profilePictureHash']);

                $res2 = $bridge->get("/codeholders/$id/roles", array(
                    'fields' => ['role.name', 'dataCountry', 'dataOrg', 'dataString', 'role.id'],
                    'filter' => array('isActive' => true, 'role.public' => true),
                    'order' => [['role.name', 'asc']],
                    'limit' => 100,
                ), 240);
                if ($res2['k']) {
                    $ch['activeRoles'] = $res2['b'];

                    foreach ($res2['b'] as $role) {
                        if ($role['dataOrg'] && !in_array($role['dataOrg'], $dataOrgIds)) {
                            $dataOrgIds[] = $role['dataOrg'];
                        }
                    }
                } else {
                    Grav::instance()['log']->warn("could not fetch roles for codeholder $id");
                    $ch['activeRoles'] = [];
                }

                $codeholders[] = $ch;
            }

            if (count($res['b']) == 0) break; // no more items; we're done
        }

        for ($i = 0; $i < count($dataOrgIds); $i += 100) {
            $ids = array_slice($dataOrgIds, $i, 100);
            $res = $bridge->get('/codeholders', array(
                'fields' => ['id', 'fullName', 'nameAbbrev'],
                'filter' => array('id' => array('$in' => $ids)),
                'limit' => 100
            ), 60);
            if (!$res['k']) {
                Grav::instance()['log']->error('could not fetch codeholders for list: ' . $res['b']);
                return null;
            }
            foreach ($res['b'] as $ch) {
                $dataOrgCodeholders[$ch['id']] = $ch;
            }
        }

        return array(
            'codeholders' => $codeholders,
            'data_orgs' => $dataOrgCodeholders,
            'sorting' => $sortBy ? array(
                'field' => $sortBy,
                'roles' => $sortByRoles,
            ) : null,
            'id' => $listId,
        );
    }

    static function renderCodeholder($bridge, $codeholder, $dataOrgs, $isViewerMember, $elementTag = 'li') {
        $chNode = new Element($elementTag);
        $chNode->class = 'codeholder-item';
        $chNode->setAttribute('data-codeholder-id', $codeholder['id']);

        $left = new Element('div');
        $left->class = 'item-picture-container';
        $imgBack = new Element('img');
        $imgBack->class = 'item-back-picture';
        $imgBack->width = '1';
        $imgBack->height = '1';
        $img = new Element('img');
        $img->class = 'item-picture';

        $canSeePP = $codeholder['profilePicturePublicity'] === 'public'
            || ($isViewerMember && $codeholder['profilePicturePublicity'] === 'members');

        if ($canSeePP && $codeholder['profilePictureHash']) {
            // codeholder has a profile picture
            $picPrefix = AksoBridgePlugin::CODEHOLDER_PICTURE_PATH . '?'
                . 'c=' . $codeholder['id']
                . '&s=';
            $img->src = $picPrefix . '128px';
            $img->srcset = $picPrefix . '128px 1x, ' . $picPrefix . '256px 2x, ' . $picPrefix . '512px 3x';

            $imgBack->src = $img->src;
            $imgBack->srcset = $img->srcset;
        } else {
            $left->class .= ' is-empty';
        }

        $left->appendChild($imgBack);
        $innerPictureContainer = new Element('div');
        $innerPictureContainer->class = 'inner-picture-container';
        $innerPictureContainer->appendChild($img);
        $left->appendChild($innerPictureContainer);

        $right = new Element('div');
        $right->class = 'item-details';

        $canSeeAddress = $codeholder['addressPublicity'] === 'public'
            || ($isViewerMember && $codeholder['addressPublicity'] === 'members');

        if ($codeholder['publicCountry'] || $canSeeAddress) {
            $country = $codeholder['publicCountry'] ?: $codeholder['address']['country'];
            $countryBadge = new Element('div');
            $countryBadge->class = 'item-country-badge';

            $emoji = Utils::getEmojiForFlag($country);
            $countryFlag = new Element('img');
            $countryFlag->class = 'inline-flag-icon';
            $countryFlag->draggable = 'false';
            $countryFlag->alt = $emoji['alt'];
            $countryFlag->src = $emoji['src'];

            $countryName = new Element('span', ' ' . Utils::formatCountry($bridge, $country));
            $countryBadge->appendChild($countryFlag);
            $countryBadge->appendChild($countryName);
            $right->appendChild($countryBadge);
        }

        $codeholderName = '';
        if ($codeholder['codeholderType'] === 'human') {
            $canSeeLastName = $codeholder['lastNamePublicity'] === 'public'
                || ($isViewerMember && $codeholder['lastNamePublicity'] === 'members');

            $codeholderName = $codeholder['honorific'] ?: '';
            if ($codeholderName) $codeholderName .= ' ';
            if ($codeholder['firstName']) $codeholderName .= $codeholder['firstName'];
            else $codeholderName .= $codeholder['firstNameLegal'];

            if ($canSeeLastName) {
                if ($codeholder['lastName']) $codeholderName .= ' ' . $codeholder['lastName'];
                else if ($codeholder['lastNameLegal']) $codeholderName .= ' ' . $codeholder['lastNameLegal'];
            }
        } else if ($codeholder['codeholderType'] === 'org') {
            $codeholderName = $codeholder['fullName'];
        }
        $nameContainer = new Element('div', $codeholderName);
        $nameContainer->class = 'item-name';
        $right->appendChild($nameContainer);

        if (isset($codeholder['activeRoles']) && isset($dataOrgs)) {
            $rolesContainer = new Element('ul');
            $rolesContainer->class = 'item-roles';
            foreach ($codeholder['activeRoles'] as $role) {
                $li = new Element('li');
                $li->class = 'item-role';

                $roleName = new Element('span', $role['role']['name']);
                $roleName->class = 'role-name';
                $li->appendChild($roleName);

                if ($role['dataCountry'] || $role['dataString'] || $role['dataOrg']) {
                    $roleDetails = new Element('span');
                    $roleDetails->class = 'role-details';
                    $roleDetails->appendChild(new Element('span', '('));

                    if ($role['dataCountry']) {
                        $dCountry = new Element('span', Utils::formatCountry($bridge, $role['dataCountry']));
                        $dCountry->class = 'detail-country';
                        $roleDetails->appendChild($dCountry);
                    }

                    if ($role['dataOrg']) {
                        if ($role['dataCountry']) $roleDetails->appendChild(new Element('span', ': '));
                        $orgId = $role['dataOrg'];
                        $dOrg = new Element('span', $dataOrgs[$orgId]['nameAbbrev']);
                        $dOrg->title = $dataOrgs[$orgId]['fullName'];
                        $dOrg->class = 'detail-org';
                        $roleDetails->appendChild($dOrg);
                    }

                    if ($role['dataString']) {
                        if ($role['dataCountry'] || $role['dataOrg']) $roleDetails->appendChild(new Element('span', ', '));
                        $dStr = new Element('span', $role['dataString']);
                        $dStr->class = 'detail-string';
                        $roleDetails->appendChild($dStr);
                    }

                    $roleDetails->appendChild(new Element('span', ')'));
                    $li->appendChild(new Element('span', ' '));
                    $li->appendChild($roleDetails);
                }

                $rolesContainer->appendChild($li);
                $rolesContainer->appendChild(new Element('span', ' '));
            }
            $right->appendChild($rolesContainer);
        }

        $canSeeEmail = $codeholder['emailPublicity'] === 'public'
            || ($isViewerMember && $codeholder['emailPublicity'] === 'members');

        if ($codeholder['publicEmail'] || ($canSeeEmail && $codeholder['email'])) {
            $emailContainer = new Element('div');
            $emailContainer->class = 'item-email';
            $emailLink = Utils::obfuscateEmail($codeholder['publicEmail'] ?: $codeholder['email']);
            $emailContainer->appendChild($emailLink);
            $right->appendChild($emailContainer);
        }

        if ($codeholder['website']) {
            $websiteContainer = new Element('div');
            $websiteContainer->class = 'item-website';
            $websiteLink = new Element('a', $codeholder['website']);
            $websiteLink->target = '_blank';
            $websiteLink->rel = 'nofollow noreferrer';
            $websiteLink->href = $codeholder['website'];
            $websiteContainer->appendChild($websiteLink);
            $right->appendChild($websiteContainer);
        }

        if ($canSeeAddress && $codeholder['address']) {
            $countryName = Utils::formatCountry($bridge, $codeholder['address']['country']);
            $formatted = $bridge->renderAddress(array(
                'countryCode' => $codeholder['address']['country'],
                'countryArea' => $codeholder['address']['countryArea'],
                'city' => $codeholder['address']['city'],
                'cityArea' => $codeholder['address']['cityArea'],
                'streetAddress' => $codeholder['address']['streetAddress'],
                'postalCode' => $codeholder['address']['postalCode'],
                'sortingCode' => $codeholder['address']['sortingCode'],
            ), $countryName)['c'];
            $addressContainer = new Element('div');
            $addressContainer->class = 'item-address';
            $lines = preg_split('/\n/', $formatted);
            foreach ($lines as $line) {
                $lne = new Element('div', $line);
                $lne->class = 'address-line';
                $addressContainer->appendChild($lne);
            }
            $right->appendChild($addressContainer);
        }

        if ($codeholder['biography']) {
            $bioContainer = new Element('div', $codeholder['biography']);
            $bioContainer->class = 'item-bio';
            $right->appendChild($bioContainer);
        }

        $chNode->appendChild($left);
        $chNode->appendChild($right);
        return $chNode;
    }
}
