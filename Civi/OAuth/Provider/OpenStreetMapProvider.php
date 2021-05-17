<?php


namespace Civi\OAuth\Provider;


use League\OAuth2\Client\Token\AccessToken;

class OpenStreetMapProvider extends \Civi\OAuth\CiviGenericProvider {

  protected function createResourceOwner(array $response, AccessToken $token): OpenStreetMapUser {
    return new OpenStreetMapUser($response);
  }
}