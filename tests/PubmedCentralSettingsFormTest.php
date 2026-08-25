<?php

/**
 * @file tests/PubmedCentralSettingsFormTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Unit tests for the PubMed Central plugin settings form.
 */

namespace APP\plugins\generic\pubmedCentral\tests;

use APP\plugins\generic\pubmedCentral\classes\form\PubmedCentralSettingsForm;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\tests\PKPTestCase;

#[CoversClass(PubmedCentralSettingsForm::class)]
class PubmedCentralSettingsFormTest extends PKPTestCase
{
    /**
     * The setting types accepted by DAO::convertToDB(), which is what
     * Plugin::updateSetting() ultimately hands the declared type to.
     */
    private const SETTING_TYPES = [
        'bool', 'boolean', 'int', 'integer', 'float', 'number',
        'object', 'array', 'date', 'string',
    ];

    private function createForm(): PubmedCentralSettingsForm
    {
        return $this->getMockBuilder(PubmedCentralSettingsForm::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
    }

    /**
     * isOptional() returns false for anything it does not list, so a field added
     * to getFormFields() and forgotten there silently becomes required. The NLM
     * title abbreviation is the only setting that should be: it is what
     * PubObjectsExportPlugin::display() checks to decide whether to raise
     * EXPORT_CONFIG_ERROR_SETTINGS and hide the export tab, and it is also the
     * leading part of every generated package and file name.
     */
    public function testNlmTitleIsTheOnlyRequiredSetting(): void
    {
        $form = $this->createForm();

        $required = array_values(array_filter(
            array_keys($form->getFormFields()),
            fn (string $fieldName) => !$form->isOptional($fieldName)
        ));

        $this->assertSame(['nlmTitle'], $required);
    }

    /**
     * execute() passes each declared type straight to Plugin::updateSetting(). A
     * type DAO::convertToDB() does not recognise is stored as a serialised string
     * rather than failing, so a typo here would only surface as a corrupt setting.
     */
    public function testEveryFieldDeclaresAStorableType(): void
    {
        $form = $this->createForm();

        foreach ($form->getFormFields() as $fieldName => $fieldType) {
            $this->assertContains(
                $fieldType,
                self::SETTING_TYPES,
                "Setting '{$fieldName}' declares an unsupported type '{$fieldType}'"
            );
        }
    }
}
