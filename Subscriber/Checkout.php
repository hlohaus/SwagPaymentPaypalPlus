<?php
/*
 * (c) shopware AG <info@shopware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Shopware\SwagPaymentPaypalPlus\Subscriber;

use \Enlight_Controller_Action as ControllerAction;
use \Shopware_Plugins_Frontend_SwagPaymentPaypalPlus_Bootstrap as Bootstrap;
use \Shopware_Plugins_Frontend_SwagPaymentPaypal_Bootstrap as PaypalBootstrap;

/**
 * Class Checkout
 * @package Shopware\SwagPaymentPaypal\Subscriber
 */
class Checkout
{
    /**
     * @var Bootstrap
     */
    protected $bootstrap;

    /**
     * @var PaypalBootstrap
     */
    protected $paypalBootstrap;

    protected $config;
    protected $session;

    /**
     * @var \Shopware_Components_Paypal_RestClient
     */
    protected $restClient;

    public function __construct(Bootstrap $bootstrap)
    {
        $this->bootstrap = $bootstrap;
        $this->paypalBootstrap = $bootstrap->Collection()->get('SwagPaymentPaypal');
        $this->config = $this->paypalBootstrap->Config();
        $this->session = $bootstrap->get('session');
        $this->restClient = $bootstrap->get('paypalRestClient');
        $this->restClient->setHeaders('PayPal-Partner-Attribution-Id', 'ShopwareAG_Cart_PayPalPlus_1017');
    }

    public static function getSubscribedEvents()
    {
        return array(
            'Enlight_Controller_Action_PostDispatch_Frontend_Checkout' => 'onPostDispatchCheckout'
        );
    }

    /**
     * @return string
     */
    protected function getCurrency()
    {
        return $this->bootstrap->get('currency')->getShortName();
    }

    /**
     * @param $basket
     * @param $user
     * @return string
     */
    protected function getTotalAmount($basket, $user)
    {
        if (!empty($user['additional']['charge_vat'])) {
            return empty($basket['AmountWithTaxNumeric']) ? $basket['AmountNumeric'] : $basket['AmountWithTaxNumeric'];
        } else {
            return $basket['AmountNetNumeric'];
        }
    }

    /**
     * @param $basket
     * @param $user
     * @return mixed
     */
    protected function getTotalShipment($basket, $user)
    {
        if (!empty($user['additional']['charge_vat'])) {
            return $basket['sShippingcostsWithTax'];
        } else {
            return str_replace(',', '.', $basket['sShippingcosts']);
        }
    }

    /**
     * @param $basket
     * @return mixed
     */
    protected function getTotalSub($basket)
    {
        $total = 0;
        foreach ($basket['content'] as $basketItem) {
            $unitPrice = round(str_replace(',', '.', $basketItem['price']), 2);
            $total += $unitPrice * $basketItem['quantity'];
        }
        return $total;
    }

    /**
     * @param $user
     * @return array
     */
    protected function getShippingAddress($user)
    {
        $address = array(
            'recipient_name' => $user['shippingaddress']['firstname'] . ' ' . $user['shippingaddress']['lastname'],
            'line1' => trim($user['shippingaddress']['street'] . ' ' . $user['shippingaddress']['streetnumber']),
            'city' => $user['shippingaddress']['city'],
            'postal_code' => $user['shippingaddress']['zipcode'],
            'country_code' => $user['additional']['countryShipping']['countryiso'],
        );
        return $address;
    }

    /**
     * @param $basket
     * @return array
     */
    protected function getItemList($basket, $user)
    {
        $list = array();
        $currency = $this->getCurrency();
        foreach ($basket['content'] as $basketItem) {
            if (!empty($user['additional']['charge_vat']) && !empty($basketItem['amountWithTax'])) {
                $amount = round($basketItem['amountWithTax'], 2);
                $quantity = 1;
            } else {
                $amount = str_replace(',', '.', $basketItem['amount']);
                $quantity = (int)$basketItem['quantity'];
                $amount = $amount / $basketItem['quantity'];
            }
            $amount = round($amount, 2);
            $list[] = array(
                'name' => $basketItem['articlename'],
                'sku' => $basketItem['ordernumber'],
                'price' => number_format($amount, 2, '.', ','),
                'currency' => $currency,
                'quantity' => $quantity,
            );
        }
        return $list;
    }

    /**
     * @return array
     */
    protected function getProfile()
    {
        if (!isset($this->session['PaypalProfile'])) {
            $profile = $this->getProfileData();
            $uri = 'payment-experience/web-profiles';
            $this->restClient->setAuthToken();
            $profileList = $this->restClient->get($uri);
            foreach($profileList as $entry) {
                if($entry['name'] == $profile['name']) {
                    $this->restClient->update("$uri/{$entry['id']}", $profile);
                    $this->session['PaypalProfile'] = array(
                        'id' => $entry['id']
                    );
                    break;
                }
            }
            if(!isset($this->session['PaypalProfile'])) {
                $this->session['PaypalProfile'] = $this->restClient->create($uri, $profile);
            }
        }
        return $this->session['PaypalProfile'];
    }

    /**
     * @return array
     */
    protected function getProfileData()
    {
        $template = $this->bootstrap->get('template');
        $router = $this->bootstrap->get('router');
        $shop = $this->bootstrap->get('shop');

        $localeCode = $this->paypalBootstrap->getLocaleCode(true);

        $profileName = "{$shop->getHost()}{$shop->getBasePath()}[{$shop->getId()}]";

        $shopName = $this->bootstrap->get('config')->get('shopName');
        $shopName = $this->config->get('paypalBrandName', $shopName);

        $logoImage = $this->config->get('paypalLogoImage');
        $logoImage = 'string:{link file=' . var_export($logoImage, true) . ' fullPath}';
        $logoImage = $template->fetch($logoImage);

        $notifyUrl = $router->assemble(array(
            'controller' => 'payment_paypal', 'action' => 'notify',
            'forceSecure' => true
        ));

        return array(
            'name' => $profileName,
            'presentation' => array(
                'brand_name' => $shopName,
                'logo_image' => $logoImage,
                'locale_code' => $localeCode
            ),
            'input_fields' => array(
                'allow_note' => true,
                'no_shipping' => 0,
                'address_override' => 1
            ),
            'flow_config' => array(
                'landing_page_type' => 'billing',
                'bank_txn_pending_url' => $notifyUrl
            ),
        );
    }

    private function getTransactionData($basket, $user)
    {
        $total = $this->getTotalAmount($basket, $user);
        $shipping = $this->getTotalShipment($basket, $user);

        return array(array(
            'amount' => array(
                'currency' => $this->getCurrency(),
                'total' => number_format($total, 2, '.', ','),
                'details' => array(
                    'shipping' => number_format($shipping, 2, '.', ','),
                    'subtotal' => number_format($total - $shipping, 2, '.', ','),
                    'tax' => number_format(0, 2, '.', ','),
                )
            ),
            'item_list' => array(
                'items' => $this->getItemList($basket, $user),
                'shipping_address' => $this->getShippingAddress($user)
            ),
        ));
    }

    /**
     * @param \Enlight_Controller_ActionEventArgs $args
     */
    public function onPostDispatchCheckout($args)
    {
        unset($this->session->PaypalPlusPayment);

        $action = $args->getSubject();
        $request = $action->Request();
        $response = $action->Response();
        $view = $action->View();

        // Secure dispatch
        if (!$request->isDispatched()
            || $response->isException()
            || $response->isRedirect()
        ) {
            return;
        }

        //Fix payment description
        $newDescription = $this->bootstrap->Config()->get('paypalPlusDescription');
        if (!empty($newDescription)) {
            $payments = $view->sPayments;
            if (!empty($payments)) {
                foreach($payments as $key => $payment) {
                    if($payment['name'] == 'paypal') {
                        $payments[$key]['description'] = $newDescription;
                        break;
                    }
                }
                $view->sPayments = $payments;
            }
            $user = $view->sUserData;
            if (!empty($user['additional']['payment']['name'])
              && $user['additional']['payment']['name'] == 'paypal') {
                $user['additional']['payment']['description'] = $newDescription;
                $view->sUserData = $user;
            }
        }

        // Check action
        if ($request->getActionName() != 'confirm') {
            return;
        }

        if($request->get('ppplusRedirect')) {
            $action->redirect(array(
                'controller' => 'checkout',
                'action' => 'payment',
                'sAGB' => 1
            ));
            return;
        }

        // Paypal plus conditions
        $user = $view->sUserData;
        $countries = $this->bootstrap->Config()->get('paypalPlusCountries');
        if ($countries instanceof \Enlight_Config) {
            $countries = $countries->toArray();
        } else {
            $countries = (array)$countries;
        }

        if (!empty($this->session->PaypalResponse['TOKEN']) // PP-Express
            || empty($user['additional']['payment']['name'])
            || $user['additional']['payment']['name'] != 'paypal'
            || !in_array($user['additional']['country']['id'], $countries)
        ) {
            return;
        }

        /** @var $shopContext \Shopware\Models\Shop\Shop */
        $shopContext = $this->bootstrap->get('shop');
        $templateVersion = $shopContext->getTemplate()->getVersion();

        $this->bootstrap->registerMyTemplateDir($templateVersion >= 3);
        $this->onPaypalPlus($action);

        if ($templateVersion < 3) { // emotion template
            $view->extendsTemplate('frontend/payment_paypal_plus/checkout.tpl');
        }
    }

    /**
     * @param ControllerAction $action
     */
    public function onPaypalPlus(ControllerAction $action)
    {
        $config = $this->config;
        $router = $action->Front()->Router();
        $view = $action->View();
        $user = $view->sUserData;
        $basket = $view->sBasket;

        $cancelUrl = $router->assemble(array(
            'controller' => 'payment_paypal', 'action' => 'cancel',
            'forceSecure' => true,
        ));
        $returnUrl = $router->assemble(array(
            'controller' => 'payment_paypal', 'action' => 'return',
            'forceSecure' => true,
        ));

        $profile = $this->getProfile();

        $this->restClient->setAuthToken();

        $uri = 'payments/payment';
        $params = array(
            'intent' => 'sale',
            'experience_profile_id' => $profile['id'],
            'payer' => array(
                'payment_method' => 'paypal'
            ),
            'transactions' => $this->getTransactionData($basket, $user),
            'redirect_urls' => array(
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl
            ),
        );
        $payment = $this->restClient->create($uri, $params);

        $view->PaypalPlusRequest = $params;
        $view->PaypalPlusResponse = $payment;

        if(!empty($payment['links'][1]['href'])) {
            $view->PaypalPlusApprovalUrl = $payment['links'][1]['href'];
            $view->PaypalPlusModeSandbox = $config->get('paypalSandbox');
            $view->PaypalLocale = $this->paypalBootstrap->getLocaleCode();

            $db = $this->bootstrap->get('db');
            $sql = '
              SELECT paymentmeanID as id, paypal_plus_media as media
              FROM s_core_paymentmeans_attributes WHERE paypal_plus_active=1
            ';
            $paymentMethods = $db->fetchAssoc($sql);
            $view->PaypalPlusThirdPartyPaymentMethods = $paymentMethods;
            $this->session->PaypalPlusPayment = $payment['id'];
        }
    }
}