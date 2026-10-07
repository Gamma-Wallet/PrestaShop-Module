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
 * Gamma Wallet for PrestaShop — what the shop owner set, and what Gamma said about the connection.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class GammaWalletSettings
{
    public const TOKEN = 'GAMMAWALLET_TOKEN';
    public const REWARDS = 'GAMMAWALLET_REWARDS';
    public const NO_REWARD_MODULES = 'GAMMAWALLET_NO_REWARD_MODULES';
    public const REWARD_EMAIL = 'GAMMAWALLET_REWARD_EMAIL';
    public const CREDITS = 'GAMMAWALLET_CREDITS';
    public const CONNECTION = 'GAMMAWALLET_CONNECTION';
    public const OS_AWAITING = 'GAMMAWALLET_OS_AWAITING';
    /** When the module was installed (Unix time): orders placed before it never earn a reward. */
    public const INSTALLED_ON = 'GAMMAWALLET_INSTALLED_ON';
    /** The secret in the address of the cron task that settles store-credit orders paid after the page closed. */
    public const CRON_TOKEN = 'GAMMAWALLET_CRON_TOKEN';
    /** When the back office last checked the waiting store-credit orders. */
    public const RECONCILED_AT = 'GAMMAWALLET_RECONCILED_AT';

    /** Paid outside the shop, after the order is placed: cash on delivery, bank transfer, cheque. */
    public const PAY_LATER_MODULES = ['ps_cashondelivery', 'ps_wirepayment', 'ps_checkpayment'];

    /** How long a connection check is trusted before Gamma is asked again. */
    public const STALE_SECONDS = 3600;

    public static function token()
    {
        return trim((string) Configuration::get(self::TOKEN));
    }

    public static function rewardsEnabled()
    {
        return (bool) Configuration::get(self::REWARDS);
    }

    public static function rewardInEmail()
    {
        return (bool) Configuration::get(self::REWARD_EMAIL);
    }

    public static function creditsEnabled()
    {
        return (bool) Configuration::get(self::CREDITS);
    }

    public static function isPayLater($moduleName)
    {
        return in_array((string) $moduleName, self::PAY_LATER_MODULES, true);
    }

    /** Payment modules left unticked in the settings: their orders earn no reward. */
    public static function excludedModules()
    {
        $saved = json_decode((string) Configuration::get(self::NO_REWARD_MODULES), true);

        return is_array($saved) ? $saved : self::PAY_LATER_MODULES;
    }

    /**
     * True when an order paid with this payment module earns a reward: the shop ticked it. Online
     * methods are ticked by default; cash on delivery, bank transfer and cheque are not. Orders
     * settled with store credits never earn one.
     */
    public static function moduleEarnsReward($moduleName)
    {
        if ('' === (string) $moduleName || 'gammawallet' === $moduleName) {
            return false;
        }

        return !in_array($moduleName, self::excludedModules(), true);
    }

    /** What Gamma said the last time the connection was checked, or null when never checked. */
    public static function connection()
    {
        $connection = json_decode((string) Configuration::get(self::CONNECTION), true);

        return is_array($connection) ? $connection : null;
    }

    public static function businessCurrency()
    {
        $connection = self::connection();

        return isset($connection['currencyCode']) ? $connection['currencyCode'] : null;
    }

    /** True when the currency is the business's, or when that cannot be known yet. */
    public static function currencyMatches($isoCode)
    {
        $business = self::businessCurrency();

        return null === $business || strtoupper($business) === strtoupper((string) $isoCode);
    }

    /** Asks Gamma who the token belongs to, and keeps the answer. */
    public static function checkConnection($timeout = 20)
    {
        $api = GammaWalletApi::fromSettings();
        if (!$api) {
            Configuration::updateValue(self::CONNECTION, '');

            return null;
        }
        try {
            $connection = $api->connection($timeout);
            $connection['checkedOn'] = time();
        } catch (GammaWalletApiError $e) {
            $connection = ['error' => self::explain($e), 'checkedOn' => time()];
            // Gamma briefly out of reach: keep what it said last time, so the shop keeps working.
            $previous = self::connection();
            if ($e->isRetryable() && $previous && isset($previous['canClaim'])) {
                $connection['canClaim'] = $previous['canClaim'];
                if (isset($previous['currencyCode'])) {
                    $connection['currencyCode'] = $previous['currencyCode'];
                }
            }
        }
        Configuration::updateValue(self::CONNECTION, json_encode($connection));

        return $connection;
    }

    /** Asks Gamma again when the last answer is over an hour old. */
    public static function refreshIfStale($timeout = 5)
    {
        if ('' === self::token()) {
            return;
        }
        $connection = self::connection();
        if (!$connection || (int) (isset($connection['checkedOn']) ? $connection['checkedOn'] : 0) < time() - self::STALE_SECONDS) {
            self::checkConnection($timeout);
        }
    }

    /**
     * True while the business's active Gamma service is a Reward service: the only kind whose rewards
     * customers can collect. The module does nothing for customers otherwise.
     */
    public static function rewardServiceActive()
    {
        if ('' === self::token()) {
            return false;
        }
        // Checked again at most once an hour, with a short wait, so a checkout is never held up long.
        self::refreshIfStale(3);
        $connection = self::connection();

        return !empty($connection['canClaim']);
    }

    /** A sentence the shop owner can act on, in the back office language. */
    public static function explain(GammaWalletApiError $e)
    {
        $module = Module::getInstanceByName('gammawallet');
        switch ($e->identifier) {
            case '0388':
                return $module->l('Gamma does not recognise this token. Copy it again from Gamma Business → Integrations.', 'gammawalletsettings');
            case '0389':
                return $module->l('This token was disabled or replaced. Create a new one in Gamma Business → Integrations.', 'gammawalletsettings');
            case '0390':
                return $module->l('This token has expired. Create a new one in Gamma Business → Integrations.', 'gammawalletsettings');
            case '0393':
                return $module->l('The business this token belongs to is not available in Gamma.', 'gammawalletsettings');
        }
        if (0 === $e->status) {
            return $module->l('Gamma could not be reached. Check that this server can make outgoing HTTPS connections.', 'gammawalletsettings');
        }
        if (429 === $e->status) {
            return $module->l('Too many requests to Gamma. Try again in a minute.', 'gammawalletsettings');
        }

        return $e->getMessage();
    }

    /**
     * Six characters fixed for this shop, in every order reference sent to Gamma, so a second shop on
     * the same Gamma business never reuses one. Derived from the shop's own secret key, so it stays
     * the same when the module is reinstalled.
     */
    public static function shopTag()
    {
        return Tools::substr(hash('sha256', 'gammawallet:' . _COOKIE_KEY_), 0, 6);
    }

    /** The address of the cron task, with its secret. */
    public static function cronUrl()
    {
        return Context::getContext()->link->getModuleLink('gammawallet', 'cron', ['token' => (string) Configuration::get(self::CRON_TOKEN)], true);
    }

    /** "GWINT_Ab12Cd3…x9Yz": enough to recognise a token, never enough to use it. */
    public static function masked($token)
    {
        return Tools::strlen($token) > 17 ? Tools::substr($token, 0, 13) . '…' . Tools::substr($token, -4) : '…';
    }
}
