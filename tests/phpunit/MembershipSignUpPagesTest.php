<?php

use Civi\OAuth\Provider\DummyOpenStreetMapProvider;
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
      ->callback([__CLASS__, 'setUpCustomFields'])
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

  public static function setUpCustomFields(): void {
    Civi\Api4\CustomGroup::delete(FALSE)
      ->addWhere('name', '=', 'constituent_information')
      ->execute();
    Civi\Api4\CustomField::delete(FALSE)
      ->addWhere('name', 'IN', [
        'Verified_OpenStreetMap_User_ID',
        'Verified_OpenStreetMap_User_ID',
      ])->execute();
    $customGroup = Civi\Api4\CustomGroup::create(FALSE)
      ->setValues(
        [
          'name' => 'constituent_information',
          'title' => 'Constituent Information',
          'extends' => 'Individual',
          'style' => 'Inline',
          'collapse_display' => TRUE,
          'help_pre' => 'Please enter additional constituent information as data becomes available for this contact.',
          'weight' => 1,
          'is_active' => TRUE,
          'table_name' => 'civicrm_value_constituent_information_1',
          'is_multiple' => FALSE,
          'collapse_adv_display' => FALSE,
          'is_reserved' => FALSE,
          'is_public' => TRUE,
        ]
      )->execute()->single();
    Civi\Api4\CustomField::create(FALSE)
      ->setValues(
        [
          'custom_group_id' => $customGroup['id'],
          'name' => 'Verified_OpenStreetMap_User_ID',
          'label' => 'Verified OpenStreetMap User ID',
          'data_type' => 'String',
          'html_type' => 'Text',
          'is_searchable' => TRUE,
          'is_search_range' => FALSE,
          'is_active' => TRUE,
          'text_length' => 255,
          'serialize' => 0,
          'in_selector' => FALSE,
        ]
      )->execute();
    Civi\Api4\CustomField::create(FALSE)
      ->setValues(
        [
          'custom_group_id' => $customGroup['id'],
          'name' => 'Verified_OpenStreetMap_Username',
          'label' => 'Verified OpenStreetMap Username',
          'data_type' => 'String',
          'html_type' => 'Text',
          'is_searchable' => TRUE,
          'is_search_range' => FALSE,
          'is_active' => TRUE,
          'text_length' => 255,
          'serialize' => 0,
          'in_selector' => FALSE,
        ]
      )->execute();
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

  private function makeMembershipSignupEntities(): array {
    Civi\Api4\MembershipType::delete(FALSE)
      ->addWhere('name', '=', 'Fee-waiver Member')
      ->execute();
    $membershipType = Civi\Api4\MembershipType::create(FALSE)
      ->setValues(
        [
          "domain_id" => 1,
          "name" => "Fee-waiver Member",
          "description" => "Fee-waiver members are members under the fee waiver program. They are like associate members. The fact that they are fee waiver members is private.",
          "member_of_contact_id" => 1,
          "financial_type_id:name" => "Member Dues",
          "minimum_fee" => 0.0,
          "duration_unit" => "year",
          "duration_interval" => 1,
          "period_type" => "rolling",
          "visibility" => "Admin",
          "auto_renew" => FALSE,
          "is_active" => TRUE,
        ]
      )->execute()->single();

    $priceField = Civi\Api4\PriceField::update(FALSE)
      ->addWhere('price_set_id:name', '=', 'default_membership_type_amount')
      ->addValue('name', 'membership_amount')
      ->addValue('is_required', '1')
      ->execute()->single();

    $priceField = Civi\Api4\PriceField::get(FALSE)
      ->addWhere('price_set_id:name', '=', 'default_membership_type_amount')
      ->execute()->single();

    $priceFieldValue = Civi\Api4\PriceFieldValue::update(FALSE)
      ->addWhere('price_field_id', '=', $priceField['id'])
      ->addWhere('membership_type_id', '=', $membershipType['id'])
      ->addValue('price_field_id', $priceField['id'])
      // needed due to bug in \CRM_Price_BAO_PriceFieldValue::add
      ->addValue('is_default', '1')
      ->execute()->single();

    $contributionPage = \Civi\Api4\ContributionPage::create(FALSE)
      ->setValues(
        [
          "title" => "Member Signup and Renewal",
          "financial_type_id:name" => "Member Dues",
          "is_credit_card_only" => FALSE,
          "is_monetary" => FALSE,
          "is_recur" => FALSE,
          "is_confirm_enabled" => FALSE,
          "is_recur_interval" => FALSE,
          "is_recur_installments" => FALSE,
          "adjust_recur_start_date" => FALSE,
          "is_pay_later" => FALSE,
          "is_partial_payment" => FALSE,
          "is_allow_other_amount" => FALSE,
          "thankyou_title" => "Almost finished",
          "thankyou_text" => "{oauth.authCodeUrl}",
          "is_email_receipt" => FALSE,
          "is_active" => TRUE,
          "amount_block_is_active" => FALSE,
          "is_share" => FALSE,
          "is_billing_required" => FALSE,
        ]
      )
      ->execute()->single();

    CRM_Price_BAO_PriceSet::addTo(
      'civicrm_contribution_page',
      $contributionPage['id'],
      $priceField['price_set_id']
    );
    $contributionPage['priceSetId'] = $priceField['price_set_id'];
    $contributionPage['priceFieldId'] = $priceField['id'];
    $contributionPage['priceFieldValueId'] = $priceFieldValue['id'];

    $membershipBlock = civicrm_api3('MembershipBlock', 'create', [
      'entity_id' => $contributionPage['id'],
      'entity_table' => "civicrm_contribution_page",
      'membership_types' => serialize([$membershipType['id'] => NULL]),
      'membership_type_default' => $membershipType['id'],
      'display_min_fee' => 0,
      'is_separate_payment' => 0,
      'is_required' => 1,
      'is_active' => 1,
    ]);

    $ufGroup = \Civi\Api4\UFGroup::create(FALSE)
      ->setValues([
        "is_active" => TRUE,
        "group_type" => [
          "Individual",
          "Contact",
        ],
        "title" => "New Individual",
        "frontend_title" => NULL,
        "description" => NULL,
        "help_pre" => NULL,
        "help_post" => NULL,
        "limit_listings_group_id" => NULL,
        "post_URL" => NULL,
        "add_to_group_id" => NULL,
        "add_captcha" => FALSE,
        "is_map" => FALSE,
        "is_edit_link" => FALSE,
        "is_uf_link" => FALSE,
        "is_update_dupe" => FALSE,
        "cancel_URL" => NULL,
        "is_cms_user" => FALSE,
        "notify" => NULL,
        "is_reserved" => TRUE,
        "name" => "new_individual_tessssssst",
        "created_id" => NULL,
        "created_date" => NULL,
        "is_proximity_search" => FALSE,
        "cancel_button_text" => NULL,
        "submit_button_text" => NULL,
        "add_cancel_button" => TRUE,
      ])
      ->execute()->single();

    $ufFieldParams = [
      [
        "uf_group_id" => $ufGroup['id'],
        "field_name" => "first_name",
        "is_active" => TRUE,
        "is_view" => FALSE,
        "is_required" => TRUE,
        "weight" => 1,
        "help_post" => NULL,
        "help_pre" => NULL,
        "visibility" => "User and User Admin Only",
        "in_selector" => FALSE,
        "is_searchable" => FALSE,
        "location_type_id" => NULL,
        "phone_type_id" => NULL,
        "website_type_id" => NULL,
        "label" => "First Name",
        "field_type" => "Individual",
        "is_reserved" => FALSE,
        "is_multi_summary" => FALSE,
      ],
      [
        "uf_group_id" => $ufGroup['id'],
        "field_name" => "last_name",
        "is_active" => TRUE,
        "is_view" => FALSE,
        "is_required" => TRUE,
        "weight" => 2,
        "help_post" => NULL,
        "help_pre" => NULL,
        "visibility" => "User and User Admin Only",
        "in_selector" => FALSE,
        "is_searchable" => FALSE,
        "location_type_id" => NULL,
        "phone_type_id" => NULL,
        "website_type_id" => NULL,
        "label" => "Last Name",
        "field_type" => "Individual",
        "is_reserved" => FALSE,
        "is_multi_summary" => FALSE,
      ],
      [
        "uf_group_id" => $ufGroup['id'],
        "field_name" => "email",
        "is_active" => TRUE,
        "is_view" => FALSE,
        "is_required" => FALSE,
        "weight" => 4,
        "help_post" => NULL,
        "help_pre" => NULL,
        "visibility" => "User and User Admin Only",
        "in_selector" => FALSE,
        "is_searchable" => FALSE,
        "location_type_id" => NULL,
        "phone_type_id" => NULL,
        "website_type_id" => NULL,
        "label" => "Email Address",
        "field_type" => "Contact",
        "is_reserved" => FALSE,
        "is_multi_summary" => FALSE,
      ],
      [
        "uf_group_id" => $ufGroup['id'],
        "field_name" => "country",
        "is_active" => TRUE,
        "is_view" => FALSE,
        "is_required" => FALSE,
        "weight" => 3,
        "help_post" => NULL,
        "help_pre" => NULL,
        "visibility" => "User and User Admin Only",
        "in_selector" => FALSE,
        "is_searchable" => FALSE,
        "location_type_id" => NULL,
        "phone_type_id" => NULL,
        "website_type_id" => NULL,
        "label" => "Country",
        "field_type" => "Contact",
        "is_reserved" => NULL,
        "is_multi_summary" => FALSE,
      ],
    ];
    foreach ($ufFieldParams as $ps) {
      \Civi\Api4\UFField::create(FALSE)->setValues($ps)->execute();
    }

    \Civi\Api4\UFJoin::create(FALSE)
      ->setValues([
        "is_active" => TRUE,
        "module" => "CiviContribute",
        "entity_table" => "civicrm_contribution_page",
        "entity_id" => $contributionPage['id'],
        "weight" => 1,
        "uf_group_id" => $ufGroup['id'],
        "module_data" => NULL,
      ])->execute();

    return $contributionPage;
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

    $cPage = $this->makeMembershipSignupEntities();
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
    $contributionPage = $this->makeMembershipSignupEntities();

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
    $contributionPage = $this->makeMembershipSignupEntities();

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
    $contributionPage = $this->makeMembershipSignupEntities();

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
    $contributionPage = $this->makeMembershipSignupEntities();

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
