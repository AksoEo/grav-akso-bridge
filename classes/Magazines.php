<?php
namespace Grav\Plugin\AksoBridge;

use DateTime;
use DateTimeInterface;
use Exception;
use Grav\Common\Grav;
use Grav\Plugin\AksoBridgePlugin;

class Magazines {
    const MAGAZINE = 'revuo';
    const EDITION = 'numero';
    const TOC = 'enhavo';

    private $plugin, $bridge;

    public function __construct($plugin, $bridge) {
        $this->plugin = $plugin;
        $this->bridge = $bridge;
    }

    public const TH_MAGAZINE = 'm';
    public const TH_EDITION = 'e';
    public const TH_SIZE = 's';
    public function runThumbnail() {
        $magazine = $_GET[self::TH_MAGAZINE] ?? '?';
        $edition = $_GET[self::TH_EDITION] ?? '?';
        $size = $_GET[self::TH_SIZE] ?? '?';

        $res = $this->bridge->get("/magazines/$magazine/editions/$edition", array(
            'fields' => ['thumbnail'],
        ), 240);
        if (!$res['k']) {
            Grav::instance()['log']->error("could not load magazine $magazine edition $edition for thumbnail: " . $res['b']);
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

    public const DL_MAGAZINE = 'm';
    public const DL_EDITION = 'e';
    public const DL_ENTRY = 't';
    public const DL_FORMAT = 'f';

    /** @throws Exception */
    public function runDownload() {
        $magazine = $_GET[self::DL_MAGAZINE] ?? '?';
        $edition = $_GET[self::DL_EDITION] ?? '?';
        $entry = $_GET[self::DL_ENTRY] ?? '?';
        $format = $_GET[self::DL_FORMAT] ?? '?';

        $magazineInfo = $this->getMagazine($magazine);
        if (!$magazineInfo) {
            $this->plugin->getGrav()->fireEvent('onPageNotFound');
            return;
        }
        $editionInfo = $this->getMagazineEdition($magazine, $edition, $magazineInfo['name']);

        $hasPerm = $this->canUserReadMagazine($this->plugin->aksoUser, $magazineInfo, $editionInfo, 'access');
        if (!$hasPerm) {
            $this->plugin->getGrav()->fireEvent('onPageNotFound');
            return;
        }

        $shouldBumpDownloadCount = !isset($_SERVER['HTTP_RANGE']);

        if ($entry !== '?') {
            $res = $this->bridge->get("/magazines/$magazine/editions/$edition/toc/$entry/recitation", array(
                'fields' => ['format', 'url'],
            ), 240);
            if (!$res['k']) {
                Grav::instance()['log']->error("could not load magazine $magazine edition $edition recitations: " . $res['b']);
                $this->plugin->getGrav()->fireEvent('onPageNotFound');
                return;
            }
            $url = null;
            foreach ($res['b'] as $file) {
                if ($file['format'] === $format) {
                    $url = $file['url'];
                    break;
                }
            }
            if (!$url) {
                $this->plugin->getGrav()->fireEvent('onPageNotFound');
                return;
            }

            if ($shouldBumpDownloadCount) {
                $this->bridge->post("/magazines/$magazine/editions/$edition/toc/$entry/recitation/$format/!bump", [], [], []);
            }
        } else {
            if (!isset($editionInfo['downloads'][$format]) || !$editionInfo['downloads'][$format]) {
                $this->plugin->getGrav()->fireEvent('onPageNotFound');
                return;
            }
            $url = $editionInfo['downloads'][$format]['url'];

            if ($shouldBumpDownloadCount) {
                $this->bridge->post("/magazines/$magazine/editions/$edition/files/$format/!bump", [], [], []);
            }
        }

        http_response_code(302); // Found
        header('Location: ' . $url);
        die();
    }

    function addEditionDownloadLinks($magazine, $edition, $magazineName, $detailed = false) {
        $edition['downloads'] = array('pdf' => null, 'epub' => null);
        try {
            $editionId = $edition['id'];
            if ($detailed) {
                $path = "/magazines/$magazine/editions/$editionId/files";
                $res = $this->bridge->get($path, array(
                    'fields' => ['format', 'downloads', 'size', 'url'],
                ), 120);
                if ($res['k']) {
                    $files = $res['b'];
                } else {
                    $files = [];
                }
            } else {
                $files = array_map(function ($format) { return array('format' => $format, 'size' => 0); }, $edition['files']);
            }
            foreach ($files as $item) {
                $fileName = urlencode(Utils::escapeFileNameLossy($magazineName . ' - ' .$edition['idHuman'])) . '.' . $item['format'];

                $edition['downloads'][$item['format']] = array(
                    'link' => AksoBridgePlugin::MAGAZINE_DOWNLOAD_PATH
                        . '/' . $fileName
                        . '?' . self::DL_MAGAZINE . '=' . $magazine
                        . '&' . self::DL_EDITION . '=' . $editionId
                        . '&' . self::DL_FORMAT . '=' . $item['format'],
                    'url' => $item['url'],
                    'size' => $item['size'],
                );
            }
        } catch (Exception $e) {}
        return $edition;
    }

    /** @throws Exception */
    function getLatestEditions($magazine, $n): array {
        $res = $this->bridge->get("/magazines/$magazine/editions", array(
            'fields' => ['id', 'idHuman', 'date', 'description', 'thumbnail', 'subscribers', 'subscriberFiltersCompiled'],
            'filter' => ['published' => true],
            'order' => [['date', 'desc']],
            'offset' => 0,
            'limit' => $n,
        ), 120);

        if ($res['k']) {
            $editions = [];
            foreach ($res['b'] as $edition) {
                $editions[] = $edition;
            }

            return $editions;
        } else {
            throw new Exception("Failed to fetch latest editions for magazine $magazine:\n" . $res['b']);
        }
    }

    private $cachedMagazines = null;

    /** @throws Exception */
    function listMagazines(): ?array {
        if (!$this->cachedMagazines) {
            $this->cachedMagazines = [];
            // TODO: handle case where there are more than 100 magazines
            $res = $this->bridge->get('/magazines', array(
                'fields' => ['id', 'name', 'subscribers', 'subscriberFiltersCompiled'],
                'limit' => 100,
            ), 240);
            if ($res['k']) {
                foreach ($res['b'] as $magazine) {
                    $latest = $this->getLatestEditions($magazine['id'], 2);
                    if (!$latest || count($latest) < 1) continue;
                    $magazine['latest'] = $latest[0];
                    $magazine['previous'] = $latest[1] ?? null;
                    $this->cachedMagazines[$magazine['id']] = $magazine;
                }
            } else {
                throw new Exception("Failed to fetch magazines:\n" . $res['b']);
            }
            uasort($this->cachedMagazines, function ($a, $b) {
                if ($a === $b) return 0;
                // RFC3339 can be sorted lexicographically
                return strnatcmp($a['latest']['date'], $b['latest']['date']);
            });
        }
        return $this->cachedMagazines;
    }

    /** @throws Exception */
    function getMagazine($id) {
        $res = $this->bridge->get("/magazines/$id", array(
            'fields' => ['id', 'name', 'description', 'issn', 'org', 'subscribers', 'subscriberFiltersCompiled'],
        ), 240);
        if ($res['k']) {
            $res['b']['description_rendered'] = $this->bridge->renderMarkdown(
                $res['b']['description'] ?: '',
                ['emphasis', 'strikethrough', 'link', 'list', 'table'],
            )['c'];
            return $res['b'];
        } else if ($res['sc'] === 404) {
            $this->plugin->getGrav()->fireEvent('onPageNotFound');
            return null;
        } else {
            throw new Exception("Failed to fetch magazine $id:\n" . $res['b']);
        }
    }

    /** @throws Exception */
    function getMagazineEditions($magazine, $magazineName): array {
        $allEditions = [];
        while (true) {
            $res = $this->bridge->get("/magazines/$magazine/editions", array(
                'fields' => ['id', 'idHuman', 'date', 'thumbnail', 'subscribers', 'subscriberFiltersCompiled', 'files'],
                'filter' => ['published' => true],
                'order' => [['date', 'desc']],
                'offset' => count($allEditions),
                'limit' => 100,
            ), 240);

            if (!$res['k']) {
                throw new Exception("Failed to fetch magazine editions for $magazine:\n" . $res['b']);
            }
            foreach ($res['b'] as $edition) {
                $edition = $this->addEditionDownloadLinks($magazine, $edition, $magazineName);
                $allEditions[] = $edition;
            }

            if (count($allEditions) >= $res['h']['x-total-items']) break;
        }

        $editionsByYear = [];
        foreach ($allEditions as $edition) {
            preg_match('/^(\d+)/', $edition['date'], $matches);
            $year = (int) $matches[1];
            if (!isset($editionsByYear[$year])) $editionsByYear[$year] = [];
            $editionsByYear[$year][] = $edition;
        }
        return $editionsByYear;
    }

    /** @throws Exception */
    function getMagazineEdition($magazine, $edition, $magazineName) {
        $res = $this->bridge->get("/magazines/$magazine/editions/$edition", array(
            'fields' => ['id', 'idHuman', 'date', 'description', 'thumbnail', 'published', 'subscribers', 'subscriberFiltersCompiled', 'files'],
        ), 240);
        if ($res['k']) {
            $edition = $res['b'];
            $edition = $this->addEditionDownloadLinks($magazine, $edition, $magazineName, true);
            $edition['description_rendered'] = $this->bridge->renderMarkdown(
                $edition['description'] ?: '',
                ['emphasis', 'strikethrough', 'link', 'list', 'table'],
            )['c'];
            return $edition;
        } else if ($res['sc'] === 404) {
            $this->plugin->getGrav()->fireEvent('onPageNotFound');
            return null;
        } else {
            throw new Exception("Failed to fetch magazine edition $magazine/$edition:\n" . $res['b']);
        }
    }

    private function addEntryDownloadUrl($magazine, $edition, $entry, $magazineName, $editionName) {
        $fileNamePrefix = Utils::escapeFileNameLossy(
            $magazineName . ' - ' . $editionName . ' - ' . $entry['page'] . ' ' . $entry['title'] . '.'
        );

        // \Grav\Common\Utils::getMimeByExtension returns incorrect types :(
        $mimeTypes = [
            'mp3' => 'audio/mpeg',
            'flac' => 'audio/flac',
            'wav' => 'audio/wav'
        ];

        $entry['downloads'] = [];
        foreach ($entry['availableRecitationFormats'] as $fmt) {
            $entry['downloads'][$fmt] = array(
                'link' => AksoBridgePlugin::MAGAZINE_DOWNLOAD_PATH
                    . '/' . urlencode($fileNamePrefix) . '.' . $fmt
                    . '?' . self::DL_MAGAZINE . '=' . $magazine
                    . '&' . self::DL_EDITION . '=' . $edition
                    . '&' . self::DL_ENTRY . '=' . $entry['id']
                    . '&' . self::DL_FORMAT . '=' . $fmt,
                'mime' => $mimeTypes[$fmt],
                'format' => $fmt,
            );
        }
        return $entry;
    }

    /** @throws Exception */
    function getEditionTocEntries($magazine, $edition, $magazineName, $editionName, $highlightsOnly = false): array {
        $allEntries = [];
        $hasHighlighted = false;
        while (true) {
            $options = array(
                'fields' => ['id', 'title', 'page', 'author', 'recitationAuthor', 'highlighted', 'availableRecitationFormats'],
                'order' => [['page', 'asc']],
                'offset' => count($allEntries),
                'limit' => 100,
            );
            if ($highlightsOnly) $options['filter'] = array('highlighted' => true);
            $res = $this->bridge->get("/magazines/$magazine/editions/$edition/toc", $options, 240);
            if (!$res['k']) throw new Exception("Failed to fetch toc for $magazine/$edition:\n" . $res['b']);
            foreach ($res['b'] as $entry) {
                if ($entry['highlighted']) $hasHighlighted = true;
                $entry = $this->addEntryDownloadUrl($magazine, $edition, $entry, $magazineName, $editionName);
                $entry['title_rendered'] = $this->bridge->renderMarkdown(
                    $entry['title'] ?: '',
                    ['emphasis', 'strikethrough'],
                    true,
                )['c'];
                $allEntries[] = $entry;
            }
            if (!$hasHighlighted) {
                // if there are no highlighted items, mark the first three as highlighted
                for ($i = 0; $i < 3; $i++) {
                    if (isset($allEntries[$i])) $allEntries[$i]['highlighted'] = true;
                }
                $hasHighlighted = true;
            }
            if (count($allEntries) >= $res['h']['x-total-items']) break;
        }

        return $allEntries;
    }

    /** @throws Exception */
    function getEditionTocEntry($magazine, $edition, $entry, $magazineName, $editionName) {
        $res = $this->bridge->get("/magazines/$magazine/editions/$edition/toc/$entry", array(
            'fields' => ['id', 'title', 'page', 'author', 'recitationAuthor', 'highlighted', 'text', 'availableRecitationFormats'],
        ), 240);
        if ($res['k']) {
            $entry = $res['b'];
            $entry['title_rendered'] = $this->bridge->renderMarkdown(
                $entry['title'] ?: '',
                ['emphasis', 'strikethrough'],
                true,
            )['c'];
            $entry['text_rendered'] = $this->bridge->renderMarkdown(
                $entry['text'] ?: '',
                ['emphasis', 'strikethrough', 'link', 'list', 'table', 'image'],
            )['c'];
            return $this->addEntryDownloadUrl($magazine, $edition, $entry, $magazineName, $editionName);
        } else {
            throw new Exception("Failed to fetch toc $magazine/$edition/$entry:\n" . $res['b']);
        }
    }

    private $magazineAccessCache = [];

    /** @throws Exception */
    private function canUserReadMagazine($user, $magazine, $edition, $accessType): bool {
        $effectiveSubscribers = $edition['subscribers'] ?: $magazine['subscribers'];
        $effectiveCompiledFilters = $edition['subscriberFiltersCompiled'] ?: $magazine['subscriberFiltersCompiled'];

        if (!$effectiveSubscribers) return false;

        if ($effectiveCompiledFilters[$accessType] === true) return true;

        if ($user && gettype($effectiveCompiledFilters[$accessType]) === 'array') {
            $cacheKey = json_encode($effectiveCompiledFilters[$accessType]) . ' ' . $user['id'];
            if (isset($this->magazineAccessCache[$cacheKey])) {
                $result = $this->magazineAccessCache[$cacheKey];
            } else {
                $res = $this->bridge->get("/codeholders", array(
                    'filter' => array(
                        '$and' => [
                            $effectiveCompiledFilters[$accessType],
                            array('id' => $user['id']),
                        ],
                    ),
                    'limit' => 1,
                ));
                if (!$res['k']) throw new Exception("failed to check codeholder magazine access");
                $result = $res['h']['x-total-items'] > 0;

                $this->magazineAccessCache[$cacheKey] = $result;
            }

            if ($result) {
                return true;
            }
        }

        if ($effectiveSubscribers[$accessType] === true) return true;

        if (gettype($effectiveSubscribers[$accessType]) === 'array') {
            $freelyAvailableAfter = $effectiveSubscribers[$accessType]['freelyAvailableAfter'];
            if ($freelyAvailableAfter) {
                $fDate = DateTime::createFromFormat(DateTimeInterface::RFC3339, $edition['date'] . 'T00:00:00Z');
                $interval = new \DateInterval($freelyAvailableAfter);
                $fDate->add($interval);
                if ($fDate < new DateTime()) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @throws Exception */
    public function run(): array {
        $header = $this->plugin->getGrav()['page']->header();
        $path = $header->path_subroute;
        $route = ['type' => 'error'];

        $isSingleMagazine = false;

        $magazines = null;
        $accessMessages = array();
        if (isset($header->magazines)) {
            $magazines = explode(',', $header->magazines);
            $isSingleMagazine = count($magazines) == 1;
        }
        if (isset($header->magazine_access_messages)) {
            $accessMessages = $header->magazine_access_messages;
        }

        if (!$path) {
            if (count($magazines) == 1) {
                // just one magazine; show the detail view directly
                $route = [
                    'type' => 'magazine-single',
                    'magazine' => $magazines[0],
                ];
            } else {
                $route = [
                    'type' => 'list',
                    'magazines' => $magazines,
                ];
            }
        } else if (preg_match('/^(\d+)$/', $path, $matches)) {
            if ($isSingleMagazine) {
                Grav::instance()->redirectLangSafe($header->path_base, 302);
            }
            if (in_array($matches[1], $magazines)) {
                $route = [
                    'type' => 'magazine',
                    'magazine' => $matches[1],
                ];
            }
        } else if (preg_match('/^(\d+)\/' . self::EDITION . '\/(\d+)$/', $path, $matches)) {
            if (in_array($matches[1], $magazines)) {
                $route = [
                    'type' => 'edition',
                    'magazine' => $matches[1],
                    'edition' => $matches[2],
                ];
            }
        } else if (preg_match('/^(\d+)\/' . self::EDITION . '\/(\d+)\/' . self::TOC . '\/(\d+)$/', $path, $matches)) {
            if (in_array($matches[1], $magazines)) {
                $route = [
                    'type' => 'toc_entry',
                    'magazine' => $matches[1],
                    'edition' => $matches[2],
                    'entry' => $matches[3],
                ];
            }
        }

        $pathComponents = array(
            'login_path' => $this->plugin->loginPath,
            'base' => $header->path_base,
            'magazine' => self::MAGAZINE,
            'edition' => self::EDITION,
            'toc' => self::TOC,
        );

        $showAccessBanner = false;

        if ($route['type'] === 'list') {
            $magazines = $this->listMagazines();
            if ($route['magazines']) {
                $list = [];
                foreach ($route['magazines'] as $id) {
                    if (isset($magazines[$id])) $list[] = $magazines[$id];
                }
            } else {
                $list = $magazines;
            }

            $showAccessBanner = true;
            if ($this->plugin->aksoUser) {
                // check if user can access all these magazines. if not, show banner
                $canAccessAll = true;
                foreach ($magazines as $item) {
                    $canRead = $this->canUserReadMagazine($this->plugin->aksoUser, $item, $item['latest'], 'access');
                    if (!$canRead) {
                        $canAccessAll = false;
                        break;
                    }
                }
                $showAccessBanner = !$canAccessAll;
            }

            return array(
                'path_components' => $pathComponents,
                'type' => 'list',
                'magazines' => $list,
                'show_access_banner' => $showAccessBanner,
            );
        } else if ($route['type'] === 'magazine' || $route['type'] === 'magazine-single') {
            $magazine = $this->getMagazine($route['magazine']);
            if (!$magazine) return $this->plugin->getGrav()->fireEvent('onPageNotFound');
            $editions = $this->getMagazineEditions($route['magazine'], $magazine['name']);

            if ($route['type'] === 'magazine-single') {
                $showAccessBanner = true;
                if ($this->plugin->aksoUser) {
                    // check if user can access all these magazines. if not, show banner
                    $showAccessBanner = !$this->canUserReadMagazine($this->plugin->aksoUser, $magazine, $magazine['latest'], 'access');
                }
            }

            $showYear = 0;
            foreach ($editions as $year => $items) {
                if ($year > $showYear) $showYear = $year;
            }

            $yearParam = $this->plugin->locale['magazines']['edition_year_param'];
            if (isset($_GET[$yearParam]) && gettype($_GET[$yearParam]) === 'string' && isset($editions[(int) $_GET[$yearParam]])) {
                $showYear = (int)$_GET[$yearParam];
            }

            foreach ($editions as &$year) {
                foreach ($year as &$edition) {
                    $edition['can_read'] = false; // default value
                }
            }
            $editionsShown = array();
            $editionsShown[$showYear] = $editions[$showYear];

            // actually compute access here
            foreach ($editionsShown[$showYear] as &$edition) {
                $edition['can_read'] = $this->canUserReadMagazine($this->plugin->aksoUser, $magazine, $edition, 'access');
            }

            $editionsYearPath = Grav::instance()['page']->route() . '?' . $yearParam . '=';

            return array(
                'path_components' => $pathComponents,
                'type' => $route['type'],
                'magazine' => $magazine,
                'editions' => $editions,
                'editions_shown' => $editionsShown,
                'editions_year_path' => $editionsYearPath,
                'show_access_banner' => $showAccessBanner,
                'title' => $this->plugin->locale['magazines']['title_prefix'] . $magazine['name'],
            );
        } else if ($route['type'] === 'edition') {
            $magazine = $this->getMagazine($route['magazine']);
            if (!$magazine) return $this->plugin->getGrav()->fireEvent('onPageNotFound');

            $edition = $this->getMagazineEdition($route['magazine'], $route['edition'], $magazine['name']);
            if (!$edition || !$edition['published']) return $this->plugin->getGrav()->fireEvent('onPageNotFound');

            $canRead = $this->canUserReadMagazine($this->plugin->aksoUser, $magazine, $edition, 'access');

            if (isset($_GET['js_toc_preview'])) {
                $highlights = $this->getEditionTocEntries(
                    $route['magazine'], $route['edition'], $magazine['name'], $edition['idHuman'], true
                );
                if (empty($highlights)) {
                    // fall back
                    $highlights = $this->getEditionTocEntries(
                        $route['magazine'], $route['edition'], $magazine['name'], $edition['idHuman']
                    );
                    $highlights = array_slice($highlights, 0, 10);
                }
                echo json_encode(array('highlights' => $highlights, 'canRead' => $canRead));
                die();
            }

            $accessMessage = null;
            if (!$canRead && isset($accessMessages[$magazine['id']])) {
                $accessMessage = $this->bridge->renderMarkdown($accessMessages[$magazine['id']], ['emphasis', 'strikethrough', 'link'])['c'];
            }

            return array(
                'path_components' => $pathComponents,
                'type' => 'edition',
                'magazine' => $magazine,
                'edition' => $edition,
                'toc_entries' => $this->getEditionTocEntries(
                    $route['magazine'], $route['edition'], $magazine['name'], $edition['idHuman']
                ),
                'can_read' => $canRead,
                'access_message' => $accessMessage,
                'is_single_magazine' => $isSingleMagazine,
                'title' => $this->plugin->locale['magazines']['title_prefix'] . $magazine['name']
                    . ': ' . $edition['idHuman'],
            );
        } else if ($route['type'] === 'toc_entry') {
            $magazine = $this->getMagazine($route['magazine']);
            if (!$magazine) return $this->plugin->getGrav()->fireEvent('onPageNotFound');

            $edition = $this->getMagazineEdition($route['magazine'], $route['edition'], $magazine['name']);
            if (!$edition || !$edition['published']) return $this->plugin->getGrav()->fireEvent('onPageNotFound');

            $canRead = $this->canUserReadMagazine($this->plugin->aksoUser, $magazine, $edition, 'access');

            $entry = $this->getEditionTocEntry(
                $route['magazine'], $route['edition'], $route['entry'], $magazine['name'], $edition['idHuman']
            );
            if (!$entry) return $this->plugin->getGrav()->fireEvent('onPageNotFound');

            $doc = new \DOMDocument();
            $doc->loadHTML($entry['title_rendered']);
            $plain_title = $doc->textContent;

            return array(
                'path_components' => $pathComponents,
                'type' => 'toc_entry',
                'magazine' => $magazine,
                'edition' => $edition,
                'entry' => $entry,
                'can_read' => $canRead,
                'title' => $plain_title . ' — '
                    . $this->plugin->locale['magazines']['title_prefix'] . $magazine['name']
                    . ': ' . $edition['idHuman'],
            );
        } else if ($route['type'] === 'error') {
            $this->plugin->getGrav()->fireEvent('onPageNotFound');
        }

        return [];
    }
}
