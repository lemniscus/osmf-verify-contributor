<?php
use CRM_OsmfVerifyContributor_ExtensionUtil as E;

class CRM_OsmfVerifyContributor_Page_UsernameVerificationResult extends CRM_Core_Page {

  public function run() {
    CRM_Utils_System::setTitle(E::ts('OpenStreetMap username verified'));

    $this->assign(
      'osm_username',
      CRM_Core_Session::singleton()->get('osm_username', 'osmfvc'));
    $this->assign(
      'membership_status',
      CRM_Core_Session::singleton()->get('membership_status', 'osmfvc'));

    parent::run();
  }

}
