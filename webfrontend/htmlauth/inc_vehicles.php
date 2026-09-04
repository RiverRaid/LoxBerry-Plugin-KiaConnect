<?php
// Kia2Lox - Hilfsfunktionen fuer die Fahrzeugverwaltung (Laden/Speichern
// der pluginconfig.json, Login-Test ueber die Python-venv).

define("KIA2LOX_MAX_VEHICLES", 4);
define("KIA2LOX_MAX_CUSTOM_TIMES", 8);
define("KIA2LOX_INTERVAL_OPTIONS", [30, 60, 90, 120, 180, 240]);
define("KIA2LOX_FORCE_DEFAULT_TIMES", [
	1 => ["12:00"],
	2 => ["08:00", "18:00"],
	3 => ["08:00", "13:00", "18:00"],
	4 => ["07:00", "11:00", "15:00", "19:00"],
]);

function kia2lox_config_path() {
	global $lbpconfigdir;
	return $lbpconfigdir . "/pluginconfig.json";
}

// Liest state.json (vom Python-Poll-Script gepflegt) - u.a. den
// "poll_log" je Fahrzeug fuer die "Heute geplant"-Anzeige.
function kia2lox_load_state() {
	global $lbpdatadir;
	$path = $lbpdatadir . "/state.json";
	if (!file_exists($path)) {
		return [];
	}
	$data = json_decode(file_get_contents($path), true);
	return is_array($data) ? $data : [];
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
	$vehicles = $data["vehicles"];
	$needs_save = false;
	// Migration: Fahrzeuge aus einer Zeit vor "kia_connected" haben das
	// Feld noch nicht. Bestmoegliche Annahme: wenn schon Zugangsdaten
	// hinterlegt sind, war die Verbindung frueher schon mal erfolgreich.
	foreach ($vehicles as &$v) {
		if (!array_key_exists("kia_connected", $v)) {
			$v["kia_connected"] = !empty($v["kia_username"]) && !empty($v["kia_password"]);
		}
		// Migration: Fahrzeuge aus einer Zeit vor den Intervall-Einstellungen
		// (und aus der ersten, noch unvollstaendigen Fassung davon) bekommen
		// die Kia2Lox-Standardwerte.
		if (!array_key_exists("passive_mode", $v)) {
			$v["passive_mode"] = "interval";
		}
		if (!array_key_exists("passive_interval_minutes", $v)) {
			$v["passive_interval_minutes"] = 60;
		}
		if (!array_key_exists("passive_custom_times", $v)) {
			$v["passive_custom_times"] = [];
		}
		if (!array_key_exists("passive_window_enabled", $v)) {
			$v["passive_window_enabled"] = true;
		}
		if (!array_key_exists("passive_window_from", $v)) {
			$v["passive_window_from"] = "08:00";
		}
		if (!array_key_exists("passive_window_to", $v)) {
			$v["passive_window_to"] = "18:00";
		}
		if (!array_key_exists("force_freq", $v)) {
			$v["force_freq"] = 0;
		}
		if (!array_key_exists("force_times", $v)) {
			$v["force_times"] = KIA2LOX_FORCE_DEFAULT_TIMES[$v["force_freq"]] ?? [];
		}
		// Migration: Sicherheits-Schluessel fuer die oeffentlichen
		// HTTP-Trigger (poll.php/refresh.php). Muss stabil bleiben, sobald
		// er einmal in Loxone hinterlegt wurde - daher sofort speichern.
		if (!array_key_exists("http_key", $v) || $v["http_key"] === "") {
			$v["http_key"] = bin2hex(random_bytes(6));
			$needs_save = true;
		}
		// Aufraeumen von Feldnamen der allerersten (noch nicht auf echten
		// Nutzerdaten gespeicherten) Fassung dieser Einstellungen.
		unset($v["poll_mode"], $v["poll_interval_minutes"], $v["poll_times"],
			$v["poll_window_enabled"], $v["poll_window_from"], $v["poll_window_to"]);
	}
	unset($v);
	if ($needs_save) {
		kia2lox_save_vehicles($vehicles);
	}
	return $vehicles;
}

function kia2lox_save_vehicles($vehicles) {
	$path = kia2lox_config_path();
	$data = ["vehicles" => array_values($vehicles)];
	$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	file_put_contents($path, $json, LOCK_EX);
	chmod($path, 0640);
}

// Escaped einen Wert fuer die Verwendung als XML-Attributwert (Loxone
// Config Vorlagen-Dateien fuer virtuelle Ein-/Ausgaenge).
function kia2lox_xml_attr($value) {
	return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, "UTF-8");
}

// Bereinigt eine Liste von "HH:MM"-Uhrzeiten aus einem Formular: nur
// gueltige, eindeutige Zeiten behalten, sortiert, auf $max begrenzt.
function kia2lox_clean_times($raw, $max) {
	if (!is_array($raw)) {
		$raw = [];
	}
	$time_re = '/^([01]\d|2[0-3]):[0-5]\d$/';
	$times = [];
	foreach ($raw as $t) {
		$t = trim((string)$t);
		if ($t !== "" && preg_match($time_re, $t)) {
			$times[] = $t;
		}
	}
	$times = array_values(array_unique($times));
	sort($times);
	return array_slice($times, 0, $max);
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
		"kia_connected" => false,
		"ms_number" => "",
		"udp_target_ip" => "",
		"udp_target_port" => 7000,
		"passive_mode" => "interval",
		"passive_interval_minutes" => 60,
		"passive_custom_times" => [],
		"passive_window_enabled" => true,
		"passive_window_from" => "08:00",
		"passive_window_to" => "18:00",
		"force_freq" => 0,
		"force_times" => [],
		"http_key" => bin2hex(random_bytes(6)),
	];
}

// Liste der auf diesem LoxBerry konfigurierten Miniserver als
// [msnr => "Name (IP)"] fuer das Auswahlfeld.
function kia2lox_miniserver_options() {
	$options = [];
	foreach (LBSystem::get_miniservers() as $msnr => $ms) {
		if (empty($ms["Name"]) || empty($ms["IPAddress"])) {
			continue;
		}
		$options[$msnr] = $ms["Name"] . " (" . $ms["IPAddress"] . ")";
	}
	return $options;
}

function kia2lox_miniserver_ip($msnr) {
	$miniservers = LBSystem::get_miniservers();
	if (isset($miniservers[$msnr]["IPAddress"])) {
		return $miniservers[$msnr]["IPAddress"];
	}
	return "";
}

// Liest den SOC-Verlauf eines Fahrzeugs (von kia2lox_poll.py in
// history_<id>.jsonl geschrieben) fuer das Ladezustands-Diagramm.
// Gibt eine Liste von ["at" => ISO-Zeit, "soc" => Zahl] zurueck.
function kia2lox_load_history($vehicle_id) {
	global $lbpdatadir;
	$path = $lbpdatadir . "/history_" . $vehicle_id . ".jsonl";
	if (!file_exists($path)) {
		return [];
	}
	$entries = [];
	$lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	if ($lines === false) {
		return [];
	}
	foreach ($lines as $line) {
		$entry = json_decode($line, true);
		if (is_array($entry) && isset($entry["at"]) && isset($entry["soc"])) {
			$entries[] = [
				"at" => $entry["at"],
				"soc" => $entry["soc"],
				"charging" => $entry["charging"] ?? null,
				"plugged" => $entry["plugged"] ?? null,
			];
		}
	}
	return $entries;
}

// Naechste geplante passive Abfrage als "HH:MM" fuer die Uebersicht.
// Bei Intervall-Modus: naechster Slot innerhalb des heutigen Zeitfensters,
// sonst der Beginn des Fensters morgen. Bei Individuell: naechste
// konfigurierte Uhrzeit (heute oder morgen die erste). "Nie" -> null.
function kia2lox_next_passive_time($vehicle, $now_hm) {
	$mode = $vehicle["passive_mode"] ?? "interval";
	if ($mode === "never") {
		return null;
	}
	$to_min = function ($hm) {
		[$h, $m] = explode(":", $hm);
		return ((int)$h) * 60 + ((int)$m);
	};
	$to_hm = function ($min) {
		$min = ((int)$min) % (24 * 60);
		return sprintf("%02d:%02d", intdiv($min, 60), $min % 60);
	};
	$now_min = $to_min($now_hm);

	if ($mode === "custom") {
		$times = $vehicle["passive_custom_times"] ?? [];
		if (empty($times)) {
			return null;
		}
		sort($times);
		foreach ($times as $t) {
			if ($to_min($t) > $now_min) {
				return $t;
			}
		}
		return $times[0]; // morgen die erste konfigurierte Zeit
	}

	// Intervall-Modus.
	$interval = (int)($vehicle["passive_interval_minutes"] ?? 60);
	if ($interval <= 0) {
		$interval = 60;
	}
	$window_enabled = !empty($vehicle["passive_window_enabled"]);
	$from_min = $window_enabled ? $to_min($vehicle["passive_window_from"] ?? "00:00") : 0;
	$to_min_val = $window_enabled ? $to_min($vehicle["passive_window_to"] ?? "23:59") : (24 * 60 - 1);

	if ($from_min <= $to_min_val) {
		for ($m = $from_min; $m <= $to_min_val; $m += $interval) {
			if ($m > $now_min) {
				return $to_hm($m);
			}
		}
		// Heute nichts mehr -> morgen ab Fensterbeginn.
		return $to_hm($from_min);
	}
	// Zeitfenster geht ueber Mitternacht.
	for ($m = $from_min; $m < 24 * 60; $m += $interval) {
		if ($m > $now_min) {
			return $to_hm($m);
		}
	}
	for ($m = 0; $m <= $to_min_val; $m += $interval) {
		if ($m > $now_min) {
			return $to_hm($m);
		}
	}
	return $to_hm($from_min);
}

// Einfacher Erreichbarkeits-Check fuer den Miniserver: TCP-Verbindung zur
// Web-UI (Port 80). Kein ICMP-Ping, weil das von PHP/www-data aus in der
// Regel keine Berechtigung hat.
function kia2lox_ping_miniserver($ip, $timeout = 1.5) {
	if ($ip === "") {
		return false;
	}
	$conn = @fsockopen($ip, 80, $errno, $errstr, $timeout);
	if ($conn) {
		fclose($conn);
		return true;
	}
	return false;
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

// Loest fuer ein einzelnes Fahrzeug sofort eine echte Abfrage aus
// (unabhaengig vom eingestellten Intervall) und wartet auf das Ergebnis.
// $force = true: Force-Refresh (weckt das Fahrzeug, frischer Stand).
// $force = false: passive Abfrage (nur gecachter Stand von Kia Connect).
function kia2lox_manual_refresh($vehicle_id, $force = true) {
	global $lbpbindir, $lbpdatadir, $lbplogdir;

	// Solange die Zugangsdaten fuer dieses Fahrzeug noch nie erfolgreich
	// getestet wurden, erst gar keinen Python-Prozess starten.
	foreach (kia2lox_load_vehicles() as $v) {
		if ($v["id"] === $vehicle_id && empty($v["kia_connected"])) {
			return ["ok" => false, "error" => "Zugangsdaten für dieses Fahrzeug wurden noch nicht erfolgreich getestet."];
		}
	}

	$python = $lbpdatadir . "/venv/bin/python3";
	$script = $lbpbindir . "/kia2lox_poll.py";

	if (!is_executable($python) || !file_exists($script)) {
		return ["ok" => false, "error" => "Python-Umgebung nicht gefunden. Ist die Installation abgeschlossen?"];
	}

	$command = [$python, $script, "--vehicle", $vehicle_id];
	if ($force) {
		$command[] = "--force";
	}

	$descriptorspec = [
		0 => ["pipe", "r"],
		1 => ["pipe", "w"],
		2 => ["pipe", "w"],
	];
	$process = proc_open($command, $descriptorspec, $pipes);
	if (!is_resource($process)) {
		return ["ok" => false, "error" => "Aktualisierung konnte nicht gestartet werden."];
	}

	fclose($pipes[0]);
	$stdout = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[2]);
	proc_close($process);

	$output = trim($stdout . "\n" . $stderr);

	// Genau wie der Cron-Durchlauf ins gemeinsame Log schreiben, damit
	// manuelle Klicks und Loxone-HTTP-Trigger dort ebenfalls auftauchen.
	$log_path = $lbplogdir . "/poll.log";
	@file_put_contents($log_path, $output . "\n", FILE_APPEND | LOCK_EX);

	if (strpos($output, "FEHLER") !== false) {
		// Erste FEHLER-Zeile als Kurzfassung der Meldung nehmen.
		$error = $output;
		foreach (explode("\n", $output) as $line) {
			if (strpos($line, "FEHLER") !== false) {
				$error = trim($line);
				break;
			}
		}
		return ["ok" => false, "error" => $error];
	}
	return ["ok" => true];
}
