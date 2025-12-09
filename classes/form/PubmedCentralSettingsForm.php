<?php

/**
 * @file PubmedCentralSettingsForm.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PubmedCentralSettingsForm
 *
 * @brief Form for journal managers to modify PubMed Central plugin settings.
 */

namespace APP\plugins\generic\pubmedCentral\classes\form;

use APP\plugins\generic\pubmedCentral\PubmedCentralExportPlugin;
use APP\plugins\PubObjectsExportSettingsForm;
use APP\template\TemplateManager;
use Exception;

class PubmedCentralSettingsForm extends PubObjectsExportSettingsForm
{
    /**
     * Constructor
     */
    public function __construct(private PubmedCentralExportPlugin $plugin, private int $contextId)
    {
        parent::__construct($this->plugin->getTemplateResource('settingsForm.tpl'));
    }

    //
    // Implement template methods from Form.
    //
    /**
     * @copydoc Form::initData()
     */
    public function initData(): void
    {
        $contextId = $this->contextId;
        $plugin = $this->plugin;
        foreach ($this->getFormFields() as $fieldName => $fieldType) {
            $this->setData($fieldName, $plugin->getSetting($contextId, $fieldName));
        }
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData(): void
    {
        $this->readUserVars(array_keys($this->getFormFields()));
    }

    /**
     * @copydata Form::fetch()
     *
     * @param null|mixed $template
     * @throws Exception
     */
    public function fetch($request, $template = null, $display = false)
    {
        // @todo update when we determine what PMC uses
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'endpointTypeOptions' => [
                '' => '',
                'sftp' => 'SFTP',
                'ftp' => 'FTP',
            ]
        ]);
        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs): void
    {
        $plugin = $this->plugin;
        $contextId = $this->contextId;
        parent::execute(...$functionArgs);
        foreach ($this->getFormFields() as $fieldName => $fieldType) {
            if ($fieldName == 'endpoint') {
                // @todo remove if we determine PMC only uses password
                $endpoint = $this->getData('endpoint');
                if (array_key_exists('private_key', $endpoint) && !is_file($endpoint['private_key'])) {
                    throw new Exception('Invalid private key');
                }
            }
            $plugin->updateSetting($contextId, $fieldName, $this->getData($fieldName), $fieldType);
        }
    }

    public function getFormFields(): array
    {
        return [
            'endpoint' => 'array',
            'jatsImported' => 'bool',
            'nlmTitle' => 'string'
        ];
    }

    public function isOptional(string $settingName): bool
    {
        return in_array($settingName, [
            'endpoint',
            'jatsImported',
            'nlmTitle'
        ]);
    }
}
