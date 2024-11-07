<?php

namespace Opencart\Catalog\Controller\Extension\UnzerPayments\Payment;

use UnzerPaymentsSrc\UnzerpaymentClient;
use UnzerPaymentsSrc\UnzerPaymentsHelper;
use UnzerSDK\Constants\WebhookEvents;

require_once(DIR_EXTENSION . "unzer_payments/system/library/unzer_payments/src/init.php");
class UnzerPayments extends \Opencart\System\Engine\Controller
{
    public const REGISTERED_EVENTS = array(
        WebhookEvents::CHARGE_CANCELED,
        WebhookEvents::AUTHORIZE_CANCELED,
        WebhookEvents::AUTHORIZE_SUCCEEDED,
        WebhookEvents::CHARGE_SUCCEEDED,
        WebhookEvents::PAYMENT_CHARGEBACK,
    );


    /**
     * @param $route
     * @param $args
     * @return void
     */
    public function checkoutController(&$route, &$args)
    {
        if (!isset($this->session->data['unz_tmx_id'])) {
            $this->session->data['unz_tmx_id'] =
                'UnzerPaymentOC_' . substr(md5(uniqid(rand(), true)), 0, 25) .
                '_' .substr(md5($_SERVER['HTTP_HOST']), 0, 25);
        }

        $this->document->addScript('https://static.unzer.com/v1/checkout.js');
        $this->document->addScript('https://h.online-metrix.net/fp/tags.js?org_id=363t8kgq&session_id=' . $this->session->data['unz_tmx_id']);
        $this->document->addScript('extension/unzer_payments/catalog/view/javascript/unzer_payments.js');
        $this->document->addStyle('https://static.unzer.com/v1/unzer.css');
        $this->document->addStyle('extension/unzer_payments/catalog/view/css/unzer_payments.css');
    }

    /**
     * index
     *
     * @return mix
     */
    public function index(): string
    {
        $this->load->language('extension/unzer_payments/payment/unzer_payments');

        $data['language'] = $this->config->get('config_language');

        return $this->load->view('extension/unzer_payments/payment/unzer_payments', $data);
    }

    /**
     * initpaypage
     *
     * @return json|string
     */
    public function initpaypage(): void
    {
        UnzerpaymentClient::getInstance(
            $this->config->get('payment_unzer_payments_private_key')
        );

        $this->load->language('extension/unzer_payments/payment/unzer_payments');

        $json = [];

        if (!isset($this->session->data['order_id'])) {
            $json['error'] = $this->language->get('error_order');
        }
        if (!isset($this->session->data['payment_method']) || substr($this->session->data['payment_method']['code'], 0, 15) != 'unzer_payments.') {
            $json['error'] = $this->language->get('error_payment_method');
        }

        if (!$json) {

            $selectedPaymentMethod = str_replace('unzer_payments.', '', $this->session->data['payment_method']['code']);

            $order = $this->getOpenCartOrder($this->session->data['order_id']);
            $orderId = $this->session->data['order_id'];

            $unzer = UnzerpaymentClient::getInstance();
            $customer = $this->customer;

            $key_to_use = 'payment_';
            if ($order['payment_lastname'] == '') {
                $key_to_use = 'shipping_';
            }
            $unzerAddressBilling = (new \UnzerSDK\Resources\EmbeddedResources\Address())
                ->setName($order[$key_to_use . 'firstname'] . ' ' . $order[$key_to_use . 'lastname'])
                ->setStreet($order[$key_to_use . 'address_1'])
                ->setZip($order[$key_to_use . 'postcode'])
                ->setCity($order[$key_to_use . 'city'])
                ->setCountry($order[$key_to_use . 'iso_code_2']);

            $key_to_use = 'shipping_';
            if ($order['shipping_lastname'] == 0) {
                $key_to_use = 'payment_';
            }
            $unzerAddressShipping = (new \UnzerSDK\Resources\EmbeddedResources\Address())
                ->setName($order[$key_to_use . 'firstname'] . ' ' . $order[$key_to_use . 'lastname'])
                ->setStreet($order[$key_to_use . 'address_1'])
                ->setZip($order[$key_to_use . 'postcode'])
                ->setCity($order[$key_to_use . 'city'])
                ->setCountry($order[$key_to_use . 'iso_code_2']);

            if ((int)$order['payment_address_id'] == (int)$order['shipping_address_id']) {
                $unzerAddressShipping->setShippingType(
                    \UnzerSDK\Constants\ShippingTypes::EQUALS_BILLING
                );
            } else {
                $unzerAddressShipping->setShippingType(
                    \UnzerSDK\Constants\ShippingTypes::DIFFERENT_ADDRESS
                );
            }

            $unzerCustomer = (new \UnzerSDK\Resources\Customer())
                ->setCustomerId($order['customer_id'])
                ->setFirstname($order['firstname'])
                ->setLastname($order['lastname'])
                ->setEmail($order['email'])
                ->setPhone($order['telephone'])
                ->setBillingAddress($unzerAddressBilling)
                ->setShippingAddress($unzerAddressShipping);

            $unzerCustomer
                    ->setSalutation(\UnzerSDK\Constants\Salutations::UNKNOWN);

            $unzer->createOrUpdateCustomer(
                $unzerCustomer
            );

            $basket = (new \UnzerSDK\Resources\Basket())
                ->setTotalValueGross($this->numberFormat($order['total']))
                ->setCurrencyCode($this->session->data['currency'])
                ->setOrderId($orderId)
                ->setNote('');

            $this->load->model('catalog/product');
            $basketItems = [];
            $tmpSum = 0;
            foreach ($this->getOrderProducts((int)$orderId) as $product) {
                $productDetails = $this->model_catalog_product->getProduct($product['product_id']);

                $tax_rates = $this->tax->getRates($product['price'], $productDetails['tax_class_id']);
                $rates = $this->getTaxRate($tax_rates);
                $vatRate = isset($rates[0]) ? $rates[0] : 0;

                $qty = (int)$product['quantity'];
                if ($qty < 1) {
                    $qty = 1;
                    $price = $product['price'] * $product['quantity'];
                    $tax = $product['tax'] * $product['quantity'];
                } else {
                    $qty = (int)$product['quantity'];
                    $price = $product['price'];
                    $tax = $product['tax'];
                }

                $total = ($product['price'] + $product['tax']) * $product['quantity'];

                $vatAmount = $total * ($vatRate / (100 +  $vatRate));

                $tmpSum += $this->numberFormat($total);

                $basketItem = (new \UnzerSDK\Resources\EmbeddedResources\BasketItem())
                    ->setBasketItemReferenceId('Item-' . $product['product_id'])
                    ->setQuantity($product['quantity'])
                    ->setUnit('m')
                    ->setAmountPerUnitGross($this->numberFormat($product['price'] + $product['tax']))
                    ->setVat($vatAmount)
                    ->setTitle($product['name'])
                    ->setType(\UnzerSDK\Constants\BasketItemTypes::GOODS);

                $basketItems[] = $basketItem;
            }

            if (isset($order['shipping_method']) && is_array($order['shipping_method'])) {
                if (isset($order['shipping_method']['cost']) && $order['shipping_method']['cost'] > 0) {
                    $tmpSum += $order['shipping_method']['cost'];
                    $basketItem = (new \UnzerSDK\Resources\EmbeddedResources\BasketItem())
                        ->setBasketItemReferenceId('Shipping')
                        ->setQuantity(1)
                        ->setAmountPerUnitGross($this->numberFormat($order['shipping_method']['cost']))
                        ->setTitle('Shipping')
                        ->setType(\UnzerSDK\Constants\BasketItemTypes::SHIPMENT);
                    $basketItems[] = $basketItem;
                }
            }

            $difference = $order['total'] - $tmpSum;
            if ($difference > 0) {
                $basketItem = (new \UnzerSDK\Resources\EmbeddedResources\BasketItem())
                    ->setBasketItemReferenceId('add-shipping-delta')
                    ->setQuantity(1)
                    ->setAmountPerUnitGross($this->numberFormat($difference))
                    ->setTitle('Shipping')
                    ->setSubTitle('Shipping Delta')
                    ->setType(\UnzerSDK\Constants\BasketItemTypes::SHIPMENT);
                $basketItems[] = $basketItem;
            } elseif ($difference < 0) {
                $basketItem = (new \UnzerSDK\Resources\EmbeddedResources\BasketItem())
                    ->setBasketItemReferenceId('VoucherDelta')
                    ->setQuantity(1)
                    ->setAmountDiscountPerUnitGross($this->numberFormat($difference) * -1)
                    ->setTitle('Voucher Delta')
                    ->setType(\UnzerSDK\Constants\BasketItemTypes::VOUCHER);
                $basketItems[] = $basketItem;
            }

            foreach ($basketItems as $basketItem) {
                $basket->addBasketItem(
                    $basketItem
                );
            }

            $successURL = UnzerPaymentsHelper::getSuccessUrl(
                [
                    'caid' => $order['order_id'],
                    'cuid' => $order['customer_id'],
                ]
            );

            $paypage = new \UnzerSDK\Resources\PaymentTypes\Paypage(
                $this->numberFormat($order['total']),
                $this->session->data['currency'],
                $successURL
            );
            $threatMetrixId = isset($this->session->data['unz_tmx_id']) ? $this->session->data['unz_tmx_id'] : null;

            $metadata = new \UnzerSDK\Resources\Metadata();
            foreach ($this->getModuleModel()->getMetaData() as $key => $val) {
                if ($key == 'shopType') {
                    $metadata->setShopType($val);
                } elseif ($key == 'shopVersion') {
                    $metadata->setShopVersion($val);
                } else {
                    $metadata->addMetadata($key, $val);
                }
            }

            $this->load->model('account/order');

            $paypage->setShopName($this->config->get('config_name'))
                ->setOrderId($orderId)
                ->setAdditionalAttribute('riskData.threatMetrixId', $threatMetrixId)
                ->setAdditionalAttribute('riskData.customerGroup', 'NEUTRAL')
                ->setAdditionalAttribute('riskData.customerId', $order['customer_id'])
                # ->setAdditionalAttribute('riskData.confirmedAmount', UnzerpaymentHelper::getCustomersTotalOrderAmount())
                ->setAdditionalAttribute('riskData.confirmedOrders', $this->customer->isLogged() ? $this->model_account_order->getTotalOrders() : '0')
                ->setAdditionalAttribute('riskData.registrationLevel', $this->customer->isLogged() ? '1' : '0')
                # ->setAdditionalAttribute('riskData.registrationDate', UnzerpaymentHelper::getCustomersRegistrationDate())
            ;

            if (!$this->customer->isLogged()) {
                $paypage->setAdditionalAttribute('disabledCOF', 'card,paypal,sepa-direct-debit');
            }

            foreach (UnzerpaymentsHelper::getInactivePaymentMethods($this->config) as $inactivePaymentMethod) {
                $paypage->addExcludeType($inactivePaymentMethod);
            }

            foreach (UnzerpaymentClient::getAvailablePaymentMethods() as $availablePaymentMethod) {
                if ($selectedPaymentMethod != $availablePaymentMethod->type) {
                    $paypage->addExcludeType($availablePaymentMethod->type);
                }
            }

            UnzerPaymentsHelper::writeLog(
                'check Init Objects',
                1,
                false,

                [
                    'paypage' => $paypage,
                    'unzerCustomer' => $unzerCustomer,
                    'basket' => $basket,
                    'metadata' => $metadata
                ]
            );

            if (UnzerpaymentsHelper::getPaymentMethodChargeMode($this->config, $selectedPaymentMethod) == 'authorize' || UnzerpaymentsHelper::isSandboxMode($this->config)) {
                try {
                    $unzer->initPayPageAuthorize($paypage, $unzerCustomer, $basket, $metadata);
                } catch (\Exception $e) {
                    UnzerPaymentsHelper::writeLog(
                        'initPayPageAuthorize Error',
                        1,
                        $e,
                        [
                            'paypage' => $paypage,
                            'unzerCustomer' => $unzerCustomer,
                            'basket' => $basket,
                            'metadata' => $metadata
                        ]
                    );
                }
            } else {
                try {
                    $unzer->initPayPageCharge($paypage, $unzerCustomer, $basket, $metadata);
                } catch (\Exception $e) {
                    UnzerPaymentsHelper::writeLog('initPayPageCharge Error', 1, $e, [
                        'paypage' => $paypage,
                        'unzerCustomer' => $unzerCustomer,
                        'basket' => $basket,
                        'metadata' => $metadata
                    ]);

                }
            }

            $this->session->data['UnzerPaymentId'] = $paypage->getPaymentId();

            $json = ['token' => $paypage->getId(), 'successURL' => $successURL];
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    /**
     * confirm
     *
     * @return json|string
     */
    public function confirm(): void
    {
        $is_ajax = 'xmlhttprequest' == strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
        $this->load->language('extension/unzer_payments/payment/unzer_payments');

        $json = [];

        if (!isset($this->session->data['order_id'])) {
            $json['error'] = $this->language->get('error_order');
        }

        if (!isset($this->session->data['payment_method']) || substr($this->session->data['payment_method']['code'], 0, 15) != 'unzer_payments.') {
            $json['error'] = $this->language->get('error_payment_method');
        }

        if (!$json) {

            $unzer = UnzerpaymentClient::getInstance(
                $this->config->get('payment_unzer_payments_private_key')
            );

            $selectedPaymentMethod = str_replace('unzer_payments.', '', $this->session->data['payment_method']['code']);

            $payment = $unzer->fetchPayment(
                $this->session->data['UnzerPaymentId']
            );

            /*
            if (!UnzerpaymentsHelper::isValidState($payment->getState())) {
                UnzerpaymentLogger::getInstance()->addLog('Invalid payment state', 2, false, [$payment]);
                $this->errorRedirect();
            }
            */

            if ($payment->getState() == \UnzerSDK\Constants\PaymentState::STATE_COMPLETED) {
                $orderStatus = $this->config->get('payment_unzer_payments_status_captured');
            } else {
                $orderStatus = $this->config->get('payment_unzer_payments_status_order');
            }

            $unzerPaymentInstance = \UnzerSDK\Services\ResourceService::getTypeInstanceFromIdString(
                $payment->getPaymentType()->getId()
            );
            $paymentMethodName = UnzerpaymentsHelper::getPaymentClassNameByFullName(
                $unzerPaymentInstance
            );
            $this->load->model('checkout/order');

            $this->model_checkout_order->addHistory($this->session->data['order_id'], $orderStatus);
            $this->model_checkout_order->editTransactionId($this->session->data['order_id'], $this->session->data['UnzerPaymentId']);

            $metadata = $unzer->fetchMetadata(
                $payment->getMetadata()->getId()
            );
            $metadata->addMetadata(
                'shopOrderId',
                $this->session->data['order_id']
            );
            try {
                $unzer->getResourceService()->updateResource(
                    $metadata
                );
            } catch (\Exception $e) {
                UnzerPaymentsHelper::writeLog('Could not update metadata', 1, $e, [$metadata]);
            }

            unset($this->session->data['UnzerPaymentId']);
            unset($this->session->data['unz_tmx_id']);

			$this->session->data['unzer_order_id'] = $this->session->data['order_id'];

            $json['redirect'] = $this->url->link('checkout/success', 'language=' . $this->config->get('config_language'), true);
            if (!$is_ajax) {
                $this->response->redirect(
                    $json['redirect']
                );
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    /**
     * @param $data
     * @return void
     */
    public function renderJson($data)
    {
        header('Content-Type: application/json');
        echo json_encode($data);
        die;
    }

    /**
     * @return void
     */
    public function notify(): void
    {
        $unzer = UnzerpaymentClient::getInstance(
            $this->config->get('payment_unzer_payments_private_key')
        );
        $jsonRequest = file_get_contents('php://input');
        $data = json_decode($jsonRequest, true);

        if (empty($data)) {
            header("HTTP/1.0 404 Not Found");
            UnzerPaymentsHelper::writeLog('empty webhook call', 1, false, [
                'server' => self::getServerVar()
            ]);
            exit();
        }

        if (!in_array($data['event'], self::REGISTERED_EVENTS, true)) {
            $this->renderJson(
                array(
                    'success' => true,
                    'msg' => 'event not relevant',
                )
            );
        }

        UnzerPaymentsHelper::writeLog('webhook received', 2, false, [
            'data' => $data
        ]);
        if (empty($data['paymentId'])) {
            UnzerPaymentsHelper::writeLog('no payment id in webhook event', 1, false, [
                'data' => $data
            ]);
            exit();
        }

        $orderId = UnzerPaymentsHelper::getOrderIdByTransactionId(
			$this->db,
            $data['paymentId']
        );
        if (empty($orderId)) {
            UnzerPaymentsHelper::writeLog('no order id for webhook event found', 1, false, [
                'data' => $data
            ]);
            exit();
        }

        $eventHash = 'unzer_event_' . md5($data['paymentId'] . '|' . $data['event']);

        switch ($data['event']) {
            case WebhookEvents::CHARGE_CANCELED:
            case WebhookEvents::AUTHORIZE_CANCELED:
                $this->handleCancel($data['paymentId'], $orderId);
                break;
            case WebhookEvents::AUTHORIZE_SUCCEEDED:
                $this->handleAuthorizeSucceeded($data['paymentId'], $orderId);
                break;
            case WebhookEvents::CHARGE_SUCCEEDED:
                $this->handleChargeSucceeded($data['paymentId'], $orderId);
                break;
            case WebhookEvents::PAYMENT_CHARGEBACK:
                $this->handleChargeback($data['paymentId'], $orderId);
                break;
        }
    }

    /**
     * @param $route
     * @param $data
     * @param $template_code
     * @return void
     */
    public function checkoutPaymentMethodController(&$route, &$data, &$template_code)
    {
        if (str_contains($data['code'], 'unzer_payments')) {
            $data['payment_method'] = strip_tags($data['payment_method']);
        }
    }

	/**
	 * @param $route
	 * @param $data
	 * @param $template_code
	 * @return void
	 */
	public function checkoutSuccessController(&$route, &$data, &$template_code)
	{
		if (isset($this->session->data['unzer_order_id'])) {

			$data['unzer_add_invoice_info'] = false;

			$this->load->model('checkout/order');
			$this->load->language('extension/unzer_payments/payment/unzer_payments');

			$order_id = $this->session->data['unzer_order_id'];

			$order_info = $this->model_checkout_order->getOrder($order_id);

			$unzer = UnzerpaymentClient::getInstance(
				$this->config->get('payment_unzer_payments_private_key')
			);
			if ($transaction_id = UnzerpaymentsHelper::getTransactionIdByOrder($this->db, $order_id)) {
				try {
					$payment = $unzer->fetchPayment($transaction_id);
					$paymentId = (\UnzerSDK\Services\IdService::getResourceTypeFromIdString($payment->getPaymentType()->getId()));
					if ($paymentId == 'ppy' || $paymentId == 'piv' || $paymentId == 'ivc') {
						$data['unzer_add_invoice_info'] = true;
						$data['unzer_invoice_trans_amount'] = $this->currency->format($payment->getInitialTransaction()->getAmount(), $order_info['currency_code'], $order_info['currency_value']);
						$data['unzer_account_holder'] = $payment->getInitialTransaction()->getHolder();
						$data['unzer_account_iban'] = $payment->getInitialTransaction()->getIban();
						$data['unzer_account_bic'] = $payment->getInitialTransaction()->getBic();
						$data['unzer_account_descriptor'] = $payment->getInitialTransaction()->getDescriptor();
					}
				} catch (\Exception $e) {
				}

			}
			if ($data['unzer_add_invoice_info']) {
				$data['text_message'] .= '
				  <p>
					' . $this->language->get('unzer_please_transfer') . ' ' . $data['unzer_invoice_trans_amount']. '
				  </p>

				  <p>
					' . $this->language->get('unzer_holder') . ' ' . $data['unzer_account_holder']. '<br>
					' . $this->language->get('unzer_iban') . ' ' . $data['unzer_account_iban']. '<br>
					' . $this->language->get('unzer_bic') . ' ' . $data['unzer_account_bic']. '<br>
					' . $this->language->get('unzer_please_use_identification') . ' ' . $data['unzer_account_descriptor']. '<br>
				  </p>';
			}
		}
	}

    /**
     * Get the order
     *
     * @return array
     */
    protected function getOpenCartOrder($order_id)
    {
        $this->load->model("checkout/order");
        return $this->model_checkout_order->getOrder($order_id);
    }

    /**
     * @param $amount
     * @return string
     */
    public function numberFormat($amount)
    {
        return number_format((float)$amount, 2, '.', '');
    }

    /**
     * @return \Opencart\Catalog\Model\Extension\UnzerPayments\Payment\UnzerPayments
     */
    protected function getModuleModel()
    {
        $this->load->model('extension/unzer_payments/payment/unzer_payments');
        return $this->model_extension_unzer_payments_payment_unzer_payments;
    }

    //Get order products
    protected function getOrderProducts($order_id)
    {
        $model = $this->getModuleModel();

        return $model->getOrderProducts($order_id);
    }

    //Get tax rate
    protected function getTaxRate($tax_rates = array())
    {
        $rates = array();
        if (!empty($tax_rates)) {
            foreach ($tax_rates as $tax) {
                $rates[] = $tax['rate'];
            }
        }
        return $rates;
    }

    //Get Coupon Details
    protected function getCouponDetails($orderID)
    {
        $model = $this->getModuleModel();

        return $model->getCouponDetails($orderID);
    }

    //Get Voucher Details
    protected function getVoucherDetails($orderID)
    {
        $model = $this->getModuleModel();

        return $model->getVoucherDetails($orderID);
    }

    /**
     * @return array
     */
    public static function getServerVar()
    {
        return $_SERVER;
    }

    /**
     * @param $paymentId
     * @param $orderId
     * @return void
     */
    public function handleChargeback($paymentId, $orderId)
    {
        UnzerPaymentsHelper::writeLog('webhook handleChargeback', 3, false, [
            'paymentId' => $paymentId,
            'orderId' => $orderId
        ]);
        if (!(int)$this->config->get('payment_unzer_payments_status_chargeback') > 0) {
            return;
        }
        $this->setOrderStatus(
            $orderId,
            $this->config->get('payment_unzer_payments_status_chargeback')
        );
    }

    /**
     * @param $paymentId
     * @param $orderId
     * @return void
     */
    private function handleCancel($paymentId, $orderId)
    {
        UnzerPaymentsHelper::writeLog('webhook handleCancle', 3, false, [
            'paymentId' => $paymentId,
            'orderId' => $orderId
        ]);
        if (!(int)$this->config->get('payment_unzer_payments_status_cancelled') > 0) {
            return;
        }
        $this->setOrderStatus(
            $orderId,
            $this->config->get('payment_unzer_payments_status_cancelled')
        );
    }

    /**
     * @param $paymentId
     * @param $orderId
     * @return void
     */
    private function handleAuthorizeSucceeded($paymentId, $orderId)
    {
        UnzerPaymentsHelper::writeLog('webhook handleAuthorizeSucceeded', 3, false, [
            'paymentId' => $paymentId,
            'orderId' => $orderId
        ]);
        if (!(int)$this->config->get('payment_unzer_payments_status_order') > 0) {
            return;
        }
        $this->setOrderStatus(
            $orderId,
            $this->config->get('payment_unzer_payments_status_order')
        );
    }

    /**
     * @param $paymentId
     * @param $orderId
     * @return void
     */
    private function handleChargeSucceeded($paymentId, $orderId)
    {
        UnzerPaymentsHelper::writeLog('webhook handleChargeSucceeded', 3, false, [
            'paymentId' => $paymentId,
            'orderId' => $orderId
        ]);
        if (!(int)$this->config->get('payment_unzer_payments_status_captured') > 0) {
            return;
        }
        $this->setOrderStatus(
            $orderId,
            $this->config->get('payment_unzer_payments_status_captured')
        );
    }

    /**
     * @param $orderId
     * @param $orderStatus
     * @return void
     */
    public function setOrderStatus($orderId, $orderStatus)
    {
        $this->load->model('checkout/order');
        $this->model_checkout_order->addHistory($orderId, $orderStatus);
    }


    /**
     * @param $route
     * @param $args
     * @param $output
     * @return void
     */
    public function addHistoryAfter(&$route, &$args, &$output)
    {
        $this->load->model('checkout/order');

        $order_id = $args[0];
        $order_status_id = $args[1];

        $order_info = $this->model_checkout_order->getOrder($order_id);
        if (!empty($order_info)) {
            if (isset($order_info['payment_method']['code']) && substr($order_info['payment_method']['code'], 0, 15) == 'unzer_payments.') {
                $unzer = UnzerpaymentClient::getInstance(
                    $this->config->get('payment_unzer_payments_private_key')
                );
                if ($transaction_id = UnzerpaymentsHelper::getTransactionIdByOrder($this->db, $order_id)) {
                    if ($order_status_id == $this->config->get('payment_unzer_payments_status_autocapture')) {
                        try {
                            $payment = $unzer->fetchPayment($transaction_id);
                            if ($payment->getAmount()->getRemaining() > 0) {
                                $paymentId = (\UnzerSDK\Services\IdService::getResourceTypeFromIdString($payment->getPaymentType()->getId()));
                                if ($paymentId != 'ppy') {
                                    UnzerPaymentsHelper::writeLog('performChargeOnAuthorization Call', 2, false, [
                                        'paymentId' => $transaction_id,
                                        'amount' => $payment->getAmount()->getRemaining()
                                    ]);
                                    $unzer->performChargeOnAuthorization(
                                        $transaction_id,
                                        $payment->getAmount()->getRemaining()
                                    );
                                }
                            }
                        } catch (\Exception $e) {
                            UnzerPaymentsHelper::writeLog('performChargeOnAuthorization Error', 1, $e, [
                                'paymentId' => $transaction_id,
                                'amount' => $payment->getAmount()->getRemaining()
                            ]);
                        }

                    } elseif ($order_status_id == $this->config->get('payment_unzer_payments_status_autorefund')) {
                        try {
                            UnzerPaymentsHelper::writeLog('cancelPayment Call', 2, false, [
                                'paymentId' => $transaction_id
                            ]);
                            $payment = $unzer->fetchPayment($transaction_id);
                            $paymentId = (\UnzerSDK\Services\IdService::getResourceTypeFromIdString($payment->getPaymentType()->getId()));
                            if ($paymentId == 'pdd' || $paymentId == 'piv' || $paymentId == 'pit') {
                                if ($payment->getAmount()->getCharged() > 0) {
                                    $unzer->cancelChargedPayment(
                                        $transaction_id
                                    );
                                } else {
                                    $unzer->cancelAuthorizedPayment(
                                        $transaction_id
                                    );
                                }
                            } else {
                                $unzer->cancelPayment(
                                    $transaction_id
                                );
                            }
                        } catch (\Exception $e) {
                            UnzerPaymentsHelper::writeLog('cancelPayment Error', 1, $e, [
                                'paymentId' => $transaction_id
                            ]);
                        }
                    }
                }
            }
        }
    }

	protected function getTemplateBuffer($route, $event_template_buffer)
	{
		// if there already is a modified template from view/*/before events use that one
		if ($event_template_buffer) {
			return $event_template_buffer;
		}

		$dir_template = DIR_TEMPLATE;

		$template_file = $dir_template . $route . '.twig';

		if (file_exists($template_file) && is_file($template_file)) {
			return file_get_contents($template_file);
		}

		exit;
	}

}
