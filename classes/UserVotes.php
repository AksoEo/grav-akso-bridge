<?php
namespace Grav\Plugin\AksoBridge;

use Grav\Plugin\AksoBridge\Utils;

class UserVotes {
    private $plugin, $app, $bridge;

    public function __construct($plugin, $app, $bridge, $path) {
        $this->plugin = $plugin;
        $this->app = $app;
        $this->bridge = $bridge;
    }

    function getVotesList() {
        $votes = [];
        while (true) {
            $res = $this->bridge->get('/codeholders/self/votes', array(
                'fields' => ['mayVote', 'hasVoted', 'id', 'org', 'name', 'timeStart', 'timeEnd', 'hasStarted', 'hasEnded', 'hasResults', 'isActive', 'description'],
                'offset' => count($votes),
                'order' => [['timeEnd', 'desc']],
                'limit' => 100,
            ));
            if (!$res['k']) {
                throw new Exception('Could not fetch self/votes');
            }
            foreach ($res['b'] as $vote) {
                $vote['description_rendered'] = $this->bridge->renderMarkdown(
                    $vote['description'] ?: '',
                    ['emphasis', 'strikethrough', 'link', 'list', 'table', 'image'],
                )['c'];
                $votes[] = $vote;
            }
            if ($res['h']['x-total-items'] >= count($votes)) break;
        }
        return $votes;
    }

    function getVote($id) {
        $res = $this->bridge->get("/codeholders/self/votes/$id", array(
            'fields' => [
                'id', 'org', 'name', 'description', 'type', 'mayVote', 'isActive', 'hasResults',
                'ballotsSecret', 'hasVoted', 'options', 'tieBreakerCodeholder',
            ],
        ));
        if (!$res['k']) {
            if ($res['sc'] === 404) {
                $this->plugin->getGrav()->fireEvent('onPageNotFound');
                return null;
            } else {
                throw new \Exception('Could not fetch vote');
            }
        }
        $vote = $res['b'];
        $vote['description_rendered'] = $this->bridge->renderMarkdown(
            $vote['description'] ?: '',
            ['emphasis', 'strikethrough', 'link', 'list', 'table', 'image'],
        )['c'];

        if ($vote['options']) {
            $codeholderIds = [];
            foreach ($vote['options'] as &$option) {
                if ($option['type'] === 'codeholder' && !in_array($option['codeholderId'], $codeholderIds)) {
                    $codeholderIds[] = $option['codeholderId'];
                }

                if ($option['description']) {
                    $option['description_rendered'] = $this->bridge->renderMarkdown(
                        $option['description'] ?: '',
                        ['emphasis', 'strikethrough', 'link', 'list'],
                    )['c'];
                }
            }
            $chRes = $this->app->bridge->get("/codeholders", array(
                'fields' => [
                    'id',
                    'firstNameLegal',
                    'lastNameLegal',
                    'firstName',
                    'lastName',
                    'honorific',
                    'fullName',
                    'fullNameLocal',
                    'nameAbbrev',
                    'lastNamePublicity',
                    'profilePictureHash',
                ],
                'filter' => array('id' => array('$in' => $codeholderIds)),
                'limit' => 100,
            ));
            if (!$chRes['k']) {
                throw new \Exception('Could not fetch vote option codeholders');
            }
            $codeholders = array();
            foreach ($chRes['b'] as $ch) $codeholders[$ch['id']] = $ch;
            $vote['codeholders'] = $codeholders;
        }

        if ($vote['hasVoted']) {
            $res = $this->bridge->get("/codeholders/self/votes/$id/ballot", array(
                'fields' => ['time', 'ballot'],
            ));
            if (!$res['k']) {
                throw new \Exception('Could not fetch vote ballot');
            }
            $vote['ballot'] = $res['b'];
        }

        if ($vote['hasResults']) {
            // TODO: fetch results
        }

        return $vote;
    }

    private function runVoteAction($voteId, $vote) {
        $action = isset($_POST['action']) && gettype($_POST['action']) === 'string' ? $_POST['action'] : null;

        if ($action == 'back' || $action == 'vote' || $action == 'confirm') {
            $res = null;

            if ($vote['type'] == 'yn' || $vote['type'] == 'ynb') {
                $choice = $_POST['choice'];

                $res = $this->bridge->put("/codeholders/self/votes/$voteId/ballot", array(
                    'ballot' => $choice,
                ), [], []);
            } else if ($vote['type'] == 'stv') {
                $optionCount = count($vote['options']);
                $ranks = isset($_POST['ranks']) && gettype($_POST['ranks']) == 'array' ? $_POST['ranks'] : [];
                if (isset($_POST['ballot'])) {
                    try {
                        $ballot = json_decode($_POST['ballot']);
                        if (gettype($ballot) === 'array') $ranks = $ballot;
                    } catch (\Exception $e) {}
                }

                $isTieBreaker = $vote['tieBreakerCodeholder'] == $this->plugin->aksoUser['id'];

                $options = [];
                for ($i = 0; $i < count($ranks); $i++) {
                    if (!$ranks[$i]) continue;
                    $value = (int) $ranks[$i];
                    if ($value <= 0 || $value > $optionCount) {
                        return array(
                            'error' => $this->plugin->locale['account_votes']['submit_err_bad_rank_0']
                                . $value . $this->plugin->locale['account_votes']['submit_err_bad_rank_1'],
                            'values' => $ranks,
                        );
                    }
                    if (isset($options[$value - 1])) {
                        return array(
                            'error' => $this->plugin->locale['account_votes']['submit_err_dup_rank_0']
                                . $value . $this->plugin->locale['account_votes']['submit_err_dup_rank_1'],
                            'values' => $ranks,
                        );
                    }
                    $options[$value - 1] = $i;
                }
                ksort($options);
                $prevWasNone = false;
                for ($i = 0; $i < $optionCount; $i++) {
                    $isNone = !isset($options[$i]);
                    if ($isNone && $isTieBreaker) {
                        return array(
                            'error' => $this->plugin->locale['account_votes']['submit_err_tie_breaker_complete'],
                        );
                    }

                    if (!$isNone && $prevWasNone) {
                        return array(
                            'error' => $this->plugin->locale['account_votes']['submit_err_rank_hole_0']
                                . ($i + 1) . $this->plugin->locale['account_votes']['submit_err_rank_hole_1'],
                            'values' => $ranks,
                        );
                    }
                    $prevWasNone = $isNone;
                }

                if ($action === 'vote') {
                    return array(
                        'confirm' => true,
                        'ballot' => $options,
                        'ballot_coded' => json_encode($ranks),
                    );
                } else if ($action === 'confirm') {
                    $res = $this->bridge->put("/codeholders/self/votes/$voteId/ballot", array(
                        'ballot' => $options,
                    ), [], []);
                } else if ($action === 'back') {
                    return array('values' => $ranks);
                }
            }

            if ($res['k']) {
                return array('message' => $this->plugin->locale['account_votes']['submit_msg_success']);
            } else if ($res['sc'] == 400) {
                return array('error' => $this->plugin->locale['account_votes']['submit_err_bad_request']);
            } else if ($res['sc'] == 404) {
                return array('error' => $this->plugin->locale['account_votes']['submit_err_not_allowed']);
            } else if ($res['sc'] == 409) {
                return array('error' => $this->plugin->locale['account_votes']['submit_err_secret_resubmission']);
            } else if ($res['sc'] == 423) {
                return array('error' => $this->plugin->locale['account_votes']['submit_err_ended']);
            } else {
                return array('error' => $this->plugin->locale['account_votes']['submit_err_unknown']);
            }
        }
    }

    public function run() {
        $voteId = null;
        if (isset($_GET['v']) && gettype($_GET['v']) === 'string') {
            $voteId = (int) $_GET['v'];
        }

        if ($voteId) {
            $vote = $this->getVote($voteId);
            $state = null;
            if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                $state = $this->runVoteAction($voteId, $vote);
                $vote = $this->getVote($voteId); // fetch again to update state
            }

            return array(
                'state' => $state,
                'path' => $this->plugin->getGrav()['uri']->path(),
                'page' => 'vote',
                'vote' => $vote,
            );
        }

        return array(
            'page' => 'votes',
            'path' => $this->plugin->getGrav()['uri']->path(),
            'votes' => $this->getVotesList()
        );
    }
}

