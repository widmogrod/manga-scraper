<?php
require_once 'vendor/autoload.php';

use Monad as M;
use Functional as f;

// String -> Either String String
function getUrl($url)
{
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_HEADER, 0);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_VERBOSE, false);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 0);
    // curl_setopt($curl, CURLOPT_REFERER, $referer);
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_TIMEOUT, 2);
    $result = curl_exec($curl);
    $errno = curl_errno($curl);
    $error = curl_error($curl);
    curl_close($curl);

    return $errno !== 0
        ? M\Either\Left::of($error)
        : M\Either\Right::of($result);
}

// String -> IO ()
function makeDirectory($path) {
    return !is_dir($path) && !mkdir($path, 0700)
        ? M\Either\Left::of("Cant create directory $path")
        : M\Either\Right::of($path);
    // return M\IO::of(function() {
        // if (!is_dir($path) && !mkdir($path, 0700)) {
        //     return M\IO\ioError(sprintf(
        //         'Cant create a directory %s',
        //         $path
        //     ));
        // }
    // });
}

function writeFile($name, $content) {
    return false === file_put_contents($name, $content)
        ? M\Either\Left::of("Cant save content of the file $name")
        : M\Either\Right::of($name);
}

// String -> Either String DOMDocument
function toDomDoc($data) {
    $document = new \DOMDocument();
    $previos = libxml_use_internal_errors(true);
    $isLoaded = $document->loadHTML($data);
    libxml_use_internal_errors($previos);
    return $isLoaded
        ? M\Either\Right::of($document)
        : M\Either\Left::of("Can't load html data from given source");
}

function chaptersList(\DOMDocument $doc) {
    $xpath = new \DOMXPath($doc);
    $elements = $xpath->query(
        "//ul[contains(normalize-space(@class), 'chapter_list')]".
        "//li".
        "//a"
    );

    if (!count($elements)) {
        return M\Either\Left::of('No result of chapters');
    }

    $chapters = [];
    foreach($elements as $key => /* @var $element \DOMElement */ $element){
        $name = trim($element->nodeValue);
        $chapters[] = [
            'url' => $element->getAttribute('href'),
            'name' => $name,
        ];
    }

    return M\Either\Right::of($chapters);
}

function chapterPages(\DOMDocument $doc) {
    $xpath = new \DOMXPath($doc);
    $elements = $xpath->query(
        "//option[contains(normalize-space(@value), 'http')]"
    );

    if (!count($elements)) {
        return M\Either\Left::of('No result of chapter pages');
    }

    $result = [];
    foreach($elements as $key => /* @var $element \DOMElement */ $element){
        $name = trim($element->nodeValue);
        $result[$name] = [
            'url' => $element->getAttribute('value'),
            'name' => $name,
        ];
    }

    return M\Either\Right::of($result);
}

function pageImageURL(\DOMDocument $doc) {
    $xpath = new \DOMXPath($doc);
    $elements = $xpath->query(
        "//div[contains(normalize-space(@id), 'viewer')]".
        "//img"
    );

    if (!count($elements)) {
        return M\Either\Left::of('No result of chapter pages');
    }

    foreach($elements as $key => /* @var $element \DOMElement */ $element){
        return M\Either\Right::of($element->getAttribute('src'));
    }
}

$mangaUrl = 'http://www.mangatown.com/manga/feng_shen_ji/';
$getChapters = f\pipeline(
    'getUrl',
    f\bind('toDomDoc'),
    f\bind('chaptersList')
);

// $chapters = $getChapters($mangaUrl);
// var_dump($chapters);

$getChapterPages = f\pipeline(
    'getUrl',
    f\bind('toDomDoc'),
    f\bind('chapterPages')
);

// $pages = $getChapterPages('http://www.mangatown.com/manga/feng_shen_ji/c001/');
// var_dump($pages);

$getPagesImageURL = f\pipeline(
    'getUrl',
    f\bind('toDomDoc'),
    f\bind('pageImageURL')
);
// $image = $getPagesImageURL('http://www.mangatown.com/manga/feng_shen_ji/c001/');
// var_dump($image);


$result = $getChapters($mangaUrl)
    ->bind(function($chapters) use ($getChapterPages, $getPagesImageURL) {
        foreach($chapters as $chapter) {
            $getChapterPages($chapter['url'])
                ->bind(function($pages) use ($chapter, $getPagesImageURL) {
                    foreach($pages as $page) {
                        M\Either\either(
                            'var_dump',
                            'var_dump',
                            f\liftM2(
                                function($path, $imageContent) use ($page) {
                                    return writeFile($path . '/' . $page['name'] . '.jpg', $imageContent);
                                },
                                makeDirectory($chapter['name']),
                                $getPagesImageURL($page['url'])
                                    ->bind('getUrl')
                            )
                        );
                    }
                });
        }
    });

M\Either\either(
    'var_dump',
    'var_dump',
    $result
);


// $link = 'http://s.mangatown.com/store/manga/10458/%s.0/compressed/dfeng_shen_ji_ch%s_p%s.jpg?v=51339361821';
// $link = 'http://s.mangatown.com/store/manga/10458/009.0/compressed/dfeng-shen-ji-3420141.jpg?v=51341177841';
// // for ($i = 9; $i < 170; $i++) {
// $i = 9;
// $chapter = substr("000$i", -3);
// var_dump($chapter);
// for ($j = 1; $j < 40; $j++) {
//     $page = substr("000$j", -3);
//     var_dump(['$page' => $page]);
//
//     if (!is_dir($chapter)) {
//         mkdir($chapter, 0755);
//     }
//
//     $url = sprintf($link, $chapter, $chapter, $page);
//     var_dump(['$url' => $url]);
//     $image = file_get_contents($url);
//
//     $status = file_put_contents($chapter.'/'. $page. '.jpg', $image);
//
//     var_dump(!!$image, $url, $status);
// }
// // }
