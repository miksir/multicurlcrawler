<?php
require "CurlCollection.php";
require "Crawler.php";
require "Parsers.php";
require "Logger.php";

$logger = new EchoLogger();
//$db = new PDO("sqlite:".__DIR__.DIRECTORY_SEPARATOR."url.db");
$db = new PDO("mysql:host=localhost;dbname=", "", "");
$site = 'http://www.test.ru';

$parser = new ParserCollection();
$parser->register(new IndexParser($logger, $db));
$parser->register(new ForumParser($logger, $db));
$parser->register(new TopicParser($logger, $db));

$collection = new CurlCollection($logger);

$fabric = new CurlNodeFabric(array(
    CURLOPT_COOKIEJAR => __DIR__.DIRECTORY_SEPARATOR."cookies.txt",
    CURLOPT_COOKIEFILE => __DIR__.DIRECTORY_SEPARATOR."cookies.txt"
));

//$node = $fabric->getNode($site);
//$node->Run();
//if ($node->getRealURL() == $site.'login') {
//    $node = $fabric->getNode($site.'login');
//    $node->setOptions(array(
//            CURLOPT_POST => true,
//            CURLOPT_POSTFIELDS => array(
//                '' => '' // form data
//            )
//        ));
//    $node->Run();
//    if ($node->getRealURL() != $site) {
//        die("Can't login");
//    }
//    $logger->log("Logged in");
//} else {
//    $logger->log("Already logged in");
//}
//unset($node);
//$fabric->resetFabric();

$crawler = new Crawler($collection, $fabric, $parser, $logger, $site);
$crawler->Run('/');
