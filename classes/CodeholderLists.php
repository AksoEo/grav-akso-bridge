<?php
namespace Grav\Plugin\AksoBridge;

use \DiDom\Element;
use Exception;
use Grav\Common\Grav;
use Grav\Plugin\AksoBridgePlugin;

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
        'publicCountry',
        'mainDescriptor',
        'factoids',
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
            ), 60 * 30);
            if (!$res['k']) {
                Grav::instance()['log']->error('failed to fetch codeholder list: ' . $res['b']);
                return null;
            }

            $codeholderIds = $res['b'];

            $res = $bridge->get('/codeholders', array(
                'filter' => array('id' => array('$in' => $codeholderIds)),
                'fields' => CodeholderLists::FIELDS,
                'limit' => 100,
            ), 60 * 30);
            if (!$res['k']) {
                Grav::instance()['log']->error('failed to fetch codeholders in list: ' . $res['b']);
                return null;
            }

            $chDataById = array();
            foreach ($res['b'] as $ch) {
                $chDataById[$ch['id']] = $ch;
            }

            $res2 = $bridge->get("/codeholders/roles", array(
                'fields' => ['role.name', 'dataCountry', 'dataOrg', 'dataString', 'role.id', 'codeholderId'],
                'filter' => array(
                    'isActive' => true,
                    'role.public' => true,
                    'codeholderId' => array('$in' => $codeholderIds),
                ),
                'order' => [['role.name', 'asc']],
                'limit' => 100,
            ), 60 * 30);
            if (!$res2['k']) {
                Grav::instance()['log']->error("could not fetch codeholder roles in list: " . $res2['b']);
                return null;
            }

            foreach ($res2['b'] as $role) {
                $id = $role['codeholderId'];
                if (!isset($chDataById[$id])) continue;
                if (!isset($chDataById[$id]['activeRoles'])) $chDataById[$id]['activeRoles'] = [];

                $chDataById[$id]['activeRoles'][] = $role;

                if ($role['dataOrg'] && !in_array($role['dataOrg'], $dataOrgIds)) {
                    $dataOrgIds[] = $role['dataOrg'];
                }
            }

            foreach ($codeholderIds as $id) {
                $ch = $chDataById[$id];
                $ch['activeRoles'] = $ch['activeRoles'] ?? [];
                $ch['profilePictureHash'] = bin2hex($ch['profilePictureHash']);
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

    const LIST_PICTURE_CHID = 'c';
    const LIST_PICTURE_SIZE = 's';
    public static function runListPicture($plugin, $bridge) {
        $chId = isset($_GET[self::LIST_PICTURE_CHID]) ? (int)$_GET[self::LIST_PICTURE_CHID] : 0;
        $size = isset($_GET[self::LIST_PICTURE_SIZE]) ? (string)$_GET[self::LIST_PICTURE_SIZE] : '';
        if (!$chId || !$size) {
            Grav::instance()->fireEvent('onPageNotFound');
            return;
        }

        $found = true;
        {
            // fetch the entire batch so it can be cached more easily
            $res = $bridge->get('/codeholders', array(
                'filter' => array('id' => array('$in' => [$chId])),
                'fields' => [
                    'id',
                    'profilePictureHash',
                    'profilePicturePublicity'
                ],
                'limit' => 100,
            ), 60);
            if (!$res['k']) throw new Exception('Failed to fetch list codeholders');
            $foundCh = false;
            foreach ($res['b'] as $ch) {
                if ($ch['id'] === $chId) {
                    $foundCh = true;
                    $found = $ch;
                    break;
                }
            }
            if (!$foundCh) {
                $found = false;
            }
        }
        $ch = $found;
        if (!$found || !$ch['profilePictureHash']) {
            Grav::instance()->fireEvent('onPageNotFound');
            return;
        }
        $isMember = $plugin->aksoUser && $plugin->aksoUser['member'];
        if ($ch['profilePicturePublicity'] !== 'public' && !($ch['profilePicturePublicity'] === 'members' && $isMember)) {
            Grav::instance()->fireEvent('onPageNotFound');
            return;
        }
        $hash = bin2hex($ch['profilePictureHash']);
        $path = "/codeholders/$chId/profile_picture/$size";
        // hack: use noop as unique cache key for getRaw
        $res = $bridge->getRaw($path, 10, array('noop' => $hash));
        if ($res['k']) {
            header('Content-Type: ' . $res['h']['content-type']);
            try {
                readfile($res['ref']);
            } finally {
                $bridge->releaseRaw($path);
            }
            die();
        } else {
            throw new Exception('Failed to load picture');
        }
    }


    static function renderCodeholder($bridge, $codeholder, $dataOrgs, $isViewerMember, $elementTag = 'li') {
        $chNode = new Element($elementTag);
        $chNode->class = 'codeholder-item';
        $chNode->setAttribute('data-codeholder-id', $codeholder['id']);

        $left = new Element('div');
        $left->class = 'item-picture-container';
        $imgBack = new Element('img');
        $imgBack->loading = 'lazy';
        $imgBack->class = 'item-back-picture';
        $imgBack->width = '1';
        $imgBack->height = '1';
        $img = new Element('img');
        $img->loading = 'lazy';
        $img->class = 'item-picture';

        $canSeePP = $codeholder['profilePicturePublicity'] === 'public'
            || ($isViewerMember && $codeholder['profilePicturePublicity'] === 'members');

        if ($canSeePP && $codeholder['profilePictureHash']) {
            // codeholder has a profile picture
            $picPrefix = AksoBridgePlugin::CODEHOLDER_PICTURE_PATH . '?'
                . self::LIST_PICTURE_CHID . '=' . $codeholder['id']
                . '&' . self::LIST_PICTURE_SIZE . '=';
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

            $countryName = new Element('span', htmlspecialchars(' ' . Utils::formatCountry($bridge, $country)));
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
        $nameContainer = new Element('div', htmlspecialchars($codeholderName));
        $nameContainer->class = 'item-name';
        $right->appendChild($nameContainer);

        if (isset($codeholder['activeRoles']) && isset($dataOrgs)) {
            $rolesContainer = new Element('ul');
            $rolesContainer->class = 'item-roles';
            foreach ($codeholder['activeRoles'] as $role) {
                $li = new Element('li');
                $li->class = 'item-role';

                $roleName = new Element('span', htmlspecialchars($role['role']['name']));
                $roleName->class = 'role-name';
                $li->appendChild($roleName);

                if ($role['dataCountry'] || $role['dataString'] || $role['dataOrg']) {
                    $roleDetails = new Element('span');
                    $roleDetails->class = 'role-details';
                    $roleDetails->appendChild(new Element('span', '('));

                    if ($role['dataCountry']) {
                        $dCountry = new Element('span', htmlspecialchars(Utils::formatCountry($bridge, $role['dataCountry'])));
                        $dCountry->class = 'detail-country';
                        $roleDetails->appendChild($dCountry);
                    }

                    if ($role['dataOrg']) {
                        if ($role['dataCountry']) $roleDetails->appendChild(new Element('span', ': '));
                        $orgId = $role['dataOrg'];
                        if ($dataOrgs[$orgId]['nameAbbrev']) {
                            $dOrg = new Element('abbr', htmlspecialchars($dataOrgs[$orgId]['nameAbbrev']));
                            $dOrg->title = $dataOrgs[$orgId]['fullName'];
                            $dOrg->class = 'detail-org is-abbrev';
                            $roleDetails->appendChild($dOrg);
                        } else {
                            $dOrg = new Element('span', htmlspecialchars($dataOrgs[$orgId]['fullName']));
                            $dOrg->class = 'detail-org';
                            $roleDetails->appendChild($dOrg);
                        }
                    }

                    if ($role['dataString']) {
                        if ($role['dataCountry'] || $role['dataOrg']) $roleDetails->appendChild(new Element('span', ', '));
                        $dStr = new Element('span', htmlspecialchars($role['dataString']));
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
            $websiteLink = new Element('a', htmlspecialchars($codeholder['website']));
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
                $lne = new Element('div', htmlspecialchars($line));
                $lne->class = 'address-line';
                $addressContainer->appendChild($lne);
            }
            $right->appendChild($addressContainer);
        }

        if (!empty($codeholder['factoids'])) {
            foreach ($codeholder['factoids'] as &$fact) {
                self::renderFactoidValue($bridge, $fact);
            }
            $right->appendChild(self::renderFactoids($codeholder['factoids']));
        }

        if ($codeholder['biography']) {
            $bioContainer = new Element('div', htmlspecialchars($codeholder['biography']));
            $bioContainer->class = 'item-bio';
            $right->appendChild($bioContainer);
        }

        if ($codeholder['mainDescriptor']) {
            $bioContainer = new Element('div');
            $bioContainer->class = 'item-bio';
            $lines = preg_split("/\n/", $codeholder['mainDescriptor']);
            foreach ($lines as $line) {
                $bioContainer->appendChild(new Element('div', htmlspecialchars($line)));
            }
            $right->appendChild($bioContainer);
        }

        $chNode->appendChild($left);
        $chNode->appendChild($right);
        return $chNode;
    }

    public static function renderFactoidValue($bridge, &$fact) {
        if ($fact['type'] == 'text') {
            $fact['val_rendered'] = $bridge->renderMarkdown('' . $fact['val'], ['emphasis', 'strikethrough', 'link'])['c'];
        } else if ($fact['type'] == 'email') {
            $fact['val_rendered'] = Utils::obfuscateEmail('' . $fact['val'])->html();
        } else if ($fact['type'] == 'tel') {
            $phoneFmt = $bridge->evalScript([array(
                'number' => array('t' => 's', 'v' => $fact['val']),
            )], [], array('t' => 'c', 'f' => 'phone_fmt', 'a' => ['number']));
            if ($phoneFmt['s']) $fact['val_rendered'] = $phoneFmt['v'];
            else $fact['val_rendered'] = $fact['val'];
        }
    }

    public static function renderFactoids($factoids): Element {
        $table = new Element('table');
        $table->class = 'akso-codeholder-factoids';

        $tbody = new Element('tbody');

        foreach ($factoids as $factKey => $fact) {
            $tr = new Element('tr');
            $tr->class = 'ch-factoid';
            $tr->setAttribute('data-type', $fact['type']);

            $label = new Element('th', htmlspecialchars($factKey));
            $label->class = 'factoid-label';
            $tr->appendChild($label);

            $contents = new Element('td');
            $contents->class = 'factoid-contents';

            if ($fact['type'] === 'tel') {
                $a = new Element('a', htmlspecialchars($fact['val_rendered']));
                $a->href = "tel:" . $fact['val'];
                $contents->appendChild($a);
            } else if ($fact['type'] === 'text') {
                $contents->setInnerHtml($fact['val_rendered']);
            } else if ($fact['type'] === 'number') {
                $contents->setValue(htmlspecialchars($fact['val']));
            } else if ($fact['type'] === 'email') {
                $contents->setInnerHtml($fact['val_rendered']);
            } else if ($fact['type'] === 'url') {
                $a = new Element('a', htmlspecialchars($fact['val']));
                $a->class = 'factoid-url';
                $a->href = $fact['val'];
                $contents->appendChild($a);
            }

            $tr->appendChild($contents);
            $tbody->appendChild($tr);
        }

        $table->appendChild($tbody);

        return $table;
    }
}
