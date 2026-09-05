<?php

# Kia2Lox - Einstiegspunkt. LoxBerry verlinkt vom Plugin-Menue immer auf
# index.php (Framework-Konvention) - hier nur ein Redirect auf die
# Uebersichtsseite, damit das Plugin beim Aufruf ueber das Menue auf
# Uebersicht statt Einstellungen startet. Die Einstellungsseite selbst
# lebt in settings.php.

$target = "overview.php";
if (isset($_GET["vehicle"])) {
	$target .= "?vehicle=" . urlencode($_GET["vehicle"]);
}
header("Location: " . $target);
exit;
