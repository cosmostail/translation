## Install
`composer require revenuewire/translation`

## Description
Translation Services using DyanmoDB or Redis as cache 
options. We also embedded two translation service 
providers, [One Hour Translation](https://www.onehourtranslation.com/) 
and [Google Cloud Translation](https://cloud.google.com/translate/). 

**OneHourTranslation** provides human translators to 
translate your project where **Google Cloud Translation**
charges a flat fee for 
[Neural Machine Translation (NMT)](https://research.googleblog.com/2016/09/a-neural-network-for-machine.html).
Both service providers provide simple machine translation
but are not supported for the purpose of this project.

## Requirements 
Generally, there are two modes (live or db) of configurations you can 
choose from.  

##### Live Mode 
Live mode uses **Google Cloud Translation** APIs that 
translate the texts directly to targeted languages. 
You need a Google Cloud account and key in order to use it. 
You also need **Redis** cache for better performance. 
No **memcached** support yet.

###### Requirement Summary
* Google Cloud Account
* Redis

##### DB Mode
Database mode uses **AWS DynamoDB** as a storage choice. 
**Redis** is suggested but not required.
###### Requirement Summary
* AWS DynamoDB
* Redis (optional)

## Configurations
##### Live Mode 
```php
$defaultLanguage = "en";
$supportLanguages = ["en", "fr", "zh"];
$redisConfig = [
    "host" => "REDIS_HOST",
    "timeout" => "0.5",
    "port" => "6379",
    "prefix" => "t_2sx_", //optional, to prevent cache key collision
];
$gct = [
    "project" => "GOOGLE_CLOUD_PROJECT_ID",
    "key" => "GOOGLE_CLOUD_PROJECT_KEY"
];
$translationService = new \RW\Translation(null, $supportLanguages, $cache, $defaultLanguage, $gct);

//translate to Simple Chinese
echo $translationService->translate("Hello World", "zh");
print_r($translationService->batchTranslate([
   "hello" => "Hello World",
   "how-s-going" => "How's going?"
],"zh"));
```
##### DB Mode
###### Install
```bash
php vendor/revenuewire/translation/bin/cli.php \
    --region=[AWS_REGION] \
    --translation=[TRANSLATION_TABLE] \
    --translation_queue=[TRANSLATION_QUEUE_TABLE] \
    --translation_project=[TRANSLATION_PROJECT_TABLE] \
    init 
```

###### Usage
```php
$defaultLanguage = "en";
$supportLanguages = ["en", "fr", "zh"];

$dynamoConfig = [
   "region" => "us-west-2",
   "table" => "YOUR TABLE NAME",
   "version" => "2012-08-10"
];

$redisConfig = [
    "host" => "YOUR REDIS HOST",
    "timeout" => "0.5",
    "port" => "6379",
    "prefix" => "t_2sx_", //optional, to prevent cache key collision
];

$translationService = new \RW\Translation($dynamoConfig, $supportLanguages, $cache, $defaultLanguage);

//translate to Simple Chinese
echo $translationService->translate("Hello World", "zh");
print_r($translationService->batchTranslate([
   "hello" => "Hello World",
   "how-s-going" => "How's going?"
],"zh"));
```
## Working with Translation Services
Once you go through all your pages, your translation table
should have collected all texts you need to translate.

###### diff
Calculate the difference between existing texts and 
targeted translation texts. For example, if your source
table have a English word "Hello World", and the following
command will generate two queue items that aim to translate
"Hello World" to Chinese and French.

```bash
php vendor/revenuewire/translation/bin/cli.php \
        --provider=[OTH or GCT] \
        --region=[AWS_REGION] \
        --translation=[TRANSLATION_TABLE] \
        --translation_queue=[TRANSLATION_QUEUE_TABLE] \
        --translation_project=[TRANSLATION_PROJECT_TABLE] \
   	    diff zh fr
```

###### add
The add command will add all pending items into projects.
This is useful when working with OneHourTranslation which 
had limits on how many texts you can schedule to translate
per batch.
```bash
php vendor/revenuewire/translation/bin/cli.php \
        --region=[AWS_REGION] \
        --translation=[TRANSLATION_TABLE] \
        --translation_queue=[TRANSLATION_QUEUE_TABLE] \
        --translation_project=[TRANSLATION_PROJECT_TABLE] \
        --oth_pubkey=[ONE_HOUR_TRANSLATION_PUB_KEY] \
        --oth_secret=[ONE_HOUR_TRANSLATION_SECRET] \
        --oth_sandbox=[ONE_HOUR_TRANSLATION_SANDBOX] \
        --gct_project=[GOOGLE_CLOUD_PROJECT] \
        --gct_key=[GOOGLE_CLOUD_KEY] \
        add 
```

###### commit
Commit the projects to service provider. The commit only
available for One Hour Translation.
```bash
php vendor/revenuewire/translation/bin/cli.php \
        --region=[AWS_REGION] \
        --translation=[TRANSLATION_TABLE] \
        --translation_queue=[TRANSLATION_QUEUE_TABLE] \
        --translation_project=[TRANSLATION_PROJECT_TABLE] \
        --oth_pubkey=[ONE_HOUR_TRANSLATION_PUB_KEY] \
        --oth_secret=[ONE_HOUR_TRANSLATION_SECRET] \
        --oth_sandbox=[ONE_HOUR_TRANSLATION_SANDBOX] \
        commit 
```

###### push
Push the translated texts back to translation table.
```bash
php vendor/revenuewire/translation/bin/cli.php \
        --region=[AWS_REGION] \
        --translation=[TRANSLATION_TABLE] \
        --translation_queue=[TRANSLATION_QUEUE_TABLE] \
        --translation_project=[TRANSLATION_PROJECT_TABLE] \
   	    push
```