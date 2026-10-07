<?php
/**
 * Gamma Wallet for PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 *
 * @author    Gamma Wallet <developer@gamma-wallet.com>
 * @copyright Since 2026 Gamma Wallet
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0 (AFL-3.0)
 */
/**
 * Gamma Wallet for PrestaShop — the customer chose "Use Store Credits with Gamma" and placed the
 * order. The order is created waiting for the credits, Gamma is asked for a QR code, and the
 * customer goes to the order confirmation page, which shows it.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class GammaWalletValidationModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        $cart = $this->context->cart;
        $restart = $this->context->link->getPageLink('order', true, null, array('step' => 1));
        // The checkout posts the payment form; a plain link (or a link from another site) places nothing.
        if ('POST' !== Tools::strtoupper((string) filter_input(INPUT_SERVER, 'REQUEST_METHOD'))) {
            Tools::redirect($restart);
        }
        if (!$this->module->active || !$cart->id || !$cart->id_customer || !$cart->id_address_delivery || !$cart->id_address_invoice) {
            Tools::redirect($restart);
        }
        $offered = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] === $this->module->name) {
                $offered = true;
                break;
            }
        }
        $customer = new Customer((int) $cart->id_customer);
        if (!$offered || !Validate::isLoadedObject($customer) || !GammaWalletSettings::creditsEnabled() || !GammaWalletSettings::rewardServiceActive()) {
            Tools::redirect($restart);
        }

        // The same checks as when the option was offered: the cart may have changed since.
        $total = (float) $cart->getOrderTotal(true, Cart::BOTH);
        $currency = new Currency((int) $cart->id_currency);
        if ($total <= 0 || !GammaWalletSettings::currencyMatches($currency->iso_code) || GammaWallet::cartSplits($cart)) {
            Tools::redirect($restart);
        }
        $this->module->validateOrder(
            (int) $cart->id,
            (int) Configuration::get(GammaWalletSettings::OS_AWAITING),
            $total,
            $this->module->l('Gamma Wallet store credits', 'validation'),
            null,
            array(),
            (int) $this->context->currency->id,
            false,
            $customer->secure_key
        );
        $order = new Order((int) $this->module->currentOrder);
        try {
            GammaWalletCredits::startRequest($order, true);
        } catch (GammaWalletApiError $e) {
            // The page then offers a new code; the order stays waiting.
            GammaWalletApi::log(sprintf('Store-credit request for order %s failed: %s', $order->reference, $e->getMessage()), 2, $order->id);
        }

        Tools::redirect($this->context->link->getPageLink('order-confirmation', true, null, array(
            'id_cart' => (int) $cart->id,
            'id_module' => (int) $this->module->id,
            'id_order' => (int) $order->id,
            'key' => $customer->secure_key,
        )));
    }
}
