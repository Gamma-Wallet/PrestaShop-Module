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
 * Gamma Wallet for PrestaShop — the cron task: store-credit orders whose customer confirmed in the
 * app after leaving the page are checked with Gamma and settled. Call it every few minutes (the
 * address, with its secret, is on the module's settings page; the "Cron tasks manager" module or the
 * server's crontab can call it).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class GammaWalletCronModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        $expected = (string) Configuration::get(GammaWalletSettings::CRON_TOKEN);
        $token = (string) Tools::getValue('token');
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        if ('' === $expected || !hash_equals($expected, $token)) {
            http_response_code(403);
            exit('forbidden');
        }
        if ('' !== GammaWalletSettings::token()) {
            Configuration::updateValue(GammaWalletSettings::RECONCILED_AT, time());
            GammaWalletCredits::reconcile(10);
            GammaWalletSettings::refreshIfStale(10);
        }
        exit('ok');
    }
}
