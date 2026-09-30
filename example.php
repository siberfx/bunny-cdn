<?php
require __DIR__ . '/vendor/autoload.php';

use Siberfx\BunnyCdn\BunnyCore\BunnyAPIException;
use Siberfx\BunnyCdn\BunnyCore\BunnyAPIPull;
use Siberfx\BunnyCdn\BunnyCore\BunnyAPIStorage;
use Siberfx\BunnyCdn\BunnyCore\BunnyAPIStream;

/*
 *
 * PULL ZONE EXAMPLES
 *
 */

$bunny = new BunnyAPIPull('your-account-api-key');

try {
    echo json_encode($bunny->listPullZones());//Returns data for all Pull zones on account
} catch (BunnyAPIException $e) {
    echo $e->getStatusCode() . ': ' . $e->getMessage();
}

//Examples using pull zone id: 1337

//Individual pull zone data
$bunny->getPullZone(1337);

//List hostnames for a pull zone
$bunny->pullZoneHostnames(1337);

//Add hostname to pull zone and enable free SSL
$bunny->addHostnamePullZone(1337, 'cdn.domain.com');
$bunny->addFreeSSLCertificate('cdn.domain.com');

//Force SSL for pull zone hostname
$bunny->forceSSLPullZone(1337, 'cdn.domain.com', true);

//Remove hostname for pull zone
$bunny->removeHostnamePullZone(1337, 'cdn.domain.com');

//Block / unblock an ip address
$bunny->addBlockedIpPullZone(1337, '203.0.113.7');
$bunny->unBlockedIpPullZone(1337, '203.0.113.7');

//Pull zone HTTP access logs for a day
$bunny->pullZoneLogs(1337, new DateTimeImmutable('yesterday'));

//Create pull zone
$bunny->createPullZone('a_test_pull_zone', 'https://domain.com');

//Purge pull zone (everything, or by cache tag)
$bunny->purgePullZone(1337);
$bunny->purgePullZone(1337, 'images');

//Purge cache for a URL
$bunny->purgeCache('https://cdn.domain.com/css/style.min.css');

//Billing
$bunny->balance();
$bunny->monthCharges();
$bunny->monthChargeBreakdown();

//Bandwidth stats
$bunny->getStatistics(date_from: '2026-09-01', date_to: '2026-09-30');


/*
 *
 * STORAGE ZONE EXAMPLES
 *
 */

$storage = new BunnyAPIStorage('your-account-api-key');

//View all storage zones for account
echo json_encode($storage->listStorageZones());

//Select a storage zone (password is looked up with the API key when omitted), primary region New York
$storage->setStorageZone('homeimagebackups', '', 'ny');

//HTTP API
$storage->uploadFileHTTP('fluffy.jpg', 'pets/fluffy.jpg');
echo json_encode($storage->listFiles('pets'));
$contents = $storage->downloadFileHTTP('pets/fluffy.jpg');
$storage->deleteFileHTTP('pets/fluffy.jpg');

//FTP (requires ext-ftp)
$storage->zoneConnect('homeimagebackups', '', 'ny');
$storage->createFolder('pets');
$storage->uploadFile('fluffy.jpg', 'pets/fluffy.jpg');
$storage->renameFile('pets/', 'fluffy.jpg', 'fluffy_young.jpg');
$storage->moveFile('pets/', 'fluffy_young.jpg', 'pets/puppy_fluffy/');
echo $storage->convertBytes($storage->getFileSize('pets/puppy_fluffy/fluffy_young.jpg'), 'MB');
$storage->deleteFile('pets/puppy_fluffy/fluffy_young.jpg');
$storage->deleteFolder('pets/puppy_fluffy/');
$storage->closeConnection();


/*
 *
 * Video stream API examples
 *
 */

$stream = new BunnyAPIStream(stream_library_access_key: 'your-stream-library-key');
$stream->setStreamLibraryId(1234);

//List collections
echo json_encode($stream->getStreamCollections());

//List videos for a collection
$stream->setStreamCollectionGuid('886gce58-1482-416f-b908-fca0b60f49ba');
echo json_encode($stream->listVideosForCollectionId());

//Video information
echo json_encode($stream->getVideo('e6410005-d591-4a7e-a83d-6c1eef0fdc78'));
echo json_encode($stream->videoResolutionsArray('e6410005-d591-4a7e-a83d-6c1eef0fdc78'));
echo json_encode($stream->videoSize('e6410005-d591-4a7e-a83d-6c1eef0fdc78', 'MB'));

//Create a video, then upload the file
$video = $stream->createVideo('title_for_the_video');
echo json_encode($stream->uploadVideo($video['guid'], 'test_video.mp4'));
