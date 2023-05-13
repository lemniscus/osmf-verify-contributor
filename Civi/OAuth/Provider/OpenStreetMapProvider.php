<?php


namespace Civi\OAuth\Provider;


use League\OAuth2\Client\Token\AccessToken;

class OpenStreetMapProvider extends \Civi\OAuth\CiviGenericProvider {

  public function __construct(array $options = [], array $collaborators = []) {
    $options['redirectUri'] = \CRM_Utils_System::url('civicrm/oauth-client/return', NULL, TRUE, NULL, FALSE, TRUE, FALSE);
    parent::__construct($options, $collaborators);
  }

  protected function createResourceOwner(array $response, AccessToken $token): OpenStreetMapUser {
    return new OpenStreetMapUser($response);
  }

}
