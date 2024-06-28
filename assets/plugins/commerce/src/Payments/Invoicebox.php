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
            //'flNTccRL3k',
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
        foreach($items as $item) {
            $amount = (float)$item['count'] * (float)$item['price'];
            $basketItems[] = new BasketItem(
                $item['id'],
                $item['name'],
                'шт.',
                '796',
                $item['count'],
                $amount,
                $amount,
                $amount,
                0,
                VatCode::VATNONE,
                BasketItemType::COMMODITY,
                PaymentType::FULL_PREPAYMENT,
                new \DateTime(date("Y-m-d"))
            );
        }

        $request = new CreateOrderRequest(
            $this->lang['invoicebox.order_description'] . ' ' . $order['id'],
            $this->getSetting('merchant_id'), // id магазина
            $order['id'],
            $payment['amount'],
            0,
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
                return $processor->processPayment($payment['id'], $payment['amount']);
            } catch (Exception $e) {
                $this->modx->logEvent(0, 3, 'Payment process failed: ' . $e->getMessage(), 'Commerce InvoiceBox Payment');
                return false;
            }
        }
        return false;
    }

    public function getRequestPaymentHash()
    {
        if (!empty($_REQUEST['paymentHash']) && is_scalar($_REQUEST['paymentHash'])) {
            return $_REQUEST['paymentHash'];
        }

        return null;
    }
}