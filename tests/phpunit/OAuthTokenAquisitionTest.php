<?php

use CRM_OsmfVerifyContributor_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * FIXME - Add test description.
 *
 * @group headless
 */
class OAuthTokenAquisitionTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  /**
   * @var array
   */
  private $originalRequest;

  private $providers;

  private $hookEvents;

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()
      ->install(['oauth-client', 'osmf-verify-contributor'])
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();
    $this->originalRequest = $_REQUEST;
  }

  public function tearDown(): void {
    parent::tearDown();
    $_REQUEST = $this->originalRequest;
  }

  public function hook_civicrm_oauthProviders(&$providers) {
    $providers = array_merge($providers, $this->providers);
  }

  public function hook_civicrm_oauthReturn($tokenRecord, &$nextUrl) {
    $this->hookEvents['oauthReturn'][] = func_get_args();
  }

  public function makeDummyProviderThatGetsATokenAndUser(): array {
    $newTokenResponse = [
      'status' => 200,
      'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
      'body' => json_encode(
        [
          'access_token' => 'example-access-token-value',
          'token_type' => 'Bearer',
          'scope' => 'read_prefs',
          'created_at' => time(),
        ]
      ),
    ];

    $userPrefsResponse = [
      'status' => 200,
      'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
      'body' => json_encode([
        'version' => '0.6',
        'generator' => 'OpenStreetMap server',
        'copyright' => 'OpenStreetMap and contributors',
        'attribution' => 'http://www.openstreetmap.org/copyright',
        'license' => 'http://opendatacommons.org/licenses/odbl/1-0/',
        'user' => [
          'id' => 0,
          'display_name' => 'lemniscus',
          'account_created' => '2021-04-23T19:36:23Z',
          'description' => '',
          'contributor_terms' => ['agreed' => TRUE, 'pd' => FALSE],
          'roles' => [],
          'changesets' => ['count' => 0],
          'traces' => ['count' => 0],
          'blocks' => ['received' => ['count' => 0, 'active' => 0]],
          'languages' => [0 => 'en-US', 1 => 'en'],
          'messages' => [
            'received' => ['count' => 0, 'unread' => 0],
            'sent' => ['count' => 0],
          ],
        ],
      ]),
    ];

    $this->providers['dummy-osm'] = [
      'name' => 'dummy-osm',
      'title' => 'Dummy Provider',
      'class' => 'Civi\OAuth\Provider\DummyOpenStreetMapProvider',
      'options' => [
        'urlAuthorize' => 'https://dummy/authorize',
        'urlAccessToken' => 'https://dummy/token',
        'urlResourceOwnerDetails' => 'https://dummy/',
        'scopes' => ['foo'],
        'cannedResponses' => [$newTokenResponse, $userPrefsResponse],
      ],
    ];

    return $this->providers;
  }

  public function testReturn() {
    $contact = \Civi\Api4\Contact::create(FALSE)
      ->addValue('first_name', 'Bob')
      ->execute()->single();
    self::assertTrue(is_numeric($contact['id']));

    $this->providers = $this->makeDummyProviderThatGetsATokenAndUser();
    $client = \Civi\Api4\OAuthClient::create(FALSE)->setValues(
      [
        'provider' => 'dummy-osm',
        'guid' => "example-client-guid",
        'secret' => "example-secret",
      ]
    )->execute()->single();

    $url = Civi\Api4\OAuthClient::authorizationCode(0)
      ->addWhere('id', '=', $client['id'])
      ->setLandingUrl('http://localhost/example_landing_url')
      ->setStorage('OAuthContactToken')
      ->setTag('linkContact:' . $contact['id'])
      ->execute()->single()['url'];
    parse_str(parse_url($url, PHP_URL_QUERY), $query);

    $_REQUEST['state'] = $query['state'];
    $_REQUEST['code'] = 'example_code';

    try {
      $page = new CRM_OAuth_Page_Return();
      $page->run();
      $this->fail('We should not reach this line due to redirect');
    }
    catch (CRM_Core_Exception_PrematureExitException $e) {
      //self::assertEquals('http://localhost/example_landing_url', $e->errorData['url']);
      self::assertStringEndsWith('osm-username-verification-success', $e->errorData['url']);
    }

    self::assertArrayHasKey('oauthReturn', $this->hookEvents);
    $returnEvent = array_pop($this->hookEvents['oauthReturn']);

    $getToken = \Civi\Api4\OAuthContactToken::get(FALSE)
      ->addOrderBy('id', 'DESC')
      ->setLimit(1)
      ->execute()->single();

    self::assertEquals($returnEvent[0]['id'], $getToken['id']);
    self::assertEquals($contact['id'], $getToken['contact_id']);
    self::assertEquals('example-access-token-value', $getToken['access_token']);
    self::assertEquals('lemniscus', $getToken['resource_owner_name']);
  }

}
