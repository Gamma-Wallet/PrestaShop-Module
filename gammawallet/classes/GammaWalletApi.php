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
 * Gamma Wallet for PrestaShop — the calls to the Gamma Integration API.
 *
 * Every call carries the shop's integration token (GWINT_…) and runs on the shop's server, never in
 * the customer's browser. Gamma answers in an envelope {version, statusCode, message, result, error};
 * this class returns `result` or throws GammaWalletApiError.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class GammaWalletApiError extends Exception
{
    /** @var int HTTP status, 0 when Gamma could not be reached at all */
    public $status;

    /** @var string|null Gamma's error number, such as "0390" */
    public $identifier;

    /** @var string|null Gamma's error name, such as "IntegrationTokenExpired" */
    public $errorName;

    public function __construct($status, $identifier, $errorName)
    {
        $this->status = (int) $status;
        $this->identifier = $identifier;
        $this->errorName = $errorName;
        parent::__construct(sprintf(
            'Gamma answered HTTP %d: %s%s',
            $this->status,
            $errorName ? $errorName : 'no details',
            $identifier ? ' (' . $identifier . ')' : ''
        ));
    }

    public function isTokenProblem()
    {
        return 401 === $this->status;
    }

    /** Worth trying again later: Gamma unreachable, busy or failing. */
    public function isRetryable()
    {
        return 0 === $this->status || 429 === $this->status || $this->status >= 500;
    }
}

class GammaWalletApi
{
    /** Can be overridden in config/defines.inc.php for testing: define('GAMMAWALLET_API_URL', 'https://…'); */
    const DEFAULT_URL = 'https://integration.gamma-wallet.com';

    /** @var string */
    private $token;

    public function __construct($token)
    {
        $this->token = (string) $token;
    }

    /** The API for the saved token, or null when the shop is not connected. */
    public static function fromSettings()
    {
        $token = GammaWalletSettings::token();

        return '' === $token ? null : new self($token);
    }

    public static function baseUrl()
    {
        return rtrim(defined('GAMMAWALLET_API_URL') ? GAMMAWALLET_API_URL : self::DEFAULT_URL, '/');
    }

    /** Who the token belongs to: business, currency, whether customers can claim, token expiry. */
    public function connection($timeout = 20)
    {
        return $this->send('GET', '/api/Connection/Me', null, $timeout);
    }

    /** Declares a paid order. Safe to repeat with the same reference: Gamma returns the same bill. */
    public function createBill(array $bill, $timeout = 20)
    {
        return $this->send('POST', '/api/Bill/Create', $bill, $timeout);
    }

    public function getBill($billId, $timeout = 20)
    {
        return $this->send('GET', '/api/Bill/Get/' . rawurlencode($billId), null, $timeout);
    }

    /** A new store-credit request for the whole order. */
    public function startCredit(array $order)
    {
        return $this->send('POST', '/api/Credit/Start', $order);
    }

    /** Waiting, Paid or Expired, with the seconds left. */
    public function checkCredit($creditRequest)
    {
        return $this->send('POST', '/api/Credit/Check', array('creditRequest' => $creditRequest));
    }

    private function send($method, $path, $body = null, $timeout = 20)
    {
        $headers = array(
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
            'User-Agent: gamma-wallet-prestashop/' . GammaWallet::VERSION . '; ' . Tools::getShopDomainSsl(true),
        );
        $curl = curl_init(self::baseUrl() . $path);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, (int) $timeout);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, min(10, (int) $timeout));
        if (null !== $body) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($curl, CURLOPT_POSTFIELDS, self::json($body));
        }
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($curl);
        if (false === $raw) {
            $message = curl_error($curl);
            curl_close($curl);
            throw new GammaWalletApiError(0, null, 'Gamma could not be reached: ' . $message);
        }
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $envelope = json_decode((string) $raw, true);
        if ($status >= 200 && $status < 300 && is_array($envelope) && isset($envelope['result']) && is_array($envelope['result'])) {
            return $envelope['result'];
        }
        $error = (is_array($envelope) && isset($envelope['error']) && is_array($envelope['error'])) ? $envelope['error'] : array();
        throw new GammaWalletApiError(
            $status,
            isset($error['identifier']) ? $error['identifier'] : null,
            isset($error['message']) ? $error['message'] : null
        );
    }

    /**
     * JSON with amounts written exactly: 0.8, never 0.80000000000000004. Servers whose PHP has
     * serialize_precision = 17 (PrestaShop's Docker image, many hosts) write the long form, and Gamma
     * then signs a total the customer's app does not match.
     */
    public static function json(array $body)
    {
        $previous = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        $json = json_encode($body, JSON_PRESERVE_ZERO_FRACTION);
        ini_set('serialize_precision', $previous);

        return $json;
    }

    /** Writes to Advanced Parameters → Logs. Never pass the token. */
    public static function log($message, $severity = 2, $idOrder = null)
    {
        PrestaShopLogger::addLog('[Gamma Wallet] ' . $message, $severity, null, $idOrder ? 'Order' : null, $idOrder ? (int) $idOrder : null, true);
    }
}
