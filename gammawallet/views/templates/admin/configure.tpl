{* Gamma Wallet settings: connection, rewards, store credits. *}
<form method="post" action="{$gw_action|escape:'html':'UTF-8'}" class="form-horizontal" autocomplete="off">
  <div class="panel">
    <div class="panel-heading"><img src="{$gw_logo|escape:'html':'UTF-8'}" alt="Gamma Wallet" height="20" style="vertical-align:-4px"></div>
    <p>{l s='Your customers earn a reward for every paid order and can settle an order with the store credits they hold at your shop, by scanning a QR code with the Gamma Wallet app.' mod='gammawallet'}</p>

    <h4>{l s='Connection' mod='gammawallet'}</h4>
    <div class="form-group">
      <label class="control-label col-lg-3" for="gw_token">{l s='Integration token' mod='gammawallet'}</label>
      <div class="col-lg-6">
        <input type="password" id="gw_token" name="gw_token" value="" spellcheck="false" placeholder="{if $gw_has_token}{$gw_masked|escape:'html':'UTF-8'}{else}GWINT_…{/if}">
        <p class="help-block">
          {l s='Create it in Gamma Business → Integrations, as the business owner. It starts with GWINT_ and is shown only once.' mod='gammawallet'}
          <a href="https://business.gamma-wallet.com" target="_blank" rel="noopener noreferrer">Gamma Business</a>
          {if $gw_has_token}<br>{l s='A token is saved. Leave the field empty to keep it.' mod='gammawallet'}{/if}
        </p>
        {if $gw_has_token}
          <label><input type="checkbox" name="gw_remove_token" value="1"> {l s='Remove the saved token (disconnects the shop)' mod='gammawallet'}</label>
        {/if}
      </div>
    </div>
    <div class="form-group">
      <label class="control-label col-lg-3">{l s='Status' mod='gammawallet'}</label>
      <div class="col-lg-6" style="padding-top:7px">
        {if !$gw_has_token}
          <span class="text-danger">{l s='Not connected. Paste your integration token above.' mod='gammawallet'}</span>
        {elseif !$gw_connection}
          {l s='Not checked yet.' mod='gammawallet'}
        {elseif isset($gw_connection.error) && !isset($gw_connection.businessName)}
          <span class="text-danger">{$gw_connection.error|escape:'html':'UTF-8'}</span>
        {else}
          {if isset($gw_connection.error)}<span class="text-warning">{$gw_connection.error|escape:'html':'UTF-8'}</span><br>{/if}
          <span class="text-success">&#10003; {l s='Connected' mod='gammawallet'}</span><br>
          {l s='Business' mod='gammawallet'}: <strong>{$gw_connection.businessName|escape:'html':'UTF-8'}</strong> ·
          {l s='Currency' mod='gammawallet'}: <strong>{$gw_connection.currencyCode|escape:'html':'UTF-8'}</strong><br>
          {l s='Token' mod='gammawallet'} <code>{$gw_masked|escape:'html':'UTF-8'}</code>, {l s='%d day(s) left' sprintf=[$gw_connection.token.daysLeft|intval] mod='gammawallet'}
          {if empty($gw_connection.canClaim)}
            <br><span class="text-danger">{l s='Gamma Wallet for PrestaShop works only with a Reward service. Your business has no Reward service active in Gamma, so customers get no reward QR code and store credits are not offered at checkout. Activate a Reward service in Gamma Business.' mod='gammawallet'}</span>
          {/if}
          {if !$gw_currency_ok}
            <br><span class="text-danger">{l s='Your shop sells in %1$s but your Gamma business uses %2$s. Orders cannot be sent to Gamma until they match.' sprintf=[$gw_shop_currency, $gw_connection.currencyCode] mod='gammawallet'}</span>
          {/if}
        {/if}
        {if $gw_checked_on}<br><span class="help-block" style="display:inline">{l s='Checked' mod='gammawallet'} {$gw_checked_on|escape:'html':'UTF-8'}</span>{/if}
        {if $gw_has_token}&nbsp;<button type="submit" name="gwCheckAgain" class="btn btn-default btn-xs">{l s='Check again' mod='gammawallet'}</button>{/if}
      </div>
    </div>

    <h4>{l s='Rewards for paid orders' mod='gammawallet'}</h4>
    <div class="form-group">
      <label class="control-label col-lg-3">{l s='Rewards' mod='gammawallet'}</label>
      <div class="col-lg-6" style="padding-top:7px">
        <label><input type="checkbox" name="gw_rewards" value="1"{if $gw_rewards} checked{/if}> {l s='Give customers a QR code to collect their reward for each paid order' mod='gammawallet'}</label>
      </div>
    </div>
    <div class="form-group">
      <label class="control-label col-lg-3">{l s='Payment methods that earn a reward' mod='gammawallet'}</label>
      <div class="col-lg-6" style="padding-top:7px">
        {foreach $gw_methods as $method}
          <label style="display:block;font-weight:normal;margin-bottom:6px">
            <input type="checkbox" name="gw_reward_modules[]" value="{$method.name|escape:'html':'UTF-8'}"{if $method.ticked} checked{/if}>
            <strong>{$method.title|escape:'html':'UTF-8'}</strong>
            <span class="text-muted">— {if $method.payLater}{l s='paid later: the reward is emailed when you mark the order paid' mod='gammawallet'}{else}{l s='paid at checkout: the reward is given when the payment is confirmed' mod='gammawallet'}{/if}</span>
          </label>
        {foreachelse}
          <span class="text-muted">{l s='No other payment modules are installed.' mod='gammawallet'}</span>
        {/foreach}
        <p class="help-block">{l s='Paid at checkout (card and the like): the reward is given the moment the payment is confirmed, and its QR code is on the order confirmation page and in the order email.' mod='gammawallet'}</p>
        <p class="help-block">{l s='Paid later (cash on delivery, bank transfer, cheque): nothing is paid when the order is placed, so the reward is given only when you mark the order paid (for example "Payment accepted" or "Delivered"). The customer then receives an email of its own with the QR code, also if they bought as a guest. You can send it again from the order page.' mod='gammawallet'}</p>
        <p class="help-block">{l s='Orders settled with store credits never earn a reward.' mod='gammawallet'}</p>
      </div>
    </div>
    <div class="form-group">
      <label class="control-label col-lg-3">{l s='Email' mod='gammawallet'}</label>
      <div class="col-lg-6" style="padding-top:7px">
        <label><input type="checkbox" name="gw_reward_email" value="1"{if $gw_reward_email} checked{/if}> {l s='Put the reward QR code in the order confirmation email' mod='gammawallet'}</label>
      </div>
    </div>

    <h4>{l s='Store credits at checkout' mod='gammawallet'}</h4>
    <div class="form-group">
      <label class="control-label col-lg-3">{l s='Use Store Credits with Gamma' mod='gammawallet'}</label>
      <div class="col-lg-6" style="padding-top:7px">
        <label><input type="checkbox" name="gw_credits" value="1"{if $gw_credits} checked{/if}> {l s='Offer it as a payment option at checkout' mod='gammawallet'}</label>
        <p class="help-block">{l s='At checkout, the customer scans a QR code with Gamma Wallet and the whole order is settled from their store credits. The code is valid for 60 seconds. An order settled this way earns no reward.' mod='gammawallet'}</p>
      </div>
    </div>

    <div class="panel-footer">
      <button type="submit" name="submitGammaWallet" class="btn btn-primary pull-right">{l s='Save and check the connection' mod='gammawallet'}</button>
    </div>
  </div>
</form>
