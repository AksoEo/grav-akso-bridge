<?php
namespace Grav\Plugin\AksoBridge;

function localize($locale, $key, ...$params) {
    if (count($params)) {
        $out = '';
        $i = 0;
        foreach ($params as $p) {
            $out .= $locale[$key . '_' . $i];
            $out .= $p;
            $i++;
        }
        $out .= $locale[$key . '_' . $i];
        return $out;
    }
    if (!isset($locale[$key])) return '???' . $key;
    return $locale[$key];
}


// ASC script exec context
class FormScriptExecCtx {
    private $app;
    public $scriptStack = [];
    public $formVars = [];

    public function __construct($app) {
        $this->app = $app;
    }

    public function pushScript($script) {
        $this->scriptStack[] = $script;
    }
    public function popScript() {
        array_pop($this->scriptStack);
    }
    public function setFormVar($name, $value) {
        $this->formVars[$name] = $value;
    }
    public function setDateFormVar($name, $epoch) {
        $this->formVars[$name] = array('type' => 'date', 'time' => $epoch);
    }

    public function eval($expr) {
        return $this->app->bridge->evalScript($this->scriptStack, $this->formVars, $expr);
    }
}

abstract class FormInputPrototype {
    // Reads post data into the actual representation and returns it.
    // - $data: data to validate
    // - $extra: extra data. keys:
    //      - 'currencies': currency multipliers map
    //      - 'item': item object
    //      - 'error': can be set to a value to emit an error
    //      - 'locale': form locale data
    public abstract function readFromPost($data, $extra);

    // Returns either null or an error string.
    // - $item: the form item object
    // - $data: data to validate
    // - $extra: extra data. keys:
    //      - 'req': bool (is the field required?)
    //      - 'bridge': akso bridge
    //      - 'locale': form locale data
    public abstract function getError($item, $value, $extra);
}

class FormInputBoolean extends FormInputPrototype {
    public const ID = 'boolean';
    public function readFromPost($data, $extra) {
        return (bool) $data;
    }
    public function getError($item, $value, $extra) {
        if ($extra['req'] && !$value) {
            // booleans are special: required means they must be true
            return localize($extra['locale'], 'err_field_is_required');
        }
    }
}
class FormInputNumber extends FormInputPrototype {
    public const ID = 'number';
    public function readFromPost($data, $extra) {
        return $data === "" ? null : floatval($data);
    }
    public function getError($item, $value, $extra) {
        if ($item['step'] !== null) {
            if ($value % $item['step'] !== 0) {
                return localize($extra['locale'], 'err_number_step', $item['step']);
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
                return localize($extra['locale'], 'err_number_range', $item['min'], $item['max']);
            }
        } else if (!$fulfillsMin) {
            return localize($extra['locale'], 'err_number_min', $item['min']);
        } else if (!$fulfillsMax) {
            return localize($extra['locale'], 'err_number_max', $item['max']);
        }
    }
}
class FormInputText extends FormInputPrototype {
    public const ID = 'text';
    public function readFromPost($data, $extra) {
        return $data === "" ? null : strval($data);
    }
    public function getError($item, $value, $extra) {
        if ($item['pattern'] !== null) {
            $res = $extra['bridge']->matchRegExp($item['pattern'], $value);
            if (!$res['m']) {
                return $item['patternError'] ?: localize($extra['locale'], 'err_text_pattern_generic');
            }
        }

        // Javascript uses UTF16
        $fulfillsMin = $item['minLength'] !== null ? mb_strlen($value, 'UTF-16') >= $item['minLength'] : true;
        $fulfillsMax = $item['maxLength'] !== null ? mb_strlen($value, 'UTF-16') <= $item['maxLength'] : true;

        if ($item['minLength'] !== null && $item['maxLength'] !== null) {
            if (!$fulfillsMin || !$fulfillsMax) {
                return localize($extra['locale'], 'err_text_len_range', $item['minLength'], $item['maxLength']);
            }
        } else if (!$fulfillsMin) {
            return localize($extra['locale'], 'err_text_len_min', $item['minLength']);
        } else if (!$fulfillsMax) {
            return localize($extra['locale'], 'err_text_len_max', $item['maxLength']);
        }
    }
}
class FormInputMoney extends FormInputPrototype {
    public const ID = 'money';
    public function readFromPost($data, $extra) {
        $multiplier = $extra['currencies'][$extra['item']['currency']];
        return $data === "" ? null : floor(floatval($data) * $multiplier);
    }
    public function getError($item, $value, $extra) {
        if ($item['step'] !== null) {
            if ($value % $item['step'] !== 0) {
                return localize($extra['locale'], 'err_money_step', $item['step']);
            }
        }

        $min = $item['min'] === null ? 0 : $item['min'];

        $fulfillsMin = $value >= $min;
        $fulfillsMax = ($item['max'] !== null) ? ($value <= $item['max']) : true;

        if ($item['max'] !== null && (!$fulfillsMin || !$fulfillsMax)) {
            return localize($extra['locale'], 'err_money_range', $min, $item['max']);
        } else if (!$fulfillsMin) {
            return localize($extra['locale'], 'err_money_min', $min);
        }
    }
}
class FormInputEnum extends FormInputText {
    public const ID = 'enum';
    public function getError($item, $value, $extra) {
        if (!$value) return null;

        $found = false;
        foreach ($item['options'] as $option) {
            if ($option['value'] === $value) {
                $found = true;
                break;
            }
        }

        if (!$found) return localize($extra['locale'], 'err_enum_not_in_set');
    }
}
class FormInputCountry extends FormInputText {
    public const ID = 'country';
    public function getError($item, $value, $extra) {
        // nothing to validate here because all options presented in the UI are valid.
        // if the user sends invalid data on purpose, then AKSO API will deal with it.
    }
}
class FormInputDate extends FormInputText {
    public const ID = 'date';
    public function getError($item, $value, $extra) {
        if (!$value) return null;

        $dateTime = \DateTime::createFromFormat('Y-m-d', $value);
        if ($dateTime === false) return localize($extra['locale'], 'err_date_fmt');

        $minDate = null;
        $maxDate = null;
        if ($item['min']) $minDate = \DateTime::createFromFormat('Y-m-d', $item['min']);
        if ($item['max']) $maxDate = \DateTime::createFromFormat('Y-m-d', $item['max']);

        $fulfillsMin = $minDate ? $dateTime >= $minDate : true;
        $fulfillsMax = $maxDate ? $dateTime <= $maxDate : true;

        if ($minDate && $maxDate) {
            if (!$fulfillsMin || !$fulfillsMax) {
                return localize($extra['locale'], 'err_datetime_range', Utils::formatDate($minDate), Utils::formatDate($maxDate));
            }
        } else if (!$fulfillsMin) {
            return localize($extra['locale'], 'err_datetime_min', Utils::formatDate($minDate));
        } else if (!$fulfillsMax) {
            return localize($extra['locale'], 'err_datetime_max', Utils::formatDate($maxDate));
        }
    }
}
class FormInputTime extends FormInputText {
    public const ID = 'time';
    public function getError($item, $value, $extra) {
        if (!$value) return null;

        $dateTime = \DateTime::createFromFormat('H:i', $value);
        if ($dateTime === false) return localize($extra['locale'], 'err_time_fmt');

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
                return localize($extra['locale'], 'err_datetime_range', $item['min'], $item['max']);
            }
        } else if ($minMins > $timeMins) {
            return localize($extra['locale'], 'err_datetime_min', $item['min']);
        } else if ($maxMins < $timeMins) {
            return localize($extra['locale'], 'err_datetime_max', $item['max']);
        }
    }
}
class FormInputDateTime extends FormInputText {
    public const ID = 'datetime';
    public function readFromPost($data, $extra) {
        $item = $extra['item'];
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
                return $date->getTimestamp();
            } else {
                $extra['error'] = localize($extra['locale'], 'err_datetime_fmt');
                return null;
            }
        }
    }
    public function getError($item, $value, $extra) {
        if (!$value) return null;

        $dateTime = new \DateTime("@$value");
        if ($dateTime === false) return localize($extra['locale'], 'err_datetime_fmt');

        $fulfillsMin = $item['min'] ? $item['min'] <= $value : true;
        $fulfillsMax = $item['max'] ? $item['max'] >= $value : true;

        if ($item['min'] && $item['max']) {
            if (!$fulfillsMin || !$fulfillsMax) {
                $minTime = $item['min'];
                $maxTime = $item['max'];
                $minTime = Utils::formatDate(new \DateTime("@$minTime"));
                $maxTime = Utils::formatDate(new \DateTime("@$maxTime"));
                return localize($extra['locale'], 'err_datetime_range', $minTime, $maxTime);
            }
        } else if (!$fulfillsMin) {
            $minTime = $item['min'];
            $minTime = Utils::formatDate(new \DateTime("@$minTime"));
            return localize($extra['locale'], 'err_datetime_min', $minTime);
        } else if (!$fulfillsMax) {
            $maxTime = $item['max'];
            $maxTime = Utils::formatDate(new \DateTime("@$maxTime"));
            return localize($extra['locale'], 'err_datetime_max', $maxTime);
        }
    }
}
class FormInputBooleanTable extends FormInputText {
    public const ID = 'boolean_table';
    public function readFromPost($data, $extra) {
        $item = $extra['item'];
        $excludedCells = [];
        if ($item['excludeCells'] !== null) {
            foreach ($item['excludeCells'] as $xy) {
                $excludedCells []= $xy[0] . '-' . $xy[1];
            }
        }

        $isNull = true;
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
                if ($cellValue) $isNull = false;
                $row[] = $cellValue;
            }
            $out[] = $row;
        }
        if ($isNull) $out = null;
    }
    public function getError($item, $value, $extra) {
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
                return localize($extra['locale'], 'err_bool_table_select_range', $item['minSelect'], $item['maxSelect']);
            }
        } else if (!$fulfillsMin) {
            return localize($extra['locale'], 'err_bool_table_select_min', $item['minSelect']);
        } else if (!$fulfillsMax) {
            return localize($extra['locale'], 'err_bool_table_select_max', $item['maxSelect']);
        }
    }
}

// Renders, reads, and validates a form.
class Form {
    // Current form field data.
    protected $data = null;

    // List of errors. Map<field name (string), string>
    protected $errors = [];
    // Top-level error
    protected $error = null;
    // Top-level message
    public $message = null;

    // AKSO bridge
    protected $app;
    // form locale data
    protected $locale;
    // form prototype
    protected $form;

    protected $inputTypes = [];

    public function __construct($app) {
        $this->app = $app;

        $this->inputTypes[FormInputBoolean::ID] = new FormInputBoolean();
        $this->inputTypes[FormInputNumber::ID] = new FormInputNumber();
        $this->inputTypes[FormInputText::ID] = new FormInputText();
        $this->inputTypes[FormInputMoney::ID] = new FormInputMoney();
        $this->inputTypes[FormInputEnum::ID] = new FormInputEnum();
        $this->inputTypes[FormInputCountry::ID] = new FormInputCountry();
        $this->inputTypes[FormInputDate::ID] = new FormInputDate();
        $this->inputTypes[FormInputTime::ID] = new FormInputTime();
        $this->inputTypes[FormInputDateTime::ID] = new FormInputDateTime();
        $this->inputTypes[FormInputBooleanTable::ID] = new FormInputBooleanTable();
    }

    protected $cachedCurrencies = null;
    function getCachedCurrencies() {
        if (!$this->cachedCurrencies) {
            $this->cachedCurrencies = $this->app->bridge->currencies();
        }
        return $this->cachedCurrencies;
    }

    // TODO: deduplicate code
    protected $cachedCurrencyRates = null;
    protected function convertCurrency($fromCur, $toCur, $value) {
        if ($fromCur == $toCur) return $value;
        if (!$fromCur || !$toCur) return $value;
        if (!$this->cachedCurrencyRates) {
            $res = $this->app->bridge->get('/aksopay/exchange_rates', array(
                'base' => $fromCur,
            ), 60);
            if ($res['k']) $this->cachedCurrencyRates = $res['b'];
            else throw new \Exception('failed to load exchange rates');
        }
        $rates = $this->cachedCurrencyRates;
        $multipliers = $this->app->bridge->currencies();
        $fromCurFloat = $value / $multipliers[$fromCur];
        $toCurFloat = $this->app->bridge->convertCurrency($rates, $fromCur, $toCur, $fromCurFloat)['v'];
        return round($toCurFloat * $multipliers[$toCur]);
    }

    protected $cachedCountries = [];
    function getCachedCountries() {
        if (empty($this->cachedCountries)) {
            $res = $this->app->bridge->get('/countries', array(
                'limit' => 300,
                'fields' => ['name_eo', 'code'],
                'order' => [['name_eo', 'asc']]
            ), 300);
            if ($res['k']) $this->cachedCountries = $res['b'];
        }
        return $this->cachedCountries;
    }

    protected function localize(...$args) {
        return localize($this->locale, ...$args);
    }

    // Reads input field data from POST.
    // Only validates types.
    protected function readInputFieldFromPost($item, $data) {
        $ty = $item['type'];
        $out = null;

        if (isset($this->inputTypes[$ty])) {
            $extra = array(
                'item' => $item,
                'currencies' => $this->getCachedCurrencies(),
                'error' => null,
                'locale' => $this->locale,
            );
            $out = $this->inputTypes[$ty]->readFromPost($data, $extra);
            if ($extra['error']) {
                $this->errors[$item['name']] = $extra['error'];
            }
        } else {
            $this->errors[$item['name']] = $this->localize('unknown_input_field_type');
        }

        return $out;
    }

    protected function loadPostData($data) {
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

    protected function getFieldError($scriptCtx, $item, $value) {
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
            if (isset($this->inputTypes[$ty])) {
                $err = $this->inputTypes[$ty]->getError($item, $value, array(
                    'bridge' => $this->app->bridge,
                    'req' => $req,
                    'locale' => $this->locale,
                ));
                if ($err) return $err;
            }
        }

        return null; // everything ok
    }

    // Validates form data. Returns true if valid.
    protected function validateData() {
        if ($this->data === null) return;
        $ok = true;
        $scriptCtx = new FormScriptExecCtx($this->app);

        foreach ($this->form as $item) {
            if ($item['el'] === 'input') {
                $fieldError = null;
                $value = null;
                if (isset($this->data[$item['name']])) {
                    $value = $this->data[$item['name']];
                    $fieldError = $this->getFieldError($scriptCtx, $item, $value);
                } else {
                    // fields can be nullable actually!!
                    // $fieldError = $this->localize('err_data_field_missing', $item['name']);
                }
                if ($fieldError) $ok = false;
                $this->errors[$item['name']] = $fieldError;

                $scriptCtx->setFormVar($item['name'], $value);
            } else if ($item['el'] === 'script') {
                $scriptCtx->pushScript($item['script']);
            }
        }

        return $ok;
    }
}
