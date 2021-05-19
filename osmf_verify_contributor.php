<?php

require_once 'osmf_verify_contributor.civix.php';
// phpcs:disable
use CRM_OsmfVerifyContributor_ExtensionUtil as E;
// phpcs:enable

function osmf_verify_contributor_civicrm_oauthProviders(&$providers) {
  $providers['openstreetmap.org'] = [
    'name' => 'openstreetmap.org',
    'title' => 'OpenStreetMap',
    'class' => 'Civi\OAuth\Provider\OpenStreetMapProvider',
    'options' => [
      'urlAuthorize' => 'https://oauth2.apis.dev.openstreetmap.org/oauth2/authorize',
      'urlAccessToken' => 'https://oauth2.apis.dev.openstreetmap.org/oauth2/token',
      'urlResourceOwnerDetails' => 'https://oauth2.apis.dev.openstreetmap.org/api/0.6/user/details.json',
      'scopes' => ['read_prefs'],
    ],
  ];
}

function osmf_verify_contributor_register_tokens(Civi\Token\Event\TokenRegisterEvent $e) {
  $e->entity('oauth')
    ->register('authCodeUrl', ts('OAuth Authorization Code URL'));
}

function osmf_verify_contributor_evaluate_tokens(Civi\Token\Event\TokenValueEvent $e) {
  /** @var Civi\Token\TokenRow $row */
  try {
    foreach ($e->getRows() as $row) {
      $url = Civi\Api4\OAuthClient::authorizationCode(0)
        ->addWhere('provider', '=', 'openstreetmap.org')
        ->setLandingUrl(CRM_Utils_System::url(
          'civicrm/osm-username-verification-success',
          NULL,
          TRUE
        ))
        ->setStorage('OAuthContactToken')
        ->setTag('linkContact:' . $row->context['contact_id'])
        ->execute()->single()['url'];
      $row->tokens('oauth', 'authCodeUrl', $url);
    }
  }
  catch (Exception $e) {
    Civi::log($e->getMessage());
  }
}

function osmf_verify_contributor_civicrm_preProcess(string $formName, CRM_Core_Form &$form) {
  if ($formName === 'CRM_Contribute_Form_Contribution_Main') {
    $form->setVar('_paymentProcessors', (array) $form->getVar('_paymentProcessors'));
  }

  if ($formName !== 'CRM_Contribute_Form_Contribution_ThankYou') {
    return;
  }

  /** @var CRM_Contribute_Form_Contribution_ThankYou $form */

  Civi::dispatcher()->addListener('civi.token.list', 'osmf_verify_contributor_register_tokens');
  Civi::dispatcher()->addListener('civi.token.eval', 'osmf_verify_contributor_evaluate_tokens');

  $tokenProcessor = new \Civi\Token\TokenProcessor(\Civi::dispatcher(), [
    'controller' => $formName,
    'smarty' => FALSE,
  ]);
  $tokenProcessor->addMessage(
    'thankyou_text',
    $form->_values['thankyou_text'],
    'text/plain'
  );

  $tokenProcessor->addRow()
    ->context('contact_id', $form->get('contactID'));
  $tokenRow = $tokenProcessor->evaluate()->getRow(0);
  $form->assign('thankyou_text', $tokenRow->render('thankyou_text'));

  CRM_Core_Resources::singleton()->addStyle('
    .crm-contribution-thankyou-form-block > * {
      display: none;
    }
    #thankyou_text {
      display: block;
    }
  ');
}

function osmf_verify_contributor_civicrm_oauthReturn($tokenRecord, &$nextUrl) {
  $name = $tokenRecord['resource_owner_name'];
  \Civi\Api4\Contact::update(FALSE)
    ->addWhere('id', '=', $tokenRecord['contact_id'])
    ->addValue('constituent_information.Verified_OpenStreetMap_Username', $name)
    ->execute();
  CRM_Core_Session::singleton()->set('osm_username', $name, 'osmfvc');
  $nextUrl = CRM_Utils_System::url('civicrm/osm-username-verification-success');
}

function osmf_verify_contributor_civicrm_pre($op, $objectName, $id, &$params) {
  if ($objectName !== 'Membership' || empty($params['membership_type_id'])) {
    return;
  }

  $membershipType = CRM_Core_Pseudoconstant::getName(
    'CRM_Member_BAO_Membership',
    'membership_type_id',
    $params['membership_type_id']
  );
  if ($membershipType !== 'Fee-waiver Member') {
    return;
  }

  $contributionPageId = $params['contribution']->contribution_page_id ?? NULL;
  static $targetContactId = NULL;
  if (
    ($op === 'create' && !empty($contributionPageId))
    ||
    ($op === 'edit' && !empty($params['contact_id']) && $params['contact_id'] === $targetContactId)
  ) {
    $params['status_id'] = CRM_Core_Pseudoconstant::getKey('CRM_Member_BAO_Membership',
      'status_id', 'Pending');
    $params['is_override'] = TRUE;
    $params['start_date'] = $params['end_date'] = NULL;
    $targetContactId = $params['contact_id'];
  }
}

/**
 * @param string $op
 * @param string $objectName
 * @param int $objectId
 * @param CRM_Core_DAO $objectRef
 */
function osmf_verify_contributor_civicrm_post($op, $objectName, $objectId, &$objectRef) {
  if ($objectName === 'OAuthContactToken') {
    /** @var CRM_OAuth_DAO_OAuthContactToken $objectRef */
    if ($objectRef->contact_id) {
      Civi\Osmf\VerifyMapper::verifyAndUpdateMembership($objectRef);
    }
  }
  elseif ($objectName === 'Membership' && isset($objectRef->status_id)) {
    CRM_Core_Session::singleton()->set(
      'membership_status',
      CRM_Core_Pseudoconstant::getLabel(
        'CRM_Member_BAO_Membership',
        'status_id',
        $objectRef->status_id
      ),
      'osmfvc');
  }
}

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function osmf_verify_contributor_civicrm_config(&$config) {
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