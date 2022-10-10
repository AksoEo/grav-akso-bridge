<?php
namespace Grav\Plugin\AksoBridge;

use Grav\Common\Grav;
use Grav\Plugin\AksoBridgePlugin;
use Grav\Plugin\AksoBridge\Utils;
use diversen\sendfile;

class Magazines {
    const MAGAZINE = 'revuo';
    const EDITION = 'numero';
    const TOC = 'enhavo';

    private $plugin, $user;

    public function __construct($plugin, $bridge) {
        $this->plugin = $plugin;
        $this->bridge = $bridge;
    }

    public const TH_MAGAZINE = 'm';
    public const TH_EDITION = 'e';
    public const TH_SIZE = 's';
    public function runThumbnail() {
        $magazine = isset($_GET[self::TH_MAGAZINE]) ? $_GET[self::TH_MAGAZINE] : '?';
        $edition = isset($_GET[self::TH_EDITION]) ? $_GET[self::TH_EDITION] : '?';
        $size = isset($_GET[self::TH_SIZE]) ? $_GET[self::TH_SIZE] : '?';
        $path = "/magazines/$magazine/editions/$edition/thumbnail/$size";

        $res = $this->bridge->getRaw($path, 60);
        if ($res['k']) {
            header('Content-Type: ' . $res['h']['content-type']);
            try {
                readfile($res['ref']);
            } finally {
                $this->bridge->releaseRaw($path);
            }
            die();
        } else {
            $this->plugin->getGrav()->fireEvent('onPageNotFound');
        }
    }

    public const DL_MAGAZINE = 'm';
    public const DL_EDITION = 'e';
    public const DL_ENTRY = 't';
    public const DL_FORMAT = 'f';
    public function runDownload() {
        $magazine = isset($_GET[self::DL_MAGAZINE]) ? $_GET[self::DL_MAGAZINE] : '?';
        $edition = isset($_GET[self::DL_EDITION]) ? $_GET[self::DL_EDITION] : '?';
        $entry = isset($_GET[self::DL_ENTRY]) ? $_GET[self::DL_ENTRY] : '?';
        $format = isset($_GET[self::DL_FORMAT]) ? $_GET[self::DL_FORMAT] : '?';

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

        $path = null;
        $tryStream = false;
        if ($entry !== '?') {
            $path = "/magazines/$magazine/editions/$edition/toc/$entry/recitation/$format";
            $tryStream = true;
        } else {
            $path = "/magazines/$magazine/editions/$edition/files/$format";

            // bump download count
            $this->bridge->post("/magazines/$magazine/editions/$edition/files/$format/!bump", [], [], []);
        }

        $res = null;
        if ($tryStream) {
            $srange = [0, null];
            $useRange = false;

            if (isset($_SERVER['HTTP_RANGE'])) {
                // copied from diversen/sendfile
                list($a, $range) = explode("=", $_SERVER['HTTP_RANGE'], 2);
                list($range) = explode(",", $range, 2);
                list($range, $range_end) = explode("-", $range);
                $range = intval($range);
                if (!$range_end) {
                    $range_end = null;
                } else {
                    $range_end = intval($range_end);
                }
                $srange[0] = $range;
                $srange[1] = $range_end;
                $useRange = true;
            }

            $res = $this->bridge->getRawStream($path, 600, $srange, function ($chunk) use ($useRange) {
                if (isset($chunk['sc'])) {
                    $headers = ['content-type', 'content-length', 'accept-ranges', 'cache-control', 'pragma', 'expires'];
                    if ($useRange) {
                        header('HTTP/1.1 206 Partial Content');
                        $headers[] = 'content-range';
                    } else {
                        header('HTTP/1.1 200 OK');
                    }
                    foreach ($headers as $k) {
                        if (isset($chunk['h'][$k])) {
                            header($k . ': ' . $chunk['h'][$k]);
                        }
                    }
                }

                echo base64_decode($chunk['chunk']);
                ob_flush();
                flush();
            });
            if (!(isset($res['cached']) && $res['cached'])) {
                // if the data is cached, there weren't any stream chunks and we need to run
                // sendfile. otherwise, quit now
                die();
            }
        } else {
            $res = $this->bridge->getRaw($path, 0); // no caching because user auth
        }

        {
            if ($res['k']) {
                try {
                    $sendFile = new sendfile();
                    $sendFile->contentType($res['h']['content-type']);
                    $sendFile->send($res['ref'], false);
                } finally {
                    $this->bridge->releaseRaw($path);
                }
                die();
            } else {
                throw new \Exception("Magazine download: file response not ok!\n" . $res['b']);
            }
        }
    }

    function addEditionDownloadLinks($magazine, $edition, $magazineName) {
        $edition['downloads'] = array('pdf' => null, 'epub' => null);
        try {
            $editionId = $edition['id'];
            $path = "/magazines/$magazine/editions/$editionId/files";
            $res = $this->bridge->get($path, array(
                'fields' => ['format', 'downloads', 'size'],
            ), 120);
            if ($res['k']) {
                foreach ($res['b'] as $item) {
                    $fileName = urlencode(Utils::escapeFileNameLossy($magazineName . ' - ' .$edition['idHuman'])) . '.' . $item['format'];

                    $edition['downloads'][$item['format']] = array(
                        'link' => AksoBridgePlugin::MAGAZINE_DOWNLOAD_PATH
                            . '/' . $fileName
                            . '?' . self::DL_MAGAZINE . '=' . $magazine
                            . '&' . self::DL_EDITION . '=' . $editionId
                            . '&' . self::DL_FORMAT . '=' . $item['format'],
                        'size' => $item['size'],
                    );
                }
            }
            $this->bridge->releaseRaw($path);
        } catch (\Exception $e) {}
        return $edition;
    }

    function getLatestEditions($magazine, $n) {
        $res = $this->bridge->get("/magazines/$magazine/editions", array(
            'fields' => ['id', 'idHuman', 'date', 'description', 'hasThumbnail', 'subscribers', 'subscriberFiltersCompiled'],
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
            throw new \Exception("Failed to fetch latest editions for magazine $magazine:\n" . $res['b']);
        }
        return null;
    }

    private $cachedMagazines = null;
    function listMagazines() {
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
                    $magazine['previous'] = isset($latest[1]) ? $latest[1] : null;
                    $this->cachedMagazines[$magazine['id']] = $magazine;
                }
            } else {
                throw new \Exception("Failed to fetch magazines:\n" . $res['b']);
            }
            uasort($this->cachedMagazines, function ($a, $b) {
                if ($a === $b) return 0;
                // RFC3339 can be sorted lexicographically
                return strnatcmp($a['latest']['date'], $b['latest']['date']);
            });
        }
        return $this->cachedMagazines;
    }

    function getMagazine($id) {
        $res = $this->bridge->get("/magazines/$id", array(
            'fields' => ['id', 'name', 'description', 'org', 'subscribers', 'subscriberFiltersCompiled'],
        ), 240);
        if ($res['k']) {
            $res['b']['description_rendered'] = $this->bridge->renderMarkdown(
                $res['b']['description'] ? $res['b']['description'] : '',
                ['emphasis', 'strikethrough', 'link', 'list', 'table'],
            )['c'];
            return $res['b'];
        } else if ($res['sc'] === 404) {
            $this->plugin->getGrav()->fireEvent('onPageNotFound');
            return null;
        } else {
            throw new \Exception("Failed to fetch magazine $id:\n" . $res['b']);
        }
        return null;
    }

    function getMagazineEditions($magazine, $magazineName, $offset = 0) {
        $allEditions = [];
        while (true) {
            $res = $this->bridge->get("/magazines/$magazine/editions", array(
                'fields' => ['id', 'idHuman', 'date', 'hasThumbnail', 'subscribers', 'subscriberFiltersCompiled'],
                'filter' => ['published' => true],
                'order' => [['date', 'desc']],
                'offset' => count($allEditions),
                'limit' => 100,
            ), 240);

            if (!$res['k']) {
                throw new \Exception("Failed to fetch magazine editions for $magazine:\n" . $res['b']);
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

    function getMagazineEdition($magazine, $edition, $magazineName) {
        $res = $this->bridge->get("/magazines/$magazine/editions/$edition", array(
            'fields' => ['id', 'idHuman', 'date', 'description', 'hasThumbnail', 'published', 'subscribers', 'subscriberFiltersCompiled'],
        ), 240);
        if ($res['k']) {
            $edition = $res['b'];
            $edition = $this->addEditionDownloadLinks($magazine, $edition, $magazineName);
            $edition['description_rendered'] = $this->bridge->renderMarkdown(
                $edition['description'] ? $edition['description'] : '',
                ['emphasis', 'strikethrough', 'link', 'list', 'table'],
            )['c'];
            return $edition;
        } else if ($res['sc'] === 404) {
            $this->plugin->getGrav()->fireEvent('onPageNotFound');
            return null;
        } else {
            throw new \Exception("Failed to fetch magazine edition $magazine/$edition:\n" . $res['b']);
        }
        return null;
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

    function getEditionTocEntries($magazine, $edition, $magazineName, $editionName, $highlightsOnly = false) {
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
            if (!$res['k']) throw new \Exception("Failed to fetch toc for $magazine/$edition:\n" . $res['b']);
            foreach ($res['b'] as $entry) {
                if ($entry['highlighted']) $hasHighlighted = true;
                $entry = $this->addEntryDownloadUrl($magazine, $edition, $entry, $magazineName, $editionName);
                $entry['title_rendered'] = $this->bridge->renderMarkdown(
                    $entry['title'] ? $entry['title'] : '',
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

    function getEditionTocEntry($magazine, $edition, $entry, $magazineName, $editionName) {
        $res = $this->bridge->get("/magazines/$magazine/editions/$edition/toc/$entry", array(
            'fields' => ['id', 'title', 'page', 'author', 'recitationAuthor', 'highlighted', 'text', 'availableRecitationFormats'],
        ), 240);
        if ($res['k']) {
            $entry = $res['b'];
            $entry['title_rendered'] = $this->bridge->renderMarkdown(
                $entry['title'] ? $entry['title'] : '',
                ['emphasis', 'strikethrough'],
                true,
            )['c'];
            $entry['text_rendered'] = $this->bridge->renderMarkdown(
                $entry['text'] ? $entry['text'] : '',
                ['emphasis', 'strikethrough', 'link', 'list', 'table', 'image'],
            )['c'];
            $entry = $this->addEntryDownloadUrl($magazine, $edition, $entry, $magazineName, $editionName);
            return $entry;
        } else {
            throw new \Exception("Failed to fetch toc $magazine/$edition/$entry:\n" . $res['b']);
        }
        return null;
    }

    private function canUserReadMagazine($user, $magazine, $edition, $accessType) {
        $effectiveSubscribers = $edition['subscribers'] ?: $magazine['subscribers'];
        $effectiveCompiledFilters = $edition['subscriberFiltersCompiled'] ?: $magazine['subscriberFiltersCompiled'];

        if (!$effectiveSubscribers) return false;

        if ($user) {
            $res = $this->bridge->get("/codeholders", array(
                'filter' => array(
                    '$and' => [
                        $effectiveCompiledFilters[$accessType],
                        array('id' => $user['id']),
                    ],
                ),
                'limit' => 1,
            ));
            if (!$res['k']) throw new \Exception("failed to check codeholder magazine access");
            if ($res['h']['x-total-items'] > 0) {
                return true;
            }
        }

        if ($effectiveSubscribers[$accessType] === true) return true;

        if (gettype($effectiveSubscribers[$accessType]) === 'array') {
            $freelyAvailableAfter = $effectiveSubscribers[$accessType]['freelyAvailableAfter'];
            if ($freelyAvailableAfter) {
                $fDate = \DateTime::createFromFormat(\DateTime::RFC3339, $edition['date'] . 'T00:00:00Z');
                $interval = new \DateInterval($freelyAvailableAfter);
                $fDate->add($interval);
                if ($fDate < new \DateTime()) {
                    return true;
                }
            }
        }

        return false;
    }

    public function run() {
        $header = $this->plugin->getGrav()['page']->header();
        $path = $header->path_subroute;
        $route = ['type' => 'error'];

        $isSingleMagazine = false;

        $magazines = null;
        if (isset($header->magazines)) {
            $magazines = explode(',', $header->magazines);
            $isSingleMagazine = count($magazines) == 1;
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
        if ($route['type'] === 'list' || $route['type'] === 'magazine-single') {
            $showAccessBanner = true;
            if ($this->plugin->aksoUser) {
                // check if user can access all these magazines. if not, show banner
                $canAccessAll = true;
                foreach ($list as $item) {
                    $canRead = $this->canUserReadMagazine($this->plugin->aksoUser, $item, $item['latest'], 'access');
                    if (!$canRead) {
                        $canAccessAll = false;
                        break;
                    }
                }
                $showAccessBanner = !$canAccessAll;
            }
        }

        if ($route['type'] === 'list') {
            $magazines = $this->listMagazines();
            $list = null;
            if ($route['magazines']) {
                $list = [];
                foreach ($route['magazines'] as $id) {
                    if (isset($magazines[$id])) $list[] = $magazines[$id];
                }
            } else {
                $list = $magazines;
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

            foreach ($editions as &$year) {
                foreach ($year as &$edition) {
                    $edition['can_read'] = $this->canUserReadMagazine($this->plugin->aksoUser, $magazine, $edition, 'access');
                }
            }

            return array(
                'path_components' => $pathComponents,
                'type' => $route['type'],
                'magazine' => $magazine,
                'editions' => $editions,
                'show_access_banner' => $showAccessBanner,
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

            return array(
                'path_components' => $pathComponents,
                'type' => 'edition',
                'magazine' => $magazine,
                'edition' => $edition,
                'toc_entries' => $this->getEditionTocEntries(
                    $route['magazine'], $route['edition'], $magazine['name'], $edition['idHuman']
                ),
                'can_read' => $canRead,
                'is_single_magazine' => $isSingleMagazine,
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

            return array(
                'path_components' => $pathComponents,
                'type' => 'toc_entry',
                'magazine' => $magazine,
                'edition' => $edition,
                'entry' => $entry,
                'can_read' => $canRead,
            );
        } else if ($route['type'] === 'error') {
            $this->plugin->getGrav()->fireEvent('onPageNotFound');
            return;
        }
    }
}
