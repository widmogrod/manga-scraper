<?php



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

