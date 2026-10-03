<?php
declare(strict_types=1);$core=require __DIR__.'/_app.php';$core->auth()->logout();header('Location: /login.php');exit;
