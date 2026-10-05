{* The Gamma Wallet box on the shop's order page. *}
<div class="card mt-2" id="gammawallet-order">
  <div class="card-header">
    <h3 class="card-header-title"><img src="{$gw_logo|escape:'html':'UTF-8'}" alt="" width="18" height="18" style="vertical-align:-3px;margin-right:6px">Gamma Wallet</h3>
  </div>
  <div class="card-body">
    {if $gw_flash == 'sent'}<div class="alert alert-success">{l s='The reward QR code was emailed to the customer.' mod='gammawallet'}</div>{/if}
    {if $gw_flash == 'failed'}<div class="alert alert-danger">{l s='The reward QR code could not be sent: the order is not eligible for a reward yet, or Gamma could not be reached.' mod='gammawallet'}</div>{/if}
    {foreach $gw_lines as $line}<p class="mb-1">{$line|escape:'html':'UTF-8'}</p>{/foreach}
    {if $gw_qr}<p class="mt-2 mb-2"><img src="{$gw_qr|escape:'html':'UTF-8'}" width="120" height="120" alt=""></p>{/if}
    {if $gw_resend_url}<a class="btn btn-outline-secondary btn-sm" href="{$gw_resend_url|escape:'html':'UTF-8'}">{l s='Send the reward QR code to the customer' mod='gammawallet'}</a>{/if}
  </div>
</div>
