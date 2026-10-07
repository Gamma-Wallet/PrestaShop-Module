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
{* Added to the order confirmation email of an order paid at checkout. Inline styles: email clients. *}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0;border:1px solid #d6eaf5;border-radius:12px;background:#f5fbfe">
  <tr>
    <td style="padding:20px;text-align:center;font-family:Arial,sans-serif;color:#1d2a33">
      <p style="margin:0 0 6px;font-size:18px;font-weight:bold">{l s='Collect your reward' mod='gammawallet'}</p>
      <p style="margin:0 0 14px;font-size:14px">{l s='This order earns you a reward. Scan the code with the Gamma Wallet app to add it to your wallet.' mod='gammawallet'}</p>
      <img src="{$gw_qr|escape:'html':'UTF-8'}" width="180" height="180" alt="{l s='Reward QR code' mod='gammawallet'}" style="display:block;margin:0 auto 12px">
      <a href="{$gw_link|escape:'html':'UTF-8'}" style="color:#2e9bcc;font-weight:bold;font-size:14px">{l s='On your phone? Open in Gamma Wallet' mod='gammawallet'}</a>
    </td>
  </tr>
</table>
