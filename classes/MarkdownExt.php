<?php
namespace Grav\Plugin\AksoBridge;

use \DiDom\Document;
use \DiDom\Element;
use Grav\Common\Plugin;
use Grav\Common\Markdown\Parsedown;
use RocketTheme\Toolbox\Event\Event;
use Grav\Plugin\AksoBridgePlugin;
use Grav\Plugin\AksoBridge\CongressFields;
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
    }

    public function onMarkdownInitialized(Event $event) {
        $this->initAppIfNeeded();

        $markdown = $event['markdown'];

        $markdown->addBlockType('!', 'SectionMarker');
        $markdown->blockSectionMarker = function($line) {
            if (preg_match('/^!###(?:\[([\w\-_]+)\])?\s+(.*)/', $line['text'], $matches)) {
                $attrs = array('class' => 'section-marker');
                if (isset($matches[1]) && !empty($matches[1])) {
                    $attrs['id'] = $matches[1];
                } else {
                    $attrs['id'] = Utils::escapeFileNameLossy($matches[2]); // close enough
                }
                $text = trim($matches[2], ' ');
                return array(
                    'element' => array(
                        'name' => 'h3',
                        'attributes' => $attrs,
                        'handler' => 'line',
                        'text' => $text,
                    ),
                );
            }
        };

        $markdown->addBlockType('[', 'Figure', true, true);
        $markdown->blockFigure = function($line, $block) {
            if (preg_match('/^\[\[figuro(\s+!)?\]\]/', $line['text'], $matches)) {
                $fullWidth = isset($matches[1]);

                return array(
                    'char' => $line['text'][0],
                    'element' => array(
                        'name' => 'figure',
                        'attributes' => array(
                            'class' => ($fullWidth ? 'full-width' : ''),
                        ),
                        // parse markdown inside
                        'handler' => 'blockFigureContents',
                        // line handler needs a string
                        'text' => '',
                    ),
                );
            }
        };
        $markdown->blockFigureContinue = function($line, $block) {
            if (isset($block['complete'])) {
                return;
            }

            // A blank newline has occurred.
            if (isset($block['interrupted'])) {
                return;
            }

            // Check for end of the block.
            if (preg_match('/\[\[\/figuro\]\]/', $line['text'])) {
                $block['complete'] = true;
                return $block;
            }

            $block['element']['text'] .= $line['body'];

            return $block;
        };
        $markdown->blockFigureComplete = function($block) {
            return $block;
        };

        // copy-pasted from Parsedown source (line) and modified
        $markdown->blockFigureContents = \Closure::bind(function($text, $nonNestables = array()) {
            $markup = '';
            $inCaption = false;

            # $excerpt is based on the first occurrence of a marker
            while ($excerpt = strpbrk($text, $this->inlineMarkerList)) {
                $marker = $excerpt[0];
                $markerPosition = strpos($text, $marker);
                $Excerpt = array('text' => $excerpt, 'context' => $text);

                foreach ($this->InlineTypes[$marker] as $inlineType) {
                    # check to see if the current inline type is nestable in the current context
                    if (!empty($nonNestables) and in_array($inlineType, $nonNestables)) {
                        continue;
                    }
                    $Inline = $this->{'inline'.$inlineType}($Excerpt);
                    if (!isset($Inline)) {
                        continue;
                    }

                    # makes sure that the inline belongs to "our" marker
                    if (isset($Inline['position']) and $Inline['position'] > $markerPosition) {
                        continue;
                    }
                    # sets a default inline position
                    if (!isset($Inline['position'])) {
                        $Inline['position'] = $markerPosition;
                    }
                    # cause the new element to 'inherit' our non nestables
                    foreach ($nonNestables as $non_nestable) {
                        $Inline['element']['nonNestables'][] = $non_nestable;
                    }
                    # the text that comes before the inline
                    $unmarkedText = substr($text, 0, $Inline['position']);
                    $unmarkedText = $this->unmarkedText($unmarkedText);

                    if ($unmarkedText != '') {
                        if (!$inCaption) {
                            $markup .= '<figcaption>';
                            $inCaption = true;
                        }

                        # compile the unmarked text
                        $markup .= $unmarkedText;
                    }

                    $element = $Inline['element'];
                    if (isset($element) and isset($element['name']) and $element['name'] === 'img') {
                        if ($inCaption) {
                            $markup .= '</figcaption>';
                            $inCaption = false;
                        }
                    } else if (!$inCaption) {
                        $markup .= '<figcaption>';
                        $inCaption = true;
                    }

                    # compile the inline
                    $markup .= isset($Inline['markup']) ? $Inline['markup'] : $this->element($Inline['element']);
                    # remove the examined text
                    $text = substr($text, $Inline['position'] + $Inline['extent']);
                    continue 2;
                }

                # the marker does not belong to an inline
                $unmarkedText = substr($text, 0, $markerPosition + 1);
                $unmarkedText = $this->unmarkedText($unmarkedText);
                if ($unmarkedText != '') {
                    if (!$inCaption) {
                        $markup .= '<figcaption>';
                        $inCaption = true;
                    }
                    $markup .= $unmarkedText;
                }
                $text = substr($text, $markerPosition + 1);
            }

            $unmarkedText = $this->unmarkedText($text);
            if ($unmarkedText != '') {
                if (!$inCaption) {
                    $markup .= '<figcaption>';
                    $inCaption = true;
                }
                $markup .= $unmarkedText;
            }
            if ($inCaption) {
                $markup .= '</figcaption>';
            }

            return $markup;
        }, $markdown, $markdown);

        /* for Parsedown 1.8 (untested)
        $markdown->blockFigureContents = \Closure::bind(function($text, $nonNestables = array()) {
            $elements = $this->lineElements($text, $nonNestables);
            $markup = '';
            $autoBreak = true;
            $inCaption = false; // if true, we’re inside a <figcaption>
            foreach ($elements as $element) {
                if (empty($element)) {
                    continue;
                }
                $autoBreakNext = (isset($element['autobreak'])
                    ? $element['autobreak'] : isset($element['name'])
                );
                // (autobreak === false) covers both sides of an element
                $autoBreak = !$autoBreak ? $autoBreak : $autoBreakNext;

                $elMarkup = '';

                if (isset($element['name']) and $element['name'] === 'img') {
                    // image tag; put it directly in the figure body
                    if ($inCaption) {
                        $elMarkup .= '</figcaption>';
                        $inCaption = false;
                    }
                } else {
                    // some other thing; put it in the caption
                    if (!$inCaption) {
                        $elMarkup .= '<figcaption>';
                        $inCaption = true;
                    }
                }
                $elMarkup .= $this->element($element);

                $markup .= ($autoBreak ? "\n" : '') . $elMarkup;
                $autoBreak = $autoBreakNext;
            }
            if ($inCaption) {
                $markup .= '</figcaption>';
            }
            return $markup;
        }, $markdown, $markdown);
        */

        // add an infobox type
        $markdown->addBlockType('[', 'InfoBox', true, true);

        $markdown->blockInfoBox = function($line, $block) {
            if (preg_match('/^\[\[(\w+)skatolo\]\]/', $line['text'], $matches)) {
                $boxTypes = array(
                    'inform' => 'infobox',
                    'atentigo' => 'infobox is-warning',
                    'averto' => 'infobox is-error',
                );
                $tag = $matches[1];
                if (!isset($boxTypes[$tag])) return;
                $type = $boxTypes[$tag];

                return array(
                    'char' => $line['text'][0],
                    'element' => array(
                        'name' => 'blockquote',
                        'attributes' => array(
                            'class' => $type,
                            'data-tag' => $tag,
                        ),
                        // parse markdown inside
                        'handler' => 'lines',
                        // lines handler needs an array of lines
                        'text' => array(),
                    ),
                );
            }
        };
        $markdown->blockInfoBoxContinue = function($line, $block) {
            if (isset($block['complete'])) {
                return;
            }

            // A blank newline has occurred.
            if (isset($block['interrupted'])) {
                array_push($block['element']['text'], "\n");
                unset($block['interrupted']);
            }

            // Check for end of the block.
            if (preg_match('/\[\[\/' . $block['element']['attributes']['data-tag'] . 'skatolo\]\]/', $line['text'])) {
                $block['complete'] = true;
                return $block;
            }

            array_push($block['element']['text'], $line['body']);

            return $block;
        };
        $markdown->blockInfoBoxComplete = function($block) {
            unset($block['element']['attributes']['data-tag']);
            return $block;
        };

        $markdown->addBlockType('[', 'InfoBoxAd', true, true);
        $markdown->blockInfoBoxAd = function($line, $block) {
            if (preg_match('/^\[\[anonceto\]\]/', $line['text'], $matches)) {
                return array(
                    'char' => $line['text'][0],
                    'element' => array(
                        'name' => 'blockquote',
                        'attributes' => array(
                            'class' => 'infobox is-ab',
                            'data-ab-label' => $this->plugin->locale['content']['info_box_ad_label'],
                        ),
                        // parse markdown inside
                        'handler' => 'lines',
                        // lines handler needs an array of lines
                        'text' => array(),
                    ),
                );
            }
        };
        $markdown->blockInfoBoxAdContinue = function($line, $block) {
            if (isset($block['complete'])) {
                return;
            }

            // A blank newline has occurred.
            if (isset($block['interrupted'])) {
                array_push($block['element']['text'], "\n");
                unset($block['interrupted']);
            }

            // Check for end of the block.
            if (preg_match('/\[\[\/anonceto\]\]/', $line['text'])) {
                $block['complete'] = true;
                return $block;
            }

            array_push($block['element']['text'], $line['body']);

            return $block;
        };
        $markdown->blockInfoBoxAdComplete = function($block) {
            return $block;
        };


        $markdown->addBlockType('[', 'Expandable', true, true);
        $markdown->blockExpandable = function($line, $block) {
            if (preg_match('/^\[\[etendeblo\]\]/', $line['text'], $matches)) {
                return array(
                    'char' => $line['text'][0],
                    'element' => array(
                        'name' => 'details',
                        'attributes' => array(
                            'class' => 'unhandled-expandable',
                        ),
                        'handler' => 'lines',
                        'text' => array(),
                    ),
                );
            }
        };
        $markdown->blockExpandableContinue = function($line, $block) {
            if (isset($block['complete'])) {
                return;
            }

            // A blank newline has occurred.
            if (isset($block['interrupted'])) {
                array_push($block['element']['text'], "\n");
                unset($block['interrupted']);
            }

            // Check for end of the block.
            if (preg_match('/\[\[\/etendeblo\]\]/', $line['text'])) {
                $block['complete'] = true;
                return $block;
            }

            array_push($block['element']['text'], $line['body']);

            return $block;
        };
        $markdown->blockExpandableComplete = function($block) {
            return $block;
        };

        $markdown->addBlockType('[', 'Carousel', true, true);
        $markdown->blockCarousel = function($line, $block) {
            if (preg_match('/^\[\[bildkaruselo\]\]/', $line['text'], $matches)) {
                return array(
                    'char' => $line['text'][0],
                    'element' => array(
                        'name' => 'figure',
                        'attributes' => array(
                            'class' => 'full-width carousel',
                        ),
                        'handler' => 'lines',
                        'text' => array(),
                    ),
                );
            }
        };
        $markdown->blockCarouselContinue = function($line, $block) {
            if (isset($block['complete'])) {
                return;
            }

            // A blank newline has occurred.
            if (isset($block['interrupted'])) {
                $block['element']['text'][] = "\n";
                unset($block['interrupted']);
            }

            // Check for end of the block.
            if (preg_match('/\[\[\/bildkaruselo\]\]/', $line['text'])) {
                $block['complete'] = true;
                return $block;
            }

            $block['element']['text'][] = $line['body'];

            return $block;
        };
        $markdown->blockCarouselComplete = function($block) {
            return $block;
        };

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
                array_push($block['element']['text'], "\n");
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
                array_push($block['element']['text'], "\n");
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
            if (preg_match('/^\[\[listo\s+(\d+)\]\]/', $line['text'], $matches)) {
                $listId = $matches[1];

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
                        'fields' => [
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
                        ],
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
                            'fields' => ['role.name', 'dataCountry', 'dataOrg', 'dataString'],
                            'filter' => array('isActive' => true),
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

        $markdown->addBlockType('[', 'AksoNews');
        $markdown->blockAksoNews = function($line, $block) use ($self) {
            if (preg_match('/^\[\[aktuale\s+([^\s]+)\s+(\d+)(?:\s+"([^"]+)")?\]\]/', $line['text'], $matches)) {
                $error = null;
                $codeholders = [];

                $target = $matches[1];
                $count = (int) $matches[2];
                $title = isset($matches[3]) ? "$matches[3]" : '';

                return array(
                    'element' => array(
                        'name' => 'script',
                        'attributes' => array(
                            'class' => 'news-sidebar',
                            'type' => 'application/json',
                        ),
                        'text' => json_encode(array(
                            'title' => $title,
                            'target' => $target,
                            'count' => $count,
                        )),
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
                foreach (preg_split('/\s+/', $matches[4]) as $arg) {
                    $arg2 = trim($arg);
                    if (!empty($arg2)) $args[] = $arg2;
                }
                $extent = strlen($matches[0]);

                $rendered = $self->congressFields->renderField($extent, $fieldName, $congress, $instance, $args);
                if ($rendered != null) return $rendered;
            }
        };

        $markdown->addBlockType('[', 'TrIntro', true, true);
        $markdown->blockTrIntro = function($line, $block) {
            if (preg_match('/^\[\[intro\]\]/', $line['text'], $matches)) {
                return array(
                    'char' => $line['text'][0],
                    'element' => array(
                        'name' => 'div',
                        'attributes' => array(
                            'class' => 'intro-text',
                        ),
                        'handler' => 'lines',
                        'text' => array(
                            'current' => 'eo',
                            'variants' => array('eo' => []),
                        ),
                    ),
                );
            }
        };
        $markdown->blockTrIntroContinue = function($line, $block) {
            if (isset($block['complete'])) {
                return;
            }

            $current = $block['element']['text']['current'];

            // A blank newline has occurred.
            if (isset($block['interrupted'])) {
                $block['element']['text']['variants'][$current][] = "\n";
                unset($block['interrupted']);
            }

            if (preg_match('/\[\[(\w{2})\]\]/', $line['text'], $matches)) {
                // language code
                $current = $matches[1];
                $block['element']['text']['current'] = $current;
                $block['element']['text']['variants'][$current] = [];
                return $block;
            } else if (preg_match('/\[\[\/intro\]\]/', $line['text'])) {
                // end of the block
                $block['complete'] = true;
                return $block;
            }

            $block['element']['text']['variants'][$current][] = $line['body'];

            return $block;
        };
        $markdown->blockTrIntroComplete = function($block) use ($self) {
            $preferredLang = substr(isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '', 0, 2);
            if ($self->plugin->aksoUser) {
                $preferredLang = 'eo';
            }
            if (!isset($block['element']['text']['variants'][$preferredLang])) {
                $preferredLang = 'eo';
            }
            $block['element']['text'] = $block['element']['text']['variants'][$preferredLang];
            return $block;
        };

        $markdown->addBlockType('[', 'AksoBigButton');
        $markdown->blockAksoBigButton = function($line, $block) use ($self) {
            if (preg_match('/^\[\[butono(!)?(!)?\s+([^\s]+)\s+(.+?)\]\]/', $line['text'], $matches)) {
                $emphasis = $matches[1];
                $emphasis2 = $matches[2];
                $linkTarget = $matches[3];
                $label = $matches[4];

                $emphasisClass = '';
                if ($emphasis) $emphasisClass .= ' is-primary has-emphasis';
                if ($emphasis2) $emphasisClass .= ' has-big-emphasis';

                return array(
                    'element' => array(
                        'name' => 'div',
                        'attributes' => array(
                            'class' => 'big-actionable-button-container' . $emphasisClass,
                        ),
                        'handler' => 'elements',
                        'text' => [
                            array(
                                'name' => 'a',
                                'attributes' => array(
                                    'class' => 'link-button big-actionable-button' . $emphasisClass,
                                    'href' => $linkTarget,
                                ),
                                'handler' => 'elements',
                                'text' => [
                                    array(
                                        'name' => 'span',
                                        'text' => $label,
                                    ),
                                    array(
                                        'name' => 'span',
                                        'attributes' => array(
                                            'class' => 'action-arrow-icon',
                                        ),
                                        'text' => '',
                                    ),
                                    array(
                                        'name' => 'span',
                                        'attributes' => array(
                                            'class' => 'action-button-shine',
                                        ),
                                        'text' => '',
                                    ),
                                ],
                            ),
                        ],
                    ),
                );
            }
        };

        $markdown->addBlockType('[', 'MultiCol', true, true);
        $markdown->blockMultiCol = function($line, $block) {
            if (preg_match('/^\[\[kolumnoj\]\]/', $line['text'], $matches)) {
                return array(
                    'char' => $line['text'][0],
                    'element' => array(
                        'name' => 'div',
                        'attributes' => array(
                            'class' => 'content-multicols',
                        ),
                        'handler' => 'elements',
                        'text' => [['']],
                    ),
                );
            }
        };
        $markdown->blockMultiColContinue = function($line, $block) {
            if (isset($block['complete'])) {
                return;
            }

            $lastIndex = count($block['element']['text']) - 1;

            // A blank newline has occurred.
            if (isset($block['interrupted'])) {
                $block['element']['text'][$lastIndex][] = "\n";
                unset($block['interrupted']);
            }

            if (preg_match('/^===$/', $line['text'], $matches)) {
                // column break
                $block['element']['text'][] = [''];
                return $block;
            } else if (preg_match('/^\[\[\/kolumnoj\]\]$/', $line['text'])) {
                // end of the block
                $block['complete'] = true;
                return $block;
            }

            $block['element']['text'][$lastIndex][] = $line['body'];

            return $block;
        };
        $markdown->blockMultiColComplete = function($block) use ($self) {
            $block['element']['attributes']['data-columns'] = count($block['element']['text']);
            $els = [];
            foreach ($block['element']['text'] as $lines) {
                if (count($els)) {
                    $els[] = array(
                        'name' => 'hr',
                        'attributes' => array(
                            'class' => 'multicol-column-break',
                        ),
                        'text' => '',
                    );
                }
                $els[] = array(
                    'name' => 'div',
                    'attributes' => array(
                        'class' => 'multicol-column',
                    ),
                    'handler' => 'lines',
                    'text' => $lines,
                );
            }
            $block['element']['text'] = $els;
            return $block;
        };

        $markdown->addInlineType('[', 'AksoFlag');
        $markdown->inlineAksoFlag = function($excerpt) use ($self) {
            if (preg_match('/^\[\[flago:(\w+)\]\]/u', $excerpt['text'], $matches)) {
                $code = mb_strtolower($matches[1]);

                $emoji = $this->getEmojiForFlag($code);
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


        $markdown->addBlockType('[', 'AksoTable', true, true);
        $markdown->blockAksoTable = function($line, $block) {
            if (preg_match('/^\[\[tabelo\]\]/', $line['text'], $matches)) {
                return array(
                    'char' => $line['text'][0],
                    'element' => array(
                        'name' => 'table',
                        'attributes' => array(
                            'class' => 'content-mdx-table',
                        ),
                        'handler' => 'elements',
                        'text' => [[]],
                    ),
                );
            }
        };
        $markdown->blockAksoTableContinue = function($line, $block) {
            if (isset($block['complete'])) {
                return;
            }
            if (isset($block['interrupted'])) {
                // we don't care about blank lines
                unset($block['interrupted']);
            }

            if (preg_match('/^\[\[\/tabelo\]\]$/', $line['text'])) {
                // end of the block
                $block['complete'] = true;
                return $block;
            }

            $block['element']['text'][] = preg_split('/\|/',
                preg_replace('/^\s*\|/', '',
                    preg_replace('/\|\s*$/', '', $line['body'])));

            return $block;
        };
        $markdown->blockAksoTableComplete = function($block) use ($self) {
            $columnCount = 0;
            foreach ($block['element']['text'] as $row) {
                $columnCount = max($columnCount, count($row));
            }
            $rows = [];
            foreach ($block['element']['text'] as $row) {
                $cells = [];
                foreach ($row as $cell) {
                    $cells[] = array(
                        'name' => 'td',
                        'handler' => 'text',
                        'text' => $cell,
                    );
                }
                $rows[] = array(
                    'name' => 'tr',
                    'handler' => 'elements',
                    'text' => $cells,
                );
            }
            $block['element']['text'] = [array(
                'name' => 'tbody',
                'handler' => 'elements',
                'text' => $rows,
            )];
            return $block;
        };
    }

    public static function getEmojiForFlag($code) {
        $altText = '';
        $emojiName = '';
        if (strlen($code) === 2) {
            $ri1 = 0x1f1e6 - 0x61 + ord($code[0]);
            $ri2 = 0x1f1e6 - 0x61 + ord($code[1]);
            $altText = mb_chr($ri1) . mb_chr($ri2);
            $emojiName = 'twemoji/' . dechex($ri1) . '-' . dechex($ri2);
        } else {
            $altText = $code;
            $emojiName = 'extra/' . $code;
        }
        return array(
            'src' => "/user/plugins/akso-bridge/emoji/$emojiName.png",
            'alt' => $altText,
        );
    }

    public $nonces = array('scripts' => [], 'styles' => []);

    public function onOutputGenerated(Event $event) {
        if ($this->plugin->isAdmin()) {
            return;
        }
        $grav = $this->plugin->getGrav();
        $grav->output = $this->performHTMLPostProcessingTasks($grav->output);
        return $this->nonces;
    }

    // Separates full-width figures from the rest of the content.
    // also moves the sidebar nav to an appropriate position
    // adapted from https://github.com/trilbymedia/grav-plugin-image-captions/blob/develop/image-captions.php
    protected function performHTMLPostProcessingTasks($content) {
        if (strlen($content) === 0) {
            return '';
        }

        $this->initAppIfNeeded();
        $document = new Document($content);
        $mains = $document->find('main#main-content');
        if (count($mains) === 0) return $content;

        $breadcrumbs = $document->find('.breadcrumbs-container');
        if (count($breadcrumbs) > 0) {
            // fix html a bit
            $breadcrumbs[0]->setAttribute('role', 'navigation');
            $breadcrumbs[0]->setAttribute('aria-label', $this->plugin->locale['content']['breadcrumbs_title']);
            $document->first('.breadcrumbs-container [itemtype="http://schema.org/BreadcrumbList"]')->setAttribute('role', 'list');
            $itemCount = 0;
            foreach ($document->find('.breadcrumbs-container [itemtype="http://schema.org/Thing"]') as $li) {
                $li->setAttribute('role', 'listitem');
                $itemCount += 1;
            }
            // last item is always a span
            $document->first('.breadcrumbs-container span[itemtype="http://schema.org/Thing"]')->setAttribute('aria-current', 'page');
            $breadcrumbs[0]->remove();
            if ($itemCount == 1) {
                // only one item; probably "home". we dont need breadcrumbs for this
                $breadcrumbs = [];
            }
        }

        // collect top level children into sections
        $rootNode = $mains[0];
        $topLevelChildren = $rootNode->children();
        $sections = array();
        $sidebarNews = null;
        foreach ($topLevelChildren as $child) {
            if ($child->isElementNode() and $child->class === 'news-sidebar') {
                $sidebarNews = $child;
            } else if ($child->isElementNode() and $child->tag === 'figure' and strpos($child->class, 'full-width') !== false) {
                // full-width figure!
                $sections[] = array(
                    'kind' => 'figure',
                    'contents' => $child,
                );
            } else if ($child->isTextNode() and trim($child->text()) === '') {
                continue;
            } else if ($child->isElementNode() && $child->tag === 'h3' && strpos($child->class, 'section-marker') !== false) {
                $sections[] = array(
                    'kind' => 'section',
                    'contents' => [$child],
                );
            } else {
                $isSectionEndingNode = $child->isElementNode() && in_array($child->tag, ['h1', 'h2']);
                $lastSection = count($sections) ? $sections[count($sections) - 1] : null;
                $lastSectionIsSection = $lastSection ? $lastSection['kind'] === 'section' : false;
                $lastSectionCanReceiveNode = $lastSection
                    ? ($lastSectionIsSection ? !$isSectionEndingNode : false)
                    : false;

                if (!$lastSectionCanReceiveNode) {
                    $sections[] = array(
                        'kind' => 'normal',
                        'contents' => array(),
                    );
                }
                $sections[count($sections) - 1]['contents'][] = $child;
            }
            $child->remove();
        }

        // first item is a full-width figure; split here

        $newRootNode = new Element('main');
        $newRootNode->class = 'page-container';
        $contentSplitNode = new Element('div');
        $contentSplitNode->class = 'page-split';
        $contentRootNode = new Element('div');
        $contentRootNode->class = 'page-contents';

        $firstIsBigFigure = (count($sections) > 0) && ($sections[0]['kind'] === 'figure');
        if ($firstIsBigFigure) {
            $fig = $sections[0]['contents'];
            $fig->class .= ' is-top-figure';
            array_splice($sections, 0, 1);
            $newRootNode->appendChild($fig);
        }

        // move sidebar nav
        $navSidebar = $document->find('#nav-sidebar');
        if (count($navSidebar) > 0 || $sidebarNews !== null) {
            if ($sidebarNews !== null) {
                $contentSplitNode->appendChild($sidebarNews);
            }
            if (count($navSidebar) > 0) {
                $navSidebar = $navSidebar[0];
                $navSidebar->remove();
                $contentSplitNode->appendChild($navSidebar);
            }
        } else {
            $newRootNode->class .= ' is-not-split';
        }

        $isFirstContainer = true;
        $didAddBreadcrumbs = false;
        $currentContainer = null;
        $flushContainer = function()
            use
                (&$currentContainer, &$contentRootNode, &$contentSplitNode, &$isFirstContainer)
        {
            if (!$currentContainer) return;
            if ($isFirstContainer) {
                $contentSplitNode->appendChild($currentContainer);
            } else {
                $containerContainerNode = new Element('div');
                $containerContainerNode->class = 'content-split-container';
                $layoutSpacer = new Element('div');
                $layoutSpacer->class = 'layout-spacer';
                $containerContainerNode->appendChild($layoutSpacer);
                $containerContainerNode->appendChild($currentContainer);
                $contentRootNode->appendChild($containerContainerNode);
            }
            $currentContainer = null;
            $isFirstContainer = false;
        };

        foreach ($sections as $section) {
            if ($section['kind'] === 'figure') {
                $flushContainer();
                $contentRootNode->appendChild($section['contents']);
            } else {
                if (!$currentContainer) {
                    $currentContainer = new Element('div');
                    $currentContainer->class = 'content-container';
                }

                $sectionNode = new Element('section');
                $sectionNode->class = 'md-container';

                if (!$didAddBreadcrumbs) {
                    // add breadcrumbs to first section
                    $didAddBreadcrumbs = true;
                    if (count($breadcrumbs) > 0) {
                        $currentContainer->appendChild($breadcrumbs[0]);
                    }
                }

                foreach ($section['contents'] as $contentNode) {
                    $sectionNode->appendChild($contentNode);
                }

                $currentContainer->appendChild($sectionNode);
            }
        }
        $flushContainer();

        $newRootNode->appendChild($contentSplitNode);
        $newRootNode->appendChild($contentRootNode);
        $rootNode->replace($newRootNode);

        return $this->handleHTMLComponents($document);
    }

    public function processHTMLComponents($contentString) {
        $document = new Document($contentString);
        return $this->handleHTMLComponents($document);
    }

    protected function handleHTMLComponents($document) {
        $this->initAppIfNeeded();
        $this->handleHTMLCarousels($document);
        $this->handleHTMLSectionMarkers($document);
        $this->handleHTMLExpandables($document);
        $this->handleHTMLLists($document);
        $this->handleHTMLNews($document);
        $this->handleHTMLMagazines($document);
        $this->handleHTMLIfMembers($document);
        $this->handleHTMLIfLoggedIn($document);
        $this->handleHTMLCongressPosters($document);
        $this->congressFields->handleHTMLCongressStuff($document);

        $this->nonces = $this->removeXSS($document);

        return $this->cleanupTags($document->html());
    }

    protected function handleHTMLCarousels($doc) {
        $carouselIdCounter = 0;
        $carousels = $doc->find('figure.carousel');
        $didPassImg = false;
        foreach ($carousels as $carousel) {
            $topLevelChildren = $carousel->children();
            $pages = array();
            $currentCaption = array();
            foreach ($topLevelChildren as $tlChild) {
                $tlChild->remove();

                if ($tlChild->isElementNode() && $tlChild->tag === 'p') {
                    $pChildren = $tlChild->children();
                    $newPChildren = array();
                    foreach ($pChildren as $pChild) {
                        $link = null;
                        $imageNode = null;
                        if ($pChild->isElementNode() && $pChild->tag === 'img') {
                            $imageNode = $pChild;
                        } else if ($pChild->isElementNode() && $pChild->tag === 'a') {
                            $ch = $pChild->children();
                            $isValid = true;
                            foreach ($ch as $child) {
                                if ($child->isElementNode() && $child->tag !== 'img') {
                                    $isValid = false;
                                    break;
                                } else if ($child->isTextNode() && !empty(chop($child->text()))) {
                                    $isValid = false;
                                    break;
                                }
                            }
                            if ($isValid) {
                                foreach ($ch as $child) {
                                    if ($child->isElementNode() && $child->tag === 'img') {
                                        $link = $pChild->getAttribute('href');
                                        if (!$link) $link = '';
                                        $imageNode = $child;
                                        break;
                                    }
                                }
                            }
                        }

                        if ($imageNode) {
                            // split here
                            if (count($newPChildren) > 0) {
                                $newP = new Element('p');
                                foreach ($newPChildren as $npc) {
                                    $newP->appendChild($npc);
                                }
                                $currentCaption[] = $newP;
                                $newPChildren = array();
                            }
                            if (count($currentCaption) > 0 && $didPassImg) {
                                $newCaption = &$pages[count($pages) - 1]['caption'];
                                foreach ($currentCaption as $ccc) {
                                    $newCaption->appendChild($ccc);
                                }
                                $currentCaption = array();
                            }

                            $pages[] = array(
                                'img' => $imageNode,
                                'link' => $link,
                                'caption' => new Element('figcaption')
                            );
                            $didPassImg = true;
                        } else {
                            $newPChildren[] = $pChild;
                        }
                    }

                    // flush rest
                    if (count($newPChildren) > 0) {
                        $newP = new Element('p');
                        foreach ($newPChildren as $npc) {
                            $newP->appendChild($npc);
                        }
                        $currentCaption[] = $newP;
                        $newPChildren[] = array();
                    }
                } else {
                    $currentCaption[] = $tlChild;
                }
            }

            // flush rest
            if (count($currentCaption) > 0 && $didPassImg) {
                $newCaption = &$pages[count($pages) - 1]['caption'];
                foreach ($currentCaption as $ccc) {
                    $newCaption->appendChild($ccc);
                }
                $currentCaption = array();
            }

            $carouselId = 'figure-carousel-pages-' . $carouselIdCounter;
            $carouselIdCounter++;

            $pagesContainer = new Element('div');
            $pagesContainer->class = 'carousel-pages';
            $isFirst = true;
            foreach ($pages as $ntlChild) {
                $pageContainer = null;
                if ($ntlChild['link']) {
                    $pageContainer = new Element('a');
                    $link = $ntlChild['link'];
                    $pageContainer->href = "$link";
                } else {
                    $pageContainer = new Element('div');
                }
                $pageContainer->class = 'carousel-page';
                $pageContainer->appendChild($ntlChild['img']);
                $pageContainer->appendChild($ntlChild['caption']);
                if (trim($ntlChild['caption']->text())) {
                    $pageContainer->class .= ' page-has-caption';
                }
                $pagesContainer->appendChild($pageContainer);

                $radio = new Element('input');
                $radio->class = 'carousel-page-button';
                $radio->type = 'radio';
                $radio->name = $carouselId;
                if ($isFirst) {
                    $isFirst = false;
                    $radio->checked = '';
                }
                $carousel->appendChild($radio);
            }
            $carousel->appendChild($pagesContainer);
            if (sizeof($pages) === 1) {
                $carousel->class .= ' is-single-page';
            }
        }
    }

    protected function handleHTMLSectionMarkers($doc) {
        $secMarkers = $doc->find('.section-marker');
        foreach ($secMarkers as $sm) {
            $innerHtml = $sm->innerHtml();
            $newSM = new Element('h3');
            $newSM->class = $sm->class;
            if (isset($sm->id)) $newSM->id = $sm->id;
            $contentSpan = new Element('span');
            {
                $elements = new Document($innerHtml);
                $elements = $elements->find('body')[0];
                foreach ($elements->children() as $el) {
                    if ($el->tag === 'p') {
                        foreach ($el->children() as $child) $contentSpan->appendChild($child);
                    } else {
                        $contentSpan->appendChild($el);
                    }
                }
            }
            $contentSpan->class = 'section-marker-inner';
            $newSM->appendChild($contentSpan);
            $fillSpan = new Element('span');
            $fillSpan->class = 'section-marker-fill';
            $newSM->appendChild($fillSpan);
            $sm->replace($newSM);
        }
    }

    protected function handleHTMLExpandables($doc) {
        $unhandledExpandables = $doc->find('.unhandled-expandable');
        foreach ($unhandledExpandables as $exp) {
            $topLevelChildren = $exp->children();
            $summaryNodes = array();
            $didPassFirstBreak = false;
            $remainingNodes = array();
            foreach ($topLevelChildren as $child) {
                if (!$didPassFirstBreak and $child->isElementNode() and $child->tag === 'hr') {
                    $didPassFirstBreak = true;
                    continue;
                }
                if (!$didPassFirstBreak) {
                    $summaryNodes[] = $child;
                } else {
                    $remainingNodes[] = $child;
                }
            }
            $newExpNode = new Element('details');
            $newExpNode->class = 'expandable';
            $summaryNode = new Element('summary');
            foreach ($summaryNodes as $child) {
                $summaryNode->appendChild($child);
            }
            $newExpNode->appendChild($summaryNode);
            foreach ($remainingNodes as $child) {
                $newExpNode->appendChild($child);
            }
            $exp->replace($newExpNode);
        }
    }

    function getCountries() {
        $res = $this->bridge->get('/countries', array(
            'limit' => 300,
            'fields' => ['code', 'name_eo'],
            'order' => [['name_eo', 'asc']],
        ), 600);
        if (!$res['k']) return null;
        return $res['b'];
    }

    // Formats a country code
    function formatCountry($code) {
        foreach ($this->getCountries() as $country) {
            if ($country['code'] === $code) return $country['name_eo'];
        }
        return null;
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
                $data = json_decode($textContent);
                $listId = $data->id;
                $codeholders = $data->codeholders;
                $dataOrgs = $data->data_orgs;

                foreach ($codeholders as $codeholder) {
                    $chNode = new Element('li');
                    $chNode->class = 'codeholder-item';
                    $chNode->setAttribute('data-codeholder-id', $codeholder->id);

                    $left = new Element('div');
                    $left->class = 'item-picture-container';
                    $imgBack = new Element('img');
                    $imgBack->class = 'item-back-picture';
                    $imgBack->width = '1';
                    $imgBack->height = '1';
                    $img = new Element('img');
                    $img->class = 'item-picture';

                    $canSeePP = $codeholder->profilePicturePublicity === 'public'
                        || ($isMember && $codeholder->profilePicturePublicity === 'members');

                    if ($canSeePP && $codeholder->profilePictureHash) {
                        // codeholder has a profile picture
                        $picPrefix = AksoBridgePlugin::CODEHOLDER_PICTURE_PATH . '?'
                            . 'c=' . $codeholder->id
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

                    $canSeeAddress = $codeholder->addressPublicity === 'public'
                        || ($isMember && $codeholder->addressPublicity === 'members');

                    if ($codeholder->publicCountry || $canSeeAddress) {
                        $country = $codeholder->publicCountry ?: $codeholder->address->country;
                        $countryBadge = new Element('div');
                        $countryBadge->class = 'item-country-badge';

                        $emoji = $this->getEmojiForFlag($country);
                        $countryFlag = new Element('img');
                        $countryFlag->class = 'inline-flag-icon';
                        $countryFlag->draggable = 'false';
                        $countryFlag->alt = $emoji['alt'];
                        $countryFlag->src = $emoji['src'];

                        $countryName = new Element('span', ' ' . $this->formatCountry($country));
                        $countryBadge->appendChild($countryFlag);
                        $countryBadge->appendChild($countryName);
                        $right->appendChild($countryBadge);
                    }

                    $codeholderName = '';
                    if ($codeholder->codeholderType === 'human') {
                        $canSeeLastName = $codeholder->lastNamePublicity === 'public'
                            || ($isMember && $codeholder->lastNamePublicity === 'members');

                        $codeholderName = $codeholder->honorific ?: '';
                        if ($codeholderName) $codeholderName .= ' ';
                        if ($codeholder->firstName) $codeholderName .= $codeholder->firstName;
                        else $codeholderName .= $codeholder->firstNameLegal;

                        if ($canSeeLastName) {
                            if ($codeholder->lastName) $codeholderName .= ' ' . $codeholder->lastName;
                            else if ($codeholder->lastNameLegal) $codeholderName .= ' ' . $codeholder->lastNameLegal;
                        }
                    } else if ($codeholder->codeholderType === 'org') {
                        $codeholderName = $codeholder->fullName;
                    }
                    $nameContainer = new Element('div', $codeholderName);
                    $nameContainer->class = 'item-name';
                    $right->appendChild($nameContainer);

                    $rolesContainer = new Element('ul');
                    $rolesContainer->class = 'item-roles';
                    foreach ($codeholder->activeRoles as $role) {
                        $li = new Element('li');
                        $li->class = 'item-role';

                        $roleName = new Element('span', $role->role->name);
                        $roleName->class = 'role-name';
                        $li->appendChild($roleName);

                        if ($role->dataCountry || $role->dataString || $role->dataOrg) {
                            $roleDetails = new Element('span');
                            $roleDetails->class = 'role-details';
                            $roleDetails->appendChild(new Element('span', '('));

                            if ($role->dataCountry) {
                                $dCountry = new Element('span', $this->formatCountry($role->dataCountry));
                                $dCountry->class = 'detail-country';
                                $roleDetails->appendChild($dCountry);
                            }

                            if ($role->dataOrg) {
                                if ($role->dataCountry) $roleDetails->appendChild(new Element('span', ': '));
                                $orgId = $role->dataOrg;
                                $dOrg = new Element('span', $dataOrgs->$orgId->nameAbbrev);
                                $dOrg->title = $dataOrgs->$orgId->fullName;
                                $dOrg->class = 'detail-org';
                                $roleDetails->appendChild($dOrg);
                            }

                            if ($role->dataString) {
                                if ($role->dataCountry || $role->dataOrg) $roleDetails->appendChild(new Element('span', ', '));
                                $dStr = new Element('span', $role->dataString);
                                $dStr->class = 'detail-string';
                                $roleDetails->appendChild($dStr);
                            }

                            $roleDetails->appendChild(new Element('span', ')'));
                            $li->appendChild(new Element('span', ' '));
                            $li->appendChild($roleDetails);
                        }

                        $rolesContainer->appendChild($li);
                    }
                    $right->appendChild($rolesContainer);

                    $canSeeEmail = $codeholder->emailPublicity === 'public'
                        || ($isMember && $codeholder->emailPublicity === 'members');

                    if ($codeholder->publicEmail || ($canSeeEmail && $codeholder->email)) {
                        $emailContainer = new Element('div');
                        $emailContainer->class = 'item-email';
                        $emailLink = Utils::obfuscateEmail($codeholder->publicEmail ?: $codeholder->email);
                        $emailContainer->appendChild($emailLink);
                        $right->appendChild($emailContainer);
                    }

                    if ($codeholder->website) {
                        $websiteContainer = new Element('div');
                        $websiteContainer->class = 'item-website';
                        $websiteLink = new Element('a', $codeholder->website);
                        $websiteLink->target = '_blank';
                        $websiteLink->rel = 'nofollow noreferrer';
                        $websiteLink->href = $codeholder->website;
                        $websiteContainer->appendChild($websiteLink);
                        $right->appendChild($websiteContainer);
                    }

                    if ($canSeeAddress && $codeholder->address) {
                        $countryName = $this->formatCountry($codeholder->address->country);
                        $formatted = $this->bridge->renderAddress(array(
                            'countryCode' => $codeholder->address->country,
                            'countryArea' => $codeholder->address->countryArea,
                            'city' => $codeholder->address->city,
                            'cityArea' => $codeholder->address->cityArea,
                            'streetAddress' => $codeholder->address->streetAddress,
                            'postalCode' => $codeholder->address->postalCode,
                            'sortingCode' => $codeholder->address->sortingCode,
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

                    if ($codeholder->biography) {
                        $bioContainer = new Element('div', $codeholder->biography);
                        $bioContainer->class = 'item-bio';
                        $right->appendChild($bioContainer);
                    }

                    $chNode->appendChild($left);
                    $chNode->appendChild($right);

                    $newList->appendChild($chNode);
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

    protected function handleHTMLNews($doc) {
        $unhandledNews = $doc->find('.news-sidebar');
        foreach ($unhandledNews as $news) {
            $textContent = $news->text();
            if (strncmp($textContent, '!', 1) === 0) {
                // this is an error; skip
                $news->replace($this->createError($doc));
                continue;
            }

            $newNews = new Element('aside');
            $newNews->class = 'news-sidebar';

            try {
                $readMoreLabel = $this->plugin->locale['content']['news_read_more'];
                $moreNewsLabel = $this->plugin->locale['content']['news_sidebar_more_news'];

                $params = json_decode($textContent, true);

                $newsTitle = $params['title'];
                $newsPath = $params['target'];
                $newsCount = $params['count'];

                $newsPage = $this->plugin->getGrav()['pages']->find($newsPath);
                $newsPostCollection = $newsPage->collection();

                $newsPages = [];
                for ($i = 0; $i < min($newsCount, $newsPostCollection->count()); $i++) {
                    $newsPostCollection->next();
                    // for some reason, calling next() after current() causes the first item
                    // to duplicate
                    $newsPages[] = $newsPostCollection->current();
                }
                $hasMore = count($newsPages) < $newsPostCollection->count();

                $moreNews = new Element('div');
                $moreNews->class = 'more-news-container';
                $moreNewsLink = new Element('a', $moreNewsLabel);
                $moreNewsLink->class = 'more-news-link link-button';
                $moreNewsLink->href = $newsPath;
                $moreNews->appendChild($moreNewsLink);

                $title = new Element('h4', $newsTitle);
                $title->class = 'news-title';
                if ($hasMore) $title->appendChild($moreNews);
                $newNews->appendChild($title);

                $newNewsList = new Element('ul');
                $newNewsList->class = 'news-items';

                foreach ($newsPages as $page) {
                    $li = new Element('li');
                    $li->class = 'news-item';
                    $pageLink = new Element('a', $page->title());
                    $pageLink->class = 'item-title';
                    $pageLink->href = $page->url();
                    $li->appendChild($pageLink);
                    $pageDate = $page->date();
                    $itemMeta = new Element('div', Utils::formatDate((new \DateTime("@$pageDate"))->format('Y-m-d')));
                    $itemMeta->class = 'item-meta';
                    $li->appendChild($itemMeta);
                    $itemDescription = new Element('div');
                    $itemDescription->class = 'item-description';
                    $itemDescription->setInnerHTML($page->summary());
                    $li->appendChild($itemDescription);
                    $itemReadMore = new Element('div');
                    $itemReadMore->class = 'item-read-more';
                    $itemReadMoreLink = new Element('a', $readMoreLabel);
                    $itemReadMoreLink->href = $page->url();
                    $itemReadMore->appendChild($itemReadMoreLink);
                    $li->appendChild($itemReadMore);
                    $newNewsList->appendChild($li);
                }
                $newNews->appendChild($newNewsList);

                if ($hasMore) {
                    $moreNews->class = 'more-news-container is-footer-container';
                    $newNews->appendChild($moreNews);
                }

                $news->replace($newNews);
            } catch (Exception $e) {
                // oh no
                $newNews->class .= ' is-error';
                $news->replace($newNews);
            }
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

        // styles, too
        /* $styles = $doc->find('.page-container style');
        foreach ($styles as $style) {
            $replacement = new Element('div', '<style>' . $style->text() . '</style>');
            $replacement->class = 'illegal-style-tag';
            $style->replace($replacement);
        } */

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
