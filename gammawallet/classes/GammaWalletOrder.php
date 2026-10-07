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
 * Gamma Wallet for PrestaShop — what Gamma said about one order, kept in its own table
 * (PREFIX_gammawallet_order, one row per order).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class GammaWalletOrder
{
    public const TABLE = 'gammawallet_order';

    public const COLUMNS = [
        'bill_id', 'code', 'link', 'qr_url', 'bill_status', 'claimed_on', 'error', 'attempts', 'emailed',
        'credit_request', 'credit_request_id', 'credit_expires_on', 'credit_link', 'credit_qr', 'settled_request_id',
    ];

    public static function installSql()
    {
        return 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
            `id_order` INT UNSIGNED NOT NULL,
            `bill_id` VARCHAR(64) NULL,
            `code` VARCHAR(64) NULL,
            `link` VARCHAR(255) NULL,
            `qr_url` VARCHAR(255) NULL,
            `bill_status` VARCHAR(16) NULL,
            `claimed_on` VARCHAR(40) NULL,
            `error` VARCHAR(255) NULL,
            `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
            `emailed` INT UNSIGNED NOT NULL DEFAULT 0,
            `credit_request` TEXT NULL,
            `credit_request_id` VARCHAR(64) NULL,
            `credit_expires_on` VARCHAR(40) NULL,
            `credit_link` TEXT NULL,
            `credit_qr` MEDIUMTEXT NULL,
            `settled_request_id` VARCHAR(64) NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_order`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';
    }

    public static function uninstallSql()
    {
        return 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE . '`';
    }

    /** The row for an order, with every column present (empty when there is no row yet). */
    public static function get($idOrder)
    {
        $row = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_order` = ' . (int) $idOrder);
        $data = array_fill_keys(self::COLUMNS, null);

        return is_array($row) ? array_merge($data, $row) : $data;
    }

    /** Sets some columns of an order's row, creating the row when needed. */
    public static function save($idOrder, array $values)
    {
        $clean = ['id_order' => (int) $idOrder, 'date_upd' => date('Y-m-d H:i:s')];
        foreach ($values as $column => $value) {
            if (in_array($column, self::COLUMNS, true)) {
                $clean[$column] = null === $value ? null : pSQL((string) $value);
            }
        }
        $db = Db::getInstance();
        $exists = (bool) $db->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_order` = ' . (int) $idOrder);

        return $exists
            ? $db->update(self::TABLE, $clean, '`id_order` = ' . (int) $idOrder, 0, true)
            : $db->insert(self::TABLE, $clean, true);
    }

    private static function ensureRow($idOrder)
    {
        Db::getInstance()->execute('INSERT IGNORE INTO `' . _DB_PREFIX_ . self::TABLE . '` (`id_order`, `date_upd`) VALUES ('
            . (int) $idOrder . ", '" . pSQL(date('Y-m-d H:i:s')) . "')");
    }

    /**
     * Marks the order settled, once: true only for the request that made the change, so two pages
     * asking at the same moment can never record the payment twice.
     */
    public static function claimSettlement($idOrder, $requestId)
    {
        self::ensureRow($idOrder);
        $db = Db::getInstance();
        $db->execute('UPDATE `' . _DB_PREFIX_ . self::TABLE . "` SET `settled_request_id` = '" . pSQL((string) $requestId)
            . "', `credit_qr` = '', `date_upd` = '" . pSQL(date('Y-m-d H:i:s')) . "' WHERE `id_order` = " . (int) $idOrder
            . ' AND `settled_request_id` IS NULL');

        return 1 === (int) $db->Affected_Rows();
    }

    /**
     * Reserves the right to ask Gamma for a new store-credit code, for one request only, so an order
     * never has two live codes (two tabs, a double click). $firstOnly: only when the order never had
     * a code. Otherwise only once the last code expired over 15 seconds ago (Gamma still accepts a
     * code a few seconds past its time). The reservation counts as a live code for 30 seconds.
     * Returns the previous expiry (to give the slot back if Gamma cannot be reached), or null when
     * another request holds it.
     */
    public static function claimCodeSlot($idOrder, $firstOnly)
    {
        self::ensureRow($idOrder);
        $row = self::get($idOrder);
        $previous = (string) $row['credit_expires_on'];
        $where = '`id_order` = ' . (int) $idOrder . ' AND `settled_request_id` IS NULL';
        $where .= $firstOnly
            ? ' AND `credit_request` IS NULL AND `credit_expires_on` IS NULL'
            : " AND (`credit_expires_on` IS NULL OR `credit_expires_on` < '" . pSQL(gmdate('Y-m-d\TH:i:s\Z', time() - 15)) . "')";
        $db = Db::getInstance();
        $db->execute('UPDATE `' . _DB_PREFIX_ . self::TABLE . "` SET `credit_expires_on` = '"
            . pSQL(gmdate('Y-m-d\TH:i:s\Z', time() + 30)) . "' WHERE " . $where);

        return 1 === (int) $db->Affected_Rows() ? $previous : null;
    }

    /** Gives a reserved slot back when Gamma could not start the code. */
    public static function releaseCodeSlot($idOrder, $previous)
    {
        Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . self::TABLE . '` SET `credit_expires_on` = '
            . ('' !== (string) $previous ? "'" . pSQL((string) $previous) . "'" : 'NULL') . ' WHERE `id_order` = ' . (int) $idOrder);
    }

    /** Store-credit orders not settled yet whose code was shown in the last hours. */
    public static function awaitingSettlement($hours)
    {
        $rows = Db::getInstance()->executeS('SELECT `id_order` FROM `' . _DB_PREFIX_ . self::TABLE
            . "` WHERE `settled_request_id` IS NULL AND `credit_request` IS NOT NULL AND `date_upd` > '"
            . pSQL(date('Y-m-d H:i:s', time() - (int) $hours * 3600)) . "'");

        return array_map('intval', array_column(is_array($rows) ? $rows : [], 'id_order'));
    }
}

/**
 * Rewards for paid orders: a Gamma bill per order, whose QR code the customer scans to collect it.
 */
class GammaWalletRewards
{
    /** No more automatic attempts after this many failures; the shop can still send it by hand. */
    public const MAX_ATTEMPTS = 6;

    /** The paid state an order is in, or null. */
    private static function isPaidNow(Order $order)
    {
        $state = new OrderState((int) $order->getCurrentState());

        return Validate::isLoadedObject($state) && (bool) $state->paid;
    }

    /**
     * True when this order should have a reward QR code now: rewards are on, the business has a
     * Reward service, the payment method earns one, and the money is in (the order is in a paid
     * state — for cash on delivery and the like, that is when the shop confirms the payment).
     */
    public static function qualifies(Order $order)
    {
        if (!GammaWalletSettings::rewardsEnabled() || (float) $order->total_paid <= 0 || !GammaWalletSettings::rewardServiceActive()) {
            return false;
        }
        if (!GammaWalletSettings::moduleEarnsReward($order->module)) {
            return false;
        }
        $currency = new Currency((int) $order->id_currency);
        if (!GammaWalletSettings::currencyMatches($currency->iso_code)) {
            return false;
        }

        return self::isPaidNow($order) && self::placedSinceInstall($order);
    }

    /** Placed since the module was installed: older orders never earn a reward. */
    public static function placedSinceInstall(Order $order)
    {
        $installedOn = (int) Configuration::get(GammaWalletSettings::INSTALLED_ON);

        return $installedOn > 0 && strtotime($order->date_add) >= $installedOn;
    }

    /** True when this order can ever earn a reward, now or once its payment is confirmed. */
    public static function mayEarn(Order $order)
    {
        return GammaWalletSettings::rewardsEnabled()
            && GammaWalletSettings::rewardServiceActive()
            && GammaWalletSettings::moduleEarnsReward($order->module);
    }

    /**
     * The reference Gamma knows the order by: "PS-3f9a1c-XKBKNABJK". The short tag is fixed for this
     * shop, so a second shop on the same Gamma business never reuses a reference. Orders split from
     * one cart share a PrestaShop reference, so they also carry their id.
     */
    public static function reference(Order $order)
    {
        return 'PS-' . GammaWalletSettings::shopTag() . '-' . self::legacyReference($order);
    }

    /** The reference used by version 1.0.0, for requests started before an upgrade. */
    public static function legacyReference(Order $order)
    {
        $siblings = Order::getByReference($order->reference);

        return ($siblings && count($siblings) > 1) ? $order->reference . '-' . (int) $order->id : $order->reference;
    }

    /**
     * Declares the order to Gamma once. Returns true when the order has a bill afterwards. Safe to
     * call any number of times: Gamma returns the same bill for the same reference.
     */
    public static function ensureBill(Order $order, $timeout = 20)
    {
        $row = GammaWalletOrder::get($order->id);
        if ($row['bill_id']) {
            return true;
        }
        if (!self::qualifies($order) || (int) $row['attempts'] >= self::MAX_ATTEMPTS) {
            return false;
        }
        $api = GammaWalletApi::fromSettings();
        if (!$api) {
            return false;
        }
        $currency = new Currency((int) $order->id_currency);
        try {
            $bill = $api->createBill([
                'reference' => self::reference($order),
                'total' => (float) Tools::ps_round((float) $order->total_paid, 2),
                'currencyCode' => $currency->iso_code,
                'issuedOn' => date(DATE_ATOM, strtotime($order->date_add)),
                'platform' => 'prestashop',
                'pluginVersion' => GammaWallet::VERSION,
            ], $timeout);
        } catch (GammaWalletApiError $e) {
            GammaWalletOrder::save($order->id, [
                'error' => Tools::substr(GammaWalletSettings::explain($e), 0, 250),
                'attempts' => (int) $row['attempts'] + 1,
            ]);
            GammaWalletApi::log(sprintf('Bill for order %s failed: %s', $order->reference, $e->getMessage()), 2, $order->id);

            return false;
        }
        GammaWalletOrder::save($order->id, [
            'bill_id' => $bill['billId'],
            'code' => $bill['code'],
            'link' => $bill['link'],
            'qr_url' => $bill['qrImageUrl'],
            'bill_status' => $bill['status'],
            'claimed_on' => isset($bill['claimedOn']) ? $bill['claimedOn'] : null,
            'error' => null,
        ]);

        return true;
    }

    /** Asks Gamma whether the reward was collected. "Waiting" or "Claimed". */
    public static function refreshStatus(Order $order, $timeout = 20)
    {
        $row = GammaWalletOrder::get($order->id);
        if ('Claimed' === $row['bill_status'] || !$row['bill_id']) {
            return (string) $row['bill_status'];
        }
        $api = GammaWalletApi::fromSettings();
        if (!$api) {
            return (string) $row['bill_status'];
        }
        try {
            $bill = $api->getBill($row['bill_id'], $timeout);
        } catch (GammaWalletApiError $e) {
            return (string) $row['bill_status'];
        }
        if ($bill['status'] !== $row['bill_status']) {
            GammaWalletOrder::save($order->id, [
                'bill_status' => $bill['status'],
                'claimed_on' => isset($bill['claimedOn']) ? $bill['claimedOn'] : null,
            ]);
        }

        return (string) $bill['status'];
    }

    /**
     * The reward email of its own: for cash on delivery and the like, whose order emails were
     * written before anything was paid, and for sending the QR code again by hand.
     */
    public static function sendRewardEmail(Order $order)
    {
        if (!self::ensureBill($order)) {
            return false;
        }
        $row = GammaWalletOrder::get($order->id);
        $customer = new Customer((int) $order->id_customer);
        if (!Validate::isLoadedObject($customer) || !Validate::isEmail($customer->email)) {
            return false;
        }
        $module = Module::getInstanceByName('gammawallet');
        $idLang = self::mailLanguage((int) $order->id_lang);
        $sent = Mail::Send(
            $idLang,
            'gammawallet_reward',
            sprintf($module->l('Your reward from %1$s (order %2$s)', 'gammawalletorder'), Configuration::get('PS_SHOP_NAME'), $order->reference),
            [
                '{firstname}' => $customer->firstname,
                '{lastname}' => $customer->lastname,
                '{order_name}' => $order->reference,
                '{gamma_qr_url}' => $row['qr_url'],
                '{gamma_link}' => $row['link'],
            ],
            $customer->email,
            trim($customer->firstname . ' ' . $customer->lastname),
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . 'gammawallet/mails/',
            false,
            (int) $order->id_shop
        );
        if ($sent) {
            GammaWalletOrder::save($order->id, ['emailed' => time()]);
        }

        return (bool) $sent;
    }

    /** Sends the reward email once, for a pay-later order that has just been paid. */
    public static function emailPayLaterOnce(Order $order)
    {
        if (!GammaWalletSettings::isPayLater($order->module)) {
            return;
        }
        $row = GammaWalletOrder::get($order->id);
        if (!(int) $row['emailed']) {
            self::sendRewardEmail($order);
        }
    }

    /** The order's language when the module has its email in that language, otherwise English. */
    private static function mailLanguage($idLang)
    {
        $iso = Language::getIsoById($idLang);
        if ($iso && is_file(_PS_MODULE_DIR_ . 'gammawallet/mails/' . $iso . '/gammawallet_reward.html')) {
            return $idLang;
        }
        $english = (int) Language::getIdByIso('en');

        return $english ? $english : $idLang;
    }
}

/**
 * Store credits at checkout: the whole order is settled with the credits the customer holds at the
 * shop, by scanning a QR code that is valid for a short time.
 */
class GammaWalletCredits
{
    /** How long after a code was shown the shop keeps asking Gamma whether it was settled. */
    public const RECONCILE_HOURS = 6;

    /**
     * Asks Gamma for a new store-credit request for the whole order, and keeps it. Returns null when
     * another request is starting one or the current one is still live (never two codes per order).
     */
    public static function startRequest(Order $order, $firstOnly = false)
    {
        $api = GammaWalletApi::fromSettings();
        if (!$api) {
            throw new GammaWalletApiError(401, '0392', 'IntegrationTokenMissing');
        }
        $previous = GammaWalletOrder::claimCodeSlot($order->id, $firstOnly);
        if (null === $previous) {
            return null;
        }
        $currency = new Currency((int) $order->id_currency);
        try {
            $request = $api->startCredit([
                'reference' => GammaWalletRewards::reference($order),
                'total' => (float) Tools::ps_round((float) $order->total_paid, 2),
                'currencyCode' => $currency->iso_code,
            ]);
        } catch (GammaWalletApiError $e) {
            GammaWalletOrder::releaseCodeSlot($order->id, $previous);
            throw $e;
        }
        GammaWalletOrder::save($order->id, [
            'credit_request' => $request['creditRequest'],
            'credit_request_id' => $request['requestId'],
            'credit_expires_on' => $request['expiresOn'],
            'credit_link' => $request['link'],
            'credit_qr' => isset($request['qrPngBase64']) ? $request['qrPngBase64'] : '',
        ]);

        return $request;
    }

    public static function isSettled(Order $order)
    {
        $row = GammaWalletOrder::get($order->id);

        return '' !== (string) $row['settled_request_id'] || $order->hasBeenPaid() > 0;
    }

    /** True while the order waits for the credits (not cancelled or changed by hand meanwhile). */
    public static function isAwaiting(Order $order)
    {
        return (int) $order->getCurrentState() === (int) Configuration::get(GammaWalletSettings::OS_AWAITING);
    }

    /** Gamma's answer is about this order: same reference, total and currency. */
    private static function matches(Order $order, array $checked)
    {
        if (isset($checked['reference']) && !in_array((string) $checked['reference'],
            [GammaWalletRewards::reference($order), GammaWalletRewards::legacyReference($order)], true)) {
            return false;
        }
        $currency = new Currency((int) $order->id_currency);
        if (isset($checked['currencyCode']) && 0 !== strcasecmp((string) $checked['currencyCode'], (string) $currency->iso_code)) {
            return false;
        }

        return !isset($checked['total']) || abs((float) $checked['total'] - Tools::ps_round((float) $order->total_paid, 2)) < 0.005;
    }

    /**
     * Records a settled request once: the order is paid, and the QR code is no longer needed. When the
     * order no longer waits for the credits (cancelled meanwhile, or changed by hand), it is left as
     * it is and the shop is told: the customer has used their credits.
     */
    public static function markSettled(Order $order, array $checked)
    {
        if (!self::matches($order, $checked)) {
            GammaWalletApi::log(sprintf('The settled request %s does not match order %s (reference, total or currency); the order was not changed.',
                isset($checked['requestId']) ? $checked['requestId'] : '?', $order->reference), 3, $order->id);

            return;
        }
        $requestId = isset($checked['requestId']) ? (string) $checked['requestId'] : 'settled';
        if (!GammaWalletOrder::claimSettlement($order->id, '' !== $requestId ? $requestId : 'settled')) {
            return;
        }
        if (!self::isAwaiting($order)) {
            $text = sprintf('Gamma Wallet: the customer settled this order with their store credits (request %s), but the order was no longer waiting for them, so it was not changed. Check it.', $requestId);
            GammaWalletApi::log($text, 3, $order->id);
            self::privateNote($order, $text);

            return;
        }
        $order->setCurrentState((int) Configuration::get('PS_OS_PAYMENT'));

        // The payment PrestaShop records for the new state carries Gamma's request id.
        foreach (OrderPayment::getByOrderReference($order->reference) as $payment) {
            if ('' === (string) $payment->transaction_id) {
                $payment->transaction_id = $requestId;
                $payment->update();
            }
        }
    }

    /**
     * The order's state, asking Gamma when it is still waiting: Paid (settled now or before),
     * Waiting with the seconds left, or Expired.
     */
    public static function status(Order $order)
    {
        if (self::isSettled($order)) {
            return ['status' => 'Paid'];
        }
        $row = GammaWalletOrder::get($order->id);
        $api = GammaWalletApi::fromSettings();
        if (!$row['credit_request'] || !$api) {
            return ['status' => 'Expired'];
        }
        $checked = $api->checkCredit($row['credit_request']);
        if ('Paid' === $checked['status']) {
            self::markSettled($order, $checked);

            return ['status' => 'Paid'];
        }

        return ['status' => $checked['status'], 'secondsLeft' => (int) $checked['secondsLeft']];
    }

    /**
     * Orders whose customer may have confirmed in the app after leaving the page. Credit/Check answers
     * for a request long after its code expired, so a settled order is found even then.
     */
    public static function reconcile($timeout = 5)
    {
        foreach (GammaWalletOrder::awaitingSettlement(self::RECONCILE_HOURS) as $idOrder) {
            $order = new Order((int) $idOrder);
            if (!Validate::isLoadedObject($order) || 'gammawallet' !== $order->module) {
                continue;
            }
            try {
                self::status($order);
            } catch (GammaWalletApiError $e) {
                GammaWalletApi::log(sprintf('Checking the store credits of order %s failed: %s', $order->reference, $e->getMessage()), 2, $order->id);
            }
        }
    }

    /** A private message on the order, seen by the shop in the order page. */
    private static function privateNote(Order $order, $text)
    {
        $message = new Message();
        $message->id_order = (int) $order->id;
        $message->id_cart = (int) $order->id_cart;
        $message->id_customer = (int) $order->id_customer;
        $message->message = Tools::substr($text, 0, 1600);
        $message->private = 1;
        $message->add();
    }
}
