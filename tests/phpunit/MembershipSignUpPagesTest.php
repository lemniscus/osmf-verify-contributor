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

  private $originalRequest;

  private $originalPost;

  private $createdEntities = [];

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()
      ->install(['oauth-client', 'osmf-verify-contributor'])
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();

    \Osmf\Fixture\MembershipSignUp::setUpCustomFields();

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

    foreach ($this->createdEntities as $type => $ids) {
      foreach ($ids as $id) {
        civicrm_api3($type, 'Delete', ['id' => $id]);
      }
    }

    \Osmf\Membership::weAreDoneProcessingAContributionPageSubmission();
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
      'https://www.openstreetmap.org/oauth2/authorize?',
      $evaluatedToken
    );
    self::assertStringContainsString($oauthClient['guid'], $evaluatedToken);
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
      self::assertStringContainsString('_qf_ThankYou_display', $e->errorData['url']);

      $membership = $this->getMembershipByEmail('baz@biff.net');
      $this->createdEntities['Membership'][] = $membership['id'];
      self::assertNull($membership['start_date'] ?? NULL);
      self::assertNull($membership['end_date'] ?? NULL);

      $membershipType = \Civi\Api4\MembershipType::get(FALSE)
        ->addWhere('id', '=', $membership['membership_type_id'])
        ->execute()->single();

      self::assertEquals('Fee-waiver Member', $membershipType['name']);

      self::assertEquals($this->getPendingStatusId(), $membership['status_id']);
    }
  }

  public function testMembershipStaysPendingUntilVerified() {
    $contributionPage = \Osmf\Fixture\MembershipSignUp::makeCompleteMembershipSignupPage();

    try {
      $this->submitContributionPage($contributionPage);
      self::fail('We should not reach this line');
    }
    catch (CRM_Core_Exception_PrematureExitException $e) {
      // Membership has been created.

      $membership = $this->getMembershipByEmail('baz@biff.net');
      $this->createdEntities['Membership'][] = $membership['id'];
      self::assertEquals('Pending', $this->getStatusName($membership));

      // Now, auto-update membership statuses

      civicrm_api3('Job', 'process_membership');

      $membership = civicrm_api3('Membership', 'getsingle', [
        'id' => $membership['id'],
      ]);
      self::assertEquals($this->getPendingStatusId(), $membership['status_id']);
    }
  }

  public function testPassVerificationAndActivateNew() {
    $contributionPage = \Osmf\Fixture\MembershipSignUp::makeCompleteMembershipSignupPage();

    try {
      $this->submitContributionPage($contributionPage);
      self::fail('We should not reach this line');
    }
    catch (CRM_Core_Exception_PrematureExitException $e) {
      // Membership has been created

      $membership = $this->getMembershipByEmail('baz@biff.net');
      $this->createdEntities['Membership'][] = $membership['id'];
      $contactId = $membership['contact_id'];

      $statusName = $this->getStatusName($membership);
      self::assertEquals('Pending', $statusName);

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

      self::assertEquals('New', $this->getStatusName($membership));
      self::assertEquals('New', CRM_Core_Session::singleton()
        ->get('membership_status', 'osmfvc'));
      self::assertStringContainsString(' 111', $note['subject']);
      self::assertStringContainsString(' 111 ', $note['note']);
    }
  }

  public function testPassVerificationAndActivateRenewal() {
    $contributionPage = \Osmf\Fixture\MembershipSignUp::makeCompleteMembershipSignupPage();

    $statuses = civicrm_api3('Membership', 'getoptions', [
      'field' => "status_id",
    ])['values'];

    // ORIGINAL SIGN-UP

    try {
      $this->submitContributionPage($contributionPage);
      self::fail('We should not reach this line');
    }
    catch (CRM_Core_Exception_PrematureExitException $e) {
      // Contribution page has been processed
      \Osmf\Membership::weAreDoneProcessingAContributionPageSubmission();

      $membership = $this->getMembershipByEmail('baz@biff.net');
      $this->createdEntities['Membership'][] = $membership['id'];
      $contactId = $membership['contact_id'];

      $date = new \DateTime('-360 Days');
      $startDateBeforeRenew = $date->format('Y-m-d');
      $membership['start_date'] = $membership['join_date'] = $startDateBeforeRenew;

      $date = new \DateTime('+4 Days');
      $endDateBeforeRenew = $date->format('Y-m-d');
      $membership['end_date'] = $endDateBeforeRenew;

      $membership['status_id'] = 'Current';
      $membership['is_override'] = FALSE;

      civicrm_api3('Membership', 'create', $membership);

      $membership = civicrm_api3('Membership', 'getsingle', [
        'id' => $membership['id'],
      ]);

      self::assertEquals('Current', $statuses[$membership['status_id']]);

      // RENEWAL

      try {
        $this->submitContributionPage($contributionPage);
        self::fail('We should not reach this line');
      }
      catch (CRM_Core_Exception_PrematureExitException $e) {
        // Contribution page has been processed

        \Osmf\Membership::weAreDoneProcessingAContributionPageSubmission();

        $membership = civicrm_api3('Membership', 'getsingle', [
          'id' => $membership['id'],
        ]);

        self::assertEquals('Pending', $statuses[$membership['status_id']]);
        self::assertEquals($startDateBeforeRenew, $membership['start_date']);
        self::assertEquals($endDateBeforeRenew, $membership['end_date']);

        $osmName = 'foobar';
        $osmId = '99';

        \Civi\Api4\Contact::update(FALSE)
          ->addWhere('id', '=', $contactId)
          ->addValue('OpenStreetMap_user_info.Verified_OpenStreetMap_Username', $osmName)
          ->addValue('OpenStreetMap_user_info.Verified_OpenStreetMap_User_ID', $osmId)
          ->execute();

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
            "resource_owner_name" => $osmName,
            "resource_owner" => [
              'id' => $osmId,
            ],
          ])->execute();

        $date = new DateTime($endDateBeforeRenew);
        $date->add(new DateInterval('P1Y'));
        $expectedEndDate = $date->format('Y-m-d');

        $membership = civicrm_api3('Membership', 'getsingle', [
          'contact_id' => $contactId,
        ]);
        $note = \Civi\Api4\Note::get(FALSE)
          ->addWhere('entity_table', '=', 'civicrm_contact')
          ->addWhere('entity_id', '=', $contactId)
          ->execute()->last();

        self::assertEquals('Current', $statuses[$membership['status_id']]);
        self::assertEquals($expectedEndDate, $membership['end_date']);
        self::assertEquals($startDateBeforeRenew, $membership['start_date']);
        self::assertEquals('Current', CRM_Core_Session::singleton()
          ->get('membership_status', 'osmfvc'));
        self::assertStringContainsString(' 111', $note['subject']);
        self::assertStringContainsString(' 111 ', $note['note']);
      }
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

      $membership = $this->getMembershipByEmail('baz@biff.net');
      $this->createdEntities['Membership'][] = $membership['id'];
      $contactId = $membership['contact_id'];

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
      self::assertStringContainsString(' 39', $note['subject']);
      self::assertStringContainsString(' 39 ', $note['note']);
    }
  }

  private function getMembershipByEmail($address): array {
    $email = \Civi\Api4\Email::get(FALSE)
      ->addWhere('email', '=', $address)
      ->execute()->last();

    $membership = civicrm_api3('Membership', 'getsingle', [
      'contact_id' => $email['contact_id'],
    ]);
    return $membership;
  }

  private function getPendingStatusId() {
    $pendingStatusId = CRM_Core_Pseudoconstant::getKey(
      'CRM_Member_BAO_Membership',
      'status_id',
      'Pending');
    return $pendingStatusId;
  }

  private function getStatusName($membership) {
    $statuses = civicrm_api3('Membership', 'getoptions', [
      'field' => "status_id",
    ])['values'];

    return $statuses[$membership['status_id']];
  }

}
