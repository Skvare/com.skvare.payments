<div class="help">
  <p>{ts 1=$maxIds}Paste transaction IDs, separated by spaces, commas or new lines (at most %1 at a time). Each ID is looked up in the logged payment notifications (civicrm_system_log), and every notification that contains it is replayed with the NotificationLog retry API.{/ts}</p>
  <p>{ts}Only PayPal, PayPal Standard and legacy Authorize.Net notifications can be replayed. For Stripe use the Stripe.Ipn API; for Stripe and the Authorize.Net (authnet) extension, use the payment processor webhook retry. Matching notifications are replayed once each, oldest first. Notifications whose payment is already recorded, or that belong to another domain, are skipped.{/ts}</p>
</div>
{foreach from=$elementNames item=elementName}
  <div class="crm-section">
    <div class="label">{$form.$elementName.label}</div>
    <div class="content">{$form.$elementName.html}</div>
    <div class="clear"></div>
  </div>
{/foreach}
{if $reportRows}
  <div class="crm-section">
    <table class="selector row-highlight">
      <thead>
        <tr>
          <th>{ts}Transaction ID{/ts}</th>
          <th>{ts}Log ID{/ts}</th>
          <th>{ts}Log Date{/ts}</th>
          <th>{ts}Processor{/ts}</th>
          <th>{ts}Contact ID{/ts}</th>
          <th>{ts}Status{/ts}</th>
          <th>{ts}Detail{/ts}</th>
        </tr>
      </thead>
      <tbody>
        {foreach from=$reportRows item=row}
          <tr class="{cycle values="odd-row,even-row"}">
            <td>{$row.trxid|escape}</td>
            <td>{$row.log_id|escape}</td>
            <td>{$row.log_date|escape}</td>
            <td>{$row.processor|escape}</td>
            <td>{if $row.contact_id}<a href="{crmURL p='civicrm/contact/view' q="reset=1&cid=`$row.contact_id`"}" target="_blank">{$row.contact_id|escape}</a>{/if}</td>
            <td>{$row.status|escape}</td>
            <td>{$row.detail|escape}</td>
          </tr>
        {/foreach}
      </tbody>
    </table>
  </div>
{/if}
<div class="crm-submit-buttons">
{include file="CRM/common/formButtons.tpl" location="bottom"}
</div>
