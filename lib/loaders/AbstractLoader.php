<?php
abstract class AbstractLoader
{
    protected $logger;
    
    abstract public function load();
    abstract public function getResult();
    public function setLogger($logger) {
        $this->logger = $logger;
    }
}
?>