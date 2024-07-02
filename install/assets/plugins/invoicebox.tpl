//<?php
/**
 * Payment InvoiceBox
 *
 * InvoiceBox payments processing
 *
 * @category    plugin
 * @version     1.0.0
 * @author      Webber
 * @internal    @events OnRegisterPayments,OnBeforeOrderSending,OnManagerBeforeOrderRender
 * @internal    @properties &title=Title;text; &api_key=API ключ;text;&api_token=API токен для l3;text;&api_version=API version;list;v3==v3||l3==l3;l3 &merchant_id=Merchant ID;text; &vat=НДС;text;0 &debug=Debug;list;No==0||Yes==1;1 
 * @internal    @modx_category Commerce
 * @internal    @installset base
 */

if (empty($modx->commerce) && !defined('COMMERCE_INITIALIZED')) {
    return;
}

$isSelectedPayment = !empty($order['fields']['payment_method']) && $order['fields']['payment_method'] == 'invoicebox';
$commerce = ci()->commerce;
$lang = $commerce->getUserLanguage('invoicebox');

switch ($modx->event->name) {
    case 'OnRegisterPayments': {
        $class = new \Commerce\Payments\Invoicebox($modx, $params);

        if (empty($params['title'])) {
            $params['title'] = $lang['invoicebox.caption'];
        }

        $commerce->registerPayment('invoicebox', $params['title'], $class);
        break;
    }

    case 'OnBeforeOrderSending': {
        if ($isSelectedPayment) {
            $FL->setPlaceholder('extra', $FL->getPlaceholder('extra', '') . $commerce->loadProcessor()->populateOrderPaymentLink());
        }

        break;
    }

    case 'OnManagerBeforeOrderRender': {
        if (isset($params['groups']['payment_delivery']) && $isSelectedPayment) {
            $params['groups']['payment_delivery']['fields']['payment_link'] = [
                'title'   => $lang['invoicebox.link_caption'],
                'content' => function($data) use ($commerce) {
                    return $commerce->loadProcessor()->populateOrderPaymentLink('@CODE:<a href="[+link+]" target="_blank">[+link+]</a>');
                },
                'sort' => 50,
            ];
        }

        break;
    }
}
