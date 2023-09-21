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
        error_log("number: $total");
        
        $submissionFiles = Repo::submissionFile()->getCollector()->getMany();
        foreach ($submissionFiles as $submissionFile) {
            $submissionFileId = $submissionFile->getId();
            //
            $checkedSubmissionFiles[] = $submissionFileId;
            //
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

            //// Write missing submissions to text file
            //$fileName = __DIR__ . '/missingSubmissions.txt';
            if ($this->my_empty($publicCode) || $this->my_empty($privateCode)
            ) { 
                //file_put_contents($fileName, $submissionId . '\n', FILE_APPEND | LOCK_EX);
                continue;
            }

            error_log("contextId:     $contextId");
            error_log("submissionId:  $submissionId");
            error_log("chapterId:     $chapterId");
            error_log("domain:        $domain");
            error_log("dateOrdered:   $dateOrdered");
            error_log("dateAssigned:  $dateAssigned");
            error_log("status:        $status");
            error_log("textType:      $textType");
            error_log("message:       $message");
            error_log("privateCode:   $privateCode");
            error_log("publicCode:    $publicCode");
            error_log("************************************************");

            $pixelTag = $pixelTagDao->getPixelTagBySubmissionId($submissionId, $contextId);
            
            if (!$this->my_empty($pixelTag)) {
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
            $this->progress_bar($done, $total);
        }
    }

    function my_empty($x)
    {
        if (!isset($x) || empty($x) || is_null($x) || '') {
            return true;
        }
    }

    function progress_bar($done, $total, $info="", $width=50)
    {
        $perc = round(($done * 100) / $total);
        $bar = round(($width * $perc) / 100);
        return sprintf("%s%%[%s>%s]%s\r", $perc, str_repeat("=", $bar), str_repeat(" ", $width-$bar), $info);
    }
}

try {
    $tool = new InsertOldPixelTags;
    $tool->execute();
} catch (\Throwable $th) {
    throw $th;
}

?>
