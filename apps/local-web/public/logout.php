<?php
declare(strict_types=1);use Sokna\Local\UI\LocalUrl;$core=require __DIR__.'/_app.php';$core->auth()->logout();header('Location: '.LocalUrl::path('/login.php'));exit;
