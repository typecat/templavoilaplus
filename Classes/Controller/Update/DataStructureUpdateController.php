<?php
namespace Ppi\TemplaVoilaPlus\Controller\Update;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

use Ppi\TemplaVoilaPlus\Domain\Repository\DataStructureRepository;

/**
 * Controller to migrate/update the DataStructure
 * @TODO We need more migrations, see TcaMigration in TYPO3 Core
 *
 * @author Alexander Opitz <opitz.alexander@pluspol-interactive.de>
 */
class DataStructureUpdateController extends StepUpdateController
{
    protected $errors = [];
    protected $lastKey = '';

    protected function stepStart()
    {
        $dsRepo = GeneralUtility::makeInstance(DataStructureRepository::class);
        $this->fluid->assignMultiple([
            'dataStructures' => $dsRepo->getAll(),
        ]);
    }

    protected function stepFinal()
    {
        $handler = GeneralUtility::makeInstance(DataStructureUpdateHandler::class);
        $count = $handler->updateAllDs(
            [],
            [
                [$this, 'fixWizardScript'],
                [$this, 'migrateT3editorWizardToRenderTypeT3editor'],
                [$this, 'cleanupEmptyWizardFields'],
            ]
        );

        $this->fluid->assignMultiple([
            'count' => $count,
            'errors' => $this->errors,
        ]);
    }

    public function fixWizardScript(array &$element)
    {
        $changed = false;

        if (isset($element['TCEforms']['config']['wizards']) // if wizards is set
            && is_array($element['TCEforms']['config']['wizards']) // and there are wizards
        ) {
            foreach ($element['TCEforms']['config']['wizards'] as &$wizardConfig) {
                $cleaned = false;

                // Convert ['script'] to ['module']['name']
                if (isset($wizardConfig['script'])) {
                    if (!isset($wizardConfig['module']['name'])) {
                        if (StringUtility::beginsWith($wizardConfig['script'], 'browse_links.php')) {
                            $cleaned = true;
                            $wizardConfig['module']['name'] = 'wizard_link';
                        } else {
                            $this->errors[] = 'Cannot fix wizard script: ' . $wizardConfig['script'] . ' Key: ' . $this->lastKey;
                        }
                    } else {
                        $cleaned = true;
                    }

                    if ($cleaned) {
                        unset($wizardConfig['script']);
                    }
                }

                // Convert ['module']['name'] = 'wizard_element_browser'
                // && ['module']['urlParameters']['mode'] = 'wizard'
                // to ['module']['name'] = 'wizard_link'
                if (isset($wizardConfig['module']['name'])
                    && $wizardConfig['module']['name'] === 'wizard_element_browser'
                    && isset($wizardConfig['module']['urlParameters']['mode'])
                    && $wizardConfig['module']['urlParameters']['mode'] === 'wizard'
                ) {
                    $wizardConfig['module']['name'] = 'wizard_link';
                    unset ($wizardConfig['module']['urlParameters']['mode']);
                    if (empty($wizardConfig['module']['urlParameters'])) {
                        unset($wizardConfig['module']['urlParameters']);
                    }
                    $cleaned = true;
                }

                $changed = $changed || $cleaned;
            }
        }
        return $changed;
    }

    /**
     * Migrate type=text field with t3editor wizard to renderType=t3editor without this wizard
     * From TYPO3\CMS\Core\Migrations\TcaMigration::migrateT3editorWizardToRenderTypeT3editorIfNotEnabledByTypeConfig
     */
    public function migrateT3editorWizardToRenderTypeT3editor(array &$element)
    {
        $changed = false;

        if (isset($element['TCEforms']['config']['wizards']) // if wizards is set
            && is_array($element['TCEforms']['config']['wizards']) // and there are wizards
        ) {
            foreach ($element['TCEforms']['config']['wizards'] as $wizardName => &$wizardConfig) {
                $cleaned = false;

                if (
                    !empty($wizardConfig['userFunc']) // a userFunc is defined
                    && trim($wizardConfig['userFunc']) === 'TYPO3\\CMS\\T3editor\\FormWizard->main' // and set to FormWizard
                    && (
                        !isset($wizardConfig['enableByTypeConfig']) // and enableByTypeConfig is not set
                        || (isset($wizardConfig['enableByTypeConfig']) && !$wizardConfig['enableByTypeConfig'])  // or set, but not enabled
                    )
                ) {
                    // Set renderType from text to t3editor
                    $element['TCEforms']['config']['renderType'] = 't3editor';
                    // Move format parameter
                    if (!empty($wizardConfig['params']['format'])) {
                        $element['TCEforms']['config']['format'] = $wizardConfig['params']['format'];
                    }
                    // Unset this wizard definition
                    unset($element['TCEforms']['config']['wizards'][$wizardName]);
                }

                $changed = $changed || $cleaned;
            }
        }

        return $changed;
    }

    /**
     * removes wizard element, if empty
     */
    public function cleanupEmptyWizardFields(array &$element)
    {
        $changed = false;

        if (isset($element['TCEforms']['config']['wizards']) // if wizards is set
            && (
                ! is_array($element['TCEforms']['config']['wizards']) // and there is no wizard array
                || empty($element['TCEforms']['config']['wizards'])  // or it is empty
            )
        ) {
            unset($element['TCEforms']['config']['wizards']);
            $changed = true;
        }

        return $changed;
    }
}
