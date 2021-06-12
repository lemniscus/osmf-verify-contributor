<?php

namespace Civi\Osmf;

use CRM_OsmfVerifyContributor_ExtensionUtil as E;

class ContributionPage {

  public static function preProcess(\Civi\Core\Event\GenericHookEvent $e) {
    if ($e->formName === 'CRM_Contribute_Form_Contribution_Main') {
      $e->form->setVar('_paymentProcessors', (array) $e->form->getVar('_paymentProcessors'));
    }

    if ($e->formName === 'CRM_Contribute_Form_Contribution_ThankYou') {
      self::processThankYouPageTokens($e->formName, $e->form);
    }
  }

  public static function processThankYouPageTokens(
    string $formName,
    \CRM_Contribute_Form_Contribution_ThankYou $form): void {

    \Civi::dispatcher()->addListener('civi.token.list', [
      '\Civi\Osmf\TemplateToken',
      'register_tokens',
    ]);
    \Civi::dispatcher()->addListener('civi.token.eval', [
      '\Civi\Osmf\TemplateToken',
      'evaluate_tokens',
    ]);

    $tokenProcessor = new \Civi\Token\TokenProcessor(\Civi::dispatcher(), [
      'controller' => $formName,
      'smarty' => FALSE,
    ]);
    $tokenProcessor->addMessage(
      'thankyou_text',
      $form->_values['thankyou_text'],
      'text/plain'
    );

    $tokenProcessor->addRow()
      ->context('contact_id', $form->get('contactID'));
    $tokenRow = $tokenProcessor->evaluate()->getRow(0);
    $form->assign('thankyou_text', $tokenRow->render('thankyou_text'));
  }

  public static function alterTemplateFile($formName, &$form, $context, &$tplName) {
    if ($formName !== 'CRM_Contribute_Form_Contribution_ThankYou') {
      return;
    }

    $pageId = $form->_id;
    $alterThisPage = \Civi::settings()->get(E::SHORT_NAME . ':'
        . 'contribution_page:' . $pageId . ':only_thankyou_message') ?? FALSE;
    if ($alterThisPage) {
      $tplName = 'CRM/Contribute/Form/Contribution/SimpleThankYou.tpl';
    }
  }

}
