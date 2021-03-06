<?php


namespace Osmf;

use Civi\Core\Event\GenericHookEvent;

class OAuth {

  public static function oauthProviders(GenericHookEvent $e) {
    $e->providers['openstreetmap.org'] = [
      'name' => 'openstreetmap.org',
      'title' => 'OpenStreetMap',
      'class' => 'Civi\OAuth\Provider\OpenStreetMapProvider',
      'options' => [
        'urlAuthorize' => 'https://www.openstreetmap.org/oauth2/authorize',
        'urlAccessToken' => 'https://www.openstreetmap.org/oauth2/token',
        'urlResourceOwnerDetails' => 'https://www.openstreetmap.org/api/0.6/user/details.json',
        'scopes' => ['read_prefs'],
      ],
    ];
  }

  public static function oauthReturn(GenericHookEvent $e) {
    $e->nextUrl = \CRM_Utils_System::url('civicrm/osm-username-verification-success');
  }

}