<?php

declare(strict_types=1);

use Cms\Http\Kernel;
use Cms\Http\Request;

/** @var Kernel $kernel */
$kernel = require dirname(__DIR__) . '/src/bootstrap.php';
$kernel->handle(Request::fromGlobals())->send();
