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
 * Gamma Wallet for PrestaShop.
 *
 * Customers earn a reward for every paid order and can settle an order with the store credits they
 * hold at the shop, by scanning a QR code with the Gamma Wallet app. Credits are a promise of value
 * at the business — not money — and Gamma never handles a payment.
 *
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

require_once __DIR__ . '/classes/GammaWalletApi.php';
require_once __DIR__ . '/classes/GammaWalletSettings.php';
require_once __DIR__ . '/classes/GammaWalletOrder.php';

class GammaWallet extends PaymentModule
{
    public const VERSION = '1.1.1';

    /** Order-email placeholders the module adds to order_conf: the QR code (HTML part) and a link (text part). */
    public const EMAIL_PLACEHOLDER = '{gamma_wallet_reward}';
    public const EMAIL_PLACEHOLDER_TXT = '{gamma_wallet_reward_txt}';

    public const HOOKS = [
        'paymentOptions',
        'displayPaymentReturn',
        'displayOrderConfirmation',
        'displayOrderDetail',
        'actionOrderStatusPostUpdate',
        'actionFrontControllerSetMedia',
        'actionEmailAddAfterContent',
        'sendMailAlterTemplateVars',
        'displayAdminOrderSide',
        'displayBackOfficeHeader',
    ];

    public function __construct()
    {
        $this->name = 'gammawallet';
        $this->tab = 'payments_gateways';
        $this->version = self::VERSION;
        $this->author = 'Gamma Wallet';
        $this->need_instance = 0;
        // Tested on PrestaShop 8.2 and 9.2.
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '9.99.99'];
        $this->bootstrap = true;
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        parent::__construct();

        $this->displayName = $this->l('Gamma Wallet');
        $this->description = $this->l('Rewards for paid orders and store credits at checkout, with the Gamma Wallet app.');
        $this->confirmUninstall = $this->l('Remove Gamma Wallet? Rewards already given stay in your customers\' wallets.');
    }

    // ------------------------------------------------------------------ install

    public function install()
    {
        return parent::install()
            && $this->registerHook(self::HOOKS)
            && Db::getInstance()->execute(GammaWalletOrder::installSql())
            && $this->installOrderState()
            && Configuration::updateValue(GammaWalletSettings::REWARDS, 1)
            && Configuration::updateValue(GammaWalletSettings::REWARD_EMAIL, 1)
            && Configuration::updateValue(GammaWalletSettings::CREDITS, 1)
            && Configuration::updateValue(GammaWalletSettings::NO_REWARD_MODULES, json_encode(GammaWalletSettings::PAY_LATER_MODULES))
            && self::recordInstall()
            && $this->allowEverywhere();
    }

    /**
     * Gamma works for customers in any country. PrestaShop links a new payment module only to the
     * countries active at install time, so a shop selling to more countries later would have to tick
     * them by hand: every country (active or not) is allowed here instead. The shop can still narrow it
     * in Payment > Preferences.
     */
    public function allowEverywhere()
    {
        $db = Db::getInstance();
        foreach (Shop::getShops(false, null, true) as $idShop) {
            $db->execute('INSERT IGNORE INTO `' . _DB_PREFIX_ . 'module_country` (`id_module`, `id_shop`, `id_country`) '
                . 'SELECT ' . (int) $this->id . ', ' . (int) $idShop . ', `id_country` FROM `' . _DB_PREFIX_ . 'country`');
        }

        return true;
    }

    /** The install date (orders before it earn no reward) and the cron task's secret. Also run on upgrade. */
    public static function recordInstall()
    {
        if (!(int) Configuration::get(GammaWalletSettings::INSTALLED_ON)) {
            Configuration::updateValue(GammaWalletSettings::INSTALLED_ON, time());
        }
        if ('' === (string) Configuration::get(GammaWalletSettings::CRON_TOKEN)) {
            Configuration::updateValue(GammaWalletSettings::CRON_TOKEN, bin2hex(random_bytes(16)));
        }

        return true;
    }

    public function uninstall()
    {
        foreach ([
            GammaWalletSettings::TOKEN, GammaWalletSettings::REWARDS, GammaWalletSettings::NO_REWARD_MODULES,
            GammaWalletSettings::REWARD_EMAIL, GammaWalletSettings::CREDITS, GammaWalletSettings::CONNECTION,
            GammaWalletSettings::INSTALLED_ON, GammaWalletSettings::CRON_TOKEN, GammaWalletSettings::RECONCILED_AT,
        ] as $key) {
            Configuration::deleteByName($key);
        }
        // The order state stays: past orders still point at it. The table goes with the module.
        Db::getInstance()->execute(GammaWalletOrder::uninstallSql());

        return parent::uninstall();
    }

    /** "Awaiting Gamma store credits": an order placed with store credits until Gamma says it is settled. */
    private function installOrderState()
    {
        $existing = (int) Configuration::get(GammaWalletSettings::OS_AWAITING);
        if ($existing && Validate::isLoadedObject(new OrderState($existing))) {
            return true;
        }
        $state = new OrderState();
        $state->name = [];
        foreach (Language::getLanguages(false) as $language) {
            $state->name[$language['id_lang']] = 'Awaiting Gamma store credits';
        }
        $state->module_name = $this->name;
        $state->color = '#4fb7e8';
        $state->unremovable = true;
        $state->hidden = false;
        $state->logable = false;
        $state->paid = false;
        $state->send_email = false;
        $state->invoice = false;
        $state->shipped = false;
        $state->delivery = false;
        if (!$state->add()) {
            return false;
        }
        // The state's icon in the order list. Not having it is only cosmetic.
        $icon = _PS_ORDER_STATE_IMG_DIR_ . (int) $state->id . '.gif';
        if (is_writable(_PS_ORDER_STATE_IMG_DIR_) && !copy(__DIR__ . '/views/img/order-state.gif', $icon)) {
            GammaWalletApi::log('The icon of the "Awaiting Gamma store credits" order state could not be copied.', 1);
        }

        return Configuration::updateValue(GammaWalletSettings::OS_AWAITING, (int) $state->id);
    }

    // ------------------------------------------------------------------ store credits at checkout

    /** "Use Store Credits with Gamma", offered only when it can work for this cart. */
    public function hookPaymentOptions($params)
    {
        if (!$this->active || !GammaWalletSettings::creditsEnabled() || !GammaWalletSettings::rewardServiceActive()) {
            return [];
        }
        $cart = $params['cart'];
        $currency = new Currency((int) $cart->id_currency);
        if (!GammaWalletSettings::currencyMatches($currency->iso_code) || (float) $cart->getOrderTotal(true, Cart::BOTH) <= 0
            || self::cartSplits($cart)) {
            return [];
        }
        $option = new PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText($this->l('Use Store Credits with Gamma'))
            ->setLogo(Media::getMediaPath(__DIR__ . '/views/img/gamma-mark-20.png'))
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', [], true))
            ->setAdditionalInformation($this->fetch('module:gammawallet/views/templates/hook/payment_option.tpl'));

        return [$option];
    }

    /**
     * True when the cart becomes several orders (several carriers or delivery addresses): store
     * credits settle one whole order, so they are not offered then.
     */
    public static function cartSplits(Cart $cart)
    {
        if ($cart->isMultiAddressDelivery()) {
            return true;
        }
        $packages = 0;
        foreach ((array) $cart->getPackageList() as $addressPackages) {
            $packages += count((array) $addressPackages);
        }

        return $packages > 1;
    }

    /** The order confirmation page of an order placed with store credits: the QR code until settled. */
    public function hookDisplayPaymentReturn($params)
    {
        $order = isset($params['order']) ? $params['order'] : null;
        if (!$this->active || !$order instanceof Order || $order->module !== $this->name) {
            return '';
        }

        return $this->creditBox($order);
    }

    // ------------------------------------------------------------------ rewards

    /** The order confirmation page of an order paid any other way: the reward, or when it will come. */
    public function hookDisplayOrderConfirmation($params)
    {
        $order = isset($params['order']) ? $params['order'] : null;
        if (!$this->active || !$order instanceof Order || $order->module === $this->name) {
            return '';
        }

        return $this->rewardSection($order, true);
    }

    /** The customer's order page (also the guest order-tracking page). */
    public function hookDisplayOrderDetail($params)
    {
        $order = isset($params['order']) ? $params['order'] : null;
        if (!$this->active || !$order instanceof Order) {
            return '';
        }
        if ($order->module === $this->name) {
            if (GammaWalletCredits::isSettled($order)) {
                return $this->note($this->l('This order is settled with your store credits through Gamma Wallet.'));
            }

            return GammaWalletCredits::isAwaiting($order)
                ? $this->creditBox($order)
                : $this->note($this->l('This order is no longer waiting for store credits.'));
        }

        return $this->rewardSection($order, true);
    }

    /** Every status change: once an order is paid, it gets its reward (and, if paid later, its email). */
    public function hookActionOrderStatusPostUpdate($params)
    {
        $state = isset($params['newOrderStatus']) ? $params['newOrderStatus'] : null;
        if (!$this->active || !$state instanceof OrderState || !$state->paid) {
            return;
        }
        $order = new Order((int) $params['id_order']);
        if (!Validate::isLoadedObject($order) || $order->module === $this->name) {
            return;
        }
        if (GammaWalletRewards::ensureBill($order, 10)) {
            GammaWalletRewards::emailPayLaterOnce($order);
        }
    }

    private function rewardSection(Order $order, $poll)
    {
        // Short wait: the page is being built. Whether it was collected is asked by the page script.
        if (GammaWalletRewards::ensureBill($order, 5)) {
            return $this->rewardBox($order, GammaWalletOrder::get($order->id), $poll);
        }
        if (!GammaWalletRewards::mayEarn($order) || $order->hasBeenPaid()) {
            return '';
        }

        return $this->note(GammaWalletSettings::isPayLater($order->module)
            ? $this->l('This order earns a Gamma Wallet reward. Once your payment is received, we will email you a QR code to collect it.')
            : $this->l('As soon as your payment is confirmed, you will receive a QR code by email to collect your reward with Gamma Wallet.'));
    }

    // ------------------------------------------------------------------ the reward in the order email

    /** Adds a placeholder for the reward to the order confirmation email. */
    public function hookActionEmailAddAfterContent($params)
    {
        if (!$this->active || 'order_conf' !== $params['template'] || !GammaWalletSettings::rewardInEmail()) {
            return;
        }
        $params['template_html'] = str_replace('</body>', self::EMAIL_PLACEHOLDER . '</body>', $params['template_html'], $count);
        if (!$count) {
            $params['template_html'] .= self::EMAIL_PLACEHOLDER;
        }
        $params['template_txt'] .= "\n" . self::EMAIL_PLACEHOLDER_TXT;
    }

    /** Fills the placeholder: the reward QR code for an order paid at checkout, otherwise nothing. */
    public function hookSendMailAlterTemplateVars($params)
    {
        if ('order_conf' !== $params['template']) {
            return;
        }
        $params['template_vars'][self::EMAIL_PLACEHOLDER] = '';
        $params['template_vars'][self::EMAIL_PLACEHOLDER_TXT] = '';
        $idOrder = isset($params['template_vars']['{id_order}']) ? (int) $params['template_vars']['{id_order}'] : 0;
        if (!$this->active || !$idOrder || !GammaWalletSettings::rewardInEmail()) {
            return;
        }
        $order = new Order($idOrder);
        // A pay-later order gets its own reward email once paid; this one was written before that.
        if (!Validate::isLoadedObject($order) || GammaWalletSettings::isPayLater($order->module) || !GammaWalletRewards::ensureBill($order, 10)) {
            return;
        }
        $row = GammaWalletOrder::get($order->id);
        $this->context->smarty->assign(['gw_qr' => $row['qr_url'], 'gw_link' => $row['link']]);
        $params['template_vars'][self::EMAIL_PLACEHOLDER] = $this->fetch('module:gammawallet/views/templates/hook/email_reward.tpl');
        $params['template_vars'][self::EMAIL_PLACEHOLDER_TXT] = $this->l('Collect your reward with Gamma Wallet: open this link on your phone:') . "\n" . $row['link'];
    }

    // ------------------------------------------------------------------ the customer's pages

    public function hookActionFrontControllerSetMedia()
    {
        $page = isset($this->context->controller->php_self) ? $this->context->controller->php_self : '';
        if (!in_array($page, ['order-confirmation', 'order-detail', 'guest-tracking'], true)) {
            return;
        }
        $this->context->controller->registerStylesheet('gammawallet', 'modules/' . $this->name . '/views/css/gamma-wallet.css', ['media' => 'all', 'priority' => 150]);
        $this->context->controller->registerJavascript('gammawallet', 'modules/' . $this->name . '/views/js/gamma-wallet.js', ['position' => 'bottom', 'priority' => 150]);
        Media::addJsDef(['gammaWalletText' => [
            'secondsLeft' => $this->l('%d s left'),
            'timeLeft' => $this->l('%s left'),
            'expired' => $this->l('This code has expired.'),
            'settled' => $this->l('Done! Your order is settled with your store credits.'),
            'claimed' => $this->l('Reward collected. Thank you!'),
            'unavailable' => $this->l('Gamma cannot be reached right now. Please try again in a moment.'),
        ]]);
    }

    private function statusUrl(Order $order, $controller = 'status')
    {
        return $this->context->link->getModuleLink($this->name, $controller, ['id_order' => (int) $order->id, 'key' => $order->secure_key], true);
    }

    private function rewardBox(Order $order, array $row, $poll)
    {
        $claimed = 'Claimed' === $row['bill_status'];
        $this->context->smarty->assign([
            'gw_status_url' => $this->statusUrl($order),
            'gw_poll' => $poll && !$claimed,
            'gw_claimed' => $claimed,
            'gw_qr' => $row['qr_url'],
            'gw_link' => $row['link'],
            'gw_logo' => $this->_path . 'views/img/gamma-logo.png',
        ]);

        return $this->fetch('module:gammawallet/views/templates/hook/reward_box.tpl');
    }

    private function creditBox(Order $order)
    {
        if (GammaWalletCredits::isSettled($order)) {
            return $this->note($this->l('Your order is settled with your store credits through Gamma Wallet.'));
        }
        $row = GammaWalletOrder::get($order->id);
        $expires = $row['credit_expires_on'] ? strtotime($row['credit_expires_on']) : 0;
        $seconds = $expires ? max(0, $expires - time()) : 0;
        $this->context->smarty->assign([
            'gw_status_url' => $this->statusUrl($order),
            'gw_new_code_url' => $this->statusUrl($order, 'newcode'),
            'gw_seconds' => $seconds,
            'gw_qr' => $row['credit_qr'] ? 'data:image/png;base64,' . $row['credit_qr'] : '',
            'gw_link' => (string) $row['credit_link'],
            'gw_total' => Tools::getContextLocale($this->context)->formatPrice((float) $order->total_paid, (new Currency((int) $order->id_currency))->iso_code),
            'gw_logo' => $this->_path . 'views/img/gamma-logo.png',
        ]);

        return $this->fetch('module:gammawallet/views/templates/hook/credit_box.tpl');
    }

    private function note($text)
    {
        $this->context->smarty->assign('gw_note', $text);

        return $this->fetch('module:gammawallet/views/templates/hook/note.tpl');
    }

    // ------------------------------------------------------------------ the shop's order page

    public function hookDisplayAdminOrderSide($params)
    {
        $order = new Order((int) $params['id_order']);
        if (!Validate::isLoadedObject($order)) {
            return '';
        }
        $row = GammaWalletOrder::get($order->id);
        $lines = [];
        $canResend = false;
        if ($order->module === $this->name) {
            if (!GammaWalletCredits::isSettled($order) && $row['credit_request'] && GammaWalletCredits::isAwaiting($order)) {
                // The customer may have confirmed in the app after leaving the page: ask Gamma now.
                try {
                    GammaWalletCredits::status($order);
                    $order = new Order((int) $order->id);
                    $row = GammaWalletOrder::get($order->id);
                } catch (GammaWalletApiError $e) {
                    GammaWalletApi::log(sprintf('Checking order %s failed: %s', $order->reference, $e->getMessage()), 2, $order->id);
                }
            }
            $lines[] = GammaWalletCredits::isSettled($order)
                ? $this->l('Settled with store credits through Gamma Wallet.')
                : $this->l('Waiting for the customer to settle it with store credits.');
            if ($row['credit_request_id']) {
                $lines[] = $this->l('Request') . ': ' . $row['credit_request_id'];
            }
            $lines[] = $this->l('No reward is given for an order settled with store credits.');
        } elseif ($row['bill_id'] || GammaWalletRewards::ensureBill($order, 5)) {
            $row = GammaWalletOrder::get($order->id);
            if ('Claimed' !== $row['bill_status']) {
                GammaWalletRewards::refreshStatus($order, 5);
                $row = GammaWalletOrder::get($order->id);
            }
            $lines[] = 'Claimed' === $row['bill_status'] ? $this->l('Reward collected') : $this->l('Waiting for the customer to collect the reward');
            $lines[] = $this->l('Bill') . ': ' . $row['bill_id'];
            $canResend = 'Claimed' !== $row['bill_status'];
        } elseif ($row['error']) {
            $lines[] = $row['error'];
            $canResend = GammaWalletRewards::mayEarn($order);
        } elseif (!GammaWalletSettings::rewardServiceActive()) {
            $lines[] = $this->l('No reward: your business has no Reward service active in Gamma.');
        } elseif (!GammaWalletRewards::mayEarn($order)) {
            $lines[] = $this->l('This order earns no reward (its payment method does not earn one).');
        } else {
            $lines[] = GammaWalletSettings::isPayLater($order->module)
                ? $this->l('Paid later: when you mark the order paid, the reward QR code is created and the customer receives it in an email of its own.')
                : $this->l('Not sent to Gamma Wallet yet. It is sent when the payment is confirmed.');
        }
        $flash = (string) $this->context->cookie->__get('gw_flash');
        if ('' !== $flash) {
            $this->context->cookie->__unset('gw_flash');
        }
        $this->context->smarty->assign([
            'gw_flash' => $flash,
            'gw_lines' => $lines,
            'gw_qr' => $order->module === $this->name ? '' : (string) $row['qr_url'],
            'gw_resend_url' => $canResend
                ? $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name, 'gw_resend' => (int) $order->id])
                : '',
            'gw_logo' => $this->_path . 'views/img/gamma-mark-64.png',
        ]);

        return $this->fetch('module:gammawallet/views/templates/admin/order_side.tpl');
    }

    /**
     * While someone uses the back office, waiting store-credit orders are checked with Gamma at most
     * every 2 minutes, so an order paid after the customer left the page is settled even without the
     * cron task.
     */
    public function hookDisplayBackOfficeHeader()
    {
        if (!$this->active || '' === GammaWalletSettings::token()
            || (int) Configuration::get(GammaWalletSettings::RECONCILED_AT) > time() - 120) {
            return '';
        }
        Configuration::updateValue(GammaWalletSettings::RECONCILED_AT, time());
        GammaWalletCredits::reconcile(5);

        return '';
    }

    // ------------------------------------------------------------------ settings page

    public function getContent()
    {
        $messages = '';
        $idResend = (int) Tools::getValue('gw_resend');
        if ($idResend) {
            $order = new Order($idResend);
            // A click is a deliberate new try, also after the automatic ones ran out.
            $resendRow = GammaWalletOrder::get($idResend);
            if (!$resendRow['bill_id']) {
                GammaWalletOrder::save($idResend, ['attempts' => 0]);
            }
            $sent = Validate::isLoadedObject($order) && GammaWalletRewards::sendRewardEmail($order);
            $orderUrl = $this->context->link->getAdminLink('AdminOrders', true, ['route' => 'admin_orders_view', 'orderId' => $idResend]);
            $this->context->cookie->__set('gw_flash', $sent ? 'sent' : 'failed');
            Tools::redirectAdmin($orderUrl);
        }

        if (Tools::isSubmit('submitGammaWallet')) {
            $token = trim((string) Tools::getValue('gw_token'));
            if (Tools::getValue('gw_remove_token')) {
                Configuration::updateValue(GammaWalletSettings::TOKEN, '');
            } elseif ('' !== $token) {
                Configuration::updateValue(GammaWalletSettings::TOKEN, $token);
            }
            Configuration::updateValue(GammaWalletSettings::REWARDS, Tools::getValue('gw_rewards') ? 1 : 0);
            Configuration::updateValue(GammaWalletSettings::REWARD_EMAIL, Tools::getValue('gw_reward_email') ? 1 : 0);
            Configuration::updateValue(GammaWalletSettings::CREDITS, Tools::getValue('gw_credits') ? 1 : 0);

            // Every payment module shown but left unticked earns no reward. Pay-later modules that
            // are not installed stay excluded, so they are off by default if installed later.
            $ticked = (array) Tools::getValue('gw_reward_modules', []);
            $shown = array_column($this->paymentModules(), 'name');
            $excluded = array_values(array_unique(array_merge(
                array_diff($shown, $ticked),
                array_diff(GammaWalletSettings::PAY_LATER_MODULES, $shown)
            )));
            Configuration::updateValue(GammaWalletSettings::NO_REWARD_MODULES, json_encode($excluded));
            GammaWalletSettings::checkConnection();
            $messages .= $this->displayConfirmation($this->l('Settings saved.'));
        } elseif (Tools::isSubmit('gwCheckAgain')) {
            GammaWalletSettings::checkConnection();
        } else {
            GammaWalletSettings::refreshIfStale(10);
        }

        $connection = GammaWalletSettings::connection();
        $token = GammaWalletSettings::token();
        $methods = [];
        foreach ($this->paymentModules() as $module) {
            $methods[] = [
                'name' => $module['name'],
                'title' => $module['title'],
                'ticked' => GammaWalletSettings::moduleEarnsReward($module['name']),
                'payLater' => GammaWalletSettings::isPayLater($module['name']),
            ];
        }
        $shopCurrency = Currency::getDefaultCurrency();
        $this->context->smarty->assign([
            'gw_action' => $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]),
            'gw_has_token' => '' !== $token,
            'gw_masked' => '' !== $token ? GammaWalletSettings::masked($token) : '',
            'gw_connection' => $connection,
            'gw_checked_on' => $connection && isset($connection['checkedOn']) ? date('Y-m-d H:i', (int) $connection['checkedOn']) : '',
            'gw_currency_ok' => GammaWalletSettings::currencyMatches($shopCurrency->iso_code),
            'gw_shop_currency' => $shopCurrency->iso_code,
            'gw_rewards' => GammaWalletSettings::rewardsEnabled(),
            'gw_reward_email' => GammaWalletSettings::rewardInEmail(),
            'gw_credits' => GammaWalletSettings::creditsEnabled(),
            'gw_methods' => $methods,
            'gw_logo' => $this->_path . 'views/img/gamma-logo.png',
            'gw_cron_url' => GammaWalletSettings::cronUrl(),
        ]);

        return $messages . $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }

    /** The shop's payment modules other than this one, with their names as the customer sees them. */
    private function paymentModules()
    {
        $modules = [];
        foreach (PaymentModule::getInstalledPaymentModules() as $installed) {
            if ($installed['name'] === $this->name) {
                continue;
            }
            $instance = Module::getInstanceByName($installed['name']);
            $modules[] = [
                'name' => $installed['name'],
                'title' => $instance ? $instance->displayName : $installed['name'],
            ];
        }

        return $modules;
    }
}
