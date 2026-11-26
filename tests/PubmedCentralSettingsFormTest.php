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
use APP\plugins\generic\pubmedCentral\PubmedCentralExportPlugin;
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

    private const HOST_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAICqQGxvlHkmK4AuiP4zU7sstYcbsmJKw33GNEEbu/gCl';
    private const HOST_KEY_SHA256 = 'SHA256:UnuSuf27CkGdsMtclRwXM+Kwlfxm3tq7c8x2OcD6rZM';
    private const OTHER_SHA256 = 'SHA256:8iF492S8TEsjOYv4CBvy72Xuwj7GOzTD9mP3+RPPwd4';

    /**
     * Save the form with the given data, for a plugin whose settings are held in memory.
     *
     * @param array $settings Plugin setting name => value, updated with what the form saves
     */
    private function saveForm(array &$settings, array $data): void
    {
        $plugin = $this->getMockBuilder(PubmedCentralExportPlugin::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSetting', 'updateSetting', 'getTemplateResource'])
            ->getMock();
        $plugin->method('getSetting')->willReturnCallback(function ($contextId, $name) use (&$settings) {
            return $settings[$name] ?? null;
        });
        $plugin->method('updateSetting')->willReturnCallback(function ($contextId, $name, $value) use (&$settings) {
            $settings[$name] = $value;
        });
        $plugin->method('getTemplateResource')->willReturn('settingsForm.tpl');

        $form = new PubmedCentralSettingsForm($plugin, 1);
        foreach ($data as $name => $value) {
            $form->setData($name, $value);
        }
        $form->execute();
    }

    /**
     * A fingerprint the recorded key does not match is how a changed key is entered: the
     * recorded key is forgotten, and the next connection must match the fingerprint.
     */
    public function testFingerprintContradictingTheRecordedKeyReplacesIt(): void
    {
        $settings = ['host' => 'sftp.example.org', 'hostKeys' => ['sftp.example.org:22' => self::HOST_KEY]];

        $this->saveForm($settings, ['host' => 'sftp.example.org', 'hostKeyFingerprint' => self::OTHER_SHA256]);

        $this->assertSame([], $settings['hostKeys']);
        $this->assertSame(self::OTHER_SHA256, $settings['hostKeyFingerprint']);
    }

    public function testFingerprintMatchingTheRecordedKeyKeepsIt(): void
    {
        $settings = ['host' => 'sftp.example.org', 'hostKeys' => ['sftp.example.org:22' => self::HOST_KEY]];

        $this->saveForm($settings, ['host' => 'sftp.example.org', 'hostKeyFingerprint' => self::HOST_KEY_SHA256]);

        $this->assertSame(['sftp.example.org:22' => self::HOST_KEY], $settings['hostKeys']);
    }

    /**
     * A fingerprint is for one host, so changing the host leaves it behind, along with
     * the key recorded for the old host, which still applies should the journal return to it.
     */
    public function testChangingTheHostClearsTheFingerprint(): void
    {
        $settings = [
            'host' => 'test.example.org',
            'hostKeyFingerprint' => self::HOST_KEY_SHA256,
            'hostKeys' => ['test.example.org:22' => self::HOST_KEY],
        ];

        $this->saveForm($settings, ['host' => 'sftp.example.org', 'hostKeyFingerprint' => self::HOST_KEY_SHA256]);

        $this->assertSame('', $settings['hostKeyFingerprint']);
        $this->assertSame(['test.example.org:22' => self::HOST_KEY], $settings['hostKeys']);
    }

    /**
     * Build the form for a plugin with the given saved settings.
     */
    private function createFormWithSettings(array $settings, array $data = []): PubmedCentralSettingsForm
    {
        $plugin = $this->getMockBuilder(PubmedCentralExportPlugin::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSetting', 'getTemplateResource'])
            ->getMock();
        $plugin->method('getSetting')->willReturnCallback(fn ($contextId, $name) => $settings[$name] ?? null);
        $plugin->method('getTemplateResource')->willReturn('settingsForm.tpl');

        $form = new PubmedCentralSettingsForm($plugin, 1);
        foreach ($data as $name => $value) {
            $form->setData($name, $value);
        }
        return $form;
    }

    private function invokeForm(PubmedCentralSettingsForm $form, string $method): mixed
    {
        return (new \ReflectionMethod($form, $method))->invoke($form);
    }

    /**
     * The saved password is never sent back to the browser.
     */
    public function testInitDataLeavesThePasswordBlank(): void
    {
        $form = $this->createFormWithSettings(['host' => 'sftp.example.org', 'username' => 'user', 'password' => 'secret']);

        $form->initData();

        $this->assertSame('', $form->getData('password'));
        $this->assertSame('user', $form->getData('username'));
    }

    public function testBlankPasswordKeepsTheSavedOne(): void
    {
        $settings = ['host' => 'sftp.example.org', 'username' => 'user', 'password' => 'secret'];

        $this->saveForm($settings, ['host' => 'sftp.example.org', 'username' => 'other', 'password' => '']);

        $this->assertSame('secret', $settings['password']);
        $this->assertSame('other', $settings['username']);
    }

    public function testEnteredPasswordReplacesTheSavedOne(): void
    {
        $settings = ['host' => 'sftp.example.org', 'username' => 'user', 'password' => 'secret'];

        $this->saveForm($settings, ['host' => 'sftp.example.org', 'username' => 'user', 'password' => 'new secret']);

        $this->assertSame('new secret', $settings['password']);
    }

    /**
     * Clearing the host and username removes the account, password included.
     */
    public function testRemovingTheAccountClearsThePassword(): void
    {
        $settings = ['host' => 'sftp.example.org', 'username' => 'user', 'password' => 'secret'];

        $this->saveForm($settings, ['host' => '', 'username' => '', 'password' => '']);

        $this->assertSame('', $settings['password']);
    }

    /**
     * A saved password counts towards a complete account, so the account need not be re-entered to be saved.
     */
    public function testSavedPasswordCountsTowardsACompleteAccount(): void
    {
        $form = $this->createFormWithSettings(
            ['host' => 'sftp.example.org', 'password' => 'secret'],
            ['host' => 'sftp.example.org', 'username' => 'user', 'password' => '']
        );

        $this->assertSame(true, $this->invokeForm($form, 'getAccountData')['password']);
    }

    /**
     * Keeping the saved password while pointing the account at another server would send
     * the password there, so a new host or port needs the password entered again.
     */
    public function testChangingTheHostNeedsThePasswordAgain(): void
    {
        $saved = ['host' => 'sftp.example.org', 'port' => '', 'password' => 'secret'];

        $this->assertTrue($this->invokeForm(
            $this->createFormWithSettings($saved, ['host' => 'attacker.example.com', 'password' => '']),
            'keepsSavedPasswordForAnotherHost'
        ));
        $this->assertTrue($this->invokeForm(
            $this->createFormWithSettings($saved, ['host' => 'sftp.example.org', 'port' => '2222', 'password' => '']),
            'keepsSavedPasswordForAnotherHost'
        ));
        $this->assertFalse($this->invokeForm(
            $this->createFormWithSettings($saved, ['host' => 'SFTP.example.org', 'port' => '22', 'password' => '']),
            'keepsSavedPasswordForAnotherHost'
        ));
        $this->assertFalse($this->invokeForm(
            $this->createFormWithSettings($saved, ['host' => 'attacker.example.com', 'password' => 'entered again']),
            'keepsSavedPasswordForAnotherHost'
        ));
    }

    /**
     * A required check on the password would have the browser refuse to submit the form
     * with the password left blank, which is how the saved password is kept.
     */
    public function testPasswordIsNotRequiredInTheBrowser(): void
    {
        $form = $this->createFormWithSettings(['password' => 'secret']);

        $this->assertNotContains('required', $form->cssValidation['password'] ?? []);
    }
}
