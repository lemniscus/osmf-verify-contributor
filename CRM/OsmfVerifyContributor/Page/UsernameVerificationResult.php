<?php
use CRM_OsmfVerifyContributor_ExtensionUtil as E;

class CRM_OsmfVerifyContributor_Page_UsernameVerificationResult extends CRM_Core_Page {

  public function run() {

    foreach (['osm_username', 'membership_status', 'error_message'] as $key) {
      $vars[$key] = CRM_Core_Session::singleton()->get($key, 'osmfvc');
      $this->assign($key, $vars[$key]);
    }

    if (!empty($vars['error_message'])) {
      CRM_Utils_System::setTitle(E::ts('Error'));
    }
    else {
      CRM_Utils_System::setTitle(E::ts('OpenStreetMap username verified'));
    }

    parent::run();
  }

}
