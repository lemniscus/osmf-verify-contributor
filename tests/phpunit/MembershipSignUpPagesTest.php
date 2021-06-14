<?php

use Osmf\Fixture\DummyOpenStreetMapProvider;
use CRM_OsmfVerifyContributor_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * Test the custom processing that should take place during OSMF's
 * membership signup flow.
 *
 * @group headless
 */
class MembershipSignUpPagesTest extends \PHPUnit\Framework\TestCase implements
    HeadlessInterface,
    HookInterface,
    TransactionalInterface {

  /**
   * @var array
   */
  private $originalRequest;

  private $originalPost;

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()
      ->install(['oauth-client', 'osmf-verify-contributor'])
      ->callback(['\Osmf\Fixture\MembershipSignUp', 'setUpCustomFields'])
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();
    $this->originalRequest = $_REQUEST;
    $this->originalPost = $_POST;

    CRM_Core_Config::singleton()->userPermissionClass->permissions = [
      'profile create',
      'make online contributions',
    ];
    self::assertNull(\CRM_Core_Session::singleton()->getLoggedInContactID());
  }

  public function tearDown(): void {
    parent::tearDown();
    $_REQUEST = $this->originalRequest;
    $_POST = $this->originalPost;
  }

  private function makeOsmOAuthClient(): array {
    $oauthClient = \Civi\Api4\OAuthClient::create(FALSE)->setValues(
      [
        'provider' => 'openstreetmap.org',
        'guid' => "example-client-guid",
        'secret' => "example-secret",
      ]
    )->execute()->single();
    return $oauthClient;
  }

  private function makeContactWithContribution(): array {
    return \Civi\Api4\Contact::create(FALSE)
      ->addValue('first_name', 'Bob')
      ->addChain('contribution', \Civi\Api4\Contribution::create(FALSE)
        ->addValue('contact_id', '$id')
        ->addValue('contribution_status_id', 1)
        ->addValue('total_amount', 0)
        ->addValue('invoice_id', 12345678)
        ->addValue('financial_type_id', 2))
      ->execute()->single();
  }

  private function makeChangeSetsXml($startDay, $howMany): string {
    $changeSetsXml[] = '<?xml version="1.0" encoding="UTF-8"?>';
    $changeSetsXml[] = '<osm version="0.6" generator="OpenStreetMap server" copyright="OpenStreetMap and contributors" attribution="http://www.openstreetmap.org/copyright" license="http://opendatacommons.org/licenses/odbl/1-0/">';
    $days = [];
    for ($hr = $startDay * 24; count($days) < $howMany; $hr = $hr + 16) {
      $d1 = new DateTime("-$hr hours", new DateTimeZone('Etc/UTC'));
      $d2 = $d1->add(new DateInterval('PT30M'));
      $changeSetsXml[] = "<changeset id=\"$hr\""
        . ' created_at="' . $d1->format("Y-m-d\TH:i:s") . 'Z" open="false"'
        . ' comments_count="0" changes_count="8"'
        . ' closed_at="' . $d2->format("Y-m-d\TH:i:s") . 'Z"'
        . ' min_lat="47.1885649" min_lon="8.4652215" max_lat="47.2047274" max_lon="8.4704262" uid="1" user="foo">';
      $changeSetsXml[] = '<tag k="source" v="local knowledge"/>';
      $changeSetsXml[] = '</changeset>';
      $days[$d1->format('Ymd')] = 1;
    }
    $changeSetsXml[] = '</osm>';
    return implode("\n", $changeSetsXml);
  }

  private function makeDummyHttpClientThatGets39DaysOfOsmChangeSets(): \GuzzleHttp\Client {
    $osmChangesetsResponse1 = [
      'status' => 200,
      'headers' => ['Content-Type' => 'application/xml; charset=utf-8'],
      'body' => $this->makeChangeSetsXml(1, 39),
    ];

    $osmChangesetsResponse2 = [
      'status' => 200,
      'headers' => ['Content-Type' => 'application/xml; charset=utf-8'],
      'body' => $this->makeChangeSetsXml(0, 0),
    ];

    return DummyOpenStreetMapProvider::createHttpClient(
      [
        $osmChangesetsResponse1,
        $osmChangesetsResponse2,
      ]);
  }

  private function makeDummyHttpClientThatGets111DaysOfOsmChangeSets(): \GuzzleHttp\Client {
    $osmChangesetsResponse1 = [
      'status' => 200,
      'headers' => ['Content-Type' => 'application/xml; charset=utf-8'],
      'body' => $this->makeChangeSetsXml(1, 100),
    ];

    $osmChangesetsResponse2 = [
      'status' => 200,
      'headers' => ['Content-Type' => 'application/xml; charset=utf-8'],
      'body' => $this->makeChangeSetsXml(222, 11),
    ];

    return DummyOpenStreetMapProvider::createHttpClient(
      [
        $osmChangesetsResponse1,
        $osmChangesetsResponse2,
      ]);
  }

  private function submitContributionPage($contributionPage): void {
    $_REQUEST = $_POST = [
      'id' => $contributionPage['id'],
      'entryURL' => 'http://cmaster.localhost/civicrm/contribute/transact/?reset=1&amp;id=2',
      'priceSetId' => $contributionPage['priceSetId'],
      'selectProduct' => '',
      '_qf_default' => 'Main:upload',
      'MAX_FILE_SIZE' => '41943040',
      'price_' . $contributionPage['priceFieldId'] => (string) $contributionPage['priceFieldValueId'],
      'first_name' => 'Foo',
      'last_name' => 'Bar',
      'country-Primary' => '1002',
      'email-Primary' => 'baz@biff.net',
      '_qf_Main_upload' => '1',
    ];
    $controller = new CRM_Contribute_Controller_Contribution();
    $controller->run();
  }

  public function testUrlToken() {
    $oauthClient = $this->makeOsmOAuthClient();

    $cPage = \Osmf\Fixture\MembershipSignUp::makeCompleteMembershipSignupPage();
    $c = $this->makeContactWithContribution();

    $form = new CRM_Contribute_Form_Contribution_ThankYou();
    $_REQUEST['id'] = $cPage['id'];
    $form->controller = new CRM_Contribute_Controller_Contribution();
    $form->_params['contributionID'] = $c['contribution'][0]['id'];
    $form->buildForm();
    $evaluatedToken = (string) $form->get_template_vars('thankyou_text');

    $expectedUrl = Civi\Api4\OAuthClient::authorizationCode(0)
      ->addWhere('id', '=', $oauthClient['id'])
      ->setLandingUrl(CRM_Utils_System::url(
        'civicrm/osm-username-verification-success',
        NULL,
        TRUE
      ))
      ->setStorage('OAuthContactToken')
      ->setTag('linkContact:' . $c['id'])
      ->execute()->single()['url'];
    parse_str(parse_url($expectedUrl, PHP_URL_QUERY), $query);

    self::assertStringStartsWith(
      'https://oauth2.apis.dev.openstreetmap.org/oauth2/authorize?',
      $evaluatedToken
    );
    self::assertContains($oauthClient['guid'], $evaluatedToken);
    self::assertEquals(
      preg_replace('/state=[^&]+/', '', $expectedUrl),
      preg_replace('/state=[^&]+/', '', $evaluatedToken));
  }

  public function testMembershipStartsOutPending() {
    $contributionPage = \Osmf\Fixture\MembershipSignUp::makeCompleteMembershipSignupPage();

    try {
      $this->submitContributionPage($contributionPage);
      self::fail('We should not reach this line');
    }
    catch (CRM_Core_Exception_PrematureExitException $e) {
      self::assertContains('_qf_ThankYou_display', $e->errorData['url']);
      $email = \Civi\Api4\Email::get(FALSE)
        ->addWhere('email', '=', 'baz@biff.net')
        ->execute()->last();
      $membership = civicrm_api3('Membership', 'getsingle', [
        'contact_id' => $email['contact_id'],
      ]);
      self::assertNull($membership['start_date'] ?? NULL);
      self::assertNull($membership['end_date'] ?? NULL);
      $membershipType = \Civi\Api4\MembershipType::get(FALSE)
        ->addWhere('id', '=', $membership['membership_type_id'])
        ->execute()->single();
      self::assertEquals('Fee-waiver Member', $membershipType['name']);
      $pendingStatusId = CRM_Core_Pseudoconstant::getKey(
        'CRM_Member_BAO_Membership',
        'status_id',
        'Pending');
      self::assertEquals($pendingStatusId, $membership['status_id']);
    }
  }

  public function testMembershipStaysPendingUntilVerified() {
    $contributionPage = \Osmf\Fixture\MembershipSignUp::makeCompleteMembershipSignupPage();

    try {
      $this->submitContributionPage($contributionPage);
      self::fail('We should not reach this line');
    }
    catch (CRM_Core_Exception_PrematureExitException $e) {
      // Membership has been created. Now, auto-update membership statuses
      civicrm_api3('Job', 'process_membership');

      $email = \Civi\Api4\Email::get(FALSE)
        ->addWhere('email', '=', 'baz@biff.net')
        ->execute()->last();
      $pendingStatusId = CRM_Core_Pseudoconstant::getKey(
        'CRM_Member_BAO_Membership',
        'status_id',
        'Pending');
      $membership = civicrm_api3('Membership', 'getsingle', [
        'contact_id' => $email['contact_id'],
      ]);
      self::assertEquals($pendingStatusId, $membership['status_id']);
    }
  }

  public function testPassVerificationAndActivate() {
    $contributionPage = \Osmf\Fixture\MembershipSignUp::makeCompleteMembershipSignupPage();

    try {
      $this->submitContributionPage($contributionPage);
      self::fail('We should not reach this line');
    }
    catch (CRM_Core_Exception_PrematureExitException $e) {
      // Membership has been created
      $email = \Civi\Api4\Email::get(FALSE)
        ->addWhere('email', '=', 'baz@biff.net')
        ->execute()->last();
      $contactId = $email['contact_id'];
      $membership = civicrm_api3('Membership', 'getsingle', [
        'contact_id' => $contactId,
      ]);
      $statuses = civicrm_api3('Membership', 'getoptions', [
        'field' => "status_id",
      ])['values'];

      self::assertEquals('Pending', $statuses[$membership['status_id']]);

      Civi::$statics['osmf-verify-contributor']['http-client'] =
        $this->makeDummyHttpClientThatGets111DaysOfOsmChangeSets();

      Civi\Api4\OAuthContactToken::create(FALSE)
        ->setValues([
          "tag" => "linkContact:$contactId",
          "client_id" => $this->makeOsmOAuthClient()['id'],
          "contact_id" => $contactId,
          "grant_type" => "authorization_code",
          "scopes" => [
            "read_prefs",
          ],
          "token_type" => "Bearer",
          "access_token" => "example-access-token",
          "resource_owner_name" => "foobar",
          "resource_owner" => [
            'id' => '99',
          ],
        ])->execute();

      $membership = civicrm_api3('Membership', 'getsingle', [
        'contact_id' => $contactId,
      ]);
      $note = \Civi\Api4\Note::get(FALSE)
        ->addWhere('entity_table', '=', 'civicrm_contact')
        ->addWhere('entity_id', '=', $contactId)
        ->execute()->last();

      self::assertEquals('New', $statuses[$membership['status_id']]);
      self::assertEquals('New', CRM_Core_Session::singleton()
        ->get('membership_status', 'osmfvc'));
      self::assertContains(' 111', $note['subject']);
      self::assertContains(' 111 ', $note['note']);
    }
  }

  public function testFailVerification() {
    $contributionPage = \Osmf\Fixture\MembershipSignUp::makeCompleteMembershipSignupPage();

    try {
      $this->submitContributionPage($contributionPage);
      self::fail('We should not reach this line');
    }
    catch (CRM_Core_Exception_PrematureExitException $e) {
      // Membership has been created
      $email = \Civi\Api4\Email::get(FALSE)
        ->addWhere('email', '=', 'baz@biff.net')
        ->execute()->last();
      $contactId = $email['contact_id'];
      $membership = civicrm_api3('Membership', 'getsingle', [
        'contact_id' => $contactId,
      ]);
      $statuses = civicrm_api3('Membership', 'getoptions', [
        'field' => "status_id",
      ])['values'];

      self::assertEquals('Pending', $statuses[$membership['status_id']]);

      Civi::$statics['osmf-verify-contributor']['http-client'] =
        $this->makeDummyHttpClientThatGets39DaysOfOsmChangeSets();

      Civi\Api4\OAuthContactToken::create(FALSE)
        ->setValues([
          "tag" => "linkContact:$contactId",
          "client_id" => $this->makeOsmOAuthClient()['id'],
          "contact_id" => $contactId,
          "grant_type" => "authorization_code",
          "scopes" => [
            "read_prefs",
          ],
          "token_type" => "Bearer",
          "access_token" => "example-access-token",
          "resource_owner_name" => "foobar",
          "resource_owner" => [
            'id' => '99',
          ],
        ])->execute();

      $membership = civicrm_api3('Membership', 'getsingle', [
        'contact_id' => $contactId,
      ]);
      $note = \Civi\Api4\Note::get(FALSE)
        ->addWhere('entity_table', '=', 'civicrm_contact')
        ->addWhere('entity_id', '=', $contactId)
        ->execute()->last();

      self::assertEquals('Pending', $statuses[$membership['status_id']]);
      self::assertEquals('Pending', CRM_Core_Session::singleton()
        ->get('membership_status', 'osmfvc'));
      self::assertContains(' 39', $note['subject']);
      self::assertContains(' 39 ', $note['note']);
    }
  }

}
