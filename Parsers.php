<?php
interface Parser
{
    /**
     * Detect is this parser process URI or not
     * @abstract
     * @param $uri
     * @return bool
     */
    public function check($uri);

    /**
     * @abstract
     * @param $uri
     * @param $html
     * @return array array of URI's
     */
    public function parse($uri, $html);
}

class ParserCollection
{
    /**
     * @var Parser[]
     */
    protected $parsers = array();

    public function register(Parser $parser) {
        $this->parsers[] = $parser;
    }

    public function find($uri) {
        foreach ($this->parsers as $parser) {
            if ($parser->check($uri))
                return $parser;
        }

        return false;
    }
}

class ParserBase implements Parser
{
    protected $logger;
    protected $db;

    // Filter links on page
    protected $rejected_uri_regexp = array();
    protected $allow_uri_regexp = array();

    public function __construct(Logger $logger, PDO $db) {
        $this->logger = $logger;
        $this->db = $db;
    }

    protected function log($message) {
        $this->logger->log(get_class($this)." ".$message);
    }

    public function check($uri) {
        throw new Exception("Must be implemented");
    }

    public function parse($uri, $html) {
        $this->log($uri);
        preg_match_all('/href=["\']?([^"\'>\s]+)/', $html, $matches);
        return $this->filter_uri($matches[1]);
    }

    protected function filter_uri($arr) {
        if (!empty($this->rejected_uri_regexp)) {
            $arr = array_filter($arr, array($this, 'check_reject_uri'));
        }
        if (!empty($this->allow_uri_regexp)) {
            $arr = array_filter($arr, array($this, 'check_allow_uri'));
        }
        return $arr;
    }

    private function check_reject_uri($uri) {
        foreach($this->rejected_uri_regexp as $pattern) {
            if (preg_match($pattern, $uri)) {
                return false;
            }
        }
        return true;
    }

    private function check_allow_uri($uri) {
        foreach($this->allow_uri_regexp as $pattern) {
            if (preg_match($pattern, $uri)) {
                return true;
            }
        }
        return false;
    }
}

/* all parsers here */

class IndexParser extends ParserBase implements Parser
{
    // only forums
    protected $allow_uri_regexp = array(
        '/^(http:\/\/[^\/]+)?\/f\d+-/'
    );
    protected $rejected_uri_regexp = array(
        '/[?#]/'
    );

    public function check($uri)
    {
        if (preg_match('/^http:\/\/[^\/]+\/$/', $uri)) {
            return true;
        }

        return false;
    }

    public function parse($uri, $html)
    {
        $this->save(array(':uri'=>$uri, ':body'=>$html));
        return parent::parse($uri, $html);
    }

    protected function save($arr) {
        //$st = $this->db->prepare("INSERT OR IGNORE INTO collector (uri, body) VALUES(:uri, :body)");
        //$st->execute($arr);
    }
}

class ForumParser extends ParserBase implements Parser
{
    // forums (including pages of forums)
    protected $allow_uri_regexp = array(
        '/^(http:\/\/[^\/]+)?\/f\d+(p\d+)?-/',
        // '/^(http:\/\/[^\/]+)?\/t\d+-/' // links to topic added in parseLinks with DB verification
    );
    protected $rejected_uri_regexp = array(
        '/[?#]/'
    );

    public function check($uri)
    {
        if (preg_match('/\/f\d+(p\d+)?-/', $uri) ||
            preg_match('/search_id=newposts/', $uri)) {
            return true;
        }

        return false;
    }

    public function parse($uri, $html)
    {
        if (preg_match('/\/f(\d+)-([^\/]+)/', $uri, $matches)) {
            // first page of forum, add to db
            $forum_id = $matches[1];
            $title = $matches[2];
            if (preg_match('/<title>(.+?)<\/title>/i', $html, $matches)) {
                $title = $matches[1];
            }
            $this->saveForum($forum_id, $title);
        }

        return $this->parseLinks($uri, $html);

        //return parent::parse($uri, $html);
    }

    protected function parseLinks($uri, $html) {

        $links = array();
        $i1 = 0; $i2 = 0;
        if (preg_match_all('/<tr>(.*?)<\/tr>/s', $html, $match)) {
            foreach ($match[1] as $block) {
                if (!preg_match('/class="topictitle"\s+href="(?:http:\/\/[^\/]+)?(\/t(\d+)-[^?"]+)"/', $block, $m))
                    continue;
                $topic_id = $m[2];
                $link = $m[1];
                $i1 ++;
                if (preg_match('/^.+class="topictitle".+?<td class="(?:centered row2|row2 centered)(?:\s+sticky-separator)?">(\d+)<\/td>/s', $block, $m)) {
                    $count = $m[1];
                    //$this->log("--> $topic_id with $count messages");
                    if ($this->checkTopicCount($topic_id, $count+1)) {
                        //$this->log("---> counts diff");
                        $links[] = $link;
                    } else {
                        $i2 ++;
                        //$this->log("---> counts same, skiped");
                    }
                } else {
                    $this->log("$link missed count information");
                    $links[] = $link;
                }
            }
        }

        $this->log($uri . " ($i1/$i2)");

        preg_match_all('/href=["\']?([^"\'>\s]+)/', $html, $matches);

        return array_merge($links, $this->filter_uri($matches[1]));
    }

    protected function checkTopicCount($topic_id, $count) {
        $st = $this->db->prepare("SELECT COUNT(*) FROM posts WHERE topic_id=:topic_id GROUP BY topic_id");
        if (!$st)
            return true;
        $st->bindParam(':topic_id', $topic_id, PDO::PARAM_INT);
        $st->execute();
        $result = $st->fetch(PDO::FETCH_NUM);
        if (!empty($result) && !is_null($result[0]) && (string)$result[0] === (string)$count) {
            return false;
        } else {
            return true;
        }
    }

    protected function saveForum($forum_id, $title) {
        $st = $this->db->prepare("REPLACE INTO forums (id, name) VALUES(:id, :name)");
        if ($st) {
            $st->bindParam(':id', $forum_id, PDO::PARAM_INT);
            $st->bindParam(':name', $title, PDO::PARAM_STR);
            $st->execute();
        }
    }
}

class TopicParser extends ParserBase implements Parser
{
    // inside topic - only other pages of topic
    protected $allow_uri_regexp = array(
        '/^(http:\/\/[^\/]+)?\/t\d+p\d+-/',
        // '/^(http:\/\/[^\/]+)?\/u\d+$/'
    );
    protected $rejected_uri_regexp = array(
        '/[?#]/'
    );

    public function check($uri)
    {
        if (preg_match('/\/t\d+(p\d+)?-/', $uri)) {
            return true;
        }

        return false;
    }

    public function parse($uri, $html)
    {
        if (preg_match('/\/t(\d+)(p\d+)?-([^\/]+)/', $uri, $matches)) {
            $topic_id = $matches[1];
            if (!$matches[2]) {
                // first page of topic, add to db
                $title = $matches[3];
                if (preg_match('/<title>(.+?)<\/title>/i', $html, $matches)) {
                    $title = $matches[1];
                }
                $forum_id = null;
                if (preg_match('/\bf=(\d+)&amp;/', $html, $matches)) {
                    $forum_id = $matches[1];
                }
                $this->saveTopic($forum_id, $topic_id, $title);
            }

            preg_match_all('/<div id="p\d+" class="post.*?<!--[\s\r\n]+closing tag post -->/is', $html, $matches);
            $this->parsePosts($topic_id, $matches[0]);
        }

        return parent::parse($uri, $html);
    }

    protected function parsePosts($topic_id, $posts_arr) {
        $cpost = 0;
        foreach($posts_arr as $post) {
            $post = preg_replace('/[\n\r]+/', ' ', $post);

            $post = preg_replace('/<div class="post-footer.*/s', '', $post);
            $post = preg_replace('/<div id="sig\d+.*/s', '', $post);

            if (!preg_match('/<div class="post-entry">(.*)/s', $post, $matches)) continue;
            $draft_post = $matches[1];
            $post = preg_replace('/<div class="post-entry">.*/s', '', $post); // strip post for find metadata

            if (!preg_match('/<div id="p(\d+)"/', $post, $matches)) continue;
            $post_id = $matches[1];

            if (!preg_match('/Post n°(\d+)/', $post, $matches)) continue;
            $ord = $matches[1];
            if ($cpost && ($cpost+1) != $ord) {
                $this->log("Lost posts ".($cpost+1)."-".($ord-1)." from topic $topic_id");
            }
            $cpost = $ord;

            if (!preg_match('/title="Post"\s*\/?>(?:\s+|&nbsp;)by(?:\s+|&nbsp;)(?:<a href="\/u(\d+)">.*?(?:<span[^>]+>)?(?:<strong>)?([^<]+)(?:<\/strong>)?(?:<\/span>)?.*?<\/a>|\S+)\s+((?:on|Today\s+at|Yesterday\s+at)\s+.*?)<\/p>/s', $post, $matches)) continue;
            $user_id = $matches[1];
            $date = $matches[3];
            $user_name = $matches[2];
            if (!$user_id) {
                $user_id = -1;
                $user_name = 'Guest';
            }

            $date = preg_replace('/^on\s+/', '', $date);
            $date = preg_replace('/\s+at\s+/', '', $date);
            $date = strtotime($date);

            $draft_post = preg_replace('/<\/?div[^>]*>/i', '', $draft_post);
            $draft_post = preg_replace('/(<br[^>]*>)+$/i', '', $draft_post);

            $this->savePost($topic_id, $post_id, $ord, $user_id, $date, $draft_post);

            if (!preg_match('/<dl class="postprofile-details.*?<dd>([^<]+)/s', $post, $matches)) continue;
            $user_group = $matches[1];

            $this->saveUser($user_id, $user_name, $user_group);

        }
    }

    protected function savePost($topic_id, $post_id, $ord, $user_id, $date, $post) {
        $fdate = date('Y-m-d H:i:s', $date);
        $st = $this->db->prepare("REPLACE INTO posts (id, topic_id, ord, user_id, stamp, post) VALUES(:id, :topic_id, :ord, :user_id, :stamp, :post)");
        if ($st) {
            $st->bindParam(':id', $post_id, PDO::PARAM_INT);
            $st->bindParam(':topic_id', $topic_id, PDO::PARAM_INT);
            $st->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $st->bindParam(':ord', $ord, PDO::PARAM_INT);
            $st->bindParam(':stamp', $fdate, PDO::PARAM_STR);
            $st->bindParam(':post', $post, PDO::PARAM_STR);
            $st->execute();
        }
    }

    protected function saveUser($user_id, $user_name, $user_group) {
        $st = $this->db->prepare("REPLACE INTO users (id, name, usergroup) VALUES(:id, :name, :usergroup)");
        if ($st) {
            $st->bindParam(':id', $user_id, PDO::PARAM_INT);
            $st->bindParam(':name', $user_name, PDO::PARAM_STR);
            $st->bindParam(':usergroup', $user_group, PDO::PARAM_STR);
            $st->execute();
        }
    }

    protected function saveTopic($forum_id, $topic_id, $title) {
        $st = $this->db->prepare("REPLACE INTO topics (id, forum_id, name) VALUES(:id, :forum_id, :name)");
        if ($st) {
            $st->bindParam(':id', $topic_id, PDO::PARAM_INT);
            $st->bindParam(':forum_id', $forum_id, PDO::PARAM_INT);
            $st->bindParam(':name', $title, PDO::PARAM_STR);
            $st->execute();
        }
    }
}
