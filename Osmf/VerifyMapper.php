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
    $name = $token->resource_owner_name ?? NULL;
    \CRM_Core_Session::singleton()->set('osm_username', $name, 'osmfvc');
    $osmId = json_decode($token->resource_owner ?? '')->id ?? NULL;
    if (empty($name) || empty($osmId)) {
      \Civi::log()->error('Contact token is missing resource owner information');
      return;
    }

    $duplicates = \Civi\Api4\Contact::get(FALSE)
      ->addWhere(
        'constituent_information.Verified_OpenStreetMap_User_ID',
        '=',
        $osmId)
      ->selectRowCount()->execute()->rowCount;

    if ($duplicates) {
      $message = E::ts(
        'There is already a record in this system linked to '
        . 'the OpenStreetMap user "%1". Please contact membership@osmfoundation.org '
        . 'to resolve this issue.',
        [1 => $name]
      );
      \CRM_Core_Session::singleton()->set('error_message', $message, 'osmfvc');
      return;
    }
    else {
      \Civi\Api4\Contact::update(FALSE)
        ->addWhere('id', '=', $token->contact_id)
        ->addValue('constituent_information.Verified_OpenStreetMap_Username', $name)
        ->addValue('constituent_information.Verified_OpenStreetMap_User_ID', $osmId)
        ->execute();
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
        civicrm_api3('Membership', 'create', [
          'id' => $membership['id'],
          'status_id' => "New",
          'is_override' => FALSE,
        ]);
      }

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

}
