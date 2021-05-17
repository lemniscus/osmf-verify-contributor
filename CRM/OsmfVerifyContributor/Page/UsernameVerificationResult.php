<?php
use CRM_OsmfVerifyContributor_ExtensionUtil as E;

class CRM_OsmfVerifyContributor_Page_UsernameVerificationResult extends CRM_Core_Page {

  public function run() {
    // Example: Set the page-title dynamically; alternatively, declare a static title in xml/Menu/*.xml
    CRM_Utils_System::setTitle(E::ts('OpenStreetMap username verified'));

    // Example: Assign a variable for use in a template
    $this->assign('osm_username', CRM_Core_Session::singleton()->get('osm_username'));

    parent::run();
  }

}
