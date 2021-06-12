<?php

use CRM_OsmfVerifyContributor_ExtensionUtil as E;

class CRM_OsmfVerifyContributor_Form_OsmfContributionPageSettings extends CRM_Contribute_Form_ContributionPage {

  public $_component = 'contribute';

  public function preProcess() {
    parent::preProcess();
    $this->setSelectedChild('osmfcontributionpagesettings');
  }

  public function setDefaultValues(): array {
    if (isset($this->_id)) {
      $page_id = $this->_id;
      $def = Civi::settings()->get(E::SHORT_NAME . ':' . 'contribution_page:'
          . $page_id . ':only_thankyou_message') ?? FALSE;
      $this->assign('pageId', $page_id);
    }
    return ['only_thankyou_message' => $def ?? FALSE];
  }

  public function buildQuickForm() {
    $checkboxLabel = E::ts('Show only "Thank-you Message" on Thank-You page');
    $this->add('checkbox', 'only_thankyou_message', $checkboxLabel);
    $this->assign('elementNames', ['only_thankyou_message']);
    parent::buildQuickForm();
  }

  public function postProcess() {
    $values = $this->controller->exportValues($this->_name);
    $page_id = $this->_id;
    Civi::settings()->set(E::SHORT_NAME . ':' . 'contribution_page:'
      . $page_id . ':only_thankyou_message', $values['only_thankyou_message']);
    parent::endPostProcess();
  }

  public function getTitle(): string {
    return ts('OSMF Overrides');
  }

}
