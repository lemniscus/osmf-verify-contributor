<?php

namespace Osmf;

class Membership {

  private static $submittedFormVals = ['contactId' => NULL, 'membershipId' => NULL];
  private static $membershipIsNewlyCreated = NULL;

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
    $membershipId = $params['id'] ?? NULL;
    $contactId = $params['contact_id'] ?? NULL;

    if (self::weAreProcessingAContributionPageSubmission(
      $contributionPageId, $membershipId, $contactId)) {

      $params['status_id'] = \CRM_Core_PseudoConstant::getKey(
        'CRM_Member_BAO_Membership',
        'status_id',
        'Pending');

      $params['is_override'] = TRUE;

      if (is_null(self::$membershipIsNewlyCreated) && $op === 'create') {
        self::$membershipIsNewlyCreated = TRUE;
      }
      if (self::$membershipIsNewlyCreated) {
        $params['start_date'] = $params['end_date'] = 'null';
      }

      self::rememberThatWeAreProcessingAContributionPageSubmission(
        $membershipId, $contactId);
    }
  }

  private static function weAreProcessingAContributionPageSubmission(
    $contributionPageId,
    $membershipId,
    $contactId): bool {
    return !empty($contributionPageId)
    || (!empty($membershipId) && $membershipId === self::$submittedFormVals['membershipId'])
    || (!empty($contactId) && $contactId === self::$submittedFormVals['contactId']);
  }

  private static function rememberThatWeAreProcessingAContributionPageSubmission(
    $membershipId,
    $contactId): void {
    self::$submittedFormVals['membershipId']
      = self::$submittedFormVals['membershipId'] ?? $membershipId;
    self::$submittedFormVals['contactId']
      = self::$submittedFormVals['contactId'] ?? $contactId;
  }

  public static function weAreDoneProcessingAContributionPageSubmission(): void {
    self::$submittedFormVals['membershipId'] = NULL;
    self::$submittedFormVals['contactId'] = NULL;
    self::$membershipIsNewlyCreated = NULL;
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
