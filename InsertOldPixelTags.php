<?php

namespace APP\plugins\generic\vgwort;

use APP\plugins\generic\vgwort\classes\PixelTagDAO;
use APP\plugins\generic\vgwort\classes\PixelTag;

use PKP\plugins\SubmissionFile;
use PKP\cliTool\CommandLineTool;
use PKP\db\DAORegistry;
use PKP\db\DAO;

use Illuminate\Support\Facades\DB;

use APP\core\Application;
use APP\core\Services;
use APP\facades\Repo;
use APP\submission\Submission;

require(dirname(__FILE__, 4) . '/tools/bootstrap.php');

class InsertOldPixelTags extends CommandLineTool
{
    public function execute()
    {
        $request = Application::get()->getRequest();
        $pixelTagDao = new PixelTagDAO("vgwortplugin");
        
        $domain = 'vg07.met.vgwort.de';
        $message = '';
        $dateOrdered = date("Y-m-d H:i:s");
        $dateAssigned = $dateOrdered;
        $dateRegistered = NULL;
        $dateRemoved = NULL;
        $status = 2; // unregistered, active
        $textType = 1; // Text (i.e. >1800 characters or ~300 words)

        $checkedSubmissionFiles = [];
        $total = Repo::submissionFile()->getCollector()->getCount();
        error_log("Total: $total submission files");
        
        $submissionFiles = Repo::submissionFile()->getCollector()->getMany();
        foreach ($submissionFiles as $submissionFile) {
            $submissionFileId = $submissionFile->getId();

            // Show progress bar
            $checkedSubmissionFiles[] = $submissionFileId;
            echo $this->showProgressBar(count($checkedSubmissionFiles), $total);
            
            $chapterId = $submissionFile->getData('chapterId');

            if (isset($chapterId)) {
               $textType = 1;
            }
            $publicCode = DB::table('submission_file_settings as sfs')
                ->where('sfs.submission_file_id', '=', $submissionFileId)
                ->where('sfs.setting_name', '=', 'vgWortPublic')
                ->select('sfs.setting_value')
                ->value('sfs.setting_value');
            $privateCode = DB::table('submission_file_settings as sfs')
                ->where('sfs.submission_file_id', '=', $submissionFileId)
                ->where('sfs.setting_name', '=', 'vgWortPrivate')
                ->select('sfs.setting_value')
                ->value('sfs.setting_value');
            $submissionId = $submissionFile->getData('submissionId');
            $submission = Repo::submission()->get($submissionId);
            $contextId = $submission->getContextId();
            $publications = Repo::publication()
                ->getCollector()
                ->filterBySubmissionIds([$submissionId])
                ->getMany();

            // public and private code must not be empty
            if ($this->isEmpty($publicCode) || $this->isEmpty($privateCode)) { continue; }

            // $pixelTag = $pixelTagDao->getPixelTagBySubmissionId($submissionId, $contextId);
            if (!$this->isEmpty($chapterId)) {
                $pixelTag = $pixelTagDao->getPixelTagByChapterId($chapterId, $submissionId, $contextId);
            } else {
                $pixelTag = $pixelTagDao->getPixelTagBySubmissionId($submissionId, $contextId);
            }

            if (!$this->isEmpty($pixelTag)) {
                continue;
            } else {
                $pixelTag = new PixelTag();
                $pixelTag->setContextId($contextId);
                $pixelTag->setSubmissionId($submissionId);
                $pixelTag->setChapterId($chapterId);
                $pixelTag->setDomain($domain);
                $pixelTag->setDateOrdered($dateOrdered);
                $pixelTag->setDateAssigned($dateAssigned);
                $pixelTag->setDateRegistered($dateRegistered);
                $pixelTag->setDateRemoved($dateRemoved);
                $pixelTag->setStatus($status);
                $pixelTag->setTextType($textType);
                $pixelTag->setMessage($message);
                $pixelTag->setPrivateCode($privateCode);
                $pixelTag->setPublicCode($publicCode);
                $pixelTagDao->insertObject($pixelTag);
            }

            $done = count($checkedSubmissionFiles);
        }
    }

    function isEmpty($x)
    {
        if (!isset($x) || empty($x) || is_null($x) || '') {
            return true;
        }
    }

    function showProgressBar($done, $total, $info="", $width=50)
    {
        $perc = round(($done * 100) / $total);
        $bar = round(($width * $perc) / 100);
        return sprintf("[%s%s] %s%%\r", str_repeat("#", $bar), str_repeat("-", $width-$bar), $perc);
    }
}

try {
    $tool = new InsertOldPixelTags;
    $tool->execute();
} catch (\Throwable $th) {
    throw $th;
}

?>
