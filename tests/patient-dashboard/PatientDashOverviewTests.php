<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ ."";

class MyClassTest extends TestCase 
{
    public function testPageLoads(): void
    {
        $html = $this->renderPage("");
    }
}