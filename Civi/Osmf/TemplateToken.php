<?php


namespace Civi\Osmf;

class TemplateToken {

  public static function register_tokens(\Civi\Token\Event\TokenRegisterEvent $e) {
    $e->entity('oauth')
      ->register('authCodeUrl', ts('OAuth Authorization Code URL'));
  }

  public static function evaluate_tokens(\Civi\Token\Event\TokenValueEvent $e) {
    /** @var \Civi\Token\TokenRow $row */
    try {
      foreach ($e->getRows() as $row) {
        $url = \Civi\Api4\OAuthClient::authorizationCode(0)
          ->addWhere('provider', '=', 'openstreetmap.org')
          ->setLandingUrl(\CRM_Utils_System::url(
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
    catch (\Exception $e) {
      \Civi::log($e->getMessage());
    }
  }

}