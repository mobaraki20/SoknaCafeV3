<?php
declare(strict_types=1);

use Sokna\Local\Core\Session;
use Sokna\Local\UI\LocalUrl;

// Logout is a session operation. Do not instantiate Auth here: Auth construction
// resolves the identity repository and therefore opens the database even though
// no database read/write is required to terminate the current browser session.
// Keeping this endpoint DB-independent also makes logout reliable during a
// transient database outage and avoids turning a safe exit into HTTP 500.
require __DIR__.'/_app.php';
Session::destroy();
$restored=((string)($_GET['restored']??''))==='1';
if($restored){header('Location: '.LocalUrl::path('/login.php?restored=1'));}else{header('Location: '.LocalUrl::path('/login.php'));}
exit;
