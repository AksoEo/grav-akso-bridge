<?php
namespace Grav\Plugin\AksoBridge;

use Grav\Plugin\AksoBridge\CongressLocations;
use Grav\Plugin\AksoBridge\Utils;

// Handles the congress programs page type.
class CongressPrograms {
    // query parameters
    const QUERY_DATE = 'd';
    const QUERY_DATE_ALL = '';
    const QUERY_LOC = 'loc';
    const QUERY_PROG = 'prog';

    private $plugin;
    private $app;
    private $congressId = null;
    private $instanceId = null;
    private $doc;

    public function __construct($plugin, $app, $congressId, $instanceId) {
        $this->plugin = $plugin;
        $this->app = $app;
        $this->congressId = $congressId;
        $this->instanceId = $instanceId;

        $this->doc = new \DOMDocument();

        $this->load();
    }

    // set this to a relative path to enable location links
    public $locationsPath = null;

    private $congress = null;
    private $tz = null;

    // loads congress info ($congress, $tz)
    function load() {
        $congressId = $this->congressId;
        $instanceId = $this->instanceId;
        $res = $this->app->bridge->get("/congresses/$congressId/instances/$instanceId", array(
            'fields' => ['tz', 'dateFrom', 'dateTo'],
        ), 240);
        if ($res['k']) {
            $this->congress = $res['b'];
            try {
                $this->tz = new \DateTimeZone($res['b']['tz']);
            } catch (\Exception $e) {}
        }
    }

    /// Renders a single program item in the list
    function renderProgramItem($program, $locations) {
        $node = $this->doc->createElement('div');
        $node->setAttribute('class', 'program-item');

        $timeFrom = (new \DateTime('@' . $program['timeFrom'], $this->tz))->format('H:i');
        $timeTo = (new \DateTime('@' . $program['timeTo'], $this->tz))->format('H:i');

        $timeLabel = $this->doc->createElement('div');
        $timeLabel->setAttribute('class', 'item-time-span');
        $timeFromLabel = $this->doc->createElement('span');
        $timeFromLabel->setAttribute('class', 'time-from');
        $timeSpanLabel = $this->doc->createElement('span');
        $timeSpanLabel->setAttribute('class', 'time-span');
        $timeToLabel = $this->doc->createElement('span');
        $timeToLabel->setAttribute('class', 'time-to');
        $timeFromLabel->textContent = $timeFrom;
        $timeSpanLabel->textContent = '–';
        $timeToLabel->textContent = $timeTo;
        $timeLabel->appendChild($timeFromLabel);
        $timeLabel->appendChild($timeSpanLabel);
        $timeLabel->appendChild($timeToLabel);
        $node->appendChild($timeLabel);

        $titleContainer = $this->doc->createElement('div');
        $titleContainer->setAttribute('class', 'program-title-container');
        $node->appendChild($titleContainer);

        $linkTarget = $this->plugin->getGrav()['uri']->path() . '?' . self::QUERY_PROG . '=' . $program['id'];

        $titleNode = $this->doc->createElement('h3');
        $titleNode->setAttribute('class', 'program-title');
        $titleLinkNode = $this->doc->createElement('a');
        $titleLinkNode->setAttribute('href', $linkTarget);
        $titleLinkNode->textContent = $program['title'];
        $titleNode->appendChild($titleLinkNode);
        $titleContainer->appendChild($titleNode);

        if ($program['location'] && isset($locations[$program['location']])) {
            $location = $locations[$program['location']];

            $locationContainer = $this->doc->createElement('div');
            $locationContainer->setAttribute('class', 'location-container');

            $locationPre = $this->doc->createElement('span');
            $locationPre->setAttribute('class', 'location-itext');
            $locationPre->textContent = $this->plugin->locale['congress_programs']['program_location_pre'] . ' ';
            $locationContainer->appendChild($locationPre);

            $locationLink = $this->renderLocationLink($location);
            $locationContainer->appendChild($locationLink);
            $titleContainer->appendChild($locationContainer);
        }

        $description = $this->doc->createElement('div');
        $description->setAttribute('class', 'program-description');
        $rules = ['emphasis', 'strikethrough', 'link', 'list', 'table', 'image'];
        if ($program['description']) {
            $res = $this->app->bridge->renderMarkdown($program['description'], $rules);
            Utils::setInnerHTML($description, $res['c']);
        }
        $node->appendChild($description);

        return $node;
    }

    // loads all locations with the given ids
    function batchLoadLocations($ids) {
        $locations = [];
        for ($i = 0; true; $i += 100) {
            $congressId = $this->congressId;
            $instanceId = $this->instanceId;
            $res = $this->app->bridge->get("/congresses/$congressId/instances/$instanceId/locations", array(
                'fields' => ['id', 'name', 'icon'],
                'filter' => ['id' => ['$in' => $ids->slice(0, 100)->toArray()]],
                'limit' => 100,
                'offset' => $i,
            ));
            if (!$res['k']) {
                // TODO: emit error
                break;
            }
            foreach ($res['b'] as $loc) {
                $locations[$loc['id']] = $loc;
                $ids->remove($loc['id']);
            }
            if ($ids->isEmpty()) break;
        }
        return $locations;
    }

    // Renders the program list for a single day
    // - $date: string like 2020-01-02
    function renderDayAgenda($date, $showNoItems = false, $extraFilter = []) {
        $unixFrom = \DateTime::createFromFormat("Y-m-d", $date, $this->tz);
        $unixFrom->setTime(0, 0, 0);
        $unixTo = \DateTime::createFromFormat("Y-m-d", $date, $this->tz);
        $unixTo->setTime(24, 0, 0);
        $unixFrom = (int) $unixFrom->format('U');
        $unixTo = (int) $unixTo->format('U');

        $programs = [];

        while (true) {
            $congressId = $this->congressId;
            $instanceId = $this->instanceId;
            $res = $this->app->bridge->get("/congresses/$congressId/instances/$instanceId/programs", array(
                'fields' => ['id', 'title', 'description', 'timeFrom', 'timeTo', 'location'],
                'filter' => array_merge(array(
                    'timeTo' => ['$gte' => $unixFrom],
                    'timeFrom' => ['$lt' => $unixTo],
                ), $extraFilter),
                'offset' => count($programs),
                'order' => [['timeFrom', 'asc']],
                'limit' => 100,
            ), 60);
            if (!$res['k']) {
                // TODO: emit error
                break;
            }
            foreach ($res['b'] as $program) {
                $programs[] = $program;
            }
            if (count($programs) >= $res['h']['x-total-items']) break;
        }

        $locationIds = new \Ds\Set();
        foreach ($programs as $program) {
            if ($program['location']) {
                $locationIds->add($program['location']);
            }
        }
        $locations = $this->batchLoadLocations($locationIds);

        $root = $this->doc->createElement('div');
        $root->setAttribute('class', 'program-day-agenda');

        $dayTitle = $this->doc->createElement('h2');
        $dayTitle->setAttribute('class', 'program-day-title');
        $dayTitle->textContent = Utils::formatDayMonth($date);
        $root->appendChild($dayTitle);

        foreach ($programs as $program) {
            $root->appendChild($this->renderProgramItem($program, $locations));
        }

        if ($showNoItems && count($programs) === 0) {
            $noItems = $this->doc->createElement('div');
            $noItems->setAttribute('class', 'no-items');
            $noItems->textContent = $this->plugin->locale['congress_programs']['no_items_on_this_day'];
            $root->appendChild($noItems);
        } else if (!$showNoItems && count($programs) === 0) {
            return null;
        }

        return $root;
    }

    // Renders the program for all days
    function renderWholeAgenda() {
        if (!$this->congress) return null;
        $root = $this->doc->createElement('div');
        $root->setAttribute('class', 'whole-program');

        $cursor = \DateTime::createFromFormat('Y-m-d', $this->congress['dateFrom']);
        for ($i = 0; $i < 64; $i++) {
            $date = $cursor->format('Y-m-d');

            $root->appendChild($this->renderDayAgenda($date, true));

            $cursor->setDate($cursor->format('Y'), $cursor->format('m'), $cursor->format('d') + 1);
            if ($cursor->format('Y-m-d') == $this->congress['dateTo']) {
                break;
            }
        }

        return $root;
    }

    // Renders the day switcher at the top
    function renderDaySwitcher($currentDate) {
        if (!$this->congress) {
            $error = $this->doc->createElement('div');
            $error->setAttribute('class', 'md-render-error');
            $error->textContent = $this->plugin->locale['content']['render_error'];
            return $error;
        }
        $node = $this->doc->createElement('div');
        $node->setAttribute('class', 'program-day-switcher');

        $button = $this->doc->createElement('a');
        $button->setAttribute('class', 'program-day link-button' . (!$currentDate ? ' is-primary' : ''));
        $button->setAttribute('href', $this->plugin->getGrav()['uri']->path() . '?' . self::QUERY_DATE . '=' . urlencode(self::QUERY_DATE_ALL));
        $button->textContent = $this->plugin->locale['congress_programs']['day_switcher_all'];
        $node->appendChild($button);

        $cursor = \DateTime::createFromFormat('Y-m-d', $this->congress['dateFrom']);
        for ($i = 0; $i < 64; $i++) {
            $date = $cursor->format('Y-m-d');

            $isCurrent = $date == $currentDate;
            $button = $this->doc->createElement('a');
            $button->setAttribute('class', 'program-day link-button' . ($isCurrent ? ' is-primary' : ''));
            $button->textContent = Utils::formatDayMonth($date);
            $node->appendChild($button);

            $button->setAttribute('href', $this->plugin->getGrav()['uri']->path() . '?' . self::QUERY_DATE . '=' . $date);

            $cursor->setDate($cursor->format('Y'), $cursor->format('m'), $cursor->format('d') + 1);
            if ($cursor->format('Y-m-d') == $this->congress['dateTo']) {
                break;
            }
        }

        return $node;
    }

    // Renders the detail page for a program item
    function renderProgramPage($programId) {
        $congressId = $this->congressId;
        $instanceId = $this->instanceId;
        $res = $this->app->bridge->get("/congresses/$congressId/instances/$instanceId/programs/$programId", array(
            'fields' => ['id', 'timeFrom', 'timeTo', 'title', 'location', 'description', 'owner'],
        ), 60);
        if (!$res['k']) {
            $this->plugin->getGrav()->fireEvent('onPageNotFound');
            return null;
        }
        $program = $res['b'];

        $timeFrom = new \DateTime('@' . $program['timeFrom'], $this->tz);
        $timeTo = new \DateTime('@' . $program['timeTo'], $this->tz);
        $dateFrom = $timeFrom->format('Y-m-d');
        $timeFrom = $timeFrom->format('H:i');
        $timeTo = $timeTo->format('H:i');

        $root = $this->doc->createElement('div');
        $root->setAttribute('class', 'program-page');

        {
            $time = $this->doc->createElement('div');
            $time->setAttribute('class', 'program-time');
            $root->appendChild($time);

            $timeDate = $this->doc->createElement('span');
            $time->appendChild($timeDate);
            $timeDate->setAttribute('class', 'time-date');
            $timeDate->textContent = Utils::formatDayMonth($dateFrom);

            $timeSpanContainer = $this->doc->createElement('span');
            $timeSpanContainer->setAttribute('class', 'time-span-container');
            $time->appendChild($timeSpanContainer);
            $timeSpanFrom = $this->doc->createElement('span');
            $timeSpanContainer->appendChild($timeSpanFrom);
            $timeSpanFrom->textContent = $timeFrom;

            $timeSpanSpan = $this->doc->createElement('span');
            $timeSpanSpan->setAttribute('class', 'time-span-span');
            $timeSpanContainer->appendChild($timeSpanSpan);
            $timeSpanSpan->textContent = '–';

            $timeSpanTo = $this->doc->createElement('span');
            $timeSpanContainer->appendChild($timeSpanTo);
            $timeSpanTo->textContent = $timeTo;
        }

        {
            $ptitle = $this->doc->createElement('h1');
            $ptitle->setAttribute('class', 'program-title');
            $ptitle->textContent = $program['title'];
            $root->appendChild($ptitle);
        }

        if ($program['location']) {
            $locationIds = new \Ds\Set();
            $locationIds->add($program['location']);
            $locations = $this->batchLoadLocations($locationIds);
            $location = $locations[$program['location']];

            if ($location) {
                $container = $this->doc->createElement('div');
                $container->setAttribute('class', 'location-container');
                $root->appendChild($container);

                $containerText = $this->doc->createElement('span');
                $containerText->setAttribute('class', 'location-itext');
                $containerText->textContent = $this->plugin->locale['congress_programs']['program_location_pre'];
                $container->appendChild($containerText);

                $container->appendChild($this->doc->createTextNode(' '));

                $locationLink = $this->renderLocationLink($location);
                $container->appendChild($locationLink);
            }
        }

        if ($program['owner']) {
            $programOwner = $this->doc->createElement('div');
            $programOwner->setAttribute('class', 'program-owner');
            $root->appendChild($programOwner);

            $programOwnerText = $this->doc->createElement('span');
            $programOwnerText->textContent = $this->plugin->locale['congress_programs']['program_owner_pre'];
            $programOwnerText->setAttribute('class', 'owner-itext');
            $programOwner->appendChild($programOwnerText);

            $programOwner->appendChild($this->doc->createTextNode(' '));

            $programOwnerContent = $this->doc->createElement('span');
            $programOwnerContent->setAttribute('class', 'owner-content');
            $programOwnerContent->textContent = $program['owner'];
            $programOwner->appendChild($programOwnerContent);
        }

        $description = $this->doc->createElement('div');
        $description->setAttribute('class', 'program-description');
        $rules = ['emphasis', 'strikethrough', 'link', 'list', 'table', 'image'];
        if ($program['description']) {
            $res = $this->app->bridge->renderMarkdown($program['description'], $rules);
            Utils::setInnerHTML($description, $res['c']);
        }
        $root->appendChild($description);

        return $root;
    }

    // Renders a link to a congress location with an icon
    function renderLocationLink($location) {
        $locationLink = $this->doc->createElement('a');
        $locationLink->setAttribute('class', 'location-link');

        if (isset($location['icon']) && $location['icon']) {
            $locationIcon = $this->doc->createElement('img');
            $locationIcon->setAttribute('class', 'location-icon');
            $locationIcon->setAttribute('src', CongressLocations::ICONS_PATH_PREFIX . $location['icon'] . CongressLocations::ICONS_PATH_SUFFIX);
            $locationLink->appendChild($locationIcon);
        }

        $locationName = $this->doc->createElement('span');
        $locationName->setAttribute('class', 'location-name');
        $locationName->textContent = $location['name'];
        $locationLink->appendChild($locationName);

        if ($this->locationsPath) {
            $locationLink->setAttribute('href', $this->locationsPath . '?' . CongressLocations::QUERY_LOC . '=' . $location['id']);
        }

        return $locationLink;
    }

    // Renders the “events in this location” page for the given location
    function renderEventsInLocation($locationId) {
        $congressId = $this->congressId;
        $instanceId = $this->instanceId;
        $res = $this->app->bridge->get("/congresses/$congressId/instances/$instanceId/locations/$locationId", array(
            'fields' => ['id', 'icon', 'name'],
        ));
        if (!$res['k']) {
            return null;
        }
        $location = $res['b'];

        $root = $this->doc->createElement('div');
        $root->setAttribute('class', 'location-program');

        {
            $title = $this->doc->createElement('div');
            $title->setAttribute('class', 'location-program-title');
            $title->appendChild($this->doc->createTextNode($this->plugin->locale['congress_programs']['location_program_title_pre']));
            $title->appendChild($this->doc->createTextNode(' '));

            $locationLink = $this->renderLocationLink($location);
            $title->appendChild($locationLink);
            $root->appendChild($title);
        }
        $hasAnyDay = false;

        $cursor = \DateTime::createFromFormat('Y-m-d', $this->congress['dateFrom']);
        for ($i = 0; $i < 255; $i++) {
            $date = $cursor->format('Y-m-d');

            $day = $this->renderDayAgenda($date, false, array(
                'location' => $locationId,
            ));
            if ($day) {
                $root->appendChild($day);
                $hasAnyDay = true;
            }

            $cursor->setDate($cursor->format('Y'), $cursor->format('m'), $cursor->format('d') + 1);
            if ($cursor->format('Y-m-d') == $this->congress['dateTo']) {
                break;
            }
        }

        if (!$hasAnyDay) {
            $noItems = $this->doc->createElement('div');
            $noItems->setAttribute('class', 'location-no-items');
            $noItems->textContent = $this->plugin->locale['congress_programs']['no_items_in_this_loc'];
            $root->appendChild($noItems);
        }

        return $root;
    }

    // Returns the date the user has requested, or null for all days.
    // returns YYYY-MM-DD string or null.
    function readCurrentDate() {
        if (!$this->congress) return null;
        $dateFrom = \DateTime::createFromFormat('Y-m-d', $this->congress['dateFrom']);
        $dateTo = \DateTime::createFromFormat('Y-m-d', $this->congress['dateTo']);

        $currentDate = null;
        $forceNull = false;

        if (isset($_GET[self::QUERY_DATE]) && gettype($_GET[self::QUERY_DATE]) === 'string') {
            if ($_GET[self::QUERY_DATE] === self::QUERY_DATE_ALL) {
                $forceNull = true;
            } else {
                $qdate = \DateTime::createFromFormat('Y-m-d', $_GET[self::QUERY_DATE]);
                if ($qdate !== false) $currentDate = $qdate;
            }
        }
        if (!$currentDate && !$forceNull) {
            // default to current date if congress is ongoing
            $currentDate = new \DateTime();
            if ($dateFrom->diff($currentDate)->invert || $currentDate->diff($dateTo)->invert) {
                // out of bounds
                $currentDate = null;
            }
        }
        if (!$currentDate) return null;

        if ($dateFrom->diff($currentDate)->invert) {
            // current date is before date from
            $currentDate = $dateFrom;
        } else if ($currentDate->diff($dateTo)->invert) {
            // current date is after date to
            $currentDate = $dateFrom;
        }
        return $currentDate->format('Y-m-d');
    }

    public function run() {
        $contents = null;

        if (isset($_GET[self::QUERY_LOC]) && gettype($_GET[self::QUERY_LOC]) === 'string') {
            $locationId = (int) $_GET[self::QUERY_LOC];

            $contents = $this->doc->saveHtml($this->renderEventsInLocation($locationId));
        }

        if (isset($_GET[self::QUERY_PROG]) && gettype($_GET[self::QUERY_PROG]) === 'string') {
            $programId = (int) $_GET[self::QUERY_PROG];

            $contents = $this->doc->saveHtml($this->renderProgramPage($programId));
        }

        if (!$contents) {
            $currentDate = $this->readCurrentDate();

            $contents = $this->doc->saveHtml($this->renderDaySwitcher($currentDate));
            if ($currentDate) {
                $contents .= $this->doc->saveHtml($this->renderDayAgenda($currentDate, true));
            } else {
                $contents .= $this->doc->saveHtml($this->renderWholeAgenda());
            }
        }

        return array(
            'contents' => $contents,
        );
    }
}
