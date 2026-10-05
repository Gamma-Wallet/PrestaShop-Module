<?php
/**
 * Gamma Wallet for PrestaShop — a new store-credit QR code for an order whose code expired unused.
 *
 * Asks Gamma about the current code first: the customer may have settled it a moment ago, before
 * the page asked, and then the order is completed instead of getting a new code.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/status.php';

class GammaWalletNewcodeModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        $reply = array('GammaWalletStatusModuleFrontController', 'reply');
        $order = GammaWalletStatusModuleFrontController::orderFromRequest();
        if (!$order || $order->module !== $this->module->name || 'POST' !== $_SERVER['REQUEST_METHOD']) {
            call_user_func($reply, array('error' => 'not_found'), 404);
        }
        try {
            $current = GammaWalletCredits::status($order);
        } catch (GammaWalletApiError $e) {
            call_user_func($reply, array('error' => 'unavailable'), 503);
        }
        if ('Paid' === $current['status']) {
            call_user_func($reply, array(
                'status' => 'Paid',
                'redirect' => GammaWalletStatusModuleFrontController::confirmationUrl($this->context, $this->module, $order),
            ));
        }
        if ((int) $order->getCurrentState() !== (int) Configuration::get(GammaWalletSettings::OS_AWAITING)) {
            call_user_func($reply, array('error' => 'not_payable'), 409);
        }

        // Never two live codes for one order, or the customer could settle it twice. Gamma still
        // accepts a code a few seconds past its time (clock differences), so wait those out too.
        $row = GammaWalletOrder::get($order->id);
        $expires = $row['credit_expires_on'] ? strtotime($row['credit_expires_on']) : 0;
        if ($expires && time() < $expires + 15) {
            call_user_func($reply, array('error' => 'still_valid'), 409);
        }

        try {
            $started = GammaWalletCredits::startRequest($order);
        } catch (GammaWalletApiError $e) {
            GammaWalletApi::log(sprintf('New store-credit code for order %s failed: %s', $order->reference, $e->getMessage()), 2, $order->id);
            call_user_func($reply, array('error' => 'unavailable'), 503);
        }
        call_user_func($reply, array(
            'status' => 'Waiting',
            'secondsLeft' => (int) $started['secondsLeft'],
            'link' => $started['link'],
            'qr' => 'data:image/png;base64,' . (isset($started['qrPngBase64']) ? $started['qrPngBase64'] : ''),
        ));
    }
}
