<?php

# Primary entry point of the Kia2Lox plugin (LoxBerry Design System, no jQuery Mobile).

require_once "loxberry_system.php";
require_once "loxberry_web.php";

$version = LBSystem::pluginversion();
$L = LBSystem::readlanguage("language.ini");

LBWeb::lbheader($L['BASIC.LABEL_PLUGINTITLE'] . " V$version", "https://github.com/RiverRaid/LoxBerry-Plugin-KiaConnect", "help.html", true);

?>
<div class="lb-container">
	<h2><?php echo $L['MAIN.TITLE']; ?></h2>
	<p><?php echo $L['MAIN.TEXT']; ?></p>
</div>
<?php

LBWeb::lbfooter();
exit;
