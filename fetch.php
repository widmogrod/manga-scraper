<?php
require_once 'vendor/autoload.php';

use Monad as M;
use Monad\IO;
use Monad\Maybe;
use Monad\Either;
use Monad\Collection;
use Monad\Control as control;
use Functional as f;

const getUrl = 'getUrl';

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
        ? Either\Left::of($error)
        : Either\Right::of($result);
}

// String -> Either String String
function makeDirectory($path)
{
    return !is_dir($path) && !mkdir($path, 0700)
        ? Either\Left::of("Cant create directory $path")
        : Either\Right::of($path);
}

// String -> String -> Either String String
function writeFile($name, $content)
{
    return false === file_put_contents($name, $content)
        ? Either\Left::of("Cant save content of the file $name")
        : Either\Right::of($name);
}

const toDomDoc = 'toDomDoc';

// String -> Either String DOMDocument
function toDomDoc($data)
{
    $document = new \DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $isLoaded = $document->loadHTML($data);
    libxml_use_internal_errors($previous);

    return $isLoaded
        ? Either\Right::of($document)
        : Either\Left::of("Can't load html data from given source");
}

// DOMDocument -> String -> Maybe (Collection DOMElement)
function xpath(\DOMDocument $doc, $path)
{
    $xpath = new \DOMXPath($doc);
    $elements = $xpath->query($path);

    return $elements->length
        ? Maybe\just(Collection::of($elements))
        : Maybe\nothing();
}


class Chapter
{
    private $url;
    private $name;

    public function __construct($name, $url)
    {
        $this->name = $name;
        $this->url = $url;
    }

    public function getUrl()
    {
        return $this->url;
    }

    public function getName()
    {
        return $this->name;
    }
}

const elementToChapterItem = 'elementToChapterItem';

// DOMElement -> Chapter
function elementToChapterItem(DOMElement $element)
{
    return new Chapter(
        trim($element->nodeValue),
        $element->getAttribute('href')
    );
}

const chaptersList = 'chaptersList';

// DOMDocument -> Maybe (Collection ChapterItem)
function chaptersList(\DOMDocument $doc)
{
    $xpath =
        "//ul[contains(normalize-space(@class), 'chapter_list')]" .
        "//li" .
        "//a";

    return f\map(f\map(elementToChapterItem), xpath($doc, $xpath));
}

class Page
{
    private $url;
    private $name;

    public function __construct($name, $url)
    {
        $this->name = $name;
        $this->url = $url;
    }

    public function getUrl()
    {
        return $this->url;
    }

    public function getName()
    {
        return $this->name;
    }
}

const elementToPage = 'elementToPage';

// DOMElement -> Page
function elementToPage(DOMElement $element)
{
    return new Page(
        trim($element->nodeValue),
        $element->getAttribute('value')
    );
}


// DOMDocument -> Maybe (Collection Page)
function chapterPages(\DOMDocument $doc)
{
    $xpath = "//option[contains(normalize-space(@value), 'http')]";

    return f\map(f\map(elementToPage), xpath($doc, $xpath));
}

class PageImage
{
    private $url;

    public function __construct($url)
    {
        $this->url = $url;
    }

    public function getUrl()
    {
        return $this->url;
    }
}

const elementToPageImage = 'elementToPageImage';

// DOMElement -> PageImage
function elementToPageImage(DOMElement $element)
{
    return new PageImage(
        $element->getAttribute('src')
    );
}

// DOMDocument -> Maybe (Collection PageImage)
function pageImageURL(\DOMDocument $doc)
{
    $xpath =
        "//div[contains(normalize-space(@id), 'viewer')]" .
        "//img";

    return f\map(f\map(elementToPageImage), xpath($doc, $xpath));
}

$mangaUrl = 'http://www.mangatown.com/manga/feng_shen_ji/';
$getChapters = f\pipeline(
    getUrl
    , f\bind(toDomDoc)
    , Either\toMaybe
    , f\bind(chaptersList)
);

$getChapterPages = f\pipeline(
    'getUrl'
    , f\bind('toDomDoc')
    , Either\toMaybe
    , f\bind('chapterPages')
);

$getPagesImageURL = f\pipeline(
    'getUrl'
    , f\bind('toDomDoc')
    , Either\toMaybe
    , f\bind('pageImageURL')
);

// $result = $getChapters($mangaUrl)
//     ->bind(function($chapters) use ($getChapterPages, $getPagesImageURL) {
//         foreach($chapters as $chapter) {
//             $getChapterPages($chapter['url'])
//                 ->bind(function($pages) use ($chapter, $getPagesImageURL) {
//                     foreach($pages as $page) {
//                         Either\either(
//                             'var_dump',
//                             'var_dump',
//                             f\liftM2(
//                                 function($path, $imageContent) use ($page) {
//                                     return writeFile($path . '/' . $page['name'] . '.jpg', $imageContent);
//                                 },
//                                 makeDirectory($chapter['name']),
//                                 $getPagesImageURL($page['url'])
//                                     ->bind('getUrl')
//                             )
//                         );
//                     }
//                 });
//         }
//     });
//
// Either\either(
//     'var_dump',
//     'var_dump',
//     $result
// );

var_dump($getChapters($mangaUrl));
// IO ()
//function main()
//{
//    return IO\getArgs()->bind('var_dump');
//}
//
//main()->run();



