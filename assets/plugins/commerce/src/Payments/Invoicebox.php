<?php

namespace Commerce\Payments;

use Invoicebox\Sdk\Client\HttpClient;
use Invoicebox\Sdk\Client\InvoiceboxClient;
use Invoicebox\Sdk\DTO\Order\PrivateCustomer;
use Invoicebox\Sdk\DTO\Order\BasketItem;
use Invoicebox\Sdk\DTO\Enum\VatCode;
use Invoicebox\Sdk\DTO\Enum\BasketItemType;
use Invoicebox\Sdk\DTO\Enum\PaymentType;
use Invoicebox\Sdk\DTO\Order\CreateOrderRequest;
use Invoicebox\Sdk\SignValidator;
use Invoicebox\Sdk\DTO\NotificationResult;

class Invoicebox extends Payment
{
    protected $debug = false;
    protected $client = null;

    public function __construct($modx, array $params = [])
    {
        parent::__construct($modx, $params);
        $this->lang = $modx->commerce->getUserLanguage('invoicebox');
        $this->debug = $this->getSetting('debug') == '1';
        $this->client = new InvoiceboxClient(
            $this->getSetting('api_token'),
            $this->getSetting('api_version'),
            null,
            null,
        );
    }

    public function getMarkup()
    {
        if (empty($this->getSetting('merchant_id')) || empty($this->getSetting('api_key'))) {
            return '<span class="error" style="color: red;">' . $this->lang['invoicebox.error.empty_client_credentials'] . '</span>';
        }
    }

    public function getPaymentLink()
    {
        $processor = $this->modx->commerce->loadProcessor();
        $order     = $processor->getOrder();
        $currency  = ci()->currency->getCurrency($order['currency']);
        $payment   = $this->createPayment($order['id'], $order['amount']);

        $customer = new PrivateCustomer(
            $order['name'],
            preg_replace("/[^0-9]/", '', $order['phone']),
            $order['email'],
        );

        $basketItems = [];
        $items = $processor->getCart()->getItems();
        $discountRate = $this->getDiscountRate($items, $payment);
        
        foreach($items as $item) {
            $price = (float)$item['price'] * $discountRate; //цена полная с учетом скидки в корзине
            $amount = (float)$item['count'] * $price; //стоимость полная
            $price_without_vat = $price / ((100 + (float)$this->getSetting('vat', 0)) / 100); //цена без ндс
            $price_vat = $price - $price_without_vat; //ндс в цене
            $amount_without_vat = $price_without_vat * (float)$item['count']; //стоимость без ндс
            $amount_vat = $price_vat * (float)$item['count']; //общая ндс
            
            $basketItems[] = new BasketItem(
                $item['id'],
                $item['name'],
                'шт.',
                '796',
                $item['count'],
                round($price, 2), //Стоимость единицы
                round($price_without_vat, 2), //Стоимость единицы без учета НДС
                round($amount), //Стоимость всех единиц с НДС
                round($amount_vat), //Итого сумма НДС
                $this->getVatCode(),
                BasketItemType::COMMODITY,
                PaymentType::FULL_PREPAYMENT,
                new \DateTime(date("Y-m-d"))
            );
        }

        $totalAmount = $payment['amount'];
        $amount_without_vat = $totalAmount / ((100 + (float)$this->getSetting('vat', 0)) / 100); //сумма без ндс
        
        $request = new CreateOrderRequest(
            $this->lang['invoicebox.order_description'] . ' ' . $order['id'],
            $this->getSetting('merchant_id'), // id магазина
            $order['id'],
            $payment['amount'], //Сумма заказа с НДС
            ($totalAmount - $amount_without_vat), //Сумма НДС
            $currency['code'],
            new \DateTime('tomorrow'),
            $basketItems,
            $customer,
            null,
            null,
            null,
            MODX_SITE_URL . 'commerce/invoicebox/payment-process?paymentHash=' . $payment['hash'],
            MODX_SITE_URL . 'commerce/invoicebox/payment-success',
            MODX_SITE_URL . 'commerce/invoicebox/payment-failed',
        );

        $result = $this->client->createOrder($request);
        if (!empty($result) && !empty($result->getPaymentUrl())) {
            return $result->getPaymentUrl();
        } else {
            if ($this->debug) {
                $this->modx->logEvent(0, 3, 'Request failed: <pre>' . print_r($request, true) . '</pre><pre>'. print_r($result, true) . '</pre>',
                'Commerce Invoicebox Payment');
            }
        }
        return false;
    }

    public function handleCallback()
    {
        $notificationJson = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? false;

        if(empty($signature)) {
            $this->modx->logEvent(0, 3, 'Signature is empty', 'Commerce InvoiceBox Payment');
            return false;
        }

        if(empty($notificationJson)) {
            $this->modx->logEvent(0, 3, 'NotificationJson is empty', 'Commerce InvoiceBox Payment');
            return false;
        }

        $secretKey = $this->getSetting('api_key');

        $validator = new SignValidator();
        $result = $validator->validate($notificationJson, $secretKey, $signature);
        if ($result->getStatus() == NotificationResult::SUCCESS || $result->getStatus() == 'completed') {
            //Успешная оплата
            $paymentHash = $this->getRequestPaymentHash();
            $processor = $this->modx->commerce->loadProcessor();
            try {
                $payment = $processor->loadPaymentByHash($paymentHash);
                if (!$payment) {
                    throw new Exception('Payment "' . htmlentities(print_r($paymentHash, true)) . '" . not found!');
                }
                try {
                    $processor->processPayment($payment['id'], $payment['amount']);
                    return $this->success();
                } catch (Exception $e) {
                    $this->modx->logEvent(0, 3, 'Payment processPayment failed: ' . $e->getMessage(), 'Commerce InvoiceBox Payment');
                    return $this->error();
                }
            } catch (Exception $e) {
                $this->modx->logEvent(0, 3, 'Payment process failed: ' . $e->getMessage(), 'Commerce InvoiceBox Payment');
                return $this->error();
            }
        }
        return $this->error();
    }

    protected function success()
    {
        $out = [
            'status' => 'success'
        ];
        echo json_encode($out);
        exit();
    }

    protected function error()
    {
        $out = [
            'status' => 'error'
        ];
        echo json_encode($out);
        exit();
    }
    
    public function getRequestPaymentHash()
    {
        if (!empty($_REQUEST['paymentHash']) && is_scalar($_REQUEST['paymentHash'])) {
            return $_REQUEST['paymentHash'];
        }
        return null;
    }

    protected function getVatCode()
    {
        $vat = $this->getSetting('vat');
        switch(true) {
            case $vat === '':
                $code = VatCode::VATNONE;
                break;
            case $vat == 0:
                $code = VatCode::RUS_VAT0;
                break;
            case $vat == 10:
                $code = VatCode::RUS_VAT10;
                break;
            case $vat == 20:
                $code = VatCode::RUS_VAT20;
                break;
            default:
                $code = VatCode::VATNONE;
                break;
        }
        return $code;
    }

    protected function getDiscountRate($items, $payment)
    {
        $amount = 0;
        foreach($items as $item) {
            $amount += (float)$item['price'] * (float)$item['count'];
        }
        if($amount == $payment['amount']) {
            return 1;
        } else {
            return $payment['amount'] / $amount;
        }
    }
    
}
