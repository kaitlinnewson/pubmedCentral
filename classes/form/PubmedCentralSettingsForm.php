<?php

/**
 * @file classes/form/PubmedCentralSettingsForm.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PubmedCentralSettingsForm
 *
 * @brief Form for journal managers to modify PubMed Central plugin settings.
 */

namespace APP\plugins\generic\pubmedCentral\classes\form;

use APP\plugins\generic\pubmedCentral\classes\HostKeyConnectionProvider;
use APP\plugins\generic\pubmedCentral\PubmedCentralExportPlugin;
use APP\plugins\PubObjectsExportSettingsForm;
use APP\template\TemplateManager;
use Exception;
use PKP\form\validation\FormValidator;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorPost;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RemoteActionConfirmationModal;

class PubmedCentralSettingsForm extends PubObjectsExportSettingsForm
{
    /**
     * Constructor
     */
    public function __construct(private readonly PubmedCentralExportPlugin $plugin, private readonly int $contextId)
    {
        parent::__construct($this->plugin->getTemplateResource('settingsForm.tpl'));

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
        // The NLM title abbreviation names every package and file, and is written
        // into the JATS, so the plugin cannot export anything without it.
        $this->addCheck(
            new FormValidator(
                $this,
                'nlmTitle',
                FormValidator::FORM_VALIDATOR_REQUIRED_VALUE,
                'plugins.importexport.pmc.settings.form.nlmTitleRequired'
            )
        );
        // The SFTP account is optional (Export-only use is valid), but partially
        // filling it in is not -- either all of host/username/password, or none.
        // The check is attached to each of the three so that whichever ones were
        // filled in are the ones flagged.
        foreach (['host', 'username', 'password'] as $accountField) {
            $this->addCheck(
                new FormValidatorCustom(
                    $this,
                    $accountField,
                    FormValidator::FORM_VALIDATOR_OPTIONAL_VALUE,
                    'plugins.importexport.pmc.settings.form.accountIncomplete',
                    fn () => $this->plugin->isAccountComplete($this->getAccountData())
                )
            );
        }
        // Leaving the password blank keeps the saved one, but only for the server it was
        // saved for: otherwise changing the host would send it to another server. Attached
        // to the host, as a required check on the password would also stop the browser
        // submitting the form with the password left blank.
        $this->addCheck(
            new FormValidatorCustom(
                $this,
                'host',
                FormValidator::FORM_VALIDATOR_OPTIONAL_VALUE,
                'plugins.importexport.pmc.settings.form.passwordRequiredForNewHost',
                fn () => !$this->keepsSavedPasswordForAnotherHost()
            )
        );
        $this->addCheck(
            new FormValidatorCustom(
                $this,
                'hostKeyFingerprint',
                FormValidator::FORM_VALIDATOR_OPTIONAL_VALUE,
                'plugins.importexport.pmc.settings.form.hostKeyFingerprintInvalid',
                fn (string $fingerprint) => HostKeyConnectionProvider::normalizeFingerprint($fingerprint) !== null
            )
        );
        $this->addCheck(
            new FormValidatorCustom(
                $this,
                'automaticRegistration',
                FormValidator::FORM_VALIDATOR_OPTIONAL_VALUE,
                'plugins.importexport.pmc.settings.form.automaticRegistrationRequiresAccount',
                fn () => $this->plugin->isAccountComplete($this->getAccountData())
            )
        );
    }

    /**
     * The submitted port, or the SFTP default.
     */
    protected function getPort(): int
    {
        return (int) $this->getData('port') ?: 22;
    }

    /**
     * Whether the password box was left blank, which keeps the saved password.
     */
    protected function keepsSavedPassword(): bool
    {
        return (string) $this->getData('password') === ''
            && (string) $this->plugin->getSetting($this->contextId, 'password') !== '';
    }

    /**
     * Whether the saved password would be kept for a host or port other than the one it was saved for.
     */
    protected function keepsSavedPasswordForAnotherHost(): bool
    {
        $host = (string) $this->getData('host');
        $savedHost = (string) $this->plugin->getSetting($this->contextId, 'host');
        $savedPort = (int) $this->plugin->getSetting($this->contextId, 'port') ?: 22;
        return $host !== ''
            && $this->keepsSavedPassword()
            && HostKeyConnectionProvider::hostId($host, $this->getPort()) !== HostKeyConnectionProvider::hostId($savedHost, $savedPort);
    }

    /**
     * The submitted (not yet saved) host/username/password, counting a saved password
     * the blank password box keeps.
     */
    protected function getAccountData(): array
    {
        return [
            'host' => $this->getData('host'),
            'username' => $this->getData('username'),
            'password' => $this->keepsSavedPassword() ? true : $this->getData('password'),
        ];
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
        // Never sent back to the browser: a blank box keeps the saved password
        $this->setData('password', '');
        // Default to volume/issue naming when no value has been saved.
        if (!$this->getData('namingType')) {
            $this->setData('namingType', 'volumeIssue');
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
     *
     * @throws Exception
     */
    public function fetch($request, $template = null, $display = false): ?string
    {
        // Shown for the saved host, which is the one deposits connect to
        $host = (string) $this->plugin->getSetting($this->contextId, 'host');
        $port = (int) $this->plugin->getSetting($this->contextId, 'port') ?: 22;
        $recordedKey = $this->plugin->recordedHostKey($this->contextId, $host, $port);
        TemplateManager::getManager($request)->assign([
            'hostNotPmc' => $host !== '' && !PubmedCentralExportPlugin::isPmcHost($host),
            'passwordSaved' => (string) $this->plugin->getSetting($this->contextId, 'password') !== '',
            'recordedHostKeyFingerprint' => $recordedKey ? HostKeyConnectionProvider::fingerprint($recordedKey) : null,
            'recordedHostKeyHost' => $host,
            'hostKeyScanCommand' => 'ssh-keyscan ' . ($port === 22 ? '' : "-p {$port} ")
                . (preg_match('/^[A-Za-z0-9.:-]+$/', $host) ? $host : escapeshellarg($host)) . ' | ssh-keygen -lf -',
            'clearHostKeyLinkAction' => $recordedKey ? new LinkAction(
                'clearHostKey',
                new RemoteActionConfirmationModal(
                    $request->getSession(),
                    __('plugins.importexport.pmc.settings.form.clearHostKey.confirm'),
                    __('plugins.importexport.pmc.settings.form.clearHostKey'),
                    $request->url(null, null, 'manage', null, [
                        'verb' => 'clearHostKey',
                        'plugin' => $this->plugin->getName(),
                        'category' => 'importexport',
                    ]),
                    'negative'
                ),
                __('plugins.importexport.pmc.settings.form.clearHostKey'),
                'delete'
            ) : null,
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

        // A blank password keeps the saved one, unless the account is being removed
        if ((string) $this->getData('password') === '') {
            $this->setData(
                'password',
                $this->getData('host') || $this->getData('username') ? $plugin->getSetting($contextId, 'password') : ''
            );
        }

        $savedHost = (string) $plugin->getSetting($contextId, 'host');
        $savedPort = (int) $plugin->getSetting($contextId, 'port') ?: 22;
        $hostId = HostKeyConnectionProvider::hostId((string) $this->getData('host'), $this->getPort());
        // A fingerprint is for one host, so it is not carried over to another
        if (
            HostKeyConnectionProvider::hostId($savedHost, $savedPort) !== $hostId
            && $this->getData('hostKeyFingerprint') === $plugin->getSetting($contextId, 'hostKeyFingerprint')
        ) {
            $this->setData('hostKeyFingerprint', '');
        }

        // A fingerprint the recorded key does not match replaces it, as it does when the
        // server's key has changed: the next connection must present a key with that fingerprint
        $fingerprint = HostKeyConnectionProvider::normalizeFingerprint($this->getData('hostKeyFingerprint'));
        $recordedKey = $plugin->recordedHostKey($contextId, $this->getData('host'), $this->getPort());
        if ($fingerprint && $recordedKey && !HostKeyConnectionProvider::matchesFingerprint($recordedKey, $fingerprint)) {
            $plugin->recordHostKey($contextId, $hostId, null);
        }

        foreach ($this->getFormFields() as $fieldName => $fieldType) {
            $plugin->updateSetting($contextId, $fieldName, $this->getData($fieldName), $fieldType);
        }
    }

    public function getFormFields(): array
    {
        return [
            'automaticRegistration' => 'bool',
            'nlmTitle' => 'string',
            'namingType' => 'string',
            'host' => 'string',
            'port' => 'string',
            'path' => 'string',
            'username' => 'string',
            'password' => 'string',
            'hostKeyFingerprint' => 'string',
        ];
    }

    public function isOptional(string $settingName): bool
    {
        return in_array($settingName, [
            'automaticRegistration',
            'namingType',
            'host',
            'port',
            'path',
            'username',
            'password',
            'hostKeyFingerprint',
        ]);
    }
}
