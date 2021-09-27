<?php


namespace Osmf;

use Civi\Core\Event\GenericHookEvent;

class Membership {

  public static $overrideStatus = ['byContactId' => NULL, 'byMembershipId' => NULL];

  public static function pre($op, $objectName, $id, &$params) {
    if ($objectName !== 'Membership' || empty($params['membership_type_id'])) {
      return;
    }

    $membershipType = \CRM_Core_Pseudoconstant::getName(
      'CRM_Member_BAO_Membership',
      'membership_type_id',
      $params['membership_type_id']
    );
    if ($membershipType !== 'Fee-waiver Member') {
      return;
    }

    $contributionPageId = $params['contribution']->contribution_page_id ?? NULL;
    $membershipId = $params['id'] ?? -1;
    $contactId = $params['contact_id'] ?? -1;
    $paramsOriginatedOnContributionPage = ($membershipId === self::$overrideStatus['byMembershipId'])
                                          || ($contactId === self::$overrideStatus['byContactId']);

    if (!empty($contributionPageId)) {
      self::makePending($params);
      self::$overrideStatus['byMembershipId'] = $membershipId;
      self::$overrideStatus['byContactId'] = $contactId;
    }
    if ($op === 'edit' && $paramsOriginatedOnContributionPage) {
      self::makePending($params);
    }
  }

  private static function makePending(&$params): void {
    $params['status_id'] = \CRM_Core_Pseudoconstant::getKey('CRM_Member_BAO_Membership',
      'status_id', 'Pending');
    $params['is_override'] = TRUE;
    $params['start_date'] = $params['end_date'] = NULL;
  }

  public static function post($op, $objectName, $objectId, &$objectRef) {
    if ($objectName !== 'Membership') {
      return;
    }

    if (isset($objectRef->status_id)) {
      \CRM_Core_Session::singleton()->set(
        'membership_status',
        \CRM_Core_Pseudoconstant::getLabel(
          'CRM_Member_BAO_Membership',
          'status_id',
          $objectRef->status_id
        ),
        'osmfvc');
    }
  }

}