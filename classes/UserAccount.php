<?php
namespace Grav\Plugin\AksoBridge;

use Grav\Plugin\AksoBridge\Registration;
use Grav\Plugin\AksoBridge\Utils;

// Handles the user’s “my account” page.
class UserAccount {
    // query parameter used for loading the profile picture
    const QUERY_PROFILE_PICTURE = 'profile_picture';
    const QUERY_EDIT = 'redakti';
    const QUERY_CANCEL_REQUEST = 'peto-nuligi';
    const QUERY_EDIT_PICTURE = 'redakti-profilbildon';

    private $plugin, $app, $bridge, $page;
    private $editing = false;

    public function __construct($plugin, $app, $bridge, $path) {
        $this->plugin = $plugin;
        $this->app = $app;
        $this->bridge = $bridge;

        $this->loginsPath = $this->plugin->accountPath . $this->plugin->getGrav()['config']->get('plugins.akso-bridge.account_logins_path');
        $this->editPath = $this->plugin->accountPath . '?' . self::QUERY_EDIT;
        $this->cancelRequestPath = $this->plugin->accountPath . '?' . self::QUERY_CANCEL_REQUEST;
        $this->editPicturePath = $this->plugin->accountPath . '?' . self::QUERY_EDIT_PICTURE;
        if ($path === $this->plugin->accountPath) {
            $this->page = 'account';

            if (isset($_GET[self::QUERY_EDIT])) {
                $this->editing = true;
            }

            if (isset($_GET[self::QUERY_CANCEL_REQUEST])) {
                $this->page = 'cancel_change_request';
            } else if (isset($_GET[self::QUERY_EDIT_PICTURE])) {
                $this->page = 'edit_picture';
            }
        } else if ($path === $this->loginsPath) {
            $this->page = 'logins';
        }

        $this->doc = new \DOMDocument();
    }

    private $cPendingRequest = null;
    private function getPendingRequest() {
        if ($this->cPendingRequest) return $this->cPendingRequest;
        $res = $this->app->bridge->get('codeholders/change_requests', array(
            'filter' => array(
                'codeholderId' => $this->plugin->aksoUser['id'],
                'status' => 'pending',
            ),
            'fields' => ['id', 'time', 'codeholderDescription', 'data'],
            'order' => [['time', 'desc']],
            'limit' => 1,
        ));

        if ($res['k'] && count($res['b'])) {
            $item = $res['b'][0];
            $this->cPendingRequest = $item;
            return $item;
        }
        return null;
    }

    // gets data from pending change requests
    private function getPendingDetails() {
        $req = $this->getPendingRequest();
        if ($req) return $req['data'];
        return null;
    }

    private function renderCodeholderFields(&$details) {
        if ($details['profilePictureHash']) {
            $path = $this->plugin->getGrav()['uri']->path() . '?' . self::QUERY_PROFILE_PICTURE
                . '=1&s=';
            $details['profilePicturePath'] = $path . '128px';
            $details['profilePictureSizes'] = $path . '32px 32w,' . $path . '64px 64w,'
                . $path . '128px 128w,' . $path . '256px 256w,' . $path . '512px 512w';
        }

        if ($details['codeholderType'] === 'human') {
            $details['fmtName'] = $this->plugin->aksoUserFormattedName;
            if ($details['firstName'] || $details['lastName']) {
                $details['fmtLegalName'] = $details['firstNameLegal'] . ' ' . $details['lastNameLegal'];
            }

            $details['fmtBirthdate'] = '—';
            if ($details['birthdate']) {
                $details['fmtBirthdate'] = Utils::formatDate($details['birthdate']);
            }
        } else {
            $details['fmtName'] = $details['fullName'];
            if ($details['nameAbbrev']) {
                $details['fmtName'] .= ' (' . $details['nameAbbrev'] . ')';
            }
            $details['fmtLocalName'] = $details['fullNameLocal'];
        }

        $phoneNumbers = [];
        if ($details['codeholderType'] === 'human' && $details['cellphoneFormatted']) {
            $phoneNumbers[] = [$this->plugin->locale['account']['phoneNumberCell'], $details['cellphoneFormatted']];
        }
        if ($details['codeholderType'] === 'human' && $details['landlinePhoneFormatted']) {
            $phoneNumbers[] = [$this->plugin->locale['account']['phoneNumberLandline'], $details['landlinePhoneFormatted']];
        }
        if ($details['officePhoneFormatted']) {
            $phoneNumbers[] = [$this->plugin->locale['account']['phoneNumberOffice'], $details['officePhoneFormatted']];
        }
        if (!empty($phoneNumbers)) {
            $phoneNumbersList = $this->doc->createElement('ul');
            $phoneNumbersList->setAttribute('class', 'phone-numbers-list');
            foreach ($phoneNumbers as $entry) {
                $li = $this->doc->createElement('li');
                $label = $this->doc->createElement('span');
                $label->setAttribute('class', 'number-label');
                $label->textContent = $entry[0] . ': ';
                $li->appendChild($label);
                $value = $this->doc->createElement('span');
                $value->setAttribute('class', 'number-value');
                $value->textContent = $entry[1];
                $li->appendChild($value);
                $phoneNumbersList->appendChild($li);
            }
            $details['phoneNumbersFormatted'] = $this->doc->saveHtml($phoneNumbersList);
        } else $details['phoneNumbersFormatted'] = '—';

        $details['fmtAddress'] = '—';
        if ($details['address']) {
            $addr = $details['address'];
            $fmtAddress = $this->doc->createElement('div');
            $countryName = $this->formatCountry($addr['country']);
            $formatted = $this->bridge->renderAddress(array(
                'countryCode' => $addr['country'],
                'countryArea' => $addr['countryArea'],
                'city' => $addr['city'],
                'cityArea' => $addr['cityArea'],
                'streetAddress' => $addr['streetAddress'],
                'postalCode' => $addr['postalCode'],
                'sortingCode' => $addr['sortingCode'],
            ), $countryName)['c'];
            foreach (explode("\n", $formatted) as $line) {
                $ln = $this->doc->createElement('div');
                $ln->textContent = $line;
                $fmtAddress->appendChild($ln);
            }
            $details['fmtAddress'] = $this->doc->saveHtml($fmtAddress);
        }

        $details['fmtFeeCountry'] = '—';
        if ($details['feeCountry']) {
            $details['fmtFeeCountry'] = $this->formatCountry($details['feeCountry']);
        }
    }

    // Renders the codeholders/self details section
    private function renderDetails($includePending = false) {
        $res = $this->bridge->get('codeholders/self', array(
            'fields' => [
                'codeholderType',
                'firstName',
                'lastName',
                'firstNameLegal',
                'lastNameLegal',
                'honorific',
                'fullName',
                'fullNameLocal',
                'nameAbbrev',
                'careOf',
                'newCode',
                'oldCode',
                'birthdate',
                'address.country',
                'address.countryArea',
                'address.city',
                'address.cityArea',
                'address.streetAddress',
                'address.postalCode',
                'address.sortingCode',
                'feeCountry',
                'email',
                'officePhone',
                'cellphone',
                'landlinePhone',
                'officePhoneFormatted',
                'cellphoneFormatted',
                'landlinePhoneFormatted',

                'profilePictureHash',
                'profession',
                'website',
                'biography',
                // 'mainDescriptor', FIXME causes issues on API side
            ],
        ));

        if ($res['k']) {
            $this->plugin->updateFormattedName();
            $details = $res['b'];

            if ($includePending) {
                $pending = $this->getPendingDetails();
                if ($pending) $details = array_merge($details, $pending);
            }

            $this->renderCodeholderFields($details);

            return $details;
        }
        return null;
    }

    // Renders more membership items (as requested by JS)
    function renderMoreMembershipItems($offset) {
        $res = $this->bridge->get('/codeholders/self/membership', array(
            'fields' => ['categoryId', 'year', 'name', 'lifetime', 'availableTo'],
            'order' => [['year', 'desc']],
            'offset' => $offset,
            'limit' => 100,
        ));

        if ($res['k']) {
            $totalCount = $res['h']['x-total-items'];
            $hasMore = $totalCount > ($offset + count($res['b']));

            $currentYear = (int) (new \DateTime())->format('Y');
            foreach ($res['b'] as &$item) {
                $item['availableThisYear'] = $item['availableTo'] >= $currentYear;
                $item['availableNextYear'] = $item['availableTo'] >= $currentYear + 1;
                unset($item['availableTo']);
            }

            echo json_encode(array(
                'items' => $res['b'],
                'hasMore' => $hasMore,
            ));
        } else echo '!';
        die();
    }

    // Renders the membership section
    private function renderMembership() {
        $res = $this->bridge->get('/codeholders/self/membership', array(
            'fields' => ['categoryId', 'year', 'name', 'lifetime', 'availableTo'],
            'order' => [['year', 'desc']],
            'limit' => 10,
        ));

        if ($res['k']) {
            $categories = [];

            foreach ($res['b'] as $item) {
                $catId = $item['categoryId'];
                if (!isset($categories[$catId])) {
                    $categories[$catId] = $item;
                    $categories[$catId]['years'] = [];
                    $categories[$catId]['canBeRenewed'] = false;
                }
                $categories[$catId]['years'][] = $item['year'];
            }

            $currentYear = (int) (new \DateTime())->format('Y');
            foreach ($categories as &$category) {
                if ($category['lifetime']) continue;
                $newestYear = 0;
                foreach ($category['years'] as $y) if ($y > $newestYear) $newestYear = $y;

                $wouldRenewToYear = $newestYear == $currentYear ? $currentYear + 1 : $currentYear;
                $couldBeRenewed = $category['availableTo'] >= $wouldRenewToYear;

                if ($couldBeRenewed && $newestYear <= $currentYear) {
                    $category['canBeRenewed'] = true;
                }
            }

            $totalCount = $res['h']['x-total-items'];
            $hasMore = $totalCount > count($res['b']);

            return array(
                'categories' => $categories,
                'history' => $res['b'],
                'historyHasMore' => $hasMore,
            );
        }

        return null;
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

    // Streams the user’s profile picture and quits
    public function runProfilePicture() {
        $res = $this->bridge->get('codeholders/self', array('fields' => ['profilePictureHash']));
        if (!$res['k'] || !$res['b']['profilePictureHash'] || !isset($_GET['s'])) die();
        $hash = bin2hex($res['b']['profilePictureHash']);
        $size = $_GET['s'];
        // TODO: check if this is safe
        $path = "/codeholders/self/profile_picture/$size";
        // hack: use noop as unique cache key for getRaw
        $res = $this->bridge->getRaw($path, 10, array('noop' => $hash));
        if ($res['k']) {
            header('Content-Type: ' . $res['h']['content-type']);
            try {
                readfile($res['ref']);
            } finally {
                $this->bridge->releaseRaw($path);
            }
            die();
        } else {
            throw new \Exception('Failed to load picture');
        }
    }

    function renderResetPassword() {
        $returnLink = $this->plugin->getGrav()['uri']->path();
        $link = $returnLink . '?' . $this->plugin->locale['account']['reset_password_path'];
        $submitLink = $link;
        $active = false;
        $state = 'none';

        if (isset($_GET[$this->plugin->locale['account']['reset_password_path']])) {
            $active = true;

            if (isset($_POST) && isset($_POST['reset_password'])) {
                // TODO: use a nonce

                $login = $this->plugin->aksoUser['uea'];
                $res = $this->bridge->forgotPassword($login);
                if ($res['k']) {
                    $state = 'success';
                } else {
                    $state = 'error';
                }
            }
        }

        return array(
            'active' => $active,
            'state' => $state,
            'link' => $link,
            'submit_link' => $submitLink,
            'return_link' => $returnLink,
        );
    }

    private function runCancelChgReq() {
        $state = 'none';
        $submitLink = $this->cancelRequestPath;

        if (isset($_POST['cancel_request']) && $_POST['cancel_request']) {
            $state = 'error';
            $req = $this->getPendingRequest();
            if ($req) {
                $id = $req['id'];
                $res = $this->app->bridge->patch("/codeholders/change_requests/$id", array(
                    'status' => 'canceled',
                ), [], []);
                if ($res['k']) $state = 'success';
            }
        }

        return array(
            'active' => true,
            'state' => $state,
            'submit_link' => $submitLink,
        );
    }

    private function getLastLogins() {
        $res = $this->bridge->get('/codeholders/self/logins', array(
            'fields' => ['time', 'timezone', 'ip', 'userAgentParsed', 'userAgent', 'll', 'area', 'country', 'region', 'city'],
            'order' => [['time', 'desc']],
            'limit' => 100,
        ));

        if ($res['k']) {
            return $res['b'];
        }
        return null;
    }

    function getOwnFieldAsks() {
        $res = $this->bridge->get('/perms', []);
        if (!$res['k']) {
            return [];
        }
        $fields = [];
        foreach ($res['b']['ownMemberFields'] as $field => $perms) {
            if (strpos($perms, 'a') !== false) {
                $fields[$field] = true;
            }
        }
        return $fields;
    }

    function applyProfileEdits($input) {
        if (!isset($input['codeholder'])) throw new \Exception('No codeholder data in input');
        $ch = $input['codeholder'];
        $codeholder = Registration::readCodeholderStateSafe($this->bridge, $ch);
        // we'll take the user's word for it about whether they're an org. AKSO API will deal with it later if it's wrong
        $isOrg = isset($ch['codeholderType']) && $ch['codeholderType'] === 'org';
        if (isset($ch['mainDescriptor']) && gettype($ch['mainDescriptor']) === 'string') $codeholder['mainDescriptor'] = $ch['mainDescriptor'] ?: null;
        if (isset($ch['profession']) && gettype($ch['profession']) === 'string') $codeholder['profession'] = $ch['profession'] ?: null;
        if (isset($ch['website']) && gettype($ch['website']) === 'string') $codeholder['website'] = $ch['website'] ?: null;
        if (isset($ch['biography']) && gettype($ch['biography']) === 'string') $codeholder['biography'] = $ch['biography'] ?: null;

        $commitDesc = null;
        if (isset($input['commit_desc']) && gettype($input['commit_desc']) === 'string') {
            $commitDesc = $input['commit_desc'] ?: null;
        }

        $error = Registration::getCodeholderError($this->bridge, $this->plugin->locale, $codeholder, $isOrg);

        if (!$error) {
            $reqOptions = [];
            if ($commitDesc) $reqOptions['modDesc'] = $commitDesc;
            $res = $this->bridge->patch('/codeholders/self', $codeholder, $reqOptions, []);
            if ($res['k']) {
                $this->plugin->getGrav()->redirectLangSafe($this->plugin->accountPath, 303);
                die();
            }

            $error = $res['sc'] == 400
                ? $this->plugin->locale['account']['edit_error_bad_request']
                : $this->plugin->locale['account']['edit_error_unknown'];
            var_dump($codeholder, $res['b']);
        }

        return array(
            'pending_request' => $this->getPendingRequest(),
            'own_field_asks' => $this->getOwnFieldAsks(),
            'account_link' => $this->plugin->accountPath,
            'cancel_request_link' => $this->cancelRequestPath,
            'codeholder' => $codeholder,
            'commit_desc' => $commitDesc,
            'countries' => $this->getCountries(),
            'editing' => true,
            'error' => $error,
        );
    }

    function applyPictureAction($input) {
        $action = 'upload';
        if (isset($input['action']) && $input['action'] === 'delete') $action = 'delete';

        $error = null;
        $message = null;
        if ($action === 'upload') {
            $fileErr = isset($_FILES['picture']) ? $_FILES['picture']['error'] : -1;
            $fileSize = isset($_FILES['picture']) ? $_FILES['picture']['size'] : -1;
            if ($fileErr === UPLOAD_ERR_INI_SIZE || $fileErr === UPLOAD_ERR_FORM_SIZE || $fileSize > 2097152) {
                $error = $this->plugin->locale['account']['upload_pfp_error_too_big'];
            } else if (!isset($_FILES['picture']) || $_FILES['picture']['error'] !== UPLOAD_ERR_OK) {
                $error = $this->plugin->locale['account']['upload_pfp_error_bad_request'];
            } else {
                $file = file_get_contents($_FILES['picture']['tmp_name']);
                $res = $this->bridge->put('/codeholders/self/profile_picture', null, [], array(
                    'picture' => array(
                        't' => $_FILES['picture']['type'],
                        'b' => base64_encode($file),
                    ),
                ));

                if ($res['k']) {
                    $message = $this->plugin->locale['account']['upload_pfp_success'];
                } else if ($res['sc'] === 400) {
                    $error = $this->plugin->locale['account']['upload_pfp_error_bad_request'];
                } else {
                    $error = $this->plugin->locale['account']['upload_pfp_error_unknown'];
                }
            }
        } else if ($action === 'delete') {
            $res = $this->bridge->delete('/codeholders/self/profile_picture', [], [], []);
            if ($res['k']) {
                $message = $this->plugin->locale['account']['delete_pfp_success'];
            } else if ($res['sc'] === 404) {
                $error = $this->plugin->locale['account']['delete_pfp_error_not_found'];
            } else {
                $error = $this->plugin->locale['account']['delete_pfp_error'];
            }
        }

        $details = $this->renderDetails();
        return array(
            'editing_picture' => true,
            'account_link' => $this->plugin->accountPath,
            'details' => $details,
            'error' => $error,
            'message' => $message,
        );
    }

    public function run() {
        if ($this->editing && $_SERVER['REQUEST_METHOD'] === 'POST') {
            return $this->applyProfileEdits($_POST);
        }
        if ($this->page === 'edit_picture' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            return $this->applyPictureAction($_POST);
        }

        if (isset($_GET[self::QUERY_PROFILE_PICTURE])) {
            $this->runProfilePicture();
        }

        if (isset($_GET['membership_more_items_offset']) && gettype($_GET['membership_more_items_offset']) === 'string') {
            $offset = (int) $_GET['membership_more_items_offset'];
            $this->renderMoreMembershipItems($offset);
        }

        if ($this->page === 'account') {
            if ($this->editing) {
                return array(
                    'pending_request' => $this->getPendingRequest(),
                    'own_field_asks' => $this->getOwnFieldAsks(),
                    'account_link' => $this->plugin->accountPath,
                    'cancel_request_link' => $this->cancelRequestPath,
                    'codeholder' => $this->renderDetails(true),
                    'countries' => $this->getCountries(),
                    'editing' => $this->editing,
                );
            }

            $details = $this->renderDetails();
            $membership = $this->renderMembership();
            $resetPassword = $this->renderResetPassword();
            $pendingReq = $this->getPendingRequest();
            $pendingDetails = null;
            if ($pendingReq) {
                $newDetails = array_merge([], $details, $pendingReq['data']);
                $this->renderCodeholderFields($newDetails);
                // TODO: proper diff array according to the actual changed fields instead of this heuristic
                $pendingDetails = [];
                foreach ($newDetails as $key => $value) {
                    if ($newDetails[$key] != $details[$key]) {
                        $pendingDetails[$key] = $newDetails[$key];
                    }
                }
            }

            return array(
                'pending_request' => $pendingReq,
                'pending_details' => $pendingDetails,
                'details' => $details,
                'membership' => $membership,
                'reset_password' => $resetPassword,
                'logins_link' => $this->loginsPath,
                'editing' => $this->editing,
                'edit_link' => $this->editPath,
                'cancel_request_link' => $this->cancelRequestPath,
                'edit_picture_link' => $this->editPicturePath,
                'registration_link' => $this->plugin->registrationPath
            );
        } else if ($this->page === 'logins') {
            $countries = [];
            foreach ($this->getCountries() as $country) {
                $countries[$country['code']] = $country['name_eo'];
            }

            return array(
                'logins' => $this->getLastLogins(),
                'countries' => $countries,
                'return_link' => $this->plugin->accountPath,
            );
        } else if ($this->page === 'cancel_change_request') {
            return array(
                'pending_request' => $this->getPendingRequest(),
                'account_link' => $this->plugin->accountPath,
                'cancel_chgreq' => $this->runCancelChgReq(),
            );
        } else if ($this->page === 'edit_picture') {
            $details = $this->renderDetails();
            return array(
                'editing_picture' => true,
                'account_link' => $this->plugin->accountPath,
                'details' => $details,
            );
        }
    }
}
