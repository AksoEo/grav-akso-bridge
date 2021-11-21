<?php
namespace Grav\Plugin\AksoBridge;

use Grav\Plugin\AksoBridgePlugin;
use Grav\Plugin\AksoBridge\Utils;

class DelegationApplications {
    const SESX_KEY = 'delegationApplicationState';
    const PAGE_ORDER = ['cities', 'subjects', 'final'];

    private $plugin, $bridge, $user;

    public function __construct($plugin, $bridge) {
        $this->plugin = $plugin;
        $this->bridge = $bridge;
        $this->user = $plugin->aksoUser ? $plugin->bridge : null;
    }

    private $state = [];
    private function deserializeState() {
        $this->state = array(
            'page' => self::PAGE_ORDER[0],
            'cities' => [],
            'subjects' => [],
        );

        $postData = null;
        if (isset($_SESSION[self::SESX_KEY]) && gettype($_SESSION[self::SESX_KEY]) === 'string') {
            try {
                $postData = json_decode($_SESSION[self::SESX_KEY], true);
            } catch (\Exception $e) {}
            unset($_SESSION[self::SESX_KEY]);
        } else if (isset($_POST['state']) && gettype($_POST['state'] === 'string')) {
            try {
                $postData = json_decode($_POST['state'], true);
            } catch (\Exception $e) {}
        }

        if ($postData) {
            if (isset($postData['cities']) && gettype($postData['cities']) === 'array') {
                $this->state['cities'] = array_values($postData['cities']);
            }
            if (isset($postData['subjects']) && gettype($postData['subjects']) === 'array') {
                $this->state['subjects'] = array_values($postData['subjects']);
            }
            if (isset($postData['page']) && in_array($postData['page'], self::PAGE_ORDER)) {
                $this->state['page'] = $postData['page'];
            }
        }
    }
    private function serializeState() {
        return json_encode($this->state);
    }

    private function getCountries() {
        $res = $this->bridge->get("/countries", array(
            'fields' => ['code', 'name_eo'],
            'limit' => 300,
        ), 60);
        if (!$res['k']) throw new \Exception('failed to get countries');
        $countries = [];
        foreach ($res['b'] as $item) $countries[$item['code']] = $item['name_eo'];
        return $countries;
    }

    private function searchCities($query = '') {
        $options = array(
            'fields' => ['id', 'eoLabel', 'nativeLabel', 'subdivision_nativeLabel', 'subdivision_eoLabel', 'country'],
            'limit' => 25,
        );
        if (!empty($query)) {
            $options['search'] = array(
                'str' => $query,
                'cols' => ['searchLabel'],
            );
            $options['order'] = [['_relevance', 'desc']];
        }
        $res = $this->bridge->get("/geodb/cities", $options, empty($query) ? 60 : 0);
        if (!$res['k']) throw new \Exception('failed to search cities');
        $cities = [];
        foreach ($res['b'] as $item) {
            $item['id'] = (int) substr($item['id'], 1);
            $cities[$item['id']] = $item;
        }
        return $cities;
    }

    private function getCities($cityIds) {
        if (empty($cityIds)) return [];
        $res = $this->bridge->get("/geodb/cities", array(
            'filter' => array('id' => array('$in' => $cityIds)),
            'limit' => count($cityIds),
            'fields' => ['id', 'eoLabel', 'nativeLabel', 'subdivision_nativeLabel', 'subdivision_eoLabel', 'country'],
        ));
        if (!$res['k']) throw new \Exception('could not fetch cities');
        $cities = [];
        foreach ($res['b'] as $item) {
            $item['id'] = (int) substr($item['id'], 1);
            $cities[$item['id']] = $item;
        }
        return $cities;
    }

    private function searchSubjects($query = '') {
        $options = array(
            'fields' => ['id', 'name', 'description'],
            'limit' => 25,
        );
        if (!empty(trim($query))) {
            $options['search'] = array(
                'str' => trim($query) . '*',
                'cols' => ['name'],
            );
            $options['order'] = [['_relevance', 'desc']];
        } else {
            // TODO: use popularity order
        }
        $res = $this->bridge->get("/delegations/subjects", $options, empty($query) ? 60 : 0);
        if (!$res['k']) throw new \Exception('failed to search subjects' . $res['b']);
        $subjects = [];
        foreach ($res['b'] as $item) $subjects[$item['id']] = $item;
        return $subjects;
    }

    private function getSubjects($subjectIds) {
        if (empty($subjectIds)) return [];
        $res = $this->bridge->get("/delegations/subjects", array(
            'filter' => array('id' => array('$in' => $subjectIds)),
            'limit' => count($subjectIds),
            'fields' => ['id', 'name', 'description'],
        ));
        if (!$res['k']) throw new \Exception('could not fetch subjects');
        $subjects = [];
        foreach ($res['b'] as $item) $subjects[$item['id']] = $item;
        return $subjects;
    }

    function runCitiesPage() {
        $query = '';

        if (isset($_POST['q']) && gettype($_POST['q']) === 'string') {
            $query = $_POST['q'];
        }

        if (isset($_GET['add_city_id']) && gettype($_GET['add_city_id']) === 'string') {
            // set temporary _SESSION state and redirect, to get rid of path parameters
            $cityId = (int) $_GET['add_city_id'];
            if (!in_array($cityId, $this->state['cities'])) {
                $this->state['cities'][] = $cityId;
            }
            $_SESSION[self::SESX_KEY] = $this->serializeState();
            $this->plugin->getGrav()->redirectLangSafe($this->plugin->getGrav()['uri']->path(), 302);
            die();
        } else if (isset($_GET['remove_city_id']) && gettype($_GET['remove_city_id']) === 'string') {
            // same deal
            $cityId = (int) $_GET['remove_city_id'];
            $this->state['cities'] = array_diff($this->state['cities'], [$cityId]);
            $_SESSION[self::SESX_KEY] = $this->serializeState();
            $this->plugin->getGrav()->redirectLangSafe($this->plugin->getGrav()['uri']->path(), 302);
            die();
        } else if (isset($_GET['continue'])) {
            $this->state['page'] = 'subjects';
            $_SESSION[self::SESX_KEY] = $this->serializeState();
            $this->plugin->getGrav()->redirectLangSafe($this->plugin->getGrav()['uri']->path(), 302);
            die();
        }

        $results = [];
        if ($query) $results = $this->searchCities($query);
        $selectedCities = $this->getCities($this->state['cities']);

        return array(
            'page' => 'cities',
            'countries' => $this->getCountries(),
            'results' => $results,
            'selected_cities' => $selectedCities,
            'query' => $query,
            'search_path' => $this->plugin->getGrav()['uri']->path(),
            'add_city_path' => $this->plugin->getGrav()['uri']->path() . '?add_city_id=',
            'remove_city_path' => $this->plugin->getGrav()['uri']->path() . '?remove_city_id=',
            'continue_path' => $this->plugin->getGrav()['uri']->path() . '?continue=1',
        );
    }

    private function runSubjectsPage() {
        $query = '';

        if (isset($_POST['q']) && gettype($_POST['q']) === 'string') {
            $query = $_POST['q'];
        }

        if (isset($_GET['add_subject_id']) && gettype($_GET['add_subject_id']) === 'string') {
            $subjectId = (int) $_GET['add_subject_id'];
            if (!in_array($subjectId, $this->state['subjects'])) {
                $this->state['subjects'][] = $subjectId;
            }
            $_SESSION[self::SESX_KEY] = $this->serializeState();
            $this->plugin->getGrav()->redirectLangSafe($this->plugin->getGrav()['uri']->path(), 302);
            die();
        } else if (isset($_GET['remove_subject_id']) && gettype($_GET['remove_subject_id']) === 'string') {
            $subjectId = (int) $_GET['remove_subject_id'];
            $this->state['subjects'] = array_diff($this->state['subjects'], [$subjectId]);
            $_SESSION[self::SESX_KEY] = $this->serializeState();
            $this->plugin->getGrav()->redirectLangSafe($this->plugin->getGrav()['uri']->path(), 302);
            die();
        } else if (isset($_GET['continue'])) {
            $this->state['page'] = 'final';
            $_SESSION[self::SESX_KEY] = $this->serializeState();
            $this->plugin->getGrav()->redirectLangSafe($this->plugin->getGrav()['uri']->path(), 302);
            die();
        }

        $results = $this->searchSubjects($query);
        $selectedCities = $this->getCities($this->state['cities']);
        $selectedSubjects = $this->getSubjects($this->state['subjects']);

        return array(
            'page' => 'subjects',
            'has_summary' => true,
            'can_go_back' => true,
            'countries' => $this->getCountries(),
            'query' => $query,
            'results' => $results,
            'selected_cities' => $selectedCities,
            'selected_subjects' => $selectedSubjects,
            'search_path' => $this->plugin->getGrav()['uri']->path(),
            'add_subject_path' => $this->plugin->getGrav()['uri']->path() . '?add_subject_id=',
            'remove_subject_path' => $this->plugin->getGrav()['uri']->path() . '?remove_subject_id=',
            'continue_path' => $this->plugin->getGrav()['uri']->path() . '?continue=1',
        );
    }

    function run() {
        $this->deserializeState();

        if (isset($_POST['_back'])) {
            $index = array_search($this->state['page'], self::PAGE_ORDER);
            if ($index > 0) {
                $this->state['page'] = self::PAGE_ORDER[$index - 1];
            }
        }

        $pageParams = null;
        if ($this->state['page'] === 'cities') {
            $pageParams = $this->runCitiesPage();
        } else if ($this->state['page'] === 'subjects') {
            $pageParams = $this->runSubjectsPage();
        }

        $pageParams['state'] = $this->state;
        $pageParams['state_serialized'] = $this->serializeState();
        return $pageParams;
    }
}
