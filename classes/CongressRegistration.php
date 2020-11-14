<?php
namespace Grav\Plugin\AksoBridge;

// handles congress registration form
class CongressRegistration {
    const DATAID = 'dataId';
    const CANCEL = 'cancel';
    const VALIDATE = 'validate';
    const REALLY_CANCEL = 'really_cancel';
    const PAYMENT = 'payment';
    const PAYMENT_METHOD = 'method';
    const PAYMENT_CURRENCY = 'currency';

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

        $fields = ['cancelledTime', 'price', 'amountPaid', 'hasPaidMinimum'];
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
                'fields' => ['id', 'type', 'stripeMethods', 'name', 'description', 'currencies',
                    'feePercent', 'feeFixed.val', 'feeFixed.cur'],
            ), 60);

            $currency = $this->paymentCurrency;

            if ($res['k'] && !in_array($currency, $res['b']['currencies'])) {
                $currency = null;
            }

            if ($res['k'] && $currency !== null) {
                $method = $res['b'];

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

                $customerName = 'John Doe';
                $customerEmail = 'test@akso.org';

                $isSubmission = $_SERVER['REQUEST_METHOD'] === 'POST';
                $error = null;
                $redirectTarget = null;
                $returnTarget = null;

                while ($isSubmission) {
                    $post = !empty($_POST) ? $_POST : [];
                    $value = $post['amount'];
                    $notes = $post['notes'];
                    if (gettype($value) !== 'string' || gettype($notes) !== 'string' || gettype($currency) !== 'string') {
                        // TODO: bad request
                        $error = '[[bad request]]';
                        break;
                    }
                    $value = floatval($value);
                    if ($value < $min || $value > $max) {
                        // TODO: bad request
                        $error = '[[value out of bounds]]';
                        break;
                    }
                    $value = floor($value * $multiplier);
                    if (!in_array($currency, $method['currencies'])) {
                        $error = '[[invalid currency]]';
                        break;
                    }

                    $triggerAmount = $this->convertCurrency($currency, $this->currency, $value);

                    $codeholderId = null;
                    if ($this->plugin->aksoUser) {
                        $codeholderId = $this->plugin->aksoUser['id'];
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
                            'amount' => $value,
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
                        var_dump($res);
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

                return array(
                    'is_payment' => true,
                    'is_payment_method' => true,
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

                        'currency' => ['t' => 's', 'v' => $currency],
                        'fee_fixed_val' => ['t' => 'n', 'v' => $method['feeFixed'] !== null ? $method['feeFixed']['val'] : 0],
                        'fee_fixed_cur' => ['t' => 's', 'v' => $method['feeFixed'] !== null ? $method['feeFixed']['cur'] : ''],
                        'fee_fixed' => ['t' => 'c', 'f' => 'currency_fmt', 'a' => ['fee_fixed_cur', 'fee_fixed_val']],

                        'fee_pc_val' => ['t' => 'n', 'v' => $method['feePercent'] !== null ? $method['feePercent'] : 0],

                        '0' => ['t' => 'n', 'v' => 0],
                        'has_fixed_fee' => ['t' => 'c', 'f' => '>', 'a' => ['fee_fixed_val', '0']],
                        'has_pc_fee' => ['t' => 'c', 'f' => '>', 'a' => ['fee_pc_val', '0']],
                        'has_both_fees' => ['t' => 'c', 'f' => 'and', 'a' => ['has_fixed_fee', 'has_pc_fee']],
                        'has_any_fee' => ['t' => 'c', 'f' => 'or', 'a' => ['has_fixed_fee', 'has_pc_fee']],

                        'text_fee_fixed' => ['t' => 'c', 'f' => 'id', 'a' => ['fee_fixed']],

                        'text_fee_join' => ['t' => 's', 'v' => ' % ('],
                        'text_fee_after' => ['t' => 's', 'v' => ')'],
                        'text_fee_pc' => ['t' => 'c', 'f' => '++', 'a' => ['fee_pc_val', 'text_fee_join']],
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

            $paymentMethods = [];
            if ($this->paymentOrg) {
                $res = $this->app->bridge->get('/aksopay/payment_orgs/' . $this->paymentOrg . '/methods', array(
                    'fields' => ['id', 'type', 'stripeMethods', 'name', 'description', 'currencies',
                        'feePercent', 'feeFixed.val', 'feeFixed.cur', 'isRecommended'],
                    'limit' => 100,
                    'order' => [['name', 'asc']],
                ), 60);
                if ($res['k']) {
                    // put recommended methods first
                    foreach ($res['b'] as $method) {
                        if ($method['isRecommended']) $paymentMethods[] = $method;
                    }
                    foreach ($res['b'] as $method) {
                        if (!$method['isRecommended']) $paymentMethods[] = $method;
                    }
                }
            }

            return array(
                'is_payment' => true,
                'payment' => $paymentInfo,
                'payment_methods' => $paymentMethods,
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

    private function participantPaymentInfo() {
        if (!$this->participant) return array('outstanding_payment' => false);
        $price = $this->participant['price'];
        if (!$price) return array('outstanding_payment' => false);
        $remaining = $price - $this->participant['amountPaid'];

        $remainingRendered = $this->formatCurrency($remaining, $this->currency);
        $totalRendered = $this->formatCurrency($price, $this->currency);

        $link = $this->plugin->getGrav()['uri']->path() . '?' .
            self::DATAID . '=' . $this->dataId . '&' .
            self::PAYMENT . '=true';

        return array(
            'outstanding_payment' => $remaining > 0,
            'remaining_amount' => $remaining,
            'remaining_rendered' => $remainingRendered,
            'total_amount' => $price,
            'total_rendered' => $totalRendered,
            'link' => $link,
        );
    }

    private function runForm() {
        $form = new CongressRegistrationForm(
            $this->plugin,
            $this->app,
            $this->form['form'],
            $this->congressId,
            $this->instanceId,
            $this->currency
        );

        if ($this->participant) {
            $form->setParticipant($this->dataId, $this->participant);
        }

        $isSubmission = !$this->isCancellation && ($_SERVER['REQUEST_METHOD'] === 'POST');
        $isConfirmation = false;

        if (!$this->canceledTime) {
            if ($this->isActualCancellation) {
                $this->canceledTime = $form->cancel();
            } else if ($isSubmission) {
                $post = !empty($_POST) ? $_POST : [];
                if ($this->validateOnly) {
                    $form->validate($post, true);
                } else {
                    $form->trySubmit($post);
                }
            }
        }

        if ($form->confirmDataId !== null) {
            // the form was submitted successfully
            $this->dataId = $form->confirmDataId;
            $isConfirmation = true;

            $res = $this->app->bridge->get('/congresses/' . $this->congressId . '/instances/' . $this->instanceId . '/participants/' . $this->dataId, array(
                'fields' => ['price', 'amountPaid', 'hasPaidMinimum']
            ));
            if ($res['k']) {
                $this->participant = $res['b'];
                $form->setParticipant($this->dataId, $this->participant);
            }
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
                self::DATAId . '=' . $this->dataId . '&' .
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

            return array(
                'data_id' => $this->dataId,
                'payment' => $payment,
                'editable' => $this->isEditable,
                'cancelable' => $this->isCancelable,
                'cancel_target' => $cancelTarget,
                'validate_target' => $validateTarget,
                'submit_target' => $submitTarget,
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
}