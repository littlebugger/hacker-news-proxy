<?php

namespace HackerNewsProxy;

use CURLFile;
use Exception;
use RuntimeException;

/**
 * @author littlebugger <https://github.com/littlebugger>
 * @license http://unlicense.org
 * @package HackerNewsProxy
 *
 * Credits to:
 *    https://github.com/zounar/php-proxy/
 *
 * Usage:
 *    Create enviroment
 *        TARGET_URL                proxy final target
 *        CUSTOM_PROXY_MODIFIER     Special symbol to add 
 *
 * Debug:
 *    To debug, set $DEBUG to any non-zero value
 *
 * Compatibility:
 *    PHP >=5.6
 *    libcurl
 *    gzip
 *    PHP safe_mode disabled
 */
class Proxy
{
    /**
     * If true, PHP safe mode compatibility will not be checked
     * (you may not need it if no POST files are sent over proxy)
     * @var bool
     */
    public static $IGNORE_SAFE_MODE = false;

    /**
     * Enable debug mode (you can do it by sending Proxy-Debug header as well).
     * This value overrides any value specified in Proxy-Debug header.
     * @var bool
     */
    public static $DEBUG = false;

    /**
     * When set to false the fetched header is not included in the result
     * @var bool
     */
    public static $CURLOPT_HEADER = true;

    /**
     * When set to false the fetched result is echoed immediately instead of waiting for the fetch to complete first
     * @var bool
     */
    public static $CURLOPT_RETURNTRANSFER = true;

    /**
     * Target URL is set via Proxy-Target-URL header. For debugging purposes you might set it directly here.
     * This value overrides any value specified in Proxy-Target-URL header.
     * @var string
     */
    public static $TARGET_URL = 'https://news.ycombinator.com';

    /**
     * Line break for debug purposes
     * @var string
     */
    protected static $HR = PHP_EOL . PHP_EOL . '----------------------------------------------' . PHP_EOL . PHP_EOL;

    /**
     * REGEX Pattern strings for word substituon
     * @var string[]
     */
    protected static $REGEX_PATTERNS = ['/(\w{6,})/i'];

    /**
     * REGEX Modifying strings
     * @var string[]
     */
    protected static $REPLACEMENT_PATTERNS = ['$1™'];


    /**
     * @return string
     */
    protected static function substitute($string)
    {
        return preg_replace(
            static::$REGEX_PATTERNS,
            static::$REPLACEMENT_PATTERNS,
            $string
        );
    }

    /**
     * @return void
     */
    protected static function editNode(\DOMNode $domNode)
    {
        $domNode->nodeValue = static::substitute($domNode->nodeValue);
    }

    /**
     * @return bool
     */
    protected static function isHTMLPage($pageCode)
    {
        return preg_match('/^<html/i', $pageCode);
    }

    /**
     * @return string
     */
    protected static function modifyDom($htmlString)
    {
        if (!static::isHTMLPage($htmlString)) { // If responce is from css/js or another page haltedit
            return $htmlString;
        }

        $doc = new \DOMdocument();
        $internalErrors = libxml_use_internal_errors(true);
        $htmlString = mb_convert_encoding($htmlString , 'HTML-ENTITIES', 'UTF-8'); // require mb_string
        $doc->loadHTML($htmlString, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_use_internal_errors($internalErrors);

        $doc = static::loopDOMNode($doc);
        return $doc->saveHTML();
    }

    /**
     * @return \DOMNode
     */
    protected static function loopDOMNode(\DOMNode $domNode)
    {
        foreach ($domNode->childNodes as $node) {
            if (!$node->hasChildNodes()) {
                static::editNode($node);
            } else {
                static::loopDOMNode($node);
            }
        }
        return $domNode;
    }

    /**
     * @return string[]
     */
    protected static function getSkippedHeaders()
    {
        return [
            'HTTP_HOST',
            'HTTP_ACCEPT_ENCODING'
        ];
    }

    /**
     * Return variable or default value if not set
     * @param mixed $variable
     * @param mixed|null $default
     * @return mixed
     * @noinspection PhpParameterByRefIsNotUsedAsReferenceInspection
     */
    protected static function ri(&$variable, $default = null)
    {
        if (isset($variable)) {
            return $variable;
        } else {
            return $default;
        }
    }

    /**
     * @param string $message
     */
    protected static function exitWithError($message)
    {
        http_response_code(500);
        echo 'PROXY ERROR: ' . $message;
        exit(500);
    }

    /**
     * @return bool
     */
    public static function isInstalledWithComposer()
    {
        $autoloaderPath = join(DIRECTORY_SEPARATOR, [dirname(dirname(__DIR__)), 'autoload.php']);
        return is_readable($autoloaderPath);
    }

    /**
     * @return void
     */
    public static function registerErrorHandlers()
    {
        set_error_handler(function ($code, $message, $file, $line) {
            Proxy::exitWithError("($code) $message in $file at line $line");
        }, E_ALL);

        set_exception_handler(function (Exception $ex) {
            Proxy::exitWithError("{$ex->getMessage()} in {$ex->getFile()} at line {$ex->getLine()}");
        });
    }

    /**
     * @return void
     */
    public static function checkCompatibility()
    {
        if (!static::$IGNORE_SAFE_MODE && function_exists('ini_get') && ini_get('safe_mode')) {
            throw new RuntimeException('Safe mode is enabled, this may cause problems with uploading files');
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('libcurl is not installed on this server');
        }

        if (!function_exists('gzdecode')) {
            throw new RuntimeException('gzip is not installed on this server');
        }
    }

    /**
     * @return bool
     */
    protected static function hasCURLFileSupport()
    {
        return class_exists('CURLFile');
    }

    /**
     * @param string $headerString
     * @return string[]
     */
    protected static function splitResponseHeaders($headerString)
    {
        $results = [];
        $headerLines = preg_split('/[\r\n]+/', $headerString);
        foreach ($headerLines as $headerLine) {
            if (empty($headerLine)) {
                continue;
            }

            // Header contains HTTP version specification and path
            if (strpos($headerLine, 'HTTP/') === 0) {
                // Reset the output array as there may by multiple response headers
                $results = [];
                continue;
            }

            $results[] = "$headerLine";
        }

        return $results;
    }

    /**
     * Returns true if response code matches 2xx or 3xx
     * @param int $responseCode
     * @return bool
     */
    public static function isResponseCodeOk($responseCode)
    {
        return preg_match('/^[23]\d\d$/', $responseCode) === 1;
    }

    /**
     * @return string
     */
    protected static function getDynamicUrl()
    {
        return $_SERVER['REQUEST_URI'];
    }

    /**
     * @return string
     */
    protected static function getTargetUrl()
    {
        if (!empty(static::$TARGET_URL)) {
            $targetURL = static::$TARGET_URL . static::getDynamicUrl();
        } else {
            throw new RuntimeException('TARGET_URL ENV is invalid');
        }

        if (filter_var($targetURL, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('"' . $targetURL . '" is invalid');
        }

        return $targetURL;
    }

    /**
     * @return bool
     */
    protected static function isDebug()
    {
        return static::$DEBUG;
    }

    /**
     * @param string[] $skippedHeaders
     * @return string[]
     */
    protected static function getIncomingRequestHeaders($skippedHeaders = [])
    {
        $results = [];
        foreach ($_SERVER as $key => $value) {
            if (in_array($key, $skippedHeaders)) {
                continue;
            }

            $loweredKey = strtolower($key);
            if (strpos($loweredKey, 'http_') === 0) {
                // Remove prefix
                $key = substr($loweredKey, strlen('http_'));
                // Replace underscores with dashes
                $key = str_replace('_', '-', $key);
                // Capital each word
                $key = ucwords($key, '-');

                $results[] = "$key: $value";
            }
        }

        return $results;
    }

    /**
     * @param string $targetURL
     * @return false|resource
     */
    protected static function createRequest($targetURL)
    {
        $request = curl_init($targetURL);

        // Set input data
        $requestMethod = strtoupper(static::ri($_SERVER['REQUEST_METHOD']));
        if ($requestMethod === "PUT" || $requestMethod === "PATCH") {
            curl_setopt($request, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
        } elseif ($requestMethod === "POST") {
            $data = array();

            if (!empty($_FILES)) {
                if (!static::hasCURLFileSupport()) {
                    curl_setopt($request, CURLOPT_SAFE_UPLOAD, false);
                }

                foreach ($_FILES as $fileName => $file) {
                    $filePath = realpath($file['tmp_name']);

                    if (static::hasCURLFileSupport()) {
                        $data[$fileName] = new CURLFile($filePath, $file['type'], $file['name']);
                    } else {
                        $data[$fileName] = '@' . $filePath;
                    }
                }
            }

            curl_setopt($request, CURLOPT_POSTFIELDS, $data + $_POST);
        }

        $headers = static::getIncomingRequestHeaders(static::getSkippedHeaders());

        curl_setopt_array($request, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADER => static::$CURLOPT_HEADER,
            CURLOPT_RETURNTRANSFER => static::$CURLOPT_RETURNTRANSFER,
            CURLINFO_HEADER_OUT => true,
            CURLOPT_HTTPHEADER => $headers
        ]);

        return $request;
    }

    /**
     * @return int HTTP response code (200, 404, 500, etc.)
     */
    public static function run()
    {
        $debug = static::isDebug();
        $targetURL = static::getTargetUrl();

        $request = static::createRequest($targetURL);

        // Get response
        $response = curl_exec($request);

        $headerSize = curl_getinfo($request, CURLINFO_HEADER_SIZE);
        $responseHeader = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);
        $responseInfo = curl_getinfo($request);
        $responseCode = static::ri($responseInfo['http_code'], 500);
        $redirectCount = static::ri($responseInfo['redirect_count'], 0);
        $requestHeaders = preg_split('/[\r\n]+/', static::ri($responseInfo['request_header'], ''));
        if ($responseCode === 0) {
            $responseCode = 404;
        }

        $finalRequestURL = curl_getinfo($request, CURLINFO_EFFECTIVE_URL);
        if ($redirectCount > 0 && !empty($finalRequestURL)) {
            $finalRequestURLParts = parse_url($finalRequestURL);
            $effectiveURL = static::ri($finalRequestURLParts['scheme'], 'http') . '://' .
                static::ri($finalRequestURLParts['host']) . static::ri($finalRequestURLParts['path'], '');
        }

        curl_close($request);

        //----------------------------------

        // Split header text into an array.
        $responseHeaders = static::splitResponseHeaders($responseHeader);
        // Pass headers to output
        foreach ($responseHeaders as $header) {
            $headerParts = preg_split('/:\s+/', $header, 2);
            if (count($headerParts) !== 2) {
                throw new RuntimeException("Can not parse header \"$header\"");
            }

            $headerName = $headerParts[0];
            $loweredHeaderName = strtolower($headerName);

            $headerValue = $headerParts[1];
            $loweredHeaderValue = strtolower($headerValue);

            // Pass following headers to response
            if (in_array($loweredHeaderName,
                ['content-type', 'content-language', 'content-security', 'server'])) {
                header("$headerName: $headerValue");
            } elseif (strpos($loweredHeaderName, 'x-') === 0) {
                header("$headerName: $headerValue");
            } // Replace cookie domain and path
            elseif ($loweredHeaderName === 'set-cookie') {
                $newValue = preg_replace('/((?>domain)\s*=\s*)[^;\s]+/', '\1.' . $_SERVER['HTTP_HOST'], $headerValue);
                $newValue = preg_replace('/\s*;?\s*path\s*=\s*[^;\s]+/', '', $newValue);
                header("$headerName: $newValue", false);
            } // Decode response body if gzip encoding is used
            elseif ($loweredHeaderName === 'content-encoding' && $loweredHeaderValue === 'gzip') {
                $responseBody = gzdecode($responseBody);
            }
        }

        http_response_code($responseCode);

        //----------------------------------

        if ($debug) {
            echo 'Headers sent to proxy' . PHP_EOL . PHP_EOL;
            echo implode(PHP_EOL, static::getIncomingRequestHeaders());
            echo static::$HR;

            if (!empty($_GET)) {
                echo '$_GET sent to proxy' . PHP_EOL . PHP_EOL;
                print_r($_GET);
                echo static::$HR;
            }

            if (!empty($_POST)) {
                echo '$_POST sent to proxy' . PHP_EOL . PHP_EOL;
                print_r($_POST);
                echo static::$HR;
            }

            echo 'Headers sent to target' . PHP_EOL . PHP_EOL;
            echo implode(PHP_EOL, $requestHeaders);
            echo static::$HR;

            if (isset($effectiveURL) && $effectiveURL !== $targetURL) {
                echo "Request was redirected from \"$targetURL\" to \"$effectiveURL\"";
                echo static::$HR;
            }

            echo 'Headers received from target' . PHP_EOL . PHP_EOL;
            echo $responseHeader;
            echo static::$HR;

            echo 'Headers sent from proxy to client' . PHP_EOL . PHP_EOL;
            echo implode(PHP_EOL, headers_list());
            echo static::$HR;

            echo 'Body sent from proxy to client' . PHP_EOL . PHP_EOL;
        }


        echo static::modifyDom($responseBody);

        return $responseCode;
    }
}

if (!Proxy::isInstalledWithComposer()) {
    Proxy::checkCompatibility();
    Proxy::registerErrorHandlers();
    $responseCode = Proxy::run();

    if (Proxy::isResponseCodeOk($responseCode)) {
        exit(0);
    } else {
        exit($responseCode);
    }
}
