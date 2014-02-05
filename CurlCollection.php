<?php
class CurlCollection
{
    public $run_limit = 4;
    public $req_per_int = 1;
    public $req_int_gap = 3;
    public $log_level = 0;

    protected $collection = array();
    protected $buffer_collection = array();
    protected $run = false;
    protected $curl_m_handler = null;
    protected $logger;
    protected $last_ts;
    protected $last_ts_requests = 1;
    protected $freeze = 0;
    protected $statefile = '';


    public function __construct(Logger $logger)
    {
        $this->curl_m_handler = curl_multi_init();
        $this->logger = $logger;
        $this->last_ts = time();
        $this->statefile = __DIR__.DIRECTORY_SEPARATOR."state.curl";
        $this->restoreState();
    }

    /**
     * Add node to collection
     * @param CurlNode $curl_node
     * @param bool $lazy_run
     * @param bool $first
     */
    public function Add(CurlNode $curl_node, $lazy_run = false, $first = false)
    {
        if ($first)
            array_unshift($this->buffer_collection, $curl_node);
        else
            $this->buffer_collection[] = $curl_node;

        $this->log("Queued ".$curl_node->getURL(), 10);

        if (!$lazy_run && !$this->run) {
            $this->fill_collection();
            $this->run_loop();
        }
    }

    public function Run() {
        if ($this->run)
            return;

        $this->fill_collection();
        $this->run_loop();
    }

    /**
     * Add request to curl_multi (run request)
     * @param CurlNode $curl_node
     */
    protected function add_to_collection(CurlNode $curl_node) {
        $curl_handler = $curl_node->getHandler();
        curl_multi_add_handle($this->curl_m_handler, $curl_handler);
        $this->collection[(int)$curl_handler] = $curl_node;
        $this->log("Started ".$curl_node->getURL(), 5);
    }

    /**
     * Count request frequency
     * @return int seconds to sleep
     */
    protected function check_freq() {
        $allow_more = 0;
        if (($this->last_ts + $this->req_int_gap) > time() && $this->last_ts_requests >= $this->req_per_int) {
            $allow_more = ($this->last_ts + $this->req_int_gap) - time();
        } elseif (($this->last_ts + $this->req_int_gap) <= time()) {
            $this->last_ts_requests = 1;
            $this->last_ts = time();
        } else {
            $this->last_ts_requests ++;
        }
        return $allow_more;
    }

    protected function run_loop() {
        $this->run = true;
        while ($this->run_once()) {};
        $this->run = false;
    }

    /**
     * Main part - execute curl_multi for run http requests
     * @return bool
     */
    protected function run_once()
    {
        $active = null;
        do {
            $mrc = curl_multi_exec($this->curl_m_handler, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);

        curl_multi_select($this->curl_m_handler);

        while ($result = curl_multi_info_read($this->curl_m_handler)) {
            if (array_key_exists((int)$result['handle'], $this->collection)) {
                /** @var $curl_node CurlNode */
                $curl_node = $this->collection[(int)$result['handle']];
                $html = curl_multi_getcontent($result['handle']);

                $this->log("Finish ".$curl_node->getURL()." ".strlen($html)." bytes", 1);

                curl_multi_remove_handle($this->curl_m_handler, $result['handle']);
                unset($this->collection[(int)$result['handle']]);

                $curl_node->finish($html);
            }
        }

        $this->fill_collection();

        if (count($this->collection) == 0) {
            if (count($this->buffer_collection) == 0) {
                return false;
            } elseif ($this->freeze) {
                $this->saveState();
                return false;
            } else {
                return true;
            }
        } else {
            return true;
        }
    }

    /**
     * Move part of queued request to runtime queue (run some requests from queue)
     */
    protected function fill_collection() {
        if ($this->freeze)
          return;

        $c = count($this->collection);
        while ($c < $this->run_limit) {
            if (count($this->buffer_collection) == 0)
                break;
            if ($sleep = $this->check_freq()) { // frequency limit
                // if runtime queue empty, just sleep
                // else continue work for catch other finished requests
                if ($c == 0) {
                    $this->log("Freq overflow, sleep ".$sleep." sec", 10);
                    sleep($sleep);
                    continue;
                } else {
                    $this->log("Freq overflow, loop", 10);
                    break;
                }
            }
            $el = array_shift($this->buffer_collection);
            if ($el instanceof CurlNode)
                $this->add_to_collection($el);
            $c = count($this->collection);
        }
    }

    protected function log($message, $level) {
        if ($this->log_level >= $level)
            $this->logger->log(get_class($this)." ".$message);
    }

    protected function saveState() {
        $state = serialize($this->buffer_collection);
        file_put_contents($this->statefile, $state);
        $this->log("State saved", 1);
    }

    protected function restoreState() {
        $state = '';
        if (file_exists($this->statefile))
            $state = file_get_contents($this->statefile);

        if ($state) {
            $this->buffer_collection = unserialize($state);
            if ($this->buffer_collection)
                $this->log("State restored", 1);
            else
                $this->buffer_collection = array();
        }
    }

    public function interrupt() {
        $this->freeze = 1;
    }
}

class CurlNode
{
    protected $handler;
    protected $callback;
    protected $url;
    protected $html;
    protected $status;
    protected $real_url;
    protected $options;

    public function __construct($url, $options) {
        $this->handler = curl_init();
        $this->url = $url;
        $this->options = $options;
        $this->setDefaultOptions();
        $this->setOptions($options);
    }

    public function register(CurlNodeCallback $callback) {
        $this->callback = $callback;
    }

    public function getHandler() {
        return $this->handler;
    }

    public function getURL() {
        return $this->url;
    }

    public function getRealURL() {
        return $this->real_url;
    }

    public function getHTML() {
        return $this->html;
    }

    public function finish($html) {
        $this->html = $html;
        $this->status = curl_getinfo($this->handler, CURLINFO_HTTP_CODE);
        $this->real_url = curl_getinfo($this->handler, CURLINFO_EFFECTIVE_URL);

        if ($this->callback) {
            $this->callback->finish($this);
        }

        curl_close($this->handler);
    }

    public function Run() {
        $html = curl_exec($this->handler);
        $this->finish($html);
    }

    public function getStatus() {
        return $this->status;
    }

    protected function setDefaultOptions() {
        curl_setopt($this->handler, CURLOPT_URL, $this->url);
        curl_setopt($this->handler, CURLOPT_RETURNTRANSFER, true);
    }

    public function setOptions($options = array()) {
        foreach ($options as $key=>$value) {
            curl_setopt($this->handler, $key, $value);
        }
    }

    public function __sleep() {
        return array('url', 'options');
    }

    public function __wakeup() {
        $this->handler = curl_init();
        $this->setDefaultOptions();
        $this->setOptions($this->options);
    }
}

class CurlNodeFabric
{
    protected $instances = array();
    protected $curl_options = array(
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/535.19 (KHTML, like Gecko) Chrome/18.0.1025.168 Safari/535.19'
    );
    protected $statefile;

    /**
     * @param array $options curl options
     */
    public function __construct($options = array()) {
        $this->curl_options = $options + $this->curl_options;
        $this->statefile = __DIR__.DIRECTORY_SEPARATOR."state.nodes";
    }

    /**
     * @param $url
     * @return bool|CurlNode return false if node was requested
     */
    public function getNode($url) {
        if (array_key_exists($url, $this->instances)) {
            return false;
        }

        $this->instances[$url] = new CurlNode($url, $this->curl_options);

        return $this->instances[$url];
    }

    public function markNodeAsUsed(CurlNode $node) {
        $this->instances[$node->getURL()] = true;
    }

    public function removeNode(CurlNode $node) {
        unset($this->instances[$node->getURL()]);
    }

    public function resetFabric() {
        $this->instances = array();
    }

    protected function saveState() {
        file_put_contents($this->statefile, join("\n", array_keys($this->instances)));
    }

    protected function restoreState() {
        $state = '';
        if (file_exists($this->statefile))
            $state = file_get_contents($this->statefile);

        if ($state) {
            foreach(explode("\n", $state) as $line)
                $this->instances[$line] = true;
        }
    }


    public function interrupt() {
        $this->saveState();
    }
}

interface CurlNodeCallback
{
    /**
     * @abstract
     * @param CurlNode $node
     * @return mixed
     */
    public function finish($node);
}