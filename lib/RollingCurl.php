<?php
/*
Authored by Josh Fraser (www.joshfraser.com)
Released under Apache License 2.0
Maintained by Alexander Makarov, http://rmcreative.ru/
$Id$
*/
include_once(__DIR__."/defines.php");
include_once(__DIR__."/ProxyFinder.php");

/**
 * Class that represent a single curl request
 */
class RollingCurlRequest {
	public $url = false;
	public $method = 'GET';
	public $post_data = null;
	public $headers = null;
	public $options = null;
	public $cookie = null;
    /**
     * @param string $url
     * @param string $method
     * @param  $post_data
     * @param  $headers
     * @param  $options
     * @return void
     */
    function __construct($url, $method = "GET", $post_data = null, $headers = null, $options = null, $cookie = null) {
        $this->url = $url;
        $this->method = $method;
        $this->post_data = $post_data;
        $this->headers = $headers;
        $this->options = $options;
        $this->cookie = $cookie;
    }
    /**
     * @return void
     */
    public function __destruct() {
        unset($this->url, $this->method, $this->post_data, $this->headers, $this->options);
    }
}
/**
 * RollingCurl custom exception
 */
class RollingCurlException extends Exception {}
/**
 * Class that holds a rolling queue of curl requests.
 *
 * @throws RollingCurlException
 */
class RollingCurl {
    /**
     * @var int
     *
     * Window size is the max number of simultaneous connections allowed.
	 * 
     * REMEMBER TO RESPECT THE SERVERS:
     * Sending too many requests at one time can easily be perceived
     * as a DOS attack. Increase this window_size if you are making requests
     * to multiple servers or have permission from the receving server admins.
     */
    private $window_size = 5;
    /**
     * @var float
     *
     * Timeout is the timeout used for curl_multi_select.
     */
    private $timeout = 10;
    /**
     * @var string|array
     *
     * Callback function to be applied to each result.
     */
    private $callback;
    /**
     * @var array
     *
     * Set your base options that you want to be used with EVERY request.
     */
    protected $options = array(
		CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_CONNECTTIMEOUT => 60,
        CURLOPT_TIMEOUT => 60,
	);
	
    /**
     * @var array
     */
    private $headers = array();
    /**
     * @var Request[]
     *
     * The request queue
     */
    private $requests = array();
    /**
     * @var RequestMap[]
     *
     * Maps handles to request indexes
     */
    private $requestMap = array();
    /**
     * @param  $callback
     * Callback function to be applied to each result.
     *
     * Can be specified as 'my_callback_function'
     * or array($object, 'my_callback_method').
     *
     * Function should take three parameters: $response, $info, $request.
     * $response is response body, $info is additional curl info.
     * $request is the original request
     *
     * @return void
     */
	function __construct($callback = null) {
        $this->callback = $callback;
    }
    /**
     * @param string $name
     * @return mixed
     */
    public function __get($name) {
        return (isset($this->{$name})) ? $this->{$name} : null;
    }
    /**
     * @param string $name
     * @param mixed $value
     * @return bool
     */
    public function __set($name, $value){
        // append the base options & headers
        if ($name == "options" || $name == "headers") {
            $this->{$name} = $value + $this->{$name};
        } else {
            $this->{$name} = $value;
        }
        return true;
    }
    /**
     * Add a request to the request queue
     *
     * @param Request $request
     * @return bool
     */
    public function add($request) {
         $this->requests[] = $request;
         return true;
    }
    /**
     * Create new Request and add it to the request queue
     *
     * @param string $url
     * @param string $method
     * @param  $post_data
     * @param  $headers
     * @param  $options
     * @return bool
     */
    public function request($url, $method = "GET", $post_data = null, $headers = null, $options = null, $cookie = null) {
         $this->requests[] = new RollingCurlRequest($url, $method, $post_data, $headers, $options, $cookie);
         return true;
    }
    /**
     * Perform GET request
     *
     * @param string $url
     * @param  $headers
     * @param  $options
     * @return bool
     */
    public function get($url, $headers = null, $options = null, $cookie = null) {
        return $this->request($url, "GET", null, $headers, $options, $cookie);
    }
    /**
     * Perform POST request
     *
     * @param string $url
     * @param  $post_data
     * @param  $headers
     * @param  $options
     * @return bool
     */
    public function post($url, $post_data = null, $headers = null, $options = null, $cookie = null) {
        return $this->request($url, "POST", $post_data, $headers, $options, $cookie);
    }
    /**
     * Execute the curl
     *
     * @param int $window_size Max number of simultaneous connections
     * @return string|bool
     */
    public function execute($window_size = null) {
        // rolling curl window must always be greater than 1
        /*if (sizeof($this->requests) == 1) {
            return $this->single_curl();
        } else {*/
            // start the rolling curl. window_size is the max number of simultaneous connections
            return $this->rolling_curl($window_size);
        //}
    }
    /**
     * Performs a single curl request
     *
     * @access private
     * @return string
     */
    private function single_curl() {
        $ch = curl_init();		
        $request = array_shift($this->requests);
        $options = $this->get_options($request);
        curl_setopt_array($ch,$options);
        $output = curl_exec($ch);
        $info = curl_getinfo($ch);
        // it's not neccesary to set a callback for one-off requests
        if ($this->callback) {
            $callback = $this->callback;
            if (is_callable($this->callback)){
                call_user_func($callback, $output, $info, $request);
            }
        }
		else
            return $output;
	return true;
    }
    /**
     * Performs multiple curl requests
     *
     * @access private
     * @throws RollingCurlException
     * @param int $window_size Max number of simultaneous connections
     * @return bool
     */
    private function rolling_curl($window_size = null) {
        if ($window_size)
            $this->window_size = $window_size;
        // make sure the rolling window isn't greater than the # of urls
        //if (sizeof($this->requests) < $this->window_size)
        //    $this->window_size = sizeof($this->requests);
        
        //if ($this->window_size < 2) {
        //    throw new RollingCurlException("Window size must be greater than 1");
        //}
        $master = curl_multi_init();        
        // start the first batch of requests
        for ($i = 0; $i < min($this->window_size, count($this->requests)); $i++) {
            $ch = curl_init();
            $options = $this->get_options($this->requests[$i]);
            curl_setopt_array($ch,$options);
            curl_multi_add_handle($master, $ch);
            // Add to our request Maps
            $key = (string) $ch;
            $this->requestMap[$key] = $i;
        }
        do {
            while(($execrun = curl_multi_exec($master, $running)) == CURLM_CALL_MULTI_PERFORM);
            if($execrun != CURLM_OK)
                break;
            // a request was just completed -- find out which one
            while($done = curl_multi_info_read($master)) {
                // get the info and content returned on the request
                $info = curl_getinfo($done['handle']);
                $key = (string)$done['handle'];
                $request = $this->requests[$this->requestMap[$key]];
                $options = $this->get_options($request);
                //try with proxy
                if ($info['http_code'] != 200 && $proxy = ProxyFinder::findProxy($info['url'], $options[CURLOPT_PROXY])) {
                    $options[CURLOPT_PROXY] = $proxy;
                    $request->options = $options;
                    global $logger;
                    $logger->info("try " . $info['url'] . " with proxy: $proxy");
                    $this->requests[] = $request;
                    $running = 1;
                } else {
                    $output = curl_multi_getcontent($done['handle']);
                    // send the return values to the callback function.
                    $callback = $this->callback;
                    if (is_callable($callback)){
                        unset($this->requestMap[$key]);
                        call_user_func($callback, $output, $info, $request);
                    }
                }
                // start a new request (it's important to do this before removing the old one)
                if ($i < sizeof($this->requests) && isset($this->requests[$i]) && $i < count($this->requests)) {
                    $ch = curl_init();
                    $options = $this->get_options($this->requests[$i]);
                    curl_setopt_array($ch,$options);
                    curl_multi_add_handle($master, $ch);
                    // Add to our request Maps
                    $key = (string) $ch;
                    $this->requestMap[$key] = $i;
                    $i++;
                }
                // remove the curl handle that just completed
                curl_multi_remove_handle($master, $done['handle']);
            }
	    // Block for data in / output; error handling is done by curl_multi_exec
	    if ($running)
                curl_multi_select($master, $this->timeout);
        } while ($running);
        curl_multi_close($master);
        return true;
    }
    /**
     * Helper function to set up a new request by setting the appropriate options
     *
     * @access private
     * @param Request $request
     * @return array
     */
    private function get_options($request) {
        // options for this entire curl object
        $options = $this->__get('options');
		if (ini_get('safe_mode') == 'Off' || !ini_get('safe_mode')) {
            $options[CURLOPT_FOLLOWLOCATION] = 1;
			$options[CURLOPT_MAXREDIRS] = 5;
        }
        $headers = $this->__get('headers');
		// append custom options for this specific request
		if ($request->options) {
            $options = $request->options + $options;
        }
		// set the request URL
        $options[CURLOPT_URL] = $request->url;
        // posting data w/ this request?
        if ($request->post_data) {
            $options[CURLOPT_POST] = 1;
            $options[CURLOPT_POSTFIELDS] = $request->post_data;
        }
        if ($headers) {
            $options[CURLOPT_HEADER] = 0;
            $options[CURLOPT_HTTPHEADER] = $headers;
        }
        return $options;
    }
    /**
     * @return void
     */
    public function __destruct() {
        unset($this->window_size, $this->callback, $this->options, $this->headers, $this->requests);
	}

    public static $rc;
}
?>