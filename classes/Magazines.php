<?php
namespace Grav\Plugin\AksoBridge;

use Grav\Plugin\AksoBridgePlugin;
use Grav\Plugin\AksoBridge\Utils;
use diversen\sendfile;

class Magazines {
    const MAGAZINE = 'revuo';
    const EDITION = 'numero';
    const TOC = 'enhavo';

    private $plugin, $bridge, $user;

    public function __construct($plugin, $bridge) {
        $this->plugin = $plugin;
        $this->bridge = $bridge;
        $this->user = $plugin->aksoUser ? $plugin->bridge : null;
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
            // TODO: error?
            die();
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
            // TODO: error page
            die();
        }
        $org = $magazineInfo['org'];

        $perm = "magazines.read.$org";
        $hasPerm = $this->user ? $this->user->hasPerms([$perm])['p'][0] : false;
        if (!$hasPerm) {
            // TODO: error page
            die();
        }

        $path = null;
        $tryStream = false;
        if ($entry !== '?') {
            $path = "/magazines/$magazine/editions/$edition/toc/$entry/recitation/$format";
            $tryStream = true;
        } else {
            $path = "/magazines/$magazine/editions/$edition/files/$format";
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
                header('HTTP/1.1 500 Internal Server Error');
                // TODO: error?
                die();
            }
        }
    }

    function editionDataAddHasThumbnail($magazine, $edition) {
        $edition['hasThumbnail'] = false;
        try {
            $editionId = $edition['id'];
            $path = "/magazines/$magazine/editions/$editionId/thumbnail/32px";
            $res = $this->bridge->getRaw($path, 120);
            if ($res['k']) {
                $edition['hasThumbnail'] = true;
            }
            $this->bridge->releaseRaw($path);
        } catch (\Exception $e) {}
        return $edition;
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
            'fields' => ['id', 'idHuman', 'date', 'description'],
            'order' => [['date', 'desc']],
            'offset' => 0,
            'limit' => $n,
        ), 120);

        if ($res['k']) {
            $editions = [];
            foreach ($res['b'] as $edition) {
                $edition = $this->editionDataAddHasThumbnail($magazine, $edition);
                $editions[] = $edition;
            }

            return $editions;
        }
        return null;
    }

    private $cachedMagazines = null;
    function listMagazines() {
        if (!$this->cachedMagazines) {
            $this->cachedMagazines = [];
            // TODO: handle case where there are more than 100 magazines
            $res = $this->bridge->get('/magazines', array(
                'fields' => ['id', 'name'],
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
            'fields' => ['id', 'name', 'description', 'org'],
        ), 240);
        if ($res['k']) {
            $res['b']['description_rendered'] = $this->bridge->renderMarkdown(
                $res['b']['description'] ? $res['b']['description'] : '',
                ['emphasis', 'strikethrough', 'link', 'list', 'table'],
            )['c'];
            return $res['b'];
        }
        return null;
    }

    function getMagazineEditions($magazine, $offset = 0) {
        $allEditions = [];
        while (true) {
            $res = $this->bridge->get("/magazines/$magazine/editions", array(
                'fields' => ['id', 'idHuman', 'date'],
                'order' => [['date', 'desc']],
                'offset' => count($allEditions),
                'limit' => 100,
            ), 240);

            if (!$res['k']) {
                return null;
            }
            foreach ($res['b'] as $edition) {
                $edition = $this->editionDataAddHasThumbnail($magazine, $edition);
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
            'fields' => ['id', 'idHuman', 'date', 'description'],
        ), 240);
        if ($res['k']) {
            $edition = $res['b'];
            $edition = $this->editionDataAddHasThumbnail($magazine, $edition);
            $edition = $this->addEditionDownloadLinks($magazine, $edition, $magazineName);
            $edition['description_rendered'] = $this->bridge->renderMarkdown(
                $edition['description'] ? $edition['description'] : '',
                ['emphasis', 'strikethrough', 'link', 'list', 'table'],
            )['c'];
            return $edition;
        }
        return null;
    }

    private function addEntryDownloadUrl($magazine, $edition, $entry, $magazineName, $editionName) {
        $fileNamePrefix = Utils::escapeFileNameLossy(
            $magazineName . ' - ' . $editionName . ' - ' . $entry['page'] . ' ' . $entry['title'] . '.'
        );

        // TODO: use the actual field
        $entry['recitationFormats'] = ['mp3', 'flac', 'wav'];

        // \Grav\Common\Utils::getMimeByExtension returns incorrect types :(
        $mimeTypes = [
            'mp3' => 'audio/mpeg',
            'flac' => 'audio/flac',
            'wav' => 'audio/wav'
        ];

        $entry['downloads'] = [];
        foreach ($entry['recitationFormats'] as $fmt) {
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

    function getEditionTocEntries($magazine, $edition, $magazineName, $editionName) {
        $allEntries = [];
        while (true) {
            $res = $this->bridge->get("/magazines/$magazine/editions/$edition/toc", array(
                'fields' => ['id', 'title', 'page', 'author', 'recitationAuthor', 'highlighted'],
                'order' => [['page', 'asc']],
                'offset' => count($allEntries),
                'limit' => 100,
            ), 240);
            if (!$res['k']) return null;
            $hasHighlighted = false;
            foreach ($res['b'] as $entry) {
                if ($entry['highlighted']) $hasHighlighted = true;
                $entry = $this->addEntryDownloadUrl($magazine, $edition, $entry, $magazineName, $editionName);
                $allEntries[] = $entry;
            }
            if (!$hasHighlighted) {
                // if there are no highlighted items, mark the first three as highlighted
                for ($i = 0; $i < 3; $i++) {
                    if (isset($allEntries[$i])) $allEntries[$i]['highlighted'] = true;
                }
            }
            if (count($allEntries) >= $res['h']['x-total-items']) break;
        }

        return $allEntries;
    }

    function getEditionTocEntry($magazine, $edition, $entry, $magazineName, $editionName) {
        $res = $this->bridge->get("/magazines/$magazine/editions/$edition/toc/$entry", array(
            'fields' => ['id', 'title', 'page', 'author', 'recitationAuthor', 'highlighted', 'text'],
        ), 240);
        if ($res['k']) {
            $entry = $res['b'];
            $entry['text_rendered'] = $this->bridge->renderMarkdown(
                $entry['text'] ? $entry['text'] : '',
                ['emphasis', 'strikethrough', 'link', 'list', 'table', 'image'],
            )['c'];
            $entry = $this->addEntryDownloadUrl($magazine, $edition, $entry, $magazineName, $editionName);
            return $entry;
        }
        return null;
    }


    public function run() {
        $path = $this->plugin->getGrav()['page']->header()->path_subroute;
        $route = ['type' => 'error'];
        if (!$path) {
            $header = $this->plugin->getGrav()['page']->header();
            $magazines = null;
            if (isset($header->magazines)) {
                $magazines = explode(',', $header->magazines);
            }
            $route = [
                'type' => 'list',
                'magazines' => $magazines,
            ];
        } else if (preg_match('/^(\d+)$/', $path, $matches)) {
            $route = [
                'type' => 'magazine',
                'magazine' => $matches[1],
            ];
        } else if (preg_match('/^(\d+)\/' . self::EDITION . '\/(\d+)$/', $path, $matches)) {
            $route = [
                'type' => 'edition',
                'magazine' => $matches[1],
                'edition' => $matches[2],
            ];
        } else if (preg_match('/^(\d+)\/' . self::EDITION . '\/(\d+)\/' . self::TOC . '\/(\d+)$/', $path, $matches)) {
            $route = [
                'type' => 'toc_entry',
                'magazine' => $matches[1],
                'edition' => $matches[2],
                'entry' => $matches[3],
            ];
        }

        $pathComponents = array( 
            'login_path' => $this->plugin->loginPath,
            'base' => $this->plugin->getGrav()['page']->header()->path_base,
            'magazine' => self::MAGAZINE,
            'edition' => self::EDITION,
            'toc' => self::TOC,
        );

        $canRead = !!$this->plugin->aksoUser;

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
            );
        } else if ($route['type'] === 'magazine') {
            $magazine = $this->getMagazine($route['magazine']);
            if (!$magazine) return array('type' => 'error');
            $editions = $this->getMagazineEditions($route['magazine']);

            return array(
                'path_components' => $pathComponents,
                'type' => 'magazine',
                'magazine' => $magazine,
                'editions' => $editions,
            );
        } else if ($route['type'] === 'edition') {
            $magazine = $this->getMagazine($route['magazine']);
            if (!$magazine) return array('type' => 'error');

            $org = $magazine['org'];
            $canRead = $this->user ? $this->user->hasPerms(["magazines.read.$org"])['p'][0] : false;

            $edition = $this->getMagazineEdition($route['magazine'], $route['edition'], $magazine['name']);
            if (!$edition) return array('type' => 'error');

            return array(
                'path_components' => $pathComponents,
                'type' => 'edition',
                'magazine' => $magazine,
                'edition' => $edition,
                'toc_entries' => $this->getEditionTocEntries(
                    $route['magazine'], $route['edition'], $magazine['name'], $edition['idHuman']
                ),
                'can_read' => $canRead,
            );
        } else if ($route['type'] === 'toc_entry') {
            if (!$canRead) {
                $this->plugin->getGrav()->redirectLangSafe($this->plugin->loginPath, 302);
            }

            $magazine = $this->getMagazine($route['magazine']);
            if (!$magazine) return array('type' => 'error');

            $org = $magazine['org'];
            $canRead = $this->user ? $this->user->hasPerms(["magazines.read.$org"])['p'][0] : false;

            $edition = $this->getMagazineEdition($route['magazine'], $route['edition'], $magazine['name']);
            if (!$edition) return array('type' => 'error');
            $entry = $this->getEditionTocEntry(
                $route['magazine'], $route['edition'], $route['entry'], $magazine['name'], $edition['idHuman']
            );
            if (!$edition) return array('type' => 'error');

            return array(
                'path_components' => $pathComponents,
                'type' => 'toc_entry',
                'magazine' => $magazine,
                'edition' => $edition,
                'entry' => $entry,
                'can_read' => $canRead,
            );
        } else if ($route['type'] === 'error') {
            // TODO
            return array('type' => 'error');
        }
    }
}
