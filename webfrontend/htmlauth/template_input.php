<?php
// Kia2Lox - liefert eine fertig ausgefuellte Loxone-Config-Vorlage fuer
// den virtuellen UDP-Eingang eines Fahrzeugs zum Download an (Port
// bereits eingetragen, Befehlserkennungen sind fuer alle Fahrzeuge
// gleich). Format 1:1 aus einem echten Loxone-Config-Export uebernommen.

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
	echo "Fahrzeug nicht gefunden.";
	exit;
}

$title = kia2lox_xml_attr("Kia2Lox - " . $vehicle["name"]);
$port = (int)$vehicle["udp_target_port"];

// Die Befehlserkennungen sind fuer jedes Fahrzeug identisch (das
// Python-Script sendet immer dieselben Schluessel), nur Titel und Port
// des virtuellen Eingangs selbst sind pro Fahrzeug unterschiedlich.
$commands = <<<'XML'
	<VirtualInUdpCmd Title="SOC" Comment="Battery" Address="" Check="SOC=\v" Signed="true" Analog="true" SourceValLow="0" DestValLow="0" SourceValHigh="100" DestValHigh="100" DefVal="0" MinVal="0" MaxVal="100" Unit="&lt;v&gt;%" HintText="" Documentation="State of Charge"/>
	<VirtualInUdpCmd Title="Range" Comment="Range" Address="" Check="RANGE=\v" Signed="true" Analog="true" SourceValLow="0" DestValLow="0" SourceValHigh="100" DestValHigh="100" DefVal="0" MinVal="0" MaxVal="999" Unit="&lt;v&gt; km" HintText=""/>
	<VirtualInUdpCmd Title="Charging" Comment="Charging" Address="" Check="CHARGING=\v" Signed="true" Analog="false" SourceValLow="0" DestValLow="0" SourceValHigh="100" DestValHigh="100" DefVal="0" MinVal="-10000" MaxVal="10000" Unit="" HintText=""/>
	<VirtualInUdpCmd Title="Plugged" Comment="Plugged" Address="" Check="PLUGGED=\v" Signed="true" Analog="true" SourceValLow="0" DestValLow="0" SourceValHigh="100" DestValHigh="100" DefVal="0" MinVal="-10000" MaxVal="10000" Unit="&lt;v&gt;" HintText="" Documentation="This is not a simple 0/1, but comes directly from the Kia Connect API with several possible numerical values (0 = not plugged in, 2 = plugged in, possibly other codes depending on the vehicle/charge status) - therefore set up as an analog input, not digital, otherwise intermediate values would be lost."/>
	<VirtualInUdpCmd Title="Full" Comment="Battery full" Address="" Check="FULL=\v" Signed="true" Analog="false" SourceValLow="0" DestValLow="0" SourceValHigh="100" DestValHigh="100" DefVal="0" MinVal="-10000" MaxVal="10000" Unit="" HintText="" Documentation="Triggers if the battery is charged to 100% for more than 3 hours"/>
	<VirtualInUdpCmd Title="Fullparked" Comment="Battery full &amp; still connected" Address="" Check="FULLPARKED=\v" Signed="true" Analog="false" SourceValLow="0" DestValLow="0" SourceValHigh="100" DestValHigh="100" DefVal="0" MinVal="-10000" MaxVal="10000" Unit="" HintText="" Documentation="Triggers if the battery is charged to 100% for more than 3 hours and the charging plug is still connected"/>
	<VirtualInUdpCmd Title="Recharge100" Comment="Recharge appreciated" Address="" Check="RECHARGE100=\v" Signed="true" Analog="false" SourceValLow="0" DestValLow="0" SourceValHigh="100" DestValHigh="100" DefVal="0" MinVal="-10000" MaxVal="10000" Unit="" HintText="" Documentation="Triggers if not fully charged for 30 days"/>
	<VirtualInUdpCmd Title="Lowbattery" Comment="Battery low" Address="" Check="LOWBATTERY=\v" Signed="true" Analog="false" SourceValLow="0" DestValLow="0" SourceValHigh="100" DestValHigh="100" DefVal="0" MinVal="-10000" MaxVal="10000" Unit="" HintText="" Documentation="Triggers if the battery is below 10% and not charging for more than 3 hours"/>
XML;

$xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
	. '<VirtualInUdp HintText="Receives Date from the Kia2Lox plugin" Title="' . $title . '" Comment="Kia2Lox" Address="" Port="' . $port . '">' . "\n"
	. "\t" . '<Info templateType="1" minVersion="17010727"/>' . "\n"
	. $commands . "\n"
	. '</VirtualInUdp>' . "\n";

$filename = "VIU_Kia2Lox_" . preg_replace('/[^A-Za-z0-9_-]/', '_', $vehicle["name"]) . ".xml";
header("Content-Type: application/xml; charset=utf-8");
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header("Content-Length: " . strlen($xml));
echo $xml;
