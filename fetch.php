<?php
require_once 'vendor/autoload.php';

use Widmogrod\Functional as f;
use Widmogrod\Monad\Collection;
use Widmogrod\Monad\Either;
use Widmogrod\Monad\IO;
use Widmogrod\Monad\Maybe;

const getUrl = 'getUrl';

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


function normaliseMangatownURL($href)
{
    return str_replace('//www.mangatown.com/', 'https://www.mangatown.com/', $href);
}

const elementToChapterItem = 'elementToChapterItem';

// DOMElement -> Chapter
function elementToChapterItem(DOMElement $element)
{
    return new Chapter(
        trim($element->nodeValue),
        normaliseMangatownURL($element->getAttribute('href'))
    );
}

const chaptersList = 'chaptersList';

// DOMDocument -> Maybe (Collection Chapter)
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
        normaliseMangatownURL($element->getAttribute('value'))
    );
}

function uniquePage()
{
    return unique(function (Page $value) {
        return $value->getUrl();
    });
}

const chapterPages = 'chapterPages';

// DOMDocument -> Maybe (Collection Page)
function chapterPages(\DOMDocument $doc)
{
    $xpath = "//option[contains(normalize-space(@value), '//www.')]";

    $unique = f\compose(Collection::of, f\filter(uniquePage()));
    return f\map($unique, f\map(f\map(elementToPage), xpath($doc, $xpath)));
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
        normaliseMangatownURL($element->getAttribute('src'))
    );
}

function uniquePageImage()
{
    return unique(function (PageImage $value) {
        return $value->getUrl();
    });
}


const pageImageURL = 'pageImageURL';

// DOMDocument -> Maybe (Collection PageImage)
function pageImageURL(\DOMDocument $doc)
{
    $xpath =
        "//div[contains(normalize-space(@id), 'viewer')]" .
        "//img";

    $unique = f\compose(Collection::of, f\filter(uniquePageImage()));
    return f\map($unique, f\map(f\map(elementToPageImage), xpath($doc, $xpath)));
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

IO\getArgs()->map(get(0))->map(function (Maybe\Maybe $argument) {
    return $argument->map(function ($mangaUrl) {
        var_dump('started ', $mangaUrl);
        $mangaData = fetchMangaData($mangaUrl);
        var_dump('manga data ready', $mangaData);
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


