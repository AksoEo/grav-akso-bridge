<?php
namespace Grav\Plugin\AksoBridge;

use Grav\Plugin\AksoBridgePlugin;
use Grav\Plugin\AksoBridge\MarkdownExt;
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

                {
                    $startTime = $vote['timeStart'];
                    $startTime = new \DateTime("@$startTime");
                    $endTime = $vote['timeEnd'];
                    $endTime = new \DateTime("@$endTime");

                    $vote['fmt_time_start'] = Utils::formatDateTimeUtc($startTime);
                    $vote['fmt_time_end'] = Utils::formatDateTimeUtc($endTime);
                }

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
                    'codeholderType',
                    'firstNameLegal',
                    'lastNameLegal',
                    'firstName',
                    'lastName',
                    'honorific',
                    'fullName',
                    'nameAbbrev',
                    'lastNamePublicity',
                    'profilePictureHash',
                    'profilePicturePublicity',
                    'address.country',
                    'addressPublicity',
                    'publicCountry',
                    'email',
                    'emailPublicity',
                    'publicEmail',
                    'website',
                    'biography',
                ],
                'filter' => array('id' => array('$in' => $codeholderIds)),
                'limit' => 100,
            ));
            if (!$chRes['k']) {
                throw new \Exception('Could not fetch vote option codeholders');
            }
            $codeholders = array();
            $isMember = $this->plugin->aksoUser['member'];
            foreach ($chRes['b'] as $ch) {
                if ($ch['codeholderType'] === 'human') {
                    $canSeeLastName = $ch['lastNamePublicity'] === 'public'
                        || ($ch['lastNamePublicity'] === 'members' && $isMember);

                    $ch['fmt_name'] = implode(' ', array_filter([
                        $ch['honorific'],
                        $ch['firstName'] ?: $ch['firstNameLegal'],
                        $canSeeLastName ? ($ch['lastName'] ?: $ch['lastNameLegal']) : null,
                    ]));
                } else if ($ch['codeholderType'] === 'org') {
                    $ch['fmt_name'] = $ch['fullName'];
                    if ($ch['nameAbbrev']) {
                        $ch['fmt_name'] .= ' (' . $ch['nameAbbrev'] . ')';
                    }
                }

                $ch['country'] = null;
                if ($ch['publicCountry']) $ch['country'] = $ch['publicCountry'];
                else if ($ch['addressPublicity'] === 'public'
                    || ($ch['addressPublicity'] === 'members' && $isMember)) {
                    $ch['country'] = $ch['address']['country'];
                }
                if ($ch['country']) {
                    $ch['fmt_country'] = $this->formatCountry($ch['country']);
                    $ch['fmt_country_emoji'] = MarkdownExt::getEmojiForFlag($ch['country']);
                }

                $ch['email'] = $ch['publicEmail'] ?:
                    (($ch['emailPublicity'] === 'public' || ($ch['emailPublicity'] === 'members' && $isMember))
                        ? $ch['email']
                        : null);

                if ($ch['profilePictureHash'] && ($ch['profilePicturePublicity'] === 'public'
                    || ($ch['profilePicturePublicity'] === 'members' && $isMember))) {
                    $picPrefix = AksoBridgePlugin::CODEHOLDER_PICTURE_PATH . '?'
                        . 'c=' . $ch['id']
                        . '&s=';
                    $ch['icon_src'] = $picPrefix . '64px';
                    $ch['icon_srcset'] = $picPrefix . '64px 1x, ' . $picPrefix . '128px 2x, ' . $picPrefix . '256px 3x';
                }

                $ch['has_details'] = $ch['email'] || $ch['website'] || $ch['biography'];

                $codeholders[$ch['id']] = $ch;
            }
            $vote['codeholders'] = $codeholders;
        }

        if ($vote['hasVoted']) {
            $res = $this->bridge->get("/codeholders/self/votes/$id/ballot", array(
                'fields' => ['time', 'ballot'],
            ));
            if (!$res['k']) {
                throw new \Exception('Could not fetch vote ballot');
            }
            if ($res['b']) {
                try {
                    $timeVoted = $res['b']['timeVoted'];
                    $res['b']['fmt_time'] = Utils::formatDateTimeUtc(new \DateTime("@$timeVoted"));
                } catch (\Exception $e) {
                    $res['b']['fmt_time'] = '???';
                }
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
            $rvalue = null;

            if ($vote['type'] == 'yn' || $vote['type'] == 'ynb') {
                $choice = $_POST['choice'];

                $res = $this->bridge->put("/codeholders/self/votes/$voteId/ballot", array(
                    'ballot' => $choice,
                ), [], []);
            } else if ($vote['type'] === 'stv' || $vote['type'] === 'rp') {
                $optionCount = count($vote['options']);
                $ranks = isset($_POST['ranks']) && gettype($_POST['ranks']) == 'array' ? $_POST['ranks'] : [];
                if (isset($_POST['ballot'])) {
                    try {
                        $ballot = json_decode($_POST['ballot']);
                        if (gettype($ballot) === 'array') $ranks = $ballot;
                    } catch (\Exception $e) {}
                }

                $isTieBreaker = $vote['tieBreakerCodeholder'] == $this->plugin->aksoUser['id'];

                $optionsCovered = 0;
                $options = [];
                for ($i = 0; $i < count($ranks); $i++) {
                    if (!$ranks[$i]) continue;
                    $value = (int) $ranks[$i];
                    $optionsCovered += 1;

                    if ($value <= 0 || $value > $optionCount) {
                        return array(
                            'error' => $this->plugin->locale['account_votes']['submit_err_bad_rank_0']
                                . $value . $this->plugin->locale['account_votes']['submit_err_bad_rank_1'],
                            'values' => $ranks,
                        );
                    }
                    if (isset($options[$value - 1]) && $vote['type'] === 'stv') {
                        return array(
                            'error' => $this->plugin->locale['account_votes']['submit_err_dup_rank_0']
                                . $value . $this->plugin->locale['account_votes']['submit_err_dup_rank_1'],
                            'values' => $ranks,
                        );
                    }

                    if ($vote['type'] === 'stv') {
                        $options[$value - 1] = $i;
                    } else {
                        if (!isset($options[$value - 1])) $options[$value - 1] = [];
                        $options[$value - 1][] = $i;
                    }
                }

                if ($isTieBreaker && $optionsCovered < $optionCount) {
                    return array(
                        'error' => $this->plugin->locale['account_votes']['submit_err_tie_breaker_complete'],
                    );
                }

                ksort($options);

                // ensure there are no holes in the ranks
                $prevWasNone = false;
                for ($i = 0; $i < $optionCount; $i++) {
                    $isNone = !isset($options[$i]);
                    if (!$isNone && $prevWasNone) {
                        return array(
                            'error' => $this->plugin->locale['account_votes']['submit_err_rank_hole_0']
                                . ($i + 1) . $this->plugin->locale['account_votes']['submit_err_rank_hole_1'],
                            'values' => $ranks,
                        );
                    }
                    $prevWasNone = $isNone;
                }

                $rvalue = $ranks;
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
                return array('values' => $rvalue, 'error' => $this->plugin->locale['account_votes']['submit_err_bad_request']);
            } else if ($res['sc'] == 404) {
                return array('values' => $rvalue, 'error' => $this->plugin->locale['account_votes']['submit_err_not_allowed']);
            } else if ($res['sc'] == 409) {
                return array('values' => $rvalue, 'error' => $this->plugin->locale['account_votes']['submit_err_secret_resubmission']);
            } else if ($res['sc'] == 423) {
                return array('values' => $rvalue, 'error' => $this->plugin->locale['account_votes']['submit_err_ended']);
            } else {
                return array('values' => $rvalue, 'error' => $this->plugin->locale['account_votes']['submit_err_unknown']);
            }
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

