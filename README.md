# Bulk Payments Retry Extension

A CiviCRM extension for retrying failed payment transactions by processing transaction IDs from system logs. This extension provides a bulk payment retry interface that looks up failed transaction data in the system log table and reprocesses payment submissions.

## Features

- Bulk processing of failed transaction IDs
- Integration with system logs to retrieve transaction context
- Retry payment processing through notification logs
- Visual feedback with processing status (Processed, Already Processed, Failed to retry, Not Found)
- CSV export functionality for processed payment data
- Navigation menu integration under Contributions

This extension requires the `nz.co.fuzion.notificationlog` extension.

The extension is licensed under [AGPL-3.0](LICENSE.txt).

## Requirements

* PHP v7.0+
* CiviCRM v5.0+
* Extension: nz.co.fuzion.notificationlog

## Installation (Web UI)

This extension has not yet been published for installation via the web UI.

## Installation (CLI, Zip)

Sysadmins and developers may download the `.zip` file for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
cd <extension-dir>
cv dl com.skvare.payments@https://github.com/Skvare/com.skvare.payments/archive/master.zip
```

## Installation (CLI, Git)

Sysadmins and developers may clone the [Git](https://en.wikipedia.org/wiki/Git) repo for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
git clone https://github.com/Skvare/com.skvare.payments.git
cv en payments
```

## Usage

1. Navigate to **Contributions → Bulk Payment retry** in the CiviCRM menu
2. Enter failed transaction IDs in the textarea (comma-separated or one per line)
3. Optionally check:
   - **Download Processed Data**: Export results as CSV
   - **Debug**: Enable debug mode for troubleshooting
4. Click **Submit** to process the transactions

The system will:
- Look up each transaction ID in the system logs
- Extract contact information from the transaction context
- Retry payment processing through the notification log
- Display results showing transaction status for each ID


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

