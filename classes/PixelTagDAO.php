<?php

namespace APP\plugins\generic\vgwort\classes;

use APP\plugins\generic\vgwort\classes\PixelTag;

use PKP\db\DAO;
use PKP\db\DAOResultFactory;
use PKP\plugins\PluginRegistry;
use PKP\plugins\Hook;

define('PT_FIELD_PRIVCODE', 'private_code');
define('PT_FIELD_PUBCODE', 'public_code');

class PixelTagDAO extends DAO {

    var $parentPluginName;

    function __construct($parentPluginName)
    {
        parent::__construct();
        $this->parentPluginName = $parentPluginName;
    }

    function newDataObject()
    {
        $vgWortPlugin = PluginRegistry::getPlugin('generic', $this->parentPluginName);

        return new PixelTag();
    }

    function getById($pixelTagId, $contextId = NULL)
    {
        $params = array((int) $pixelTagId);

        if ($contextId) {
            $params[] = (int) $contextId;
        }

        $result = $this->retrieve(
            'SELECT * FROM pixel_tags WHERE pixel_tag_id = ?' . ($contextId ? ' AND context_id = ?' : ''),
            $params
        );

        $row = $result->current();
        return $row ? $this->_fromRow((array) $row) : NULL;
    }

    function _fromRow($row)
    {
        $pixelTag = $this->newDataObject();
        $pixelTag->setId($row['pixel_tag_id']);
        $pixelTag->setContextId($row['context_id']);
        $pixelTag->setSubmissionId($row['submission_id']);
        $pixelTag->setChapterId($row['chapter_id']);
        $pixelTag->setPrivateCode($row['private_code']);
        $pixelTag->setPublicCode($row['public_code']);
        $pixelTag->setDomain($row['domain']);
        $pixelTag->setDateOrdered($row['date_ordered']);
        $pixelTag->setDateAssigned($row['date_assigned']);
        $pixelTag->setDateRegistered($row['date_registered']);
        $pixelTag->setDateRemoved($row['date_removed']);
        $pixelTag->setStatus($row['status']);
        $pixelTag->setTextType($row['text_type']);
        $pixelTag->setMessage($row['message']);

        Hook::call('PixelTagDAO::_fromRow', [&$pixelTag, &$row]);

        return $pixelTag;
    }

    function insertObject($pixelTag)
    {
        $returner = $this->update(
            sprintf('
                INSERT INTO pixel_tags
                    (context_id,
                    submission_id,
                    chapter_id,
                    private_code,
                    public_code,
                    domain,
                    date_ordered,
                    date_assigned,
                    date_registered,
                    date_removed,
                    status,
                    text_type,
                    message)
                VALUES
                    (?, ?, ?, ?, ?, ?, %s, %s, %s, %s, ?, ?, ?)',
                $this->datetimeToDB($pixelTag->getDateOrdered()),
                $this->datetimeToDB($pixelTag->getDateAssigned()),
                $this->datetimeToDB($pixelTag->getDateRegistered()),
                $this->datetimeToDB($pixelTag->getDateRemoved()),
                ),
            [
                $pixelTag->getContextId(),
                $pixelTag->getSubmissionId(),
                $pixelTag->getChapterId(),
                $pixelTag->getPrivateCode(),
                $pixelTag->getPublicCode(),
                $pixelTag->getDomain(),
                $pixelTag->getStatus(),
                $pixelTag->getTextType(),
                $pixelTag->getMessage()
            ]
        );
        $pixelTag->setId($this->getInsertPixelTagId());
        return $pixelTag->getId();
    }

    function updateObject($pixelTag)
    {
        return $this->update(
            sprintf('UPDATE pixel_tags
                SET
                    context_id = ?,
                    submission_id = ?,
                    chapter_id = ?,
                    private_code = ?,
                    public_code = ?,
                    domain = ?,
                    date_ordered = %s,
                    date_assigned = %s,
                    date_registered = %s,
                    date_removed = %s,
                    status = ?,
                    text_type = ?,
                    message = ?
                    WHERE pixel_tag_id = ?',
                $this->datetimeToDB($pixelTag->getDateOrdered()),
                $this->datetimeToDB($pixelTag->getDateAssigned()),
                $this->datetimeToDB($pixelTag->getDateRegistered()),
                $this->datetimeToDB($pixelTag->getDateRemoved())
                ),
            [
                $pixelTag->getContextId(),
                $pixelTag->getSubmissionId(),
                $pixelTag->getChapterId(),
                $pixelTag->getPrivateCode(),
                $pixelTag->getPublicCode(),
                $pixelTag->getDomain(),
                $pixelTag->getStatus(),
                $pixelTag->getTextType(),
                $pixelTag->getMessage(),
                $pixelTag->getId()
            ]
        );
    }

    function deleteObject($pixelTag)
    {
        $this->deletePixelTagById($pixelTag->getId());
    }

    function deletePixelTagById($pixelTagId)
    {
        $this->update('DELETE FROM pixel_tags WHERE pixel_tag_id = ?', (int) $pixelTagId);
    }

    function deletePixelTagsByContextId($contextId)
    {
        $pixelTags = $this->getPixelTagsByContextId($contextId);
        while ($pixelTag = $pixelTags->next()) {
            $this->deletePixelTagById($pixelTag->getId());
        }
    }

    function getPixelTagsByContextId($contextId, $searchType = NULL, $search = NULL, $status = NULL,
        $rangeInfo = NULL, $sortBy = NULL, $sortDirection = SORT_DIRECTION_ASC)
    {
        $sql = 'SELECT DISTINCT * FROM pixel_tags ';
        $paramArray = [];

        switch ($searchType) {
            case PT_FIELD_PRIVCODE:
                $sql .= ' WHERE LOWER(private_code) LIKE LOWER(?)';
                $paramArray[] = "%$search%";
                break;
            case PT_FIELD_PUBCODE:
                $sql .= ' WHERE LOWER(public_code) LIKE LOWER(?)';
                $paramArray[] = "%$search%";
                break;
            default:
                $searchType = NULL;
        }

        if (empty($searchType)) {
            $sql .= ' WHERE';
        } else  {
            $sql .= ' AND';
        }

        $sql .= ' context_id = ?' . ($sortBy ? (' ORDER BY ' . $sortBy . ' ' . $this->getDirectionMapping($sortDirection)) : '');
        $paramArray[] = (int) $contextId;
        $result = $this->retrieveRange($sql, $paramArray, $rangeInfo);
        return new DAOResultFactory($result, $this, '_fromRow', ['id'], $sql, $paramArray, $rangeInfo);
    }

    function getPixelTagBySubmissionId($submissionId, $contextId = NULL)
    {
        $params = array((int) $submissionId);
        if ($contextId) $params[] = (int) $contextId;
        $result = $this->retrieve(
            'SELECT * FROM pixel_tags WHERE chapter_id IS NULL AND submission_id = ?' . ($contextId ? ' AND context_id = ?' : ''),
            $params
        );
        $row = $result->current();
        return $row ? $this->_fromRow((array) $row) : NULL;
    }

    function getPixelTagByChapterId($chapterId, $submissionId, $contextId = NULL)
    {
        $params = array((int) $chapterId, (int) $submissionId);
        if ($contextId) $params[] = (int) $contextId;
        $result = $this->retrieve(
            'SELECT * FROM pixel_tags WHERE chapter_id = ? AND submission_id = ?' . ($contextId ? ' AND context_id = ?' : ''),
            $params
        );
        $row = $result->current();
        return $row ? $this->_fromRow((array) $row) : NULL;
    }

    function getAllForRegistration($contextId, $publicationDate = NULL)
    {
        // TODO: import('classes.publication.Publication');
        $params = array((int) $contextId, PixelTag::STATUS_UNREGISTERED_ACTIVE, STATUS_DECLINED);
        $result = $this->retrieve(
            'SELECT pt.*
            FROM pixel_tags pt
            LEFT JOIN publications ps ON ps.submission_id = pt.submission_id
            LEFT JOIN submissions s ON s.submission_id = ps.submission_id
            LEFT JOIN publication_settings ps_set ON ps_set.publication_id = ps.publication_id
            LEFT JOIN issues i ON i.issue_id = ps_set.setting_value
            WHERE ps_set.setting_name = "issueId" AND pt.context_id = ? AND pt.status = ? AND
                i.published = 1 AND s.status <> ?' .
                ($publicationDate ? sprintf(' AND ps.date_published < %s AND i.date_published < %s', $this->datetimeToDB($publicationDate), $this->datetimeToDB($publicationDate)) : ''),
            $params
        );
        $returner = new DAOResultFactory($result, $this, '_fromRow');
        return $returner;
    }

    function getAvailablePixelTag($contextId)
    {
        $result = $this->retrieve(
            'SELECT * FROM pixel_tags
            WHERE context_id = ? AND submission_id IS NULL AND date_assigned IS NULL AND status = ?
            ORDER BY date_ordered',
            array((int) $contextId, PixelTag::STATUS_AVAILABLE)
        );
        $row = $result->current();
        return $row ? $this->_fromRow((array) $row) : NULL;
        // $returner = NULL;
        // if (isset($result) && $result->RecordCount() != 0) {
        //     $returner = $this->_fromRow($result->GetRowAssoc(false));
        // }
        // $result->Close();
        // return $returner;
    }

    function failedUnregisteredActiveExists($contextId)
    {
        $result = $this->retrieve(
            'SELECT COUNT(*) FROM pixel_tags WHERE message <> \'\' AND message IS NOT NULL AND  context_id = ?',
            array((int) $contextId)
        );
        return $result->fields[0] ? true : false;
    }

    function getInsertPixelTagId()
    {
        return $this->_getInsertId('pixel_tags', 'pixel_tag_id');
    }
}

?>
