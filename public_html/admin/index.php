<?php
/*
   +----------------------------------------------------------------------+
   | PEAR Web site version 1.0                                            |
   +----------------------------------------------------------------------+
   | Copyright (c) 2001-2003 The PHP Group                                |
   +----------------------------------------------------------------------+
   | This source file is subject to version 2.02 of the PHP license,      |
   | that is bundled with this package in the file LICENSE, and is        |
   | available at through the world-wide-web at                           |
   | http://www.php.net/license/2_02.txt.                                 |
   | If you did not receive a copy of the PHP license and are unable to   |
   | obtain it through the world-wide-web, please send a note to          |
   | license@php.net so we can mail you a copy immediately.               |
   +----------------------------------------------------------------------+
   | Authors: Martin Jansen <mj@php.net>                                  |
   +----------------------------------------------------------------------+
   $Id: index.php 317356 2011-09-26 21:12:23Z pajoye $
*/

auth_require(true);

if (!empty($_GET['phpinfo'])) {
    phpinfo();
    exit();
}

$acreq = isset($_GET['acreq']) ? strip_tags(htmlspecialchars($_GET['acreq'], ENT_QUOTES)) : null;

$SIDEBAR_DATA='
This is the PEAR administration page.<br />
<noscript><p>
<!-- be annoying! -->
<b><blink>You must enable Javascript to use this page!</blink></b>
</p></noscript>
';

response_header("PEAR Administration");

menu_link("Package maintainers", "package-maintainers.php");
menu_link("Manage categories", "category-manager.php");

response_footer();
?>
