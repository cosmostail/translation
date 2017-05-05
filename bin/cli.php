<?php
/**
 * php cli.php [--options] [command] [arguments]
 */
require_once ("./../vendor/autoload.php");
date_default_timezone_set( 'UTC' );

$options = getopt('', [
    'region::', 'translation::', 'translation_queue::', 'translation_project::',
    'provider::', 'limit::',
    'oth_pubkey::', 'oth_secret::', 'oth_sandbox::', 'oth_note::',
    'oth_tag::', 'oth_expertise::', 'oth_callback::'
]);

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

$translationConfig = [
    "name" => $options['translation'],
    "region" => $options['region'],
    "version" => "2012-08-10"
];
RW\Models\Translation::init($translationConfig);

$translationQueueConfig = [
    "name" => $options['translation_queue'],
    "region" => $options['region'],
    "version" => "2012-08-10"
];
RW\Models\TranslationQueue::init($translationQueueConfig);

$translationProjectConfig = [
    "name" => $options['translation_project'],
    "region" => $options['region'],
    "version" => "2012-08-10"
];
RW\Models\TranslationProject::init($translationProjectConfig);

$oht = [
    "pubkey" => !empty($options['oth_pubkey']) ? $options['oth_pubkey'] : "",
    "secret" => !empty($options['oth_secret']) ? $options['oth_secret'] : "",
    "sandbox" => !empty($options['oth_sandbox']) ? filter_var($options['oth_sandbox'], FILTER_VALIDATE_BOOLEAN) : false,
    "note" => !empty($options['oth_note']) ? $options['oth_note'] : "DO NOT TRANSLATE any texts enclosed with 'curly brackets {}', '%s' notations and xml/html attributes.",
    "expertise" => !empty($options['oth_expertise']) ? $options['oth_expertise'] : "",
    "tag" => !empty($options['oth_tag']) ? $options['oth_tag'] : "",
];

$numOfOptions = count($options);
$action = $argv[$numOfOptions+1];

/**
 * Code started.
 */
switch ($action) {
    case "diff":
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
        foreach ($targetLanguages as $targetLanguage) {
            diff($targetLanguage, $targetProvider, $limit);
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
                    if (empty($oht['pubkey']) || empty($oht['secret'])) {
                        throw new InvalidArgumentException("Unable to continue OTH project without keys.");
                    }

                    handleOHTProject($translationProjectItem, $oht);
                    break;
                case RW\Models\TranslationProject::PROVIDER_GOOGLE_CLOUD_TRANSLATION:
                    echo "TBD\n";
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
            $projectData = $project->getProjectData();
            $projectId = $project->getId();
            $status = $project->getStatus();

            if ($status == RW\Models\TranslationProject::STATUS_PENDING) {
                echo "Project: [{$projectId}] is [{$status}]\n";
            } else {
                if (empty($oht['pubkey']) || empty($oht['secret'])) {
                    throw new InvalidArgumentException("Unable to continue OTH project without keys.");
                }

                $oneHourTranslation = new RW\Services\OneHourTranslation($oht['pubkey'], $oht['secret'], $oht['sandbox']);
                $result = $oneHourTranslation->getProjectStatus($projectData['project_id']);
                echo "Project: [{$projectId}]. OHT Status: [{$result->results->project_status}]\n";
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
            $projectData = $project->getProjectData();
            $projectId = $project->getId();
            $status = $project->getStatus();

            if ($status == RW\Models\TranslationProject::STATUS_PENDING) {
                echo "Unable to commit pending project. Project: [{$projectId}]\n";
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
            }
            $project->setStatus(RW\Models\TranslationProject::STATUS_COMPLETED);
            $project->setModified(time());
            $project->save();

            echo "\n Remember, you need to run [php cli.php push] to add translated texts to translation table.\n";
        }
        break;
    case "push":
        $queueItems = RW\Models\TranslationQueue::getQueueItemsByStatus(RW\Models\TranslationQueue::STATUS_READY);

        /** @var $queueItem RW\Models\TranslationQueue*/
        foreach ($queueItems as $queueItem) {
            list($sourceId, $targetLanguage, $__p) = RW\Models\TranslationQueue::idExplode($queueItem->getId());
            $targetItemId = RW\Models\Translation::idFactory($queueItem->getNamespace(), $targetLanguage,$queueItem->getTargetResult());
            $translationItem = \RW\Models\Translation::getById($targetItemId);

            if ($translationItem == null) {
                $translationItem = new RW\Models\Translation();
                $translationItem->setId($targetItemId);
                $translationItem->setLang($targetLanguage);
                $translationItem->setNamespace($queueItem->getNamespace());
            }
            $translationItem->setText($queueItem->getTargetResult());
            $translationItem->save();
            echo "Target ID: [$targetItemId] pushed\n";

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
function diff($targetLanguage, $targetProvider, $limit)
{
    $lastEvaluatedKey = null;
    $projectItemCount = 0;
    $projectCount = 0;
    $projectId = null;

    echo "Source Language: [en]. Target Language: [$targetLanguage]. Target Provider: [$targetProvider]\n";

    $translationItems = RW\Models\Translation::getAllTextsByLanguage(RW\Models\Translation::DEFAULT_LANGUAGE_CODE, $lastEvaluatedKey);
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
            $translationQueueItem->setNamespace($translationItemObj->getNamespace());
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
function handleOHTProject($translationProjectItem, $oht)
{
    $queuedItems = RW\Models\TranslationQueue::getQueueItemsByProjectId($translationProjectItem->getId());

    $projectId = $translationProjectItem->getId();
    $targetLang = RW\Services\OneHourTranslation::transformTargetLang($translationProjectItem->getTargetLanguage());
    $sourceLang = RW\Services\OneHourTranslation::transformTargetLang(RW\Models\Translation::DEFAULT_LANGUAGE_CODE);

    echo "Starting OHT translation project id: [$projectId]. Source Lang: [$sourceLang]. Target Lang: [$targetLang] \n";

    $dom = new DOMDocument('1.0', 'utf-8');
    $translations = $dom->createElement("translations");
    $translations->setAttribute("id", $projectId);
    $translations->setAttribute("source_language", $sourceLang);
    $translations->setAttribute("target_language", $targetLang);
    $dom->appendChild($translations);

    foreach ($queuedItems as $queuedItem) {
        list($sourceId, $__t, $__p) = RW\Models\TranslationQueue::idExplode($queuedItem->getId());
        /** @var $translationItem RW\Models\Translation */
        $translationItem = RW\Models\Translation::getById($sourceId);
        $text = $translationItem->getText();

        $cdata = $dom->createCDATASection($text);
        $t = $dom->createElement("t");
        $t->appendChild($cdata);
        $t->setAttribute("id", $queuedItem->getId());
        $translations->appendChild($t);

        $displayText = substr($text, 0, 10) . "...";
        echo "  ===> Translate: sourceID: [$sourceId]. Text: [$displayText]\n";
    }

    file_put_contents("/tmp/{$projectId}.xml", $dom->saveXML());
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