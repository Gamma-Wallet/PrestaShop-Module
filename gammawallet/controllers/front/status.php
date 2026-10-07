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

            return;
        }
        if ($order->module === $this->module->name) {
            try {
                $status = GammaWalletCredits::status($order);
            } catch (GammaWalletApiError $e) {
                self::reply(array('kind' => 'credit', 'status' => 'Unknown'), 503);

                return;
            }
            if ('Paid' === $status['status']) {
                $status['redirect'] = self::confirmationUrl($this->context, $this->module, $order);
            }
            self::reply(array('kind' => 'credit') + $status);
        }
        self::reply(array('kind' => 'reward', 'status' => GammaWalletRewards::refreshStatus($order, 10)));
    }
}
