<?php
namespace Grav\Plugin\AksoBridge;

use Grav\Common\Grav;
use Grav\Plugin\AksoBridge\Form;

// Handles the congress registration form.
class CongressRegistrationForm extends Form {
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

    private $plugin;
    private $currency;
    private $doc;
    private $parsedown;
    private $congressId;
    private $instanceId;
    private $customFormVars;
    public function __construct($plugin, $app, $form, $customFormVars, $congressId, $instanceId, $currency) {
        parent::__construct($app);
        $this->app = $app;
        $this->plugin = $plugin;
        $this->form = $form;
        $this->customFormVars = $customFormVars;
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
    // Participant data
    private $participant = null;

    // Sets user data from API data.
    public function setParticipant($dataId, $apiData) {
        $this->dataId = $dataId;
        $this->participant = $apiData;
        if (isset($apiData['data'])) {
            $this->data = $apiData['data'];
        }
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
        $congressId = $this->congressId;
        $instanceId = $this->instanceId;

        if ($this->dataId) {
            // PATCH
            $dataId = $this->dataId;
            $res = $this->app->bridge->patch('/congresses/' . $congressId . '/instances/' . $instanceId . '/participants/' . $dataId, $args, [], []);
            if ($res['k']) {
                $this->message = $this->localize('msg_patch_success');
            } else if ($res['sc'] === 400) {
                $this->error = $this->localize('err_submit_invalid');
                Grav::instance()['log']->error("error updating congress registration in congress $congressId instance $instanceId data id $dataId: " . $res['b']);
            } else {
                Grav::instance()['log']->error("failed to update congress registration in congress $congressId instance $instanceId data id $dataId: " . $res['b']);
                $this->error = $this->localize('err_submit_generic');
            }
        } else {
            // new registration
            $res = $this->app->bridge->post('/congresses/' . $congressId . '/instances/' . $instanceId . '/participants', $args, [], []);
            if ($res['k']) {
                $this->confirmDataId = $res['h']['x-identifier'];
            } else if ($res['sc'] === 400) {
                $this->error = $this->localize('err_submit_invalid');
                Grav::instance()['log']->error("error submitting congress registration in congress $congressId instance $instanceId: " . $res['b']);
            } else if ($res['sc'] === 409) {
                $this->error = $this->localize('err_submit_already_registered');
            } else {
                Grav::instance()['log']->error("failed to submit congress registration in congress $congressId instance $instanceId: " . $res['b']);
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

    public function setNonceInvalid() {
        $this->error = $this->localize('err_nonce_invalid');
    }

    public function setUnknownError() {
        $this->error = $this->localize('err_submit_generic');
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

        if (!$this->participant && !$this->plugin->aksoUser) {
            $banner = $this->doc->createElement('div');
            $banner->setAttribute('class', 'registration-suggestion-banner');
            $banner->textContent = $this->localize('consider_signing_in_title');
            $footer = $this->doc->createElement('div');
            $footer->setAttribute('class', 'inner-footer');
            $link = $this->doc->createElement('a');
            $link->setAttribute('class', 'link-button is-primary');
            $link->setAttribute('href', $this->plugin->loginPath);
            $link->textContent = $this->localize('consider_signing_in_button');
            $footer->appendChild($link);
            $banner->appendChild($footer);
            return $banner;
        }

        return null;
    }

    function ascCastToString($value) {
        if (gettype($value) === 'array') {
            return implode('', $value);
        }
        return (string) $value;
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
            if (isset($ch['honorific'])) $name .= $ch['honorific'] . ' ';
            if (isset($ch['firstName'])) $name .= $ch['firstName'] . ' ';
            else $name .= $ch['firstNameLegal'] . ' ';
            if (isset($ch['lastName'])) $name .= $ch['lastName'];
            else if (isset($ch['lastNameLegal'])) $name .= $ch['lastNameLegal'];
            $name = trim($name);
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
        if ($item['hideIfDisabled']) {
            $root->setAttribute('data-hide-if-disabled', 'true');
        }

        $data = $this->doc->createElement('div');
        $data->setAttribute('class', 'form-data');

        $ty = $item['type'];
        $name = self::DATA_VAR_NAME . '[' . $item['name'] . ']';
        $existenceName = self::DATA_VAR_NAME . '[' . self::DATA_VAR_EXISTENCE_PREFIX . $item['name'] . ']';
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
            $input->setAttribute('class', 'field-value-input');
            $input->setAttribute('id', $inputId);
            $input->setAttribute('name', $name);
            $input->setAttribute('type', 'checkbox');
            if ($value) $input->setAttribute('checked', '');
            if ($disabled) $input->setAttribute('disabled', '');

            $existenceMarker = $this->doc->createElement('input');
            $existenceMarker->setAttribute('class', 'field-existence-marker');
            $existenceMarker->setAttribute('name', $existenceName);
            $existenceMarker->setAttribute('type', 'hidden');
            $existenceMarker->setAttribute('value', '1');
            if ($disabled) $existenceMarker->setAttribute('disabled', '');

            $root->appendChild($existenceMarker);
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

                    $optDisabled = $option['disabled'];
                    if ($optDisabled === 'onlyExisting' && $this->dataId !== null && $value === $option['value']) $optDisabled = false;
                    if ($optDisabled) {
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

                    $optDisabled = $option['disabled'];
                    if ($optDisabled === 'onlyExisting' && $this->dataId !== null && $value === $option['value']) $optDisabled = false;
                    if ($disabled || $optDisabled) $li->setAttribute('class', 'is-disabled');

                    $radioId = $inputId . '--' . $option['value'];

                    $radio = $this->doc->createElement('input');
                    $radio->setAttribute('type', 'radio');
                    $radio->setAttribute('name', $name);
                    $radio->setAttribute('id', $radioId);
                    $radio->setAttribute('value', $option['value']);
                    if ($value === $option['value']) $radio->setAttribute('checked', '');
                    if ($disabled || $optDisabled) {
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

            $countries = $this->getCachedCountries();
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
            $root->setAttribute('data-text', base64_encode(json_encode($item['text'])));

            // plain text
            // TODO: use markdown-it with appropriate notes
            // TODO: render templating

            $text = $item['text'];
            $text = preg_replace_callback(
                '/\{\{#if\s+(.+?)}}(.+?)(?:\{\{#else}}(.+?))?\{\{\/if}}/s',
                function ($matches) use ($scriptCtx) {
                    $condExpr = array(
                        't' => 'c',
                        'f' => 'id',
                        'a' => [$matches[1]],
                    );
                    $res = $scriptCtx->eval($condExpr);
                    if ($res['s'] && $res['v']) {
                        return $matches[2];
                    } else if ($res['s']) {
                        return $matches[3];
                    } else {
                        $name = $matches[1];
                        $err = $res['e'];
                        Grav::instance()['log']->error("Failed akso script execution in string template condition: $name: $err");
                        return '<? ERARO ?>';
                    }
                },
                $text
            );
            $text = preg_replace_callback(
                '/\{\{([^#\/].*?)}}/s',
                function ($matches) use ($scriptCtx) {
                    $scriptCtx->pushScript(array(
                        '__!!EMPTY_STRING!!__' => array('t' => 's', 'v' => ''),
                    ));
                    $expr = array(
                        't' => 'c',
                        'f' => '++',
                        'a' => ['__!!EMPTY_STRING!!__', $matches[1]],
                    );
                    $res = $scriptCtx->eval($expr);
                    $scriptCtx->popScript();

                    if ($res['s']) {
                        if (is_array($res['v'])) return join('', $res['v']);
                        return (string) $res['v'];
                    } else {
                        $name = $matches[1];
                        $err = $res['e'];
                        Grav::instance()['log']->error("Failed akso script execution in string template: $name: $err");
                        return '<? ERARO ?>';
                    }
                },
                $text
            );

            $this->setInnerHTML($root, $this->parsedown->text($text));
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

        $meta = $this->doc->createElement('div');
        $meta->setAttribute('class', 'form-meta');

        $scriptCtx = new FormScriptExecCtx($this->app);

        if ($this->participant) {
            $meta->setAttribute('data-is-member', $this->participant['codeholderId'] != null);
            $meta->setAttribute('data-created-time', $this->participant['createdTime']);
            $meta->setAttribute('data-edited-time', $this->participant['editedTime']);

            $scriptCtx->setFormVar('@isMember', $this->participant['codeholderId'] != null);
            if ($this->participant['createdTime'] != null) {
                $scriptCtx->setDateFormVar('@createdTime', $this->participant['createdTime']);
            } else {
                $scriptCtx->setFormVar('@createdTime', null);
            }
            if ($this->participant['editedTime'] != null) {
                $scriptCtx->setDateFormVar('@editedTime', $this->participant['editedTime']);
            } else {
                $scriptCtx->setFormVar('@editedTime', null);
            }
        } else {
            $meta->setAttribute('data-is-member', $this->plugin->aksoUser != null);
            $meta->setAttribute('data-created-time', null);
            $meta->setAttribute('data-edited-time', null);

            $scriptCtx->setFormVar('@isMember', $this->plugin->aksoUser != null);
            $scriptCtx->setFormVar('@createdTime', null);
            $scriptCtx->setFormVar('@editedTime', null);
        }

        $custom_vars_json = array();
        foreach ($this->customFormVars as $name => $info) {
            $var_name = substr($name, 1); // ignore the double @
            $var_value = $info['default'];

            if ($this->participant) {
                $overrides = $this->participant['customFormVars'];
                if (isset($overrides[$name])) {
                    $var_value = $overrides[$name];
                }
            }

            $custom_vars_json[$var_name] = $var_value;
        }
        $meta->setAttribute('data-custom-form-vars', json_encode($custom_vars_json));

        $root->appendChild($meta);

        foreach ($this->form as $item) {
            $root->appendChild($this->renderItem($scriptCtx, $item));
        }

        return $this->doc->saveHtml($root);
    }
}
