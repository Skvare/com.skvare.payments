# Bulk Payment Retry

A CiviCRM extension that replays logged payment notifications (IPNs) for a
list of transaction IDs. Use it when a payment processor notification reached
CiviCRM but failed to record the payment.

CiviCRM logs every incoming payment notification in `civicrm_system_log`. This
extension finds the notifications that contain the transaction IDs you paste,
and replays each one with the `NotificationLog.retry` API from
[nz.co.fuzion.notificationlog](https://github.com/eileenmcnaughton/nz.co.fuzion.notificationlog).

The extension is licensed under [AGPL-3.0](LICENSE.txt).

## Requirements

* CiviCRM 6.16 or later
* PHP 8.2 or later
* Extension: `nz.co.fuzion.notificationlog`

## Supported processors

`NotificationLog.retry` can only replay notifications from these processor
types:

* PayPal (Website Payments Pro)
* PayPal Standard
* Authorize.Net, the legacy processor built into core (silent post)

It cannot replay Stripe or the Authorize.Net (authnet) extension. For those,
use the processors' own tools:

* **Stripe:** the `Stripe.Ipn` API (`id` = system log ID, or `evtid` + `ppid`).
* **Stripe and authnet:** the payment processor webhook queue
  (`civicrm_paymentprocessor_webhook`) and its "Retry" task, provided by
  mjwshared, or authnet's `AuthnetUtilities.Retry` API.

Transaction IDs from those processors are reported as "Not found".

## Installation

On sites managed with composer, add the extension to the project's
`composer.json`. Otherwise install it with [cv](https://github.com/civicrm/cv):

```bash
cd <extension-dir>
cv dl com.skvare.payments@https://github.com/Skvare/com.skvare.payments/archive/master.zip
cv en payments
```

## Usage

1. Go to **Contributions → Bulk Payment Retry**
   (`civicrm/failedpayment/retry`). This needs the `administer CiviCRM`
   permission.
2. Paste the transaction IDs, separated by spaces, commas or new lines, up to
   100 at a time. Each ID must be at least 6 characters long and contain only
   letters, digits, `.`, `_` or `-`.
3. Optionally tick:
   * **Download Processed Data:** get the results as `Processed_Payment.csv`
     instead of a table.
   * **Debug:** show the full output of the processor's notification handler
     in the Detail column. Without it, the output is cut to 200 characters.
4. Click **Submit**.

An ID matches a logged notification only when it appears there as a complete
value (for example `txn_id`, `parent_txn_id` or `recurring_payment_id`), never
as part of a longer value. A recurring profile ID such as `I-…` matches every
notification of that profile.

Matching notifications are processed once each, in the order they were logged,
across all the pasted IDs. Order matters for PayPal Pro: while a series' first
contribution is pending, PayPal Pro applies the replayed payment to it. Each
notification gets its own row in the result.

Run the retry from the site of the domain the payment belongs to: the domain of
its recurring series, or, for a payment outside a series, the domain of the
processor that received the notification. PayPal sometimes sent a series'
notifications to another domain's IPN URL.

## Results

| Status | Meaning |
| --- | --- |
| Not found | No PayPal or legacy Authorize.Net notification in the log has this ID. |
| Unreadable log entry | The logged notification is empty or not valid JSON. Not replayed. |
| Other domain | The payment's recurring series (or, without a series, the processor that received the notification) belongs to another domain. Not replayed; run the retry from that domain's site. |
| Already recorded | A contribution, or a payment on a contribution, already has the notification's transaction ID (`txn_id` / `x_trans_id`). Not replayed. |
| Possibly recorded | PayPal Pro recurring payment: the same recurring series has a completed contribution with the same amount, no transaction ID and a receive date within a day. Not replayed, because PayPal Pro would record it again. Record it manually if it really is a different payment. |
| Processed | Replayed, and a contribution now has the transaction ID. |
| Retried, nothing recorded | Replayed, but no contribution has the transaction ID. The Detail column shows what the processor's handler printed (for example "Invalid or missing data"). |
| Retried | Replayed. The notification has no transaction ID to check (for example a profile created or expired notification). |
| Failed | The retry API threw an error, shown in the Detail column. |

Notes:

* Each replay is written to the CiviCRM log, with the user, system log ID,
  transaction ID and result.
* The `com.skvare.memberships` signup-page session flags are cleared during a
  replay. Otherwise that extension removes the line items of a replayed
  installment.
* When a notification has neither a processor ID (it was logged as
  `processor_name=…`) nor a recurring series, its domain cannot be checked.
  The Detail column says so.
* A "Processed" row can still show handler output such as "Invalid or missing
  data". The payment was recorded, but core failed afterwards. For example,
  PayPal Pro needs a `profile_status` that some logged notifications lack.

## Known limitations

* **PayPal Pro event installments cannot be replayed.** CiviCRM core rejects
  recurring notifications whose `rp_invoice_id` has `m=event` but no `e` and
  `p` values, with "Failure: Missing Parameter e". These end as "Retried,
  nothing recorded" with the output "Invalid or missing data", and have to be
  recorded manually.
* **Searching is not instant.** `civicrm_system_log` has no index on the
  notification text, so every submission scans the replayable processors'
  notifications.
* **The retry API has PHP 8 warnings.** `NotificationLog.retry` itself warns
  about an undefined `$processorId` for `processor_name=` notifications.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Support and Contributing

- **Issues:** Report bugs and feature requests on [GitHub Issues](https://github.com/Skvare/com.skvare.payments/issues)

## Credits

Developed by [Skvare, LLC](https://skvare.com/contact) for the CiviCRM community.

## About Skvare

Skvare LLC specializes in CiviCRM development, Drupal integration, and providing technology solutions for nonprofit organizations, professional societies, membership-driven associations, and small businesses. We are committed to developing open source software that empowers our clients and the wider CiviCRM community.

**Contact Information**:
- Website: [https://skvare.com](https://skvare.com)
- Email: info@skvare.com
- GitHub: [https://github.com/Skvare](https://github.com/Skvare)

## Support

[Contact us](https://skvare.com/contact) for support or to learn more.

---

## Related Extensions

You might also be interested in other Skvare CiviCRM extensions:

- **Database Custom Field Check**: Prevents adding custom fields when table limits are reached
- **Image Resize**: Automatically resizes contact images for consistent display
- **Registration Button Label**: Customize button labels on event registration pages
- **Unlink User Account**: Safely unlink user accounts from contacts without deleting data

For a complete list of our open source contributions, visit our [GitHub organization page](https://github.com/Skvare).
