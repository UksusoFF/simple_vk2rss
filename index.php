<?php

require 'vendor/autoload.php';

use Doctrine\Common\Cache\FilesystemCache;
use FeedWriter\RSS2;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Kevinrob\GuzzleCache\CacheMiddleware;
use Kevinrob\GuzzleCache\Storage\DoctrineCacheStorage;
use Kevinrob\GuzzleCache\Strategy\GreedyCacheStrategy;

$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load();

function removeEmoji($text)
{
    // Match Emoticons
    $regexEmoticons = '/[\x{1F600}-\x{1F64F}]/u';
    $clean_text = preg_replace($regexEmoticons, '', $text);

    // Match Miscellaneous Symbols and Pictographs
    $regexSymbols = '/[\x{1F300}-\x{1F5FF}]/u';
    $clean_text = preg_replace($regexSymbols, '', $clean_text);

    // Match Transport And Map Symbols
    $regexTransport = '/[\x{1F680}-\x{1F6FF}]/u';
    $clean_text = preg_replace($regexTransport, '', $clean_text);

    // Match Miscellaneous Symbols
    $regexMisc = '/[\x{2600}-\x{26FF}]/u';
    $clean_text = preg_replace($regexMisc, '', $clean_text);

    // Match Dingbats
    $regexDingbats = '/[\x{2700}-\x{27BF}]/u';
    $clean_text = preg_replace($regexDingbats, '', $clean_text);

    // Match Flags
    $regexDingbats = '/[\x{1F1E6}-\x{1F1FF}]/u';
    $clean_text = preg_replace($regexDingbats, '', $clean_text);

    // Others
    $regexDingbats = '/[\x{1F910}-\x{1F95E}]/u';
    $clean_text = preg_replace($regexDingbats, '', $clean_text);

    $regexDingbats = '/[\x{1F980}-\x{1F991}]/u';
    $clean_text = preg_replace($regexDingbats, '', $clean_text);

    $regexDingbats = '/[\x{1F9C0}]/u';
    $clean_text = preg_replace($regexDingbats, '', $clean_text);

    $regexDingbats = '/[\x{1F9F9}]/u';
    $clean_text = preg_replace($regexDingbats, '', $clean_text);

    return $clean_text;
}

function processOwnerIdOrDomain($id)
{
    if (strcmp(substr($id, 0, 2), 'id') === 0 && ctype_digit(substr($id, 2))) {
        return [
            'owner_id' => (int)substr($id, 2),
        ];
    } elseif (strcmp(substr($id, 0, 4), 'club') === 0 && ctype_digit(substr($id, 4))) {
        return [
            'owner_id' => -(int)substr($id, 4),
        ];
    } elseif (strcmp(substr($id, 0, 5), 'event') === 0 && ctype_digit(substr($id, 5))) {
        return [
            'owner_id' => -(int)substr($id, 5),
        ];
    } elseif (strcmp(substr($id, 0, 6), 'public') === 0 && ctype_digit(substr($id, 6))) {
        return [
            'owner_id' => -(int)substr($id, 6),
        ];
    } elseif (is_numeric($id) && is_int(abs($id))) {
        return [
            'owner_id' => (int)$id,
        ];
    } else {
        return [
            'domain' => $id,
        ];
    }
}

function getAuthorById($profiles, $id)
{
    $author = array_filter($profiles, function ($profile) use ($id) {
        return ($profile->id == $id) ||
            ('-' . $profile->id == $id) ||
            (isset($profile->screen_name) && $profile->screen_name == $id);
    });

    $author = array_shift($author);

    if (isset($author->first_name) && isset($author->last_name) && isset($author->screen_name)) {
        return [
            'name' => implode(' ', [$author->last_name, $author->first_name]),
            'screen_name' => $author->screen_name,
        ];
    }

    if (isset($author->name) && isset($author->screen_name)) {
        return [
            'name' => $author->name,
            'screen_name' => $author->screen_name,
        ];
    }

    return [
        'name' => 'nobody',
        'screen_name' => 'none',
    ];
}

function getDescriptionFromPost($post)
{
    $description = [];

    if (!empty($post->text)) {
        $description[] = removeEmoji(nl2br($post->text)) . '<br />';
    }

    if (isset($post->attachments)) {
        $description[] = '<b>Вложения:</b><br />';
        foreach ($post->attachments as $attachment) {
            $attachment = (array)$attachment;
            switch ($attachment['type']) {
                case 'photo':
                    $photo = $attachment[$attachment['type']];
                    $description[] = "<a href=\"https://vk.com/photo{$photo->owner_id}_{$photo->id}\"><img src=\"{$photo->photo_130}\"></a>";
                    break;
                case 'doc':
                    $doc = $attachment[$attachment['type']];
                    $description[] = $doc->title;
                    break;
                case 'audio':
                    $audio = $attachment[$attachment['type']];
                    $description[] = "{$audio->artist} - {$audio->title}";
                    break;
                case 'video':
                    $video = $attachment[$attachment['type']];
                    $description[] = "{$video->title}";
                    $description[] = "<img src=\"{$video->photo_130}\">";
                    break;
                case 'link':
                    $link = $attachment[$attachment['type']];
                    $description[] = "<a href=\"{$link->url}\">{$link->title}</a>";
                    break;
                case 'album':
                    $album = $attachment[$attachment['type']];
                    $description[] = $album->title;
                    $description[] = "<a href=\"https://vk.com/album{$album->owner_id}_{$album->id}\"><img src=\"{$album->thumb->photo_130}\"></a>";
                    break;
                case 'market':
                    $market = $attachment[$attachment['type']];
                    $description[] = $market->title;
                    $description[] = "<a href=\"https://vk.com/market{$market->owner_id}\"><img src=\"{$market->thumb_photo}\"></a>";
                    break;
                case 'page':
                    $page = $attachment[$attachment['type']];
                    $description[] = "<a href=\"{$page->view_url}\">{$page->title}</a>";
                    break;
                case 'poll':
                    $poll = $attachment[$attachment['type']];
                    $description[] = $poll->question;
                    foreach ($poll->answers as $answer) {
                        $description[] = $answer->text;
                    }
                    break;
                default:
                    $description[] = "Неподдерживаемый тип вложения {$attachment['type']}.";
                    break;
            }
        }
    }

    return implode('<br />', $description);
}

$feedId = isset($_GET['id']) ? processOwnerIdOrDomain($_GET['id']) : null;

if (empty($feedId)) {
    throw new Exception("Empty params", 400);
}

$stack = HandlerStack::create();

$stack->push(new CacheMiddleware(
    new GreedyCacheStrategy(
        new DoctrineCacheStorage(
            new FilesystemCache('cache/')
        ), 60 * 60 //Seconds
    )
), 'cache');

$client = new Client([
    'handler' => $stack,
]);

$res = $client->get('https://api.vk.com/method/wall.get', [
    'delay' => 1000,
    'query' => array_merge([
        'count' => 20,
        'extended' => 1,
        'access_token' => getenv('VK_ACCESS_TOKEN'),
        'v' => '5.71',
    ], $feedId),
]);

$response = json_decode($res->getBody());

$feed = new RSS2();
$feed->setLink("http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]");

if (empty($response->response)) {
    switch ($response->error->error_code) {
        case 15: //Show error once. //15 - Wall is disabled.
            $date = date('D, d M Y H:i:s T', strtotime('2018-01-01'));
            break;
        default: //Show error on each request.
            $date = date('D, d M Y H:i:s T');
    }
    $errorItem = $feed->createNewItem();
    $errorItem->addElementArray([
        'title' => 'Error Reporter',
        'pubDate' => $date,
        'author' => 'Error',
        'link' => "https://vk.com/{$_GET['id']}",
        'description' => $response->error->error_msg,
    ]);
    $feed->addItem($errorItem);
} else {
    $profiles = array_merge($response->response->profiles, $response->response->groups);

    $feed->setTitle(getAuthorById($profiles, array_pop($feedId))['name']);
    $feed->setDate(time());

    foreach ($response->response->items as $item) {
        $author = getAuthorById($profiles, $item->from_id);
        $text = [
            getDescriptionFromPost($item),
        ];

        if (!empty($item->copy_history)) {
            foreach ($item->copy_history as $repost) {
                $text[] = '<b>Репост ' . getAuthorById($profiles, $repost->from_id)['name'] . ':</b>';
                $text[] = getDescriptionFromPost($repost);
            }
        }

        $newItem = $feed->createNewItem();
        $newItem->addElementArray([
            'title' => $author['name'],
            'pubDate' => date('D, d M Y H:i:s T', $item->date),
            'author' => $author['screen_name'],
            'link' => "https://vk.com/wall{$item->owner_id}_{$item->id}",
            'description' => implode('<br />', array_filter($text)),
        ]);
        $feed->addItem($newItem);
    }
}

$feed->generateFeed();
$feed->printFeed();