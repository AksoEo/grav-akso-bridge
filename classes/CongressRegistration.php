<?php
namespace Grav\Plugin\AksoBridge;

// handles congress registration form
use DateInterval;
use DateTime;
use Grav\Common\Grav;

class CongressRegistration {
    public const DATAID = 'dataId';
    const CANCEL = 'cancel';
    const VALIDATE = 'validate';
    const REALLY_CANCEL = 'really_cancel';
    const PAYMENT = 'payment';
    const PAYMENT_METHOD = 'method';
    const PAYMENT_CURRENCY = 'currency';
    const PAYMENT_SUCCESS_RETURN = 'payment_success_return';
    const NONCES = 'crf_nonces';

    private $plugin;
    private $app;
    private $congressId;
    private $instanceId;
    private $paymentOrg;
    private $currency = null;
    private $congressName;
    public function __construct($plugin, $app, $congressId, $instanceId, $paymentOrg, $form, $congressName) {
        $this->plugin = $plugin;
        $this->app = $app;
        $this->congressId = $congressId;
        $this->instanceId = $instanceId;
        $this->paymentOrg = $paymentOrg;
        $this->form = $form;
        $this->congressName = $congressName;

        if ($form['price']) {
            $this->currency = $this->form['price']['currency'];
        }
    }

    private $dataId = null;
    private $validateOnly = false;
    private $isCancellation = false;
    private $isActualCancellation = false;
    private $isPayment = false;
    private $paymentMethod = null;
    private $paymentCurrency = null;

    private function readReq() {
        if (isset($_GET[self::VALIDATE])) {
            $this->validateOnly = (bool) $_GET[self::VALIDATE];
        }
        if (isset($_GET[self::DATAID])) {
            $this->dataId = $_GET[self::DATAID];
        }
        if (isset($_GET[self::CANCEL])) {
            $this->isCancellation = (bool) $_GET[self::CANCEL];
        }
        if (isset($_GET[self::REALLY_CANCEL])) {
            $this->isActualCancellation = (bool) $_GET[self::REALLY_CANCEL];
            $this->isCancellation = $this->isCancellation || $this->isActualCancellation;
        }
        if (isset($_GET[self::PAYMENT])) {
            $this->isPayment = (bool) $_GET[self::PAYMENT];
        }
        if (isset($_GET[self::PAYMENT_METHOD])) {
            $this->paymentMethod = (int) $_GET[self::PAYMENT_METHOD];
        }
        if (isset($_GET[self::PAYMENT_CURRENCY])) {
            $this->paymentCurrency = $_GET[self::PAYMENT_CURRENCY];
        }
        if (isset($_GET[self::PAYMENT_SUCCESS_RETURN])) {
            $_SESSION[self::PAYMENT_SUCCESS_RETURN] = true;
            $target = $this->plugin->getGrav()['uri']->path() . '?' . self::DATAID . '=' . $this->dataId;
            $this->plugin->getGrav()->redirectLangSafe($target, 302);
        }
    }

    private $participant = null;
    private $canceledTime = null;
    private $userDataError = null;
    private $isEditable = false;
    private $isCancelable = false;

    private function loadParticipant() {
        if (!$this->dataId) return;
        $this->isEditable = $this->form['editable'];
        $this->isCancelable = $this->form['cancellable'];

        $fields = ['cancelledTime', 'price', 'amountPaid', 'hasPaidMinimum', 'codeholderId', 'createdTime', 'editedTime', 'customFormVars'];
        foreach ($this->form['form'] as $formItem) {
            if ($formItem['el'] === 'input') $fields[] = 'data.' . $formItem['name'];
        }
        $res = $this->app->bridge->get('/congresses/' . $this->congressId . '/instances/' . $this->instanceId . '/participants/' . $this->dataId, array(
            'fields' => $fields,
        ));
        if ($res['k']) {
            $this->participant = $res['b'];
            $this->canceledTime = $res['b']['cancelledTime'];

            if ($this->canceledTime) {
                $this->isCancellation = false;
                $this->isActualCancellation = false;
            }
        } else {
            $this->userDataError = $res;
        }
    }

    private function runPayment() {
        $paymentInfo = $this->participantPaymentInfo();
        $editTarget = $this->plugin->getGrav()['uri']->path() . '?' . self::DATAID . '=' . $this->dataId;

        if (!$paymentInfo['outstanding_payment']) {
            return array(
                'is_payment' => true,
                'payment' => $paymentInfo,
                'edit_target' => $editTarget
            );
        }

        if ($this->paymentMethod) {
            $res = $this->app->bridge->get('/aksopay/payment_orgs/' . $this->paymentOrg . '/methods/' . $this->paymentMethod, array(
                'fields' => ['id', 'type', 'stripeMethods', 'name', 'descriptionPreview', 'currencies',
                    'feePercent', 'feeFixed.val', 'feeFixed.cur', 'internal'],
            ), 60);

            $currency = $this->paymentCurrency;

            if ($res['k'] && !in_array($currency, $res['b']['currencies'])) {
                $currency = null;
            }

            if ($res['k'] && $currency !== null) {
                $method = $res['b'];
                if ($method['internal']) throw new \Exception('Cannot use internal method');

                $currencies = $this->app->bridge->currencies();
                $multiplier = $currencies[$currency];

                $min = 0;
                if ($this->participant['hasPaidMinimum']) {
                    // TODO: some sort of minimum?
                    $min = 1;
                } else {
                    $minA = $this->participant['price'] - $this->participant['amountPaid'];
                    $minB = $this->form['price']['minUpfront'] - $this->participant['amountPaid'];
                    $min = min($minA, $minB);
                }
                $min = $this->convertCurrency($this->currency, $currency, $min) / $multiplier;
                $max = $this->convertCurrency($this->currency, $currency, $paymentInfo['remaining_amount']) / $multiplier;
                $step = 1 / $multiplier;
                $value = $max;

                $identity = $this->getParticipantIdentity();
                $customerName = $identity['name'];
                $customerEmail = $identity['email'];
                if (!$customerName) $customerName = '';
                if (!$customerEmail) $customerEmail = '';

                $isSubmission = $_SERVER['REQUEST_METHOD'] === 'POST';
                $error = null;
                $redirectTarget = null;
                $returnTarget = null;

                while ($isSubmission) {
                    $post = !empty($_POST) ? $_POST : [];

                    $nonceValid = false;
                    if (isset($post['nonce']) && isset($_SESSION[self::NONCES])) {
                        $nonce = $post['nonce'];
                        $nonceIndex = array_search($nonce, $_SESSION[self::NONCES]);
                        if ($nonceIndex !== false) {
                            $nonceValid = true;
                            unset($_SESSION[self::NONCES][$nonceIndex]);
                        }
                    }

                    $mvalue = $post['amount'];
                    $notes = $post['notes'];
                    if (gettype($mvalue) !== 'string' || gettype($notes) !== 'string' || gettype($currency) !== 'string') {
                        $error = $this->plugin->locale['registration_form']['payment_err_bad_request'];
                        break;
                    }
                    $mvalue = floatval($mvalue);
                    if ($mvalue < $min || $mvalue > $max) {
                        $error = $this->plugin->locale['registration_form']['payment_err_bad_request'];
                        break;
                    }
                    $mvalue = floor($mvalue * $multiplier);
                    if (!in_array($currency, $method['currencies'])) {
                        $error = $this->plugin->locale['registration_form']['payment_err_bad_request'];
                        $value = $value / $multiplier;
                        break;
                    }

                    if (!$nonceValid) {
                        $error = $this->plugin->locale['registration_form']['err_nonce_invalid'];
                        $value = $value / $multiplier;
                        break;
                    }

                    $triggerAmount = $this->convertCurrency($currency, $this->currency, $mvalue);

                    $codeholderId = null;
                    if ($this->participant['codeholderId']) {
                        $codeholderId = $this->participant['codeholderId'];
                    }

                    if (!$customerName) {
                        $error = $this->plugin->locale['registration_form']['err_submit_no_name'];
                        break;
                    }
                    if (!$customerEmail) {
                        $error = $this->plugin->locale['registration_form']['err_submit_no_email'];
                        break;
                    }

                    $res = $this->app->bridge->post('/aksopay/payment_intents', array(
                        'codeholderId' => $codeholderId,
                        'customer' => array(
                            'email' => $customerEmail,
                            'name' => $customerName,
                        ),
                        'paymentOrgId' => $this->paymentOrg,
                        'paymentMethodId' => $this->paymentMethod,
                        'currency' => $currency,
                        'customerNotes' => empty($notes) ? null : $notes,
                        'purposes' => [array(
                            'type' => 'trigger',
                            'title' => $this->plugin->locale['registration_form']['payment_intent_purpose_title'],
                            'description' => $this->congressName,
                            'amount' => $mvalue,
                            'triggerAmount' => array(
                                'currency' => $this->currency,
                                'amount' => $triggerAmount
                            ),
                            'triggers' => 'congress_registration',
                            'dataId' => hex2bin($this->dataId),
                        )],
                    ), array(), []);

                    if ($res['k']) {
                        $paymentId = $res['h']['x-identifier'];

                        $returnTarget = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") .
                            "://$_SERVER[HTTP_HOST]" . $editTarget;

                        $paymentsHost = $this->plugin->getGrav()['config']->get('plugins.akso-bridge.payments_host');
                        $redirectTarget = $paymentsHost . '/i/' . $paymentId . '?return=' . urlencode($returnTarget);
                    } else {
                        $error = $this->plugin->locale['registration_form']['err_submit_generic'];
                    }

                    break;
                }

                $backTarget = $this->plugin->getGrav()['uri']->path() . '?' .
                    self::DATAID . '=' . urlencode($this->dataId) . '&' .
                    self::PAYMENT . '=true';

                $feeFixedRendered = null;
                if ($method['feeFixed']) {
                    $feeFixedRendered = $this->formatCurrency($method['feeFixed']['val'], $currency);
                }

                $approxConversionRate = $this->convertCurrency($currency, $this->currency, 1000000);

                $nonce = base64_encode(random_bytes(32));
                if (!isset($_SESSION[self::NONCES])) $_SESSION[self::NONCES] = [];
                $_SESSION[self::NONCES][] = $nonce;

                $method['description_rendered'] = $this->app->bridge->renderMarkdown(
                    $method['descriptionPreview'] ?: '',
                    ['emphasis', 'strikethrough', 'link'],
                )['c'];

                return array(
                    'is_payment' => true,
                    'is_payment_method' => true,
                    'form_nonce' => $nonce,
                    'payment' => $paymentInfo,
                    'payment_success_redirect' => $redirectTarget,
                    'payment_success_return' => $returnTarget,
                    'payment_error' => $error,
                    'payment_method' => $method,
                    'payment_currency' => $currency,
                    'payment_currency_mult' => $multiplier,
                    'payment_price_currency' => $this->currency,
                    'payment_price_approx_rate' => $approxConversionRate,
                    'payment_amount_min' => $min,
                    'payment_amount_max' => $max,
                    'payment_amount_step' => $step,
                    'payment_amount_value' => $value,
                    'payment_customer_name' => $customerName,
                    'payment_customer_email' => $customerEmail,
                    'payment_back_target' => $backTarget,
                    'fee_fixed_rendered' => $feeFixedRendered,
                    'payment_form_script' => base64_encode(json_encode([
                        'text_pre' => ['t' => 's', 'v' => '**' . $this->plugin->locale['registration_form']['payment_fees'] . '**: '],

                        '0' => ['t' => 'n', 'v' => 0],
                        '100' => ['t' => 'n', 'v' => 100],

                        'currency' => ['t' => 's', 'v' => $currency],
                        'fee_fixed_val' => ['t' => 'n', 'v' => $method['feeFixed'] !== null ? $method['feeFixed']['val'] : 0],
                        'fee_fixed_cur' => ['t' => 's', 'v' => $method['feeFixed'] !== null ? $method['feeFixed']['cur'] : ''],
                        'fee_fixed' => ['t' => 'c', 'f' => 'currency_fmt', 'a' => ['fee_fixed_cur', 'fee_fixed_val']],

                        'fee_pc_val' => ['t' => 'n', 'v' => $method['feePercent'] !== null ? $method['feePercent'] : 0],
                        'fee_pc_val_100' => ['t' => 'c', 'f' => '*', 'a' => ['fee_pc_val', '100']],

                        'has_fixed_fee' => ['t' => 'c', 'f' => '>', 'a' => ['fee_fixed_val', '0']],
                        'has_pc_fee' => ['t' => 'c', 'f' => '>', 'a' => ['fee_pc_val', '0']],
                        'has_both_fees' => ['t' => 'c', 'f' => 'and', 'a' => ['has_fixed_fee', 'has_pc_fee']],
                        'has_any_fee' => ['t' => 'c', 'f' => 'or', 'a' => ['has_fixed_fee', 'has_pc_fee']],

                        'text_fee_fixed' => ['t' => 'c', 'f' => 'id', 'a' => ['fee_fixed']],

                        'text_fee_join' => ['t' => 's', 'v' => ' % ('],
                        'text_fee_after' => ['t' => 's', 'v' => ')'],
                        'text_fee_pc' => ['t' => 'c', 'f' => '++', 'a' => ['fee_pc_val_100', 'text_fee_join']],
                        'fee_pc_real_val' => ['t' => 'c', 'f' => '*', 'a' => ['fee_pc_val', '@amount']],
                        'text_fee_val' => ['t' => 'c', 'f' => 'currency_fmt', 'a' => ['currency', 'fee_pc_real_val']],
                        'text_fee_calc' => ['t' => 'c', 'f' => '++', 'a' => ['text_fee_val', 'text_fee_after']],
                        'text_fee_percent' => ['t' => 'c', 'f' => '++', 'a' => ['text_fee_pc', 'text_fee_calc']],

                        'join' => ['t' => 's', 'v' => ' + '],
                        'text_both_fees1' => ['t' => 'c', 'f' => '++', 'a' => ['text_fee_fixed', 'join']],
                        'text_both_fees' => ['t' => 'c', 'f' => '++', 'a' => ['text_both_fees1', 'text_fee_percent']],

                        'text_fee' => ['t' => 'w', 'm' => [
                            ['c' => 'has_both_fees', 'v' => 'text_both_fees'],
                            ['c' => 'has_fixed_fee', 'v' => 'text_fee_fixed'],
                            ['c' => null, 'v' => 'text_fee_percent'],
                        ]],
                        'fees_text2' => ['t' => 'c', 'f' => '++', 'a' => ['text_pre', 'text_fee']],
                        'empty_string' => ['t' => 's', 'v' => ''],
                        'fees_text' => ['t' => 'w', 'm' => [
                            ['c' => 'has_any_fee', 'v' => 'fees_text2'],
                            ['c' => null, 'v' => 'empty_string'],
                        ]],
                    ])),
                );
            }
        }

        {
            $methodTarget = $this->plugin->getGrav()['uri']->path();

            $autoPaymentMethods = [];
            $otherPaymentMethods = [];

            $payments = new Payments($this->plugin, $this->app);

            if ($this->paymentOrg) {
                $res = $this->app->bridge->get('/aksopay/payment_orgs/' . $this->paymentOrg . '/methods', array(
                    'fields' => ['id', 'type', 'stripeMethods', 'name', 'descriptionPreview', 'currencies',
                        'feePercent', 'feeFixed.val', 'feeFixed.cur', 'isRecommended'],
                    'filter' => array('internal' => false),
                    'limit' => 100,
                    'order' => [['name', 'asc']],
                ), 60);
                if ($res['k']) {
                    foreach ($res['b'] as &$method) {
                        $method['description_rendered'] = $this->app->bridge->renderMarkdown(
                            $method['descriptionPreview'] ?: '',
                            ['emphasis', 'strikethrough', 'link'],
                        )['c'];

                        if ($payments->hasThumbnail($this->paymentOrg, $method['id'])) {
                            $src = $payments->getMethodThumbnailSrcSet($this->paymentOrg, $method['id'], 64);
                            $method['thumbnail_src'] = $src['src'];
                            $method['thumbnail_srcset'] = $src['srcset'];
                        } else {
                            $method['thumbnail_src'] = null;
                            $method['thumbnail_srcset'] = null;
                        }

                    }
                    unset($method);

                    // put recommended methods first
                    foreach ($res['b'] as $method) {
                        if ($method['isRecommended']) {
                            if ($method['type'] === 'stripe') $autoPaymentMethods[] = $method;
                            else $otherPaymentMethods[] = $method;
                        }
                    }
                    foreach ($res['b'] as $method) {
                        if (!$method['isRecommended']) {
                            if ($method['type'] === 'stripe') $autoPaymentMethods[] = $method;
                            else $otherPaymentMethods[] = $method;
                        }
                    }
                }
            }

            return array(
                'is_payment' => true,
                'payment' => $paymentInfo,
                'payment_methods' => array(
                    'auto' => $autoPaymentMethods,
                    'other' => $otherPaymentMethods,
                ),
                'edit_target' => $editTarget,
                'data_id' => $this->dataId,
                'method_target' => $methodTarget,
            );
        }
    }

    private function convertCurrency($fromCur, $toCur, $value) {
        if ($fromCur == $toCur) return $value;
        $res = $this->app->bridge->get('/aksopay/exchange_rates', array(
            'base' => $fromCur,
        ), 60);
        if ($res['k']) {
            $rates = $res['b'];
            $multipliers = $this->app->bridge->currencies();
            $fromCurFloat = $value / $multipliers[$fromCur];
            $toCurFloat = $this->app->bridge->convertCurrency($rates, $fromCur, $toCur, $fromCurFloat)['v'];
            return round($toCurFloat * $multipliers[$toCur]);
        }
        return null;
    }

    private function formatCurrency($value, $currency) {
        $res = $this->app->bridge->evalScript([array(
            'value' => array('t' => 'n', 'v' => $value),
            'currency' => array('t' => 's', 'v' => $currency),
        )], array(), array(
            't' => 'c',
            'f' => 'currency_fmt',
            'a' => ['currency', 'value'],
        ));
        if ($res['s']) {
            return $res['v'] . '';
        }
        return null;
    }

    private function getParticipantIdentity() {
        $idName = $this->form['identifierName'];
        $idEmail = $this->form['identifierEmail'];
        $idCountryCode = $this->form['identifierCountryCode'];

        $out = array(
            'name' => null,
            'email' => null,
            'countryCode' => null,
        );

        $stack = [];
        foreach ($this->form['form'] as $item) {
            if ($item['el'] === 'script') $stack[] = $item['script'];
        }
        $fvars = $this->participant['data'];
        $res = $this->app->bridge->evalScript($stack, $fvars, array('t' => 'c', 'f' => 'id', 'a' => [$idName]));
        if ($res['s']) {
            $out['name'] = gettype($res['v']) === 'array' ? implode('', $res['v']) : (string) $res['v'];
        }
        $res = $this->app->bridge->evalScript($stack, $fvars, array('t' => 'c', 'f' => 'id', 'a' => [$idEmail]));
        if ($res['s']) {
            $out['email'] = gettype($res['v']) === 'array' ? implode('', $res['v']) : (string) $res['v'];
        }
        if ($idCountryCode) {
            $res = $this->app->bridge->evalScript($stack, $fvars, array('t' => 'c', 'f' => 'id', 'a' => [$idCountryCode]));
            if ($res['s']) {
                $out['countryCode'] = gettype($res['v']) === 'array' ? implode('', $res['v']) : (string) $res['v'];
            }
        }

        return $out;
    }

    private function participantPaymentInfo() {
        if (!$this->participant) {
            return array('has_payment' => false, 'outstanding_payment' => false);
        }
        $price = $this->participant['price'];
        if (!$price) return array('has_payment' => false, 'outstanding_payment' => false);
        $remaining = $price - $this->participant['amountPaid'];

        $remainingRendered = $this->formatCurrency($remaining, $this->currency);
        $totalRendered = $this->formatCurrency($price, $this->currency);

        $link = $this->plugin->getGrav()['uri']->path() . '?' .
            self::DATAID . '=' . $this->dataId . '&' .
            self::PAYMENT . '=true';

        $minUpfront = 0;
        if ($this->form['price']) {
            $minUpfront = $this->form['price']['minUpfront'];
            $minUpfront = min($minUpfront, $remaining);
        }
        $isMinPayment = $this->participant['amountPaid'] < $minUpfront;

        $minUpfrontRendered = $this->formatCurrency($minUpfront, $this->currency);

        $paymentHistory = [];

        $res = $this->app->bridge->get('/aksopay/payment_intents', array(
            'fields' => ['codeholderId', 'customer.name', 'customer.email', 'status', 'totalAmount', 'currency', 'id',
                'timeCreated', 'amountRefunded', 'paymentMethod', 'purposes'],
            'order' => [['timeCreated', 'desc']],
            'filter' => array(
                '$purposes' => array(
                    'dataId' => '==base64==' . base64_encode(hex2bin($this->dataId)),
                ),
            ),
            'limit' => 100,
        ));
        if ($res['k']) {
            foreach ($res['b'] as $item) {
                $item['idEncoded'] = Utils::base32_encode($item['id'], false);
                if ($item['paymentMethod']['paymentValidity']) {
                    $time = new DateTime("@" . $item['timeCreated']);
                    $interval = DateInterval::createFromDateString($item['paymentMethod']['paymentValidity'] . ' seconds');
                    $time = $time->add($interval);
                    $item['expiryDate'] = $time->getTimestamp();

                    foreach ($item['purposes'] as &$purpose) {
                        if ($purpose['triggerAmount'] != null) {
                            $purpose['triggerAmountFmt'] = Utils::formatCurrency($this->app->bridge, $purpose['triggerAmount'], $item['currency']);
                        }
                        $purpose['amountFmt'] = Utils::formatCurrency($this->app->bridge, $purpose['amount'], $item['currency']);
                    }
                }
                $item['totalAmountFmt'] = Utils::formatCurrency($this->app->bridge, $item['totalAmount'], $item['currency']);
                $paymentHistory[] = $item;
            }
        }
        $paymentsHost = Grav::instance()['config']->get('plugins.akso-bridge.payments_host');

        return array(
            'has_payment' => true,
            'outstanding_payment' => $remaining > 0,
            'purpose_data_id' => hex2bin($this->dataId),
            'payment_history' => $paymentHistory,
            'aksopay_intent_base' => $paymentsHost . '/i/',
            'remaining_amount' => $remaining,
            'remaining_rendered' => $remainingRendered,
            'total_amount' => $price,
            'total_rendered' => $totalRendered,
            'is_min_payment' => $isMinPayment,
            'min_upfront_rendered' => $minUpfrontRendered,
            'link' => $link,
        );
    }

    private function runForm() {
        $form = new CongressRegistrationForm(
            $this->plugin,
            $this->app,
            $this->form['form'],
            $this->form['customFormVars'],
            $this->congressId,
            $this->instanceId,
            $this->currency
        );

        if ($this->participant) {
            $form->setParticipant($this->dataId, $this->participant);
        }

        $isSubmission = !$this->isCancellation && ($_SERVER['REQUEST_METHOD'] === 'POST');
        $isConfirmation = $_SESSION['aksoCRegIsConfirmation'] ?? false;
        if ($isConfirmation) {
            unset($_SESSION['aksoCRegIsConfirmation']);
        }

        if (!$this->canceledTime) {
            if ($this->isActualCancellation) {
                $this->canceledTime = $form->cancel();
            } else if ($isSubmission) {
                $post = !empty($_POST) ? $_POST : [];
                if ($this->validateOnly) {
                    $form->validate($post, true);
                } else {
                    $nonceValid = false;
                    if (isset($post['nonce']) && isset($_SESSION[self::NONCES])) {
                        $nonce = $post['nonce'];
                        $nonceIndex = array_search($nonce, $_SESSION[self::NONCES]);
                        if ($nonceIndex !== false) {
                            $nonceValid = true;
                            unset($_SESSION[self::NONCES][$nonceIndex]);
                        }
                    }

                    if ($nonceValid) {
                        try {
                            $form->trySubmit($post);
                        } catch (\Exception $e) {
                            $form->setUnknownError();
                            Grav::instance()['log']->error($e);
                        }
                    } else {
                        $form->setNonceInvalid();
                    }
                }
            }
        }

        if ($form->confirmDataId !== null) {
            // the form was submitted successfully
            $this->dataId = $form->confirmDataId;
            $_SESSION['aksoCRegIsConfirmation'] = true;
            $redirectUrl = $this->plugin->getGrav()['uri']->path() . '?' . self::DATAID . '=' . $this->dataId;
            $this->plugin->getGrav()->redirectLangSafe($redirectUrl, 302);
            die();
        }

        $payment = $this->participantPaymentInfo();

        // points to congress page
        $backTarget = explode('/', $this->plugin->getGrav()['uri']->path());
        array_pop($backTarget);
        $backTarget = implode('/', $backTarget);

        if ($this->canceledTime) {
            return array(
                'is_canceled' => true,
                'cancel_success' => $form->cancelSucceeded,
                'back_target' => $backTarget,
            );
        } else if ($this->isCancellation && $this->dataId !== null) {
            $isError = $this->isActualCancellation && !$form->cancelSucceeded;

            $backTarget = $this->plugin->getGrav()['uri']->path() . '?' . self::DATAID . '=' . $this->dataId;
            $rlyTarget = $this->plugin->getGrav()['uri']->path() . '?' .
                self::DATAID . '=' . $this->dataId . '&' .
                self::REALLY_CANCEL . '=true';

            return array(
                'is_cancellation' => true,
                'cancel_error' => $isError,
                'back_target' => $backTarget,
                'rly_target' => $rlyTarget,
            );
        } else if ($isConfirmation) {
            $editTarget = $this->plugin->getGrav()['uri']->path() . '?' .  self::DATAID . '=' . $this->dataId;

            return array(
                'is_confirmation' => true,
                'payment' => $payment,
                'edit_target' => $editTarget,
                'back_target' => $backTarget,
            );
        } else {
            $submitQuery = '';
            $cancelTarget = '';
            if ($this->dataId !== null) {
                $cancelTarget = $this->plugin->getGrav()['uri']->path() . '?' .
                    self::DATAID . '=' . $this->dataId . '&' .
                    self::CANCEL . '=true';

                $submitQuery = self::DATAID . '=' . $this->dataId;
            }

            $validateQuery = self::VALIDATE . '=true&' . $submitQuery;
            $validateTarget = $this->plugin->getGrav()['uri']->path() . '?' . $validateQuery;
            $submitTarget = $this->plugin->getGrav()['uri']->path() . '?' . $submitQuery;

            $nonce = base64_encode(random_bytes(32));
            if (!isset($_SESSION[self::NONCES])) $_SESSION[self::NONCES] = [];
            $_SESSION[self::NONCES][] = $nonce;

            if (isset($_SESSION[self::PAYMENT_SUCCESS_RETURN]) && $_SESSION[self::PAYMENT_SUCCESS_RETURN]) {
                $_SESSION[self::PAYMENT_SUCCESS_RETURN] = false;
                $form->message = $this->plugin->locale['registration_form']['payment_success_return_msg'];
            }

            return array(
                'data_id' => $this->dataId,
                'payment' => $payment,
                'editable' => $this->isEditable,
                'cancelable' => $this->isCancelable,
                'back_target' => $backTarget,
                'cancel_target' => $cancelTarget,
                'validate_target' => $validateTarget,
                'submit_target' => $submitTarget,
                'form_nonce' => $nonce,
                'form' => $form->render(),
            );
        }
    }

    public function run() {
        $this->readReq();
        $this->loadParticipant();

        if ($this->userDataError) {
            return array(
                'form' => '',
                'is_error' => true,
                'is_not_found' => $this->userDataError['sc'] === 404,
            );
        } else if ($this->participant && !$this->canceledTime && $this->isPayment) {
            return $this->runPayment();
        } else {
            return $this->runForm();
        }
    }

    public static function getDataIdForCodeholder($app, $congressId, $instanceId, $codeholderId) {
        $res = $app->bridge->get('/congresses/' . $congressId . '/instances/' . $instanceId . '/participants', array(
            'filter' => array('codeholderId' => $codeholderId),
            'fields' => ['codeholderId', 'dataId', 'cancelledTime', 'createdTime', 'editedTime'],
            'limit' => 100,
        ));

        if ($res['k']) {
            // find first non-canceled registration
            foreach ($res['b'] as $item) {
                if ($item['codeholderId'] != $codeholderId) continue;
                if ($item['cancelledTime']) continue;
                return bin2hex($item['dataId']);
            }
        } else {
            return null;
        }
    }
}
