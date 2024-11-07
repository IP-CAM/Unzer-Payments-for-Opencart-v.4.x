<?php

namespace Opencart\Admin\Controller\Extension\UnzerPayments\Payment;

use UnzerPaymentsSrc\UnzerpaymentClient;
use UnzerPaymentsSrc\UnzerPaymentsHelper;
use UnzerSDK\Resources\TransactionTypes\Cancellation;

require_once(DIR_EXTENSION . "unzer_payments/system/library/unzer_payments/src/init.php");

class UnzerPayments extends \Opencart\System\Engine\Controller
{
    public function install(): void
    {
        $this->load->model('setting/event');

        $events = [
            "unzer",
            "unzer_checkout_controller",
            "unzer_order_info_controller",
            "unzer_order_info_template",
            "unzer_add_history_after",
            "unzer_order_invoice_controller",
            "unzer_order_invoice_template",
            "unzer_payment_method_controller",
			"unzer_checkout_success_controller"
        ];

        foreach ($events as $event_code) {
            $this->model_setting_event->deleteEventByCode($event_code);
        }

        $event_data = [
            0 => [
                "code" => "unzer_checkout_controller",
                "description" => "Unzer Payments - Add unzer data on checkout controller",
                "trigger" => "catalog/controller/checkout/checkout/before",
                "action" => 'extension/unzer_payments/payment/unzer_payments' . UnzerPaymentsHelper::getMethodSeparator() . 'checkoutController',
                "status" => 1,
                "sort_order" => 0
            ],
            1 => [
                "code" => "unzer_order_info_controller",
                "description" => "Unzer Payments - Add unzer data to order controller",
                "trigger" => "admin/view/sale/order_info/before",
                "action" => 'extension/unzer_payments/payment/unzer_payments' . UnzerPaymentsHelper::getMethodSeparator() . 'orderController',
                "status" => 1,
                "sort_order" => 0
            ],
            2 => [
                "code" => "unzer_order_info_template",
                "description" => "Unzer Payments - Add unzer data to order info template",
                "trigger" => "admin/view/sale/order_info/before",
                "action" => 'extension/unzer_payments/payment/unzer_payments' . UnzerPaymentsHelper::getMethodSeparator() . 'orderInfoTemplate',
                "status" => 1,
                "sort_order" => 0
            ],
            3 => [
                "code" => "unzer_add_history_after",
                "description" => "Unzer Payments",
                "trigger" => "catalog/model/checkout/order/addHistory/after",
                "action" => 'extension/unzer_payments/payment/unzer_payments' . UnzerPaymentsHelper::getMethodSeparator() . 'addHistoryAfter',
                "status" => 1,
                "sort_order" => 0
            ],
            4 => [
                "code" => "unzer_order_invoice_controller",
                "description" => "Unzer Payments - Add unzer data to invoice controller",
                "trigger" => "admin/view/sale/order_invoice/before",
                "action" => 'extension/unzer_payments/payment/unzer_payments' . UnzerPaymentsHelper::getMethodSeparator() . 'invoiceController',
                "status" => 1,
                "sort_order" => 0
            ],
            5 => [
                "code" => "unzer_order_invoice_template",
                "description" => "Unzer Payments - Add unzer data to order invoice template",
                "trigger" => "admin/view/sale/order_invoice/before",
                "action" => 'extension/unzer_payments/payment/unzer_payments' . UnzerPaymentsHelper::getMethodSeparator() . 'orderInvoiceTemplate',
                "status" => 1,
                "sort_order" => 0
            ],
            6 => [
                "code" => "unzer_payment_method_controller",
                "description" => "Unzer Payments",
                "trigger" => "catalog/view/checkout/payment_method/before",
                "action" => 'extension/unzer_payments/payment/unzer_payments' . UnzerPaymentsHelper::getMethodSeparator() . 'checkoutPaymentMethodController',
                "status" => 1,
                "sort_order" => 0
            ],
			7 => [
				"code" => "unzer_checkout_success_controller",
				"description" => "Unzer Payments",
				"trigger" => "catalog/view/common/success/before",
				"action" => 'extension/unzer_payments/payment/unzer_payments' . UnzerPaymentsHelper::getMethodSeparator() . 'checkoutSuccessController',
				"status" => 1,
				"sort_order" => 0
			]
        ];

        foreach ($event_data as $event) {
            $this->model_setting_event->addEvent($event);
        }

        $this->config->set('config_session_samesite', 'Lax');
    }

    /**
     * index
     *
     * @return void
     */
    public function index(): void
    {
        $this->load->language('extension/unzer_payments/payment/unzer_payments');

        $this->document->setTitle($this->language->get('heading_title'));

        $data['breadcrumbs'] = [];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])
        ];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment')
        ];

        if (!isset($this->request->get['module_id'])) {
            $data['breadcrumbs'][] = [
                'text' => $this->language->get('heading_title'),
                'href' => $this->url->link('extension/unzer_payments/payment/unzer_payments', 'user_token=' . $this->session->data['user_token'])
            ];
        } else {
            $data['breadcrumbs'][] = [
                'text' => $this->language->get('heading_title'),
                'href' => $this->url->link('extension/unzer_payments/payment/unzer_payments', 'user_token=' . $this->session->data['user_token'] . '&module_id=' . $this->request->get['module_id'])
            ];
        }

        $data['save'] = $this->url->link('extension/unzer_payments/payment/unzer_payments.save', 'user_token=' . $this->session->data['user_token']);
        $data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment');

        // getting payment extension config
        $data['payment_unzer_payments_order_status_id'] = $this->config->get('payment_unzer_payments_order_status_id');

        // loading order status model
        $this->load->model('localisation/order_status');

        // getting order status as array
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        $data['payment_unzer_payments_status'] = $this->config->get('payment_unzer_payments_status');

        $data['order_status_settings'] = [
            'payment_unzer_payments_status_order' => $this->config->get('payment_unzer_payments_status_order'),
            'payment_unzer_payments_status_captured' => $this->config->get('payment_unzer_payments_status_captured'),
            'payment_unzer_payments_status_cancelled' => $this->config->get('payment_unzer_payments_status_cancelled'),
            'payment_unzer_payments_status_chargeback' => $this->config->get('payment_unzer_payments_status_chargeback'),
            'payment_unzer_payments_status_autocapture' => $this->config->get('payment_unzer_payments_status_autocapture'),
            'payment_unzer_payments_status_autorefund' => $this->config->get('payment_unzer_payments_status_autorefund'),
        ];

        $data['payment_unzer_payments_private_key'] = $this->config->get('payment_unzer_payments_private_key');
        $data['payment_unzer_payments_public_key'] = $this->config->get('payment_unzer_payments_public_key');

        if ($this->config->get('payment_unzer_payments_private_key') != '') {
            $UnzerpaymentClient = UnzerpaymentClient::getInstance(
                $this->config->get('payment_unzer_payments_private_key')
            );

            if (isset($this->request->get['del_webhook_id']) && $this->request->get['del_webhook_id'] != '') {
                try {
                    $UnzerpaymentClient->deleteWebhook(
                        $this->request->get['del_webhook_id']
                    );
                } catch (\Exception $e) {
                }

            }

            $data['payment_unzer_payments_payment_methods'] = [];
            foreach (UnzerpaymentClient::getAvailablePaymentMethods() as $paymentMethod) {
                $paymentType = $paymentMethod->type;
                $data['payment_unzer_payments_payment_methods'][$paymentType] = [
                    'status' => $this->config->get('payment_unzer_payments_payment_methods_' . $paymentType . '_status')
                ];
                $paymentRessourceClassName = UnzerPaymentsHelper::dashesToCamelCase(
                    $paymentType,
                    true
                );
                if (UnzerPaymentsHelper::paymentMethodCanAuthorize($paymentRessourceClassName)) {
                    $data['payment_unzer_payments_payment_methods'][$paymentType]['charge_mode'] =
                        $this->config->get('payment_unzer_payments_payment_methods_' . $paymentType . '_charge_mode');
                }
            }

            $this->registerWebhooks();

            $data['webhooks'] = UnzerpaymentClient::getInstance()->getWebhooksList();

            if (!isset($this->request->get['module_id'])) {
                $data['webhook_delete_url'] =  $this->url->link('extension/unzer_payments/payment/unzer_payments', 'user_token=' . $this->session->data['user_token'] . '&del_webhook_id=UNZ_WEBHOOK_ID');
            } else {
                $data['webhook_delete_url'] = $this->url->link('extension/unzer_payments/payment/unzer_payments', 'user_token=' . $this->session->data['user_token'] . '&module_id=' . $this->request->get['module_id'] . '&del_webhook_id=UNZ_WEBHOOK_ID');
            }

        }

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/unzer_payments/payment/unzer_payments', $data));
    }

    /**
     * save method
     *
     * @return void
     */
    public function save(): void
    {
        $this->load->language('extension/unzer_payments/payment/unzer_payments');

        $json = [];

        if (!$this->user->hasPermission('modify', 'extension/unzer_payments/payment/unzer_payments')) {
            $json['error']['warning'] = $this->language->get('error_permission');
        }

        if (!$json) {
            $this->load->model('setting/setting');

            $this->model_setting_setting->editSetting('payment_unzer_payments', $this->request->post);

            $json['success'] = $this->language->get('text_success');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }


    protected function registerWebhooks()
    {
        UnzerpaymentClient::getInstance(
            $this->config->get('payment_unzer_payments_private_key')
        );
        try {
            if (is_null(UnzerpaymentClient::getInstance())) {
                return [];
            }
            UnzerpaymentClient::getInstance()->createWebhook(
                UnzerPaymentsHelper::getNotifyUrl(),
                'all'
            );
        } catch (\Exception $e) {
			// silent
        }
    }

    /**
     * @param $route
     * @param $data
     * @param $template_code
     * @return void
     */
    public function orderController(&$route, &$data, &$template_code)
    {
        $this->load->model('sale/order');
        $this->load->language('extension/unzer_payments/payment/unzer_payments');

        $order_id = (int)$data['order_id'];

        $order_info = $this->model_sale_order->getOrder($order_id);

		$do_redirect = false;

        if (!empty($order_info) && (int)$order_info['store_id'] >= 0) {
            if (isset($order_info['payment_method']['code']) && substr($order_info['payment_method']['code'], 0, 15) == 'unzer_payments.') {
                UnzerpaymentClient::getInstance(
                    $this->config->get('payment_unzer_payments_private_key')
                );
                if ($transaction_id = UnzerpaymentsHelper::getTransactionIdByOrder($this->db, $order_id)) {

					if (isset($this->request->post['unzer_action']) && $this->request->post['unzer_action'] == 'unzer_capture') {
						$amount  = $this->request->post['unzer_capture_amount'] ? (float)$this->request->post['unzer_capture_amount'] : null;
						UnzerpaymentClient::getInstance()->performChargeOnAuthorization($transaction_id, $amount);
						$do_redirect = true;
					} elseif (isset($this->request->post['unzer_action']) && $this->request->post['unzer_action'] == 'unzer_cancel') {
						$amount  = $this->request->post['unzer_cancel_amount'] ? (float)$this->request->post['unzer_cancel_amount'] : null;
						try {
							UnzerpaymentClient::getInstance()->cancelPayment($transaction_id, $amount);
						} catch (\Exception $e) {
							if (str_contains($e->getMessage(), 'cancelAuthorizedPayment') || str_contains($e->getMessage(), 'cancelChargedPayment')) {
								$cancellation = new Cancellation($amount);
								try {
									UnzerpaymentClient::getInstance()->cancelAuthorizedPayment(
										$transaction_id,
										$cancellation
									);
								} catch (\Exception $e) {
									try {
										UnzerpaymentClient::getInstance()->cancelChargedPayment(
											$transaction_id,
											$cancellation
										);
									} catch (\Exception $e) {
										// silent
									}
								}
							}
						}

						$do_redirect = true;
					}

					if ($do_redirect && isset($this->request->get['route'])) {
						$this->response->redirect(
							$this->url->link(
								$this->request->get['route']
								,
								'user_token=' . $this->session->data['user_token'] . '&order_id=' . $order_id . '#unzerTransactions',
								true
							)
						);
					}

                    $transactions = UnzerpaymentsHelper::getTransactions($transaction_id, $order_info, $this);
                    $data['unzer_transactions'] = $transactions;
                }
            }
        }
    }

    /**
     * @param $route
     * @param $data
     * @param $template_code
     * @return null
     */
    public function orderInfoTemplate(&$route, &$data, &$template_code)
    {
        $template_buffer = $this->getTemplateBuffer($route, $template_code);

        $search  = [
            '<div class="card mb-3">
      <div class="card-header"><i class="fa-solid fa-comment"></i> {{ text_history }}</div>',
        ];

        $replace = [
            file_get_contents(DIR_EXTENSION . 'unzer_payments/admin/view/template/payment/unzer_payments_order_info_payment.twig') . '<div class="card mb-3">
      <div class="card-header"><i class="fa-solid fa-comment"></i> {{ text_history }}</div>',
        ];

        $template_buffer = str_replace($search, $replace, $template_buffer);

        $template_code = $template_buffer;

        return null;
    }

    /**
     * @param $route
     * @param $data
     * @param $template_code
     * @return void
     */
    public function invoiceController(&$route, &$data, &$template_code)
    {
        $this->load->model('sale/order');
        $this->load->language('extension/unzer_payments/payment/unzer_payments');

        $order_id = (int)$data['orders'][0]['order_id'];

        $order_info = $this->model_sale_order->getOrder($order_id);

        if (!empty($order_info) && (int)$order_info['store_id'] >= 0) {
            if (isset($order_info['payment_method']['code']) && substr($order_info['payment_method']['code'], 0, 15) == 'unzer_payments.') {
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
            }
        }
    }

    /**
     * @param $route
     * @param $data
     * @param $template_code
     * @return null
     */
    public function orderInvoiceTemplate(&$route, &$data, &$template_code)
    {
        $template_buffer = $this->getTemplateBuffer($route, $template_code);

        $search  = [
            '{% if order.comment %}',
        ];

        $replace = [
            file_get_contents(DIR_EXTENSION . 'unzer_payments/admin/view/template/payment/unzer_payments_invoice.twig') . ' {% if order.comment %}',
        ];

        $template_buffer = str_replace($search, $replace, $template_buffer);

        $template_code = $template_buffer;

        return null;
    }

    // return template file contents as a string
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
