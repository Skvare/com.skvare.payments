<?php

use CRM_Payments_ExtensionUtil as E;

/**
 * Form controller class
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/quickform/
 */
class CRM_Payments_Form_Failedpayment extends CRM_Core_Form {
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

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  public function postProcess() {
    $values = $this->exportValues();
    $finalReport = [];
    if (!empty($values['trns_ids'])) {
      $string = $values['trns_ids'];
      $matches = explode(',', preg_replace('/\s+/', ',', $string));
      $trns_ids = array_values(array_unique($matches));
      foreach ($trns_ids as $trnxID) {
        $lineData = [];
        $contactID = '';
        $lineData['trxid'] = $trnxID;
        try {
          $resultSystemLog = civicrm_api3('SystemLog', 'get', [
            'sequential' => 1,
            'context' => ['LIKE' => "%" . $trnxID . "%"],
          ]);

          if ($resultSystemLog['values']) {
            foreach ($resultSystemLog['values'] as $value) {
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
