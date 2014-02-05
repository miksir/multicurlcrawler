<?php

interface Logger
{
    public function log($message, $level=0);
}

class EchoLogger implements Logger
{

    public function log($message, $level = 0)
    {
        echo $message."\n";
    }
}

class NullLogger implements Logger
{

    public function log($message, $level = 0)
    {
        //
    }
}
