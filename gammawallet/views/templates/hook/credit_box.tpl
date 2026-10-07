{**
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
 *}
{* The store-credit QR code with its countdown, for an order not settled yet. *}
<section class="gamma-wallet-box gamma-wallet-credit" data-kind="credit" data-status-url="{$gw_status_url|escape:'html':'UTF-8'}" data-new-code-url="{$gw_new_code_url|escape:'html':'UTF-8'}" data-seconds-left="{$gw_seconds|intval}" data-poll="1">
  <div class="gw-card">
    <div class="gw-qr-col">
      <div class="gamma-wallet-qr gw-qr-tile">
        <img class="gamma-wallet-qr-img" src="{$gw_qr|escape:'html':'UTF-8'}" width="220" height="220" alt="{l s='Store-credit QR code' mod='gammawallet'}"{if !$gw_qr || $gw_seconds <= 0} hidden{/if}>
      </div>
      <div class="gw-timer" aria-hidden="true"><span class="gw-timer-bar"></span></div>
      <p class="gamma-wallet-countdown gw-countdown" aria-live="polite"></p>
      <div class="gw-check" aria-hidden="true">&#10003;</div>
    </div>
    <div class="gw-body">
      <p class="gamma-wallet-logo"><img src="{$gw_logo|escape:'html':'UTF-8'}" alt="Gamma Wallet" height="22"></p>
      <h2 class="gamma-wallet-title">{l s='Use your store credits' mod='gammawallet'}</h2>
      <p class="gw-lead">{l s='Settle the whole order (%s) with the store credits you hold at our shop.' sprintf=[$gw_total] mod='gammawallet'}</p>
      <ol class="gw-steps gw-when-waiting">
        <li>{l s='Open the Gamma Wallet app' mod='gammawallet'}</li>
        <li>{l s='Scan this code before the time runs out' mod='gammawallet'}</li>
        <li>{l s='Confirm, and this page updates by itself' mod='gammawallet'}</li>
      </ol>
      <a class="gamma-wallet-open gw-button gw-when-waiting" href="{$gw_link|escape:'html':'UTF-8'}">{l s='On your phone? Open in Gamma Wallet' mod='gammawallet'}</a>
      <button type="button" class="gamma-wallet-new-code gw-button" hidden>{l s='Show a new code' mod='gammawallet'}</button>
      <p class="gamma-wallet-done gw-done" hidden></p>
    </div>
  </div>
</section>
