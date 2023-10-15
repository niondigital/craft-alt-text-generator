<?php

namespace dispositiontools\craftalttextgenerator\services;

use Craft;
use craft\elements\Asset as AssetElement;
use craft\helpers\DateTimeHelper;
use craft\helpers\Queue;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use dispositiontools\craftalttextgenerator\AltTextGenerator;
use dispositiontools\craftalttextgenerator\jobs\RequestHumanAltTextReview as RequestHumanAltTextReviewJob;
use dispositiontools\craftalttextgenerator\jobs\UpdateAssetWithGeneratedAltText as UpdateAssetWithGeneratedAltTextJob;
use dispositiontools\craftalttextgenerator\jobs\RefreshImageDetails as RefreshImageDetailsJob;

use dispositiontools\craftalttextgenerator\models\AltTextAiApiCall as AltTextAiApiCallModel;
use dispositiontools\craftalttextgenerator\records\AltTextAiApiCall as AltTextAiApiCallRecord;
use yii\base\Component;

/**
 * Alt Text Ai Api service
 */
class AltTextAiApi extends Component
{
    /**
     * Stores apiCall to DB.
     *
     * @return model
     */
    public function saveApiCall(AltTextAiApiCallModel $model): AltTextAiApiCallModel
    {
        $isNew = !$model->id;
        
        if (!$isNew) {
            $record = AltTextAiApiCallRecord::findOne(['id' => $model->id]);
        } else {
            $record = new AltTextAiApiCallRecord();
        }
            
            
        $fieldsToUpdate = [
                'requestUserId',
                'assetId',
                'siteId',
                'requestType',
                'dateRequest',
                'request',
                'dateResponse',
                'response',
                'originalAltText',
                'generatedAltText',
                'altTextSyncStatus',
                'humanResponse',
                'humanDateResponse',
                'humanRequest',
                'humanDateRequest',
                'humanGeneratedAltText',
                'humanAltTextSyncStatus',
                'humanRequestUserId',
                'requestId',
            ];
            
        foreach ($fieldsToUpdate as $handle) {
            if (property_exists($model, $handle)) {
                $record->$handle = $model->$handle;
            }
        }
        
        $record->validate();
        $model->addErrors($record->getErrors());
        

        $record->save(false);

        if ($isNew) {
            $model->id = $record->id;
        }
        
        return  $model;
    }
    
    // AltTextGenerator::getInstance()->altTextAiApi->statsImagesWithAltText( );
    public function statsImagesWithAltText(): array
    {
        $assetsQuery = AssetElement::find()->kind('image')->hasAlt(false);
        $assets = $assetsQuery->all();
       
        $imagesWithoutAltText = count($assets);
       
       
        $assetsQuery = AssetElement::find()->kind('image')->hasAlt(true);
        $assets = $assetsQuery->all();
        
        $imagesWithAltText = count($assets);
        
        return [
           'imagesWithoutAltText' => $imagesWithoutAltText,
           'imagesWithAltText' => $imagesWithAltText,
        ];
    }
    
    
    /*
         Called from the review page in the CP
    */
    
    // AltTextGenerator::getInstance()->updateApiCalls( $assets );
    public function updateApiCalls($assets): ?bool
    {
        $currentUser = Craft::$app->getUser()->getIdentity();
        if ($currentUser) {
            $currentUserId = $currentUser->id;
        } else {
            $currentUserId = null;
        }
       
        if ($assets) {
            foreach ($assets as $apiCallId => $type) {
                switch ($type) {
                     
                     case "generatedSync":
                        
                              $AltTextAiApiCallModel = $this->getApiCallById($apiCallId);
                                if ($AltTextAiApiCallModel) {
                                    $AltTextAiApiCallModel->altTextSyncStatus = "syncing";
                                    $AltTextAiApiCallModel = $this->saveApiCall($AltTextAiApiCallModel);
                                    Queue::push(new UpdateAssetWithGeneratedAltTextJob([
                                         "apiCallId" => $AltTextAiApiCallModel->id,
                                         "type" => "generated",
                                     ]));
                                     
                                    unset($AltTextAiApiCallModel);
                                }
                        
                        break;
                     
                     case "originalSync":
                        
                              $AltTextAiApiCallModel = $this->getApiCallById($apiCallId);
                                if ($AltTextAiApiCallModel) {
                                    Queue::push(new UpdateAssetWithGeneratedAltTextJob([
                                         "apiCallId" => $AltTextAiApiCallModel->id,
                                         "type" => "original",
                                     ]));
                                     
                                    unset($AltTextAiApiCallModel);
                                }
                     
                        break;
                     
                     case "humanGeneratedSync":
                        
                           $AltTextAiApiCallModel = $this->getApiCallById($apiCallId);
                             if ($AltTextAiApiCallModel) {
                                 $AltTextAiApiCallModel->altTextSyncStatus = "syncing";
                                 $AltTextAiApiCallModel = $this->saveApiCall($AltTextAiApiCallModel);
                                 
                                 Queue::push(new UpdateAssetWithGeneratedAltTextJob([
                                      "apiCallId" => $AltTextAiApiCallModel->id,
                                      "type" => "humanGenerated",
                                  ]));
                                  
                                 unset($AltTextAiApiCallModel);
                             }
                           
                        
                             
                        break;
                        
                    case "requestRefresh":
                        
                                $AltTextAiApiCallModel = $this->getApiCallById($apiCallId);
                                 if ($AltTextAiApiCallModel) {
                                     $AltTextAiApiCallModel->altTextSyncStatus = "refreshing";
                                     $AltTextAiApiCallModel = $this->saveApiCall($AltTextAiApiCallModel);
                                     
                                     Queue::push(new RefreshImageDetailsJob([
                                          "apiCallId" => $AltTextAiApiCallModel->id,
                                          "humanRequestUserId" =>  $currentUserId
                                      ]));
                                      
                                     unset($AltTextAiApiCallModel);
                                 }
                        
                        break;
                        
                     case "requestHuman":
                        
                           $AltTextAiApiCallModel = $this->getApiCallById($apiCallId);
                             if ($AltTextAiApiCallModel) {
                                 Queue::push(new RequestHumanAltTextReviewJob([
                                      "apiCallId" => $AltTextAiApiCallModel->id,
                                      "humanRequestUserId" => $currentUserId,
                                  ]));
                                  
                                 unset($AltTextAiApiCallModel);
                             }
                           
                    
                        break;
                        
                     case "delete":
                           
                           $record = AltTextAiApiCallRecord::findOne(['id' => $apiCallId]);
                           $record->softDelete();
                           unset($record);
                     
                        break;
                        
                     case "cancel":
                        // don't do anything
                        break;
                     
                  }
            }
               
            // update the plugin badge count
            AltTextGenerator::getInstance()->altTextAiApi->refreshNumberOfAltTextsToReview();
        }
      
         
        return true;
    }
    
    
    public function getAssetAndCheck($assetId)
    {
        // get the element
                
        $asset = AssetElement::find()->id($assetId)->one();
             
        if (!$asset) {
            return false;
        }
         
        $suitability = $this->checkAssetSuitability($asset);
           
        if (!$suitability['success']) {
            return false;
        }
           
        return  $asset;
    }
    
    
    // AltTextGenerator::getInstance()->refreshImageDetailsFromAltTextAi( $apiCallId );
    public function refreshImageDetailsFromAltTextAi($apiCallId, $requestUserId = false)
    {
        $AltTextAiApiCallModel = $this->getApiCallById($apiCallId);
        
        if (!$AltTextAiApiCallModel) {
            return false;
        }
        
        
        $requestId = $AltTextAiApiCallModel->requestId;
        if (!$requestId) {
            $requestId = $AltTextAiApiCallModel->id;
        }
        
        
        
        $imageDetails = $this->makeGetImageByAssetIdApiCall($requestId);
        
        if(!$imageDetails )
        {
            return false;
        }
        
        $imageDetailsArray = json_decode($imageDetails, true);
        if(! $imageDetailsArray  )
        {
            return false;
        }
        
        $asset = AssetElement::find()->id($AltTextAiApiCallModel->assetId)->one();
              
        if (!$asset) {
            return false;
        }
        
        $updateModel = false;
        if($imageDetailsArray['alt_text'])
        {
            if(
                $AltTextAiApiCallModel->humanDateRequest 
                && $imageDetailsArray['alt_text'] != $AltTextAiApiCallModel->generatedAltText 
                && $imageDetailsArray['alt_text'] != $AltTextAiApiCallModel->humanGeneratedAltText 
            )
            {
                $AltTextAiApiCallModel->humanGeneratedAltText = $imageDetailsArray['alt_text'];
                $AltTextAiApiCallModel->humanAltTextSyncStatus = "review";
                $AltTextAiApiCallModel->altTextSyncStatus = "review";
                $updateModel = true;
            }
            elseif($imageDetailsArray['alt_text'] != $AltTextAiApiCallModel->generatedAltText) 
            {
                $AltTextAiApiCallModel->generatedAltText = $imageDetailsArray['alt_text'];
                $AltTextAiApiCallModel->altTextSyncStatus = "review";
                $updateModel = true;
            }
            
            if($updateModel)
            {
                $this->saveApiCall($AltTextAiApiCallModel);
            }
            
            
        }
        
        
        
        
        return true;
    }
    
    
    public function updateAssetWithGeneratedAltText($apiCallId, $type = "generated")
    {
        if (!$apiCallId) {
            return false;
        }
        $AltTextAiApiCallModel = $this->getApiCallById($apiCallId);
       
        if (!$AltTextAiApiCallModel) {
            return false;
        }
        
        if (!$AltTextAiApiCallModel->assetId) {
            return false;
        }
      
        // get Asset
        $asset = $this->getAssetAndCheck($AltTextAiApiCallModel->assetId);
        if (!$asset) {
            return false;
        }
      
        switch ($type) {
               case "generated":
                  
                     if ($AltTextAiApiCallModel->generatedAltText != "") {
                         $asset->alt = $AltTextAiApiCallModel->generatedAltText;
                         $success = Craft::$app->elements->saveElement($asset);
                         $AltTextAiApiCallModel->altTextSyncStatus = "synced";
                         $AltTextAiApiCallModel = $this->saveApiCall($AltTextAiApiCallModel);
                     }
                  
                  break;
                  
                  
               case "original":
               
                  if ($AltTextAiApiCallModel->originalAltText != "") {
                      $asset->alt = $AltTextAiApiCallModel->originalAltText;
                      $success = Craft::$app->elements->saveElement($asset);
                  }
               
                  break;
                  
               case "humanGenerated":
               
                  if ($AltTextAiApiCallModel->humanGeneratedAltText != "") {
                      $asset->alt = $AltTextAiApiCallModel->humanGeneratedAltText;
                      $success = Craft::$app->elements->saveElement($asset);
                      $AltTextAiApiCallModel->altTextSyncStatus = "synced";
                      $AltTextAiApiCallModel->humanGeneratedAltText = "synced";
                      $AltTextAiApiCallModel = $this->saveApiCall($AltTextAiApiCallModel);
                  }
               
                  break;
         }
      
        
        
        return true;
    }
    
    // AltTextGenerator::getInstance()->altTextAiApi->getApiCalls( );
    public function getApiCalls($criteria = null): array
    {
        $recordsQuery = AltTextAiApiCallRecord::find();
      
        if (array_key_exists('where', $criteria)) {
            $x = 0;
    
            foreach ($criteria['where'] as $criteriaItem => $criteriaValue) {
                $x++;
                if ($x == 1) {
                    $recordsQuery->where([$criteriaItem => $criteriaValue]);
                } else {
                    $recordsQuery->andWhere([$criteriaItem => $criteriaValue]);
                }
            }
            
            $recordsQuery->andWhere(["dateDeleted" => null]);
        }
         
        $records = $recordsQuery->all();
        
        $models = array();
       
        foreach ($records as $record) {
            $model = new AltTextAiApiCallModel($record->getAttributes());
            $models[] = $model;
        }
       
        return $models;
    }
    
    public function getApiCallById($id): ?AltTextAiApiCallModel
    {
        $record = AltTextAiApiCallRecord::findOne(['id' => $id]);
        
        if (!$record) {
            return null;
        }
        
        $model = new AltTextAiApiCallModel($record->getAttributes());
        return $model;
    }
    
    
    public function getApiCallByAssetId($id): ?AltTextAiApiCallModel
    {
        $record = AltTextAiApiCallRecord::findOne(['assetId' => $id]);
        
        if (!$record) {
            return null;
        }
        
        $model = new AltTextAiApiCallModel($record->getAttributes());
        return $model;
    }
    
    
    // AltTextGenerator::getInstance()->altTextAiApi->checkGetApiCallById( $id );
    public function checkGetApiCallById($id)
    {
        $model = $this->getApiCallById($id);
        
        print_r($model);
    }
    
    
    public function checkAssetSuitability($asset): array
    {
        
        // is the element type webp / png / jpg / BMP
        if (!$asset->kind == "image") {
            return [
                'error' => true,
                'errorMessage' => "Not an image",
                'success' => false,
            ];
        }
        
        
        if (!in_array(strtolower($asset->extension), ['jpg', 'gif', 'png', 'webp', 'jpeg'])) {
            return [
                'error' => true,
                'errorMessage' => "Not right kind of image",
                'success' => false,
            ];
        }
        
        
        // check if the image is less than 10mb
          
        if ($asset->size > 10000000) {
            return [
                'error' => true,
                'errorMessage' => "Image file size is over 10mb",
                'success' => false,
            ];
        }
        
        // check if the image is over 50 x 50
        if ($asset->width < 51 || $asset->height < 51) {
            return [
                'error' => true,
                'errorMessage' => "Image needs to over 50 x 50 pixels",
                'success' => false,
            ];
        }
        
        
        return [
            'error' => false,
            'errorMessage' => "",
            'success' => true,
        ];
    }
    
    
    public function callAltTextAiHumanReviewAipi($apiCallId, $humanRequestUserId = null)
    {
        // get apiCall model
        //
        
        $AltTextAiApiCallModel = $this->getApiCallById($apiCallId);
        
        if (!$AltTextAiApiCallModel) {
            //echo "no model";
            return true;
        }
        
        $asset = AssetElement::find()->id($AltTextAiApiCallModel->assetId)->one();
              
        if (!$asset) {
            $return = [
                  'error' => true,
                  'errorMessage' => "No asset",
              ];
            return $return;
        }
          
        $suitability = $this->checkAssetSuitability($asset);
         
        if (!$suitability['success']) {
            return $suitability;
        }
        
        $AltTextAiApiCallModel->humanRequestUserId = $humanRequestUserId;
        $AltTextAiApiCallModel->humanAltTextSyncStatus = "requesting";
        $AltTextAiApiCallModel = $this->saveApiCall($AltTextAiApiCallModel);
        
        $requestId = $AltTextAiApiCallModel->requestId;
        if (!$requestId) {
            $requestId = $AltTextAiApiCallModel->id;
        }
        
        $resultsJson = $this->requestHumanReviewApiCall($requestId);
    

        if ($resultsJson) {
            $AltTextAiApiCallModel->humanAltTextSyncStatus = "requested";
            $AltTextAiApiCallModel->humanRequest = json_encode($resultsJson);
            $AltTextAiApiCallModel = $this->saveApiCall($AltTextAiApiCallModel);
        }
    }
    
    
    // AltTextGenerator::getInstance()->altTextAiApi->callAltTextAiAipi( $assetId );
    public function callAltTextAiAipi($assetId, $requestType = "No type", $async = false, $requestUserId = false)
    {
        
        // see if the asset has already been called
        // we are not allowed to recall it. so we will set it to review again
        
        $AltTextAiApiCallModel = $this->getApiCallByAssetId($assetId);
        
        if($AltTextAiApiCallModel)
        {
            
            $AltTextAiApiCallModel->altTextSyncStatus = "refreshing";
            $AltTextAiApiCallModel = $this->saveApiCall($AltTextAiApiCallModel);

            Queue::push(new RefreshImageDetailsJob([
                  "apiCallId" => $AltTextAiApiCallModel->id,
                  "humanRequestUserId" =>  $requestUserId
              ]));
            return true;
        }
        
        
        // get the element       
        $asset = AssetElement::find()->id($assetId)->one();
            
        if (!$asset) {
            return [
                'error' => true,
                'errorMessage' => "No asset",
            ];
        }
        
        $suitability = $this->checkAssetSuitability($asset);
          
        if (!$suitability['success']) {
            return $suitability;
        }
        
        // check if we have enough credits
        // if we don't have credits pause this...
        
        
        // create an rquestId
        // Craft::$app->getSystemUid()
        
        $requestId = StringHelper::toKebabCase(Craft::$app->getSystemName()) . "_" . $asset->uid . "_" . $asset->id;
        
        // create a call model
        
        $assetUrl = $asset->url;
        
        $AltTextAiApiCallModel = new AltTextAiApiCallModel();
        
        $AltTextAiApiCallModel->assetId = $asset->id;
        $AltTextAiApiCallModel->requestId = $requestId;
        $AltTextAiApiCallModel->requestType = $requestType;
        $AltTextAiApiCallModel->dateRequest = DateTimeHelper::currentUTCDateTime();
        $AltTextAiApiCallModel->altTextSyncStatus = "called";
        $AltTextAiApiCallModel->originalAltText = $asset->alt;
        
        if ($requestUserId) {
            $AltTextAiApiCallModel->requestUserId = $requestUserId;
        }
        
        // do the call
        
        $settings = AltTextGenerator::getInstance()->getSettings();
        $webHookParams = [
            'securityCode' => $settings->securityCode,
        ];
        $webhookUrl = UrlHelper::actionUrl('alt-text-generator/alt-text-ai-webhook/web-hook', $webHookParams, null, false);
        $AltTextAiApiCallModel = $this->saveApiCall($AltTextAiApiCallModel);
        
        $imageUrl = UrlHelper::siteUrl($assetUrl);
        
        $callDetails = [
            "image" => [
                "url" => $imageUrl,
                "asset_id" => $AltTextAiApiCallModel->requestId,
                "metadata" => [
                   "assetId" => $asset->id,
                   "apiCallId" => $AltTextAiApiCallModel->id,
                ],
            ],
            "async" => (bool) $settings->asyncApi,
        ];
        
        if ($settings->asyncApi) {
            $callDetails['webhook_url'] = $webhookUrl;
        }
        
        $AltTextAiApiCallModel->request = json_encode($callDetails);
        $AltTextAiApiCallModel = $this->saveApiCall($AltTextAiApiCallModel);
        

        $resultsJson = $this->makeCreateImageApiCall($callDetails);

        
        if (!$async) {
            $AltTextAiApiCallModel->response = $resultsJson;
            $AltTextAiApiCallModel->dateResponse = DateTimeHelper::currentUTCDateTime();
            $AltTextAiApiCallModel->altTextSyncStatus = "received";
            
            
            $resultsArray = json_decode($resultsJson, true);
            $newAltText = $resultsArray['alt_text'];
            $AltTextAiApiCallModel->generatedAltText = $newAltText;
            
            if ($settings->useAltTextImmediately) {
                $asset->alt = $newAltText;
                $success = Craft::$app->elements->saveElement($asset);
                $AltTextAiApiCallModel->altTextSyncStatus = "synced";
            } else {
                $AltTextAiApiCallModel->altTextSyncStatus = "review";
            }
          
            $AltTextAiApiCallModel = $this->saveApiCall($AltTextAiApiCallModel);
        }
        
        
        
        
        // what is they get a 429 error
        
        
        // what if it already exists?
        // then we need to update the asset straight away
    }
    
    
    
    
    // AltTextGenerator::getInstance()->altTextAiApi->processAltTextAiWebhook( $hookData );
    public function processAltTextAiWebhook($hookData)
    {
        // this saves the hook data to the api call hook
        $settings = AltTextGenerator::getInstance()->getSettings();
        
        $responseArray = json_decode($hookData, true);
        
      
        if ($responseArray && array_key_exists("event", $responseArray)) {
            if ($responseArray['event'] == "uploaded") {
                $this->processAltTextAiWebhookUploaded($hookData);
            }
            
            if ($responseArray['event'] == "reviewed") {
                $this->processAltTextAiWebhookReviewed($hookData);
            }
        }
        unset( $hookData );
        unset( $responseArray );
    }
    
    
    public function processAltTextAiWebhookUploaded($hookData)
    {
        
        $responseArray = json_decode($hookData, true);
        
        if (is_array($responseArray) && array_key_exists("data", $responseArray) && array_key_exists("images", $responseArray['data'])) {
            foreach ($responseArray['data']['images'] as $imageResponse) {
                if (array_key_exists('asset_id',  $imageResponse)) {
                    $apiCallId = $imageResponse['asset_id'];
                    if (array_key_exists('metadata',  $imageResponse) && array_key_exists('apiCallId',  $imageResponse['metadata'])) {
                        $apiCallId = $imageResponse['metadata']['apiCallId'];
                    }
                       
                    $AltTextAiApiCallModel = $this->getApiCallById($apiCallId);
                       
                    if ($AltTextAiApiCallModel) {
                        $AltTextAiApiCallModel->dateResponse = DateTimeHelper::currentUTCDateTime();
                        $AltTextAiApiCallModel->response = $hookData;
                           
                        $AltTextAiApiCallModel->generatedAltText = $imageResponse['alt_text'];
                           
                        if ($settings->useAltTextImmediately) {
                            $asset = AssetElement::find()->id($AltTextAiApiCallModel->assetId)->one();
                            if ($asset) {
                                $asset->alt = $imageResponse['alt_text'];
                                $success = Craft::$app->elements->saveElement($asset);
                                $AltTextAiApiCallModel->altTextSyncStatus = "synced";
                                
                                unset( $asset );
                            } else {
                                $AltTextAiApiCallModel->altTextSyncStatus = "review";
                            }
                        } else {
                            $AltTextAiApiCallModel->altTextSyncStatus = "review";
                        }
                           
                           
                        $this->saveApiCall($AltTextAiApiCallModel);
                        unset( $AltTextAiApiCallModel );
                    }
                }
            }
        }
        
       
        unset( $responseArray );
    }
    
    
    public function processAltTextAiWebhookReviewed($hookData)
    {
        $responseArray = json_decode($hookData, true);
        
        if (is_array($responseArray) && array_key_exists("data", $responseArray) && array_key_exists("images", $responseArray['data'])) {
            foreach ($responseArray['data']['images'] as $imageResponse) {
                if (array_key_exists('asset_id',  $imageResponse)) {
                    $apiCallId = $imageResponse['asset_id'];
                    if (array_key_exists('metadata',  $imageResponse) && array_key_exists('apiCallId',  $imageResponse['metadata'])) {
                        $apiCallId = $imageResponse['metadata']['apiCallId'];
                    }
                       
                    $AltTextAiApiCallModel = $this->getApiCallById($apiCallId);
                       
                    if ($AltTextAiApiCallModel) {
                        $AltTextAiApiCallModel->humanDateResponse = DateTimeHelper::currentUTCDateTime();
                        $AltTextAiApiCallModel->humanResponse = $hookData;
                           
                        $AltTextAiApiCallModel->humanGeneratedAltText = $imageResponse['alt_text'];
                           
                        if ($settings->useAltTextImmediately) {
                            $asset = AssetElement::find()->id($AltTextAiApiCallModel->assetId)->one();
                            if ($asset) {
                                $asset->alt = $imageResponse['alt_text'];
                                $success = Craft::$app->elements->saveElement($asset);
                                $AltTextAiApiCallModel->altTextSyncStatus = "synced";
                                $AltTextAiApiCallModel->humanAltTextSyncStatus = "synced";
                                unset( $asset );
                            } else {
                                $AltTextAiApiCallModel->altTextSyncStatus = "review";
                                $AltTextAiApiCallModel->humanAltTextSyncStatus = "review";
                            }
                        } else {
                            $AltTextAiApiCallModel->altTextSyncStatus = "review";
                            $AltTextAiApiCallModel->humanAltTextSyncStatus = "review";
                        }
                           
                           
                        $this->saveApiCall($AltTextAiApiCallModel);
                        unset( $AltTextAiApiCallModel );
                    }
                }
            }
        }
        
        unset( $responseArray );
    }
    
    
    
    
    public function getAltTextForUrl($url)
    {
        $callDetails = [
            "image" => [
                "url" => $url,
            ],
        ];
        $altTextApiResponse = $this->makeCreateImageApiCall($callDetails);
        
        return  $altTextApiResponse;
    }
    
    
  
    
   
    
    
    // AltTextGenerator::getInstance()->altTextAiApi->queueAllImages($generateForNoAltText, $generateForAltText);
    public function queueAllImages($generateForNoAltText = false , $generateForAltText = false)
    {
        $websiteUrl = rtrim(UrlHelper::baseSiteUrl(), "/");
                
        
        
        $currentUser = Craft::$app->getUser()->getIdentity();
        if ($currentUser) {
            $currentUserId = $currentUser->id;
        } else {
            $currentUserId = null;
        }
        
        $numberOfCredits = AltTextGenerator::getInstance()->altTextAiApi->getNumberOfAltTextApiCredits();
        $numberOfCredits = $numberOfCredits - 25;   
    
        $requestCount = 0;
        if ($generateForNoAltText) {
            $assetsQuery = AssetElement::find()->kind('image')->hasAlt(false);
            $assets = $assetsQuery->all();
            foreach ($assets as $asset) {
                
                $requestCount++;
                
                if($requestCount >= $numberOfCredits)
                {
                    continue;
                }
                
                
                $suitability = $this->checkAssetSuitability($asset);
                
                if (!$suitability['success']) {
                    continue;
                }
                
                Queue::push(new RequestAltTextJob([
                    "assetId" => $asset->id,
                    "requestUserId" => $currentUserId,
                    "actionType" => "Queue all",
                ]));
                
                unset($suitability);
            }
            unset($assets);
        }
        
        if ($generateForAltText) {
            $assetsQuery = AssetElement::find()->kind('image')->hasAlt(true);
            $assets = $assetsQuery->all();
            foreach ($assets as $asset) {
                
                $requestCount++;
                
                if($requestCount >= $numberOfCredits)
                {
                    continue;
                }
                
                $suitability = $this->checkAssetSuitability($asset);
                
                if (!$suitability['success']) {
                    continue;
                }
                
                Queue::push(new RequestAltTextJob([
                    "assetId" => $asset->id,
                    "requestUserId" => $currentUserId,
                    "actionType" => "Queue all",
                ]));
                
                unset($suitability);
            }
            unset($assets);
        }
        
        
        /*
        echo $websiteUrl ;
        echo "\n";
        echo "\n";
        */
        
        return true;
    }
    
    
    
    public function makeGetImageByAssetIdApiCall($asset_id)
    {

        $url = "https://alttext.ai/api/v1/images/".$asset_id;
    
        $settings = AltTextGenerator::getInstance()->getSettings();
        $apiKey = $settings->apiKey;
        $options = array(
          'http' => array(
            'method' => "GET",
            'header' => "X-API-Key: " . $apiKey . "\n" .
                      "Content-Type: application/json",
            "ignore_errors" => true,
          ),
        );
        
        $context = stream_context_create($options);
        $jsonResponse = file_get_contents($url, false, $context);
        
        return $jsonResponse;
    }
    
    public function makeCreateImageApiCall($callDetailsArray)
    {
        $callDetailsJson = json_encode($callDetailsArray);
        $url = "https://alttext.ai/api/v1/images";

        $settings = AltTextGenerator::getInstance()->getSettings();
        $apiKey = $settings->apiKey;
        $options = array(
          'http' => array(
            'method' => "POST",
            'header' => "X-API-Key: " . $apiKey . "\n" .
                      "Content-Type: application/json",
            'content' => $callDetailsJson,
            "ignore_errors" => true,
          ),
        );
        
        $context = stream_context_create($options);
        $jsonResponse = file_get_contents($url, false, $context);
        
        return $jsonResponse;
    }
    
    
    public function requestHumanReviewApiCall($asset_id)
    {
        $url = "https://alttext.ai/api/v1/images/" . $asset_id . "/augment";
    
        $settings = AltTextGenerator::getInstance()->getSettings();
        $apiKey = $settings->apiKey;
        $options = array(
           'http' => array(
             'method' => "POST",
             'header' => "X-API-Key: " . $apiKey . "\n" .
                       "Content-Type: application/json",
             "ignore_errors" => true,
           ),
         );
         
        $context = stream_context_create($options);
        $jsonResponse = file_get_contents($url, false, $context);
         
        return $jsonResponse;
    }
    
    
    public function makeAccountApiCall()
    {
        $url = "https://alttext.ai/api/v1/account";
       
        $settings = AltTextGenerator::getInstance()->getSettings();
        $apiKey = $settings->apiKey;

        if(! $apiKey)
        {
            return false;
        }
       
        $options = array(
         'http' => array(
           'method' => "GET",
           'header' => "X-API-Key:  " . $apiKey . "\n",
         ),
       );
       
        $context = stream_context_create($options);
        $jsonResponse = file_get_contents($url, false, $context);
       
        return $jsonResponse;
    }
    
    // AltTextGenerator::getInstance()->altTextAiApi->getNumberOfAltTextApiCredits();
    public function getNumberOfAltTextApiCredits()
    {
        $creditsCacheKey = "altTextApiCreditsCount";
        
        // Get the cached value, but if it doesn't exist, re-do the work
        // and store it for 60 seconds
        $credits = Craft::$app->cache->getOrSet($creditsCacheKey, function() {
            // Some expensive work goes in here
            
            return $this->refreshNumberOfAltTextApiCredits();
        }, 60 * 60);
        
        return $credits;
    }
    
    // AltTextGenerator::getInstance()->altTextAiApi->getNumberOfAltTextsToReview();
    public function getNumberOfAltTextsToReview()
    {
        $altTextNumberOfItemsToReview = Craft::$app->cache->getOrSet("altTextNumberOfItemsToReview", function() {
            // Some expensive work goes in here
             
            $reviewCalls = AltTextGenerator::getInstance()->altTextAiApi->getApiCalls([
                 'where' =>
                 [
                     'altTextSyncStatus' => ['review'],
                 ],
             ]);
             
            $numberOfItemsToReview = 0;
            if (is_countable($reviewCalls)) {
                $numberOfItemsToReview = count($reviewCalls);
            }
             
            return $numberOfItemsToReview;
        }, 60 * 60);
         
        return  $altTextNumberOfItemsToReview;
    }
    
    // AltTextGenerator::getInstance()->altTextAiApi->refreshNumberOfAltTextsToReview();
    public function refreshNumberOfAltTextsToReview(): int
    {
        $reviewCalls = AltTextGenerator::getInstance()->altTextAiApi->getApiCalls([
            'where' =>
            [
                'altTextSyncStatus' => ['review'],
            ],
        ]);
        
        $numberOfItemsToReview = 0;
        if (is_countable($reviewCalls)) {
            $numberOfItemsToReview = count($reviewCalls);
        }
        
        Craft::$app->cache->set("altTextNumberOfItemsToReview", $numberOfItemsToReview, 60 * 60);
        return $numberOfItemsToReview;
    }
    
    
    // AltTextGenerator::getInstance()->altTextAiApi->refreshNumberOfAltTextApiCredits();
    public function refreshNumberOfAltTextApiCredits()
    {
        $accountJson = $this->makeAccountApiCall();
        
        $accountArray = json_decode($accountJson, true);
        $usage = 0;
        $usage_limit = 0;
        if ($accountArray) {
            if (array_key_exists('usage', $accountArray)) {
                $usage = $accountArray['usage'];
            }
            
            if (array_key_exists('usage_limit', $accountArray)) {
                $usage_limit = $accountArray['usage_limit'];
            }
        }
        
        $credits = $usage_limit - $usage;
        
        $creditsCacheKey = "altTextApiCreditsCount";
        
        Craft::$app->cache->set($creditsCacheKey, $credits, 60 * 60);
        
        return $credits;
    }
}
