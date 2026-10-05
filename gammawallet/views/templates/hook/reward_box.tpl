{* The reward QR code. Asks this shop (never Gamma) every 5 seconds until the reward is collected. *}
<section class="gamma-wallet-box" data-kind="reward" data-status-url="{$gw_status_url|escape:'html':'UTF-8'}" data-poll="{if $gw_poll}1{else}0{/if}">
  <div class="gw-card{if $gw_claimed} gw-is-done{/if}">
    <div class="gw-qr-col">
      <div class="gamma-wallet-qr gw-qr-tile">
        <img src="{$gw_qr|escape:'html':'UTF-8'}" width="200" height="200" alt="{l s='Reward QR code' mod='gammawallet'}">
      </div>
      <div class="gw-check" aria-hidden="true">&#10003;</div>
    </div>
    <div class="gw-body">
      <p class="gamma-wallet-logo"><img src="{$gw_logo|escape:'html':'UTF-8'}" alt="Gamma Wallet" height="22"></p>
      <h2 class="gamma-wallet-title">{l s='Collect your reward' mod='gammawallet'}</h2>
      <p class="gw-lead gw-when-waiting">{l s='This order earns you a reward. Add it to your Gamma Wallet in a few seconds.' mod='gammawallet'}</p>
      <ol class="gw-steps gw-when-waiting">
        <li>{l s='Open the Gamma Wallet app' mod='gammawallet'}</li>
        <li>{l s='Scan this code' mod='gammawallet'}</li>
        <li>{l s='The reward is added to your wallet' mod='gammawallet'}</li>
      </ol>
      <a class="gamma-wallet-open gw-button gw-when-waiting" href="{$gw_link|escape:'html':'UTF-8'}">{l s='On your phone? Open in Gamma Wallet' mod='gammawallet'}</a>
      <p class="gamma-wallet-done gw-done"{if !$gw_claimed} hidden{/if}>{l s='Reward collected. Thank you!' mod='gammawallet'}</p>
    </div>
  </div>
</section>
