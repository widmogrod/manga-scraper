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


class ErrCurl
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

class ErrCantDownload
{
    /**
     * @var ChapterPage
     */
    private $chapterPage;
    /**
     * @var ErrCurl
     */
    private $curl;

    public function __construct(ChapterPage $chapterPage, ErrCurl $curl)
    {
        $this->curl = $curl;
        $this->chapterPage = $chapterPage;
    }

    /**
     * @return ChapterPage
     */
    public function getChapterPage()
    {
        return $this->chapterPage;
    }

    /**
     * @return ErrCurl
     */
    public function getCurl()
    {
        return $this->curl;
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
            function (ErrCurl $error) use ($chapterPage) {
                return new ErrCantDownload($chapterPage, $error);
            }
            , f\identity
            , getUrl($chapterPage->getPageImage()->getUrl(), $ttl)
        ),
        Either\Right::of($chapterPage)
    );
}

//$mangaUrl = 'http://www.mangatown.com/manga/ryuu_to_hidari_te/';
$mangaUrl = 'http://www.mangatown.com/manga/dragon_ball_chou/';

// fetchMangaData :: String -> Maybe (Collection (Maybe ChapterPage))
$mangaData = fetchMangaData($mangaUrl);
$givenType = is_object($mangaData) ? get_class($mangaData) : gettype($mangaData);
//var_dump($givenType);
//var_dump($mangaData);
//file_put_contents('manga.state', serialize($mangaData));


// Maybe (Collection Maybe Either ErrCantDownload String)
$afterDownload = $mangaData->map(f\map(f\map(download)));
$givenType = is_object($afterDownload) ? get_class($afterDownload) : gettype($afterDownload);
//var_dump($givenType);
//var_dump($afterDownload);

//file_put_contents(serialize($afterDownload));

const failed = 'failed';

function failed(Maybe\Maybe $page, $ttl = null)
{
    return $page->map(function (Either\Either $either) use ($ttl) {
        return $either->either(function (ErrCantDownload $errCantDownload) use ($ttl) {
            return download($errCantDownload->getChapterPage(), $ttl);
        }, Either\Right::of);
    });
}

$toRetry = $afterDownload->map(function (Collection $collection) {
    $ttl = 2;
    do {
        $toRetry = f\filter(function (Maybe\Maybe $maybe) {
            return $maybe->extract() instanceof Either\Left;
        }, $collection);

        var_dump(count($toRetry));
        var_dump($collection = Collection::of($toRetry)->map(function($a) use(&$ttl) {
            return failed($a, $ttl++);
        }));
    } while(count($toRetry) > 0);
});



//$r = Collection::of([
//    Maybe\nothing()
//    , Collection::of([
//        Either\Left::of(
//            new ErrCantDownload(
//                new PageImage('http://')
//                , new ErrCurl("http://", "Operation timed out after 1003 milliseconds with 0 bytes received"))),
//    ])
//]);

//var_dump(getChapters($mangaUrl));
// IO ()
//function main()
//{
//    return IO\getArgs()->bind('var_dump');
//}
//
//main()->run();


