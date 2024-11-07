<?php

namespace Opencart\Catalog\Model\Extension\UnzerPayments\Payment;

use UnzerPaymentsSrc\UnzerpaymentClient;
use UnzerPaymentsSrc\UnzerPaymentsHelper;

require_once(DIR_EXTENSION . "unzer_payments/system/library/unzer_payments/src/init.php");

class UnzerPayments extends \Opencart\System\Engine\Model
{
    /**
     * getMethods
     *
     * @param  mixed $address
     * @return array
     */
    public function getMethods(array $address = []): array
    {

        UnzerpaymentClient::getInstance(
            $this->config->get('payment_unzer_payments_private_key')
        );

        $this->load->language('extension/unzer_payments/payment/unzer_payments');

        if ($this->cart->hasSubscription()) {
            $status = false;
        } elseif (!$this->cart->hasShipping()) {
            $status = false;
        } elseif (!$this->config->get('config_checkout_payment_address')) {
            $status = true;
        } elseif (!$this->config->get('payment_unzer_payments_geo_zone_id')) {
            $status = true;
        } else {
            // getting payment data using zeo zone
            $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "zone_to_geo_zone` WHERE `geo_zone_id` = '" . (int)$this->config->get('payment_unzer_payments_geo_zone_id') . "' AND `country_id` = '" . (int)$address['country_id'] . "' AND (`zone_id` = '" . (int)$address['zone_id'] . "' OR `zone_id` = '0')");

            // if the rows found the status set to True
            if ($query->num_rows) {
                $status = true;
            } else {
                $status = false;
            }
        }

        $method_data = [];
        $option_data = [];

        if ($status) {

            foreach (UnzerpaymentClient::getAvailablePaymentMethods() as $paymentMethod) {
                $paymentType = $paymentMethod->type;
                $current_currency = $this->session->data['currency'];
                $currencyOK = false;
                if (isset($paymentMethod->supports[0]->currency)) {
                    foreach ($paymentMethod->supports[0]->currency as $currency_code) {
                        if ($currency_code == $current_currency) {
                            $currencyOK = true;
                        }
                    }
                }
                if ($currencyOK && $this->config->get('payment_unzer_payments_payment_methods_' . $paymentType . '_status')) {
                    $option_data[$paymentType] = [
                        'code' => 'unzer_payments.' . $paymentType,
                        'name' => (APPLICATION == 'Admin' ? '' : '<img src="' . HTTP_SERVER . 'extension/unzer_payments/image/icons/' . $this->getIconName($paymentType) . '.png" class="unzer-payment-method-icon" /> ') . $this->language->get($paymentType),
                    ];
                }
            }
            if (sizeof($option_data)) {

                $method_data = [
                    'code'       => 'unzer_payments',
                    'name'       => $this->language->get('heading_title'),
                    'option'     => $option_data,
                    'sort_order' => $this->config->get('payment_unzer_payments_sort_order')
                ];
            }
        }

        return $method_data;
    }

    /**
     * @return array
     */
    public function getMetaData()
    {
        return [
            'shopType' => 'OpenCart',
            'shopVersion' => VERSION,
            'pluginVersion' => UnzerPaymentsHelper::PLUGIN_VERSION,
            'pluginType' => 'unzerdev/opencart4'
        ];
    }


    /**
     * @param $paymentType
     * @return string
     */
    private function getIconName($paymentType)
    {
        $iconName = strtolower($paymentType);
        if (substr($iconName, 0, 11) == 'postfinance' || substr($iconName, 0, 12) == 'post-finance') {
            $iconName = 'postfinance';
        }
        return $iconName;
    }

    public function getOrderProducts($order_id)
    {
        $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_product WHERE order_id = '" . (int)$order_id . "'");

        return $query->rows;
    }

    public function getCouponDetails($orderID)
    {
        $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_total WHERE order_id = '" . (int)$orderID . "' AND code = 'coupon'");
        return $query->row;
    }

    public function getVoucherDetails($orderID)
    {
        $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_total WHERE order_id = '" . (int)$orderID . "' AND code = 'voucher'");
        return $query->row;
    }

    public function getRewardPointDetails($orderID)
    {
        $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_total WHERE order_id = '" . (int)$orderID . "' AND code = 'reward'");
        return $query->row;
    }

    public function getOtherOrderTotals($orderID)
    {
        $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_total WHERE order_id = '" . (int)$orderID . "' AND code != 'shipping' AND code != 'tax' AND code != 'voucher' AND code != 'sub_total' AND code != 'coupon' AND code != 'reward' AND code != 'total'");

        return $query->rows;
    }
}
