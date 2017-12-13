<?php
require_once 'vendor/autoload.php';

use Widmogrod\Functional as f;
use Widmogrod\Monad\Either;
use Widmogrod\Monad\IO;
use Widmogrod\Monad\Maybe;
use Widmogrod\Primitive\Listt;

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

    public function getMangaName()
    {
        if (false !== preg_match('/^(?P<name>.+)\s+(?P<number>[\d\.]+)$/i', $this->name, $matches)) {
            return $matches['name'];
        }
    }

    public function getNumber()
    {
        if (false !== preg_match('/(?P<number>[\d\.]+)$/i', $this->name, $matches)) {
            return $matches['number'];
        }
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

function uniqueChapter()
{
    return unique(function (Chapter $value) {
        return $value->getUrl();
    });
}

const chaptersList = 'chaptersList';

// DOMDocument -> Maybe (Listt Chapter)
function chaptersList(\DOMDocument $doc)
{
    $xpath =
        "//ul[contains(normalize-space(@class), 'chapter_list')]" .
        "//li" .
        "//a";

    $unique = f\compose(Listt::of, f\filter(uniqueChapter()));
    return f\map($unique, f\map(f\map(elementToChapterItem), xpath($doc, $xpath)));
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

    public function getNumber()
    {
        return $this->isFeatured()
            ? null
            : (int)ltrim($this->name, "0");
    }

    public function isFeatured()
    {
        return false === preg_match('/^[\d\.]+$/i', $this->name);
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

// DOMDocument -> Maybe (Listt Page)
function chapterPages(\DOMDocument $doc)
{
    $xpath = "//option[contains(normalize-space(@value), '//www.')]";
    $pageNumberOnly = function(Page $page) {
        return !$page->isFeatured();
    };

    $unique = f\compose(Listt::of, f\filter(uniquePage()), Listt::of, f\filter($pageNumberOnly));
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

// DOMDocument -> Maybe (Listt PageImage)
function pageImageURL(\DOMDocument $doc)
{
    $xpath =
        "//div[contains(normalize-space(@id), 'viewer')]" .
        "//img";

    $unique = f\compose(Listt::of, f\filter(uniquePageImage()));
    return f\map($unique, f\map(f\map(elementToPageImage), xpath($doc, $xpath)));
}


// getChapters :: String -> Maybe (Listt Chapter)
function getChapters($mangaUrl)
{
    return call_user_func(f\pipeline(
        getUrl
        , f\bind(toDomDoc)
        , Either\toMaybe
        , f\bind(chaptersList)
    ), $mangaUrl);
}

// getChapterPages :: Chapter -> Maybe (Listt Page)
function getChapterPages(Chapter $chapter)
{
    return call_user_func(f\pipeline(
        getUrl
        , f\bind(toDomDoc)
        , Either\toMaybe
        , f\bind(chapterPages)
    ), $chapter->getUrl());
}

// getPagesImageURL :: Page -> Maybe (Listt PageImage)
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

// String -> Maybe (Listt (Maybe ChapterPage))
function fetchMangaData($mangaUrl)
{
    // getChapters :: String -> Maybe (Listt Chapter)
    return getChapters($mangaUrl)
        ->map(function (Listt $chapters) {
            return $chapters
                ->bind(function (Chapter $chapter) {
                    // getChapterPages :: Chapter -> Maybe (Listt Page)
                    return getChapterPages($chapter)
                        ->bind(function (Listt $pages) use ($chapter) {
                            return $pages
                                ->bind(function (Page $page) use ($chapter) {
                                    // getPagesImageURL :: Page -> Maybe (Listt PageImage)
                                    return getPagesImageURL($page)
                                        ->bind(function (Listt $images) use ($chapter, $page) {
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
    // Multiplying by 100 give buffer for each chapter to have 1000 pages
    $chapterNo = $chapterPage->getChapter()->getNumber() * 1000;

    return liftM3(
        function ($path, $imageContent, ChapterPage $chapterPage) use ($chapterNo) {
            $pageNo = $chapterPage->getPage()->getNumber();
            $number = $chapterNo + $pageNo;

            // Padding absolute page allows to make sorting robust for readers
            $number = str_pad("$number", 20, "0", STR_PAD_LEFT);

            return writeFile($path . '/' . $number . '.jpg', $imageContent);
        },
        makeDirectory(
            './manga/' . $chapterPage->getChapter()->getMangaName()
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
        var_dump('manga data ready');
        $afterDownload = $mangaData->map(f\map(f\map(download)));
        var_dump('manga first run');
        $afterDownload->map(function (Listt $Listt) {
            $ttl = 2;

            do {
                var_dump('re-download');
                $toRetry = f\filter(function (Maybe\Maybe $maybe) {
                    return $maybe->extract() instanceof Either\Left;
                }, $Listt);

                $count = count($toRetry);
                $Listt = Listt::of($toRetry)->map(function ($a) use (&$ttl, $count) {
                    var_dump('retry', $ttl, $count);

                    return failed($a, $ttl);
                });
            } while (count($toRetry) > 0);
        });
    });
})->run();


