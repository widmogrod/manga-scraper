<?php

use Widmogrod\FantasyLand\Monad;
use Widmogrod\Functional as f;
use Widmogrod\Monad\Either;
use Widmogrod\Monad\Maybe;
use Widmogrod\Primitive\Listt;

function liftM3(
    callable $transformation,
    Monad $ma,
    Monad $mb,
    Monad $mc
)
{
    return $ma->bind(function ($a) use ($mb, $mc, $transformation) {
        return $mb->bind(function ($b) use ($mc, $a, $transformation) {
            return $mc->bind(function ($c) use ($a, $b, $transformation) {
                return call_user_func($transformation, $a, $b, $c);
            });
        });
    });
}


// get :: a -> {b} -> Maybe b
function get($key, Listt $array = null)
{
    return f\curryN(2, function ($key, Listt $array) {
        return array_key_exists($key, $array->extract())
            ? Maybe\just($array->extract()[$key])
            : Maybe\nothing();
    })(...func_get_args());
}

const curlHeaders = [
    'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10.13; rv:56.0) Gecko/20100101 Firefox/56.0',
];

const getUrl = 'getUrl';

// String -> Either ErrCurl String
function getUrl($url, $ttl = null)
{
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_HEADER, 0);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_VERBOSE, false);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 0);
    // curl_setopt($curl, CURLOPT_REFERER, $referer);
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_TIMEOUT, $ttl > 0 ? $ttl : 5);
    curl_setopt($curl, CURLOPT_HTTPHEADER, curlHeaders);
    curl_setopt($curl, CURLOPT_ENCODING, '');
    $result = curl_exec($curl);
    $errno = curl_errno($curl);
    $error = curl_error($curl);
    curl_close($curl);

//    var_dump([
//        '$url' => $url,
//        '$errno' => $errno,
//        '$length' => strlen($result),
//    ]);

//    if (strlen($result) < 10000) {
//        file_put_contents('./'.preg_replace('/[^\wd]+/', '_', $url), $result);
//    }

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

// DOMDocument -> String -> Maybe (Listt DOMElement)
function xpath(\DOMDocument $doc, $path)
{
    $xpath = new \DOMXPath($doc);
    $elements = $xpath->query($path);

    return $elements->length > 0
        ? Maybe\just(f\fromIterable(iterator_to_array($elements)))
        : Maybe\nothing();
}

function unique(callable $predicate, array $buffer = [])
{
    return function ($value) use ($predicate, &$buffer) {
        $key = $predicate($value);
        if (isset($buffer[$key])) {
            return false;
        }

        $buffer[$key] = true;
        return true;
    };
}
