<?php

/**
 * Request handling
 * @package framework
 * @subpackage request
 */

/**
 * Data request details
 *
 * This is an interface to HTTP request details. All request data
 * must be white-listed and sanitized by module set filters.
 */
class Hm_Request {

    /* sanitized $_POST variables */
    public $post = [];

    /* sanitized $_GET variables */
    public $get = [];

    /* sanitized $_COOKIE variables */
    public $cookie = [];

    /* sanitized $_SERVER variables */
    public $server = [];

    /* $_ENV variables (fallback for missing $_SERVER vals) */
    private $env = [];

    /* request type. either AJAX or HTTP */
    public $type = '';

    /* PHP sapi method used for the request */
    public $sapi = '';

    /* Output format, either Hm_Format_JSON or Hm_Format_HTML5 */
    public $format = '';

    /* bool indicating if the request was over SSL/TLS */
    public $tls = false;

    /* bool indicating if this looks like a mobile OS request */
    public $mobile = false;

    /* URL path */
    public $path = '';

    /* allowed AJAX output field names defined by module sets */
    public $allowed_output = [];

    /* bool indicating unknown input data */
    public $invalid_input_detected = false;

    /* invalid input fields */
    public $invalid_input_fields = [];

    /* uploaded file details */
    public $files = [];

    /* module filters */
    public $filters = [];

    /* HTTP request method */
    public $method = false;

    /* force mobile ui */
    private $always_mobile = false;

    /**
     * Process request details
     * @param array $filters list of input filters from module sets
     * @param object $config site config object
     */
    public function __construct($filters, $config) {
        if ($config->get('always_mobile_ui', false)) {
            $this->always_mobile = true;
        }
        $this->filters = $filters;
        $this->filter_request_input();
        $this->get_other_request_details();
        $this->files = $_FILES;
        if (!$config->get('disable_empty_superglobals', false)) {
            $this->empty_super_globals();
        }
        Hm_Debug::add('Using sapi: '.$this->sapi, 'info');
        Hm_Debug::add('Request type: '.$this->type, 'info');
        Hm_Debug::add('Request path: '.$this->path, 'info');
        Hm_Debug::add('TLS request: '.intval($this->tls), 'info');
        Hm_Debug::add('Mobile request: '.intval($this->mobile), 'info');
    }

    /**
     * Sanitize and filter user and server input
     * @return void
     */
    private function filter_request_input() {
        if (array_key_exists('allowed_server', $this->filters)) {
            $this->server = $this->filter_input(INPUT_SERVER, $this->filters['allowed_server']);
            $this->env = $this->filter_input(INPUT_ENV, $this->filters['allowed_server']);
        }
        if (array_key_exists('allowed_post', $this->filters)) {
            $this->post = $this->filter_input(INPUT_POST, $this->filters['allowed_post']);
        }
        if (array_key_exists('allowed_get', $this->filters)) {
            $this->get = $this->filter_input(INPUT_GET, $this->filters['allowed_get']);
        }
        if (array_key_exists('allowed_cookie', $this->filters)) {
            $this->cookie = $this->filter_input(INPUT_COOKIE, $this->filters['allowed_cookie']);
        }
    }

    /**
     * Collect other useful details about a request
     * @return void
     */
    private function get_other_request_details() {
        $this->sapi = php_sapi_name();
        if (array_key_exists('allowed_output', $this->filters)) {
            $this->allowed_output = $this->filters['allowed_output'];
        }
        if (array_key_exists('REQUEST_URI', $this->server)) {
            $this->path = $this->get_clean_url_path($this->server['REQUEST_URI']);
        }
        if (array_key_exists('REQUEST_METHOD', $this->server)) {
            $this->method = $this->server['REQUEST_METHOD'];
        } elseif (array_key_exists('REQUEST_METHOD', $this->env)) {
            $this->method = $this->env['REQUEST_METHOD'];
            $this->server = $this->env;
        }
        $this->get_request_type();
        $this->is_tls();
        $this->is_mobile();
    }

    /**
     * Empty out super globals.
     * @return void
     */
    private function empty_super_globals() {
        $_POST = [];
        $_SERVER = [];
        $_GET = [];
        $_COOKIE = [];
        $_FILES = [];
        $_REQUEST = [];
        $_ENV = [];

        foreach (array_keys($GLOBALS) as $key) {
            unset($GLOBALS[$key]);
        }
    }

    /**
     * Filter specified input against module defined filters
     * @param type string the type of input (POST, GET, COOKIE, etc)
     * @param filters array list of input filters from module sets
     * @return array filtered input data
     */
    public function filter_input($type, $filters) {
        $data = Hm_Functions::filter_input_array($type, $filters);
        if ($data === false || $data === NULL) {
            return [];
        }
        return $data;
    }

    /**
     * Look at the HTTP_USER_AGENT value and set a mobile OS flag
     * @return void
     */
    private function is_mobile() {
        if ($this->always_mobile) {
            $this->mobile = true;
            return;
        }

        if (!empty($_COOKIE['is_mobile_screen']) && $_COOKIE['is_mobile_screen'] == '1') {
            $this->mobile = true;
            return;
        }

        if (!empty($this->server['HTTP_USER_AGENT'])) {
            if (preg_match("/(iphone|ipod|ipad|android|blackberry|webos|opera mini)/i", $this->server['HTTP_USER_AGENT'])) {
                $this->mobile = true;
            }
        }
    }

    /**
     * Determine if a request was done over TLS
     * @return void
     */
    private function is_tls() {
        if (!empty($this->server['HTTPS']) && mb_strtolower($this->server['HTTPS']) == 'on') {
            $this->tls = true;
        } elseif (!empty($this->server['REQUEST_SCHEME']) && mb_strtolower($this->server['REQUEST_SCHEME']) == 'https') {
            $this->tls = true;
        }
    }

    /**
     * Determine the request type, either AJAX or HTTP
     * @return void
     */
    private function get_request_type() {
        if ($this->is_ajax()) {
            $this->type = 'AJAX';
            $this->format = 'Hm_Format_JSON';
        } else {
            $this->type = 'HTTP';
            $this->format = 'Hm_Format_HTML5';
        }
    }

    /**
     * Determine if a request is an AJAX call
     * @return bool true if the request is from an AJAX call
     */
    public function is_ajax() {
        return !empty($this->server['HTTP_X_REQUESTED_WITH']) && mb_strtolower($this->server['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    /**
     * Make sure a url path is sane
     * @param string $uri path to check
     * @return string clean url path
     */
    private function get_clean_url_path($uri) {
        if (mb_strpos($uri, '?') !== false) {
            $parts = explode('?', $uri, 2);
            $path = $parts[0];
        } else {
            $path = $uri;
        }
        $path = str_replace('index.php', '', $path);
        if (mb_substr($path, -1) != '/') {
            $path .= '/';
        }
        return $path;
    }
}
