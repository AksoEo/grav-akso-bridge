<?php
namespace Grav\Plugin\AksoBridge;

use \DiDom\Document;
use \DiDom\Element;
use Ds\Set;
use Exception;
use Grav\Common\Grav;
use Grav\Plugin\AksoBridge\Utils;

// Handles rendering of congress fields in markdown.
class CongressFields {
    private $plugin;
    public $bridge;
    private $cache = array();

    public function __construct($bridge, $plugin) {
        $this->plugin = $plugin;
        $this->bridge = $bridge;
    }

    private function createError() {
        return array(
            'name' => 'span',
            'attributes' => array('class' => 'md-render-error'),
            'text' => $this->plugin->locale['content']['render_error'],
        );
    }

    // Renders an HTML descriptor for the given congress field
    private function getCongressField($congress, $field) {
        $id = $congress;
        if (!isset($this->cache[$id])) {
            $res = $this->bridge->get('/congresses/' . $congress, array(
                'fields' => ['name', 'abbrev'],
            ), 60);
            if (!$res['k']) {
                return [$this->createError()];
            }
            $this->cache[$id] = $res['b'];
        }
        $data = $this->cache[$id];

        if ($field === 'nomo') return [array('name' => 'span', 'text' => $data['name'])];
        if ($field === 'mallongigo') return [array('name' => 'span', 'text' => $data['abbrev'])];
        return [$this->createError()];
    }

    // Renders an HTML descriptor for the given congress instance field
    private function getInstanceField($congress, $instance, $field, $args) {
        $id = $congress . '/' . $instance;
        if (!isset($this->cache[$id])) {
            $res = $this->bridge->get('/congresses/' . $congress . '/instances/' . $instance, array(
                'fields' => ['name', 'humanId', 'dateFrom', 'dateTo', 'locationName', 'locationNameLocal'],
            ), 60);
            if (!$res['k']) {
                return [$this->createError()];
            }
            $this->cache[$id] = $res['b'];
        }
        $data = $this->cache[$id];

        if ($field === 'nomo') return [array('name' => 'span', 'text' => $data['name'])];
        if ($field === 'homaid') return [array('name' => 'span', 'text' => $data['humanId'])];
        if ($field === 'komenco') return [array('name' => 'span', 'text' => Utils::formatDate($data['dateFrom']))];
        if ($field === 'fino') return [array('name' => 'span', 'text' => Utils::formatDate($data['dateTo']))];
        else if ($field === 'lokonomo') return [array('name' => 'span', 'text' => $data['locationName'])];
        else if ($field === 'lokonomoloka') return [array('name' => 'span', 'text' => $data['locationNameLocal'])];
        if ($field === 'tempokalkulo' || $field === 'tempokalkulo!') {
            $firstEventRes = $this->bridge->get('/congresses/' . $congress . '/instances/' . $instance . '/programs', array(
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
                $timeZone = isset($data['tz']) ? new \DateTimeZone($data['tz']) : new \DateTimeZone('+00:00');
                $dateStr = $data['dateFrom'] . ' 12:00:00';
                $congressStartTime = \DateTime::createFromFormat("Y-m-d H:i:s", $dateStr, $timeZone);
            }

            $isLarge = $field === 'tempokalkulo!';

            return [array(
                'name' => 'span',
                'attributes' => array(
                    'class' => 'congress-countdown live-countdown' . ($isLarge ? ' is-large' : ''),
                    'data-timestamp' => $congressStartTime->getTimestamp(),
                ),
            )];
        } else if ($field === 'aliĝintoj') {
            return [array(
                'name' => 'script',
                'attributes' => array(
                    'class' => 'akso-congress-participants',
                    'congress' => $congress,
                    'instance' => $instance,
                    'data' => json_encode($data),
                    'args' => json_encode($args),
                ),
            )];
        } else if ($field === 'kvantoaliĝintoj' || $field === 'kvantounikajlandoj') {
            return [array(
                'name' => 'script',
                'attributes' => array(
                    'class' => 'akso-congress-participants-meta',
                    'congress' => $congress,
                    'instance' => $instance,
                    'field' => $field,
                ),
            )];
        }
        return [$this->createError()];
    }

    // Renders a congress/instance field. (Set instance to null for congress field).
    // Returns an HTML node descriptor.
    public function renderField($extent, $field, $congress, $instance, $args) {
        $isInstance = $instance !== null;

        $contents = null;
        if ($isInstance) {
            $contents = $this->getInstanceField($congress, $instance, $field, $args);
        } else {
            $contents = $this->getCongressField($congress, $field, $args);
        }

        return array(
            'extent' => $extent,
            'element' => array(
                'name' => 'span',
                'handler' => 'elements',
                'attributes' => array(
                    'class' => 'akso-congress-field',
                ),
                'text' => $contents,
            ),
        );
    }

    // HTML post-processing.
    public function handleHTMLCongressStuff($doc) {
        $countdowns = $doc->find('.congress-countdown');
        foreach ($countdowns as $countdown) {
            $ts = $countdown->getAttribute('data-timestamp');
            $tsTime = new \DateTime();
            $tsTime->setTimestamp((int) $ts);
            $now = new \DateTime();
            $deltaInterval = $now->diff($tsTime);

            $contents = new Element('span', htmlspecialchars(Utils::formatDuration($deltaInterval)));
            $countdown->appendChild($contents);
        }

        $locations = $doc->find('.congress-location');
        foreach ($locations as $location) {
            $contents = new Element('span', htmlspecialchars($location->getAttribute('data-name')));
            $location->appendChild($contents);
        }

        $dateSpans = $doc->find('.congress-date-span');
        foreach ($dateSpans as $dateSpan) {
            $startDate = \DateTime::createFromFormat('Y-m-d', $dateSpan->getAttribute('data-from'));
            $endDate = \DateTime::createFromFormat('Y-m-d', $dateSpan->getAttribute('data-to'));

            if (!$startDate || !$endDate) continue;

            $startYear = $startDate->format('Y');
            $endYear = $endDate->format('Y');
            $startMonth = $startDate->format('m');
            $endMonth = $endDate->format('m');
            $startDate = $startDate->format('d');
            $endDate = $endDate->format('d');

            $span = '';
            if ($startYear === $endYear) {
                if ($startMonth === $endMonth) {
                    $span = $startDate . '–' . $endDate . ' ' . Utils::formatMonth($startMonth) . ' ' . $startYear;
                } else {
                    $span = $startDate . ' ' . Utils::formatMonth($startMonth);
                    $span .= '–' . $endDate . ' ' . Utils::formatMonth($endMonth);
                    $span .= ' ' . $startYear;
                }
            } else {
                $span = $startDate . ' ' . Utils::formatMonth($startMonth) . ' ' . $startYear;
                $span .= '–' . $endDate . ' ' . Utils::formatMonth($endMonth) . ' ' . $endYear;
            }

            $contents = new Element('span', htmlspecialchars($span));
            $dateSpan->appendChild($contents);
        }

        $participants = $doc->find('.akso-congress-participants');
        foreach ($participants as $list) {
            $congress = $list->getAttribute('congress');
            $instance = $list->getAttribute('instance');
            $data = json_decode($list->getAttribute('data'), true);
            $args = json_decode($list->getAttribute('args'), true);

            $rendered = $this->renderCongressParticipants($congress, $instance, $data, $args);
            $rendered = Utils::parsedownElementsToHTML($rendered);

            $containerNode = new Element('span');
            $containerNode->setInnerHtml($rendered);
            $list->replace($containerNode);
        }

        $participantsMeta = $doc->find('.akso-congress-participants-meta');
        foreach ($participantsMeta as $node) {
            $congress = $node->getAttribute('congress');
            $instance = $node->getAttribute('instance');
            $field = $node->getAttribute('field');

            $rendered = $this->renderCongressParticipantsMeta($congress, $instance, $field);
            $rendered = Utils::parsedownElementsToHTML($rendered);

            $containerNode = new Element('span');
            $containerNode->setInnerHtml($rendered);
            $node->replace($containerNode);
        }
    }

    private function renderCongressParticipants($congressId, $instanceId, $instanceInfo, $args) {
        if (count($args) < 2) {
            return [$this->createError()];
        }

        $validOnly = false;
        if (mb_strtolower($args[0]) === '!nur-validaj') {
            array_shift($args);
            $validOnly = true;
        }

        $show_name_var = \Normalizer::normalize($args[0]);
        $first_name_var = \Normalizer::normalize($args[1]);
        $extra_vars = [];

        for ($i = 2; $i < count($args); $i += 2) {
            $arg_var = \Normalizer::normalize($args[$i]);
            $arg_label = $args[$i + 1] ?? '';
            $extra_vars[] = [$arg_var, $arg_label];
        }

        $formRes = $this->bridge->get('/congresses/' . $congressId . '/instances/' . $instanceId . '/registration_form', array(
            'fields' => ['form'],
        ), 60);
        if (!$formRes['k']) {
            return [$this->createError()];
        }
        $regForm = $formRes['b']['form'];
        $regFormInputs = [];
        $fields = ['codeholderId'];
        foreach ($regForm as $field) {
            if ($field['el'] === 'input') {
                $regFormInputs[$field['name']] = $field;
                $fields[] = 'data.' . $field['name'];
            }
        }

        $participants = [];
        $totalParticipants = 1;

        $participantsFilter = array('cancelledTime' => null);

        if ($validOnly) {
            $participantsFilter = array(
                '$and' => [
                    $participantsFilter,
                    array('isValid' => true),
                ],
            );
        }

        while (count($participants) < $totalParticipants) {
            $res = $this->bridge->get("/congresses/$congressId/instances/$instanceId/participants", array(
                'offset' => count($participants),
                'limit' => 100,
                'fields' => $fields,
                'order' => [['sequenceId', 'asc'], ['createdTime', 'asc']],
                'filter' => $participantsFilter,
            ), 120);
            if (!$res['k']) {
                Grav::instance()['log']->error(
                    "markdown: could not load congress participants for $congressId/$instanceId: "
                    . $res['b']
                );

                $totalParticipants = -1;
                break;
            }
            $totalParticipants = $res['h']['x-total-items'];
            foreach ($res['b'] as $item) {
                $fvars = $item['data'];
                $stack = [];

                $should_show_name = false;
                $first_name = null;

                foreach ($regForm as $formItem) {
                    if ($formItem['el'] === 'script') $stack[] = $formItem['script'];
                }
                if ($show_name_var === '@') {
                    $should_show_name = true;
                } else {
                    $res = $this->bridge->evalScript($stack, $fvars, array('t' => 'c', 'f' => 'id', 'a' => [$show_name_var]));
                    if ($res['s']) {
                        $should_show_name = $res['v'] === true;
                    } else {
                        Grav::instance()['log']->error(
                            "markdown: could not load congress participants for $congressId/$instanceId: "
                            . $res['e']
                        );
                        $totalParticipants = -1;
                        break;
                    }
                }
                $res = $this->bridge->evalScript($stack, $fvars, array('t' => 'c', 'f' => 'id', 'a' => [$first_name_var]));
                if ($res['s']) {
                    $first_name = gettype($res['v']) === 'array' ? implode('', $res['v']) : (string) $res['v'];
                } else {
                    Grav::instance()['log']->error(
                        "markdown: could not load congress participants for $congressId/$instanceId: "
                        . $res['e']
                    );
                    $totalParticipants = -1;
                    break;
                }

                $item['show_name'] = $should_show_name;
                $item['first_name'] = $first_name ?? '';
                foreach ($extra_vars as [$field]) {
                    $res = $this->bridge->evalScript($stack, $fvars, array('t' => 'c', 'f' => 'id', 'a' => [$field]));
                    if ($res['s']) {
                        $item['extra_fields'][$field] = gettype($res['v']) === 'array' ? implode('', $res['v']) : (string) $res['v'];
                    } else {
                        Grav::instance()['log']->error(
                            "markdown: could not load congress participants for $congressId/$instanceId: "
                            . $res['e']
                        );
                        $totalParticipants = -1;
                        break;
                    }
                }
                $participants[] = $item;
            }
        }

        if ($totalParticipants === -1) {
            // error...
            return [$this->createError()];
        }

        $hasExtraFields = !empty($extra_vars);
        $plist = [];
        $ptableHeader = [array(
            'name' => 'th',
            'text' => $this->plugin->locale['content']['congress_participants_th_name'],
        )];
        $ptable = [];

        foreach ($extra_vars as [$field, $label]) {
            $ptableHeader[] = array(
                'name' => 'th',
                'text' => $label,
            );
        }

        foreach ($participants as $part) {
            if (!$part['show_name']) {
                continue;
            }
            $tableCells = [];

            $name = $part['first_name'];
            $tableCells[] = array('name' => 'th', 'text' => $name);

            $details = [];
            foreach ($extra_vars as [$field, $label]) {
                $value = $part['extra_fields'][$field];
                $fmtValue = $value;
                $fmtValueHandler = null;

                if (gettype($value) === 'array') {
                    $fmtValueHandler = 'elements';
                    $ulItems = [];

                    foreach ($value as $valueItem) {
                        $ulItems[] = array(
                            'name' => 'li',
                            'text' => (string) $valueItem,
                        );
                    }

                    $fmtValue = [array(
                        'name' => 'ul',
                        'handler' => 'elements',
                        'text' => $ulItems,
                    )];
                }

                $details[] = array(
                    'name' => 'li',
                    'handler' => 'elements',
                    'text' => [array(
                        'name' => 'div',
                        'attributes' => array('class' => 'detail-item-label'),
                        'text' => $label,
                    ), array(
                        'name' => 'div',
                        'attributes' => array('class' => 'detail-item-value'),
                        'handler' => $fmtValueHandler,
                        'text' => $fmtValue,
                    )],
                );
                $tableCells[] = array('name' => 'td', 'handler' => $fmtValueHandler, 'text' => $fmtValue);
            }

            $plist[] = array(
                'name' => 'li',
                'handler' => 'elements',
                'attributes' => array('class' => 'plist-participant'),
                'text' => [
                    array(
                        'name' => 'div',
                        'attributes' => array('class' => 'participant-name'),
                        'text' => $name,
                    ),
                    array(
                        'name' => 'ul',
                        'attributes' => array('class' => 'participant-details' . (empty($details) ? ' is-empty' : '')),
                        'handler' => 'elements',
                        'text' => $details,
                    ),
                ],
            );
            $ptable[] = array('name' => 'tr', 'handler' => 'elements', 'text' => $tableCells);
        }

        if (empty($plist)) {
            $plist[] = array(
                'name' => 'li',
                'attributes' => array('class' => 'plist-empty'),
                'text' => $this->plugin->locale['content']['congress_participants_empty'],
            );
            $ptable[] = array(
                'name' => 'tr',
                'attributes' => array('class' => 'ptable-empty'),
                'handler' => 'elements',
                'text' => [array(
                    'name' => 'td',
                    'attributes' => array('colspan' => count($ptableHeader)),
                    'text' => $this->plugin->locale['content']['congress_participants_empty'],
                )],
            );
        }

        $title = $this->plugin->locale['content']['congress_participants_title'];
        $congressName = $instanceInfo['name'];
        $partCount = $this->plugin->locale['content']['congress_participants_count_0']
            . $totalParticipants . $this->plugin->locale['content']['congress_participants_count_1'];
        $participantsEl = array(
            'name' => 'div',
            'handler' => 'elements',
            'attributes' => array(
                'class' => 'akso-congress-field-participants' . ($hasExtraFields ? ' has-extra-fields' : ''),
                'role' => 'group',
                'aria-label' => $title,
            ),
            'text' => [array(
                'name' => 'div',
                'attributes' => array('class' => 'participants-header'),
                'handler' => 'elements',
                'text' => [array(
                    'name' => 'div',
                    'attributes' => array(
                        'class' => 'participants-title',
                        'aria-hidden' => true,
                    ),
                    'text' => $title,
                ), array(
                    'name' => 'div',
                    'attributes' => array('class' => 'participants-subtitle'),
                    'handler' => 'elements',
                    'text' => [array(
                        'name' => 'div',
                        'attributes' => array('class' => 'participants-congress-name'),
                        'text' => $congressName,
                    ), array(
                        'name' => 'div',
                        'attributes' => array('class' => 'participants-count'),
                        'text' => $partCount,
                    )],
                )],
            ), array(
                'name' => 'ul',
                'attributes' => array('class' => 'participants-list'),
                'handler' => 'elements',
                'text' => $plist,
            ), array(
                'name' => 'table',
                'attributes' => array('class' => 'participants-table'),
                'handler' => 'elements',
                'text' => [array(
                    'name' => 'thead',
                    'handler' => 'elements',
                    'text' => [array(
                        'name' => 'tr',
                        'handler' => 'elements',
                        'text' => $ptableHeader,
                    )],
                ), array(
                    'name' => 'tbody',
                    'handler' => 'elements',
                    'text' => $ptable,
                )],
            )],
        );

        return [$participantsEl];
    }

    private function renderCongressParticipantsMeta($congressId, $instanceId, $field) {
        if ($field === 'kvantoaliĝintoj') {
            $res = $this->bridge->get("/congresses/$congressId/instances/$instanceId/participants", array(
                'offset' => 0,
                'limit' => 1,
                'filter' => array('isValid' => true),
            ), 60);
            if (!$res['k']) return [$this->createError()];
            return [array('name' => 'span', 'text' => $res['h']['x-total-items'])];
        } else if ($field === 'kvantounikajlandoj') {
            $formRes = $this->bridge->get('/congresses/' . $congressId . '/instances/' . $instanceId . '/registration_form', array(
                'fields' => ['form', 'identifierCountryCode'],
            ), 60);
            if (!$formRes['k']) return [$this->createError()];
            $regForm = $formRes['b']['form'];
            $fields = [];
            foreach ($regForm as $field) {
                if ($field['el'] === 'input') {
                    $fields[] = 'data.' . $field['name'];
                }
            }

            $uniqueCountries = new Set();
            $hasParticipants = 0;
            $totalParticipants = 1;
            $countryVar = $formRes['b']['identifierCountryCode'];

            $stack = [];
            foreach ($regForm as $formItem) {
                if ($formItem['el'] === 'script') $stack[] = $formItem['script'];
            }

            while ($hasParticipants < $totalParticipants) {
                $res = $this->bridge->get("/congresses/$congressId/instances/$instanceId/participants", array(
                    'offset' => $hasParticipants,
                    'limit' => 100,
                    'fields' => $fields,
                    'filter' => array('isValid' => true),
                ), 60);
                if (!$res['k']) return [$this->createError()];
                $participants = $res['b'];
                $totalParticipants = $res['h']['x-total-items'];
                $hasParticipants += count($participants);

                foreach ($participants as $participant) {
                    $fvars = $participant['data'];
                    $res = $this->bridge->evalScript($stack, $fvars, array('t' => 'c', 'f' => 'id', 'a' => [$countryVar]));
                    if ($res['s']) {
                        if (gettype($res['v']) === 'string') {
                            $uniqueCountries->add($res['v']);
                        }
                    } else {
                        Grav::instance()['log']->error(
                            "markdown: could not get country for congress participant in $congressId/$instanceId: "
                            . $res['e']
                        );
                        $totalParticipants = -1;
                        break;
                    }
                }
            }

            return [array('name' => 'span', 'text' => $uniqueCountries->count())];
        }

        throw new Exception('unexpected field type');
    }
}
