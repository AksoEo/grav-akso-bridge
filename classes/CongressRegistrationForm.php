<?php
namespace Grav\Plugin\AksoBridge;

// ASC script exec context
class CRFScriptExecCtx {
    private $app;
    public $scriptStack = [];
    public $formVars = [];

    public function __construct($app) {
        $this->app = $app;
    }

    public function pushScript($script) {
        $this->scriptStack[] = $script;
    }
    public function setFormVar($name, $value) {
        $this->formVars[$name] = $value;
    }

    public function eval($expr) {
        return $this->app->bridge->evalScript($this->scriptStack, $this->formVars, $expr);
    }
}

// Handles the congress registration form.
class CongressRegistrationForm {
    // All form fields will be put into this variable inside $POST (e.g. form_data[name] for a
    // field called "name").
    const DATA_VAR_NAME = 'form_data';

    const AUTOFILLABLE_API_FIELDS = [
        "id", "codeholderType", // used to check eligibility
        "birthdate", "email", "officePhone", "cellphone", "landlinePhone", "website",
        "profession", "feeCountry", "address.country", "address.countryArea",
        "address.city", "address.cityArea", "address.streetAddress", "address.postalCode",
        "address.sortingCode", "firstNameLegal", "lastNameLegal", "firstName", "lastName",
        "honorific"
    ];

    private $app;
    private $plugin;
    private $form;
    private $currency;
    private $doc;
    private $parsedown;
    private $locale;
    private $congressId;
    private $instanceId;
    public function __construct($plugin, $app, $form, $congressId, $instanceId, $currency) {
        $this->app = $app;
        $this->plugin = $plugin;
        $this->form = $form;
        $this->congressId = $congressId;
        $this->instanceId = $instanceId;
        $this->currency = $currency;
        $this->doc = new \DOMDocument();
        $this->parsedown = new \Parsedown();
        $this->locale = $plugin->locale['registration_form'];
        // $this->parsedown->setSafeMode(true); // this does not work
    }

    // This field will be set after a new registration is successfully created, and contain
    // the registration dataId.
    public $confirmDataId = null;

    // If this is set, then we're editing a registration instead of creating one.
    private $dataId = null;
    // User data in API format
    private $data = null;
    // Participant data
    private $participant = null;

    // List of errors. Map<field name (string), string>
    private $errors = [];
    // Top-level error
    private $error = null;
    // Top-level message
    private $message = null;

    // Sets user data from API data.
    public function setParticipant($dataId, $apiData) {
        $this->dataId = $dataId;
        $this->participant = $apiData;
        if (isset($apiData['data'])) {
            $this->data = $apiData['data'];
        }
    }

    /// Reads input field data from POST.
    /// Only validates types.
    function readInputFieldFromPost($item, $data) {
        $ty = $item['type'];
        $out = null;

        if ($ty === 'boolean') $out = (bool) $data;
        else if ($ty === 'number') $out = $data === "" ? null : floatval($data);
        else if ($ty === 'text') $out = $data === "" ? null : strval($data);
        else if ($ty === 'money') {
            $currencies = $this->app->bridge->currencies();
            $multiplier = $currencies[$item['currency']];
            $out = $data === "" ? null : floor(floatval($data) * $multiplier);
        } else if ($ty === 'enum') $out = $data === "" ? null : strval($data);
        else if ($ty === 'country') $out = $data === "" ? null : strval($data);
        else if ($ty === 'date') $out = $data === "" ? null : strval($data);
        else if ($ty === 'time') $out = $data === "" ? null : strval($data);
        else if ($ty === 'datetime') {
            if ($data !== "") {
                $tz = 'UTC';
                if ($item['tz']) $tz = $item['tz'];
                try {
                    $tz = new \DateTimeZone($tz);
                } catch (\Exception $e) {
                    $tz = new \DateTimeZone('UTC');
                }
                $dtFormat = 'Y-m-d\\TH:i';
                $date = \DateTime::createFromFormat($dtFormat, strval($data), $tz);
                if ($date !== false) {
                    $out = $date->getTimestamp();
                } else {
                    $this->errors[$item['name']] = $this->localize('err_datetime_fmt');
                }
            }
        } else if ($ty === 'boolean_table') {
            $excludedCells = [];
            if ($item['excludeCells'] !== null) {
                foreach ($item['excludeCells'] as $xy) {
                    $excludedCells []= $xy[0] . '-' . $xy[1];
                }
            }

            $out = [];
            for ($i = 0; $i < $item['rows']; $i++) {
                $row = [];
                for ($j = 0; $j < $item['cols']; $j++) {
                    $isExcluded = in_array($j . '-' . $i, $excludedCells);
                    $cellValue = $isExcluded ? null : false;
                    if (!$isExcluded) {
                        if (isset($data[$j]) && isset($data[$j][$i])) {
                            $cellValue = (bool) $data[$j][$i];
                        }
                    }
                    $row[] = $cellValue;
                }
                $out[] = $row;
            }
        }

        return $out;
    }

    function loadPostData($data) {
        $existingData = false;
        if (!$this->data) $this->data = [];
        else $existingData = true;

        foreach ($this->form as $item) {
            if ($item['el'] === 'input') {
                $name = $item['name'];

                if (!$existingData || $item['editable']) {
                    $fieldData = isset($this->data[$name]) ? $this->data[$name] : null;
                    // TODO: set fieldData to null if the user did mean to send the field, but it was
                    // not present due to the browser not sending unchecked checkboxes

                    if (isset($data[$name])) {
                        $fieldData = $data[$name];
                    }

                    $res = $this->readInputFieldFromPost($item, $fieldData);
                    $this->data[$name] = $res;
                }
            }
        }
    }

    function localize($key, ...$params) {
        if (count($params)) {
            $out = '';
            $i = 0;
            foreach ($params as $p) {
                $out .= $this->locale[$key . '_' . $i];
                $out .= $p;
                $i++;
            }
            $out .= $this->locale[$key . '_' . $i];
            return $out;
        }
        return $this->locale[$key];
    }

    function getFieldError($scriptCtx, $item, $value) {
        if (isset($this->errors[$item['name']])) {
            // error already exists (possibly from loadPostData)
            return $this->errors[$item['name']];
        }

        $ty = $item['type'];
        $req = $item['required'];

        if (gettype($req) !== 'boolean') {
            // script value
            $res = $scriptCtx->eval($req);
            if ($res['s']) {
                $req = $res['v'] === true;
            } else {
                // TODO: handle error?
            }
        }

        if ($req && $value === null) {
            // field is required!
            return $this->localize('err_field_is_required');
        }

        if ($value !== null) {
            if ($ty === 'boolean') {
                if ($req && !$value) {
                    // booleans are special: required means they must be true
                    return $this->localize('err_field_is_required');
                }
            } else if ($ty === 'number') {
                if ($item['step'] !== null) {
                    if ($value % $item['step'] !== 0) {
                        return $this->localize('err_number_step', $item['step']);
                    }
                }

                $fulfillsMin = true;
                $fulfillsMax = true;
                if ($item['min'] !== null) {
                    $fulfillsMin = $value >= $item['min'];
                }
                if ($item['max'] !== null) {
                    $fulfillsMax = $value <= $item['max'];
                }

                if ($item['min'] !== null && $item['max'] !== null) {
                    if (!$fulfillsMin || !$fulfillsMax) {
                        return $this->localize('err_number_range', $item['min'], $item['max']);
                    }
                } else if (!$fulfillsMin) {
                    return $this->localize('err_number_min', $item['min']);
                } else if (!$fulfillsMax) {
                    return $this->localize('err_number_max', $item['max']);
                }
            } else if ($ty === 'text') {
                if ($item['pattern'] !== null) {
                    $res = $this->app->bridge->matchRegExp($item['pattern'], $value);
                    if (!$res['m']) {
                        return $item['patternError'] ? $item['patternError'] : $this->localize('err_text_pattern_generic');
                    }
                }

                // Javascript uses UTF16
                $fulfillsMin = $item['minLength'] !== null ? mb_strlen($value, 'UTF-16') >= $item['minLength'] : true;
                $fulfillsMax = $item['maxLength'] !== null ? mb_strlen($value, 'UTF-16') >= $item['maxLength'] : true;

                if ($item['minLength'] !== null && $item['maxLength'] !== null) {
                    if (!$fulfillsMin || !$fulfillsMax) {
                        return $this->localize('err_text_len_range', $item['min'], $item['max']);
                    }
                } else if (!$fulfillsMin) {
                    return $this->localize('err_text_len_min', $item['min']);
                } else if (!$fulfillsMax) {
                    return $this->localize('err_text_len_max', $item['max']);
                }
            } else if ($ty === 'money') {
                if ($item['step'] !== null) {
                    if ($value % $item['step'] !== 0) {
                        return $this->localize('err_money_step', $item['step']);
                    }
                }

                $min = $item['min'] === null ? 0 : $item['min'];

                $fulfillsMin = $value >= $min;
                $fulfillsMax = ($item['max'] !== null) ? ($value <= $item['max']) : true;

                if ($item['max'] !== null && (!$fulfillsMin || !$fulfillsMax)) {
                    return $this->localize('err_money_range', $min, $item['max']);
                } else if (!$fulfillsMin) {
                    return $this->localize('err_money_min', $min);
                }
            } else if ($ty === 'enum') {
                $found = false;
                foreach ($item['options'] as $option) {
                    if ($option['value'] === $value) {
                        $found = true;
                        break;
                    }
                }

                if (!$found) return $this->localize('err_enum_not_in_set');
            } else if ($ty === 'country') {
                // nothing to validate here because all options presented in the UI are valid
            } else if ($ty === 'date') {
                $dateTime = \DateTime::createFromFormat('Y-m-d', $value);
                if ($dateTime === false) return $this->localize('err_date_fmt');

                $minDate = null;
                $maxDate = null;
                if ($item['min']) $minDate = \DateTime::createFromFormat('Y-m-d', $item['min']);
                if ($item['max']) $maxDate = \DateTime::createFromFormat('Y-m-d', $item['max']);

                $fulfillsMin = $minDate ? $dateTime >= $minDate : true;
                $fulfillsMax = $maxDate ? $dateTime <= $maxDate : true;

                if ($minDate && $maxDate) {
                    if (!$fulfillsMin || !$fulfillsMax) {
                        return $this->localize('err_datetime_range', Utils::formatDate($minDate), Utils::formatDate($maxDate));
                    }
                } else if (!$fulfillsMin) {
                    return $this->localize('err_datetime_min', Utils::formatDate($minDate));
                } else if (!$fulfillsMax) {
                    return $this->localize('err_datetime_max', Utils::formatDate($maxDate));
                }
            } else if ($ty === 'time') {
                $dateTime = \DateTime::createFromFormat('H:i', $value);
                if ($dateTime === false) return $this->localize('err_time_fmt');

                $timeMins = $dateTime->format('H') * 60 + $dateTime->format('i');
                $minMins = 0;
                $maxMins = 1440;

                if ($item['min']) {
                    $minMins = \DateTime::createFromFormat('H:i', $item['min']);
                    $minMins = $minMins->format('H') * 60 + $minMins->format('i');
                }
                if ($item['max']) {
                    $maxMins = \DateTime::createFromFormat('H:i', $item['max']);
                    $maxMins = $maxMins->format('H') * 60 + $maxMins->format('i');
                }


                if ($item['min'] && $item['max']) {
                    if ($minMins > $timeMins || $maxMins < $timeMins) {
                        return $this->localize('err_datetime_range', $item['min'], $item['max']);
                    }
                } else if ($minMins > $timeMins) {
                    return $this->localize('err_datetime_min', $item['min']);
                } else if ($maxMins < $timeMins) {
                    return $this->localize('err_datetime_max', $item['max']);
                }
            } else if ($ty === 'datetime') {
                $dateTime = new \DateTime("@$value");
                if ($dateTime === false) return $this->localize('err_datetime_fmt');

                $fulfillsMin = $item['min'] ? $item['min'] <= $value : true;
                $fulfillsMax = $item['max'] ? $item['max'] >= $value : true;

                if ($item['min'] && $item['max']) {
                    if (!$fulfillsMin || !$fulfillsMax) {
                        $minTime = $item['min'];
                        $maxTime = $item['max'];
                        $minTime = Utils::formatDate(new \DateTime("@$minTime"));
                        $maxTime = Utils::formatDate(new \DateTime("@$maxTime"));
                        return $this->localize('err_datetime_range', $minTime, $maxTime);
                    }
                } else if (!$fulfillsMin) {
                    $minTime = $item['min'];
                    $minTime = Utils::formatDate(new \DateTime("@$minTime"));
                    return $this->localize('err_datetime_min', $minTime);
                } else if (!$fulfillsMax) {
                    $maxTime = $item['max'];
                    $maxTime = Utils::formatDate(new \DateTime("@$maxTime"));
                    return $this->localize('err_datetime_max', $maxTime);
                }
            } else if ($ty === 'boolean_table') {
                $selected = 0;
                for ($i = 0; $i < $item['rows']; $i++) {
                    for ($j = 0; $j < $item['cols']; $j++) {
                        if ($value[$i][$j]) $selected++;
                    }
                }

                $fulfillsMin = $item['minSelect'] !== null ? $selected >= $item['minSelect'] : true;
                $fulfillsMax = $item['maxSelect'] !== null ? $selected <= $item['maxSelect'] : true;

                if ($item['minSelect'] !== null && $item['maxSelect'] !== null) {
                    if (!$fulfillsMin || !$fulfillsMax) {
                        return $this->localize('err_bool_table_select_range', $item['minSelect'], $item['maxSelect']);
                    }
                } else if (!$fulfillsMin) {
                    return $this->localize('err_bool_table_select_min', $item['minSelect']);
                } else if (!$fulfillsMax) {
                    return $this->localize('err_bool_table_select_max', $item['maxSelect']);
                }
            }
        }

        return null; // everything ok
    }

    // Validates form data.
    // Assumes every field exists in $this->data.
    function validateData() {
        if ($this->data === null) return;
        $ok = true;
        $scriptCtx = new CRFScriptExecCtx($this->app);

        foreach ($this->form as $item) {
            if ($item['el'] === 'input') {
                $value = $this->data[$item['name']];
                $fieldError = $this->getFieldError($scriptCtx, $item, $value);
                if ($fieldError) $ok = false;
                $this->errors[$item['name']] = $fieldError;

                $scriptCtx->setFormVar($item['name'], $value);
            } else if ($item['el'] === 'script') {
                $scriptCtx->pushScript($item['script']);
            }
        }

        return $ok;
    }

    private $didSubmit = false;
    private $submitResult = null;

    function submit() {
        $args = array('data' => $this->data);
        $ch = $this->getCodeholder();
        if ($ch && $ch['codeholderType'] === 'human') {
            $args['codeholderId'] = $ch['id'];
        }
        $this->didSubmit = true;

        if ($this->dataId) {
            // PATCH
            $res = $this->app->bridge->patch('/congresses/' . $this->congressId . '/instances/' . $this->instanceId . '/participants/' . $this->dataId, $args, [], []);
            if ($res['k']) {
                $this->message = $this->localize('msg_patch_success');
            } else if ($res['sc'] === 400) {
                $this->error = $this->localize('err_submit_invalid');
            } else {
                $this->error = $this->localize('err_submit_generic');
            }
        } else {
            // new registration
            $res = $this->app->bridge->post('/congresses/' . $this->congressId . '/instances/' . $this->instanceId . '/participants', $args, [], []);
            if ($res['k']) {
                $this->confirmDataId = $res['h']['x-identifier'];
            } else if ($res['sc'] === 400) {
                $this->error = $this->localize('err_submit_invalid');
            } else if ($res['sc'] === 409) {
                $this->error = $this->localize('err_submit_already_registered');
            } else {
                $this->error = $this->localize('err_submit_generic');
            }
        }
    }

    public function validate($post, $showValidMessage = false) {
        if (isset($post[self::DATA_VAR_NAME])) {
            $this->loadPostData($post[self::DATA_VAR_NAME]);
            $isOk = $this->validateData();
            if (!$isOk) {
                $this->error = $this->localize('err_submit_invalid');
            } else if ($showValidMessage) {
                $this->message = $this->localize('validate_valid');
            }
            return $isOk;
        } else {
            // no data, pretend nothing was sent (do nothing)
            return false;
        }
    }

    // Attempts to submit the form with the given POST data.
    public function trySubmit($post) {
        if ($this->validate($post)) {
            $this->submit();
        }
    }

    public $cancelSucceeded = false;

    /// Attempts to cancel the form.
    public function cancel() {
        $canceledTime = time();
        $patchData = array(
            'cancelledTime' => $canceledTime
        );
        $res = $this->app->bridge->patch('/congresses/' . $this->congressId . '/instances/' . $this->instanceId . '/participants/' . $this->dataId, $patchData, [], []);
        if ($res['k']) {
            return $canceledTime;
        } else {
            return null;
        }
    }

    function renderTop() {
        $err = $this->doc->createElement('div');
        $err->setAttribute('class', 'registration-error');

        if ($this->error) {
            $err->textContent = $this->error;
            return $err;
        }

        $msg = $this->doc->createElement('div');
        $msg->setAttribute('class', 'registration-message');

        if ($this->message) {
            $msg->textContent = $this->message;
            return $msg;
        }

        return null;
    }

    function ascCastToString($value) {
        if (gettype($value) === 'array') {
            return implode('', $value);
        }
        return (string) $value;
    }

    // returns list of akso countries and their name_eo
    private $cachedCountries = null;
    function getCountries() {
        if (!$this->cachedCountries) {
            $res = $this->app->bridge->get('/countries', array(
                'limit' => 300,
                'fields' => ['code', 'name_eo'],
                'order' => [['name_eo', 'asc']],
            ), 600);
            if ($res['k']) {
                $this->cachedCountries = $res['b'];
            } else {
                // TODO: handle failure better
                throw new Exception('Failed to load countries');
            }
        }
        return $this->cachedCountries;
    }

    private $cachedCodeholder = null;
    function getCodeholder() {
        if (!$this->cachedCodeholder) {
            if (!$this->plugin->aksoUser) return null;
            $res = $this->app->bridge->get('/codeholders/' . $this->plugin->aksoUser['id'], array(
                'fields' => self::AUTOFILLABLE_API_FIELDS,
            ));
            if ($res['k']) {
                $this->cachedCodeholder = $res['b'];
            } else {
                // TODO: handle failure
                // currently, do nothing
            }
        }
        return $this->cachedCodeholder;
    }

    private $cachedCodeholderAddress = null;
    function getCodeholderAddress() {
        if (!$this->cachedCodeholderAddress) {
            if (!$this->plugin->aksoUser) return null;
            $res = $this->app->bridge->get('/codeholders/' . $this->plugin->aksoUser['id'] . '/address/eo', []);
            if ($res['k']) {
                $this->cachedCodeholderAddress = $res['b'][$this->plugin->aksoUser['id']];
            } else {
                // TODO: handle failure
                // currently, do nothing
            }
        }
        return $this->cachedCodeholderAddress;
    }

    function chAutofill($field) {
        $ch = $this->getCodeholder();
        if (!$ch || $ch['codeholderType'] !== 'human') return null;

        if ($field === 'name') {
            // format name
            $name = '';
            if (isset($ch['honorific'])) $name .= $data['honorific'] . ' ';
            if (isset($ch['firstName'])) $name .= $data['firstName'] . ' ';
            else $name .= $data['firstNameLegal'] . ' ';
            if (isset($ch['lastName'])) $name .= $data['lastName'];
            else if (isset($ch['lastNameLegal'])) $name .= $data['lastNameLegal'];
            $name = trim($str);
            return $name;
        } else if ($field === 'country' || $field === 'countryArea' || $field === 'city' ||
            $field === 'cityArea' || $field === 'streetAddress' || $field === 'postalCode' ||
            $field === 'sortingCode') {
            if ($ch['address']) return $ch['address'][$field];
            return null;
        } else if ($field === 'address') {
            return $this->getCodeholderAddress();
        } else if ($field === 'phone') {
            if ($ch['cellphone']) return $ch['cellphone'];
            if ($ch['landlinePhone']) return $ch['landlinePhone'];
            if ($ch['officePhone']) return $ch['officePhone'];
            return null;
        }
        if ($ch[$field]) return $ch[$field];
        return null;
    }

    function renderInputItem($scriptCtx, $item) {
        $root = $this->doc->createElement('div');
        $root->setAttribute('class', 'form-field form-item form-input-item');
        $root->setAttribute('data-name', $item['name']);
        $root->setAttribute('data-el', 'input');
        $root->setAttribute('data-type', $item['type']);

        $data = $this->doc->createElement('div');
        $data->setAttribute('class', 'form-data');

        $ty = $item['type'];
        $name = self::DATA_VAR_NAME . '[' . $item['name'] . ']';
        $inputId = 'form-' . $item['name'];

        $value = null;
        if ($this->data && isset($this->data[$item['name']])) $value = $this->data[$item['name']];
        else if ($item['default'] !== null) {
            if (gettype($item['default']) === 'array') {
                $root->setAttribute('data-script-default', base64_encode(json_encode($item['default'])));

                $res = $scriptCtx->eval($item['default']);
                if ($res['s']) {
                    $value = $res['v'];
                }
            } else {
                $value = $item['default'];
            }
        } else if (isset($item['chAutofill'])) {
            $value = $this->chAutofill($item['chAutofill']);
        }

        $label = $this->doc->createElement('label');
        $label->textContent = $item['label'];
        $label->setAttribute('for', $inputId);

        $description = null;
        if ($item['description']) {
            $description = $this->doc->createElement('p');
            $this->setInnerHTML($description, $this->parsedown->text($item['description']));
        }

        // only add 'required' server-side if it's guaranteed to be true
        $required = $item['required'] === true;
        if ($required) {
            $root->setAttribute('data-required', 'true');
            $req = $this->doc->createElement('span');
            $req->setAttribute('class', 'label-required');
            $req->textContent = ' *';
            $label->appendChild($req);
        }
        if (gettype($item['required']) === 'array') {
            $root->setAttribute('data-script-required', base64_encode(json_encode($item['required'])));
        }

        $disabled = $item['disabled'] === true;
        if (gettype($item['disabled']) === 'array') {
            $root->setAttribute('data-script-disabled', base64_encode(json_encode($item['disabled'])));
        }

        if ($this->dataId && !$item['editable']) {
            // we're editing a registration, but this field can't be edited
            $disabled = true;
        }

        $scriptCtx->setFormVar($item['name'], $value);

        if ($ty === 'boolean') {
            $label->setAttribute('class', 'form-label is-boolean-label');
            $input = $this->doc->createElement('input');
            $input->setAttribute('id', $inputId);
            $input->setAttribute('name', $name);
            $input->setAttribute('type', 'checkbox');
            if ($value) $input->setAttribute('checked', '');
            if ($disabled) $input->setAttribute('disabled', '');

            $root->appendChild($input);
            $root->appendChild($label);
            if ($description) $root->appendChild($description);
        } else {
            $labelContainer = $this->doc->createElement('div');
            $labelContainer->setAttribute('class', 'form-label');
            $labelContainer->appendChild($label);
            $root->appendChild($labelContainer);
            if ($description) $root->appendChild($description);
            $root->appendChild($data);
        }

        if (isset($this->errors[$item['name']])) {
            $fieldError = $this->errors[$item['name']];
            $err = $this->doc->createElement('div');
            $err->setAttribute('class', 'field-error');
            $err->textContent = $fieldError;
            $root->appendChild($err);
        }

        if ($ty === 'number') {
            $input = $this->doc->createElement('input');
            $input->setAttribute('id', $inputId);
            $input->setAttribute('name', $name);
            if ($item['placeholder'] !== null) $input->setAttribute('placeholder', $item['placeholder']);
            if ($item['min'] !== null) $input->setAttribute('min', $item['min']);
            if ($item['step'] !== null) $input->setAttribute('step', $item['step']);
            if ($item['max'] !== null) $input->setAttribute('max', $item['max']);
            if ($item['variant'] === 'slider') {
                $input->setAttribute('type', 'range');
            } else {
                $input->setAttribute('type', 'number');
            }
            if ($disabled) $input->setAttribute('disabled', '');
            if ($value !== null) $input->setAttribute('value', $this->ascCastToString($value));
            $data->appendChild($input);
        } else if ($ty === 'text') {
            $input = null;
            if ($item['variant'] === 'textarea') $input = $this->doc->createElement('textarea');
            else $input = $this->doc->createElement('input');
            $input->setAttribute('id', $inputId);
            $input->setAttribute('name', $name);
            if ($item['variant'] === 'uri') $input->setAttribute('type', 'url');
            else if ($item['variant'] !== 'textarea') $input->setAttribute('type', $item['variant']);
            if ($item['placeholder'] !== null) $input->setAttribute('placeholder', $item['placeholder']);
            if ($item['pattern'] !== null) $input->setAttribute('pattern', $item['pattern']);
            if ($item['patternError'] !== null) $input->setAttribute('data-pattern-error', $item['patternError']);
            if ($item['minLength'] !== null) $input->setAttribute('minLength', $item['minLength']);
            if ($item['maxLength'] !== null) $input->setAttribute('maxLength', $item['maxLength']);
            if ($value !== null) {
                if ($item['variant'] === 'textarea') $input->textContent = $this->ascCastToString($value);
                else $input->setAttribute('value', $this->ascCastToString($value));
            }
            if ($disabled) $input->setAttribute('disabled', '');
            $data->appendChild($input);
        } else if ($ty === 'money') {
            $container = $this->doc->createElement('div');
            $container->setAttribute('class', 'money-input');

            $currencies = $this->app->bridge->currencies();
            $multiplier = $currencies[$item['currency']];

            $input = $this->doc->createElement('input');
            $input->setAttribute('id', $inputId);
            $input->setAttribute('name', $name);
            $input->setAttribute('type', 'number');
            $input->setAttribute('data-currency-multiplier', $multiplier);
            $input->setAttribute('data-currency', $item['currency']);
            if ($item['placeholder'] !== null) $input->setAttribute('placeholder', $item['placeholder']);
            if ($item['min'] !== null) $input->setAttribute('min', $item['min'] / $multiplier);
            if ($item['step'] !== null) $input->setAttribute('step', $item['step'] / $multiplier);
            else $input->setAttribute('step', 1 / $multiplier);
            if ($item['max'] !== null) $input->setAttribute('max', $item['max'] / $multiplier);
            if ($disabled) $input->setAttribute('disabled', '');
            if ($value !== null) $input->setAttribute('value', $this->ascCastToString($value) / $multiplier);
            $container->appendChild($input);

            $currLabel = $this->doc->createElement('span');
            $currLabel->setAttribute('class', 'currency-label');
            $currLabel->textContent = $item['currency'];
            $container->appendChild($currLabel);

            $data->appendChild($container);
        } else if ($ty === 'enum') {
            $root->setAttribute('data-variant', $item['variant']);
            if ($item['variant'] === 'select') {
                $input = $this->doc->createElement('select');
                $input->setAttribute('id', $inputId);
                $input->setAttribute('name', $name);
                if ($disabled) $input->setAttribute('disabled', '');

                if (!$required) {
                    $node = $this->doc->createElement('option');
                    $node->setAttribute('class', 'null-option');
                    $node->setAttribute('value', '');
                    $node->textContent = '—';
                    $input->appendChild($node);
                }

                foreach ($item['options'] as $option) {
                    $node = $this->doc->createElement('option');
                    $node->setAttribute('value', $option['value']);
                    $node->textContent = $option['name'];
                    if ($option['disabled']) {
                        // TODO: handle onlyExisting
                        $node->setAttribute('disabled', '');
                    }
                    if ($value === $option['value']) $node->setAttribute('selected', '');
                    $input->appendChild($node);
                }

                $data->appendChild($input);
            } else if ($item['variant'] === 'radio') {
                $root->setAttribute('data-radio-name', $name);
                $group = $this->doc->createElement('ul');
                $group->setAttribute('class', 'radio-group');
                $group->setAttribute('id', $inputId);

                foreach ($item['options'] as $option) {
                    $li = $this->doc->createElement('li');
                    // TODO: handle onlyExisting
                    if ($disabled || $option['disabled']) $li->setAttribute('class', 'is-disabled');

                    $radioId = $inputId . '--' . $option['value'];

                    $radio = $this->doc->createElement('input');
                    $radio->setAttribute('type', 'radio');
                    $radio->setAttribute('name', $name);
                    $radio->setAttribute('id', $radioId);
                    $radio->setAttribute('value', $option['value']);
                    if ($value === $option['value']) $radio->setAttribute('checked', '');
                    if ($option['disabled']) {
                        $radio->setAttribute('disabled', '');
                        $radio->setAttribute('data-disabled', 'true');
                    }
                    $li->appendChild($radio);

                    $rlabel = $this->doc->createElement('label');
                    $rlabel->setAttribute('for', $radioId);
                    $rlabel->textContent = $option['name'];
                    $li->appendChild($rlabel);

                    $group->appendChild($li);
                }

                $data->appendChild($group);
            }
        } else if ($ty === 'country') {
            $input = $this->doc->createElement('select');
            $input->setAttribute('id', $inputId);
            $input->setAttribute('name', $name);
            if ($disabled) $input->setAttribute('disabled', '');

            if (!$required) {
                $node = $this->doc->createElement('option');
                $node->setAttribute('class', 'null-option');
                $node->setAttribute('value', '');
                $node->textContent = '—';
                $input->appendChild($node);
            }

            $countries = $this->getCountries();
            foreach ($countries as $country) {
                if (in_array($country['code'], $item['exclude'])) {
                    // excluded
                    continue;
                }

                $opt = $this->doc->createElement('option');
                $opt->setAttribute('value', $country['code']);
                if ($value === $country['code']) $opt->setAttribute('selected', '');
                $opt->textContent = $country['name_eo'];
                $input->appendChild($opt);
            }

            $data->appendChild($input);
        } else if ($ty === 'date') {
            $input = $this->doc->createElement('input');
            $input->setAttribute('id', $inputId);
            $input->setAttribute('name', $name);
            $input->setAttribute('type', 'date');
            if ($disabled) $input->setAttribute('disabled', '');
            if ($item['min'] !== null) $input->setAttribute('min', $item['min']);
            if ($item['max'] !== null) $input->setAttribute('max', $item['max']);
            if ($value !== null) $input->setAttribute('value', $this->ascCastToString($value));
            $data->appendChild($input);
        } else if ($ty === 'time') {
            $input = $this->doc->createElement('input');
            $input->setAttribute('id', $inputId);
            $input->setAttribute('name', $name);
            $input->setAttribute('type', 'time');
            if ($disabled) $input->setAttribute('disabled', '');
            if ($item['min'] !== null) $input->setAttribute('min', $item['min']);
            if ($item['max'] !== null) $input->setAttribute('max', $item['max']);
            if ($value !== null) $input->setAttribute('value', $this->ascCastToString($value));
            $data->appendChild($input);
        } else if ($ty === 'datetime') {
            $input = $this->doc->createElement('input');
            $input->setAttribute('id', $inputId);
            $input->setAttribute('name', $name);
            $input->setAttribute('type', 'datetime-local');
            if ($disabled) $input->setAttribute('disabled', '');
            $tz = 'UTC';
            if ($item['tz']) $tz = $item['tz'];
            try {
                $tz = new \DateTimeZone($tz);
                $root->setAttribute('data-tz', $tz->getOffset());
            } catch (\Exception $e) {
                $tz = new \DateTimeZone('UTC');
            }

            if ($item['min'] !== null) {
                try {
                    $epoch = $item['min'];
                    $dateTime = new \DateTime("@$epoch");
                    $dateTime->setTimezone($tz);
                    $formatted = $dateTime->format('Y-m-d') . 'T' . $dateTime->format('H:i');

                    $input->setAttribute('min', $formatted);
                } catch (\Exception $e) {}
            }
            if ($item['max'] !== null) {
                try {
                    $epoch = $item['max'];
                    $dateTime = new \DateTime("@$epoch");
                    $dateTime->setTimezone($tz);
                    $formatted = $dateTime->format('Y-m-d') . 'T' . $dateTime->format('H:i');

                    $input->setAttribute('max', $item['max']);
                } catch (\Exception $e) {}
            }
            if ($value !== null) {
                $dateTime = new \DateTime("@$value");
                $dateTime->setTimezone($tz);
                $formatted = $dateTime->format('Y-m-d') . 'T' . $dateTime->format('H:i');
                $input->setAttribute('value', $formatted);
            }
            $data->appendChild($input);
        } else if ($ty === 'boolean_table') {
            $table = $this->doc->createElement('table');
            $table->setAttribute('id', $inputId);
            $table->setAttribute('class', 'boolean-table');

            $root->setAttribute('data-rows', $item['rows']);
            $root->setAttribute('data-cols', $item['cols']);

            if ($item['minSelect']) {
                $root->setAttribute('data-min-select', $item['minSelect']);
            }
            if ($item['maxSelect']) {
                $root->setAttribute('data-max-select', $item['maxSelect']);
            }

            if ($item['headerTop'] !== null) {
                $head = $this->doc->createElement('thead');
                $tr = $this->doc->createElement('tr');
                if ($item['headerLeft'] !== null) {
                    $space = $this->doc->createElement('th');
                    $tr->appendChild($space);
                }
                for ($i = 0; $i < $item['cols']; $i++) {
                    $th = $this->doc->createElement('th');
                    $th->textContent = $item['headerTop'][$i];
                    $tr->appendChild($th);
                }
                $head->appendChild($tr);
                $table->appendChild($head);
            }
            $excludedCells = [];
            if ($item['excludeCells'] !== null) {
                foreach ($item['excludeCells'] as $xy) {
                    $excludedCells []= $xy[0] . '-' . $xy[1];
                }
            }

            $tbody = $this->doc->createElement('tbody');
            for ($i = 0; $i < $item['rows']; $i++) {
                $tr = $this->doc->createElement('tr');

                if ($item['headerLeft'] !== null) {
                    $th = $this->doc->createElement('th');
                    $th->textContent = $item['headerLeft'][$i];
                    $tr->appendChild($th);
                }

                for ($j = 0; $j < $item['cols']; $j++) {
                    $td = $this->doc->createElement('td');

                    $isExcluded = in_array($j . '-' . $i, $excludedCells);
                    if (!$isExcluded) {
                        $box = $this->doc->createElement('input');
                        $box->setAttribute('data-index', $j . '-' . $i);
                        $box->setAttribute('type', 'checkbox');
                        if ($disabled) $box->setAttribute('disabled', '');
                        $box->setAttribute('name', $name . '[' . $j . '][' . $i . ']');
                        if ($value !== null && $value[$i][$j]) $box->setAttribute('checked', '');

                        $td->appendChild($box);
                    }

                    $tr->appendChild($td);
                }
                $tbody->appendChild($tr);
            }
            $table->appendChild($tbody);
            $data->appendChild($table);
        }

        return $root;
    }

    function setInnerHTML($node, $html) {
        $fragment = $node->ownerDocument->createDocumentFragment();
        $fragment->appendXML($html);
        $node->appendChild($fragment);
    }

    function renderTextItem($scriptCtx, $item) {
        $root = $this->doc->createElement('div');
        $root->setAttribute('class', 'form-item form-text-item');
        $root->setAttribute('data-el', 'text');
        if (gettype($item['text']) === 'string') {
            // plain text
            $this->setInnerHTML($root, $this->parsedown->text($item['text']));
        } else {
            $root->setAttribute('data-script', base64_encode(json_encode($item['text'])));

            $res = $scriptCtx->eval($item['text']);
            if ($res['s'] && gettype($res['v']) === 'string') {
                $this->setInnerHTML($root, $this->parsedown->text($res['v']));
            } else {
                // TODO: handle error?
            }
        }
        return $root;
    }

    function renderScriptItem($scriptCtx, $item) {
        $root = $this->doc->createElement('div');
        $root->setAttribute('class', 'form-item form-script-item');
        $root->setAttribute('data-el', 'script');
        $root->setAttribute('data-script', base64_encode(json_encode($item['script'])));

        $scriptCtx->pushScript($item['script']);

        return $root;
    }

    function renderItem($scriptCtx, $item) {
        if ($item['el'] === 'input') return $this->renderInputItem($scriptCtx, $item);
        if ($item['el'] === 'text') return $this->renderTextItem($scriptCtx, $item);
        if ($item['el'] === 'script') return $this->renderScriptItem($scriptCtx, $item);
    }

    public function render() {
        $root = $this->doc->createElement('div');
        $root->setAttribute('class', 'congress-registration-form-contents');

        $top = $this->renderTop();
        if ($top) $root->appendChild($top);

        $scriptCtx = new CRFScriptExecCtx($this->app);
        foreach ($this->form as $item) {
            $root->appendChild($this->renderItem($scriptCtx, $item));
        }

        return $this->doc->saveHtml($root);
    }
}
