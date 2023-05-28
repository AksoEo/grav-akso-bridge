<?php
namespace Grav\Plugin\AksoBridge;

use Grav\Common\Grav;
use Grav\Plugin\AksoBridge\CongressPrograms;
use Grav\Plugin\AksoBridge\Utils;

// Handles the congress locations page type.
class CongressLocations {
    // url query parameter to identify a location
    const QUERY_LOC = 'loc';
    // for location icons
    const ICONS_PATH_PREFIX = '/user/plugins/akso-bridge/assets/location_icons/';
    const ICONS_PATH_SUFFIX = '.svg';

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

        $this->readQuery();
    }

    // Set this to a relative path to enable program links
    public $programsPath = null;

    // If true, then this is a JS request that only needs a partial HTML render and not the full
    // page.
    private $wantsPartial = false;
    // If not null, then the request is for a single location.
    private $locationId = null;

    function readQuery() {
        if (isset($_GET['partial'])) {
            $this->wantsPartial = true;
        }
        if (isset($_GET[self::QUERY_LOC])) {
            $this->locationId = (int) $_GET[self::QUERY_LOC];
        }
    }

    // Renders a list of internal locations.
    function renderInternalList($intLoc) {
        $int = $this->doc->createElement('ul');
        $int->setAttribute('class', 'internal-locations-list');

        foreach ($intLoc as $location) {
            $li = $this->doc->createElement('li');
            $li->setAttribute('class', 'internal-location-list-item');
            $li->setAttribute('data-id', $location['id']);
            $li->setAttribute('data-name', $location['name']);

            $name = $this->doc->createElement('a');
            $name->setAttribute('href', $this->plugin->getGrav()['uri']->path() . '?' . self::QUERY_LOC . '=' . $location['id']);
            $name->setAttribute('class', 'location-name');
            $name->setAttribute('data-loc-id', $location['id']);
            $name->textContent = $location['name'];
            $li->appendChild($name);

            $description = $this->doc->createElement('div');
            $description->setAttribute('class', 'location-description');
            if ($location['description']) {
                $rules = ['emphasis', 'strikethrough', 'link', 'list', 'table'];
                $res = $this->app->bridge->renderMarkdown($location['description'], $rules);
                Utils::setInnerHTML($description, $res['c']);
            }
            $li->appendChild($description);

            $int->appendChild($li);
        }

        return $int;
    }

    // Creates a table mapping dates to timezone offset for each congress day.
    function congressTzOffsets() {
        $congressId = $this->congressId;
        $instanceId = $this->instanceId;
        $res = $this->app->bridge->get("/congresses/$congressId/instances/$instanceId", array(
            'fields' => ['dateFrom', 'dateTo', 'tz'],
        ), 240);
        if ($res['k']) {
            try {
                // day-by-day granularity is probably enough to mitigate most time zone nonsense
                $tz = new \DateTimeZone($res['b']['tz']);
                $days = array();
                $cursor = \DateTime::createFromFormat("Y-m-d", $res['b']['dateFrom'], $tz);
                $endDate = \DateTime::createFromFormat("Y-m-d", $res['b']['dateTo'], $tz);
                while (!$cursor->diff($endDate)->invert) {
                    $days[$cursor->format("Y-m-d")] = $tz->getOffset($cursor);
                    $cursor = $cursor->add(new \DateInterval("P1D"));
                }
                return $days;
            } catch (\Exception $e) {
                // ??? probably null timezone
            }
        }
        return array();
    }

    // Renders a list of external locations.
    function renderList() {
        $tzOffsets = $this->congressTzOffsets();

        // first, collect all locations into two bins
        $extLocations = []; // array of locations
        $intLocations = []; // map (external loc id -> item array)
        $locCount = 0;
        $error = false;
        while (true) {
            $congress = $this->congressId;
            $instance = $this->instanceId;
            $res = $this->app->bridge->get("/congresses/$congress/instances/$instance/locations", array(
                'fields' => ['id', 'type', 'name', 'description', 'll', 'icon', 'externalLoc',
                    'rating.rating', 'rating.max', 'rating.type', 'openHours'],
                'limit' => 100,
                'order' => [['name', 'asc']],
            ), 120);
            if ($res['k']) {
                foreach ($res['b'] as $item) {
                    if ($item['type'] === 'external') {
                        $extLocations[] = $item;
                    } else {
                        if (!array_key_exists($item['externalLoc'], $intLocations)) {
                            $intLocations[$item['externalLoc']] = [];
                        }
                        $intLocations[$item['externalLoc']][] = $item;
                    }
                    $locCount++;
                }
                $totalItems = $res['h']['x-total-items'];
                if (count($res['b']) == 0 || $locCount >= $totalItems) {
                    break;
                }
            } else {
                $error = true;
                break;
            }
        }

        // now, render just the external locations
        $ul = $this->doc->createElement('ul');
        $ul->setAttribute('class', 'locations-list');
        // used in JS
        $ul->setAttribute('data-tz-offsets', base64_encode(json_encode($tzOffsets)));

        $path = $this->plugin->getGrav()['uri']->path();

        foreach ($extLocations as $location) {
            $li = $this->doc->createElement('li');
            $li->setAttribute('class', 'location-list-item');

            $li->setAttribute('data-id', $location['id']);
            $li->setAttribute('data-ll', implode(',', $location['ll']));
            $li->setAttribute('data-icon', $location['icon']);
            $li->setAttribute('data-name', $location['name']);

            if (isset($location['rating']) && $location['rating'] && $location['rating']['max'] > 0) {
                $li->setAttribute('data-rating', $location['rating']['rating'] . '/' . $location['rating']['max']);
                $li->setAttribute('data-rating-type', $location['rating']['type']);
            }
            if (isset($location['openHours']) && $location['openHours']) {
                $li->setAttribute('data-open-hours', base64_encode(json_encode($location['openHours'])));
            }

            $liInner = $this->doc->createElement('div');
            $liInner->setAttribute('class', 'location-inner-container');

            $iconContainer = $this->doc->createElement('div');
            $iconContainer->setAttribute('class', 'location-icon-container');
            $iconImg = $this->doc->createElement('img');
            $iconImg->setAttribute('class', 'location-icon');
            $iconImg->setAttribute('src', self::ICONS_PATH_PREFIX . $location['icon'] . self::ICONS_PATH_SUFFIX);
            $iconContainer->appendChild($iconImg);
            $liInner->appendChild($iconContainer);

            $liDetails = $this->doc->createElement('div');
            $liDetails->setAttribute('class', 'location-inner-details');

            $name = $this->doc->createElement('a');
            $name->setAttribute('href', $path . '?' . self::QUERY_LOC . '=' . $location['id']);
            $name->setAttribute('class', 'location-name');
            $name->setAttribute('data-loc-id', $location['id']);
            $name->textContent = $location['name'];
            $liDetails->appendChild($name);

            $description = $this->doc->createElement('div');
            $description->setAttribute('class', 'location-description');
            if ($location['description']) {
                $rules = ['emphasis', 'strikethrough', 'link', 'list', 'table'];
                $res = $this->app->bridge->renderMarkdown($location['description'], $rules);
                Utils::setInnerHTML($description, $res['c']);
            }
            $liDetails->appendChild($description);

            $liInner->appendChild($liDetails);
            $li->appendChild($liInner);

            // render internal locations list if it exists
            if (isset($intLocations[$location['id']]) && count($intLocations[$location['id']]) > 0) {
                $li->appendChild($this->renderInternalList($intLocations[$location['id']]));
            }

            $ul->appendChild($li);

        }

        return $ul;
    }

    // Renders the detail page for a location.
    function renderDetail() {
        $container = $this->doc->createElement('div');
        $container->setAttribute('class', 'congress-location');

        $congress = $this->congressId;
        $instance = $this->instanceId;
        $locationId = $this->locationId;
        $res = $this->app->bridge->get("/congresses/$congress/instances/$instance/locations/$locationId", array(
            'fields' => ['type', 'name', 'description', 'openHours', 'address', 'll', 'rating.rating', 'rating.type', 'rating.max', 'icon', 'externalLoc', 'thumbnail'],
        ), 60);
        if ($res['k']) {
            $location = $res['b'];

            $headerImage = null;
            if ($location['thumbnail']) {
                $imgPrefix = \Grav\Plugin\AksoBridgePlugin::CONGRESS_LOC_THUMBNAIL_PATH . '?' .
                    self::TH_CONGRESS . '=' . $congress . '&' .
                    self::TH_INSTANCE . '=' . $instance . '&' .
                    self::TH_LOCATION . '=' . $locationId . '&' .
                    self::TH_SIZE;

                $headerImage = $this->doc->createElement('div');
                $headerImage->setAttribute('class', 'location-header-image');
                $img = $this->doc->createElement('img');
                $img->setAttribute('src', $imgPrefix . '=512px');
                $img->setAttribute('srcset', "$imgPrefix=128px 128w, $imgPrefix=256px 256w, $imgPrefix=512px 512w, $imgPrefix=1024px 1024w, $imgPrefix=2048px 2048w");

                $headerImage->appendChild($img);
            }

            $header = $this->doc->createElement('div');
            $header->setAttribute('class', 'location-header');
            if ($headerImage !== null) {
                $headerContainer = $this->doc->createElement('div');
                $headerContainer->setAttribute('class', 'location-header-container');
                $headerContainer->appendChild($headerImage);
                $headerContainer->appendChild($header);
                $container->appendChild($headerContainer);
            } else {
                $container->appendChild($header);
            }

            {
                $backLink = $this->doc->createElement('a');
                $backLink->setAttribute('class', 'back-link');
                $backLink->setAttribute('data-loc-id', '');
                $backLink->setAttribute('href', $this->plugin->getGrav()['uri']->path());
                $backLink->textContent = '<';
                $header->appendChild($backLink);

                $name = $this->doc->createElement('h1');
                $name->textContent = $location['name'];
                $header->appendChild($name);
            }

            if ($location['type'] === 'internal') {
                $externalLocId = $location['externalLoc'];
                $eres = $this->app->bridge->get("/congresses/$congress/instances/$instance/locations/$externalLocId", array(
                    'fields' => ['name', 'icon', 'll'],
                ), 60);
                if ($eres['k']) {
                    // render info about the external location that contains this item

                    $container->setAttribute('data-ll', implode(',', $eres['b']['ll']));
                    $container->setAttribute('data-icon', $eres['b']['icon']);

                    $label = $this->doc->createElement('span');
                    $label->textContent = $this->plugin->locale['congress_locations']['located_within'] . ' ';

                    $extLink = $this->doc->createElement('a');
                    $extLink->textContent = $eres['b']['name'];
                    $extLink->setAttribute('data-loc-id', $externalLocId);
                    $extLink->setAttribute('href', $this->plugin->getGrav()['uri']->path() . '?' . self::QUERY_LOC . '=' . $externalLocId);

                    $extLoc = $this->doc->createElement('div');
                    $extLoc->setAttribute('class', 'external-loc');
                    $extLoc->appendChild($label);
                    $extLoc->appendChild($extLink);
                    $container->appendChild($extLoc);
                }
            } else if ($location['type'] === 'external') {
                // add map metadata
                $container->setAttribute('data-ll', implode(',', $location['ll']));
                $container->setAttribute('data-icon', $location['icon']);
            }

            if (isset($location['rating']) && $location['rating'] !== null && $location['rating']['max'] > 0) {
                $ratingContainer = $this->doc->createElement('div');
                $ratingContainer->setAttribute('class', 'location-rating');

                $rating = $location['rating']['rating'];
                $max = $location['rating']['max'];
                $type = $location['rating']['type'];

                for ($i = 0; $i < $max; $i++) {
                    $icon = $this->doc->createElement('span');
                    $icon->setAttribute('class', 'rating-icon');
                    if (floor($rating) !== $rating && (int) $rating === $i) {
                        // partial icon
                        $partial = $rating - floor($rating);

                        $icon->setAttribute('class', 'rating-icon is-partial');
                        $base = $this->doc->createElement('img');
                        $base->setAttribute('class', 'rating-icon-base');
                        $base->setAttribute('src', self::ICONS_PATH_PREFIX . 'rating-' . $type . '-empty.svg');
                        $fill = $this->doc->createElement('span');
                        $fill->setAttribute('class', 'rating-icon-fill-container is-' . $type);
                        $fill->setAttribute('data-fill-partial', (int) ($partial * 10));
                        $fillImg = $this->doc->createElement('img');
                        $fillImg->setAttribute('src', self::ICONS_PATH_PREFIX . 'rating-' . $type . '-filled.svg');
                        $fill->appendChild($fillImg);
                        $icon->appendChild($base);
                        $icon->appendChild($fill);
                    } else if ($i < $rating) {
                        $img = $this->doc->createElement('img');
                        $img->setAttribute('src', self::ICONS_PATH_PREFIX . 'rating-' . $type . '-filled.svg');
                        $icon->appendChild($img);
                    } else {
                        $img = $this->doc->createElement('img');
                        $img->setAttribute('src', self::ICONS_PATH_PREFIX . 'rating-' . $type . '-empty.svg');
                        $icon->appendChild($img);
                    }

                    $ratingContainer->appendChild($icon);
                }

                $val = $this->doc->createElement('span');
                $val->setAttribute('class', 'rating-value is-' . $type);
                $val->textContent = round($rating * 10) / 10;
                $ratingContainer->appendChild($val);

                $container->appendChild($ratingContainer);
            }

            $description = $this->doc->createElement('div');
            $description->setAttribute('class', 'location-description');
            if ($location['description']) {
                $rules = ['emphasis', 'strikethrough', 'link', 'list', 'table'];
                $res = $this->app->bridge->renderMarkdown($location['description'], $rules);
                Utils::setInnerHTML($description, $res['c']);
            }
            $container->appendChild($description);

            if (isset($location['openHours']) && $location['openHours'] !== null) {
                $openHoursContainer = $this->doc->createElement('div');
                $openHoursContainer->setAttribute('class', 'location-open-hours-container');
                $openHoursTitle = $this->doc->createElement('div');
                $openHoursTitle->textContent = $this->plugin->locale['congress_locations']['open_hours'];
                $openHoursTitle->setAttribute('class', 'open-hours-title');
                $openHoursContainer->appendChild($openHoursTitle);
                $openHours = $this->doc->createElement('ul');
                $openHours->setAttribute('class', 'open-hours-list');
                foreach (array_keys($location['openHours']) as $k) {
                    $li = $this->doc->createElement('li');
                    $li->setAttribute('class', 'open-hours-day');
                    $dayLabel = $this->doc->createElement('span');
                    $dayLabel->setAttribute('class', 'day-label');
                    $dayLabel->textContent = Utils::formatDayMonth($k);
                    $li->appendChild($dayLabel);
                    $li->appendChild($this->doc->createTextNode(': '));

                    $hoursText = '';
                    foreach ($location['openHours'][$k] as $timeSpan) {
                        if ($hoursText) $hoursText .= ', ';
                        $hoursText .= $timeSpan;
                    }

                    $hours = $this->doc->createElement('san');
                    $hours->textContent = $hoursText;
                    $li->appendChild($hours);
                    $openHours->appendChild($li);
                }
                $openHoursContainer->appendChild($openHours);
                $container->appendChild($openHoursContainer);
            }

            if (isset($location['address']) && $location['address'] !== null) {
                $addressContainer = $this->doc->createElement('div');
                $addressContainer->setAttribute('class', 'address-container');
                $label = $this->doc->createElement('label');
                $label->setAttribute('class', 'address-field-label');
                $label->textContent = $this->plugin->locale['congress_locations']['address'];
                $addressContainer->appendChild($label);
                $lines = explode("\n", $location['address']);
                foreach ($lines as $line) {
                    $ln = $this->doc->createElement('div');
                    $ln->setAttribute('class', 'address-line');
                    $ln->textContent = $line;
                    $addressContainer->appendChild($ln);
                }
                $container->appendChild($addressContainer);
            }

            if ($location['type'] === 'external') {
                // NOTE: this only renders the first 100 internal locations, which should be
                // plenty for basically all use cases, but in case it isn’t, this is why
                $eres = $this->app->bridge->get("/congresses/$congress/instances/$instance/locations", array(
                    'fields' => ['id', 'name', 'description'],
                    'filter' => array('externalLoc' => $locationId),
                    'limit' => 100,
                ), 60);

                if ($eres['k'] && count($eres['b']) > 0) {
                    $internalListContainer = $this->doc->createElement('div');
                    $internalListContainer->setAttribute('class', 'internal-list-container');

                    $title = $this->doc->createElement('h4');
                    $title->setAttribute('class', 'internal-list-title');
                    $title->textContent = $this->plugin->locale['congress_locations']['internal_locations_title'];
                    $internalListContainer->appendChild($title);

                    $internalListContainer->appendChild($this->renderInternalList($eres['b']));
                    $container->appendChild($internalListContainer);
                }
            }

            if ($this->programsPath) {
                $viewPrograms = $this->doc->createElement('a');
                $viewPrograms->setAttribute('class', 'view-programs-link');
                $viewPrograms->setAttribute('href', $this->programsPath . '?' . CongressPrograms::QUERY_LOC . '=' . $locationId);
                $viewPrograms->textContent = $this->plugin->locale['congress_locations']['view_programs_here'];
                $container->appendChild($viewPrograms);
            }

            return $container;
        }
        return null;
    }

    // Will be set to true after render() call if a location was rendered
    private $didRenderLocation = false;

    // Renders page contents.
    function render() {
        $root = $this->doc->createElement('div');
        $root->setAttribute('class', 'congress-locations-rendered');

        $root->setAttribute('data-base-path', $this->plugin->getGrav()['uri']->path());
        $root->setAttribute('data-query-loc', self::QUERY_LOC);
        $root->setAttribute('data-icons-path-prefix', self::ICONS_PATH_PREFIX);
        $root->setAttribute('data-icons-path-suffix', self::ICONS_PATH_SUFFIX);

        $renderedLoc = false;
        if ($this->locationId != null) {
            $rendered = $this->renderDetail();
            if ($rendered !== null) {
                $renderedLoc = true;
                $root->appendChild($rendered);

                $root->setAttribute('data-is-loc', 'true');
                $root->setAttribute('data-loc-id', $this->locationId);
            } else {
                $this->plugin->getGrav()->fireEvent('onPageNotFound');
            }
        }
        $this->didRenderLocation = $renderedLoc;

        if (!$renderedLoc) {
            $root->appendChild($this->renderList());
        }

        return $this->doc->saveHtml($root);
    }

    // Thumbnail handling (early entry; see akso-bridge.php)
    const TH_CONGRESS = 'c'; // query parameters
    const TH_INSTANCE = 'i';
    const TH_LOCATION = 'l';
    const TH_SIZE = 's';
    public function runThumbnail() {
        $congress = isset($_GET[self::TH_CONGRESS]) ? $_GET[self::TH_CONGRESS] : '?';
        $instance = isset($_GET[self::TH_INSTANCE]) ? $_GET[self::TH_INSTANCE] : '?';
        $location = isset($_GET[self::TH_LOCATION]) ? $_GET[self::TH_LOCATION] : '?';
        $size = isset($_GET[self::TH_SIZE]) ? $_GET[self::TH_SIZE] : '?';
        $res = $this->app->bridge->get("/congresses/$congress/instances/$instance/locations/$location", array(
            'fields' => ['thumbnail'],
        ), 240);
        if (!$res['k']) {
            Grav::instance()['log']->error("could not load congress $congress instance $instance location $location for thumbnail: " . $res['b']);
            $this->plugin->getGrav()->fireEvent('onPageNotFound');
            return;
        }
        if (!$res['b']['thumbnail']) {
            $this->plugin->getGrav()->fireEvent('onPageNotFound');
            return;
        }
        $size = preg_replace('/px$/', '', $size);
        $url = $res['b']['thumbnail'][$size] ?? '';

        http_response_code(302); // Found
        header('Location: ' . $url);
        die();
    }

    // Renders the locations page.
    public function run() {
        $rendered = $this->render();

        if ($this->wantsPartial) {
            echo $rendered;
            die();
        }

        return array(
            'contents' => $rendered,
            'did_render_location' => $this->didRenderLocation,
        );
    }
}
