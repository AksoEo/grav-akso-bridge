<?php
namespace Grav\Plugin\AksoBridge;

use Grav\Plugin\AksoBridgePlugin;
use Grav\Plugin\AksoBridge\Utils;

class DelegationApplications {
    const SESX_KEY = 'delegationApplicationState';
    const PAGE_ORDER = ['pre', 'cities', 'subjects', 'final'];

    private $plugin, $bridge, $user;

    public function __construct($plugin, $bridge) {
        $this->plugin = $plugin;
        $this->bridge = $bridge;
        $this->user = $plugin->aksoUser ? $plugin->bridge : null;
    }

    private $state = [];
    private function deserializeState() {
        $this->state = array(
            'has_form' => true,
            'page' => self::PAGE_ORDER[0],
            'cities' => [],
            'subjects' => [],
        );

        if ($this->plugin->aksoUser) {
            $res = $this->bridge->get("/delegations/applications", array(
                'fields' => [
                    'id',
                    'cities',
                    'subjects',
                    'hosting',
                    'tos.docDataProtectionUEA',
                    'tos.docDelegatesUEA',
                    'tos.docDelegatesDataProtectionUEA',
                ],
                'filter' => array(
                    'codeholderId' => $this->plugin->aksoUser['id'],
                    'status' => 'pending',
                ),
                'limit' => 1,
            ));
            if (!$res['k']) throw new \Exception('failed to fetch applications');
            if (!empty($res['b'])) {
                $this->state['page'] = 'pending';
                $this->state['has_form'] = false;
                $this->state['pending_appl'] = $res['b'][0];
                return;
            }
        }

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
                'str' => $query . '*',
                'cols' => ['searchLabel'],
            );
            //$options['order'] = [['_relevance', 'desc']]; // makes it worse
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

    private function runFinalPage() {
        $selectedCities = $this->getCities($this->state['cities']);
        $selectedSubjects = $this->getSubjects($this->state['subjects']);

        $valid = true;
        $error = null;

        $hosting = null;
        if (isset($_POST['hosting']) && gettype($_POST['hosting']) === 'array' && isset($_POST['hosting']['enabled'])) {
            $data = $_POST['hosting'];
            $maxDays = isset($data['maxDays']) ? (int) $data['maxDays'] : 0;
            $maxPersons = isset($data['maxPersons']) ? (int) $data['maxPersons'] : 0;
            $description = isset($data['description']) ? (string) $data['description'] : '';
            $psProfileURL = isset($data['psProfileURL']) ? (string) $data['psProfileURL'] : '';

            $hosting = array(
                'maxDays' => $maxDays ?: null,
                'maxPersons' => $maxPersons ?: null,
                'description' => $description ?: null,
                'psProfileURL' => $psProfileURL ?: null,
            );
        }

        $tos = array('paperAnnualBook' => true);
        if (isset($_POST['tos']) && gettype($_POST['tos']) === 'array') {
            $tos = array();
            $requiredFields = [
                'docDataProtectionUEA',
                'docDelegatesUEA',
                'docDelegatesDataProtectionUEA',
            ];
            $optionalFields = [
                'paperAnnualBook',
            ];
            $allFields = array_merge([], $requiredFields, $optionalFields);

            foreach ($allFields as $field) {
                $tos[$field] = isset($_POST['tos'][$field]);
                if (in_array($field, $requiredFields) && !$tos[$field]) {
                    $valid = false;
                    $error = $this->plugin->locale['delegate_appl']['err_missing_tos_' . $field];
                }
            }
        }

        $userNotes = null;
        if (isset($_POST['notes']) && gettype($_POST['notes']) === 'string' && !empty($_POST['notes'])) {
            $userNotes = $_POST['notes'];
        }

        if ($valid && isset($_POST['action']) && $_POST['action'] === 'submit') {
            $options = array(
                'org' => 'uea',
                'codeholderId' => $this->plugin->aksoUser['id'],
                'applicantNotes' => $userNotes,
                'cities' => array_map(function ($id) { return 'Q' . $id; }, $this->state['cities']),
                'subjects' => $this->state['subjects'],
                'hosting' => $hosting,
                'tos' => $tos,
            );

            $res = $this->bridge->post("/delegations/applications", $options, [], []);
            if ($res['k']) {
                return array(
                    'page' => 'success',
                );
            } else {
                if ($res['sc'] === 400) $error = $this->plugin->locale['delegate_appl']['err_submit_bad_request'];
                else $error = $this->plugin->locale['delegate_appl']['err_submit_unknown'];
            }
        }

        return array(
            'page' => 'final',
            'error' => $error,
            'has_summary' => true,
            'can_go_back' => true,
            'countries' => $this->getCountries(),
            'selected_cities' => $selectedCities,
            'selected_subjects' => $selectedSubjects,
            'hosting' => $hosting,
            'tos' => $tos,
            'notes' => $userNotes,
        );
    }

    private function runPendingPage() {
        $this->state['cities'] = array_map(function ($id) {
            return (int) substr($id, 1); // remove Q
        }, $this->state['pending_appl']['cities']);
        $this->state['subjects'] = $this->state['pending_appl']['subjects'];

        if (isset($_POST['action']) && $_POST['action'] === 'maybe_delete') {
            return array(
                'page' => 'delete',
                'return' => $this->plugin->getGrav()['uri']->path(),
            );
        } else if (isset($_POST['action']) && $_POST['action'] === 'delete') {
            $id = $this->state['pending_appl']['id'];
            $res = $this->bridge->delete("/delegations/applications/$id", [], []);
            if ($res['k']) {
                return array(
                    'is_pre_form' => true,
                    'message' => $this->plugin->locale['delegate_appl']['delete_success'],
                    'has_page_contents' => true,
                );
            } else {
                return array(
                    'page' => 'delete',
                    'error' => $this->plugin->locale['delegate_appl']['delete_error'],
                    'return' => $this->plugin->getGrav()['uri']->path(),
                );
            }
        }

        return array(
            'page' => 'pending',
            'has_page_contents' => true,
            'has_summary' => true,
            'countries' => $this->getCountries(),
            'cities' => $this->state['subjects'],
            'subjects' => $this->state['subjects'],
            'selected_cities' => $this->getCities($this->state['cities']),
            'selected_subjects' => $this->getSubjects($this->state['subjects']),
            'hosting' => $this->state['pending_appl']['hosting'],
            'tos' => $this->state['pending_appl']['tos'],
        );
    }

    function run() {
        $this->deserializeState();

        if ($this->state['has_form'] && isset($_POST['_begin'])) {
            $this->state['page'] = self::PAGE_ORDER[1];
        }
        if ($this->state['has_form'] && isset($_POST['_back'])) {
            $index = array_search($this->state['page'], self::PAGE_ORDER);
            if ($index > 0) {
                $this->state['page'] = self::PAGE_ORDER[$index - 1];
            }
        }

        $pageParams = null;
        if ($this->state['page'] === 'pre') {
            $pageParams = array('is_pre_form' => true, 'has_page_contents' => true);
        } else if ($this->state['page'] === 'cities') {
            $pageParams = $this->runCitiesPage();
        } else if ($this->state['page'] === 'subjects') {
            $pageParams = $this->runSubjectsPage();
        } else if ($this->state['page'] === 'final') {
            $pageParams = $this->runFinalPage();
        } else if ($this->state['page'] === 'pending') {
            $pageParams = $this->runPendingPage();
        }

        $pageParams['state'] = $this->state;
        $pageParams['state_serialized'] = $this->serializeState();
        return $pageParams;
    }
}
