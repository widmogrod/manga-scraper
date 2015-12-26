<?php
require_once 'vendor/autoload.php';

use FantasyLand\Monad;
use Monad as M;
use Monad\IO;
use Monad\Maybe;
use Monad\Either;
use Monad\Collection;
use Monad\Control as control;
use Functional as f;


interface Err
{
}

class ErrCurl implements Err
{
    private $url;
    private $error;

    public function __construct($url, $error)
    {
        $this->url = $url;
        $this->error = $error;
    }

    public function getUrl()
    {
        return $this->url;
    }

    public function getError()
    {
        return $this->error;
    }
}

class ErrNoImage implements Err
{
    /**
     * @var string
     */
    private $invalidContent;

    public function __construct($invalidContent)
    {
        $this->invalidContent = $invalidContent;
    }

    public function getInvalidContent()
    {
        return $this->invalidContent;
    }
}

class ErrChapter implements Err
{
    /**
     * @var ChapterPage
     */
    private $chapterPage;
    /**
     * @var Err
     */
    private $reason;

    public function __construct(ChapterPage $chapterPage, Err $reason)
    {
        $this->chapterPage = $chapterPage;
        $this->reason = $reason;
    }

    public function getChapterPage()
    {
        return $this->chapterPage;
    }

    public function getReason()
    {
        return $this->reason;
    }
}

const getUrl = 'getUrl';

// String -> Either String String
function getUrl($url, $ttl = null)
{
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_HEADER, 0);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_VERBOSE, false);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 0);
    // curl_setopt($curl, CURLOPT_REFERER, $referer);
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_TIMEOUT, $ttl > 0 ? $ttl : 1);
    $result = curl_exec($curl);
    $errno = curl_errno($curl);
    $error = curl_error($curl);
    curl_close($curl);

    return $errno !== 0
        ? Either\Left::of(new ErrCurl($url, $error))
        : Either\Right::of($result);
}

// String -> Either String String
function makeDirectory($path)
{
    return !is_dir($path) && !mkdir($path, 0700, true)
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

const chapterPages = 'chapterPages';

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

const pageImageURL = 'pageImageURL';

// DOMDocument -> Maybe (Collection PageImage)
function pageImageURL(\DOMDocument $doc)
{
    $xpath =
        "//div[contains(normalize-space(@id), 'viewer')]" .
        "//img";

    return f\map(f\map(elementToPageImage), xpath($doc, $xpath));
}


// getChapters :: String -> Maybe (Collection Chapter)
function getChapters($mangaUrl)
{
    return call_user_func(f\pipeline(
        getUrl
        , f\bind(toDomDoc)
        , Either\toMaybe
        , f\bind(chaptersList)
    ), $mangaUrl);
}

// getChapterPages :: Chapter -> Maybe (Collection Page)
function getChapterPages(Chapter $chapter)
{
    return call_user_func(f\pipeline(
        getUrl
        , f\bind(toDomDoc)
        , Either\toMaybe
        , f\bind(chapterPages)
    ), $chapter->getUrl());
}

// getPagesImageURL :: Page -> Maybe (Collection PageImage)
function getPagesImageURL(Page $page)
{
    return call_user_func(f\pipeline(
        getUrl
        , f\bind(toDomDoc)
        , Either\toMaybe
        , f\bind(pageImageURL)
    ), $page->getUrl());
}


class ChapterPage
{
    /**
     * @var Chapter
     */
    private $chapter;
    /**
     * @var Page
     */
    private $page;
    /**
     * @var PageImage
     */
    private $pageImage;

    public function __construct(Chapter $chapter, Page $page, PageImage $pageImage)
    {
        $this->chapter = $chapter;
        $this->page = $page;
        $this->pageImage = $pageImage;
    }

    /**
     * @return Chapter
     */
    public function getChapter()
    {
        return $this->chapter;
    }

    /**
     * @return Page
     */
    public function getPage()
    {
        return $this->page;
    }

    /**
     * @return PageImage
     */
    public function getPageImage()
    {
        return $this->pageImage;
    }
}

// String -> Maybe (Collection (Maybe ChapterPage))
function fetchMangaData($mangaUrl)
{
    // getChapters :: String -> Maybe (Collection Chapter)
    return getChapters($mangaUrl)
        ->map(function (Collection $chapters) {
            return $chapters
                ->bind(function (Chapter $chapter) {
                    // getChapterPages :: Chapter -> Maybe (Collection Page)
                    return getChapterPages($chapter)
                        ->bind(function (Collection $pages) use ($chapter) {
                            return $pages
                                ->bind(function (Page $page) use ($chapter) {
                                    // getPagesImageURL :: Page -> Maybe (Collection PageImage)
                                    return getPagesImageURL($page)
                                        ->bind(function (Collection $images) use ($chapter, $page) {
                                            return $images
                                                ->bind(function (PageImage $image) use (
                                                    $chapter,
                                                    $page
                                                ) {
                                                    return Maybe\Just::of(new ChapterPage(
                                                        $chapter,
                                                        $page,
                                                        $image
                                                    ));
                                                });
                                        });
                                });
                        });
                });
        });
}

const download = 'download';

function liftM3(
    callable $transformation,
    Monad $ma,
    Monad $mb,
    Monad $mc
) {
    return $ma->bind(function ($a) use ($mb, $mc, $transformation) {
        return $mb->bind(function ($b) use ($mc, $a, $transformation) {
            return $mc->bind(function ($c) use ($a, $b, $transformation) {
                return call_user_func($transformation, $a, $b, $c);
            });
        });
    });
}

// getOnlyImage :: Either -> Either String String
function getOnlyImage(Either\Either $either)
{
    return $either->bind(function ($content) {
        $tmp = './tmp.get.only.txt';
        $result = file_put_contents($tmp, $content);
        var_dump($result);
        var_dump(exif_imagetype($tmp));
        var_dump(image_type_to_mime_type(exif_imagetype($tmp)));

        return false === exif_imagetype($tmp)
            ? Either\left(new ErrNoImage($content))
            : Either\right($content);
    });
}

// ChapterPage -> Either ErrCantDownload String
function download(ChapterPage $chapterPage, $ttl = null)
{
    return liftM3(
        function ($path, $imageContent, ChapterPage $chapterPage) {
            return writeFile($path . '/' . $chapterPage->getPage()->getName() . '.jpg', $imageContent);
        },
        makeDirectory(
            './manga/' . $chapterPage->getChapter()->getName()
        ),
        Either\doubleMap(
            function (Err $error) use ($chapterPage) {
                return new ErrChapter($chapterPage, $error);
            }
            , f\identity
            , getOnlyImage(getUrl($chapterPage->getPageImage()->getUrl(), $ttl))
        ),
        Either\Right::of($chapterPage)
    );
}

const failed = 'failed';

function failed(Maybe\Maybe $page, $ttl = null)
{
    return $page->map(function (Either\Either $either) use ($ttl) {
        return $either->either(function (ErrChapter $errCantDownload) use ($ttl) {
            return download($errCantDownload->getChapterPage(), $ttl);
        }, Either\Right::of);
    });
}


//var_dump(download(new ChapterPage(
//    new Chapter('Sudome', 'http://www.mangatown.com/manga/sundome/v01/c002/3.html'),
//    new Page('03', 'http://www.mangatown.com/manga/sundome/v01/c002/3.html'),
//    new PageImage('http://a.mangatown.com/store/manga/3412/01-002.0/compressed/002.jpg?v=51215960241')
//)));
//die;

// get :: a -> {b} -> Maybe b
function get($key, array $array = null)
{
    return call_user_func_array(f\curryN(2, function ($key, array $array) {
        return array_key_exists($key, $array)
            ? Maybe\just($array[$key])
            : Maybe\nothing();
    }), func_get_args());
}

IO\getArgs()->map(get(0))->map(function(Maybe\Maybe $argument) {
    return $argument->map(function($mangaUrl) {
        var_dump('started ', $mangaUrl);
        $mangaData = fetchMangaData($mangaUrl);
        var_dump('manga data ready');
        $afterDownload = $mangaData->map(f\map(f\map(download)));
        var_dump('manga first run');
        $afterDownload->map(function (Collection $collection) {
            $ttl = 2;

            do {
                var_dump('re-download');
                $toRetry = f\filter(function (Maybe\Maybe $maybe) {
                    return $maybe->extract() instanceof Either\Left;
                }, $collection);

                $count = count($toRetry);
                $collection = Collection::of($toRetry)->map(function ($a) use (&$ttl, $count) {
                    var_dump('retry', $ttl, $count);

                    return failed($a, $ttl);
                });
            } while (count($toRetry) > 0);
        });
    });
})->run();


