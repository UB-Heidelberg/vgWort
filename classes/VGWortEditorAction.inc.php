<?php

/**
 * @file plugins/generic/vgWort/classes/VGWortEditorAction.inc.php
 *
 * Copyright (c) 2018 Center for Digital Systems (CeDiS), Freie Universität Berlin
 * Distributed under the GNU GPL v2. For full terms see the file LICENSE.
 *
 * @class VGWortEditorAction
 * @ingroup plugins_generic_vgWort
 *
 * @brief VGWortEditorAction class.
 */

use \GuzzleHttp\Exception\ClientException;
use \GuzzleHttp\Exception\ServerException;
use \GuzzleHttp\Exception\BadResponseException;

define('PIXEL_SERVICE', 'https://tom.vgwort.de/api/external/metis/rest/pixel/v1.0/order');
define('CHECK_AUTHOR', 'https://tom.vgwort.de/api/external/metis/rest/message/v1.0/checkAuthorRequest');
define('NEW_MESSAGE', 'https://tom.vgwort.de/api/external/metis/rest/message/v1.0/newMessageRequest');

// to just test the plugin, please use the VG Wort test portal:
define('PIXEL_SERVICE_TEST', 'https://tom-test.vgwort.de/api/external/metis/rest/pixel/v1.0/order');
define('CHECK_AUTHOR_TEST', 'https://tom-test.vgwort.de/api/external/metis/rest/message/v1.0/checkAuthorRequest');
define('NEW_MESSAGE_TEST', 'https://tom-test.vgwort.de/api/external/metis/rest/message/v1.0/newMessageRequest');

class VGWortEditorAction {
	/** @var $_plugin VGWortPlugin */
	var $_plugin;

	/**
	 * Constructor.
	 * @param $plugin Plugin
	 */
	function __construct($plugin) {
		$this->_plugin = $plugin;
	}

	/**
	 * Order pixel tags, uses VG Wort service.
	 * @param $contextId int
	 * @return array (boolean successful, mixed errorMsg or result object)
	 */
	function orderPixel($contextId) {
		$vgWortPlugin = $this->_plugin;
		$vgWortUserId = $vgWortPlugin->getSetting($contextId, 'vgWortUserId');
		$vgWortUserPassword = $vgWortPlugin->getSetting($contextId, 'vgWortUserPassword');
		$vgWortTestAPI = $vgWortPlugin->getSetting($contextId, 'vgWortTestAPI');
		$vgWortAPI = PIXEL_SERVICE;

		if ($vgWortTestAPI) {
			$vgWortAPI = PIXEL_SERVICE_TEST;
		}

	    $data = ['count' => 1];

	    try {
	        if (!$vgWortPlugin->requirementsFulfilled()) {
	            return [false, __('plugins.generic.vgWort.requirementsRequired')];
	        }
			$response = $this->uploadJSON($vgWortAPI, $vgWortUserId, $vgWortUserPassword, $data);
			$response_body = $response[1];
			$response_json = json_decode($response_body,true);
			return [true, json_decode($response_json)];
	    }
	    catch (\GuzzleHttp\Exception\ClientException $e) {
	        if ($e->hasResponse()) {
	            $response = $e->getResponse();
	            $responseBodyAsString = $response->getBody()->getContents();
	            $statusCode = $response->getStatusCode();
	            $reasonPhrase = $response->getReasonPhrase();
	            return [false, __('plugins.generic.vgWort.order.errorCode') . $reasonPhrase];
	        }
	    }
	    catch (\GuzzleHttp\Exception\ServerException $e) {
	        if ($e->hasResponse()) {
	            $response = $e->getResponse();
	            $responseBodyAsString = $response->getBody()->getContents();
	            $statusCode = $response->getStatusCode();
	            $reasonPhrase = $response->getReasonPhrase();
	            return [false, $reasonPhrase];
	        }
	    }
	    catch (Exception $e) {
	        error_log("[VG Wort] Exception: " . var_export($e->getResponse(),true));
	    }
	}

	/**
	 * Insert the ordered pixel tags in the DB
	 * @param $contextId int
	 * @param $result stdClass object
	 */
	function insertOrderedPixel($contextId, $result) {
        $pixelTagDao = DAORegistry::getDAO('PixelTagDAO');
		$pixels = $result->pixels;
		$currPixel = $pixels[0];
		$pixelTag = new PixelTag();
		$pixelTag->setContextId($contextId);
		$pixelTag->setDomain($result->domain);
		$pixelTag->setDateOrdered(strtotime($result->orderDateTime));
		$pixelTag->setStatus(PT_STATUS_AVAILABLE);
		$pixelTag->setTextType(TYPE_TEXT);
		$pixelTag->setPrivateCode($currPixel->privateIdentificationId);
		$pixelTag->setPublicCode($currPixel->publicIdentificationId);
		$pixelTagId = $pixelTagDao->insertObject($pixelTag);
	}

	/**
	 * Check if a pixel tag can be registered.
	 * @param $pixelTag PixelTag
	 * @return array (successful boolean, errorMsg string)
	 */
	function check($pixelTag) {
    	$submission = $pixelTag->getSubmission();
        if ($submission->getData('status') != STATUS_PUBLISHED) {
			return array(false, __('plugins.generic.vgWort.check.articleNotPublished'));
		} else {
			$issueDao = DAORegistry::getDAO('IssueDAO');
			$issueId = $submission->getCurrentPublication()->getData('issueId');
            $issue = $issueDao->getById($issueId);#, $pixelTag->getContextId());
			if (!$issue->getPublished()) {
				return array(false, __('plugins.generic.vgWort.check.articleNotPublished'));
			} else {
				// get supported galleys
				$galleys = $submission->getGalleys();
				$supportedGalleys = array_filter($galleys, array($this->_plugin, 'galleySupported'));
				if (empty($supportedGalleys)) {
					return array(false, __('plugins.generic.vgWort.check.galleyRequired'));
				} else {
					// check that all existing card numbers are valid
					foreach ($submission->getAuthors() as $author) {
						$cardNo = $author->getData('vgWortCardNo');
						if (!empty($cardNo)) {
						    $locale = $submission->getLocale();
						    $checkAuthorResult = $this->checkAuthor($pixelTag->getContextId(), $cardNo, $author->getFamilyName($locale));
							if (!$checkAuthorResult[0]) {
								return array(false, $checkAuthorResult[1]);
							}
						}
					}
				}
			}
		}
		return array(true, '');
	}

	/**
	 * Register the pixel tag.
	 * @param $pixelTag PixelTag
	 * @param $request Request
	 */
	 function registerPixelTag($pixelTag, $request, $contextId = null) {
		$pixelTagDao = DAORegistry::getDAO('PixelTagDAO');

		// check if the requirements for the registration are fulfilled
		$checkResult = $this->check($pixelTag, $request);
        //error_log("checkResult: ". var_export($checkResult,true));
		$isError = !$checkResult[0];
		$errorMsg = null;
		if ($isError) {
			$errorMsg = $checkResult[1];
        } else {
			// register
			$registerResult = $this->newMessage($pixelTag, $request, $contextId);
			$isError = !$registerResult[0];
			$errorMsg = $registerResult[1];
			if (!$isError) {
				// update the registered pixel tag
				$pixelTag->setDateRegistered(Core::getCurrentDate());
				$pixelTag->setMessage(NULL);
				$pixelTag->setStatus(PT_STATUS_REGISTERED_ACTIVE);
				$pixelTagDao->updateObject($pixelTag);

				// remove the VG Wort error notification for editors
				$this->_removeNotification($pixelTag);
				// set parameters fo rthe trivial notification for the current user
				$notificationType = NOTIFICATION_TYPE_SUCCESS;
				$notificationMsg = __('plugins.generic.vgWort.pixelTags.register.success');
			}
		}
		if ($isError) {
			// save the error message
			// the error message is in a specific language: either it is
			// already translated in the UI language of the last registration or
			// coming directly from the SOAP API
			$pixelTag->setMessage($errorMsg);
			$pixelTagDao->updateObject($pixelTag);

			// create the VG Wort error notification for editors
			$this->_createNotification($request, $pixelTag);
			// set parameters fo rthe trivial notification for the current user
			$notificationType = NOTIFICATION_TYPE_FORM_ERROR;
			$notificationMsg = $errorMsg;
		}

		// create the trivial notification for the current user i.e.
		// only if the function is not called from the scheduled task
		if (!defined('SESSION_DISABLE_INIT')) {
			$user = $request->getUser();
			if ($user) {
				import('classes.notification.NotificationManager');
				$notificationManager = new NotificationManager();
				$notificationManager->createTrivialNotification(
					$user->getId(), $notificationType, array('contents' => $notificationMsg)
				);
			}
		}
	}

	/**
	 * Check if the card number is valid for the autor, uses VG Wort service.
	 * @param $contextId int
	 * @param $cardNo int VG Wort card number
	 * @param $lastName string author last name
	 * @return array (valid boolean, errorMsg string)
	 */
	function checkAuthor($contextId, $cardNo, $lastName) {
		$vgWortPlugin = $this->_plugin;
		$vgWortUserId = $vgWortPlugin->getSetting($contextId, 'vgWortUserId');
		$vgWortUserPassword = $vgWortPlugin->getSetting($contextId, 'vgWortUserPassword');
		$vgWortTestAPI = $vgWortPlugin->getSetting($contextId, 'vgWortTestAPI');
		$vgWortAPI = CHECK_AUTHOR;
		if ($vgWortTestAPI) {
			$vgWortAPI = CHECK_AUTHOR_TEST;
		}

		$httpClient = Application::get()->getHttpClient();
	        $data = [
	            'cardNumber' => $cardNo,
	            'surName' => $lastName
	        ];

	        try {
	            if (!$vgWortPlugin->requirementsFulfilled()) {
	                return [false, __('plugins.generic.vgWort.requirementsRequired')];
	            }

	            $response = $httpClient->request(
	                'GET',
	                $vgWortAPI,
	                [
	                    'json' => $data,
	                    'auth' => [$vgWortUserId, $vgWortUserPassword]
	                ]
	            );

	            $response = json_decode(json_encode($response->getBody()), false);

	            return [true, $response];
	        }
	        catch (\GuzzleHttp\Exception\ClientException $e) {
	            if ($e->hasResponse()) {
	                $response = $e->getResponse();
	                $responseBodyAsString = $response->getBody()->getContents();
	                $statusCode = $response->getStatusCode();
	                $reasonPhrase = $response->getReasonPhrase();
	                return [false, __('plugins.generic.vgWort.order.errorCode') . $reasonPhrase];
	            }
	        }
	        catch (\GuzzleHttp\Exception\ServerException $e) {
	            if ($e->hasResponse()) {
	                $response = $e->getResponse();
	                $responseBodyAsString = $response->getBody()->getContents();
	                $statusCode = $response->getStatusCode();
	                $reasonPhrase = $response->getReasonPhrase();
	                return [false, $reasonPhrase];
	            }
	        }
	        catch (Exception $e) {
	            error_log("[VGWortEditorAction] checkAuthor() Exception: " . var_export($e->getResponse(),true));
	        }
	    }

		//try {
		//	// check if the system requirements are fulfilled
		//	if (!$vgWortPlugin->requirementsFulfilled()) {
		//		return array(false, __('plugins.generic.vgWort.requirementsRequired'));
		//	}
		//	// check web service: availability and credentials
		//	$this->_checkService($vgWortUserId, $vgWortUserPassword, $vgWortAPI);
		//	$client = new SoapClient($vgWortAPI, array(
		//		'login' => $vgWortUserId,
		//		'password' => $vgWortUserPassword
		//	));
		//	$result = $client->checkAuthor(array("cardNumber" => $cardNo, "surName" => $lastName));
		//	return array(
		//		$result->valid,
		//		__('plugins.generic.vgWort.check.notValid', array('surName' => $lastName))
		//	);
		//}
		//catch (SoapFault $soapFault) {
		//	if($soapFault->faultcode == 'noWSDL' || $soapFault->faultcode == 'httpError') {
		//		return array(false, $soapFault->faultstring);
		//	}
		//	$detail = $soapFault->detail;
		//	$function = $detail->checkAuthorFault;
		//	if (isset($function)) {
		//	     return array(false, __('plugins.generic.vgWort.check.errorCode' . $function->errorcode));
		//	}
		//	return array(
		//		false,
		//		__('plugins.generic.vgWort.check.errorCode'),
		//		array(
		//			'faultcode' => $soapFault->faultcode,
		//			'faultstring' => $soapFault->faultstring
		//		)
		//	);
		//}
	//}

	/**
	 * Register a pixel tag with VG Wort, uses VG Wort service.
	 * @param $pixelTag PixelTag
	 * @param $request Request
	 * @return array (successful boolean, errorMsg string)
	 *  the check is already done, i.e. the article and the issue are published,
	 *  there is a supported galley and the existing card numbers are valid
	 */
	function newMessage($pixelTag, $request, $contextId = null) {
		$vgWortPlugin = $this->_plugin;
		if (!isset($contextId)) {
			$contextId = $vgWortPlugin->getCurrentContextId();//$context->getId();
		}
	        $vgWortUserId = $vgWortPlugin->getSetting($contextId, 'vgWortUserId');
	        $vgWortUserPassword = $vgWortPlugin->getSetting($contextId, 'vgWortUserPassword');
	        $vgWortTestAPI = $vgWortPlugin->getSetting($contextId, 'vgWortTestAPI');
	        $vgWortAPI = NEW_MESSAGE;
	        if ($vgWortTestAPI) {
	            $vgWortAPI = NEW_MESSAGE_TEST;
	        }

		$vgWortPlugin->import('classes.PixelTag');
	        $submission = $pixelTag->getSubmission();

	        $locale = $submission->getLocale();

	        // Get authors and translators
	        $contributors = $submission->getAuthors();
	        $authors = array_filter($contributors, [$this, '_filterAuthors']);
	        $translators = array_filter($contributors, [$this, '_filterTranslators']);
		//error_log("VGWORT: contributors: " . var_export($contributors,true));
		//error_log("VGWORT: authors: " . var_export($authors,true));
		//error_log("VGWORT: translators: " . var_export($translators,true));

		assert(!empty($authors) || !empty($translators));
	        $participants = [];
	        if (!empty($authors)) {
	            foreach ($authors as $author) {
	                $cardNo = $author->getData('vgWortCardNo');
	                if (!empty($cardNo)) {
	                    $participants[] = [
	                        'cardNumber' => $author->getData('vgWortCardNo'),
	                        'firstName' => mb_substr($author->getGivenName($locale), 0, 39, 'utf8'),
	                        'involvement' => 'AUTHOR',
	                        'surName' => $author->getFamilyName($locale)
	                    ];
	                } else {
	                    $participants[] = [
	                        'firstName' => mb_substr($author->getGivenName($locale), 0, 39, 'utf8'),
	                        'involvement' => 'AUTHOR',
	                        'surName' => $author->getFamilyName($locale)
	                    ];
	                };
	            };
	        }
	        if (!empty($translators)) {
	            foreach ($translators as $translator) {
	                $cardNo = $author->getData('vgWortCardNo');
	                if (!empty($cardNo)) {
	                    $participants[] = [
	                        'cardNumber' => $translator->getData('vgWortCardNo'),
	                        'firstName' => mb_substr($translator->getGivenName($locale), 0, 39, 'utf8'),
	                        'involvement' => 'TRANSLATOR',
	                        'surName' => $translator->getFamilyName($locale)
	                    ];
	                } else {
	                    $participants[] = [
	                        'firstName' => mb_substr($translator->getGivenName($locale), 0, 39, 'utf8'),
	                        'involvement' => 'TRANSLATOR',
	                        'surName' => $translator->getFamilyName($locale)
	                    ];
	                };
	            };
	        }

		// get supported galleys
		$galleys = (array) $submission->getGalleys();
		$supportedGalleys = array_filter($galleys, array($vgWortPlugin, 'galleySupported'));
		// construct the VG Wort webranges for the supported galleys
		$webranges = [];

	        $dispatcher = Application::get()->getDispatcher();
	        foreach ($supportedGalleys as $supportedGalley) {
		    $url = $dispatcher->url(
			$request,
			ROUTE_PAGE,
			null,
			'article',
			'view',
			array(
				$submission->getBestArticleId(),
				$supportedGalley->getBestGalleyId()
			)
		);
			$webrange = array('urls' => array($url));
			$webranges[] = $webrange;

			$downlaodUrl1 = $dispatcher->url(
				$request,
				ROUTE_PAGE,
				null,
				'article',
				'view',
				array(
					$submission->getBestArticleId(),
					$supportedGalley->getBestGalleyId()
				)
			);
			$webrange = array('urls' => array($downlaodUrl1));
			$webranges[] = $webrange;

			$downlaodUrl2 = $dispatcher->url(
				$request,
				ROUTE_PAGE,
				null,
				'article',
				'view',
				array(
					$submission->getBestArticleId(),
					$supportedGalley->getBestGalleyId(),
					$supportedGalley->getFileId()
				)
			);
			$webrange = array('urls' => array($downlaodUrl2));
			$webranges[] = $webrange;
		}
		//error_log("VGWORT: webranges: " . var_export($webranges,true));

		// get the text/content:
		// if there is no German text, then try English, else any
		$deGalleys = array_filter($supportedGalleys, array($this, '_filterDEGalleys'));
		if (!empty($deGalleys)) {
			reset($deGalleys);
			$galley = current($deGalleys);
		} else {
			$enGalleys = array_filter($supportedGalleys, array($this, '_filterENGalleys'));
			if (!empty($enGalleys)) {
				reset($enGalleys);
				$galley = current($enGalleys);
			} else {
				reset($supportedGalleys);
				$galley = current($supportedGalleys);
			}
		}
		import('lib.pkp.classes.file.SubmissionFileManager');
		$submissionFileManager = new SubmissionFileManager($contextId, $pixelTag->getSubmissionId());
		$galleyFile = $galley->getFile();
		$content = $submissionFileManager->readFileFromPath($galleyFile->getFilePath());

		$galleyFileType = $galleyFile->getFileType();
		if ($galleyFileType == 'text/html' || $galleyFileType == 'text/xml') {
			$text = array('plainText' => base64_encode(strip_tags($content)));
		} elseif ($galleyFileType == 'application/pdf') {
		    // base64_encode of pdf causes soapClient/Business Exception -> vgWort Errorcode 8
		    $text = array('pdf' => base64_encode($content));
		} elseif ($galleyFileType == 'application/epub+zip') {
		    // base64_encode of epub causes soapClient/Business Exception -> vgWort Errorcode 20
		    $text = array('epub' => $content);
		}
		//error_log("VGWORT: text: " . var_export($text,true));
		// get the title (max. 100 characters):
		// if there is no German title, then try English, else in the primary language
		$submissionLocale = $submission->getLocale();
		$primaryLocale = AppLocale::getPrimaryLocale();
		// TODO: getTitle() defined?
		$title = $submission->getTitle('de_DE');
		if (!isset($title) || $title == '') {
			$title = $submission->getTitle('en_US');
		}
		if (!isset($title) || $title == '') {
			$title = $submission->getTitle($submissionLocale);
		}
		if (!isset($title) || $title == '') {
			$title = $submission->getTitle($primaryLocale);
		}
		$shortText = mb_substr($title, 0, 99, 'utf8');

		// is it a poem
		$isLyric = ($pixelTag->getTextType() == TYPE_LYRIC);

		// create a VG Wort message
		$message = array('lyric' => $isLyric, 'shorttext' => $shortText, 'text' => $text);

	        //$httpClient = Application::get()->getHttpClient();
	        $data = [
	            'participants' => $participants,
	            'privateidentificationid' => $pixelTag->getPrivateCode(),
	            'messagetext' => $message,
	            'webranges' => $webranges,
	            "distributionRight" => true,
	            "publicAccessRight" => true,
	            "reproductionRight" => true,
	            "rightsGrantedConfirmation" => true
	        ];

		//try {
			if (!$vgWortPlugin->requirementsFulfilled()) {
                		return [false, __('plugins.generic.vgWort.requirementsRequired')];
			}
			$response = $this->uploadJSON($vgWortAPI, $vgWortUserId, $vgWortUserPassword, $data);
			//$response = json_decode($response, false);
			//error_log("VGWORT: response: " . var_export($response,true));
			return [true, $response];
		//} catch () {
		//
		//}

		//try {
	        //    if (!$vgWortPlugin->requirementsFulfilled()) {
	        //        return [false, __('plugins.generic.vgWort.requirementsRequired')];
	        //    }
	        //    $response = $httpClient->request(
	        //        'POST',
	        //        $vgWortAPI,
	        //        [
	        //            'json' => $data,
	        //            'auth' => [$vgWortUserId, $vgWortUserPassword],
	        //            // 'debug' => $debug
	        //        ]
	        //    );
	        //    $response = json_decode($response->getBody(), false);
	        //    return [true, $response];
	        //}
	        //catch (\GuzzleHttp\Exception\ClientException $e) {
	        //    if ($e->hasResponse()) {
	        //        $response = $e->getResponse();
	        //        $responseBodyAsString = $response->getBody()->getContents();
	        //        $statusCode = $response->getStatusCode();
	        //        $reasonPhrase = $response->getReasonPhrase();
	        //        return [false, __('plugins.generic.vgWort.order.errorCode') . $reasonPhrase];
	        //    }
	        //}
	        //catch (\GuzzleHttp\Exception\ServerException $e) {
	        //    if ($e->hasResponse()) {
	        //        $response = $e->getResponse();
	        //        $responseBodyAsString = $response->getBody()->getContents();
	        //        $statusCode = $response->getStatusCode();
	        //        $reasonPhrase = $response->getReasonPhrase();
	        //        return [false, $reasonPhrase];
	        //    }
	        //}
	        //catch (Exception $e) {
	        //    // error_log("[VGWortEditorAction] newMessage Exception: " . var_export($e->getResponse(),true));
	        //}

	}
//		try {
//			// check if the system requirements are fulfilled
//			if (!$vgWortPlugin->requirementsFulfilled()) {
//				return array(false, __('plugins.generic.vgWort.requirementsRequired'));
//			}
//
//			// check web service: availability and credentials
//			$this->_checkService($vgWortUserId, $vgWortUserPassword, $vgWortAPI);
//			$client = new SoapClient($vgWortAPI, array(
//				'login' => $vgWortUserId,
//				'password' => $vgWortUserPassword
//			));
//			$result = $client->newMessage(array(
//				'parties' => $parties,
//				'privateidentificationid' => $pixelTag->getPrivateCode(),
//				'messagetext' => $message,
//				'webranges' => $webranges));
//			return array($result->status == 'OK', '');
//		}
//		catch (SoapFault $soapFault) {
//
//			// TODO: Is this error log necessary??
//			// log error details
//			error_log($soapFault);
//
//			if($soapFault->faultcode == 'noWSDL' || $soapFault->faultcode == 'httpError') {
//				return array(false, $soapFault->faultstring);
//			}
//
//			switch ($soapFault->faultstring)
//			{
//			case "Validation error":
//				$errorDetails = (array) $soapFault->detail;
//				error_log(print_r($errorDetails, TRUE));
//				return array(
//					false,
//					__('plugins.generic.vgWort.register.validationError',
//					array(
//						'details' => is_array($errorDetails['ValidationError'])
//							? implode($errorDetails['ValidationError'])
//							: print_r($errorDetails['ValidationError'], TRUE)
//					))
//				);
//			case "Business Exception":
//			        $errorDetails = $soapFault->detail->newMessageFault;
//			        error_log(print_r($errorDetails, TRUE));
//			        return array(
//						false,
//						__('plugins.generic.vgWort.register.vgWortBusinessException', array(
//							'errorcode' => $errorDetails->errorcode,
//							'errormsg' => $errorDetails->errormsg
//						))
//					);
//				return array(
//					false,
//					__('plugins.generic.vgWort.register.errorCode', array(
//						'faultcode' => $soapFault->faultcode,
//						'faultstring' => $soapFault->faultstring
//					))
//				);
//			default:
//				if (isset($soapFault->detail)) {
//					// TODO: Is this error log necessary??
//					error_log(print_r($soapFault->detail, TRUE));
//				}
//				return array(
//					false,
//					__('plugins.generic.vgWort.register.errorCode', array(
//						'faultcode' => $soapFault->faultcode,
//						'faultstring' => $soapFault->faultstring
//					))
//				);
//			}



	/**
	 * Check if the contributor is an author.
	 * @param $contributor Author
	 * @return boolean
	 */
	function _filterAuthors($contributor) {
		$userGroup = $contributor->getUserGroup();
		return $userGroup->getData('nameLocaleKey') == 'default.groups.name.author';
	}

	/**
	 * Check if the contributor is a translator.
	 * @param $contributor Author
	 * @return boolean
	 */
	function _filterTranslators($contributor) {
		$userGroup = $contributor->getUserGroup();
		return $userGroup->getData('nameLocaleKey') == 'default.groups.name.translator';
	}

	/**
	 * Check if the galley locale is de_DE.
	 * @param $galley ArticleGalley
	 * @return boolean
	 */
	function _filterDEGalleys($galley) {
		return $galley->getLocale() == 'de_DE';
	}

	/**
	 * Check if the galley locale is en_US.
	 * @param $galley ArticleGalley
	 * @return boolean
	 */
	function _filterENGalleys($galley) {
		return $galley->getLocale() == 'en_US';
	}

	/**
	 * Check web service availability and credentials.
	 * @param $vgWortUserId string
	 * @param $vgWortUserPassword string
	 * @param $vgWortAPI string WSDL URL
	 */
	function _checkService($vgWortUserId, $vgWortUserPassword, $vgWortAPI) {
		// catch and throw an exception if the VG Wort server is down i.e.
		// the WSDL not found
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $vgWortAPI);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		$wsdlContent = curl_exec($curl);
		$httpStatusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		if($httpStatusCode != 200) {
			throw new SoapFault('noWSDL', __('plugins.generic.vgWort.noWSDL', array('wsdl' => $vgWortAPI)));
		}
		curl_close($curl);
		// catch and throw an exception if the authentication or the authorization error occurs
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, str_replace('://', '://' . $vgWortUserId.':' . $vgWortUserPassword . '@', $vgWortAPI));
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		$wsdlContent = curl_exec($curl);
		$httpStatusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		if($httpStatusCode != 200) {
			throw new SoapFault('httpError', __('plugins.generic.vgWort.' . $httpStatusCode));
		}
		curl_close($curl);
	}

	/**
	 * Remove the VG Wort notification.
	 * @param $pixelTag PixelTag
	 */
	function _removeNotification($pixelTag) {
		$submission = $pixelTag->getSubmission();
		$stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO');
		// get the editors assigned to the submisison in the production stage
		$editorStageAssignments = $stageAssignmentDao->getEditorsAssignedToStage($submission->getId(), WORKFLOW_STAGE_ID_PRODUCTION);
		$notificationDao = DAORegistry::getDAO('NotificationDAO');
		foreach ($editorStageAssignments as $editorStageAssignment) {
			$notificationDao->deleteByAssoc(
				ASSOC_TYPE_SUBMISSION,
				$submission->getId(),
				$editorStageAssignment->getUserId(),
				NOTIFICATION_TYPE_VGWORT_ERROR,
				$pixelTag->getContextId()
			);
		}
	}

	/**
	 * Create the VG Wort notification if none exists.
	 * The notification will only be created for editors that
	 * are assigned to the submission in the production stage.
	 * @param $request Request
	 * @param $pixelTag PixelTag
	 */
	function _createNotification($request, $pixelTag) {
		$submission = $pixelTag->getSubmission();
		$stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO');
		// get the editors assigned to the submisison in the production stage
		$editorStageAssignments = $stageAssignmentDao->getEditorsAssignedToStage($submission->getId(), WORKFLOW_STAGE_ID_PRODUCTION);
		$notificationDao = DAORegistry::getDAO('NotificationDAO');
		foreach ($editorStageAssignments as $editorStageAssignment) {
			$notificationFactory = $notificationDao->getByAssoc(
				ASSOC_TYPE_SUBMISSION,
				$submission->getId(),
				$editorStageAssignment->getUserId(),
				NOTIFICATION_TYPE_VGWORT_ERROR,
				$pixelTag->getContextId()
			);
			if ($notificationFactory->wasEmpty()) {
				$notificationMgr = new NotificationManager();
				$notificationMgr->createNotification(
					$request,
					$editorStageAssignment->getUserId(),
					NOTIFICATION_TYPE_VGWORT_ERROR,
					$pixelTag->getContextId(),
					ASSOC_TYPE_SUBMISSION,
					$submission->getId(),
					NOTIFICATION_LEVEL_NORMAL,
					null,
					true
				);
			}
		}
	}

	function uploadJSON($url, $username, $password, $data) {
	    $curl = curl_init();

	    curl_setopt($curl, CURLOPT_UPLOAD, true);
	    curl_setopt($curl, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/json']);
	    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	    curl_setopt($curl, CURLOPT_URL, $url);
	    curl_setopt($curl, CURLOPT_POST, 1);
	    curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
	    curl_setopt($curl, CURLOPT_USERPWD, "$username:$password");
	    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
	    curl_setopt($curl, CURLOPT_VERBOSE, true);

	    $filesDir = realpath(Config::getVar('files', 'files_dir'));
	    $err_fh = fopen(join(DIRECTORY_SEPARATOR, [$filesDir, 'curl_err.log']), 'a+');
	    curl_setopt($curl, CURLOPT_STDERR, $err_fh);
		$response = curl_exec($curl);

	    $curlError = curl_error($curl);
	    if ($curlError) {
		$statusCode = $response['message']['errorcode'];
		$reasonPhrase = $response['message']['errormsg'];
	    }

		if (!empty($errors)) return [false, __('plugins.generic.vgWort.order.errorCode') . $reasonPhrase];
	    curl_close($curl);

	    //error_log("VGWORT: " . var_export(json_encode($response),true));
	    return [true, json_encode($response)];
	}

}

?>
