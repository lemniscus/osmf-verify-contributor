<?php

namespace Osmf;

use CRM_OAuth_DAO_OAuthContactToken as ContactToken;
use CRM_OsmfVerifyContributor_ExtensionUtil as E;
use GuzzleHttp\Exception\GuzzleException;
use function civicrm_api3;

class VerifyMapper {

  public static function post($op, $objectName, $objectId, &$objectRef) {
    if ($objectName === 'OAuthContactToken') {
      /** @var \CRM_OAuth_DAO_OAuthContactToken $objectRef */
      if ($objectRef->contact_id) {
        static::verifyAndUpdateMembership($objectRef);
      }
    }
  }

  public static function verifyAndUpdateMembership(ContactToken $token) {
    \CRM_Core_Session::singleton()->set('error_message', '', 'osmfvc');

    if (!self::checkOsmNameAndIdAreUniqueAndSaveThem($token)) {
      return;
    }

    $memberships = civicrm_api3('Membership', 'get', [
      'contact_id' => $token->contact_id,
      'membership_type_id' => "Fee-waiver Member",
      'status_id' => "Pending",
    ]);

    if ($memberships['count'] == 0) {
      return;
    }

    try {
      $userMappingDays = self::userMappingDays($token);

      if ($userMappingDays >= 42) {
        $membership = array_pop($memberships['values']);
        self::activateMembership($membership);
      }

      self::createMappingDaysNote($token, $userMappingDays);
    }
    catch (\CRM_Core_Exception $e) {
      \Civi::log()->error($e->getMessage(), [$e]);
    }
  }

  /**
   * @throws \CRM_Core_Exception
   */
  private static function userMappingDays(ContactToken $token): int {
    if (!($username = $token->resource_owner_name)) {
      throw new \CRM_Core_Exception("Can't look up user mapping days without a username");
    }

    $httpClient = \Civi::$statics['osmf-verify-contributor']['http-client'] ??
      new \GuzzleHttp\Client([
        'base_uri' => 'https://api.openstreetmap.org/api/0.6/changesets',
        'timeout' => 10,
      ]);

    $utc = new \DateTimeZone('Etc/UTC');

    $searchLowerLimit = new \DateTime('-365 Days', $utc);
    $searchLowerLimit->setTime(0, 0);
    $lowerLimStr = $searchLowerLimit->format(DATE_ATOM);

    $searchUpperLimit = new \DateTime('now', $utc);
    $searchUpperLimit->setTime(0, 0);

    $earliestMappingDateTime = new \DateTime('tomorrow');
    $mappingDays = [];

    while ($searchUpperLimit > $searchLowerLimit) {
      $query['time'] = "$lowerLimStr," . $searchUpperLimit->format(DATE_ATOM);
      $query['display_name'] = $username;

      try {
        $response = $httpClient->request('GET', '', ['query' => $query]);
      }
      catch (GuzzleException $e) {
        throw new \CRM_Core_Exception('Communication error', 0, ['GuzzleException' => $e]);
      }

      $xmlRootObject = simplexml_load_string($response->getBody());
      $changeSetBatch = $xmlRootObject->changeset ?? [];

      foreach ($changeSetBatch as $changeSet) {
        $created = new \DateTime($changeSet['created_at']);
        if ($created > $searchLowerLimit) {
          $mappingDays[$created->format('Ymd')] = 1;
          $earliestMappingDateTime = min($earliestMappingDateTime, $created);
        }
      }

      if (
        ($earliestMappingDateTime >= $searchUpperLimit)
        ||
        (count($changeSetBatch) < 100)
      ) {
        break;
      }
      $searchUpperLimit = $earliestMappingDateTime;
    }

    return count($mappingDays);
  }

  private static function anotherContactHasOsmId($osmId, string $name, int $contactId): bool {
    $duplicates = \Civi\Api4\Contact::get(FALSE)
      ->addWhere(
        'constituent_information.Verified_OpenStreetMap_User_ID',
        '=',
        $osmId)
      ->addWhere('id', '!=', $contactId)
      ->selectRowCount()->execute()->rowCount;

    if ($duplicates) {
      $message = E::ts(
        'There is already a record in this system linked to '
        . 'the OpenStreetMap user %1 ("%2"). Please contact '
        . 'membership@osmfoundation.org to resolve this issue.',
        [1 => $osmId, 2 => $name]
      );
      \CRM_Core_Session::singleton()->set('error_message', $message, 'osmfvc');
      return TRUE;
    }

    return FALSE;
  }

  private static function activateMembership($membership): void {
    $membership['is_override'] = FALSE;

    $calcDates = \CRM_Member_BAO_MembershipType::getDatesForMembershipType(
      $membership['membership_type_id'],
      NULL,
      NULL,
      NULL,
      1
    );

    $membership['join_date'] = $membership['join_date'] ?? $calcDates['join_date'];
    $membership['start_date'] = $membership['start_date'] ?? $calcDates['start_date'];
    $membership['end_date'] = $calcDates['end_date'];

    $calcStatus = \CRM_Member_BAO_MembershipStatus::getMembershipStatusByDate(
      $membership['start_date'],
      $membership['end_date'],
      $membership['join_date'],
      'now',
      FALSE,
      $membership['membership_type_id'],
      $membership
    );

    if (empty($calcStatus)) {
      throw new \CRM_Core_Exception(ts("The membership cannot be saved because the status cannot be calculated for start_date: {$calcDates['start_date']} end_date {$calcDates['end_date']} join_date {$calcDates['join_date']} as at " . CRM_Utils_Time::date('Y-m-d H:i:s')));
    }

    $membership['status_id'] = $calcStatus['id'];

    \Osmf\Membership::weAreDoneProcessingAContributionPageSubmission();
    civicrm_api3('Membership', 'create', $membership);
  }

  private static function createMappingDaysNote(ContactToken $token, int $userMappingDays): void {
    \Civi\Api4\Note::create(FALSE)
      ->addValue('entity_table', 'civicrm_contact')
      ->addValue('entity_id', $token->contact_id)
      ->addValue('note', E::ts('In the past 365 days, OSM user '
        . '%1 created changesets on %2 days.',
        [
          1 => $token->resource_owner_name,
          2 => $userMappingDays,
        ]))
      ->addValue(
        'subject',
        E::ts("Mapping days: %1", [1 => $userMappingDays]))
      ->execute();
  }

  private static function checkOsmNameAndIdAreUniqueAndSaveThem(ContactToken $token): bool {
    $name = $token->resource_owner_name ?? NULL;
    $osmId = json_decode($token->resource_owner ?? '')->id ?? NULL;

    \CRM_Core_Session::singleton()->set('osm_username', $name, 'osmfvc');

    if (empty($name) || empty($osmId)) {
      \Civi::log()
        ->error('Contact token is missing resource owner information');
      return FALSE;
    }

    if (self::anotherContactHasOsmId($osmId, $name, $token->contact_id)) {
      return FALSE;
    }

    \Civi\Api4\Contact::update(FALSE)
      ->addWhere('id', '=', $token->contact_id)
      ->addValue('constituent_information.Verified_OpenStreetMap_Username', $name)
      ->addValue('constituent_information.Verified_OpenStreetMap_User_ID', $osmId)
      ->execute();

    return TRUE;
  }

}
