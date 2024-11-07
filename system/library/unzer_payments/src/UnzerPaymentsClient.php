<?php

namespace UnzerPaymentsSrc;

use UnzerSDK\Resources\TransactionTypes\AbstractTransactionType;
use UnzerSDK\Resources\TransactionTypes\Authorization;
use UnzerSDK\Resources\TransactionTypes\Cancellation;
use UnzerSDK\Resources\TransactionTypes\Charge;

class UnzerpaymentClient extends \UnzerSDK\Unzer
{
    public static $privateKey = null;

    public static $language = null;
    public static $_instance = null;

    public static function getInstance($privateKey = null, $language = null)
    {
        if (null === self::$_instance) {
            if ($privateKey != '') {
                self::$privateKey = $privateKey;
            }
            if ($language != '') {
                self::$language = $language;
            }
            self::$_instance = new self(
                self::$privateKey,
                self::$language
            );
        }
        return self::$_instance;
    }

    /**
     * @param $paymentId
     * @param $amount
     * @return bool
     */
    public function performChargeOnAuthorization($paymentId, $amount = null)
    {
        $charge = new Charge();
        if ($amount) {
            $charge->setAmount($amount);
        }
        $chargeResult = false;
        try {
            $chargeResult = $this->performChargeOnPayment($paymentId, $charge);
        } catch (\UnzerSDK\Exceptions\UnzerApiException $e) {
            /*
            UnzerpaymentLogger::getInstance()->addLog('performChargeOnPayment Error', 1, $e, [
                'paymentId' => $paymentId,
                'amount' => $amount
            ]);
            */
        } catch (\RuntimeException $e) {
            /*
            UnzerpaymentLogger::getInstance()->addLog('performChargeOnPayment Error', 1, $e, [
                'paymentId' => $paymentId,
                'amount' => $amount
            ]);
            */
        }
        return (bool)$chargeResult;
    }

    /**
     * @return array
     * @throws \UnzerSDK\Exceptions\UnzerApiException
     */
    public static function getAvailablePaymentMethods()
    {
        $unzerClient = self::getInstance();
        if (is_null($unzerClient)) {
            return [];
        }
        $keypairResponse = $unzerClient->fetchKeypair(true);
        $availablePaymentTypes = $keypairResponse->getAvailablePaymentTypes();
        usort($availablePaymentTypes, function ($a, $b) { return strcmp(strtolower($a->type), strtolower($b->type)); });
        foreach ($availablePaymentTypes as $availablePaymentTypeKey => &$availablePaymentType) {
            if ($availablePaymentType->type == 'PIS' || $availablePaymentType->type == 'giropay' || $availablePaymentType->type == 'bancontact') {
                unset($availablePaymentTypes[$availablePaymentTypeKey]);
            }
        }
        return $availablePaymentTypes;
    }

    /**
     * @return array|false
     */
    public function getWebhooksList()
    {
        try {
            $webhooks = $this->fetchAllWebhooks();
            if (sizeof($webhooks) > 0) {
                return $webhooks;
            }
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

}
