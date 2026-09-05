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
	echo kia2lox_t("ERRORS.VEHICLE_NOT_FOUND");
	exit;
}

$title = kia2lox_xml_attr("Kia2Lox - " . $vehicle["name"]);
$port = (int)$vehicle["udp_target_port"];

// Batteriepflege-Schwellwerte dieses Fahrzeugs (vom Benutzer in den
// Einstellungen -> "Warnungen" anpassbar) fuer die Documentation-Texte
// unten - damit die Loxone-Vorlage die tatsaechlich wirksamen Werte
// zeigt statt fest einprogrammierter Zahlen.
$full_soc_threshold = (int)($vehicle["full_soc_threshold"] ?? KIA2LOX_DEFAULT_FULL_SOC_THRESHOLD);
$full_hours = (int)($vehicle["full_hours"] ?? KIA2LOX_DEFAULT_FULL_HOURS);
$full_parked_hours = (int)($vehicle["full_parked_hours"] ?? KIA2LOX_DEFAULT_FULL_PARKED_HOURS);
$recharge_reminder_days = (int)($vehicle["recharge_reminder_days"] ?? KIA2LOX_DEFAULT_RECHARGE_REMINDER_DAYS);
$low_soc_threshold = (int)($vehicle["low_soc_threshold"] ?? KIA2LOX_DEFAULT_LOW_SOC_THRESHOLD);
$low_battery_hours = (int)($vehicle["low_battery_hours"] ?? KIA2LOX_DEFAULT_LOW_BATTERY_HOURS);

// Die Befehlserkennungen sind fuer jedes Fahrzeug identisch (das
// Python-Script sendet immer dieselben Schluessel), nur Titel und Port
// des virtuellen Eingangs selbst sowie die Documentation-Texte (siehe
// oben) sind pro Fahrzeug unterschiedlich.
$commands = <<<'XML'
	<VirtualInUdpCmd Title="SOC" Comment="{{COMMENT_SOC}}" Address="" Check="SOC=\v" Signed="true" Analog="true" SourceValLow="0" DestValLow="0" SourceValHigh="100" DestValHigh="100" DefVal="0" MinVal="0" MaxVal="100" Unit="&lt;v&gt;%" HintText="" Documentation="State of Charge"/>
	<VirtualInUdpCmd Title="Range" Comment="{{COMMENT_RANGE}}" Address="" Check="RANGE=\v" Signed="true" Analog="true" SourceValLow="0" DestValLow="0" SourceValHigh="100" DestValHigh="100" DefVal="0" MinVal="0" MaxVal="999" Unit="&lt;v&gt; km" HintText=""/>
	<VirtualInUdpCmd Title="Charging" Comment="{{COMMENT_CHARGING}}" Address="" Check="CHARGING=\v" Signed="true" Analog="true" SourceValLow="0" DestValLow="0" SourceValHigh="1" DestValHigh="1" DefVal="0" MinVal="0" MaxVal="1" Unit="&lt;v&gt;" HintText=""/>
	<VirtualInUdpCmd Title="Plugged" Comment="{{COMMENT_PLUGGED}}" Address="" Check="PLUGGED=\v" Signed="true" Analog="true" SourceValLow="0" DestValLow="0" SourceValHigh="100" DestValHigh="100" DefVal="0" MinVal="-10000" MaxVal="10000" Unit="&lt;v&gt;" HintText="" Documentation="This is not a simple 0/1, but comes directly from the Kia Connect API with several possible numerical values (0 = not plugged in, 2 = plugged in, possibly other codes depending on the vehicle/charge status) - therefore set up as an analog input, not digital, otherwise intermediate values would be lost."/>
	<VirtualInUdpCmd Title="Full" Comment="{{COMMENT_FULL}}" Address="" Check="FULL=\v" Signed="true" Analog="true" SourceValLow="0" DestValLow="0" SourceValHigh="1" DestValHigh="1" DefVal="0" MinVal="0" MaxVal="1" Unit="&lt;v&gt;" HintText="" Documentation="{{DOC_FULL}}"/>
	<VirtualInUdpCmd Title="Fullparked" Comment="{{COMMENT_FULLPARKED}}" Address="" Check="FULLPARKED=\v" Signed="true" Analog="true" SourceValLow="0" DestValLow="0" SourceValHigh="1" DestValHigh="1" DefVal="0" MinVal="0" MaxVal="1" Unit="&lt;v&gt;" HintText="" Documentation="{{DOC_FULLPARKED}}"/>
	<VirtualInUdpCmd Title="Recharge100" Comment="{{COMMENT_RECHARGE100}}" Address="" Check="RECHARGE100=\v" Signed="true" Analog="true" SourceValLow="0" DestValLow="0" SourceValHigh="1" DestValHigh="1" DefVal="0" MinVal="0" MaxVal="1" Unit="&lt;v&gt;" HintText="" Documentation="{{DOC_RECHARGE100}}"/>
	<VirtualInUdpCmd Title="Lowbattery" Comment="{{COMMENT_LOWBATTERY}}" Address="" Check="LOWBATTERY=\v" Signed="true" Analog="true" SourceValLow="0" DestValLow="0" SourceValHigh="1" DestValHigh="1" DefVal="0" MinVal="0" MaxVal="1" Unit="&lt;v&gt;" HintText="" Documentation="{{DOC_LOWBATTERY}}"/>
XML;

// Platzhalter statt echter PHP-Interpolation, damit das \v in den
// Check-Attributen oben (NOWDOC) buchstaeblich erhalten bleibt.
$commands = str_replace(
	["{{COMMENT_SOC}}", "{{COMMENT_RANGE}}", "{{COMMENT_CHARGING}}", "{{COMMENT_PLUGGED}}", "{{COMMENT_FULL}}", "{{COMMENT_FULLPARKED}}", "{{COMMENT_RECHARGE100}}", "{{COMMENT_LOWBATTERY}}",
	 "{{DOC_FULL}}", "{{DOC_FULLPARKED}}", "{{DOC_RECHARGE100}}", "{{DOC_LOWBATTERY}}"],
	[
		kia2lox_xml_attr(kia2lox_t("TEMPLATE.COMMENT_SOC")),
		kia2lox_xml_attr(kia2lox_t("TEMPLATE.COMMENT_RANGE")),
		kia2lox_xml_attr(kia2lox_t("TEMPLATE.COMMENT_CHARGING")),
		kia2lox_xml_attr(kia2lox_t("TEMPLATE.COMMENT_PLUGGED")),
		kia2lox_xml_attr(kia2lox_t("TEMPLATE.COMMENT_FULL")),
		kia2lox_xml_attr(kia2lox_t("TEMPLATE.COMMENT_FULLPARKED")),
		kia2lox_xml_attr(kia2lox_t("TEMPLATE.COMMENT_RECHARGE100")),
		kia2lox_xml_attr(kia2lox_t("TEMPLATE.COMMENT_LOWBATTERY")),
		kia2lox_xml_attr("Triggers if the battery is charged to {$full_soc_threshold}% for more than {$full_hours} hours"),
		kia2lox_xml_attr("Triggers if the battery is charged to {$full_soc_threshold}% for more than {$full_parked_hours} hours and the charging plug is still connected"),
		kia2lox_xml_attr("Triggers if not fully charged for {$recharge_reminder_days} days"),
		kia2lox_xml_attr("Triggers if the battery is below {$low_soc_threshold}% and not charging for more than {$low_battery_hours} hours"),
	],
	$commands
);

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
