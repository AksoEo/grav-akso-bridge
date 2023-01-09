<?php
namespace Grav\Plugin\AksoBridge;

use \DiDom\Document;
use \DiDom\Element;
use Grav\Common\Plugin;
use Grav\Common\Markdown\Parsedown;
use RocketTheme\Toolbox\Event\Event;
use Grav\Plugin\AksoBridgePlugin;
use Grav\Plugin\AksoBridge\CongressFields;
use Grav\Plugin\AksoBridge\CodeholderLists;
use Grav\Plugin\AksoBridge\Magazines;
use Grav\Plugin\AksoBridge\Utils;

// loaded from AKSO bridge
class MarkdownExt {
    private $plugin; // owner plugin

    function __construct($plugin) {
        $this->plugin = $plugin;
    }

    private function initAppIfNeeded() {
        if (isset($this->app) && $this->app) return;
        $grav = $this->plugin->getGrav();
        $this->app = new AppBridge($grav);
        $this->apiHost = $this->app->apiHost;
        $this->app->open();
        $this->bridge = $this->app->bridge;

        $this->congressFields = new CongressFields($this->bridge, $this->plugin);
        $this->intermediaries = new Intermediaries($this->plugin, $this->bridge, $this->plugin);
    }

    public function onMarkdownInitialized(Event $event) {
        $this->initAppIfNeeded();

        $markdown = $event['markdown'];
        $self = $this;

        $markdown->addBlockType('[', 'IfAksoMember', true, true);
        $markdown->blockIfAksoMember = function($line, $block) {
            if (preg_match('/^\[\[se membro\]\]/', $line['text'], $matches)) {
                return array(
                    'char' => $line['text'][0],
                    'element' => array(
                        'name' => 'div',
                        'attributes' => array(
                            'class' => 'akso-members-only-content',
                        ),
                        'handler' => 'elements',
                        'text' => [
                            array(
                                'name' => 'script',
                                'attributes' => array(
                                    'class' => 'akso-members-only-content-if-clause',
                                ),
                                'handler' => 'lines',
                                'text' => [],
                            ),
                            array(
                                'name' => 'div',
                                'attributes' => array(
                                    'class' => 'akso-members-only-content-else-clause',
                                ),
                                'handler' => 'lines',
                                'text' => [],
                            ),
                        ],
                    ),
                );
            }
        };
        $markdown->blockIfAksoMemberContinue = function($line, $block) {
            if (isset($block['complete'])) {
                return;
            }
            // A blank newline has occurred.
            if (isset($block['interrupted'])) {
                if (isset($block['in_else_clause'])) {
                    array_push($block['element']['text'][1]['text'], "\n");
                } else {
                    array_push($block['element']['text'][0]['text'], "\n");
                }
                unset($block['interrupted']);
            }
            // Check for end of the block.
            if (preg_match('/\[\[\/se membro\]\]/', $line['text'])) {
                $block['complete'] = true;
                return $block;
            } else if (preg_match('/\[\[alie\]\]/', $line['text'])) {
                $block['in_else_clause'] = true;
                return $block;
            }

            if (isset($block['in_else_clause'])) {
                array_push($block['element']['text'][1]['text'], $line['body']);
            } else {
                array_push($block['element']['text'][0]['text'], $line['body']);
            }
            return $block;
        };
        $markdown->blockIfAksoMemberComplete = function($block) {
            return $block;
        };

        $markdown->addBlockType('[', 'AksoOnlyMembers');
        $markdown->blockAksoOnlyMembers = function($line, $block) use ($self) {
            if (preg_match('/^\[\[nurmembroj\]\]/', $line['text'], $matches)) {
                $error = null;
                $codeholders = [];

                return array(
                    'element' => array(
                        'name' => 'div',
                        'attributes' => array(
                            'class' => 'akso-members-only-box',
                        ),
                        'text' => '',
                    ),
                );
            }
        };

        $markdown->addBlockType('[', 'IfAksoLoggedIn', true, true);
        $markdown->blockIfAksoLoggedIn = function($line, $block) {
            if (preg_match('/^\[\[se ensalutinta\]\]/', $line['text'], $matches)) {
                return array(
                    'char' => $line['text'][0],
                    'element' => array(
                        'name' => 'div',
                        'attributes' => array(
                            'class' => 'akso-logged-in-only-content',
                        ),
                        'handler' => 'elements',
                        'text' => [
                            array(
                                'name' => 'script',
                                'attributes' => array(
                                    'class' => 'akso-logged-in-only-content-if-clause',
                                ),
                                'handler' => 'lines',
                                'text' => [],
                            ),
                            array(
                                'name' => 'div',
                                'attributes' => array(
                                    'class' => 'akso-logged-in-only-content-else-clause',
                                ),
                                'handler' => 'lines',
                                'text' => [],
                            ),
                        ],
                    ),
                );
            }
        };
        $markdown->blockIfAksoLoggedInContinue = function($line, $block) {
            if (isset($block['complete'])) {
                return;
            }
            // A blank newline has occurred.
            if (isset($block['interrupted'])) {
                if (isset($block['in_else_clause'])) {
                    array_push($block['element']['text'][1]['text'], "\n");
                } else {
                    array_push($block['element']['text'][0]['text'], "\n");
                }
                unset($block['interrupted']);
            }
            // Check for end of the block.
            if (preg_match('/\[\[\/se ensalutinta\]\]/', $line['text'])) {
                $block['complete'] = true;
                return $block;
            } else if (preg_match('/\[\[alie\]\]/', $line['text'])) {
                $block['in_else_clause'] = true;
                return $block;
            }

            if (isset($block['in_else_clause'])) {
                array_push($block['element']['text'][1]['text'], $line['body']);
            } else {
                array_push($block['element']['text'][0]['text'], $line['body']);
            }
            return $block;
        };
        $markdown->blockIfAksoLoggedInComplete = function($block) {
            return $block;
        };

        $markdown->addBlockType('[', 'AksoOnlyLoggedIn');
        $markdown->blockAksoOnlyLoggedIn = function($line, $block) use ($self) {
            if (preg_match('/^\[\[nurensalutintoj\]\]/', $line['text'], $matches)) {
                $error = null;
                $codeholders = [];

                return array(
                    'element' => array(
                        'name' => 'div',
                        'attributes' => array(
                            'class' => 'akso-logged-in-only-box',
                        ),
                        'text' => '',
                    ),
                );
            }
        };

        // A MILDLY CURSED SOLUTION
        // due to grav's markdown handling being rather limited, this will not immediately
        // render the list html after fetching the data. Instead, it will write it as a JSON
        // string for the HTML handler below to *actually* render
        $markdown->addBlockType('[', 'AksoList');
        $markdown->blockAksoList = function($line, $block) use ($self) {
            if (preg_match('/^\[\[listo\s+(\d+)(?:\s+lau-(\w+)-en-rolo\s+((?:\d+(?:,\s*|\s+))*\d+))?\]\]/i', $line['text'], $matches)) {
                $listId = $matches[1];
                $sortBy = null;
                $sortByRoles = [];

                if (isset($matches[2]) && $matches[2] === 'lando') {
                    $sortBy = 'dataCountry';
                } else if (isset($matches[2]) && $matches[2] === 'teksto') {
                    $sortBy = 'dataString';
                } else if (isset($matches[2])) {
                    throw new Error('unknown sorting method ' . $sortBy . ' in list ' . $listId);
                }

                if ($sortBy) {
                    $sortByRoles = array_map(function($f) { return (int) $f; }, preg_split('/,\s*|\s+/', $matches[3]));
                }

                $error = null;
                $codeholders = [];
                $dataOrgIds = [];
                $dataOrgCodeholders = [];

                while (true) {
                    $haveItems = count($codeholders);
                    $res = $self->bridge->get('/lists/' . $listId . '/codeholders', array(
                        'offset' => $haveItems,
                        'limit' => 100
                    ), 60);

                    if (!$res['k']) {
                        $error = '[internal error while fetching list: ' . $res['b'] . ']';
                        break;
                    }

                    // $totalItems = $res['h']['x-total-items'];
                    $totalItems = 99999999; // FIXME: the server doesn't send this apparently?
                    $codeholderIds = $res['b'];

                    $res = $self->bridge->get('/codeholders', array(
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

                    foreach ($codeholderIds as $id) {
                        if (!isset($chData[$id])) continue;
                        $ch = $chData[$id];
                        // php refuses to encode json if we dont do this
                        $ch['profilePictureHash'] = bin2hex($ch['profilePictureHash']);

                        // FIXME: do not do this (sending a request for each codeholder)
                        $res2 = $this->bridge->get("/codeholders/$id/roles", array(
                            'fields' => ['role.name', 'dataCountry', 'dataOrg', 'dataString', 'role.id'],
                            'filter' => array('isActive' => true, 'role.public' => true),
                            'order' => [['role.name', 'asc']],
                            'limit' => 100
                        ), 240);
                        if ($res2['k']) {
                            $ch['activeRoles'] = $res2['b'];

                            foreach ($res2['b'] as $role) {
                                if ($role['dataOrg'] && !in_array($role['dataOrg'], $dataOrgIds)) {
                                    $dataOrgIds[] = $role['dataOrg'];
                                }
                            }
                        } else {
                            $ch['activeRoles'] = [];
                        }

                        $codeholders[] = $ch;
                    }

                    if (count($res['b']) == 0) break; // in absence of x-total-items we'll need to do this
                    if (count($codeholders) < $totalItems) {
                        if (count($res['b']) == 0) {
                            // avoid an infinite loop
                            $error = '[internal inconsistency while fetching list: server reported ' . $totalItems . ' item(s) but refuses to send any more than ' . $haveItems . ']';
                            break;
                        }
                    } else {
                        // we have all items
                        break;
                    }
                }

                for ($i = 0; $i < count($dataOrgIds); $i += 100) {
                    $ids = array_slice($dataOrgIds, $i, 100);
                    $res = $self->bridge->get('/codeholders', array(
                        'fields' => ['id', 'fullName', 'nameAbbrev'],
                        'filter' => array('id' => array('$in' => $ids)),
                        'limit' => 100
                    ), 60);
                    if (!$res['k']) {
                        $error = '[internal error while fetching list: ' . $res['b'] . ']';
                        break;
                    }
                    foreach ($res['b'] as $ch) {
                        $dataOrgCodeholders[$ch['id']] = $ch;
                    }
                }

                $text = '!' . $error;
                if ($error === null) {
                    $text = json_encode(array(
                        'codeholders' => $codeholders,
                        'data_orgs' => $dataOrgCodeholders,
                        'sorting' => $sortBy ? array(
                            'field' => $sortBy,
                            'roles' => $sortByRoles,
                        ) : null,
                        'id' => $listId
                    ));
                }

                return array(
                    'element' => array(
                        'name' => 'script',
                        'attributes' => array(
                            'class' => 'unhandled-list',
                            'type' => 'application/json',
                        ),
                        'text' => $text
                    ),
                );
            }
        };

        $markdown->addBlockType('[', 'AksoMagazines');
        $markdown->blockAksoMagazines = function($line, $block) use ($self) {
            if (preg_match('/^\[\[revuoj\s+([\/\w]+)((?:\s+\d+)+)\]\]/', $line['text'], $matches)) {
                $error = null;
                $codeholders = [];

                $pathTarget = $matches[1];

                $ids = [];
                foreach (preg_split('/\s+/', $matches[2]) as $id) {
                    if (!empty($id)) $ids[] = (int) $id;
                }
                $error = null;
                $posters = [];

                $magazines = [];
                {
                    $res = $this->app->bridge->get("/magazines", array(
                        'fields' => ['id', 'name'],
                        'filter' => array('id' => array('$in' => (array) $ids)),
                        'limit' => count($ids),
                    ));
                    if (!$res['k']) {
                        $error = 'Eraro';
                    } else {
                        foreach ($res['b'] as $item) {
                            $magazines[$item['id']] = $item;
                        }
                    }
                }

                if (!$error) {
                    foreach ($ids as $id) {
                        $res = $self->bridge->get("/magazines/$id/editions", array(
                            'fields' => ['id', 'idHuman', 'date', 'description'],
                            'order' => [['date', 'desc']],
                            'offset' => 0,
                            'limit' => 1,
                        ), 120);

                        if (!$res['k'] || count($res['b']) < 1) {
                            $error = 'Eraro';
                            break;
                        }

                        $edition = $res['b'][0];
                        $editionId = $edition['id'];
                        $hasThumbnail = false;
                        try {
                            $path = "/magazines/$id/editions/$editionId/thumbnail/32px";
                            $res = $this->app->bridge->getRaw($path, 120);
                            if ($res['k']) {
                                $hasThumbnail = true;
                            }
                            $this->app->bridge->releaseRaw($path);
                        } catch (\Exception $e) {}

                        $posters[] = array(
                            'magazine' => $id,
                            'edition' => $editionId,
                            'info' => $magazines[$id],
                            'idHuman' => $edition['idHuman'],
                            'date' => $edition['date'],
                            'description' => $edition['description'],
                            'hasThumbnail' => $hasThumbnail,
                        );
                    }
                }

                $text = '!' . $error;
                if ($error === null) {
                    $text = json_encode(array(
                        'target' => $pathTarget,
                        'posters' => $posters,
                    ));
                }


                return array(
                    'element' => array(
                        'name' => 'script',
                        'attributes' => array(
                            'class' => 'unhandled-akso-magazines',
                            'type' => 'application/json',
                        ),
                        'text' => $text,
                    ),
                );
            }
        };

        $markdown->addBlockType('[', 'AksoCongresses');
        $markdown->blockAksoCongresses = function($line, $block) use ($self) {
            if (preg_match('/^\[\[kongreso(\s+tempokalkulo)?\s+(\d+)\/(\d+)\s+([^\s]+)(?:\s+([^\s]+))?\]\]/', $line['text'], $matches)) {
                $showCountdown = isset($matches[1]) && $matches[1];
                $congressId = $matches[2];
                $instanceId = $matches[3];
                $href = $matches[4];
                $imgHref = isset($matches[5]) ? $matches[5] : null;
                $error = null;
                $renderedCongresses = '';

                $res = $self->bridge->get("/congresses/$congressId/instances/$instanceId", array(
                    'fields' => ['id', 'name', 'dateFrom', 'dateTo', 'tz'],
                ));

                $text = '';

                if ($res['k']) {
                    $info = array(
                        'name' => $res['b']['name'],
                        'href' => $href,
                        'imgHref' => $imgHref,
                        'countdown' => false,
                        'date' => '',
                        'countdownTimestamp' => '',
                        'buttonLabel' => $self->plugin->locale['content']['congress_poster_button_label'],
                    );
                    if ($showCountdown) {
                        // TODO: dedup code
                        $firstEventRes = $self->bridge->get("/congresses/$congressId/instances/$instanceId/programs", array(
                            'order' => ['timeFrom.asc'],
                            'fields' => [
                                'timeFrom',
                            ],
                            'offset' => 0,
                            'limit' => 1,
                        ), 60);
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

                        $info['date'] = Utils::formatDayMonth($res['b']['dateFrom']) . '–'. Utils::formatDayMonth($res['b']['dateTo']);
                        $info['countdownTimestamp'] = $congressStartTime->getTimestamp();
                        $info['countdown'] = true;
                    }

                    $text = json_encode($info);
                } else {
                    $text = '!';
                }

                return array(
                    'element' => array(
                        'name' => 'script',
                        'attributes' => array(
                            'class' => 'akso-congresses unhandled-akso-congress-poster',
                            'type' => 'application/json',
                        ),
                        'text' => $text,
                    ),
                );
            }
        };

        $markdown->addInlineType('[', 'AksoCongressField');
        $markdown->inlineAksoCongressField = function($excerpt) use ($self) {
            if (preg_match('/^\[\[kongreso\s+([\w!]+)\s+(\d+)(?:\/(\d+))?(.*)\]\]/u', $excerpt['text'], $matches)) {
                $fieldName = mb_strtolower(normalizer_normalize($matches[1]));
                $congress = intval($matches[2]);
                $instance = isset($matches[3]) ? intval($matches[3]) : null;
                $args = [];

                $arg_matches = [];
                preg_match_all(
                    '/(?P<quote>["«»‹›“”‟„’❝❞❮❯⹂〝〞〟＂‚‛‘❛❜❟\'])(?P<arg>(?:\\\\(?P>quote)|[^"«»‹›“”‟„’❝❞❮❯⹂〝〞〟＂‚‛‘❛❜❟\'])+?)(?P>quote)|(?P<arg2>[^\s]+)/',
                    $matches[4],
                    $arg_matches
                );

                for ($i = 0; $i < count($arg_matches['arg']); $i++) {
                    $arg = $arg_matches['arg'][$i];
                    if (!$arg) $arg = $arg_matches['arg2'][$i];
                    $args[] = $arg;
                }
                $extent = strlen($matches[0]);

                $rendered = $self->congressFields->renderField($extent, $fieldName, $congress, $instance, $args);
                if ($rendered != null) return $rendered;
            }
        };

        $markdown->addInlineType('[', 'AksoFlag');
        $markdown->inlineAksoFlag = function($excerpt) use ($self) {
            if (preg_match('/^\[\[flago:(\w+)\]\]/u', $excerpt['text'], $matches)) {
                $code = mb_strtolower($matches[1]);

                $emoji = Utils::getEmojiForFlag($code);
                $imgSrc = $emoji['src'];
                $altText = $emoji['alt'];

                return array(
                    'extent' => strlen($matches[0]),
                    'element' => array(
                        'name' => 'img',
                        'attributes' => array(
                            'class' => 'inline-flag-icon',
                            'draggable' => 'false',
                            'alt' => $altText,
                            'src' => $imgSrc,
                        ),
                        'text' => '',
                    ),
                );
            }
        };

        $markdown->addBlockType('[', 'AksoIntermediaries');
        $markdown->blockAksoIntermediaries = function($line, $block) use ($self) {
            if (preg_match('/^\[\[perantoj\]\]/', $line['text'], $matches)) {
                $error = null;
                $codeholders = [];

                return array(
                    'element' => array(
                        'name' => 'div',
                        'attributes' => array(
                            'class' => 'akso-intermediaries',
                        ),
                        'text' => '',
                    ),
                );
            }
        };
    }

    public $nonces = array('scripts' => [], 'styles' => []);

    public function onOutputGenerated(Event $event) {
        if ($this->plugin->isAdmin()) {
            return;
        }
        $grav = $this->plugin->getGrav();
        $grav->output = \Normalizer::normalize($this->performHTMLPostProcessingTasks($grav->output));
        return $this->nonces;
    }

    // Separates full-width figures from the rest of the content.
    // also moves the sidebar nav to an appropriate position
    // adapted from https://github.com/trilbymedia/grav-plugin-image-captions/blob/develop/image-captions.php
    protected function performHTMLPostProcessingTasks($content) {
        if (empty($content)) return $content;

        $this->initAppIfNeeded();
        $document = new Document($content);

        return $this->handleHTMLComponents($document);
    }

    public function processHTMLComponents($contentString) {
        $document = new Document($contentString);
        return $this->handleHTMLComponents($document);
    }

    protected function handleHTMLComponents($document) {
        $this->initAppIfNeeded();
        $this->handleHTMLMagazines($document);
        $this->handleHTMLIfMembers($document);
        $this->handleHTMLIfLoggedIn($document);
        $this->handleHTMLCongressPosters($document);
        $this->handleHTMLMailLinks($document);
        $this->handleHTMLLists($document);
        $this->congressFields->handleHTMLCongressStuff($document);
        $this->intermediaries->handleHTMLIntermediaries($document);

        $this->nonces = $this->removeXSS($document);

        return $this->cleanupTags($document->html());
    }

    const LIST_PICTURE_CHID = 'c';
    const LIST_PICTURE_SIZE = 's';
    protected function handleHTMLLists($doc) {
        $isMember = false;
        if ($this->plugin->aksoUser !== null) {
            $isMember = $this->plugin->aksoUser['member'];
        }

        $unhandledLists = $doc->find('.unhandled-list');
        foreach ($unhandledLists as $list) {
            $textContent = $list->text();
            if (strncmp($textContent, '!', 1) === 0) {
                // this is an error; skip
                $list->replace($this->createError($doc));
                continue;
            }

            $newList = new Element('ul');
            $newList->class = 'codeholder-list';

            try {
                $data = json_decode($textContent, true);
                $listId = $data['id'];
                $codeholders = $data['codeholders'];
                $dataOrgs = $data['data_orgs'];
                $sorting = $data['sorting'];

                if ($sorting) {
                    $field = $sorting['field'];
                    $sortByRoles = $sorting['roles'];

                    $sortedItems = array();
                    $restItems = [];
                    $sortingKeys = [];

                    foreach ($codeholders as $codeholder) {
                        if (isset($codeholder['activeRoles'])) {
                            $sortingKey = null;
                            foreach ($codeholder['activeRoles'] as $role) {
                                if (in_array($role['role']['id'], $sortByRoles)) {
                                    $sortingKey = $role[$field];
                                    break;
                                }
                            }
                            if ($sortingKey) {
                                if (!isset($sortedItems[$sortingKey])) {
                                    $sortedItems[$sortingKey] = [];
                                    $sortingKeys[] = $sortingKey;
                                }
                                $sortedItems[$sortingKey][] = $codeholder;
                            } else {
                                $restItems[] = $codeholder;
                            }
                        }
                    }

                    sort($sortingKeys);

                    foreach ($sortingKeys as $key) {
                        $items = $sortedItems[$key];
                        $section = new Element('li');
                        $section->class = 'codeholder-list-category';
                        $sectionTitle = new Element('h4');
                        $sectionTitle->class = 'category-label';

                        if ($field === 'dataCountry') {
                            $emoji = Utils::getEmojiForFlag($key);
                            $flag = new Element('img');
                            $flag->class = 'inline-flag-icon';
                            $flag->src = $emoji['src'];
                            $flag->alt = $emoji['alt'];
                            $countryName = new Element('span', ' ' . Utils::formatCountry($this->bridge, $key));
                            $sectionTitle->appendChild($flag);
                            $sectionTitle->appendChild($countryName);
                        } else if ($field === 'dataString') {
                            $tagName = new Element('span', $key);
                            $sectionTitle->appendChild($tagName);
                        }

                        $section->appendChild($sectionTitle);
                        $sectionItems = new Element('ul');
                        $sectionItems->class = 'category-inner-items';
                        foreach ($items as $item) {
                            $sectionItems->appendChild(CodeholderLists::renderCodeholder($this->bridge, $item, $dataOrgs, $isMember));
                        }
                        $section->appendChild($sectionItems);
                        $newList->appendChild($section);
                    }

                    if (count($restItems)) {
                        $restSection = new Element('li');
                        $restSection->class = 'codeholder-list-category';
                        $sectionTitle = new Element('h4', $this->plugin->locale['content']['codeholder_list_sorted_rest']);
                        $sectionTitle->class = 'category-label';
                        $restSection->appendChild($sectionTitle);
                        $sectionItems = new Element('ul');
                        $sectionItems->class = 'category-inner-items';
                        foreach ($restItems as $item) {
                            $sectionItems->appendChild(CodeholderLists::renderCodeholder($this->bridge, $item, $dataOrgs, $isMember));
                        }
                        $restSection->appendChild($sectionItems);
                        $newList->appendChild($restSection);
                    }
                } else {
                    foreach ($codeholders as $codeholder) {
                        $newList->appendChild(CodeholderLists::renderCodeholder($this->bridge, $codeholder, $dataOrgs, $isMember));
                    }
                }

                $list->replace($newList);
            } catch (Exception $e) {
                // oh no
                $newList->class .= ' is-error';
                $list->replace($newList);
            }
        }
    }

    public function runListPicture() {
        $chId = isset($_GET[self::LIST_PICTURE_CHID]) ? (int)$_GET[self::LIST_PICTURE_CHID] : 0;
        $size = isset($_GET[self::LIST_PICTURE_SIZE]) ? (string)$_GET[self::LIST_PICTURE_SIZE] : '';
        if (!$chId || !$size) {
            $this->plugin->getGrav()->fireEvent('onPageNotFound');
            return;
        }
        $this->initAppIfNeeded();

        $found = true;
        {
            // fetch the entire batch so it can be cached more easily
            $res = $this->bridge->get('/codeholders', array(
                'filter' => array('id' => array('$in' => [$chId])),
                'fields' => [
                    'id',
                    'profilePictureHash',
                    'profilePicturePublicity'
                ],
                'limit' => 100,
            ), 60);
            if (!$res['k']) throw new \Exception('Failed to fetch list codeholders');
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
            $this->plugin->getGrav()->fireEvent('onPageNotFound');
            return;
        }
        $isMember = $this->plugin->aksoUser && $this->plugin->aksoUser['member'];
        if ($ch['profilePicturePublicity'] !== 'public' && !($ch['profilePicturePublicity'] === 'members' && $isMember)) {
            $this->plugin->getGrav()->fireEvent('onPageNotFound');
            return;
        }
        $hash = bin2hex($ch['profilePictureHash']);
        $path = "/codeholders/$chId/profile_picture/$size";
        // hack: use noop as unique cache key for getRaw
        $res = $this->bridge->getRaw($path, 10, array('noop' => $hash));
        if ($res['k']) {
            header('Content-Type: ' . $res['h']['content-type']);
            try {
                readfile($res['ref']);
            } finally {
                $this->bridge->releaseRaw($path);
            }
            die();
        } else {
            throw new \Exception('Failed to load picture');
        }
    }

    protected function handleHTMLMagazines($doc) {
        $unhandledMagazines = $doc->find('.unhandled-akso-magazines');
        foreach ($unhandledMagazines as $magazines) {
            $textContent = $magazines->text();
            if (strncmp($textContent, '!', 1) === 0) {
                // this is an error; skip
                $magazines->replace($this->createError($doc));
                continue;
            }

            $newMagazines = new Element('ul');
            $newMagazines->class = 'akso-magazine-posters';

            try {
                $data = json_decode($textContent, true);
                $pathTarget = $data['target'];
                $posters = $data['posters'];

                foreach ($posters as $poster) {
                    $link = $pathTarget . '/' . Magazines::MAGAZINE . '/' . $poster['magazine']
                        . '/' . Magazines::EDITION . '/' . $poster['edition'];

                    $mag = new Element('li');
                    $mag->class = 'magazine';
                    $coverContainer = new Element('a');
                    $coverContainer->href = $link;
                    $coverContainer->class = 'magazine-cover-container';
                    if ($poster['hasThumbnail']) {
                        $img = new Element('img');
                        $img->class = 'magazine-cover';
                        $basePath = AksoBridgePlugin::MAGAZINE_COVER_PATH . '?'
                            . Magazines::TH_MAGAZINE . '=' . $poster['magazine'] . '&'
                            . Magazines::TH_EDITION . '=' . $poster['edition'] . '&'
                            . Magazines::TH_SIZE;
                        $img->src = "$basePath=128px";
                        $img->srcset = "$basePath=128px 1x, $basePath=256px 2x, $basePath=512px 3x";
                        $coverContainer->appendChild($img);
                    } else {
                        $coverContainer->class .= ' has-no-thumbnail';
                        $inner = new Element('div');
                        $inner->class = 'th-inner';
                        $title = new Element('div', $poster['info']['name']);
                        $title->class = 'th-title';
                        $subtitle = new Element('div', $poster['idHuman']);
                        $subtitle->class = 'th-subtitle';
                        $inner->appendChild($title);
                        $inner->appendChild($subtitle);
                        $coverContainer->appendChild($inner);
                    }
                    $mag->appendChild($coverContainer);
                    $magTitle = new Element('a', $poster['info']['name']);
                    $magTitle->class = 'magazine-title';
                    $magTitle->href = $link;
                    $mag->appendChild($magTitle);
                    $magMeta = new Element('div', Utils::formatDate($poster['date']));
                    $magMeta->class = 'magazine-meta';
                    $mag->appendChild($magMeta);
                    $newMagazines->appendChild($mag);
                }
                $magazines->replace($newMagazines);
            } catch (Exception $e) {
                // oh no
                $newMagazines->class .= ' is-error';
                $magazines->replace($newMagazines);
            }
        }
    }

    protected function handleHTMLIfMembers($doc) {
        $isLoggedIn = false;
        $isMember = false;
        if ($this->plugin->aksoUser !== null) {
            $isLoggedIn = true;
            $isMember = $this->plugin->aksoUser['member'];
        }

        $ifMembers = $doc->find('.akso-members-only-content');
        foreach ($ifMembers as $ifMember) {
            $contents = null;
            if ($isMember) {
                $contents = new Element('div');
                $ifClause = $ifMember->find('.akso-members-only-content-if-clause')[0];
                foreach ($ifClause->children() as $child) $contents->appendChild($child);
            } else {
                $contents = $ifMember->find('.akso-members-only-content-else-clause')[0];
            }
            $contents->class = 'akso-members-only-content';
            $ifMember->replace($contents);
        }

        $membersOnlyBoxes = $doc->find('.akso-members-only-box');
        foreach ($membersOnlyBoxes as $membersOnlyBox) {
            if ($isMember) {
                $membersOnlyBox->class .= ' user-is-member';
                continue;
            }

            $desc = new Element('div', $this->plugin->locale['content']['members_only_desc']);
            $membersOnlyBox->appendChild($desc);

            $loginLink = new Element('a', $this->plugin->locale['content']['members_only_login_0']);
            $signUpLink = new Element('a', $isLoggedIn
                ? $this->plugin->locale['content']['members_only_sign_up_0']
                : $this->plugin->locale['content']['members_only_login_2']);

            $loginLink->href = $this->plugin->loginPath;
            $signUpLink->href = $this->plugin->registrationPath;

            if ($isLoggedIn) {
                $signUpLink->class = 'link-button';
                $membersOnlyBox->appendChild($signUpLink);
            } else {
                $membersOnlyBox->appendChild($loginLink);
                $membersOnlyBox->appendChild(new Element('span', $this->plugin->locale['content']['members_only_login_1']));
                $membersOnlyBox->appendChild($signUpLink);
                $membersOnlyBox->appendChild(new Element('span', $this->plugin->locale['content']['members_only_login_3']));
            }
        }

        $membersOnlyNotices = $doc->find('.akso-members-only-notice-inline');
        foreach ($membersOnlyNotices as $membersOnlyNotice) {
            if ($isMember) {
                $membersOnlyNotice->class .= ' user-is-member';
                continue;
            }

            $loginLink = new Element('a', $this->plugin->locale['content']['members_only_inline_login_0']);
            $signUpLink = new Element('a', $isLoggedIn
                ? $this->plugin->locale['content']['members_only_inline_sign_up_0']
                : $this->plugin->locale['content']['members_only_inline_login_2']);

            $loginLink->href = $this->plugin->loginPath;
            $signUpLink->href = $this->plugin->registrationPath;

            if ($isLoggedIn) {
                $membersOnlyNotice->appendChild($signUpLink);
                $membersOnlyNotice->appendChild(new Element('span', $this->plugin->locale['content']['members_only_inline_sign_up_1']));
            } else {
                $membersOnlyNotice->appendChild($loginLink);
                $membersOnlyNotice->appendChild(new Element('span', $this->plugin->locale['content']['members_only_inline_login_1']));
                $membersOnlyNotice->appendChild($signUpLink);
                $membersOnlyNotice->appendChild(new Element('span', $this->plugin->locale['content']['members_only_inline_login_3']));
            }
        }
    }

    protected function handleHTMLIfLoggedIn($doc) {
        $isLoggedIn = false;
        if ($this->plugin->aksoUser !== null) {
            $isLoggedIn = true;
        }

        $ifLoggedIns = $doc->find('.akso-logged-in-only-content');
        foreach ($ifLoggedIns as $ifLoggedIn) {
            $contents = null;
            if ($isLoggedIn) {
                $contents = new Element('div');
                $ifClause = $ifLoggedIn->find('.akso-logged-in-only-content-if-clause')[0];
                foreach ($ifClause->children() as $child) $contents->appendChild($child);
            } else {
                $contents = $ifLoggedIn->find('.akso-logged-in-only-content-else-clause')[0];
            }
            $contents->class = 'akso-logged-in-only-content';
            $ifLoggedIn->replace($contents);
        }

        $loggedInOnlyBoxes = $doc->find('.akso-logged-in-only-box');
        foreach ($loggedInOnlyBoxes as $loggedInOnlyBox) {
            if ($isLoggedIn) {
                $loggedInOnlyBox->class .= ' user-is-logged-in';
                continue;
            }

            $desc = new Element('div', $this->plugin->locale['content']['logged_in_only_desc']);
            $loggedInOnlyBox->appendChild($desc);

            $loginLink = new Element('a', $this->plugin->locale['content']['logged_in_only_login_0']);
            $signUpLink = new Element('a', $this->plugin->locale['content']['logged_in_only_login_2']);

            $loginLink->href = $this->plugin->loginPath;
            $signUpLink->href = $this->plugin->registrationPath;

            $loggedInOnlyBox->appendChild($loginLink);
            $loggedInOnlyBox->appendChild(new Element('span', $this->plugin->locale['content']['logged_in_only_login_1']));
            $loggedInOnlyBox->appendChild($signUpLink);
            $loggedInOnlyBox->appendChild(new Element('span', $this->plugin->locale['content']['logged_in_only_login_3']));
        }
    }

    protected function handleHTMLCongressPosters($doc) {
        $unhandledPosters = $doc->find('.unhandled-akso-congress-poster');
        foreach ($unhandledPosters as $poster) {
            $textContent = $poster->text();
            if (strncmp($textContent, '!', 1) === 0) {
                // this is an error; skip
                $poster->replace($this->createError($doc));
                continue;
            }

            $info = json_decode($textContent, true);

            $outerContainer = $doc->createElement('div');
            $outerContainer->class = 'akso-congresses';

            $container = $doc->createElement('a');

            $container->setAttribute('class', 'akso-congress-poster');
            $container->setAttribute('href', $info['href']);
            if ($info['imgHref']) {
                $img = $doc->createElement('img');
                $img->setAttribute('class', 'congress-poster-image');
                $img->setAttribute('src', $info['imgHref']);
                $container->appendChild($img);
            }
            $detailsContainer = $doc->createElement('div');
            $detailsContainer->setAttribute('class', 'congress-details' . ($info['imgHref'] ? ' has-image' : ''));
            $details = $doc->createElement('div');
            $details->setAttribute('class', 'congress-inner-details');
            $name = new Element('div', $info['name']);
            $name->setAttribute('class', 'congress-name');
            $button = $doc->createElement('button', $info['buttonLabel']);
            $button->setAttribute('class', 'open-button');
            $details->appendChild($name);

            if ($info['countdown']) {
                $timeDetails = $doc->createElement('div');
                $timeDetails->setAttribute('class', 'congress-time-details');

                $congressDate = new Element('span', $info['date']);
                $congressDate->setAttribute('class', 'congress-date');
                $timeDetails->appendChild($congressDate);

                $timeDetails->appendChild($doc->createTextNode(' · '));

                $countdown = $doc->createElement('span');
                $countdown->setAttribute('class', 'congress-countdown live-countdown');
                $countdown->setAttribute('data-timestamp', $info['countdownTimestamp']);
                $timeDetails->appendChild($countdown);
                $details->appendChild($timeDetails);
            }
            $detailsContainer->appendChild($details);
            $detailsContainer->appendChild($button);
            $container->appendChild($detailsContainer);
            $outerContainer->appendChild($container);

            $poster->replace($outerContainer);
        }
    }

    protected function handleHTMLMailLinks($doc) {
        $anchors = $doc->find('a');
        foreach ($anchors as $anchor) {
            $href = $anchor->getAttribute('href');
            if (!str_starts_with($href, 'mailto:')) {
                continue;
            }
            $href = substr($href, strlen('mailto:'));
            // split off ?query params
            $hrefParts = explode('?', $href, 2);
            $mailAddress = $hrefParts[0];
            $params = isset($hrefParts[1]) ? $hrefParts[1] : '';

            $textContent = trim($anchor->text());
            if (mb_strtolower($textContent) !== mb_strtolower($mailAddress)) {
                // can't obfuscate with contents
                $addrParts = explode('@', $mailAddress, 2);
                if ($params) {
                    $anchor->setAttribute('data-params', $params);
                }
                $anchor->setAttribute('data-extra2', $addrParts[1]);
                $anchor->setAttribute('data-extra', $addrParts[0]);
                $anchor->setAttribute('href', 'javascript:void(0)');
                $anchor->setAttribute('class', ($anchor->getAttribute('class') ?: '') . ' non-interactive-address');
                continue;
            }

            $mailAnchor = Utils::obfuscateEmail($mailAddress);
            if ($params) {
                $mailAnchor->setAttribute('data-params', $params);
            }

            $anchor->replace($mailAnchor);
        }
    }

    private function createError($doc) {
        $el = $doc->createElement('div', $this->plugin->locale['content']['render_error']);
        $el->class = 'md-render-error';
        return $el;
    }

    /** Handles XSS and returns a list of nonces. */
    protected function removeXSS($doc) {
        // apparently, Grav allows script tags in the document body

        // remove all scripts in the page content
        $scripts = $doc->find('.page-container script');
        foreach ($scripts as $script) {
            $replacement = new Element('div', '<script>' . $script->text() . '</script>');
            $replacement->class = 'illegal-script-tag';
            $script->replace($replacement);
        }

        // make note of all other scripts
        $scripts = $doc->find('script');
        $snonces = [];
        foreach ($scripts as $script) {
            if (isset($script->src)) continue;
            $nonce = hash('md5', random_bytes(32));
            $script->nonce = $nonce;
            $snonces[]= $nonce;
        }

        $styles = $doc->find('style');
        $cnonces = [];
        foreach ($styles as $style) {
            $nonce = hash('md5', random_bytes(32));
            $style->nonce = $nonce;
            $cnonces[] = $nonce;
        }

        return array(
            'scripts' => $snonces,
            'styles' => $cnonces,
        );
    }

    /**
     * Removes html and body tags at the begining and end of the html source
     *
     * @param $html
     * @return string
     */
    private function cleanupTags($html)
    {
        // remove html/body tags
        $html = preg_replace('#<html><body>#', '', $html);
        $html = preg_replace('#</body></html>#', '', $html);

        // remove whitespace
        $html = trim($html);

        /*// remove p tags
        preg_match_all('#<p>(?:\s*)((<a*.>)?.*)(?:\s*)(<figure((?:.|\n)*?)*(?:\s*)<\/figure>)(?:\s*)(<\/a>)?(?:\s*)<\/p>#m', $html, $matches);

        if (is_array($matches) && !empty($matches)) {
            $num_matches = count($matches[0]);
            for ($i = 0; $i < $num_matches; $i++) {
                $original = $matches[0][$i];
                $new = $matches[1][$i] . $matches[3][$i] . $matches[5][$i];

                $html = str_replace($original, $new, $html);
            }
        }*/

        return $html;
    }
}
