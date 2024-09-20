<?php

namespace robokassa;

use Yii;
use yii\base\BaseObject;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\httpclient\Client;
use yii\web\Response;

class Merchant extends BaseObject
{
    /**
     * @var string Идентификатор магазина
     */
    public $storeId;

    public $password1;
    public $password2;

    public $isTest = false;

    public $baseUrl = 'https://auth.robokassa.ru/Merchant/Index.aspx';
    public $recurringUrl = 'https://auth.robokassa.ru/Merchant/Recurring';
    public $receiptAttachUrl = 'https://ws.roboxchange.com/RoboFiscal/Receipt/Attach';
    public $receiptStatusUrl = 'https://ws.roboxchange.com/RoboFiscal/Receipt/Status';
    public $smsUrl = 'https://services.robokassa.ru/SMS/';

    public $hashAlgo = 'md5';

    public $tempSignature;


    /**
     * @param PaymentOptions|array $options
     * @return \yii\console\Response|Response
     * @throws InvalidConfigException
     */
    public function payment(PaymentOptions $options)
    {
        $url = $this->getPaymentUrl($options);
        Yii::$app->user->setReturnUrl(Yii::$app->request->getUrl());
        return Yii::$app->response->redirect($url);
    }

    /**
     * @param PaymentOptions|array $options
     * @return string
     */
    public function getPaymentUrl(PaymentOptions $options)
    {
        if (is_array($options)) {
            $options = new PaymentOptions($options);
        }

        $url = $this->baseUrl;

        $url .= '?' . http_build_query(PaymentOptions::paymentParams($this, $options));

        return $url;
    }

    /**
     * @param $shp
     * @return string
     */
    private function buildShp($shp)
    {
        ksort($shp);

        foreach ($shp as $key => $value) {
            $shp[$key] = $key . '=' . $value;
        }

        return implode(':', $shp);
    }

    /**
     * @param PaymentOptions $options
     * @return string
     */
    public function generateSignature(PaymentOptions $options)
    {
        // MerchantLogin:OutSum:Пароль#1
        $signature = "{$this->storeId}:{$options->outSum}";

        if ($options->invId !== null) {
            // MerchantLogin:OutSum:InvId:Пароль#1
            $signature .= ":{$options->invId}";
        }
        if ($options->outSumCurrency !== null) {
            // MerchantLogin:OutSum:InvId:OutSumCurrency:Пароль#1
            $signature .= ":{$options->outSumCurrency}";
        }

        if ($options->userIP !== null) {
            // MerchantLogin:OutSum:InvId:OutSumCurrency:UserIp:Пароль#1
            $signature .= ":{$options->userIP}";
        }

        if (($receipt = $options->getJsonReciept()) !== null) {
            // MerchantLogin:OutSum:InvId:OutSumCurrency:UserIp:Receipt:Пароль#1
            $receipt = urlencode($receipt);
            $signature .= ":{$receipt}";
        }

        $signature .= ":{$this->password1}";

        $shp = $options->getShpParams();
        if (!empty($shp)) {
            $signature .= ':' . $this->buildShp($shp);
        }

        $this->tempSignature = $signature;

        return strtolower($this->encryptSignature($signature));
    }

    /**
     * @param $sSignatureValue
     * @param $nOutSum
     * @param $nInvId
     * @param $sMerchantPass
     * @param array $shp
     * @return bool
     */
    public function checkSignature($sSignatureValue, $nOutSum, $nInvId, $sMerchantPass, $shp = [])
    {
        $signature = "{$nOutSum}:{$nInvId}:{$sMerchantPass}";

        if (!empty($shp)) {
            $signature .= ':' . $this->buildShp($shp);
        }

        return strtolower($this->encryptSignature($signature)) === strtolower($sSignatureValue);
    }

    /**
     * @param $signature
     * @return string
     */
    protected function encryptSignature($signature)
    {
        return hash($this->hashAlgo, $signature);
    }

    /**
     * Send SMS
     *
     * @param string $phone строка, содержащая номер телефона в международном формате без символа «+» (79051234567)
     * @param string $message строка в кодировке UTF-8 длиной до 128 символов, содержащая текст отправляемого SMS.
     * @return \yii\httpclient\Response
     * @throws Exception
     */
    public function sendSMS($phone, $message)
    {
        $phone = preg_replace('/\D+/', '', $phone);
        if (mb_strlen($message) > 128) {
            throw new Exception('`$message` too long');
        }
        $signature = $this->encryptSignature("{$this->storeId}:{$phone}:{$message}:$this->password1");

        return (new Client())->get($this->smsUrl, [
            'login' => $this->storeId,
            'phone' => $phone,
            'message' => $message,
            'signature' => $signature])->send();
    }

    /**
     * Get form html string
     * @param PaymentOptions|array $options
     * @return string
     * @throws InvalidConfigException
     */
    public function getForm(PaymentOptions $options)
    {
        $paymentOptions = PaymentOptions::paymentParams($this, $options);
        $paymentOptions['Receipt'] = urlencode($paymentOptions['Receipt']);

        $html  = <<<HTML
<form action='https://auth.robokassa.ru/Merchant/Index.aspx' method=POST>
<input type=hidden name=MerchantLogin value="{$paymentOptions['MrchLogin']}">
<input type=hidden name=OutSum value="{$paymentOptions['OutSum']}">
<input type=hidden name=InvId value="{$paymentOptions['InvId']}">
<input type=hidden name=Description value="{$paymentOptions['Description']}">
<input type=hidden name=Encoding value="{$paymentOptions['Encoding']}">
<input type=hidden name=SignatureValue value="{$paymentOptions['SignatureValue']}">

HTML;
        foreach ($paymentOptions as $key => $value ){
            if(strstr($key, 'shp_')){
                $name = ucfirst($key);
                $html .= <<<HTML
<input type=hidden name=$name value="$value">
HTML;
            }
        }

        if($paymentOptions['UserIp'] && !empty($paymentOptions['UserIp'])) {
            $html .= <<<HTML
<input type=hidden name=UserIp value="{$paymentOptions['UserIp']}">
HTML;
        }

        $html .= <<<HTML
<input type=hidden name=IncCurrLabel value="{$paymentOptions['IncCurrLabel']}">
<input type=hidden name=Culture value="{$paymentOptions['Culture']}">
<input type=hidden name=Email value="{$paymentOptions['Email']}">
<input type=hidden name=ExpirationDate value="{$paymentOptions['ExpirationDate']}">
<input type=hidden name=Receipt value='{$paymentOptions['Receipt']}'>
<input type=hidden name=IsTest value="{$paymentOptions['IsTest']}">
<input type=submit value='Оплатить'>
</form>
HTML;
        return $html;
    }
}
