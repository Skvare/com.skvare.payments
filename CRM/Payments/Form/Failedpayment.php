<?php

use CRM_Payments_ExtensionUtil as E;

/**
 * Form controller class
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

    $this->add('textarea', 'trns_ids', ts('Transaction IDS'), ["cols" => 100, "rows" => 10], TRUE);
    $this->addElement('checkbox', 'download_csv', ts('Download Processed Data'));
    $this->addElement('checkbox', 'debug', ts('Debug?'));
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
    if (!empty($values['trns_ids'])) {
      $trns_ids = self::parseTransactionIds($values['trns_ids']);
      $logEntries = self::findLogEntries($trns_ids);
      foreach ($trns_ids as $trnxID) {
        $lineData = [];
        $contactID = '';
        $lineData['trxid'] = $trnxID;
        try {
          if ($logEntries[$trnxID]) {
            foreach ($logEntries[$trnxID] as $value) {
              $value['context'] = json_decode($value['context'], TRUE);
              if (array_key_exists('rp_invoice_id', $value['context'])) {
                $ipn = $value['context']['rp_invoice_id'];
                parse_str($ipn, $output);
                $contactID = $output['c'];
                $lineData['cid'] = $contactID;
              }
              else {
                $lineData['cid'] = '';
              }
              try {
                $resultNotificationLog = civicrm_api3('NotificationLog', 'retry', [
                  'system_log_id' => $value['id'],
                ]);
                if (!empty($values['debug'])) {
                  echo '<pre>NotificationLog : '; print_r($resultNotificationLog); echo '</pre>';
                }
                if (!$resultNotificationLog['is_error']) {
                  $lineData['status'] = 'Processed';
                }
                else {
                  if ($resultNotificationLog['error_message'] == 'This transaction has already been processed') {
                    $lineData['status'] = 'Already Processed';
                  }
                  else {
                    $lineData['status'] = 'Failed to retry';
                  }
                }
              }
              catch (CiviCRM_API3_Exception $e) {
                if (!empty($values['debug'])) {
                  echo '<pre>debug 1 getMessage : '; print_r($e->getMessage()); echo '</pre>';
                }
                if ($e->getMessage() == 'This transaction has already been processed') {
                  $lineData['status'] = 'Already Processed';
                }
                else {
                  $lineData['status'] = 'Not Found 1';
                }
                if (empty($contactID)) {
                  $lineData['cid'] = '';
                }
              }
            }
          }
          else {
            $lineData['cid'] = '';
            $lineData['status'] = 'Not Found 2';
          }
          $finalReport[] = $lineData;
        }
        catch (CiviCRM_API3_Exception $e) {
          $lineData['cid'] = '';
          $lineData['status'] = 'Not Found 3';
          if (!empty($values['debug'])) {
            echo '<pre>debug 3 getMessage'; print_r($e->getMessage()); echo '</pre>';
          }
        }
      }
      if (!empty($values['download_csv'])) {
        $fileName = 'Processed_Payment.csv';
        $columnsHeader = ['Trns ID', 'Contact ID', 'Status'];
        CRM_Core_Report_Excel::writeCSVFile($fileName, $columnsHeader, $finalReport);
        exit;
      }
      else {
        $htm = '<table class="selector">
        <tr>
        <th>Trns ID</th>
        <th>Contact ID</th>
        <th>Status</th>
        </tr>';
        foreach ($finalReport as $report) {
          $htm .= '<tr>';
          $htm .= '<td>' . $report['trxid'] . '</td>';
          if (!empty($report['cid'])) {
            $url = CRM_Utils_System::url("civicrm/contact/view", "reset=1&cid=" . $report['cid']);
            $report['cid'] = "<a href='{$url}' target='_blank'>" . $report['cid'] . "</a>";
          }
          $htm .= '<td>' . $report['cid'] . '</td>';
          $htm .= '<td>' . $report['status'] . '</td>';
          $htm .= '</tr>';
        }
        $htm .= '</table>';
        $smarty = CRM_Core_Smarty::singleton();
        $smarty->assign('reportData', $htm);
      }
    }
    parent::postProcess();
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
