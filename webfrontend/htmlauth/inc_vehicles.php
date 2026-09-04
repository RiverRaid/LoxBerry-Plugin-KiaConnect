<?php
// Kia2Lox - Hilfsfunktionen fuer die Fahrzeugverwaltung (Laden/Speichern
// der pluginconfig.json, Login-Test ueber die Python-venv).

define("KIA2LOX_MAX_VEHICLES", 4);

function kia2lox_config_path() {
	global $lbpconfigdir;
	return $lbpconfigdir . "/pluginconfig.json";
}

function kia2lox_load_vehicles() {
	$path = kia2lox_config_path();
	if (!file_exists($path)) {
		return [];
	}
	$data = json_decode(file_get_contents($path), true);
	if (!is_array($data) || !isset($data["vehicles"]) || !is_array($data["vehicles"])) {
		return [];
	}
	return $data["vehicles"];
}

function kia2lox_save_vehicles($vehicles) {
	$path = kia2lox_config_path();
	$data = ["vehicles" => array_values($vehicles)];
	$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	file_put_contents($path, $json, LOCK_EX);
	chmod($path, 0640);
}

function kia2lox_new_vehicle_id($vehicles) {
	$max = 0;
	foreach ($vehicles as $v) {
		if (preg_match('/^v(\d+)$/', $v["id"], $m)) {
			$max = max($max, (int)$m[1]);
		}
	}
	return "v" . ($max + 1);
}

function kia2lox_default_vehicle($name, $id) {
	return [
		"id" => $id,
		"name" => $name,
		"kia_username" => "",
		"kia_password" => "",
		"kia_pin" => "",
		"udp_target_ip" => "",
		"udp_target_port" => 7000,
	];
}

// Fuehrt einen echten Kia-Connect-Login mit den uebergebenen Daten aus,
// ohne sie zu speichern. Ruft dazu das Python-Script in der Plugin-eigenen
// venv auf und uebergibt die Zugangsdaten ueber STDIN (nicht als
// Kommandozeilen-Argument, damit sie nicht in der Prozessliste auftauchen).
function kia2lox_test_login($username, $password, $pin) {
	global $lbpbindir, $lbpdatadir;

	$python = $lbpdatadir . "/venv/bin/python3";
	$script = $lbpbindir . "/kia2lox_test_login.py";

	if (!is_executable($python) || !file_exists($script)) {
		return ["ok" => false, "error" => "Python-Umgebung nicht gefunden. Ist die Installation abgeschlossen?"];
	}

	$descriptorspec = [
		0 => ["pipe", "r"],
		1 => ["pipe", "w"],
		2 => ["pipe", "w"],
	];
	$process = proc_open([$python, $script], $descriptorspec, $pipes);
	if (!is_resource($process)) {
		return ["ok" => false, "error" => "Login-Test konnte nicht gestartet werden."];
	}

	$payload = json_encode(["username" => $username, "password" => $password, "pin" => $pin]);
	fwrite($pipes[0], $payload);
	fclose($pipes[0]);

	$stdout = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[2]);
	proc_close($process);

	$result = json_decode($stdout, true);
	if (!is_array($result) || !isset($result["ok"])) {
		$detail = trim($stderr) !== "" ? (": " . trim($stderr)) : "";
		return ["ok" => false, "error" => "Unerwartete Antwort vom Login-Test" . $detail];
	}
	return $result;
}
