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
/*
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
        $order = GammaWalletStatusModuleFrontController::orderFromRequest();
        if (!$order || $order->module !== $this->module->name || !Tools::isSubmit('newcode')) {
            GammaWalletStatusModuleFrontController::reply(['error' => 'not_found'], 404);

            return;
        }
        try {
            $current = GammaWalletCredits::status($order);
        } catch (GammaWalletApiError $e) {
            GammaWalletStatusModuleFrontController::reply(['error' => 'unavailable'], 503);

            return;
        }
        if ('Paid' === $current['status']) {
            GammaWalletStatusModuleFrontController::reply([
                'status' => 'Paid',
                'redirect' => GammaWalletStatusModuleFrontController::confirmationUrl($this->context, $this->module, $order),
            ]);

            return;
        }
        if (!GammaWalletCredits::isAwaiting($order)) {
            GammaWalletStatusModuleFrontController::reply(['error' => 'not_payable'], 409);

            return;
        }

        // Never two live codes for one order, or the customer could settle it twice. Gamma still
        // accepts a code a few seconds past its time (clock differences), so wait those out too.
        $row = GammaWalletOrder::get($order->id);
        $expires = $row['credit_expires_on'] ? strtotime($row['credit_expires_on']) : 0;
        if ($expires && time() < $expires + 15) {
            GammaWalletStatusModuleFrontController::reply(['error' => 'still_valid'], 409);

            return;
        }

        try {
            // Reserved in one step: a second click or tab arriving meanwhile gets "still valid".
            $started = GammaWalletCredits::startRequest($order);
        } catch (GammaWalletApiError $e) {
            GammaWalletApi::log(sprintf('New store-credit code for order %s failed: %s', $order->reference, $e->getMessage()), 2, $order->id);
            GammaWalletStatusModuleFrontController::reply(['error' => 'unavailable'], 503);

            return;
        }
        if (null === $started) {
            GammaWalletStatusModuleFrontController::reply(['error' => 'still_valid'], 409);

            return;
        }
        GammaWalletStatusModuleFrontController::reply([
            'status' => 'Waiting',
            'secondsLeft' => (int) $started['secondsLeft'],
            'link' => $started['link'],
            'qr' => 'data:image/png;base64,' . (isset($started['qrPngBase64']) ? $started['qrPngBase64'] : ''),
        ]);
    }
}
