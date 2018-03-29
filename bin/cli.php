<?php
/**
 * php cli.php [--options] [command] [arguments]
 */
require_once ("./vendor/autoload.php");
date_default_timezone_set( 'UTC' );

$options = getopt('', [
    'region::', 'translation::', 'translation_queue::', 'translation_project::',
    'dynamo_endpoint::', 'dynamo_version::',
    'provider::', 'limit::', 'default_language::',
    'oth_pubkey::', 'oth_secret::', 'oth_sandbox::', 'oth_note::',
    'oth_tag::', 'oth_expertise::', 'oth_callback::',
    'gct_key::', 'gct_project::', 'namespace::', 'message_file::'
]);

$defaultLanguage = empty($options['default_language']) ? "en" : $options['default_language'];
$namespace = empty($options['namespace']) ? null : $options['namespace'];

/**
 * Check configuration
 */
if (empty($options['region'])) {
    echo "Please specify AWS region\n";
    exit;
}

if (empty($options['translation'])) {
    echo "Please specify the name of translation table\n";
    exit;
}

if (empty($options['translation_queue'])) {
    echo "Please specify the name of translation_queue table\n";
    exit;
}

if (empty($options['translation_project'])) {
    echo "Please specify the name of translation_project table\n";
    exit;
}

$dynamoVersion = empty($options['dynamo_version']) ? "2012-08-10" : $options['dynamo_version'];
$dynamoEndpoint = empty($options['dynamo_endpoint']) ? null : $options['dynamo_endpoint'];

$translationConfig = [
    "name" => $options['translation'],
    "region" => $options['region'],
    "version" => $dynamoVersion,
    "endpoint" => $dynamoEndpoint,
];
RW\Models\Translation::init($translationConfig);

$translationQueueConfig = [
    "name" => $options['translation_queue'],
    "region" => $options['region'],
    "version" => $dynamoVersion,
    "endpoint" => $dynamoEndpoint,
];
RW\Models\TranslationQueue::init($translationQueueConfig);

$translationProjectConfig = [
    "name" => $options['translation_project'],
    "region" => $options['region'],
    "version" => $dynamoVersion,
    "endpoint" => $dynamoEndpoint,
];
RW\Models\TranslationProject::init($translationProjectConfig);

$oht = [
    "pubkey" => !empty($options['oth_pubkey']) ? $options['oth_pubkey'] : "",
    "secret" => !empty($options['oth_secret']) ? $options['oth_secret'] : "",
    "sandbox" => !empty($options['oth_sandbox']) ? filter_var($options['oth_sandbox'], FILTER_VALIDATE_BOOLEAN) : false,
    "note" => !empty($options['oth_note']) ? $options['oth_note'] : "PLEASE DO NOT TRANSLATE any texts enclosed with 'curly brackets {}', '%s' notations and xml/html attributes. Always use formal language if applicable.",
    "expertise" => !empty($options['oth_expertise']) ? $options['oth_expertise'] : "",
    "tag" => !empty($options['oth_tag']) ? $options['oth_tag'] : "",
];

$gct = [
    "key" => !empty($options['gct_key']) ? $options['gct_key'] : "",
    "project" => !empty($options['gct_project']) ? $options['gct_project'] : "",
];

$numOfOptions = count($options);
$action = $argv[$numOfOptions+1];

/**
 * Using Live Google Cloud Translation
 */
if (!empty($gct) && !empty($gct['project']) && !empty($gct['key'])) {
    \RW\Services\GoogleCloudTranslation::init($gct['project'], $gct['key']);
}

/**
 * Code started.
 */
switch ($action) {
    case "init":
        echo "Install translation table name: [{$options['translation']}] in region: [{$options['region']}] ...";
        $schema = RW\Models\Translation::$schema;
        $schema['TableName'] = \RW\Models\Translation::$table;
        \RW\Models\Translation::$client->createTable($schema);
        echo "done\n";

        echo "Install translation queue table name: [{$options['translation_queue']}] in region: [{$options['region']}] ...";
        $schema = RW\Models\TranslationQueue::$schema;
        $schema['TableName'] = \RW\Models\TranslationQueue::$table;
        \RW\Models\TranslationQueue::$client->createTable($schema);
        echo "done\n";

        echo "Install translation project table name: [{$options['translation_project']}] in region: [{$options['region']}] ...";
        $schema = RW\Models\TranslationProject::$schema;
        $schema['TableName'] = \RW\Models\TranslationProject::$table;
        \RW\Models\TranslationProject::$client->createTable($schema);
        echo "done\n";
        break;
    case "sync":
        if (empty($options['message_file'])) {
            echo "Sync only work with message file";
        }

        if (!file_exists($options['message_file'])) {
            echo "Message file does not exists";
        }

        if (empty($options['provider']) || ($options['provider'] != 'OHT' && $options['provider'] != 'GCT')) {
            echo "Please specify the translation provider. We current support OHT and GCT. \n";
            exit;
        }

        $targetProvider = $options['provider'];
        $limit = !empty($options['limit']) && $options['limit'] > 1 ? $options['limit'] : 100;
        $targetLanguages = array_slice($argv, count($options) + 2);
        if (empty($targetLanguages)) {
            echo "Please specify the target language. \n";
            exit;
        }

        $messageBlocks = \Symfony\Component\Yaml\Yaml::parse(file_get_contents($options['message_file']));
        $messages = [];
        foreach ($messageBlocks as $k => $blocks) {
            foreach ($blocks as $block) {
                $id = \RW\Models\Translation::idFactory($defaultLanguage, $block['text'], $namespace);
                $messages[$id] = $block['text'];
            }
        }

        foreach ($targetLanguages as $targetLanguage) {
            sync($namespace, $messages, $targetLanguage, $targetProvider, $limit);
        }

        break;
    case "add":
        $projectIds = array_slice($argv, count($options) + 2);
        if (empty($projectIds)) {
            $projects = RW\Models\TranslationProject::getProjectsByStatus(RW\Models\TranslationProject::STATUS_PENDING);
        } else {
            $projects = RW\Models\TranslationProject::getProjectsByIds($projectIds);
        }

        foreach ($projects as $project) {
            echo "Starting processing project: {$project->getId()} \n";
            /** @var $translationProjectItem RW\Models\TranslationProject */
            $translationProjectItem = RW\Models\TranslationProject::getById($project->getId());
            if (empty($translationProjectItem) || $translationProjectItem->getStatus() != RW\Models\TranslationProject::STATUS_PENDING) {
                echo "The project does not exists or the status is not pending.\n";
                continue;
            }
            switch ($translationProjectItem->getProvider()) {
                case RW\Models\TranslationProject::PROVIDER_ONE_HOUR_TRANSLATION:
                    handleOHTProject($defaultLanguage, $translationProjectItem, $oht);
                    break;
                default:
                    echo "Unknown service provider\n";
                    continue;
            }
            echo "done\n";
        }
        break;
    case "status":
        $projectIds = array_slice($argv, count($options) + 2);
        if (empty($projectIds)) {
            $projects = RW\Models\TranslationProject::getProjectsByStatus(array(RW\Models\TranslationProject::STATUS_PENDING,
                RW\Models\TranslationProject::STATUS_IN_PROGRESS));
        } else {
            $projects = RW\Models\TranslationProject::getProjectsByIds($projectIds);
        }

        /** @var $project RW\Models\TranslationProject */
        foreach ($projects as $project) {
            $projectId = $project->getId();
            $status = $project->getStatus();

            if ($status == RW\Models\TranslationProject::STATUS_PENDING) {
                echo "Project: [{$projectId}] is [{$status}]. Please run command [add] and [commit] to submit to service provider.\n";
            } else {
                if (empty($oht['pubkey']) || empty($oht['secret'])) {
                    throw new InvalidArgumentException("Unable to continue OTH project without keys.");
                }

                $projectData = $project->getProjectData();
                $oneHourTranslation = new RW\Services\OneHourTranslation($oht['pubkey'], $oht['secret'], $oht['sandbox']);
                $result = $oneHourTranslation->getProjectStatus($projectData['project_id']);

                $status = "unknown";
                if (!empty($result->results->project_status_code)){
                    switch ($result->results->project_status_code) {
                        case "in_progress":
                            $status = "Translating in progress";
                            break;
                        case "signed":
                            $status = "Translation ready to commit";
                            break;
                        case "pending":
                            $status = "Waiting for a translator";
                            break;
                    }
                }
                echo "Project: [{$projectId}]. OHT Status: [{$status}]\n";
            }
        }
        break;

    case "commit":
        $projectIds = array_slice($argv, count($options) + 2);
        if (empty($projectIds)) {
            $projects = RW\Models\TranslationProject::getProjectsByStatus(RW\Models\TranslationProject::STATUS_IN_PROGRESS);
        } else {
            $projects = RW\Models\TranslationProject::getProjectsByIds($projectIds);
        }

        /** @var $project RW\Models\TranslationProject */
        foreach ($projects as $project) {
            if ($project->getProvider() === \RW\Models\TranslationProject::PROVIDER_GOOGLE_CLOUD_TRANSLATION) {
                echo "GCT project does not require commit as translation result is instantaneously.";
                continue;
            }

            $projectData = $project->getProjectData();
            $projectId = $project->getId();
            $status = $project->getStatus();

            if ($status == RW\Models\TranslationProject::STATUS_PENDING || $status == \RW\Models\TranslationProject::STATUS_COMPLETED) {
                echo "Unable to commit pending or completed project. Project: [{$projectId}]\n";
                continue;
            }

            if (empty($oht['pubkey']) || empty($oht['secret'])) {
                throw new InvalidArgumentException("Unable to continue OTH project without keys.");
            }
            $oneHourTranslation = new RW\Services\OneHourTranslation($oht['pubkey'], $oht['secret'], $oht['sandbox']);
            $result = $oneHourTranslation->getProjectStatus($projectData['project_id']);
            if (in_array($result->results->project_status_code, array('signed', 'completed'))) {
                echo "Ready to commit translated results into project: [{$projectId}]\n";
                $translatedResources = $result->results->resources->translations;
                foreach ($translatedResources as $targetOHTResources) {
                    $oneHourTranslation->oht->downloadResource($targetOHTResources, "/tmp/$targetOHTResources.xml", $projectData['project_id']);

                    $doc = new DOMDocument();
                    $doc->loadXML(file_get_contents("/tmp/$targetOHTResources.xml"));
                    $items = $doc->getElementsByTagName('t');

                    foreach ($items as $item) {
                        $queueId = $item->getAttribute('id');

                        $text =  $item->textContent;
                        $translationQueueItem = RW\Models\TranslationQueue::getById($queueId);
                        $translationQueueItem->setTargetResult($text);
                        $translationQueueItem->setModified(time());
                        $translationQueueItem->setStatus(RW\Models\TranslationQueue::STATUS_READY);
                        $translationQueueItem->save();

                        echo "  ===> Queue ID [$queueId] processed.\n";
                    }
                }

                $project->setStatus(RW\Models\TranslationProject::STATUS_COMPLETED);
                $project->setModified(time());
                $project->save();

                echo "\n Remember, you need to run [php cli.php push] to add translated texts to translation table.\n";
            } else {
                echo "Unable to commit unsigned project. Project: [{$projectId}]\n";
                continue;
            }
        }
        break;
    case "push":
        $queueItems = RW\Models\TranslationQueue::getQueueItemsByStatus(RW\Models\TranslationQueue::STATUS_READY);

        /** @var $queueItem RW\Models\TranslationQueue*/
        foreach ($queueItems as $queueItem) {
            $targetTranslationItem = \RW\Models\Translation::getById($queueItem->getId());

            if ($targetTranslationItem == null) {
                $targetTranslationItem = new RW\Models\Translation();
                $targetTranslationItem->setId($queueItem->getId());
                $targetTranslationItem->setLang($targetLanguage);
            }
            $itemNamespace = $queueItem->getNamespace();
            if (!empty($itemNamespace)) {
                $targetTranslationItem->setNamespace($queueItem->getNamespace());
            }
            $targetTranslationItem->setText($queueItem->getTargetResult());
            $targetTranslationItem->save();
            echo "Target ID: [$targetItemId] pushed. Language: {$targetTranslationItem->getLang()}.\n";

            $queueItem->setStatus(RW\Models\TranslationQueue::STATUS_COMPLETED);
            $queueItem->setModified(time());
            $queueItem->save();
        }
        echo count($queueItems) . " items pushed to translation table\n";
        break;
    default:
        echo "The action is not supported. [$action] \n";
        break;
}

/**
 * Translate
 *
 * @param $targetLanguage
 * @param $targetProvider
 * @param $limit
 */
function diff($defaultLanguage, $targetLanguage, $targetProvider, $limit)
{
    $lastEvaluatedKey = null;
    $projectItemCount = 0;
    $projectCount = 0;
    $projectId = null;

    echo "Source Language: [en]. Target Language: [$targetLanguage]. Target Provider: [$targetProvider]\n";

    $translationItems = RW\Models\Translation::getAllTextsByLanguage($defaultLanguage, $lastEvaluatedKey);
    /** @var $translationItemObj RW\Models\Translation */
    foreach ($translationItems as $translationItemObj) {
        //starting a new project
        if ($projectId === null || $projectItemCount % $limit == 0) {
            $projectId = RW\Models\TranslationProject::idFactory();
            $translationProjectObject = new RW\Models\TranslationProject();
            $translationProjectObject->setId($projectId);
            $translationProjectObject->setCreated(time());
            $translationProjectObject->setModified(time());
            $translationProjectObject->setStatus(RW\Models\TranslationProject::STATUS_PENDING);
            $translationProjectObject->setProvider($targetProvider);
            $translationProjectObject->setTargetLanguage($targetLanguage);
        }

        $translationQueueItemID = RW\Models\TranslationQueue::idFactory($translationItemObj->getId(), $targetLanguage, $targetProvider);
        $translationQueueItem = RW\Models\TranslationQueue::getById($translationQueueItemID);
        if (empty($translationQueueItem)) {
            $translationQueueItem = new RW\Models\TranslationQueue();
            $translationQueueItem->setId($translationQueueItemID);
            $translationQueueItem->setStatus(RW\Models\TranslationQueue::STATUS_PENDING);
            $translationQueueItem->setCreated(time());
            $translationQueueItem->setModified(time());
            $translationQueueItem->setProjectId($projectId);
            $translationQueueItem->setTargetId(\RW\Models\Translation::idFactory($targetLanguage, $translationItemObj->getText(), $translationItemObj->getNamespace()));
            $translationQueueItem->save();

            $projectItemCount++;
        }

        if ($projectItemCount > 0 && !empty($translationProjectObject)) {
            echo "  ====> Project [$projectId] created by using [$targetProvider]. Source Language: [en]. Target Language: [$targetLanguage].\n";
            $translationProjectObject->save();
            $translationProjectObject = null;
            $projectCount++;
        }
    }

    echo "A total of [$projectItemCount] items added to the translation queue. [$projectCount] projects has been created. \n";
}

/**
 * Handle OHT
 * @param $translationProjectItem RW\Models\TranslationProject
 */
function handleOHTProject($defaultLanguage, $translationProjectItem, $oht)
{
    if (empty($oht['pubkey']) || empty($oht['secret'])) {
        throw new InvalidArgumentException("Unable to continue OTH project without keys.");
    }

    /** @var $queuedItems \RW\Models\TranslationQueue */
    $queuedItems = RW\Models\TranslationQueue::getQueueItemsByProjectId($translationProjectItem->getId());

    $projectId = $translationProjectItem->getId();
    $targetLang = RW\Services\Languages::transformLanguageCodeToOTH($translationProjectItem->getTargetLanguage());
    $sourceLang = RW\Services\Languages::transformLanguageCodeToOTH($defaultLanguage);

    echo "Starting OHT translation project id: [$projectId]. Source Lang: [$sourceLang]. Target Lang: [$targetLang] \n";

    $dom = new DOMDocument('1.0', 'utf-8');
    $translations = $dom->createElement("translations");
    $translations->setAttribute("id", $projectId);
    $translations->setAttribute("source_language", $sourceLang);
    $translations->setAttribute("target_language", $targetLang);
    $dom->appendChild($translations);

    foreach ($queuedItems as $queuedItem) {
        $text = $queuedItem->getTargetId();

        $cdata = $dom->createCDATASection($text);
        $t = $dom->createElement("t");
        $t->appendChild($cdata);
        $t->setAttribute("id", $queuedItem->getId());
        $translations->appendChild($t);

        $displayText = substr($text, 0, 10) . "...";
        echo "  ===> Translate: sourceID: [{$queuedItem->getId()}]. Text: [$displayText]\n";
    }
    $dom->formatOutput = true;
    file_put_contents("/tmp/{$projectId}.xml", $dom->saveXML());
    chmod("/tmp/{$projectId}.xml", 0777);
    $oneHourTranslation = new RW\Services\OneHourTranslation($oht['pubkey'], $oht['secret'], $oht['sandbox']);
    $resourceId = $oneHourTranslation->uploadResourceFile("/tmp/{$projectId}.xml");

    $ohtExpertise = null;
    if (!empty($oht['expertise'])) {
        $ohtExpertise = $oht['expertise'];
    }
    $ohtCallback = "";
    if (!empty($oht['callback'])) {
        $ohtCallback = $oht['callback'];
    }
    $ohtNote = "";
    if (!empty($oht['note'])) {
        $ohtNote = $oht['note'];
    }
    $wordCount = 0;
    if (!empty($oht['wordCount'])) {
        $wordCount = $oht['wordCount'];
    }

    $projectData = $oneHourTranslation->createProject($projectId, $sourceLang, $targetLang, $resourceId, $ohtExpertise, $ohtCallback, $ohtNote, $wordCount);
    $translationProjectItem->setProjectData($projectData);
    $translationProjectItem->setModified(time());
    $translationProjectItem->setStatus(RW\Models\TranslationProject::STATUS_IN_PROGRESS);
    $translationProjectItem->save();

    if (!empty($oht['tag'])) {
        $oneHourTranslation->tagProject($projectData->project_id, $oht['tag']);
    }

    echo "  ===> Project created. OHT Project ID: [{$projectData->project_id}]. Cost of the project: [{$projectData->credits}]\n";
}

/**
 * Sync Message
 *
 * @param $namespace
 * @param $messages
 * @param $targetLanguage
 * @param $targetProvider
 * @param $limit
 * @throws Exception
 */
function sync($namespace, $sourceMessages, $targetLanguage, $targetProvider, $limit)
{
    echo "Source Language: [en]. Target Language: [$targetLanguage]. Target Provider: [$targetProvider]\n";

    //generate new ids
    $messages = [];
    foreach ($sourceMessages as $message) {
        $id = \RW\Models\Translation::idFactory($targetLanguage, $message, $namespace);
        $messages[$id] = $message;
    }

    $alreadyTranslatedMessageObjects = \RW\Models\Translation::getAllTextsByLanguage($targetLanguage);
    $alreadyTranslatedMessages = [];
    foreach ($alreadyTranslatedMessageObjects as $alreadyTranslatedMessageObject) {
        $alreadyTranslatedMessages[$alreadyTranslatedMessageObject->getId()] = $alreadyTranslatedMessageObject->getText();
    }
    $missingMessages = array_diff_key($messages, $alreadyTranslatedMessages);

    echo count($missingMessages) . " differences found \n";

    switch ($targetProvider) {
        case "GCT":
            $sourceLang = \RW\Services\Languages::transformLanguageCodeToGTC("en");
            $targetLang = \RW\Services\Languages::transformLanguageCodeToGTC($targetLanguage);
            $translatedMessages = \RW\Services\GoogleCloudTranslation::batchTranslate($sourceLang, $targetLang, $missingMessages);

            $batchData = [];
            foreach ($translatedMessages as $k => $v) {
                $item = [
                    'id' => $k,
                    't' => $v,
                    'l' => $targetLang,
                ];
                if (!empty($namespace)) {
                    $item['n'] = $namespace;
                }

                $batchData[$k] = ['PutRequest' => [
                    "Item" => \RW\Models\Translation::$marshaller->marshalItem($item)
                ]];
            }

            if (count($batchData) > 0) {
                $batchChunks = array_chunk($batchData, 25);
                foreach ($batchChunks as $chunk) {
                    \RW\Models\Translation::$client->batchWriteItem([
                        'RequestItems' => [
                            \RW\Models\Translation::$table => $chunk
                        ]
                    ]);
                }
            }

            echo count($missingMessages) . " translated messages added to db.\n\n";
            break;
        /**
         * If it is OHT, we need to batch it and then send to OHT
         */
        case "OHT":
            $projectItemCount = 0;
            $projectId = null;
            $projectCount = 0;
            foreach ($missingMessages as $missingMessageKey => $missingMessage) {
                //starting a new project
                if ($projectId === null || $projectItemCount % $limit == 0) {
                    $projectId = RW\Models\TranslationProject::idFactory();
                    $translationProjectObject = new RW\Models\TranslationProject();
                    $translationProjectObject->setId($projectId);
                    $translationProjectObject->setCreated(time());
                    $translationProjectObject->setModified(time());
                    $translationProjectObject->setStatus(RW\Models\TranslationProject::STATUS_PENDING);
                    $translationProjectObject->setProvider($targetProvider);
                    $translationProjectObject->setTargetLanguage($targetLanguage);
                }

                //$translationQueueItemID = RW\Models\TranslationQueue::idFactory($missingMessageKey, $targetLanguage, $targetProvider);
                $translationQueueItem = RW\Models\TranslationQueue::getById($missingMessageKey);
                if (empty($translationQueueItem)) {
                    $translationQueueItem = new RW\Models\TranslationQueue();
                    $translationQueueItem->setId($missingMessageKey);
                    $translationQueueItem->setStatus(RW\Models\TranslationQueue::STATUS_PENDING);
                    $translationQueueItem->setCreated(time());
                    $translationQueueItem->setModified(time());
                    $translationQueueItem->setProjectId($projectId);
                    $translationQueueItem->setTargetId($missingMessage);
                    if (!empty($namespace)) {
                        $translationQueueItem->setNamespace($namespace);
                    }
                    $translationQueueItem->save();

                    $projectItemCount++;
                }

                if ($projectItemCount > 0 && !empty($translationProjectObject)) {
                    echo "  ====> Project [$projectId] created by using [$targetProvider]. Source Language: [en]. Target Language: [$targetLanguage].\n";
                    $translationProjectObject->save();
                    $translationProjectObject = null;
                    $projectCount++;
                }
            }
            echo "A total of [$projectItemCount] items added to the translation queue. [$projectCount] projects has been created. \n";
            break;
        default:
            throw new Exception("Do not know what to do");
    }
}