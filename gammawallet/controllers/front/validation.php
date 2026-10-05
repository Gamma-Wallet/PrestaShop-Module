<?php
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

        $total = (float) $cart->getOrderTotal(true, Cart::BOTH);
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
            GammaWalletCredits::startRequest($order);
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
