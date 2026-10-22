<?php

use CRM_Payments_ExtensionUtil as E;

/**
 * Bulk retry of logged payment notifications.
 *
 * Finds the PayPal and legacy Authorize.Net notifications logged in
 * civicrm_system_log for pasted transaction IDs, and replays them with
 * NotificationLog.retry (nz.co.fuzion.notificationlog).
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/quickform/
 */
class CRM_Payments_Form_Failedpayment extends CRM_Core_Form {

  /**
   * Processor types that NotificationLog.retry can replay.
   */
  const REPLAYABLE_TYPES = ['AuthNet', 'PayPal', 'PayPal_Standard'];

  /**
   * Maximum number of transaction IDs per submission.
   */
  const MAX_IDS = 100;

  public function buildQuickForm() {

    $this->add('textarea', 'trns_ids', E::ts('Transaction IDs'), ['cols' => 100, 'rows' => 10], TRUE);
    $this->addElement('checkbox', 'download_csv', E::ts('Download Processed Data'));
    $this->addElement('checkbox', 'debug', E::ts('Debug (show full processor output)'));
    $this->addButtons([
      [
        'type' => 'submit',
        'name' => E::ts('Submit'),
        'isDefault' => TRUE,
      ],
    ]);

    $this->addFormRule([__CLASS__, 'formRule']);

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    $this->assign('maxIds', self::MAX_IDS);
    $this->assign('reportRows', []);
    parent::buildQuickForm();
  }

  /**
   * Split the pasted text into unique, non-empty transaction IDs.
   *
   * @param string $string
   *
   * @return string[]
   */
  public static function parseTransactionIds($string): array {
    return array_values(array_unique(preg_split('/[\s,]+/', (string) $string, -1, PREG_SPLIT_NO_EMPTY)));
  }

  /**
   * Reject IDs that could match unrelated log entries.
   *
   * @param array $values
   *
   * @return array|bool
   */
  public static function formRule($values) {
    $errors = [];
    $ids = self::parseTransactionIds($values['trns_ids'] ?? '');
    $invalid = array_filter($ids, function ($id) {
      return !preg_match('/^[A-Za-z0-9._-]{6,}$/', $id);
    });
    if (!$ids) {
      $errors['trns_ids'] = E::ts('Enter at least one transaction ID.');
    }
    elseif ($invalid) {
      $errors['trns_ids'] = E::ts('Transaction IDs must be at least 6 characters long and contain only letters, digits, ".", "_" or "-". Invalid: %1', [1 => htmlspecialchars(implode(', ', $invalid))]);
    }
    elseif (count($ids) > self::MAX_IDS) {
      $errors['trns_ids'] = E::ts('Enter at most %1 transaction IDs at a time (%2 entered).', [1 => self::MAX_IDS, 2 => count($ids)]);
    }
    return $errors ?: TRUE;
  }

  /**
   * SQL condition matching the log messages NotificationLog.retry can replay.
   *
   * Uses the same message formats as NotificationLog.retry. Checking the
   * message first keeps the context scan to these rows: scanning every
   * logged notification (mostly Stripe) takes about 7 seconds per ID.
   *
   * @return string
   */
  protected static function replayableMessageClause(): string {
    $messages = ["'payment_notification PayPal_Standard'"];
    foreach (self::REPLAYABLE_TYPES as $type) {
      $messages[] = "'payment_notification processor_name={$type}'";
    }
    $dao = CRM_Core_DAO::executeQuery("
      SELECT pp.id
      FROM civicrm_payment_processor pp
      INNER JOIN civicrm_payment_processor_type ppt ON ppt.id = pp.payment_processor_type_id
      WHERE ppt.name IN ('" . implode("', '", self::REPLAYABLE_TYPES) . "')");
    while ($dao->fetch()) {
      $messages[] = "'payment_notification processor_id=" . (int) $dao->id . "'";
    }
    return 'message IN (' . implode(', ', $messages) . ')';
  }

  /**
   * Find the replayable notifications logged for each transaction ID.
   *
   * An ID only matches when it is a whole JSON string value in the logged
   * context, so a partial ID never matches another notification. All IDs are
   * looked up in one scan because civicrm_system_log.context is not indexed.
   *
   * @param string[] $ids
   *
   * @return array
   *   Log rows (id, message, context, timestamp) keyed by transaction ID.
   */
  public static function findLogEntries(array $ids): array {
    $entries = array_fill_keys($ids, []);
    if (!$ids) {
      return $entries;
    }
    $clauses = $params = [];
    foreach (array_values($ids) as $i => $id) {
      // "_" is a LIKE wildcard; formRule() rules out "%" and "\".
      $params[$i + 1] = ['%"' . str_replace('_', '\_', $id) . '"%', 'String'];
      $clauses[] = 'context LIKE %' . ($i + 1);
    }
    $dao = CRM_Core_DAO::executeQuery("
      SELECT id, message, context, timestamp
      FROM civicrm_system_log
      WHERE " . self::replayableMessageClause() . " AND (" . implode(' OR ', $clauses) . ")
      ORDER BY id", $params);
    while ($dao->fetch()) {
      foreach ($ids as $id) {
        if (str_contains($dao->context, '"' . $id . '"')) {
          $entries[$id][] = [
            'id' => $dao->id,
            'message' => $dao->message,
            'context' => $dao->context,
            'timestamp' => $dao->timestamp,
          ];
        }
      }
    }
    return $entries;
  }

  public function postProcess() {
    $values = $this->exportValues();
    $finalReport = [];
    foreach (self::findLogEntries(self::parseTransactionIds($values['trns_ids'] ?? '')) as $trnxID => $entries) {
      if (!$entries) {
        $finalReport[] = self::reportRow($trnxID, NULL, E::ts('Not found'), E::ts('No PayPal or legacy Authorize.Net notification in the log has this ID. Stripe and Authorize.Net (authnet extension) notifications cannot be replayed here: use the Stripe.Ipn API or the payment processor webhook retry.'));
        continue;
      }
      foreach ($entries as $entry) {
        $finalReport[] = $this->processLogEntry($trnxID, $entry, !empty($values['debug']));
      }
    }
    if (!empty($values['download_csv'])) {
      $columns = [E::ts('Transaction ID'), E::ts('Log ID'), E::ts('Log Date'), E::ts('Processor'), E::ts('Contact ID'), E::ts('Status'), E::ts('Detail')];
      CRM_Core_Report_Excel::writeCSVFile('Processed_Payment', $columns, $finalReport);
      CRM_Utils_System::civiExit();
    }
    $this->assign('reportRows', $finalReport);
    parent::postProcess();
  }

  /**
   * Replay one logged notification, unless it must be skipped.
   *
   * @param string $trnxID
   *   The pasted transaction ID that matched this log entry.
   * @param array $entry
   *   civicrm_system_log row: id, message, context, timestamp.
   * @param bool $debug
   *
   * @return array
   *   Report row.
   */
  protected function processLogEntry(string $trnxID, array $entry, bool $debug): array {
    $context = json_decode($entry['context'], TRUE);
    if (!is_array($context)) {
      return self::reportRow($trnxID, $entry, E::ts('Unreadable log entry'), E::ts('The logged notification could not be decoded.'));
    }
    $contactID = '';
    if (!empty($context['rp_invoice_id']) && is_string($context['rp_invoice_id'])) {
      parse_str($context['rp_invoice_id'], $invoice);
      $contactID = $invoice['c'] ?? '';
    }
    $processor = self::getProcessor($entry['message']);
    if ($processor['domain_id'] && $processor['domain_id'] != CRM_Core_Config::domainID()) {
      return self::reportRow($trnxID, $entry, E::ts('Other domain'), E::ts('Processor %1 belongs to domain %2. Run the retry from that site.', [1 => $processor['id'], 2 => $processor['domain_id']]), $contactID);
    }

    // Replaying a payment that is already recorded is not harmless: PayPal
    // Pro re-saves the recurring record before the duplicate trxn_id fails.
    $logTrxnID = $context['txn_id'] ?? $context['x_trans_id'] ?? '';
    $logTrxnID = is_string($logTrxnID) ? $logTrxnID : '';
    if ($logTrxnID && ($contribution = self::findContribution($logTrxnID))) {
      return self::reportRow($trnxID, $entry, E::ts('Already recorded'), E::ts('Contribution %1 already has transaction %2.', [1 => $contribution['id'], 2 => $logTrxnID]), $contactID ?: $contribution['contact_id']);
    }
    if ($contribution = self::findUntrackedRecurPayment($context)) {
      return self::reportRow($trnxID, $entry, E::ts('Possibly recorded'), E::ts('Contribution %1 in the same recurring series has the same amount and a receive date within a day of this payment, but no transaction ID. Not replayed: if it is a different payment, record it manually.', [1 => $contribution['id']]), $contactID ?: $contribution['contact_id']);
    }

    [$output, $error] = $this->replay((int) $entry['id']);
    // The IPN classes catch their own exceptions and only echo a message, so
    // the result of NotificationLog.retry says nothing about the payment.
    if ($error) {
      $status = E::ts('Failed');
      $detail = $error;
    }
    elseif (!$logTrxnID) {
      $status = E::ts('Retried');
      $detail = E::ts('The notification has no transaction ID to verify.');
    }
    elseif ($contribution = self::findContribution($logTrxnID)) {
      $status = E::ts('Processed');
      $detail = E::ts('Recorded as contribution %1.', [1 => $contribution['id']]);
      $contactID = $contactID ?: $contribution['contact_id'];
    }
    else {
      $status = E::ts('Retried, nothing recorded');
      $detail = E::ts('No contribution has transaction %1.', [1 => $logTrxnID]);
    }
    if ($output !== '') {
      $detail .= ' ' . E::ts('Output: %1', [1 => $debug ? $output : CRM_Utils_String::ellipsify($output, 200)]);
    }
    Civi::log()->info('Bulk payment retry by contact {user}: system log {log} ({trxn}): {status}. {detail}', [
      'user' => CRM_Core_Session::getLoggedInContactID(),
      'log' => $entry['id'],
      'trxn' => $trnxID,
      'status' => $status,
      'detail' => $detail,
    ]);
    return self::reportRow($trnxID, $entry, $status, $detail, $contactID);
  }

  /**
   * Run NotificationLog.retry for one log entry.
   *
   * @param int $logID
   *
   * @return array
   *   [output echoed by the IPN class, error message or ''].
   */
  protected function replay(int $logID): array {
    // com.skvare.memberships (memberships_civicrm_pre) strips the line items
    // of signup-page contributions saved while these session keys are set,
    // and a replay runs in the admin's session. Clear them for the replay.
    $session = CRM_Core_Session::singleton();
    $saved = [];
    foreach (['membership_custom_signup', 'membership_custom_signup_page'] as $key) {
      $saved[$key] = $session->get($key);
      $session->set($key, NULL);
    }
    $error = '';
    ob_start();
    try {
      civicrm_api3('NotificationLog', 'retry', ['system_log_id' => $logID]);
    }
    catch (\Throwable $e) {
      $error = $e->getMessage();
    }
    finally {
      foreach ($saved as $key => $value) {
        $session->set($key, $value);
      }
    }
    $output = trim(strip_tags((string) ob_get_clean()));
    return [$output, $error];
  }

  /**
   * Find the contribution a payment transaction ID was recorded on.
   *
   * @param string $trxnID
   *
   * @return array|null
   *   id and contact_id, or NULL.
   */
  protected static function findContribution(string $trxnID): ?array {
    $dao = CRM_Core_DAO::executeQuery("
      SELECT c.id, c.contact_id
      FROM civicrm_contribution c
      WHERE c.trxn_id = %1
      UNION
      SELECT c.id, c.contact_id
      FROM civicrm_financial_trxn ft
      INNER JOIN civicrm_entity_financial_trxn eft ON eft.financial_trxn_id = ft.id AND eft.entity_table = 'civicrm_contribution'
      INNER JOIN civicrm_contribution c ON c.id = eft.entity_id
      WHERE ft.trxn_id = %1 AND ft.is_payment = 1
      LIMIT 1", [1 => [$trxnID, 'String']]);
    return $dao->fetch() ? ['id' => (int) $dao->id, 'contact_id' => (int) $dao->contact_id] : NULL;
  }

  /**
   * Find a completed recurring payment that was recorded without trxn_id.
   *
   * A PayPal Pro installment completed at sign-up can have no trxn_id. Once
   * the series' first contribution is completed, PayPalProIPN records every
   * replayed recurring_payment as a new contribution, so replaying the
   * notification for that installment would record the payment twice.
   *
   * @param array $context
   *   Decoded notification.
   *
   * @return array|null
   *   id and contact_id, or NULL.
   */
  protected static function findUntrackedRecurPayment(array $context): ?array {
    if (empty($context['rp_invoice_id']) || !is_string($context['rp_invoice_id']) || empty($context['payment_date']) || !is_string($context['payment_date'])) {
      return NULL;
    }
    parse_str($context['rp_invoice_id'], $invoice);
    $recurID = (int) ($invoice['r'] ?? 0);
    $paid = strtotime($context['payment_date']);
    $amount = $context['mc_gross'] ?? $context['amount'] ?? NULL;
    if (!$recurID || !$paid || !is_numeric($amount)) {
      return NULL;
    }
    $dao = CRM_Core_DAO::executeQuery("
      SELECT id, contact_id
      FROM civicrm_contribution
      WHERE contribution_recur_id = %1 AND total_amount = %2 AND contribution_status_id = %3
        AND (trxn_id IS NULL OR trxn_id = '')
        AND receive_date BETWEEN %4 AND %5
      ORDER BY id
      LIMIT 1", [
        1 => [$recurID, 'Integer'],
        2 => [$amount, 'Float'],
        3 => [CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed'), 'Integer'],
        4 => [date('YmdHis', $paid - 86400), 'Timestamp'],
        5 => [date('YmdHis', $paid + 86400), 'Timestamp'],
      ]);
    return $dao->fetch() ? ['id' => (int) $dao->id, 'contact_id' => (int) $dao->contact_id] : NULL;
  }

  /**
   * Get the processor a log message refers to.
   *
   * Parses the message the same way NotificationLog.retry does.
   *
   * @param string $message
   *
   * @return array
   *   id (NULL for processor_name= messages), name, type, domain_id.
   */
  protected static function getProcessor(string $message): array {
    if (!isset(\Civi::$statics[__CLASS__]['processors'][$message])) {
      $processor = ['id' => NULL, 'name' => '', 'type' => '', 'domain_id' => NULL];
      if (str_starts_with($message, 'payment_notification processor_name=')) {
        $processor['type'] = substr($message, strlen('payment_notification processor_name='));
      }
      elseif ($message === 'payment_notification PayPal_Standard') {
        $processor['type'] = 'PayPal_Standard';
      }
      elseif (str_starts_with($message, 'payment_notification processor_id=')) {
        $processor['id'] = (int) substr($message, strlen('payment_notification processor_id='));
        $dao = CRM_Core_DAO::executeQuery('
          SELECT pp.name, pp.domain_id, ppt.name AS type
          FROM civicrm_payment_processor pp
          INNER JOIN civicrm_payment_processor_type ppt ON ppt.id = pp.payment_processor_type_id
          WHERE pp.id = %1', [1 => [$processor['id'], 'Integer']]);
        if ($dao->fetch()) {
          $processor['name'] = $dao->name;
          $processor['type'] = $dao->type;
          $processor['domain_id'] = (int) $dao->domain_id;
        }
      }
      \Civi::$statics[__CLASS__]['processors'][$message] = $processor;
    }
    return \Civi::$statics[__CLASS__]['processors'][$message];
  }

  /**
   * Build a report row.
   *
   * @param string $trnxID
   * @param array|null $entry
   *   civicrm_system_log row, or NULL when nothing matched.
   * @param string $status
   * @param string $detail
   * @param int|string $contactID
   *
   * @return array
   *   Values in report column order.
   */
  protected static function reportRow(string $trnxID, ?array $entry, string $status, string $detail = '', $contactID = ''): array {
    $processor = $entry ? self::getProcessor($entry['message']) : NULL;
    $processorLabel = '';
    if ($processor) {
      $processorLabel = $processor['id'] ? "{$processor['type']} #{$processor['id']}" : $processor['type'];
      $processorLabel .= ' ' . ($processor['domain_id'] ? E::ts('(domain %1)', [1 => $processor['domain_id']]) : E::ts('(domain not checked)'));
    }
    return [
      'trxid' => $trnxID,
      'log_id' => $entry['id'] ?? '',
      'log_date' => $entry['timestamp'] ?? '',
      'processor' => $processorLabel,
      'contact_id' => $contactID,
      'status' => $status,
      'detail' => $detail,
    ];
  }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  public function getRenderableElementNames() {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons".  These
    // items don't have labels.  We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = [];
    foreach ($this->_elements as $element) {
      /** @var HTML_QuickForm_Element $element */
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }

    return $elementNames;
  }

}
