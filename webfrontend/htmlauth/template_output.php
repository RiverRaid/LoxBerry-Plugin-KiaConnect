<?php
// Kia2Lox - liefert eine fertig ausgefuellte Loxone-Config-Vorlage fuer
// den virtuellen Ausgang eines Fahrzeugs zum Download an (Adresse,
// Sicherheits-Key und beide HTTP-Befehle sind bereits eingetragen).
// Format 1:1 aus einem echten Loxone-Config-Export uebernommen.

require_once "loxberry_system.php";
require_once "inc_vehicles.php";

$vehicle_id = $_GET["vehicle"] ?? "";
$vehicles = kia2lox_load_vehicles();
$vehicle = null;
foreach ($vehicles as $v) {
	if ($v["id"] === $vehicle_id) {
		$vehicle = $v;
		break;
	}
}
if ($vehicle === null) {
	http_response_code(404);
	echo kia2lox_t("ERRORS.VEHICLE_NOT_FOUND");
	exit;
}

$title = kia2lox_xml_attr("Kia2Lox - " . $vehicle["name"]);
$http_host = $_SERVER["HTTP_HOST"] ?? "";
$address = kia2lox_xml_attr("http://{$http_host}");
$key = urlencode($vehicle["http_key"] ?? "");
$poll_path = kia2lox_xml_attr("/plugins/kia2lox/poll.php?key=" . $key);
$refresh_path = kia2lox_xml_attr("/plugins/kia2lox/refresh.php?key=" . $key);

$xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
	. '<VirtualOut HintText="" Title="' . $title . '" Comment="' . kia2lox_xml_attr(kia2lox_t("TEMPLATE.COMMENT_OUTPUT")) . '" Address="' . $address . '" CmdInit="" CloseAfterSend="true" CmdSep=";">' . "\n"
	. "\t" . '<Info templateType="3" minVersion="17010727"/>' . "\n"
	. "\t" . '<VirtualOutCmd Title="Passive Refresh" Comment="" CmdOnMethod="GET" CmdOffMethod="GET" CmdOn="' . $poll_path . '" CmdOnHTTP="" CmdOnPost="" CmdOff="" CmdOffHTTP="" CmdOffPost="" CmdAnswer="" Analog="false" Repeat="0" RepeatRate="0" HintText="" Documentation="Triggers a passive refresh, does not wake up the car. Uses less of the 12V battery"/>' . "\n"
	. "\t" . '<VirtualOutCmd Title="Force Refresh" Comment="" CmdOnMethod="GET" CmdOffMethod="GET" CmdOn="' . $refresh_path . '" CmdOnHTTP="" CmdOnPost="" CmdOff="" CmdOffHTTP="" CmdOffPost="" CmdAnswer="" Analog="false" Repeat="0" RepeatRate="0" HintText="" Documentation="Triggers a force refresh, wakes up the car. Uses more of the 12V battery"/>' . "\n"
	. '</VirtualOut>' . "\n";

$filename = "VO_Kia2Lox_" . preg_replace('/[^A-Za-z0-9_-]/', '_', $vehicle["name"]) . ".xml";
header("Content-Type: application/xml; charset=utf-8");
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header("Content-Length: " . strlen($xml));
echo $xml;
