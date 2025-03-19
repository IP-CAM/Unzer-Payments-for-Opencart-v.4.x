<?php

namespace UnzerPaymentsSrc;

use Opencart\System\Library\Url;
use UnzerSDK\Resources\TransactionTypes\AbstractTransactionType;
use UnzerSDK\Resources\TransactionTypes\Authorization;
use UnzerSDK\Resources\TransactionTypes\Cancellation;
use UnzerSDK\Resources\TransactionTypes\Charge;
use UnzerSDK\Resources\TransactionTypes\Chargeback;

class UnzerPaymentsHelper
{
    public const PLUGIN_VERSION = '1.1.0';

    private static $loglevels = [
        1 => 'ERROR',
        2 => 'INFO',
        3 => 'DEBUG'
    ];


    /**
     * @return string
     */
    public static function getNotifyUrl()
    {
        $url = new Url(defined('\HTTP_CATALOG') ? \HTTP_CATALOG : \HTTP_SERVER);
        return $url->link(
            "extension/unzer_payments/payment/unzer_payments" . self::getMethodSeparator() . "notify",
            '',
            true
        );
    }

    /**
     * @return string
     */
    public static function getSuccessUrl($params = [])
    {
        $url = new Url(defined('\HTTP_CATALOG') ? \HTTP_CATALOG : \HTTP_SERVER);
        return $url->link(
            "extension/unzer_payments/payment/unzer_payments" . self::getMethodSeparator() . "confirm",
            $params,
            true
        );
    }

    /**
     * @return string
     */
    public static function getFailureUrl($params = [])
    {
        $url = new Url(defined('\HTTP_CATALOG') ? \HTTP_CATALOG : \HTTP_SERVER);
        return $url->link(
            "extension/unzer_payments/payment/unzer_payments" . self::getMethodSeparator() . "failure",
            $params,
            true
        );
    }

    /**
     * @param $string
     * @param $capitalizeFirstCharacter
     * @return array|string|string[]
     */
    public static function dashesToCamelCase($string, $capitalizeFirstCharacter = false)
    {

        $str = str_replace('-', '', ucwords($string, '-'));

        if (!$capitalizeFirstCharacter) {
            $str = lcfirst($str);
        }

        return $str;
    }

    /**
     * @param $paymentRessourceClassName
     * @return bool
     */
    public static function paymentMethodCanAuthorize($paymentRessourceClassName)
    {
        if (class_exists("UnzerSDK\Resources\PaymentTypes\\" . $paymentRessourceClassName)) {
            if (method_exists("UnzerSDK\Resources\PaymentTypes\\" . $paymentRessourceClassName, "authorize")) {
                return true;
            }
        };
        return false;
    }

    /**
     * @param $config
     * @return false
     */
    public static function isSandboxMode($config)
    {
        return false;
    }

    /**
     * @return string
     */
    public static function getMethodSeparator()
    {
        $method_separator = '|';

        if (version_compare(VERSION, '4.0.2.0', '>=')) {
            $method_separator = '.';
        }

        return $method_separator;
    }

    /**
     * @return array
     * @throws \UnzerSDK\Exceptions\UnzerApiException
     */
    public static function getInactivePaymentMethods($config)
    {
        $inactive_payment_methods = ['giropay', 'PIS', 'bancontact'];
        $methods = UnzerpaymentClient::getAvailablePaymentMethods();
        foreach ($methods as $method) {
            $paymentType = $method->type;
            if (!$config->get('payment_unzer_payments_payment_methods_' . $paymentType . '_status')) {
                $inactive_payment_methods[] = $method->type;
            }
        }
        return $inactive_payment_methods;
    }

    /**
     * @param $paymentRessourceClassName
     * @return string
     */
    public static function getPaymentMethodChargeMode($config, $paymentRessourceClassName)
    {
        if ($config->get('payment_unzer_payments_payment_methods_' . $paymentRessourceClassName . '_charge_mode') == 'authorize') {
            return 'authorize';
        }
        return 'charge';
    }


    /**
     * @param $fullName
     * @return string
     * @throws \ReflectionException
     */
    public static function getPaymentClassNameByFullName($fullName)
    {
        return (new \ReflectionClass($fullName))->getShortName();
    }

    /**
     * @return \Opencart\System\Library\Log
     */
    public static function getLogger()
    {
        return new \Opencart\System\Library\Log('UnzerPayments.log');
    }

    /**
     * @param $message
     * @param $loglevel
     * @param $exception
     * @param $dataarray
     * @return void
     */
    public static function writeLog($message, $loglevel = 3, $exception = false, $dataarray = [])
    {
        $backtrace = debug_backtrace();
        $fileinfo = '';
        $callsinfo = '';
        if (!empty($backtrace[0]) && is_array($backtrace[0])) {
            if (isset($backtrace[0]['file']) && isset($backtrace[0]['line'])) {
                $fileinfo = $backtrace[0]['file'] . ": " . $backtrace[0]['line'];
                for ($x = 1; $x < 5; $x++) {
                    if (!empty($backtrace[$x]) && is_array($backtrace[$x])) {
                        if (isset($backtrace[$x]['file']) && isset($backtrace[$x]['line'])) {
                            $callsinfo .= "\r\n" . $backtrace[$x]['file'] . ": " . $backtrace[$x]['line'];
                        }
                    }
                }
            }
        }
        $logstr = date("Y-m-d H:i:s");
        $logstr .= ' [' . self::$loglevels[$loglevel] . '] ';
        $logstr .= $message;
        $logstr .= ' - ' . $fileinfo;
        $logstr .= "\r\n";
        $logstr .= 'URL: ' . self::getServerVar()['REQUEST_URI'];
        $logstr .= "\r\n";
        if ($callsinfo != '') {
            $logstr .= 'Backtrace :';
            $logstr .= $callsinfo . "\r\n";
        }
        self::getLogger()->write($logstr);
        if ($exception) {
            $exceptionlog = 'Exception thrown: ';
            $exceptionlog .= $exception->getCode() . ': ' . $exception->getMessage() . ' - ';
            $exceptionlog .= $exception->getFile() . ': ' . $exception->getLine();
            $exceptionlog .= "\r\n";
            self::getLogger()->write($exceptionlog);
        }
        if (sizeof($dataarray) > 0) {
            if (isset($dataarray['response'])) {
                $response = json_decode($dataarray['response'], true);
                if (is_array($response)) {
                    $dataarray['response'] = self::cleanUp($response);
                }
            }
            $arraylog = 'Data-Array :';
            $arraylog .= "\r\n";
            $arraylog .= print_r($dataarray, true);
            $arraylog .= "\r\n";
            self::getLogger()->write($arraylog);
        }

    }

    /**
     * @return array
     */
    public static function getServerVar()
    {
        return $_SERVER;
    }

    /**
     * @param array $data
     * @return array
     */
    protected static function cleanUp(array $data)
    {
        foreach ($data as $d => $v) {
            if (in_array($d, ['buyer', 'billingAddress', 'shippingAddress', 'name', 'email', 'postalCode', 'countryCode', 'buyerId'])) {
                $data[$d] = '......';
            } else {
                if (is_array($v)) {
                    $data[$d] = self::cleanUp($v);
                } else {
                    $data[$d] = $v;
                }
            }
        }
        return $data;
    }

    /**
     * @param $db
     * @param $order_id
     * @return mixed
     */
    public static function getTransactionIdByOrder($db, $order_id)
    {
        $query = $db->query("SELECT transaction_id FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . (int)$order_id . "'");

        return $query->row['transaction_id'];
    }

	/**
	 * @param $db
	 * @param $order_id
	 * @return mixed
	 */
	public static function getOrderIdByTransactionId($db, $transaction_id)
	{
		$query = $db->query("SELECT order_id FROM `" . DB_PREFIX . "order` WHERE `transaction_id` = '" . $transaction_id . "'");

		return $query->row['order_id'];
	}



    /**
     * @param $payment_id
     * @param $order
     * @return array
     */
    public static function getTransactions($payment_id, $order, $controller)
    {
        $unzer = UnzerpaymentClient::getInstance();
        $payment = $unzer->fetchPayment($payment_id);
        $currency     = $payment->getCurrency();
        $transactions = array();
        if ($payment->getAuthorization()) {
            $transactions[] = $payment->getAuthorization();
            if ($payment->getAuthorization()->getCancellations()) {
                $transactions = array_merge($transactions, $payment->getAuthorization()->getCancellations());
            }
        }
        if ($payment->getCharges()) {
            foreach ($payment->getCharges() as $charge) {
                $transactions[] = $charge;
                if ($charge->getCancellations()) {
                    $transactions = array_merge($transactions, $charge->getCancellations());
                }
            }
        }
        if ($payment->getReversals()) {
            foreach ($payment->getReversals() as $reversal) {
                $transactions[] = $reversal;
            }
        }
        if ($payment->getRefunds()) {
            foreach ($payment->getRefunds() as $refund) {
                $transactions[] = $refund;
            }
        }
		if ($payment->getChargebacks()) {
			foreach ($payment->getChargebacks() as $chargeback) {
				$transactions[] = $chargeback;
			}
		}
        $transactionTypes = array(
            Cancellation::class  => 'cancellation',
			Chargeback::class	 => 'chargeback',
            Charge::class        => 'charge',
            Authorization::class => 'authorization',
        );
        $transactions = array_map(
            function (AbstractTransactionType $transaction) use ($transactionTypes, $controller, $order) {
                $return         = $transaction->expose();
                $class          = get_class($transaction);
                $return['type'] = $transactionTypes[ $class ] ?? $class;
                $return['time'] = $transaction->getDate();
                $return['amount'] = $controller->currency->format($return['amount'], $order['currency_code'], $order['currency_value']);
                $status           = $transaction->isSuccess() ? 'success' : 'error';
                $status           = $transaction->isPending() ? 'pending' : $status;
                $return['status'] = $status;

                return $return;
            },
            $transactions
        );
        usort(
            $transactions,
            function ($a, $b) {
                return strcmp($a['time'], $b['time']);
            }
        );
        $data = array(
            'id'                => $payment->getId(),
            'paymentMethod'     => $order['payment_method']['name'],
            'paymentBaseMethod' => \UnzerSDK\Services\IdService::getResourceTypeFromIdString($payment->getPaymentType()->getId()),
            'shortID'           => $payment->getInitialTransaction()->getShortId(),
			'amountPlain'		=> $payment->getAmount()->getTotal(),
            'amount'			=> $controller->currency->format($payment->getAmount()->getTotal(), $order['currency_code'], $order['currency_value']),
            'charged'           => $controller->currency->format($payment->getAmount()->getCharged(), $order['currency_code'], $order['currency_value']),
            'cancelled'         => $controller->currency->format($payment->getAmount()->getCanceled(), $order['currency_code'], $order['currency_value']),
			'cancelledPlain'    => $payment->getAmount()->getCanceled(),
            'remaining'         => $controller->currency->format($payment->getAmount()->getRemaining(), $order['currency_code'], $order['currency_value']),
            'remainingPlain'    => $payment->getAmount()->getRemaining(),
			'refundablePlain'   => $payment->getAmount()->getTotal() - $payment->getAmount()->getCanceled(),
            'transactions'      => $transactions,
            'status'            => $payment->getStateName(),
            'raw'               => print_r($payment, true),
        );
        return $data;
    }




}
