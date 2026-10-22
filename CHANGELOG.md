# Changelog

## 1.2.0 — 2026-10-22

CiviCRM 6.16 / PHP 8.3 review. Requires CiviCRM 6.16+ and PHP 8.2+.

### Fixed

* **Arbitrary notifications were replayed.** A trailing new line or `", "` in
  the pasted IDs produced an empty ID. It matched every logged notification,
  and the first 25 were replayed. IDs are now split without empty values and
  validated: at least 6 characters, only letters, digits, `.`, `_` and `-`,
  and at most 100 per submission.
* **Partial IDs matched unrelated notifications.** An ID matched any
  notification that contained it as a substring. It must now be a complete
  value in the notification. Results are no longer cut off at 25 notifications.
* **Every replay reported "Processed".** The core notification handlers catch
  their own errors, so the retry API always succeeded. The status now comes
  from checking whether a contribution has the transaction ID after the
  replay. The "Already Processed" status could never be reached and is gone.
* **Already-recorded payments were replayed.** Notifications whose transaction
  ID is already on a contribution or payment are skipped. PayPal Pro recurring
  payments are also skipped when the series has a matching completed
  contribution without a transaction ID ("Possibly recorded"); PayPal Pro would
  record those a second time.
* **Only one result per ID.** When an ID matched several notifications, each
  replay overwrote the previous status. Each notification now has its own row.
* **IDs missing from the report.** IDs whose lookup threw an error were left out
  of the report entirely.
* **PHP 8 fatal error on unreadable log entries.** An empty or non-JSON logged
  notification caused a `TypeError`. It is now reported as
  "Unreadable log entry". A missing contact in `rp_invoice_id` no longer
  raises a warning.
* **Replays across domains.** A payment whose recurring series belongs to
  another domain is no longer replayed under the current domain. A payment
  outside a series is checked against the processor that received it.
* **Replay order and duplicates.** Notifications are replayed once each, in the
  order they were logged, across all pasted IDs. Before, they ran in paste
  order, and a notification matched by two pasted IDs ran twice.
* **Line items removed from replayed installments.** The
  `com.skvare.memberships` signup session flags are cleared during a replay.
* **Processor output above the page.** What the notification handler prints is
  captured into the Detail column.
* **Unescaped report.** The report is rendered by the template with escaped
  values, and invalid IDs in error messages are escaped too.
* **CSV file name.** It was `Processed_Payment_csv.csv`; it is now
  `Processed_Payment.csv`.

### Changed

* civix format 26.10.3. Uses core's `menu-xml` and `smarty` mixins, and no
  longer bundles a polyfill. CiviCRM 6.16 is the minimum version.
* Notifications are looked up in one query, limited to processors the retry API
  supports (PayPal, PayPal Standard, legacy Authorize.Net). This is much faster
  on large logs.
* The report has these columns: transaction ID, log ID, log date, processor,
  contact ID, status, detail. The CSV download has the same columns.
* Each replay is written to the CiviCRM log.
* The page title and menu item are "Bulk Payment Retry". The menu item uses a
  relative URL.
* Removed the `echo`/`print_r` debug output. Debug now shows the full handler
  output in the Detail column.
* `info.xml`: stable, version 1.2.0, PHP and Smarty compatibility declared.
* README documents supported processors, statuses and limitations.

## 1.1 — 2025-07-23

* civix upgrade, README.

## 1.0 — 2021-02-24

* Initial version.
