<?php

/**
 * @file VGWortPlugin.php
 *
 * Copyright (c) 2018 Center for Digital Systems (CeDiS), Freie Universität Berlin
 * Copyright (c) 2022 Heidelberg University Library
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE
 *
 * @class VGWortPlugin
 */

namespace APP\plugins\generic\vgwort;

use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\plugins\PluginRegistry;
use PKP\plugins\SubmissionFile;
use PKP\plugins\FieldOptions;

use PKP\db\DAORegistry;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\core\JSONMessage;
use PKP\core\PKPApplication;
use PKP\core\Core;

use APP\plugins\generic\vgwort\classes\form\VgwortForm;
use APP\plugins\generic\vgwort\classes\VgwortEditorAction;
use APP\plugins\generic\vgwort\classes\PixelTagDAO;
use APP\plugins\generic\vgwort\classes\PixelTag;
use APP\plugins\generic\vgwort\controllers\grid\PixelTagGridHandler;

use APP\facades\Repo;
use APP\core\Services;
use APP\core\Application;
use APP\notification\NotificationManager;
use APP\template\TemplateManager;

define('NOTIFICATION_TYPE_VGWORT_ERROR', 0x400000A);

class VgwortPlugin extends GenericPlugin {

    const DATA_FIELDS = [
        'vgWort::texttype',
        'vgWort::pixeltag::status',
        'vgWort::pixeltag::assign',
        'vgWort::pixeltag::remove'
    ];
    
    const CHAPTER_NUMBER = 'chapterNumber';
    
    /**
     * @copydoc GenericPlugin::register()
     */
    public function register($category, $path, $mainContextId = NULL)
    {
        // Register the plugin even when it is not enabled.
        $success = parent::register($category, $path);
        if ($success && $this->getEnabled()) {
            $pixelTagDao = new PixelTagDAO($this->getName());
            $returner = DAORegistry::registerDAO('PixelTagDAO', $pixelTagDao);

            // Extend Schemas and DAOs for some new properties.
            Hook::add('Schema::get::publication', [$this, 'addToSchema']);
            Hook::add('Schema::get::author', [$this, 'addToSchema']);
            Hook::add('Schema::get::user', [$this, 'addToSchema']);
            Hook::add('chapterdao::getAdditionalFieldNames', [$this, 'addAdditionalFieldNames']);
            Hook::add('chapterdao::getLocaleFieldNames', [$this, 'addAdditionalFieldNames']);

            // Add new tabs to the distribution settings and publication workflow form.
            Hook::add('Template::Settings::distribution', [$this, 'addNewTabs']);
            Hook::add('Template::Workflow::Publication', [$this, 'addNewTabs']);

            //
            Hook::add('TemplateManager::display', [$this, 'handleTemplateDisplay']);
            Hook::add('TemplateManager::fetch', [$this, 'handleTemplateFetch']);

            // Create new table that lists ordered pixel tags.
            Hook::add('LoadComponentHandler', [$this, 'setupGridHandler']);

            // Add new field for VG Wort Card Number to the user's and author's form template.
            Hook::add('Common::UserDetails::AdditionalItems', [$this, 'metadataFieldEdit']);
            Hook::add('User::PublicProfile::AdditionalItems', [$this, 'metadataFieldEdit']);

            // Initialize data.
            Hook::add('authorform::initdata', [$this, 'metadataInitData']);
            Hook::add('userdetailsform::initdata', [$this, 'metadataInitData']);

            // Read user's input.
            Hook::add('authorform::readuservars', [$this, 'metadataReadUserVars']);
            Hook::add('userdetailsform::readuservars', [$this, 'metadataReadUserVars']);
            Hook::add('chapterform::readuservars', [$this, 'metadataReadUserVars']);

            // Execute forms.
            Hook::add('authorform::execute', [$this, 'metadataExecute']);
            Hook::add('userdetailsform::execute', [$this, 'metadataExecute']);
            Hook::add('chapterform::execute', [$this, 'handleChapterFormExecute']);
            Hook::add('chapterform::display', [$this, 'handleChapterFormDisplay']);

            // Add validation check for VG Wort Card No. field.
            Hook::add('authorform::Constructor', [$this, 'addCheck']);

            // Add VG Wort pixel to PDF JS Viewer.
            Hook::add('Templates::Common::Footer::PageFooter', [$this, 'insertPixelTagJSViewer']);

            // Assign pixel tag to submission object.
            Hook::add('Publication::edit', [$this, 'handleSubmissionFormExecute']);

            $this->pixelTagStatusLabels = [
                0 => __('plugins.generic.vgwort.pixelTag.status.notassigned'),
                classes\PixelTag::STATUS_REGISTERED_ACTIVE => __('plugins.generic.vgwort.pixelTag.status.registered.active'),
                classes\PixelTag::STATUS_UNREGISTERED_ACTIVE => __('plugins.generic.vgwort.pixelTag.status.unregistered.active'),
                classes\PixelTag::STATUS_REGISTERED_REMOVED => __('plugins.generic.vgwort.pixelTag.status.registered.removed'),
                classes\PixelTag::STATUS_UNREGISTERED_REMOVED => __('plugins.generic.vgwort.pixelTag.status.unregistered.removed')
            ];

        }
        return $success;
    }

    /**
     * Provide a name for this plugin.
     *
     * The name will appear in the Plugin Gallery where editors can
     * install, enable and disable plugins.
     *
     * @return string
     */
    public function getDisplayName()
    {
        return __('plugins.generic.vgwort.displayName');
    }

    /**
     * Provide a description for this plugin.
     *
     * The description will appear in the Plugin Gallery where editors can
     * install, enable and disable plugins.
     *
     * @return string
     */
    public function getDescription()
    {
        return __('plugins.generic.vgwort.description');
    }

    /**
     * Enable the settings form in the site-wide plugins list.
     *
     * @return boolean
     */
    function isSitePlugin()
    {
        return false;
    }

    // TODO: SOAP will no longer be used. Other requirements need to be checked.
    // function requirementsFulfilled()
    // {
    //     $isSoapExtension = in_array('soap', get_loaded_extensions());
    //     $isOpenSSL = in_array('openssl', get_loaded_extensions());
    //     $isCURL = function_exists('curl_init');
    //     return $isSoapExtension && $isOpenSSL && $isCURL;
    // }

    /**
     * Return the canonical template path of this plugin.
     *
     * @param bool $inCore
     */
    function getTemplatePath($inCore = false)
    {
        // TODO: ojsVersion?
        $ojsVersion = Application::getApplication()->getCurrentVersion()->getVersionString();
        return parent::getTemplatePath();
    }

    /**
     * Get installation script
     */
    function getInstallMigration()
    {
        return new VgwortMigration();
    }

    /**
     * Add a settings action to the plugin's entry in the
     * plugins list.
     *
     * @param Request $request
     * @param array $actionArgs
     *
     * @return array
     */
    public function getActions($request, $actionArgs)
    {
        // Get the existing actions
        $actions = parent::getActions($request, $actionArgs);

        // Only add the settings action when the plugin is enabled
        if (!$this->getEnabled()) {
            return $actions;
        }

        // Create a LinkAction that will make a request to the
        // plugin's `manage` method with the `settings` verb.
        $router = $request->getRouter();
        // import('lib.pkp.classes.linkAction.request.AjaxModal');
        $linkAction = new LinkAction(
            'settings',
            new AjaxModal(
                $router->url(
                    $request,
                    NULL,
                    NULL,
                    'manage',
                    NULL,
                    [
                        'verb' => 'settings',
                        'plugin' => $this->getName(),
                        'category' => 'generic'
                    ]
                ),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings'),
            NULL
        );

        // Add the LinkAction to the existing actions.
        // Make it the first action to be consistent with
        // other plugins.
        array_unshift($actions, $linkAction);

        return $actions;
    }

    /**
     * Show and save the settings form when the settings action
     * is clicked.
     *
     * @param array $args
     * @param Request $request
     *
     * @return JSONMessage
     */
    public function manage($args, $request)
    {
        switch ($request->getUserVar('verb')) {

            // Return a JSON response containing the settings form
            case 'settings':
                // Load the custom form
                $contextId = $request->getContext()->getId();
                $settingsForm = new classes\form\VgwortSettingsForm($this, $contextId);

                // Fetch the form the first time it loads, before
                // the user has tried to save it
                if (!$request->getUserVar('save')) {
                    $settingsForm->initData();
                    return new JSONMessage(true, $settingsForm->fetch($request));
                }

                // Validate and save the form data
                $settingsForm->readInputData();
                if ($settingsForm->validate()) {
                    $settingsForm->execute();
                    return new JSONMessage(true);
                }
        }
        return parent::manage($args, $request);
    }

    /**
     * Hook callback to add some pixel tag properties to the
     * publication's, author's and user's schema.
     *
     * @param string $hookName
     * @param array $args
     */
    public function addToSchema($hookName, $args) {
        $schema = $args[0];
        switch ($hookName) {
            case 'Schema::get::publication':
                $schema->properties->{"vgWort::texttype"} = (object) [
                    'type' => 'string',
                ];
                $schema->properties->{"vgWort::pixeltag::assign"} = (object) [
                    'type' => 'boolean',
                    'validation' => ['NULLable']
                ];
                $schema->properties->{"vgWort::pixeltag::remove"} = (object) [
                    'type' => 'boolean',
                    'validation' => ['NULLable']
                ];
                $schema->properties->{"vgWort::pixeltag::status"} = (object) [
                    'type' => 'string',
                ];
                break;
            case 'Schema::get::author':
            case 'Schema::get::user':
                $schema->properties->{"vgWortCardNo"} = (object) [
                    'type' => 'integer'
                ];
                break;
        }
        return false;
    }

    // TODO: Still not possible to use addToSchema() callback
    // for extending chapter properties.
    /**
     * Hook callback to add some pixel tag properties to the
     * chapter's DAO.
     *
     * @param string $hookName
     * @param array $args
     */
    function addAdditionalFieldNames($hookName, $args, &$fields) {
        switch ($hookName) {
            case 'chapterdao::getAdditionalFieldNames':
                error_log("chapterdao::getAdditionalFieldNames");
                $fields[] = 'vgWort::texttype';
                $fields[] = 'vgWort::pixeltag::assign';
                $fields[] = 'vgWort::pixeltag::remove';
                $fields[] = 'vgWort::pixeltag::status';
                error_log("chapterdao::getAdditionalFieldNames");
                error_log("fields: " . var_export($fields,true));
                break;
            case 'chapterdao::getLocaleFieldNames':
                $fields[] = self::CHAPTER_NUMBER;;
                break;
        }
        return false;
    }

    /**
     * Hook callback to add new tabs to the distribution
     * settings and publication workflow form.
     *
     * @param string $hookName
     * @param array $args
     */
    function addNewTabs($hookName, $args)
    {
        switch ($hookName) {
            case 'Template::Settings::distribution':
                $smarty =& $args[1];
                $output =& $args[2];
                $templateFile = method_exists($this, 'getTemplateResource')
                    ? $this->getTemplateResource('distributionNavLink.tpl')
                    : $this->getTemplatePath() . 'distributionNavLink.tpl';
                $output .= $smarty->fetch($templateFile);
                break;
            case 'Template::Workflow::Publication':
                $html =& $args[2];
                //error_log("addNewTabs: " . FORM_VGWORT);
                $html .= '<tab id="vgwortformtab" label="VG Wort">
                    <pkp-form v-bind="components.' . VgwortForm::FORM_VGWORT . '" @set="set" />
                    </tab>';
                break;
        }
        return false;
    }

    /**
     * Hook callback to initialize data in the user and
     * author form when first loaded.
     *
     * @param string $hookName
     * @param array $args
     */
    function metadataInitData($hookName, $args)
    {
        $form =& $args[0];
        $user = NULL;

        switch ($hookName) {
            case 'userdetailsform::initdata':
                error_log("userdetailsform::initdata");
                if (isset($form->userId)) {
                    //$userDao = DAORegistry::getDAO('UserDAO');
                    //$user = $userDao->getById($form->userId);
                    $user = $form->user;
                    error_log("user: " . var_export($user,true));
                }
                break;
            case 'authorform::initdata':
                $user = $form->getAuthor();
                break;
            case 'publicprofileform::initdata':
                $user = $form->getUser();
                break;
        }
        if ($user) {
            error_log("user: vgWortCardNo: " . $user->getData('vgWortCardNo'));
            $form->setData('vgWortCardNo', $user->getData('vgWortCardNo'));
        }
        return false;
    }

    /**
     * Hook callback for adding a new field for VG Wort Card Number
     * to the user's and author's form template.
     *
     * @param string $hookName
     * @param array $args
     */
    function metadataFieldEdit($hookName, $args)
    {
        $smarty =& $args[1];
        $output =& $args[2];

        if ($hookName == 'Common::UserDetails::AdditionalItems') {
            $smarty->assign('vgWortFieldTitle', 'plugins.generic.vgwort.cardNo');
            $userId = $smarty->smarty->tpl_vars['userId']->value;
            if ($userId) {
                $user = Repo::user()->get($userId);
            //$user = $form->user;
            //$smarty->assign('vgWortCardNo', $user->getData('vgWortCardNo'));
            }
        }
        $templateFile = method_exists($this, 'getTemplateResource')
            ? $this->getTemplateResource('vgWortCardNo.tpl')
            : $this->getTemplatePath() . 'vgWortCardNo.tpl';
        $output .= $smarty->fetch($templateFile);
        return false;
    }

    /**
     * Hook callback for reading user's input.
     *
     * @param string $hookName
     * @param array $args
     */
    function metadataReadUserVars($hookName, $args)
    {
        $form =& $args[0];
        $vars =& $args[1];

        switch ($hookName) {
            case 'authorform::readuservars':
                $vars[] = 'vgWortCardNo';
                break;
            case 'userdetailsform::readuservars':
                $vars[] = 'vgWortCardNo';
                error_log("metadataReadUserVars: vars " . var_export($vars,true));
                break;
            case 'chapterform::readuservars':
                $vars = array_merge($vars, self::DATA_FIELDS);
                $vars[] = 'vgWortAssignRemoveCheckbox';
                break;
        }
        return false;
    }

    /**
     * Hook callback to save the form.
     *
     * @param string $hookName
     * @param array $args
     */
    function metadataExecute($hookName, $args)
    {
        $form =& $args[0];
        $user = NULL;

        switch ($hookName) {
            case 'userdetailsform::execute':
                $user = $form->user;
                error_log("form->getData: " . $form->getData('vgWortCardNo'));
                break;
            case 'authorform::execute':
                $user = $form->getAuthor();
                break;
            case 'publicprofileform::execute':
                $user =& $args[2];
                break;
        }
        return false;
    }

    /**
     * Hook callback for extending "Edit Chapter" form.
     *
     * @param string $hookName
     * @param array $args
     */
    public function handleChapterFormDisplay($hookName, $args)
    {
        $request = Application::get()->getRequest();
        try {
            $templateMgr = TemplateManager::getManager($request);
        } catch (Exception $e) {
            return false;
        }

        $vgWortTextTypes = [
            PixelTag::TYPE_TEXT => __('plugins.generic.vgwort.pixelTag.textType.text'),
            PixelTag::TYPE_LYRIC => __('plugins.generic.vgwort.pixelTag.textType.lyric')
        ];
        $chapterForm =& $args[0];
        $chapter = $chapterForm->getChapter();

        if ($chapter) {
            foreach (self::DATA_FIELDS as $field) {
                $chapterForm->setData($field, $chapter->getData($field));
            }
            $pixelTagStatus = $chapter->getData("vgWort::pixeltag::status");
            $chapterForm->setData("vgWortPixeltagStatus", $pixelTagStatus);
            switch ($pixelTagStatus) {
                case PixelTag::STATUS_REGISTERED_ACTIVE:
                    $chapterForm->setData("vgWortAssignRemoveCheckbox", "vgWortAssignPixelTag");
                    break;
                case PixelTag::STATUS_UNREGISTERED_ACTIVE:
                    $chapterForm->setData("vgWortAssignRemoveCheckbox", "vgWortAssignPixelTag");
                    break;
                case PixelTag::STATUS_REGISTERED_REMOVED:
                    $chapterForm->setData("vgWortAssignRemoveCheckbox", "vgWortRemovePixelTag");
                    break;
                case PixelTag::STATUS_UNREGISTERED_REMOVED:
                    $chapterForm->setData("vgWortAssignRemoveCheckbox", "vgWortRemovePixelTag");
                    break;
            }

            $templateMgr->assign([
                'vgWortTextTypes' => $vgWortTextTypes ?: 0,
                'vgWortTextType' => $chapter->getData('vgWort::texttype') ?: 0
            ]);
        }

        try {
            $templateMgr->registerFilter('output', [$this, '_chapterFormFilter']);
        } catch (SmartyException $e) {
            return false;
        }

        return false;
    }

    public function _chapterFormFilter($output, $templateMgr)
    {
        if (preg_match('/<div[\s\S]*id="authors\[\]"/', $output, $matches, PREG_OFFSET_CAPTURE)) {
            $offset = $matches[0][1];
            $newOutput = substr($output, 0, $offset);
            $newOutput .= $templateMgr->fetch($this->getTemplateResource('vgWortChapter.tpl'));
            $newOutput .= substr($output, $offset);
            $output = $newOutput;
            $templateMgr->unregisterFilter('output', [$this, '_chapterFormFilter']);
        }

        return $output;
    }

    /**
     * Hook callback for assigning a pixel tag to a chapter 
     * when executing "Edit Chapter" form.
     *
     * @param string $hookName
     * @param array $args
     */
    public function handleChapterFormExecute($hookName, $args)
    {
        $form =& $args[0];

        $vgWortTextType = $form->getData('vgWort::texttype');
        $pixelTagStatus = $form->getData('vgWort::pixeltag::status');
        $vgWortAssignRemoveCheckbox = $form->getData("vgWortAssignRemoveCheckbox");
        if ($vgWortAssignRemoveCheckbox == NULL) {
            $pixelTagAssigned = false;
            $pixelTagRemoved = false;
        } elseif ($vgWortAssignRemoveCheckbox == "vgWortAssignPixelTag") {
            $pixelTagAssigned = true;
            $pixelTagRemoved = false;
        } elseif ($vgWortAssignRemoveCheckbox == "vgWortRemovePixelTag") {
            $pixelTagRemoved = true;
            $pixelTagAssigned = false;
        }

        // TODO: Do we need this?
        try {
            $chapterDao = DAORegistry::getDAO('ChapterDAO');
        } catch (Exception $e) {
            return false;
        }

        $chapter = $form->getChapter();

        if ($chapter) {
            $publicationId = $chapter->getData('publicationId');
            $publication = Repo::publication()->get($publicationId);
            $submissionId = $publication->getData('submissionId');
            $submission = Repo::submission()->get($submissionId);

            $contextId = $submission->getData('contextId');

            $pixelTagDao = DAORegistry::getDAO('PixelTagDAO');
            $pixelTag = $pixelTagDao->getPixelTagByChapterId($chapter->getId(), $submissionId, $contextId);

            $chapter->setData('vgWort::texttype', $vgWortTextType);
            $chapter->setData('vgWort::pixeltag::assign', $pixelTagAssigned);
            $chapter->setData('vgWort::pixeltag::remove', $pixelTagRemoved);

            $this->pixelExecute($submission, $chapter, $pixelTag);
        }

        $form->setChapter($chapter);

        return false;
    }

    /**
     * Hook callback
     *
     * @param string $hookName
     * @param array $args
     */
    function handleTemplateDisplay($hookName, $args)
    {
        $templateMgr =& $args[0];
        $template =& $args[1];
        $ompVersion = Application::getApplication()->getCurrentVersion()->getVersionString();

        if (strstr($template, "submissionGalley.tpl")) {
            $templateMgr->registerFilter('output', [$this, 'insertPixelTagSubmissionPage']);
            return false;
        }

        switch ($template) {
            case 'frontend/pages/book.tpl':
                $templateMgr->registerFilter('output', [$this, 'insertPixelTagSubmissionPage']);
                break;
            case 'frontend/pages/issue.tpl':
                $templateMgr->registerFilter('output', [$this, 'insertPixelTagIssueTOC']);
                break;
            case 'workflow/workflow.tpl':
                $context = $templateMgr->getTemplateVars('currentContext');
                $submission = $templateMgr->getTemplateVars('submission');

                $request = Application::get()->getRequest();
                $latestPublicationApiUrl = $request->getDispatcher()->url(
                    $request,
                    ROUTE_API,
                    $context->getPath(),
                    'submissions/' . $submission->getId() . '/publications/' . $submission->getLatestPublication()->getId()
                );
                
                error_log("CONST: VgwortForm::FORM_VGWORT " . VgwortForm::FORM_VGWORT);
                //error_log("CONST: FORM_VGWORT " . FORM_VGWORT);
                
                $form = new VgwortForm($latestPublicationApiUrl, [], $context, $submission);
                error_log("FORM: " . var_export($form,true));
                // Use "getState()" (instead of "setState()") to avoid
                // accidentally overwriting additional components on page.
                $components = $templateMgr->getState('components');
                $components[VgwortForm::FORM_VGWORT] = $form->getConfig();
                $templateMgr->setState([
                    'components' => $components,
                ]);

                $templateMgr->addJavaScript(
                    'vgWort-labels',
                    'window.vgWortPixeltagStatusLabels = ' . json_encode($this->pixelTagStatusLabels) . ';',
                    [
                        'inline' => true,
                        'contexts' => 'backend',
                        'priority' => STYLE_SEQUENCE_CORE
                    ]
                );

            $templateMgr->addJavaScript(
                'vgWort',
                Application::get()->getRequest()->getBaseUrl()
                    . DIRECTORY_SEPARATOR
                    . $this->getPluginPath()
                    . DIRECTORY_SEPARATOR . 'js'
                    . DIRECTORY_SEPARATOR . 'vgwort.js',
                [
                    'contexts' => 'backend',
                    'priority' => STYLE_SEQUENCE_LAST
                ]);
            break;
        }
        return false;
    }

    /**
     * Hook callback
     *
     * @param string $hookName
     * @param array $args
     */
    function handleTemplateFetch($hookName, $args)
    {
        $templateMgr =& $args[0];
        $template =& $args[1];

        switch ($template) {
            case 'controllers/tab/workflow/production.tpl':
                $submission = $templateMgr->getTemplateVars('submission');
                $notificationOptions =& $templateMgr->getTemplateVars('productionNotificationRequestOptions');
                $notificationOptions[NOTIFICATION_LEVEL_NORMAL][NOTIFICATION_TYPE_VGWORT_ERROR] = [
                    ASSOC_TYPE_SUBMISSION,
                    $submission->getId()
                ];
                break;
        }
        return false;
    }

    /**
     * Hook callback for validating VG Wort Card No. field (2-7 numbers).
     *
     * @param string $hookName
     * @param array $args
     */
    function addCheck($hookName, $args)
    {
        $form =& $args[0];
        $form->addCheck(new FormValidatorRegExp(
            $form,
            'vgWortCardNo',
            'optional',
            'plugins.generic.vgwort.cardNoValid',
            '/^\d{2,7}$/'
        ));
        return false;
    }

    /**
     * Hook callback for creating a new table in the distribution settings 
     * that lists all pixel tags.
     *
     * @param string $hookName
     * @param array $args
     */
    function setupGridHandler($hookName, $args)
    {
        $component =& $args[0];
        $componentInstance = & $args[2];
        if ($component == 'plugins.generic.vgwort.controllers.grid.PixelTagGridHandler') {
            define('VGWORT_PLUGIN_NAME', $this->getName());
            $componentInstance = new PixelTagGridHandler();
            return true;
        }
        return false;
    }

    /**
     *
     */
    function insertPixelTagSubmissionPage($output, $templateMgr)
    {
        $press = $templateMgr->getTemplateVars('currentContext');
        $monograph = $templateMgr->getTemplateVars('publishedSubmission'); // NICHT "monograph"

        $publicationFormats = $templateMgr->getTemplateVars('publicationFormats');
        $availableFiles = $templateMgr->getTemplateVars('availableFiles');

        $submissionId = $monograph->getId();
        $submission = Repo::submission()->get($submissionId);
        $contextId = $submission->getData('contextId');

        if (isset($submission)) {
            $pixelTagDao = DAORegistry::getDAO('PixelTagDAO');
            $pixelTag = $pixelTagDao->getPixelTagBySubmissionId($submission->getId(), $contextId);
            if (isset($pixelTag) && !$pixelTag->getDateRemoved()) {
                $application = PKPApplication::getApplication();
                $request = $application->getRequest();
                $httpProtocol = $request->getProtocol() == 'https' ? 'https://' : 'http://';
                $pixelTagSrc = $httpProtocol . $pixelTag->getDomain() . '/na/' . $pixelTag->getPublicCode();
                $pixelTagImg = '<img src=\'' . $pixelTagSrc . '\' width=\'1\' height=\'1\' alt=\'\' />';

                if (!empty($publicationFormats)) {
                    $search = '<div class="entry_details">';
                    $replace = $search . '<script>function vgwPixelCall(id) { document.getElementById("div_vgwpixel_"+id).innerHTML="<img src=\'' . $pixelTagSrc . '\' width=\'1\' height=\'1\' alt=\'\' />"; }</script>';
                    $output = str_replace($search, $replace, $output);
                    foreach ($publicationFormats as $publicationFormat) {
                        $submissionFiles = $this->getSubmissionFiles($submission, $publicationFormat);
                        
                        $chapterFiles = $this->getChapterFiles($submissionFiles);
                        error_log("CHAPTER FILES: " . var_export($chapterFiles,true));
                        //die();
                        if ($chapterFiles) {
                            foreach ($chapterFiles as $chapterFile) {
                                [$search, $replace] = $this->createPixelTagURL($request, $submission, $publicationFormat, $chapterFile);
                                $output = preg_replace($search, $replace, $output);
                            }
                        }
                        $bookManuscriptFile = $this->getBookManuscriptFile($submissionFiles);
                        if (!isset($bookManuscriptFile)) { continue; }
                        [$search, $replace] = $this->createPixelTagURL($request, $submission, $publicationFormat, $bookManuscriptFile);
                        // insert pixel tag for galleys download links using VG Wort redirect
                        $output = preg_replace($search, $replace, $output);
                    }
                }
            }
        }
        return $output;
    }

    function createPixelTagURL($request, $submission, $publicationFormat, $file)
    {   
       $publicationFormatUrl = $request->url(
           null,
           'catalog',
           'view',
           [
               $submission->getBestId(),
               $publicationFormat->getId(),
               $file->getId()
           ]
       );
       $search = '#<a (.*)href="' . $publicationFormatUrl . '"(.*)>#';
       // insert pixel tag for galleys download links using JS
       $replace = '<div style="font-size:0;line-height:0;width:0;" id="div_vgwpixel_' . $publicationFormat->getId() . '"></div><a $1 $2 href="' . $publicationFormatUrl . '" onclick="vgwPixelCall(' . $publicationFormat->getId() . ');">';
       return [$search, $replace];
       //// insert pixel tag for galleys download links using VG Wort redirect
       //$output = preg_replace($search, $replace, $output);
    }

    /**
     * Hook callback for adding VG Wort pixel to PDF JS Viewer.
     *
     * @param string $hookName
     * @param array $args
     */
    function insertPixelTagJSViewer($hookName, $args)
    {
        $templateMgr =& $args[1];
        $output =& $args[2];

        $press = $templateMgr->getTemplateVars('currentContext');
        $submission = $templateMgr->getTemplateVars('publishedSubmission');
        $chapter = $templateMgr->getTemplateVars('chapter');
        
        if (isset($press) && !empty($submission)) {
            if (isset($submission) && !empty($submission)) {
                $pixelTagDao = DAORegistry::getDAO('PixelTagDAO');
                if (isset($chapter)) {
                    $pixelTag = $pixelTagDao->getPixelTagByChapterId($chapter->getId(), $submission->getId(), $press->getId());
                }
                // Take pixel tag from submission if chapter does not have its own pixel.
                if (!isset($pixelTag)) {
                    $pixelTag = $pixelTagDao->getPixelTagBySubmissionId($submission->getId(), $press->getId());
                }
                if (isset($pixelTag) && !$pixelTag->getDateRemoved()) {
                    $application = PKPApplication::getApplication();
                    $request = $application->getRequest();
                    $httpsProtocol = $request->getProtocol() == 'https';
                    $output = $this->buildPixelTagHTML($pixelTag, $httpsProtocol);
                }    
            }
        }

        return false;
    }

    /**
     * Build HTML code for the pixel tag.
     *
     * @param PixelTag $pixelTag
     * @param boolean $https
     */
    function buildPixelTagHTML($pixelTag, $https = false)
    {
        $httpProtocol = $https ? 'https://' : 'http://';
        $pixelTagSrc = $httpProtocol . $pixelTag->getDomain() . '/na/' . $pixelTag->getPublicCode();
        return '<img src=\'' . $pixelTagSrc . '\' width=\'1\' height=\'1\' alt=\'\' />';
    }

    /**
     * @param Submission $submission
     * @param Object $pubObject Publication or Chapter
     * @param PixelTag $pixelTag
     */
    function pixelExecute($submission, $pubObject, $pixelTag)
    {
        $vgWortTextType = $pubObject->getData('vgWort::texttype');
        $chapterId = NULL;
        if (isset($pixelTag)) {
            $updatePixelTag = false;
            // pixel tag has been removed, see if it should be assigned again
            if ($pixelTag->getDateRemoved()) {
                $vgWortAssignPixel = $pubObject->getData('vgWort::pixeltag::assign') ? 1 : 0;
                if ($vgWortAssignPixel) {
                    $pixelTag->setDateRemoved(NULL);
                    if ($pixelTag->getStatus() == PixelTag::STATUS_UNREGISTERED_REMOVED) {
                        $pixelTag->setStatus(PixelTag::STATUS_UNREGISTERED_ACTIVE);
                    } elseif ($pixelTag->getStatus() == PixelTag::STATUS_REGISTERED_REMOVED) {
                        $pixelTag->setStatus(PixelTag::STATUS_REGISTERED_ACTIVE);
                    }
                    $updatePixelTag = true;
                }
            } else {
                $removeVGWortPixel = $pubObject->getData('vgWort::pixeltag::assign') ? 0 : 1;
                if ($removeVGWortPixel) {
                    $pixelTag->setDateRemoved(Core::getCurrentDate());
                    if ($pixelTag->getStatus() == PixelTag::STATUS_UNREGISTERED_ACTIVE) {
                        $pixelTag->setStatus(PixelTag::STATUS_UNREGISTERED_REMOVED);
                    } elseif ($pixelTag->getStatus() == PixelTag::STATUS_REGISTERED_ACTIVE) {
                        $pixelTag->setStatus(PixelTag::STATUS_REGISTERED_REMOVED);
                    }
                    $updatePixelTag = true;
                }
            }
            // if text type changed, update
            // TODO: what if the pixel tag is registered
            if ($vgWortTextType != $pixelTag->getTextType()) {
                $pixelTag->setTextType($vgWortTextType);
                $updatePixelTag = true;
            }
            if ($updatePixelTag) {
                $pixelTagDao = DAORegistry::getDAO('PixelTagDAO');
                $pixelTagDao->updateObject($pixelTag);
            }
            $pubObject->setData('vgWort::pixeltag::status', $pixelTag->getStatus());
        } else {
            $vgWortAssignPixel = $pubObject->getData('vgWort::pixeltag::assign') ? 1 : 0;
            if (is_a($pubObject, 'Chapter')) {
                $chapterId = $pubObject->getId();
            }
            if ($vgWortAssignPixel) {
                // assign pixel tag
                if ($this->assignPixelTag($submission, $vgWortTextType, $chapterId)) {
                    $pixelTagStatus = 2; // unregistered, active
                    $pubObject->setData('vgWort::pixeltag::status', $pixelTagStatus);
                }
            }
        }
    }

    /**
     * Hook callback for assigning a pixel tag to a submission
     * when executing submission's form.
     *
     * @param string hookName
     * @param array args
     */
    function handleSubmissionFormExecute($hookName, $args)
    {
        $publication =& $args[0];
        $publicationData = $args[2];

        if (!array_key_exists('vgWort::pixeltag::assign', $publicationData)
            || !array_key_exists('vgWort::texttype', $publicationData) ) {
            // do not execute hook when the vgWort data is not set
            return false;
        }
        $submissionId = $publication->getData('submissionId');
        $submission = Repo::submission()->get($submissionId);

        $contextId = $submission->getData('contextId');
        $pixelTagDao = DAORegistry::getDAO('PixelTagDAO');
        $pixelTag = $pixelTagDao->getPixelTagBySubmissionId($submissionId, $contextId);

        if (is_a($submission, 'Submission')) {
            $this->pixelExecute($submission, $publication, $pixelTag);
        }
        return false;
    }

    /**
     * Assign a pixel tag to a submission.
     *
     * @param Submission $submission
     * @param int $vgWortTextType
     * @return boolean
     */
    function assignPixelTag($submission, $vgWortTextType, $chapterId = NULL)
    {
        $pixelTagDao = DAORegistry::getDAO('PixelTagDAO');
        $contextId = $submission->getContextId();
        // TODO: it seems possible to order just 1 pixel tag
        $availablePixelTag = $pixelTagDao->getAvailablePixelTag($contextId);

        if(!$availablePixelTag) {
            // order pixel tags
            $vgWortEditorAction = new VgwortEditorAction($this);
            $orderResult = $vgWortEditorAction->orderPixel($contextId);
            if (!$orderResult[0]) {
                $application = PKPApplication::getApplication();
                $request = Application::get()->getRequest();
                $user = $request->getUser();
                // Create a form error notification.
                $notificationManager = new NotificationManager();
                $notificationManager->createTrivialNotification(
                    $user->getId(), NOTIFICATION_TYPE_FORM_ERROR, ['contents' => $orderResult[1]]
                );
                return false;
            } else {
                // insert ordered pixel tags in the db
                $vgWortEditorAction->insertOrderedPixel($contextId, $orderResult[1]);
            }
            $availablePixelTag = $pixelTagDao->getAvailablePixelTag($contextId);
        }
        assert($availablePixelTag);
        // there is an available pixel tag --> assign
        $availablePixelTag->setSubmissionId($submission->getId());
        $availablePixelTag->setDateAssigned(Core::getCurrentDate());
        $availablePixelTag->setStatus(PixelTag::STATUS_UNREGISTERED_ACTIVE);
        $availablePixelTag->setTextType($vgWortTextType);
        if ($chapterId) {
            $availablePixelTag->setChapterId($chapterId);
        }
        $pixelTagDao->updateObject($availablePixelTag);
        return true;
    }

    /**
     * Return all supported file types.
     *
     * @param array $fileType
     */
    function getSupportedFileTypes($fileType)
    {
        return ($fileType == 'application/pdf' ||
        $fileType == 'application/epub+zip' ||
        // Added XML support. Check with vgWort if allowed
        $fileType == 'text/xml' ||
        $fileType == 'text/html');
    }

    /**
     * Get all submission files that belong to a given publication format.
     *
     * @param PublicationFormat $publicationFormat
     */
    function getSubmissionFiles($submission, $publicationFormat)
    {
        $submissionFiles = Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([$submission->getId()])
            ->filterByAssoc(
                Application::ASSOC_TYPE_PUBLICATION_FORMAT,
                [$publicationFormat->getId()]
            )
            ->getMany();
        return $submissionFiles;
    }

    /**
     * Get ID of a publication format from submission file.
     *
     * @param SubmissionFile $submissionFile
     */
    function getPublicationFormatId($submissionFile)
    {
        return $submissionFile->getData('assocId');
    }

    /**
     * Get locale from submission file.
     *
     * @param SubmissionFile $submissionFile
     */
    function getLocaleFromSubmissionFile($submissionFile)
    {
        return $submissionFile->getData('locale');
    }

    /**
     * Get submission file of full document.
     *
     * @param array $submissionFiles
     */
    function getBookManuscriptFile($submissionFiles)
    {
        // Get genre ID that corresponds to the book manuscript.
        $genreDao = DAORegistry::getDAO('GenreDAO');
        $genreBook = $genreDao->getByKey('MANUSCRIPT', $this->getCurrentContextId());
        $genreIdBook = $genreBook->getId();
        // TODO: Fehlermeldung, wenn genreId != 104 (=MANUSCRIPT)
        // Return submission file with genre ID from above.
        foreach ($submissionFiles as $submissionFile) {
            $chapterId = $submissionFile->getData('chapterId');
            $mimetype = $submissionFile->getData('mimetype');
            if (isset($chapterId) || $mimetype == "text/xml" || $mimetype == "text/html") { continue; }
            $genreIdSubmissionFile = $submissionFile->getData('genreId');
            return $submissionFile;
        }
        // TODO: What if there are more than one book manuscript components?
    }

    function getChapterFiles($submissionFiles)
    {
        $chapterFiles = [];
        foreach ($submissionFiles as $submissionFile) {
            $chapterId = $submissionFile->getData('chapterId');
            if (!isset($chapterId) || empty($chapterId)) {
                continue;
            } else {
                $chapterFiles[] = $submissionFile;
            }
        }
        return $chapterFiles;
    }
}
