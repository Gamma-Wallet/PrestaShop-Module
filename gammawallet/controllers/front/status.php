<?php
/**
 * Gamma Wallet for PrestaShop — asked every 5 seconds by the customer's page (never Gamma itself):
 * has the reward been collected, has the order been settled with store credits?
 * Protected by the order's secure key, like PrestaShop's own order confirmation page.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class GammaWalletStatusModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    /** The order named in the request, or null when the key does not match. */
    public static function orderFromRequest()
    {
        $order = new Order((int) Tools::getValue('id_order'));
        $key = (string) Tools::getValue('key');

        return (Validate::isLoadedObject($order) && '' !== $key && hash_equals((string) $order->secure_key, $key)) ? $order : null;
    }

    public static function reply(array $data, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data);
        exit;
    }

    /** Where a settled store-credit order goes: its confirmation page, now showing it settled. */
    public static function confirmationUrl(Context $context, Module $module, Order $order)
    {
        return $context->link->getPageLink('order-confirmation', true, null, array(
            'id_cart' => (int) $order->id_cart,
            'id_module' => (int) $module->id,
            'id_order' => (int) $order->id,
            'key' => $order->secure_key,
        ));
    }

    public function postProcess()
    {
        $order = self::orderFromRequest();
        if (!$order) {
            self::reply(array('error' => 'not_found'), 404);
        }
        if ($order->module === $this->module->name) {
            try {
                $status = GammaWalletCredits::status($order);
            } catch (GammaWalletApiError $e) {
                self::reply(array('kind' => 'credit', 'status' => 'Unknown'), 503);
            }
            if ('Paid' === $status['status']) {
                $status['redirect'] = self::confirmationUrl($this->context, $this->module, $order);
            }
            self::reply(array('kind' => 'credit') + $status);
        }
        self::reply(array('kind' => 'reward', 'status' => GammaWalletRewards::refreshStatus($order)));
    }
}
