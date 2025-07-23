<?php

require_once 'payments.civix.php';
// phpcs:disable
use CRM_Payments_ExtensionUtil as E;
// phpcs:enable

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function payments_civicrm_config(&$config) {
  _payments_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function payments_civicrm_install() {
  _payments_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function payments_civicrm_enable() {
  _payments_civix_civicrm_enable();
}

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_preProcess
 */
//function payments_civicrm_preProcess($formName, &$form) {
//
//}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_navigationMenu
 */
function payments_civicrm_navigationMenu(&$menu) {
  _payments_civix_insert_navigation_menu($menu, 'Contributions', [
    'label' => E::ts('Bulk Payment retry'),
    'name' => 'bulk_payment_retry',
    'url' => CRM_Utils_System::url('civicrm/failedpayment/retry', 'reset=1'),
    'permission' => 'administer CiviCRM',
    'operator' => 'OR',
    'separator' => 0,
  ]);
  _payments_civix_navigationMenu($menu);
}
