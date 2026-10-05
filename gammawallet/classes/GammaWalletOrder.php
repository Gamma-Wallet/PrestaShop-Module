<?php
/**
 * Gamma Wallet for PrestaShop — what Gamma said about one order, kept in its own table
 * (PREFIX_gammawallet_order, one row per order).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class GammaWalletOrder
{
    const TABLE = 'gammawallet_order';

    const COLUMNS = array(
        'bill_id', 'code', 'link', 'qr_url', 'bill_status', 'claimed_on', 'error', 'attempts', 'emailed',
        'credit_request', 'credit_request_id', 'credit_expires_on', 'credit_link', 'credit_qr', 'settled_request_id',
    );

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
        $clean = array('id_order' => (int) $idOrder, 'date_upd' => date('Y-m-d H:i:s'));
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
}

/**
 * Rewards for paid orders: a Gamma bill per order, whose QR code the customer scans to collect it.
 */
class GammaWalletRewards
{
    /** No more automatic attempts after this many failures; the shop can still send it by hand. */
    const MAX_ATTEMPTS = 6;

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

        return self::isPaidNow($order);
    }

    /** True when this order can ever earn a reward, now or once its payment is confirmed. */
    public static function mayEarn(Order $order)
    {
        return GammaWalletSettings::rewardsEnabled()
            && GammaWalletSettings::rewardServiceActive()
            && GammaWalletSettings::moduleEarnsReward($order->module);
    }

    /** The reference Gamma knows the order by. Orders split from one cart share a reference. */
    public static function reference(Order $order)
    {
        $siblings = Order::getByReference($order->reference);

        return ($siblings && count($siblings) > 1) ? $order->reference . '-' . (int) $order->id : $order->reference;
    }

    /**
     * Declares the order to Gamma once. Returns true when the order has a bill afterwards. Safe to
     * call any number of times: Gamma returns the same bill for the same reference.
     */
    public static function ensureBill(Order $order)
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
            $bill = $api->createBill(array(
                'reference' => self::reference($order),
                'total' => (float) Tools::ps_round((float) $order->total_paid, 2),
                'currencyCode' => $currency->iso_code,
                'issuedOn' => date(DATE_ATOM, strtotime($order->date_add)),
                'platform' => 'prestashop',
                'pluginVersion' => GammaWallet::VERSION,
            ));
        } catch (GammaWalletApiError $e) {
            GammaWalletOrder::save($order->id, array(
                'error' => Tools::substr(GammaWalletSettings::explain($e), 0, 250),
                'attempts' => (int) $row['attempts'] + 1,
            ));
            GammaWalletApi::log(sprintf('Bill for order %s failed: %s', $order->reference, $e->getMessage()), 2, $order->id);

            return false;
        }
        GammaWalletOrder::save($order->id, array(
            'bill_id' => $bill['billId'],
            'code' => $bill['code'],
            'link' => $bill['link'],
            'qr_url' => $bill['qrImageUrl'],
            'bill_status' => $bill['status'],
            'claimed_on' => isset($bill['claimedOn']) ? $bill['claimedOn'] : null,
            'error' => null,
        ));

        return true;
    }

    /** Asks Gamma whether the reward was collected. "Waiting" or "Claimed". */
    public static function refreshStatus(Order $order)
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
            $bill = $api->getBill($row['bill_id']);
        } catch (GammaWalletApiError $e) {
            return (string) $row['bill_status'];
        }
        if ($bill['status'] !== $row['bill_status']) {
            GammaWalletOrder::save($order->id, array(
                'bill_status' => $bill['status'],
                'claimed_on' => isset($bill['claimedOn']) ? $bill['claimedOn'] : null,
            ));
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
            array(
                '{firstname}' => $customer->firstname,
                '{lastname}' => $customer->lastname,
                '{order_name}' => $order->reference,
                '{gamma_qr_url}' => $row['qr_url'],
                '{gamma_link}' => $row['link'],
            ),
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
            GammaWalletOrder::save($order->id, array('emailed' => time()));
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
    /** Asks Gamma for a new store-credit request for the whole order, and keeps it. */
    public static function startRequest(Order $order)
    {
        $api = GammaWalletApi::fromSettings();
        if (!$api) {
            throw new GammaWalletApiError(401, '0392', 'IntegrationTokenMissing');
        }
        $currency = new Currency((int) $order->id_currency);
        $request = $api->startCredit(array(
            'reference' => GammaWalletRewards::reference($order),
            'total' => (float) Tools::ps_round((float) $order->total_paid, 2),
            'currencyCode' => $currency->iso_code,
        ));
        GammaWalletOrder::save($order->id, array(
            'credit_request' => $request['creditRequest'],
            'credit_request_id' => $request['requestId'],
            'credit_expires_on' => $request['expiresOn'],
            'credit_link' => $request['link'],
            'credit_qr' => isset($request['qrPngBase64']) ? $request['qrPngBase64'] : '',
        ));

        return $request;
    }

    public static function isSettled(Order $order)
    {
        return $order->hasBeenPaid() > 0;
    }

    /** Records a settled request once: the order is paid, and the QR code is no longer needed. */
    public static function markSettled(Order $order, array $checked)
    {
        if (self::isSettled($order)) {
            return;
        }
        $requestId = isset($checked['requestId']) ? (string) $checked['requestId'] : '';
        GammaWalletOrder::save($order->id, array('credit_qr' => '', 'settled_request_id' => $requestId));
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
            return array('status' => 'Paid');
        }
        $row = GammaWalletOrder::get($order->id);
        $api = GammaWalletApi::fromSettings();
        if (!$row['credit_request'] || !$api) {
            return array('status' => 'Expired');
        }
        $checked = $api->checkCredit($row['credit_request']);
        if ('Paid' === $checked['status']) {
            self::markSettled($order, $checked);

            return array('status' => 'Paid');
        }

        return array('status' => $checked['status'], 'secondsLeft' => (int) $checked['secondsLeft']);
    }
}
