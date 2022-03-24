# Hacker™ News Proxy

This proxy script modifies Hacker News with ™ symbol. Works for all common request types 
including GET, POST requests with files, PATCH and PUT requests. It has minimal set of requirements 
(PHP >=5.6, libcurl, gzip) which are available even on the smallest free hostings and has its own simple authorization 
and cookie support.

## How to use
* Copy the [index.php](index.php) script to publicly-accessible folder of a PHP web server (the script is standalone and has no PHP dependencies)
* Make a cURL request targeting this script or open page with browser

## How to use (via composer)
This might be useful when you want to redirect requests coming into your app. 

* Run `composer require little_debugger/hacker-news-proxy`
* Add `Proxy::run();` line to where you want to execute it (usually into a controller action)
  * In this example, the script is in `AppController` - `actionProxy`:
    ```
    use HackerNewsProxy\Proxy;
    
    class AppController extends Controller {

        public function actionProxy() {
            // Do your custom logic before running proxy
            $responseCode = Proxy::run();
            // Do your custom logic after running proxy
            // You can utilize HTTP response code returned from the run() method
        }
    }
    ```
* Make a cURL request to your web
  * In the example, it would be `http://your-web.com/app/proxy`