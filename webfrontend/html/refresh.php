<?php
// Kia2Lox - oeffentlicher HTTP-Trigger fuer Loxone (kein LoxBerry-Login
// noetig, dafuer aber ein Fahrzeug-eigener Security-Key). Loest ein
// SOFORTIGES Force-Refresh aus (weckt das Fahrzeug aktiv fuer einen
// frischen Stand - kostet mehr Akku als eine passive Abfrage).
//
// Aufruf: refresh.php?key=<http_key> (der Schluessel ist pro Fahrzeug
// eindeutig, das Fahrzeug muss nicht extra angegeben werden).

require_once "loxberry_system.php";
global $lbphtmlauthdir;
require_once $lbphtmlauthdir . "/inc_vehicles.php";

header("Content-Type: text/plain; charset=utf-8");

$key = (string)($_GET["key"] ?? "");

$vehicles = kia2lox_load_vehicles();
$vehicle = null;
if ($key !== "") {
	foreach ($vehicles as $v) {
		if (hash_equals((string)($v["http_key"] ?? ""), $key)) {
			$vehicle = $v;
			break;
		}
	}
}

if ($vehicle === null) {
	http_response_code(403);
	echo "ERROR: ungueltiger Key\n";
	exit;
}

$result = kia2lox_manual_refresh($vehicle["id"], true);
if ($result["ok"]) {
	echo "OK\n";
} else {
	http_response_code(500);
	echo "ERROR: " . $result["error"] . "\n";
}
