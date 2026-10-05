<?php
/**
 * Gamma Wallet for PrestaShop — what the shop owner set, and what Gamma said about the connection.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class GammaWalletSettings
{
    const TOKEN = 'GAMMAWALLET_TOKEN';
    const REWARDS = 'GAMMAWALLET_REWARDS';
    const NO_REWARD_MODULES = 'GAMMAWALLET_NO_REWARD_MODULES';
    const REWARD_EMAIL = 'GAMMAWALLET_REWARD_EMAIL';
    const CREDITS = 'GAMMAWALLET_CREDITS';
    const CONNECTION = 'GAMMAWALLET_CONNECTION';
    const OS_AWAITING = 'GAMMAWALLET_OS_AWAITING';

    /** Paid outside the shop, after the order is placed: cash on delivery, bank transfer, cheque. */
    const PAY_LATER_MODULES = array('ps_cashondelivery', 'ps_wirepayment', 'ps_checkpayment');

    /** How long a connection check is trusted before Gamma is asked again. */
    const STALE_SECONDS = 3600;

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
            $connection = array('error' => self::explain($e), 'checkedOn' => time());
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
        self::refreshIfStale(3);
        $connection = self::connection();

        return !empty($connection['canClaim']);
    }

    /** A sentence the shop owner can act on. */
    public static function explain(GammaWalletApiError $e)
    {
        switch ($e->identifier) {
            case '0388':
                return 'Gamma does not recognise this token. Copy it again from Gamma Business → Integrations.';
            case '0389':
                return 'This token was disabled or replaced. Create a new one in Gamma Business → Integrations.';
            case '0390':
                return 'This token has expired. Create a new one in Gamma Business → Integrations.';
            case '0393':
                return 'The business this token belongs to is not available in Gamma.';
        }
        if (0 === $e->status) {
            return 'Gamma could not be reached. Check that this server can make outgoing HTTPS connections.';
        }
        if (429 === $e->status) {
            return 'Too many requests to Gamma. Try again in a minute.';
        }

        return $e->getMessage();
    }

    /** "GWINT_HejHaaj…HNf0": enough to recognise a token, never enough to use it. */
    public static function masked($token)
    {
        return Tools::strlen($token) > 17 ? Tools::substr($token, 0, 13) . '…' . Tools::substr($token, -4) : '…';
    }
}
