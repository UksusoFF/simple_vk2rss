<?php

require 'vendor/autoload.php';

use FeedWriter\RSS2;
use GuzzleHttp\Client;

$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load();

function remove_emoji($text)
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
        return ($profile->id == $id) || ('-' . $profile->id == $id) || ($profile->screen_name == $id);
    });

    $author = array_shift($author);

    if (isset($author->first_name) && isset($author->last_name)) {
        return [
            'name' => implode(' ', [$author->last_name, $author->first_name]),
            'screen_name' => $author->screen_name,
        ];
    }

    if (isset($author->name)) {
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
        $description[] = remove_emoji(nl2br($post->text)) . '<br />';
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
                default:
                    break;
            }
        }
    }

    return implode('<br />', $description);
}

$feedId = isset($_GET['id']) ? $_GET['id'] : null;

if (empty($feedId)) {
    throw new Exception("Empty params", 400);
}

$client = new Client();

$res = $client->get('https://api.vk.com/method/wall.get', [
    'query' => array_merge([
        'count' => 20,
        'extended' => 1,
        'access_token' => getenv('VK_ACCESS_TOKEN'),
        'v' => '5.65',
    ], processOwnerIdOrDomain($feedId)),
]);

$response = json_decode($res->getBody());
$profiles = array_merge($response->response->profiles, $response->response->groups);

$feed = new RSS2();
$feed->setTitle(getAuthorById($profiles, $feedId)['name']);
$feed->setLink("http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]");
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

$feed->generateFeed();
$feed->printFeed();