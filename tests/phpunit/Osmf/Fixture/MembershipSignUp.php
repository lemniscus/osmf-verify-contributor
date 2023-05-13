<?php

namespace Osmf\Fixture;

use Civi\Api4\PriceField;
use Civi\Api4\PriceFieldValue;

class MembershipSignUp {

  public static function setUpCustomFields(): void {
    \Civi\Api4\CustomGroup::delete(FALSE)
      ->addWhere('name', '=', 'OpenStreetMap_user_info')
      ->execute();
    \Civi\Api4\CustomField::delete(FALSE)
      ->addWhere('name', 'IN', [
        'Verified_OpenStreetMap_User_ID',
        'Verified_OpenStreetMap_User_ID',
      ])->execute();
    $customGroup = \Civi\Api4\CustomGroup::create(FALSE)
      ->setValues(
        [
          'name' => 'OpenStreetMap_user_info',
          'title' => 'Constituent Information',
          'extends' => 'Individual',
          'style' => 'Inline',
          'collapse_display' => TRUE,
          'help_pre' => 'Please enter additional constituent information as data becomes available for this contact.',
          'weight' => 1,
          'is_active' => TRUE,
          'table_name' => 'civicrm_value_OpenStreetMap_user_info_1',
          'is_multiple' => FALSE,
          'collapse_adv_display' => FALSE,
          'is_reserved' => FALSE,
          'is_public' => TRUE,
        ]
      )->execute()->single();
    \Civi\Api4\CustomField::create(FALSE)
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
    \Civi\Api4\CustomField::create(FALSE)
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

  public static function makeCompleteMembershipSignupPage(): array {

    $membershipType = self::makeMembershipType();

    $contributionPage = self::makeContributionPageEntityOnly();

    $cPageSettingsController = new \CRM_Contribute_Controller_ContributionPage(
      NULL,
      \CRM_Core_Action::UPDATE);

    /** @var \CRM_Member_Form_MembershipBlock $membershipBlockForm */
    $membershipBlockForm = $cPageSettingsController->getPage('MembershipBlock');
    $membershipBlockForm->setVar('_id', $contributionPage['id']);
    $membershipBlockForm->setVar('_values', $contributionPage);

    $cPageSettingsController->container()['values']['MembershipBlock'] = [
      'member_is_active' => '1',
      'is_required' => '1',
      'membership_type' =>
        [
          $membershipType['id'] => 1,
        ],
      'membership_type_default' => $membershipType['id'],
      'is_active' => '1',
    ];
    try {
      $membershipBlockForm->postProcess();
      // we shouldn't reach this line
      throw new \Exception('Something went wrong with creating the membership block');
    }
    catch (\CRM_Core_Exception_PrematureExitException $e) {
      // seems like success! the controller issued a redirect
    }

    $priceField = PriceField::get(FALSE)->execute()->last();
    $priceFieldValue = PriceFieldValue::get(FALSE)->execute()->last();

    $contributionPage['priceSetId'] = $priceField['price_set_id'];
    $contributionPage['priceFieldId'] = $priceField['id'];
    $contributionPage['priceFieldValueId'] = $priceFieldValue['id'];

    $ufGroup = self::makeProfile();

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

  public static function makeMembershipType(): array {
    \Civi\Api4\MembershipType::delete(FALSE)
      ->addWhere('name', '=', 'Fee-waiver Member')
      ->execute();

    $membershipType = \Civi\Api4\MembershipType::create(FALSE)
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
          "visibility" => "Public",
          "auto_renew" => FALSE,
          "is_active" => TRUE,
        ]
      )->execute()->single();

    // necessary special magic happens only when membershiptype is re-saved ??
    \Civi\Api4\MembershipType::update(FALSE)
      ->addWhere('id', '=', $membershipType['id'])
      ->addValue('id', $membershipType['id'])->execute();
    return $membershipType;
  }

  public static function makeContributionPageEntityOnly(): array {
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
          "payment_processor" => self::makeDummyProcessor(),
        ]
      )
      ->execute()->single();
    return $contributionPage;
  }

  public static function makeProfile(): array {
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
    return $ufGroup;
  }

  public static function makePaymentProcessor($params = []) {
    // copied wholesale from CiviUnitTestCase: https://github.com/civicrm/civicrm-core/blob/365efc75a69e0328e1d526efc4d875068c509164/tests/phpunit/CiviTest/CiviUnitTestCase.php#L808
    $processorParams = [
      'domain_id' => 1,
      'name' => 'Dummy',
      'title' => 'Dummy',
      'payment_processor_type_id' => 'Dummy',
      'financial_account_id' => 12,
      'is_test' => TRUE,
      'is_active' => 1,
      'user_name' => '',
      'url_site' => 'http://dummy.com',
      'url_recur' => 'http://dummy.com',
      'billing_mode' => 1,
      'sequential' => 1,
      'payment_instrument_id' => 'Debit Card',
    ];
    $processorParams = array_merge($processorParams, $params);
    $processor = civicrm_api3('PaymentProcessor', 'create', $processorParams);
    return $processor['id'];
  }

  public static function makeDummyProcessor($processorParams = []) {
    // adapted from CiviUnitTestCase: https://github.com/civicrm/civicrm-core/blob/365efc75a69e0328e1d526efc4d875068c509164/tests/phpunit/CiviTest/CiviUnitTestCase.php#L839
    $paymentProcessorID = self::makePaymentProcessor($processorParams);
    $processorParams['is_test'] = FALSE;
    return self::makePaymentProcessor($processorParams);
  }

}
