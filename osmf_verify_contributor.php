<?php

require_once 'osmf_verify_contributor.civix.php';
// phpcs:disable
use CRM_OsmfVerifyContributor_ExtensionUtil as E;
// phpcs:enable

function osmf_verify_contributor_civicrm_config(&$config) {
  if (isset(Civi::$statics[__FUNCTION__])) {
    return;
  }
  Civi::$statics[__FUNCTION__] = 1;

  Civi::dispatcher()->addListener('hook_civicrm_register_tokens', ['\Osmf\TemplateToken', 'register_tokens']);
  Civi::dispatcher()->addListener('hook_civicrm_evaluate_tokens', ['\Osmf\TemplateToken', 'evaluate_tokens']);

  Civi::dispatcher()->addListener('&hook_civicrm_tabset', ['\Osmf\ContributionPageSettings', 'tabset']);

  Civi::dispatcher()->addListener('hook_civicrm_preProcess', ['\Osmf\ContributionPage', 'preProcess']);
  Civi::dispatcher()->addListener('&hook_civicrm_alterTemplateFile', ['\Osmf\ContributionPage', 'alterTemplateFile']);

  Civi::dispatcher()->addListener('hook_civicrm_oauthProviders', ['\Osmf\OAuth', 'oauthProviders']);
  Civi::dispatcher()->addListener('hook_civicrm_oauthReturn', ['\Osmf\OAuth', 'oauthReturn']);

  Civi::dispatcher()->addListener('&hook_civicrm_pre', ['\Osmf\Membership', 'pre']);
  Civi::dispatcher()->addListener('&hook_civicrm_post', ['\Osmf\Membership', 'post']);

  Civi::dispatcher()->addListener('&hook_civicrm_post', ['\Osmf\VerifyMapper', 'post']);
  _osmf_verify_contributor_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_xmlMenu
 */
function osmf_verify_contributor_civicrm_xmlMenu(&$files) {
  _osmf_verify_contributor_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function osmf_verify_contributor_civicrm_install() {
  _osmf_verify_contributor_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_postInstall
 */
function osmf_verify_contributor_civicrm_postInstall() {
  _osmf_verify_contributor_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_uninstall
 */
function osmf_verify_contributor_civicrm_uninstall() {
  _osmf_verify_contributor_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function osmf_verify_contributor_civicrm_enable() {
  _osmf_verify_contributor_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_disable
 */
function osmf_verify_contributor_civicrm_disable() {
  _osmf_verify_contributor_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_upgrade
 */
function osmf_verify_contributor_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _osmf_verify_contributor_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed
 */
function osmf_verify_contributor_civicrm_managed(&$entities) {
  _osmf_verify_contributor_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_angularModules
 */
function osmf_verify_contributor_civicrm_angularModules(&$angularModules) {
  _osmf_verify_contributor_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterSettingsFolders
 */
function osmf_verify_contributor_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _osmf_verify_contributor_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
 */
function osmf_verify_contributor_civicrm_entityTypes(&$entityTypes) {
  _osmf_verify_contributor_civix_civicrm_entityTypes($entityTypes);
}