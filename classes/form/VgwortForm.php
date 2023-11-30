<?php

namespace APP\plugins\generic\vgwort\classes\form;

use APP\plugins\generic\vgwort\VgwortPlugin;
use APP\plugins\generic\vgwort\classes\PixelTag;
use PKP\components\forms\FormComponent;
use PKP\db\DAORegistry;


//define('FORM_VGWORT', 'vgwortform');

class VgwortForm extends FormComponent
{
    public const FORM_VGWORT = 'vgwortform';

    public $id = self::FORM_VGWORT;
    //public $id = FORM_VGWORT;

    public $method = 'PUT';

    public function __construct($action, $locales, $context, $submission)
    {
        // Define the settings template and store a copy of the plugin object.
        $this->action = $action;
        $this->locales = $locales;
        $this->successMessage = "Success!";

        $this->pixelTagStatusLabels = [
            0 => __('plugins.generic.vgwort.pixelTag.status.notassigned'),
            PixelTag::STATUS_REGISTERED_ACTIVE => __('plugins.generic.vgwort.pixelTag.status.registered.active'),
            PixelTag::STATUS_UNREGISTERED_ACTIVE => __('plugins.generic.vgwort.pixelTag.status.unregistered.active'),
            PixelTag::STATUS_REGISTERED_REMOVED => __('plugins.generic.vgwort.pixelTag.status.registered.removed'),
            PixelTag::STATUS_UNREGISTERED_REMOVED => __('plugins.generic.vgwort.pixelTag.status.unregistered.removed')
        ];

        $pixelTagDao = DAORegistry::getDAO('PixelTagDAO');
        $pixelTag = $pixelTagDao->getPixelTagBySubmissionId($submission->getId());
        // $pixelTag = $pixelTagDao->getPixelTagsByContextId($context->getId());
        $publication = $submission->getLatestPublication(); // TODO: Why not getCurrentPublication()?

        if ($pixelTag == NULL) {
            $pixelTagStatus = 0;
            $pixelTagAssigned = false;
            $pixelTagRemoved = false;

            $publication->setData('vgWort::pixeltag::status', $pixelTagStatus);
            $publication->setData('vgWort::pixeltag::assign', $pixelTagAssigned);
            $publication->setData('vgWort::pixeltag::remove', $pixelTagRemoved);
        } else {
            $pixelTagStatus = $pixelTag->getStatus();
            error_log("FORM pixelTag" . var_export($pixelTag,true));

            error_log("FORM pixelTagStatus" . $pixelTagStatus);
            $publication->setData('vgWort::pixeltag::status', $pixelTagStatus);
        }

        if ($pixelTagStatus == PixelTag::STATUS_UNREGISTERED_ACTIVE || $pixelTagStatus == PixelTag::STATUS_REGISTERED_ACTIVE) {
            $pixelTagAssigned = true;
            $pixelTagRemoved = false;

            $publication->setData('vgWort::pixeltag::assign', $pixelTagAssigned);
            $publication->setData('vgWort::pixeltag::remove', $pixelTagRemoved);
        } elseif ($pixelTagStatus == PixelTag::STATUS_UNREGISTERED_REMOVED || $pixelTagStatus == PixelTag::STATUS_REGISTERED_REMOVED) {
            $pixelTagAssigned = false;
            $pixelTagRemoved = true;

            $publication->setData('vgWort::pixeltag::assign', $pixelTagAssigned);
            $publication->setData('vgWort::pixeltag::remove', $pixelTagRemoved);
        }

        $this->addField(new \PKP\components\forms\FieldSelect('vgWort::texttype', [
            'label' => __('plugins.generic.vgwort.pixelTag.textType'),
            'description' => __('plugins.generic.vgwort.pixelTag.textType.description'),
            'value' => PixelTag::TYPE_TEXT,
            'options' => [
                [
                    'value' => PixelTag::TYPE_TEXT,
                    'label' => __('plugins.generic.vgwort.pixelTag.textType.text')
                ],
                [
                    'value' => PixelTag::TYPE_LYRIC,
                    'label' => __('plugins.generic.vgwort.pixelTag.textType.lyric')
                ]
            ]
        ]));

        $this->addField(new \PKP\components\forms\FieldOptions('vgWort::pixeltag::assign', [
            'label' => __('plugins.generic.vgwort.pixelTag'),
            'description' => 'Status: ' . $this->pixelTagStatusLabels[$pixelTagStatus],
            'value' => $pixelTagAssigned,
            'type' => 'radio',
            'options' => [
                [
                    'value' => true,
                    'label' => __('plugins.generic.vgwort.pixelTag.assign'),
                    'disabled' => $pixelTagAssigned
                ],
                [
                    'value' => false,
                    'label' => __('plugins.generic.vgwort.pixelTag.remove'),
                    'disabled' => $pixelTagRemoved || $pixelTagStatus == 0
                ]
            ]
        ]));
    }
}

?>
