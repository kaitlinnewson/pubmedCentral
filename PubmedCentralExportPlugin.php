<?php

/**
 * @file PubmedCentralExportPlugin.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PubmedCentralExportPlugin
 * @brief Pubmed Central export plugin
 */

namespace APP\plugins\generic\pubmedCentral;

use APP\facades\Repo;
use APP\journal\Journal;
use APP\notification\NotificationManager;
use APP\plugins\generic\pubmedCentral\classes\form\PubmedCentralSettingsForm;
use APP\plugins\PubObjectsExportPlugin;
use APP\publication\Publication;
use APP\submission\Submission;
use APP\template\TemplateManager;
use Exception;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use PKP\context\Context;
use PKP\core\JSONMessage;
use PKP\db\DAORegistry;
use PKP\file\FileManager;
use PKP\notification\Notification;
use PKP\plugins\interfaces\HasTaskScheduler;
use PKP\scheduledTask\PKPScheduler;
use PKP\submission\GenreDAO;
use ZipArchive;

class PubmedCentralExportPlugin extends PubObjectsExportPlugin implements HasTaskScheduler
{
    private Context $context;

    /**
     * @copydoc ImportExportPlugin::display()
     * @throws Exception
     */
    public function display($args, $request): void
    {
        $this->context = $request->getContext();
        parent::display($args, $request);
        $templateManager = TemplateManager::getManager();
        $templateManager->assign([
            'ftpLibraryMissing' => !class_exists('\League\Flysystem\Ftp\FtpAdapter'),
            'issn' => ($this->context->getData('onlineIssn') || $this->context->getData('printIssn')),
        ]);

        switch (array_shift($args)) {
            case 'index':
            case '':
                $templateMgr = TemplateManager::getManager($request);
                $templateMgr->display($this->getTemplateResource('index.tpl'));
                break;
        }
    }

    /**
     * Create a filename for files created in the plugin.
     */
    private function buildFileName(string $articleId, bool $ts = false, ?string $fileExtension = null): string
    {
        // @todo add setting to select vol/issue naming vs. continuous pub naming?
        $locale = $this->context->getData('primaryLocale');
        $acronym = $this->nlmTitle($this->context, true) ?? $this->context->getData('acronym', $locale);
        $timeStamp = date('YmdHis');
        error_log('time stamp: ' . $timeStamp);
        return $acronym . '-' . $articleId .
               ($ts ? '-' . $timeStamp : '') .
               ($fileExtension ? '.' . $fileExtension : '');
    }

    /**
     * Create a filename for the exported zip file when downloading.
     */
    private function buildZipFilename(): string
    {
        $locale = $this->context->getData('primaryLocale');
        $acronym = $this->nlmTitle($this->context, true) ?? $this->context->getData('acronym', $locale);
        return $acronym . '-' . date('YmdHis') . '.zip';
    }

    /**
     * @throws FilesystemException
     * @throws Exception
     */
    public function executeExportAction(
        $request,
        $objects,
        $filter,
        $tab,
        $objectsFileNamePart,
        $noValidation = null,
        $shouldRedirect = true
    ) {
        // OLD CODE FROM PORTICO
//        $templateManager = TemplateManager::getManager();
//        try {
//            // create zip file
//            $path = $this->createZipForIssues($objects);
//            try {
//                if ($request->getUserVar('type') == 'ftp') {
//                    $this->depositXml($objects, $this->context, $path);
//                    $templateManager->assign('pmcSuccessMessage', __('plugins.importexport.pmc.export.success'));
//                } else {
//                    $this->download($path);
//                }
//            } finally {
//                unlink($path);
//            }
//        } catch (Exception $e) {
//            $templateManager->assign('pmcErrorMessage', $e->getMessage());
//        }

        $context = $request->getContext();
        if ($request->getUserVar(PubObjectsExportPlugin::EXPORT_ACTION_DEPOSIT)) {
            $resultErrors = [];
            $paths = $this->createZip($objects, $context);
            $result = $this->depositXML($objects, $context, $paths);
            if (is_array($result)) {
                $resultErrors[] = $result;
            }
            // send notifications
            if (empty($resultErrors)) {
                $this->_sendNotification(
                    $request->getUser(),
                    $this->getDepositSuccessNotificationMessageKey(),
                    Notification::NOTIFICATION_TYPE_SUCCESS
                );
            } else {
                foreach ($resultErrors as $errors) {
                    foreach ($errors as $error) {
                        if (!is_array($error) || !count($error) > 0) {
                            throw new Exception('Invalid error message');
                        }
                        $this->_sendNotification(
                            $request->getUser(),
                            $error[0],
                            Notification::NOTIFICATION_TYPE_ERROR,
                            ($error[1] ?? null)
                        );
                    }
                }
            }
            foreach ($paths as $path) {
                unlink($path);
            }
            // Redirect back to the right tab
            $request->redirect(null, null, null, ['plugin', $this->getName()], null, $tab);
        } elseif ($request->getUserVar(PubObjectsExportPlugin::EXPORT_ACTION_EXPORT)) {
            $path = $this->createZipCollection($objects, $context);
            $fileManager = new FileManager();
            $fileManager->downloadByPath($path, 'application/zip', false, $this->buildZipFilename());
            $fileManager->deleteByPath($path);
        } else {
            parent::executeExportAction(
                $request,
                $objects,
                $filter,
                $tab,
                $objectsFileNamePart,
                $noValidation,
                $shouldRedirect
            );
        }
    }

    /**
     * Get the XML for selected objects.
     *
     * @param Submission $object single published submission, publication, issue or galley
     * @param string $filter
     * @param Journal $context
     * @param bool $noValidation If set to true no XML validation will be done
     * @param null|mixed $outputErrors
     *
     * @return string|array XML document or error message.
     * @throws Exception
     */
    public function exportXML($object, $filter, $context, $noValidation = null, &$outputErrors = null)
    {
        libxml_use_internal_errors(true); // @todo remove?

        $publication = $object instanceof Publication ? $object : $object->getCurrentPublication();
        $submissionId = $object instanceof Publication ? $object->getData('submissionId') : $object->getId();

        // @todo probably need to update for genredao refactor
        $genreDao = DAORegistry::getDAO('GenreDAO'); /** @var GenreDAO $genreDao */
        $genres = $genreDao->getEnabledByContextId($this->context->getId());

        $document = Repo::jats()
            ->getJatsFile($publication->getId(), $submissionId, $genres->toArray());

        // If this setting is enabled, only export user-uploaded JATS files and
        // do not generate our own JATS.
        $jatsImportedOnly = $this->jatsImportedOnly($this->context);

        // Check if the JATS file was found and that it was not generated.
        if (
            !$document ||
            !$document->jatsContent ||
            ($jatsImportedOnly == $document->isDefaultContent)
        ) {
            error_log("No suitable JATS XML file was found for export.");
            $outputErrors[] = __('plugins.importexport.pmc.export.failure.creatingFile');
        }

        $xml = $document->jatsContent;

        // @todo add nlm title to the JATS if set if we are generating it, e.g.
        // <abbrev-journal-title abbrev-type="nlm-ta">Proc Natl Acad Sci USA</abbrev-journal-title>
        // AND
        // <journal-id journal-id-type="pmc">BMJ</journal-id>

        $errors = array_filter(libxml_get_errors(), function ($a) {
            return $a->level == LIBXML_ERR_ERROR || $a->level == LIBXML_ERR_FATAL;
        });
        if (!empty($errors)) {
            if ($outputErrors === null) {
                $this->displayXMLValidationErrors($errors, $xml);
            } else {
                $outputErrors[] = $errors;
            }
        }
        return $xml;
    }

    /**
     * Get deposit status setting name.
     *
     * @return string
     */
    public function getDepositStatusSettingName(): string
    {
        return $this->getPluginSettingsPrefix() . '::status';
    }

    /**
     * Get the plugin ID used as plugin settings prefix.
     *
     * @return string
     */
    public function getPluginSettingsPrefix(): string
    {
        return 'pubmedCentral';
    }

    /**
     * Get a list of additional setting names that should be stored with the objects.
     */
    public function getObjectAdditionalSettings(): array
    {
        // @todo maybe add last export date?
        return array_merge(parent::getObjectAdditionalSettings(), [
            $this->getDepositStatusSettingName()
        ]);
    }

    /**
     * Get the deposit endpoint details.
     */
    public function getEndpoint(int $contextId): array
    {
        error_log('getEndpoint: ' . print_r($this->getSetting($contextId, 'endpoint'), true));
        return (array) $this->getSetting($contextId, 'endpoint');
    }


    /**
     * Get the JATS import setting value.
     */
    public function jatsImportedOnly(context $context): bool
    {
        return ($this->getSetting($context->getId(), 'jatsImported') == 1);
    }

    /**
     * Get the NLM title setting.
     *
     * @param bool $forName If we need to use this string for a filename.
     */
    public function nlmTitle(context $context, bool $forName = false): string
    {
        if ($forName) {
            return strtolower(
                str_replace(' ', '', $this->getSetting($context->getId(), 'nlmTitle'))
            );
        }
        return ($this->getSetting($context->getId(), 'nlmTitle'));
    }

    /**
     * Exports a zip file with the selected issues to the configured PMC account.
     *
     * @param array $filenames the path(s) of the zip file(s)
     * @throws Exception|FilesystemException
     */
    public function depositXml($objects, $context, $filenames): bool|array
    {
        $endpoints = $this->getEndpoint($context->getId());

        // Verify that the credentials are complete
        foreach ($endpoints as $credentials) {
            if (empty($credentials['type']) || empty($credentials['hostname'])) {
                return [['plugins.importexport.pmc.export.failure.settings']];
            }
        }

        // Perform the deposit
        foreach ($endpoints as $credentials) {
            $adapter = match ($credentials['type']) {
                'ftp' => new FtpAdapter(FtpConnectionOptions::fromArray([
                    'host' => $credentials['hostname'],
                    'port' => ((int)$credentials['port'] ?? null) ?: 21,
                    'username' => $credentials['username'],
                    'password' => $credentials['password'],
                    'root' => $credentials['path'],
                ])),
                'sftp' => new SftpAdapter(
                    new SftpConnectionProvider(
                        host: $credentials['hostname'],
                        username: $credentials['username'],
                        password: $credentials['password'],
                        port: ((int)$credentials['port'] ?? null) ?: 22,
                    ),
                    $credentials['path'] ?? '/',
                    PortableVisibilityConverter::fromArray([
                        'file' => [
                            'public' => 0640,
                            'private' => 0604,
                        ],
                        'dir' => [
                            'public' => 0740,
                            'private' => 7604,
                        ],
                    ])
                ),
                default => throw new Exception('Unknown endpoint type!'), // @todo return error
            };

            foreach ($filenames as $filename => $path) {
                $fs = new Filesystem($adapter);
                $fp = fopen($path, 'r');
                $fs->writeStream($this->buildZipFilename(), $fp);
                fclose($fp);
            }
        }
        return true;
    }

    /**
     * Creates a zip file with the given publications.
     *
     * @return array the paths of the created zip files
     * @throws Exception
     */
    public function createZip(array $objects, Context $context): array
    {
        // @todo replace with filemanager?
        $paths = [];
        try {
            foreach ($objects as $object) {
                if ($object instanceof Submission) {
                    $publication = $object->getCurrentPublication();
                } elseif ($object instanceof Publication) {
                    $publication = $object;
                } else {
                    throw new Exception('Invalid object type');
                }

                $path = tempnam(sys_get_temp_dir(), 'tmp');
                $zip = new ZipArchive();
                $pubId = $publication->getId();
                if ($zip->open($path, ZipArchive::CREATE) !== true) {
                    error_log('Unable to create PMC ZIP: ' . $zip->getStatusString()); // @todo integrate into error
                    return [['plugins.importexport.pmc.export.failure.creatingFile']];
                }
                $document = $this->exportXML($object, null, $this->context, null, $errors);
                $filename = $this->buildFileName($pubId);
                $articlePathName = $filename . '/' . $this->buildFileName($pubId, false, 'xml');
                error_log('article path name: ' . print_r($articlePathName, true));

                if (!$zip->addFromString($articlePathName, $document)) {
                    error_log("Unable to add {$articlePathName} to PMC ZIP");
                    return [['plugins.importexport.pmc.export.failure.creatingFile']];
                }

                // add galleys
                $fileService = app()->get('file');
                foreach ($publication->getData('galleys') ?? [] as $galley) {
                    error_log(print_r($galley, true));
                    $submissionFileId = $galley->getData('submissionFileId');
                    $submissionFile = $submissionFileId ? Repo::submissionFile()->get($submissionFileId) : null;
                    if (!$submissionFile) {
                        continue;
                    }

                    // @todo check for filename in the JATS and replace it with the new filename to meet pmc naming requirements

                    $filePath = $fileService->get($submissionFile->getData('fileId'))->path;
                    $extension = pathinfo($filePath, PATHINFO_EXTENSION);
                    $galleyFilename = $filename . '/' . $this->buildFileName($pubId, false, $extension);
                    // @todo should we make sure files meet 2GB max size requirement?
//                    $filesize = $fileService->fs->fileSize($filePath);
//                    if ($filesize > 2147483648) {
//                        error_log('Galley file is too large for PMC');
//                        // @todo
//                    }

                    if (
                        !$zip->addFromString(
                            $galleyFilename,
                            $fileService->fs->read($filePath)
                        )
                    ) {
                        error_log("Unable to add file {$filePath} to PMC ZIP");
                        $errorMessage = ''; //@todo
                        $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $errorMessage);
                        throw new Exception(__('plugins.importexport.pmc.export.failure.creatingFile'));
                    }
                }
                $paths[$filename] = $path;
            }
        } finally {
            if (!$zip->close()) {
                return [['plugins.importexport.pmc.export.failure.creatingFile', $zip->getStatusString()]];
            }
        }
        return $paths;
    }

    /**
     * Creates a zip file of collected publications for download.
     *
     * @throws Exception
     */
    private function createZipCollection(array $objects, Context $context): string|array
    {
        $finalZipPath = tempnam(sys_get_temp_dir(), 'tmp');

        $finalZip = new ZipArchive();
        if ($finalZip->open($finalZipPath, ZipArchive::CREATE) !== true) {
            return [['plugins.importexport.pmc.export.failure.creatingFile', $finalZip->getStatusString()]];
        }

        $paths = $this->createZip($objects, $context);
        foreach ($paths as $filename => $path) {
            if (!$finalZip->addFile($path, $filename . '.zip')) {
                $returnMessage = $finalZip->getStatusString() . '(' . $filename . ')';
                return [[
                    'plugins.importexport.pmc.export.failure.creatingFile',
                    $returnMessage
                ]];
            }
        }
        return $finalZipPath;
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
    {
        if ($request->getUserVar('verb') == 'settings') {
            $user = $request->getUser();
            $this->addLocaleData();
            $form = new PubmedCentralSettingsForm($this, $request->getContext()->getId());

            if ($request->getUserVar('save')) {
                $form->readInputData();
                if ($form->validate()) {
                    $form->execute();
                    $notificationManager = new NotificationManager();
                    $notificationManager->createTrivialNotification($user->getId());
                }
            } else {
                $form->initData();
            }
            return new JSONMessage(true, $form->fetch($request));
        }
        return parent::manage($args, $request);
    }

    /**
     * @copydoc ImportExportPlugin::executeCLI()
     */
    public function executeCLI($scriptName, &$args)
    {
    }

    /**
     * @copydoc ImportExportPlugin::usage()
     */
    public function usage($scriptName)
    {
    }

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        $isRegistered = parent::register($category, $path, $mainContextId);
        $this->addLocaleData();
        return $isRegistered;
    }

    /**
     * @copydoc Plugin::getName()
     */
    public function getName(): string
    {
        return 'PubmedCentralExportPlugin';
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName(): string
    {
        return __('plugins.importexport.pmc.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription(): string
    {
        return __('plugins.importexport.pmc.description.short');
    }

    /**
     * @copydoc PubObjectsExportPlugin::getSettingsFormClassName()
     */
    public function getSettingsFormClassName(): string
    {
        return '\APP\plugins\generic\pubmedCentral\classes\form\PubmedCentralSettingsForm';
    }

    /**
     * @copydoc \PKP\plugins\interfaces\HasTaskScheduler::registerSchedules()
     */
    public function registerSchedules(PKPScheduler $scheduler): void
    {
        $scheduler
            ->addSchedule(new PubmedCentralInfoSender())
            ->daily()
            ->name(PubmedCentralInfoSender::class)
            ->withoutOverlapping();
    }

    /**
     * @copydoc PubObjectsExportPlugin::getExportDeploymentClassName()
     */
    public function getExportDeploymentClassName(): string
    {
        return '\APP\plugins\generic\pubmedCentral\PubmedCentralExportDeployment';
    }

    /**
     * @copydoc PubObjectsExportPlugin::getExportActions()
     */
    public function getExportActions($context): array
    {
        $actions = [PubObjectsExportPlugin::EXPORT_ACTION_EXPORT, PubObjectsExportPlugin::EXPORT_ACTION_MARKREGISTERED];
        error_log(print_r($this->getEndpoint($context->getId()), true));
        if (!empty($this->getEndpoint($context->getId()))) { // @todo fix
            error_log('PMC endpoint not empty');
            array_unshift($actions, PubObjectsExportPlugin::EXPORT_ACTION_DEPOSIT);
        }
        return $actions;
    }
}
