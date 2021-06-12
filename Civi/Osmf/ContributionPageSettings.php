<?php

namespace Civi\Osmf;

use CRM_OsmfVerifyContributor_ExtensionUtil as E;

class ContributionPageSettings {

  public static function tabset($tabsetName, &$tabs, $context) {
    if ($tabsetName !== 'civicrm/admin/contribute') {
      return;
    }

    if (!empty($context['contribution_page_id'])) {
      $tab = ['osmfcontributionpagesettings' => self::settingsTabForTabHeader($context)];
    }
    elseif (!empty($context['urlString']) && !empty($context['urlParams'])) {
      $index = intval('11111111111111', 2);
      $tab = [$index => self::settingsTabForActionLinks($context)];
    }

    if (isset($tab)) {
      $tabs =
        array_slice($tabs, 0, 4, TRUE)
        + $tab
        + array_slice($tabs, 4, NULL, TRUE);
    }
  }

  public static function settingsTabForTabHeader($context): array {
    $contribID = $context['contribution_page_id'];
    $url = \CRM_Utils_System::url(
      'civicrm/admin/contribute/osmfcontributionpagesettings',
      "reset=1&snippet=5&force=1&id=$contribID&action=update&component=contribution");
    $tab = [
      'title' => E::ts('OSMF Overrides'),
      'link' => $url,
      'valid' => 1,
      'active' => 1,
      'current' => FALSE,
    ];
    return $tab;
  }

  public static function settingsTabForActionLinks($context): array {
    $title = E::ts('OSMF Overrides');
    $tab = [
      'title' => $title,
      'name' => $title,
      'url' => 'civicrm/admin/contribute/osmfcontributionpagesettings',
      'qs' => $context['urlParams'],
      'uniqueName' => 'osmfcontributionpagesettings',
    ];
    return $tab;
  }

}
