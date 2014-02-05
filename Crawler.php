<?php

class Crawler implements CurlNodeCallback
{
    protected $collection;
    protected $nodes;
    protected $domain;
    protected $logger;
    protected $parser;

    /**
     * @param CurlCollection $collection
     * @param CurlNodeFabric $nodes
     * @param ParserCollection $parser
     * @param Logger $logger
     * @param $domain
     */
    public function __construct(CurlCollection $collection,
        CurlNodeFabric $nodes,
        ParserCollection $parser,
        Logger $logger,
        $domain) {
        $this->collection = $collection;
        $this->domain = $domain;
        $this->nodes = $nodes;
        $this->parser = $parser;
        $this->logger = $logger;
    }

    /**
     * @param $uri
     */
    public function Run($uri) {
        $this->Add(array($uri));
    }

    /**
     * @param array $uris
     * @param bool $first
     */
    protected function Add($uris, $first = false) {
        foreach ($uris as $uri) {
            $url = $this->formURL($uri);
            if (!$url)
                continue;

            $node = $this->nodes->getNode($url);
            if (!$node)
                continue;

            $node->register($this);
            $this->collection->Add($node, $lazy=true, $first);
        }
        $this->collection->Run();
    }

    /**
     * @param CurlNode $node
     * @return void
     */
    public function finish($node) {
        $status = $node->getStatus();

        if ($status >= 500) {
            // read this uri
            $this->log($node->getURL()." added to queue again due status ".$status);
            $this->nodes->removeNode($node);
            $this->Add(array($node->getURL()), $first=true);
            return;
        }

        if ($status >= 400 && $status < 500) {
            // ignore this kind of pages
            $this->log($node->getURL()." ignored due status ".$status);
            $this->nodes->markNodeAsUsed($node);
            return;
        }

        $parser = $this->parser->find($node->getURL());
        if ($parser) {
            $uris = $parser->parse($node->getURL(), $node->getHTML());
            $this->Add($uris);
        }
        $this->nodes->markNodeAsUsed($node);
        return;
    }

    /**
     * @param $uri
     * @return bool|string
     */
    protected function formURL($uri) {
        $uri = trim($uri);
        if (preg_match('/^http:/i', $uri) && !preg_match('/^http:\/\/'.preg_quote($this->domain, '/').'\//i', $uri)) {
            return false;
        } elseif (preg_match('/^http:/i', $uri)) {
            $uri = preg_replace('/^http:\/\/'.preg_quote($this->domain, '/').'/i', '', $uri);
        }
        if (substr($uri,0,1) != '/')
            return false;
        $uri = preg_replace('/#.*/', '', $uri);
        return $this->domain.$uri;
    }

    /**
     * @param $message
     */
    protected function log($message) {
        $this->logger->log(get_class($this)." ".$message);
    }
}

