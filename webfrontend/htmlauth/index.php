<?php

# Kia2Lox - Einstellungsseite. Etappe 2: Fahrzeugverwaltung + Zugangsdaten
# mit echtem Login-Test beim Speichern. Weitere Karten (Miniserver,
# Intervalle, HTTP-Trigger, Vorlagen, Uebersicht) folgen in den naechsten
# Etappen.

require_once "loxberry_system.php";
require_once "loxberry_web.php";
require_once "inc_vehicles.php";

$version = LBSystem::pluginversion();

$vehicles = kia2lox_load_vehicles();

$message = null;
$message_type = null; // "ok" oder "error"

if (($_SERVER["REQUEST_METHOD"] ?? "") === "POST") {
	$action = $_POST["kia2lox_action"] ?? "";

	// Die AJAX-Aktionen (save_credentials/save_miniserver/save_interval)
	// antworten mit JSON - ein Output-Buffer verhindert, dass ein
	// PHP-Warning/Notice davor die JSON-Antwort kaputt macht (das JS
	// bekaeme dann keine gueltige JSON-Antwort und zeigt einen
	// Verbindungsfehler, obwohl das Speichern eigentlich geklappt hat).
	// kia2lox_json_response() gibt sauber nur das JSON aus.
	ob_start();
	function kia2lox_json_response($data) {
		ob_clean();
		header("Content-Type: application/json");
		echo json_encode($data);
		exit;
	}

	if ($action === "add_vehicle") {
		if (count($vehicles) >= KIA2LOX_MAX_VEHICLES) {
			$message = kia2lox_t("SETTINGS.MAX_VEHICLES_ERROR", ["max" => KIA2LOX_MAX_VEHICLES]);
			$message_type = "error";
		} else {
			$name = trim($_POST["new_vehicle_name"] ?? "");
			if ($name === "") {
				$name = kia2lox_t("VEHICLES.DEFAULT_NAME", ["n" => count($vehicles) + 1]);
			}
			$id = kia2lox_new_vehicle_id($vehicles);
			$vehicles[] = kia2lox_default_vehicle($name, $id);
			kia2lox_save_vehicles($vehicles);
			header("Location: index.php?vehicle=" . urlencode($id));
			exit;
		}
	} elseif ($action === "remove_vehicle") {
		$id = $_POST["vehicle_id"] ?? "";
		if (count($vehicles) > 1) {
			$vehicles = array_values(array_filter($vehicles, function ($v) use ($id) {
				return $v["id"] !== $id;
			}));
			kia2lox_save_vehicles($vehicles);
		}
		header("Location: index.php");
		exit;
	} elseif ($action === "save_credentials") {
		$id = $_POST["vehicle_id"] ?? "";
		$name = trim($_POST["vehicle_name"] ?? "");
		$username = trim($_POST["kia_username"] ?? "");
		$password = $_POST["kia_password"] ?? "";
		$pin = trim($_POST["kia_pin"] ?? "");

		if ($name === "" || $username === "" || $password === "") {
			kia2lox_json_response(["ok" => false, "message" => kia2lox_t("SETTINGS.CRED_REQUIRED")]);
		}

		// Fahrzeugname unabhaengig vom Login-Ergebnis speichern, damit
		// er auch bei falschen Zugangsdaten nicht verloren geht.
		foreach ($vehicles as &$v) {
			if ($v["id"] === $id) {
				$v["name"] = $name;
			}
		}
		unset($v);
		kia2lox_save_vehicles($vehicles);

		$test = kia2lox_test_login($username, $password, $pin);
		if (!$test["ok"]) {
			kia2lox_json_response([
				"ok" => false,
				"message" => kia2lox_t("SETTINGS.LOGIN_FAILED", ["error" => $test["error"] ?? kia2lox_t("COMMON.UNKNOWN_ERROR")]),
				"name" => $name,
				"connected" => false,
			]);
		}

		// War dieses Fahrzeug vorher noch nicht verbunden, ist das hier die
		// allererste erfolgreiche Verbindung - dann gleich einen echten
		// Force-Refresh anstossen, damit auf der Uebersicht sofort Daten
		// zu sehen sind, statt bis zum naechsten Intervall zu warten.
		$was_connected = false;
		foreach ($vehicles as $v) {
			if ($v["id"] === $id) {
				$was_connected = !empty($v["kia_connected"]);
			}
		}

		foreach ($vehicles as &$v) {
			if ($v["id"] === $id) {
				$v["kia_username"] = $username;
				$v["kia_password"] = $password;
				$v["kia_pin"] = $pin;
				$v["kia_connected"] = true;
			}
		}
		unset($v);
		kia2lox_save_vehicles($vehicles);

		if (!$was_connected) {
			kia2lox_manual_refresh($id, true);
		}

		kia2lox_json_response([
			"ok" => true,
			"message" => kia2lox_t("SETTINGS.CRED_SAVED"),
			"name" => $name,
			"connected" => true,
		]);
	} elseif ($action === "save_miniserver") {
		$id = $_POST["vehicle_id"] ?? "";
		$msnr = $_POST["ms_number"] ?? "";
		$port = (int)($_POST["udp_target_port"] ?? 0);
		$ip = kia2lox_miniserver_ip($msnr);

		if ($ip === "") {
			kia2lox_json_response(["ok" => false, "message" => kia2lox_t("SETTINGS.MS_SELECT_REQUIRED")]);
		}
		if ($port < 1 || $port > 65535) {
			kia2lox_json_response(["ok" => false, "message" => kia2lox_t("SETTINGS.MS_PORT_RANGE")]);
		}
		foreach ($vehicles as &$v) {
			if ($v["id"] === $id) {
				$v["ms_number"] = $msnr;
				$v["udp_target_ip"] = $ip;
				$v["udp_target_port"] = $port;
			}
		}
		unset($v);
		kia2lox_save_vehicles($vehicles);
		kia2lox_json_response(["ok" => true, "message" => kia2lox_t("SETTINGS.MS_SAVED")]);
	} elseif ($action === "save_interval") {
		$id = $_POST["vehicle_id"] ?? "";
		$time_re = '/^([01]\d|2[0-3]):[0-5]\d$/';

		// Das Auswahlfeld "Passives Intervall" traegt entweder eine
		// Minutenzahl (30/60/.../240), "never" oder "custom" als Wert -
		// daraus Modus und (falls vorhanden) Intervall ableiten.
		$passive_mode_raw = $_POST["passive_mode"] ?? "60";
		if ($passive_mode_raw === "never") {
			$passive_mode = "never";
			$passive_interval = 60;
		} elseif ($passive_mode_raw === "custom") {
			$passive_mode = "custom";
			$passive_interval = 60;
		} else {
			$passive_mode = "interval";
			$passive_interval = (int)$passive_mode_raw;
			if (!in_array($passive_interval, KIA2LOX_INTERVAL_OPTIONS, true)) {
				$passive_interval = 60;
			}
		}

		$passive_custom_times = kia2lox_clean_times($_POST["passive_custom_times"] ?? [], KIA2LOX_MAX_CUSTOM_TIMES);

		$passive_window_enabled = !empty($_POST["passive_window_enabled"]);
		$passive_window_from = trim($_POST["passive_window_from"] ?? "");
		$passive_window_to = trim($_POST["passive_window_to"] ?? "");
		if (!preg_match($time_re, $passive_window_from)) {
			$passive_window_from = "08:00";
		}
		if (!preg_match($time_re, $passive_window_to)) {
			$passive_window_to = "18:00";
		}

		$force_freq = (int)($_POST["force_freq"] ?? 0);
		if ($force_freq < 0 || $force_freq > 4) {
			$force_freq = 0;
		}
		$force_times = kia2lox_clean_times($_POST["force_times"] ?? [], $force_freq);
		// Falls zu wenige/keine gueltigen Zeiten mitgeschickt wurden (z.B.
		// nach Aendern der Haeufigkeit), mit den Standardzeiten auffuellen.
		if ($force_freq > 0 && count($force_times) < $force_freq) {
			$force_times = KIA2LOX_FORCE_DEFAULT_TIMES[$force_freq] ?? $force_times;
		}

		foreach ($vehicles as &$v) {
			if ($v["id"] === $id) {
				$v["passive_mode"] = $passive_mode;
				$v["passive_interval_minutes"] = $passive_interval;
				$v["passive_custom_times"] = $passive_custom_times;
				$v["passive_window_enabled"] = $passive_window_enabled;
				$v["passive_window_from"] = $passive_window_from;
				$v["passive_window_to"] = $passive_window_to;
				$v["force_freq"] = $force_freq;
				$v["force_times"] = $force_times;
			}
		}
		unset($v);
		kia2lox_save_vehicles($vehicles);
		kia2lox_json_response(["ok" => true, "message" => kia2lox_t("SETTINGS.INTERVAL_SAVED")]);
	} elseif ($action === "manual_refresh") {
		$id = $_POST["vehicle_id"] ?? "";
		$result = kia2lox_manual_refresh($id);
		if ($result["ok"]) {
			$message = kia2lox_t("SETTINGS.REFRESH_DONE");
			$message_type = "ok";
		} else {
			$message = kia2lox_t("SETTINGS.REFRESH_FAILED", ["error" => $result["error"] ?? kia2lox_t("COMMON.UNKNOWN_ERROR")]);
			$message_type = "error";
		}
		$active_id = $id;
	}
}

// Es sollte immer mindestens ein Fahrzeug existieren (wird eigentlich
// schon bei der Installation angelegt) - hier zur Sicherheit nochmal.
if (empty($vehicles)) {
	$vehicles = [kia2lox_default_vehicle(kia2lox_t("VEHICLES.DEFAULT_NAME", ["n" => 1]), "v1")];
	kia2lox_save_vehicles($vehicles);
}

// $active_id kann schon gesetzt sein, wenn save_credentials wegen eines
// fehlgeschlagenen Logins ohne Redirect auf derselben Seite bleibt.
if (!isset($active_id)) {
	$active_id = $_GET["vehicle"] ?? $vehicles[0]["id"];
}
$active = null;
foreach ($vehicles as $v) {
	if ($v["id"] === $active_id) {
		$active = $v;
		break;
	}
}
if ($active === null) {
	$active = $vehicles[0];
	$active_id = $active["id"];
}

// Solange die Zugangsdaten fuer dieses Fahrzeug noch nie erfolgreich
// getestet wurden, bleiben Intervall-Einstellungen, HTTP-Befehle und
// Loxone-Vorlagen gesperrt (siehe Karten weiter unten) - es wuerden ja
// ohnehin keine Kia-Connect-Anfragen fuer dieses Fahrzeug ausgefuehrt.
$connected = !empty($active["kia_connected"]);

if (isset($_GET["saved"]) && $message === null) {
	if ($_GET["saved"] === "ms") {
		$message = kia2lox_t("SETTINGS.MS_SAVED");
	} elseif ($_GET["saved"] === "interval") {
		$message = kia2lox_t("SETTINGS.INTERVAL_SAVED");
	} else {
		$message = kia2lox_t("SETTINGS.CRED_SAVED");
	}
	$message_type = "ok";
}

$ms_options = kia2lox_miniserver_options();

// Fuer "Heute geplant": heutige Abfrage-Versuche (Erfolg/Fehler) des
// aktiven Fahrzeugs aus state.json, damit die Punkte in der Tabelle
// wissen, ob zu einem geplanten Zeitpunkt tatsaechlich (und erfolgreich)
// abgefragt wurde.
$state = kia2lox_load_state();
$vstate = $state[$active_id] ?? [];
$today = date("Y-m-d");
$poll_log = array_values(array_filter(
	is_array($vstate["poll_log"] ?? null) ? $vstate["poll_log"] : [],
	function ($entry) use ($today) { return ($entry["date"] ?? "") === $today; }
));

LBWeb::lbheader("Kia2Lox V$version", "https://github.com/RiverRaid/LoxBerry-Plugin-KiaConnect", "help.html");
$kia2lox_active_tab = "settings";
require "inc_header.php";
?>

	<?php if ($message): ?>
		<p class="kia2lox-message kia2lox-message-<?php echo htmlspecialchars($message_type); ?>">
			<?php echo htmlspecialchars($message); ?>
		</p>
	<?php endif; ?>

	<div class="kia2lox-card<?php echo !empty($active["kia_connected"]) ? " kia2lox-card-connected" : ""; ?>" id="kia2lox-cred-card"
	     data-connected="<?php echo !empty($active["kia_connected"]) ? "1" : "0"; ?>">
		<h2><?php echo htmlspecialchars(kia2lox_t("SETTINGS.CRED_TITLE")); ?></h2>
		<p class="kia2lox-desc"><?php echo htmlspecialchars(kia2lox_t("SETTINGS.CRED_DESC")); ?></p>
		<form method="post" action="index.php" autocomplete="off" id="kia2lox-cred-form">
			<input type="hidden" name="kia2lox_action" value="save_credentials">
			<input type="hidden" name="vehicle_id" value="<?php echo htmlspecialchars($active_id); ?>">

			<div class="kia2lox-field-grid">
				<div class="kia2lox-field">
					<label for="vehicle_name"><?php echo htmlspecialchars(kia2lox_t("SETTINGS.LABEL_VEHICLE_NAME")); ?></label>
					<input type="text" id="vehicle_name" name="vehicle_name" autocomplete="off" data-role="none"
					       value="<?php echo htmlspecialchars($active["name"]); ?>" required>
				</div>
				<div class="kia2lox-field">
					<label for="kia_username"><?php echo htmlspecialchars(kia2lox_t("SETTINGS.LABEL_USERNAME")); ?></label>
					<input type="email" id="kia_username" name="kia_username" autocomplete="off" data-role="none"
					       pattern="[^\s@&lt;&gt;]+@[^\s@&lt;&gt;]+\.[A-Za-z]{2,}"
					       value="<?php echo htmlspecialchars($active["kia_username"]); ?>" required>
				</div>
				<div class="kia2lox-field">
					<label for="kia_password"><?php echo htmlspecialchars(kia2lox_t("SETTINGS.LABEL_PASSWORD")); ?></label>
					<input type="password" id="kia_password" name="kia_password" autocomplete="new-password" data-role="none"
					       value="<?php echo htmlspecialchars($active["kia_password"]); ?>" required>
				</div>
				<div class="kia2lox-field">
					<label for="kia_pin"><?php echo htmlspecialchars(kia2lox_t("SETTINGS.LABEL_PIN")); ?></label>
					<input type="password" id="kia_pin" name="kia_pin" autocomplete="new-password" data-role="none"
					       value="<?php echo htmlspecialchars($active["kia_pin"]); ?>">
					<p class="kia2lox-hint"><?php echo htmlspecialchars(kia2lox_t("SETTINGS.PIN_HINT")); ?></p>
				</div>
			</div>

			<div class="kia2lox-save-row">
				<button type="submit" class="kia2lox-btn" id="kia2lox-save-cred" disabled><?php echo htmlspecialchars(kia2lox_t("SETTINGS.SAVE_CRED_BUTTON")); ?></button>
				<span class="kia2lox-save-feedback" id="kia2lox-save-feedback-cred"></span>
			</div>
		</form>
	</div>

	<div class="kia2lox-card">
		<h2><?php echo htmlspecialchars(kia2lox_t("SETTINGS.MS_TITLE")); ?></h2>
		<p class="kia2lox-desc"><?php echo htmlspecialchars(kia2lox_t("SETTINGS.MS_DESC")); ?></p>
		<?php if (empty($ms_options)): ?>
			<p class="kia2lox-hint"><?php echo htmlspecialchars(kia2lox_t("SETTINGS.MS_NONE_HINT")); ?></p>
		<?php else: ?>
			<form method="post" action="index.php" id="kia2lox-ms-form">
				<input type="hidden" name="kia2lox_action" value="save_miniserver">
				<input type="hidden" name="vehicle_id" value="<?php echo htmlspecialchars($active_id); ?>">

				<div class="kia2lox-field-grid">
					<div class="kia2lox-field">
						<label for="ms_number"><?php echo htmlspecialchars(kia2lox_t("SETTINGS.LABEL_MS")); ?></label>
						<select id="ms_number" name="ms_number" data-role="none" required>
							<?php
							$ms_selected = (string)$active["ms_number"];
							if ($ms_selected === "" || !isset($ms_options[$ms_selected])) {
								$ms_selected = (string)array_key_first($ms_options);
							}
							foreach ($ms_options as $msnr => $label):
							?>
								<option value="<?php echo htmlspecialchars($msnr); ?>"
									<?php echo ($ms_selected === (string)$msnr) ? "selected" : ""; ?>>
									<?php echo htmlspecialchars($label); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="kia2lox-field">
						<label for="udp_target_port"><?php echo htmlspecialchars(kia2lox_t("SETTINGS.LABEL_UDP_PORT")); ?></label>
						<input type="number" id="udp_target_port" name="udp_target_port" min="1" max="65535" data-role="none"
						       value="<?php echo htmlspecialchars($active["udp_target_port"]); ?>" required>
					</div>
				</div>

				<div class="kia2lox-save-row">
					<button type="submit" class="kia2lox-btn" id="kia2lox-save-ms" disabled><?php echo htmlspecialchars(kia2lox_t("SETTINGS.SAVE_MS_BUTTON")); ?></button>
					<span class="kia2lox-save-feedback" id="kia2lox-save-feedback-ms"></span>
				</div>
			</form>
		<?php endif; ?>
	</div>

	<div class="kia2lox-card">
		<div class="kia2lox-card-head">
			<div>
				<h2><?php echo htmlspecialchars(kia2lox_t("SETTINGS.INTERVAL_TITLE")); ?></h2>
				<p class="kia2lox-desc"><?php echo htmlspecialchars(kia2lox_t("SETTINGS.INTERVAL_DESC")); ?></p>
			</div>
			<?php if ($connected): ?>
				<form method="post" action="index.php">
					<input type="hidden" name="kia2lox_action" value="manual_refresh">
					<input type="hidden" name="vehicle_id" value="<?php echo htmlspecialchars($active_id); ?>">
					<button type="submit" class="kia2lox-vehicle-pill-add"><?php echo htmlspecialchars(kia2lox_t("SETTINGS.FORCE_REFRESH_BUTTON")); ?></button>
				</form>
			<?php endif; ?>
		</div>

		<?php if (!$connected): ?>
			<p class="kia2lox-hint"><?php echo htmlspecialchars(kia2lox_t("SETTINGS.INTERVAL_LOCKED_HINT")); ?></p>
		<?php else: ?>

		<?php
		$passive_mode = $active["passive_mode"] ?? "interval";
		$passive_interval = (int)($active["passive_interval_minutes"] ?? 60);
		$passive_custom_times = is_array($active["passive_custom_times"] ?? null) ? $active["passive_custom_times"] : [];
		if (empty($passive_custom_times)) {
			$passive_custom_times = ["07:30"];
		}
		$window_enabled = !empty($active["passive_window_enabled"]);
		$window_from = $active["passive_window_from"] ?? "08:00";
		$window_to = $active["passive_window_to"] ?? "18:00";
		$force_freq = (int)($active["force_freq"] ?? 2);
		$force_times = is_array($active["force_times"] ?? null) ? $active["force_times"] : [];
		if (count($force_times) !== $force_freq) {
			$force_times = KIA2LOX_FORCE_DEFAULT_TIMES[$force_freq] ?? [];
		}
		?>

		<form method="post" action="index.php" id="kia2lox-interval-form">
			<input type="hidden" name="kia2lox_action" value="save_interval">
			<input type="hidden" name="vehicle_id" value="<?php echo htmlspecialchars($active_id); ?>">

			<div class="kia2lox-interval-grid">
				<div class="kia2lox-field-block">
					<div class="kia2lox-field">
						<label for="passive_mode"><?php echo htmlspecialchars(kia2lox_t("SETTINGS.LABEL_PASSIVE_MODE")); ?></label>
						<select id="passive_mode" name="passive_mode" data-role="none">
							<?php foreach (KIA2LOX_INTERVAL_OPTIONS as $opt): ?>
								<option value="<?php echo $opt; ?>" <?php echo ($passive_mode === "interval" && $passive_interval === $opt) ? "selected" : ""; ?>>
									<?php echo htmlspecialchars(kia2lox_t("SETTINGS.OPTION_MINUTES", ["n" => $opt])); ?>
								</option>
							<?php endforeach; ?>
							<option value="never" <?php echo $passive_mode === "never" ? "selected" : ""; ?>><?php echo htmlspecialchars(kia2lox_t("SETTINGS.OPTION_NEVER")); ?></option>
							<option value="custom" <?php echo $passive_mode === "custom" ? "selected" : ""; ?>><?php echo htmlspecialchars(kia2lox_t("SETTINGS.OPTION_CUSTOM")); ?></option>
						</select>
					</div>

					<div id="kia2lox-window-toggle">
						<label class="kia2lox-checkbox">
							<input type="checkbox" id="passive_window_enabled" name="passive_window_enabled" value="1" data-role="none"
							       <?php echo $window_enabled ? "checked" : ""; ?>>
							<?php echo htmlspecialchars(kia2lox_t("SETTINGS.LABEL_WINDOW_ENABLED")); ?>
						</label>
						<div class="kia2lox-times-row" id="kia2lox-window-fields">
							<div class="kia2lox-field">
								<label for="passive_window_from"><?php echo htmlspecialchars(kia2lox_t("SETTINGS.LABEL_FROM")); ?></label>
								<input type="time" id="passive_window_from" name="passive_window_from" data-role="none"
								       value="<?php echo htmlspecialchars($window_from); ?>">
							</div>
							<div class="kia2lox-field">
								<label for="passive_window_to"><?php echo htmlspecialchars(kia2lox_t("SETTINGS.LABEL_TO")); ?></label>
								<input type="time" id="passive_window_to" name="passive_window_to" data-role="none"
								       value="<?php echo htmlspecialchars($window_to); ?>">
							</div>
						</div>
					</div>

					<div id="kia2lox-custom-times-wrap">
						<p class="kia2lox-times-label"><?php echo htmlspecialchars(kia2lox_t("SETTINGS.CUSTOM_TIMES_LABEL", ["max" => KIA2LOX_MAX_CUSTOM_TIMES])); ?></p>
						<div id="kia2lox-custom-times">
							<?php foreach ($passive_custom_times as $i => $t): ?>
								<div class="kia2lox-time-row">
									<div class="kia2lox-field">
										<label><?php echo htmlspecialchars(kia2lox_t("SETTINGS.TIMEPOINT", ["n" => $i + 1])); ?></label>
										<input type="time" name="passive_custom_times[]" data-role="none"
										       value="<?php echo htmlspecialchars($t); ?>">
									</div>
									<button type="button" class="kia2lox-time-remove" aria-label="<?php echo htmlspecialchars(kia2lox_t("SETTINGS.REMOVE_TIME_ARIA")); ?>">&times;</button>
								</div>
							<?php endforeach; ?>
						</div>
						<button type="button" id="kia2lox-add-time" class="kia2lox-vehicle-pill-add" style="margin-top:10px;"><?php echo htmlspecialchars(kia2lox_t("SETTINGS.ADD_TIME_BUTTON")); ?></button>
					</div>
				</div>

				<div class="kia2lox-field-block">
					<div class="kia2lox-field">
						<label for="force_freq"><?php echo htmlspecialchars(kia2lox_t("SETTINGS.LABEL_FORCE_FREQ")); ?></label>
						<select id="force_freq" name="force_freq" data-role="none">
							<option value="0" <?php echo $force_freq === 0 ? "selected" : ""; ?>><?php echo htmlspecialchars(kia2lox_t("SETTINGS.OPTION_FORCE_NEVER")); ?></option>
							<?php for ($i = 1; $i <= 4; $i++): ?>
								<option value="<?php echo $i; ?>" <?php echo $force_freq === $i ? "selected" : ""; ?>><?php echo htmlspecialchars(kia2lox_t("SETTINGS.OPTION_FORCE_TIMES", ["n" => $i])); ?></option>
							<?php endfor; ?>
						</select>
					</div>
					<div id="kia2lox-force-times-wrap" <?php echo $force_freq === 0 ? 'style="display:none"' : ""; ?>>
						<p class="kia2lox-times-label"><?php echo htmlspecialchars(kia2lox_t("SETTINGS.FORCE_TIMES_LABEL")); ?></p>
						<div id="kia2lox-force-times">
							<?php foreach ($force_times as $i => $t): ?>
								<div class="kia2lox-field">
									<label><?php echo htmlspecialchars(kia2lox_t("SETTINGS.TIMEPOINT", ["n" => $i + 1])); ?></label>
									<input type="time" name="force_times[]" data-role="none"
									       value="<?php echo htmlspecialchars($t); ?>">
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
			</div>

			<div class="kia2lox-save-row">
				<button type="submit" class="kia2lox-btn" id="kia2lox-save-interval" disabled><?php echo htmlspecialchars(kia2lox_t("SETTINGS.SAVE_INTERVAL_BUTTON")); ?></button>
				<span class="kia2lox-save-feedback" id="kia2lox-save-feedback-interval"></span>
			</div>

			<table class="kia2lox-schedule">
				<thead>
					<tr><th><?php echo htmlspecialchars(kia2lox_t("SETTINGS.SCHEDULE_TITLE")); ?></th><th></th></tr>
				</thead>
				<tbody>
					<tr id="kia2lox-passive-schedule-row">
						<td><?php echo htmlspecialchars(kia2lox_t("SETTINGS.SCHEDULE_PASSIVE")); ?></td>
						<td id="kia2lox-passive-schedule-pills"></td>
					</tr>
					<tr id="kia2lox-force-schedule-row">
						<td><?php echo htmlspecialchars(kia2lox_t("SETTINGS.SCHEDULE_FORCE")); ?></td>
						<td id="kia2lox-force-schedule-pills"></td>
					</tr>
				</tbody>
			</table>
		</form>
		<?php endif; ?>
	</div>

	<?php
	$http_host = $_SERVER["HTTP_HOST"] ?? "";
	$http_key = $active["http_key"] ?? "";
	$poll_url = "http://{$http_host}/plugins/kia2lox/poll.php?key=" . urlencode($http_key);
	$refresh_url = "http://{$http_host}/plugins/kia2lox/refresh.php?key=" . urlencode($http_key);
	?>
	<div class="kia2lox-card">
		<h2><?php echo htmlspecialchars(kia2lox_t("SETTINGS.HTTP_TITLE")); ?></h2>
		<p class="kia2lox-desc"><?php echo htmlspecialchars(kia2lox_t("SETTINGS.HTTP_DESC")); ?></p>

		<?php if (!$connected): ?>
			<p class="kia2lox-hint"><?php echo htmlspecialchars(kia2lox_t("SETTINGS.HTTP_LOCKED_HINT")); ?></p>
		<?php else: ?>

		<div class="kia2lox-url-block">
			<p class="kia2lox-url-label"><?php echo htmlspecialchars(kia2lox_t("SETTINGS.POLL_LABEL")); ?></p>
			<div class="kia2lox-url-row">
				<code id="kia2lox-poll-url"><?php echo htmlspecialchars($poll_url); ?></code>
				<button type="button" class="kia2lox-copy-btn" data-copy-target="kia2lox-poll-url"><?php echo htmlspecialchars(kia2lox_t("SETTINGS.COPY_BUTTON")); ?></button>
			</div>
		</div>

		<div class="kia2lox-url-block">
			<p class="kia2lox-url-label"><?php echo htmlspecialchars(kia2lox_t("SETTINGS.REFRESH_LABEL")); ?></p>
			<div class="kia2lox-url-row">
				<code id="kia2lox-refresh-url"><?php echo htmlspecialchars($refresh_url); ?></code>
				<button type="button" class="kia2lox-copy-btn" data-copy-target="kia2lox-refresh-url"><?php echo htmlspecialchars(kia2lox_t("SETTINGS.COPY_BUTTON")); ?></button>
			</div>
		</div>
		<?php endif; ?>
	</div>

	<div class="kia2lox-card">
		<h2><?php echo htmlspecialchars(kia2lox_t("SETTINGS.TEMPLATES_TITLE")); ?></h2>
		<p class="kia2lox-desc"><?php echo htmlspecialchars(kia2lox_t("SETTINGS.TEMPLATES_DESC")); ?></p>
		<?php if (!$connected): ?>
			<p class="kia2lox-hint"><?php echo htmlspecialchars(kia2lox_t("SETTINGS.TEMPLATES_LOCKED_HINT")); ?></p>
		<?php else: ?>
		<div class="kia2lox-template-grid">
			<div class="kia2lox-template-card">
				<h3><?php echo htmlspecialchars(kia2lox_t("SETTINGS.INPUT_TEMPLATE_TITLE")); ?></h3>
				<p><?php echo htmlspecialchars(kia2lox_t("SETTINGS.INPUT_TEMPLATE_DESC")); ?></p>
				<a class="kia2lox-vehicle-pill-add" href="template_input.php?vehicle=<?php echo urlencode($active_id); ?>">&#8595;&nbsp;<?php echo htmlspecialchars(kia2lox_t("SETTINGS.DOWNLOAD_BUTTON")); ?></a>
			</div>
			<div class="kia2lox-template-card">
				<h3><?php echo htmlspecialchars(kia2lox_t("SETTINGS.OUTPUT_TEMPLATE_TITLE")); ?></h3>
				<p><?php echo htmlspecialchars(kia2lox_t("SETTINGS.OUTPUT_TEMPLATE_DESC")); ?></p>
				<a class="kia2lox-vehicle-pill-add" href="template_output.php?vehicle=<?php echo urlencode($active_id); ?>">&#8595;&nbsp;<?php echo htmlspecialchars(kia2lox_t("SETTINGS.DOWNLOAD_BUTTON")); ?></a>
			</div>
		</div>
		<?php endif; ?>
	</div>

</div>
</div>
<script>
	var KIA2LOX_L = <?php echo json_encode([
		"timepoint" => kia2lox_t("SETTINGS.TIMEPOINT"),
		"remove_time" => kia2lox_t("SETTINGS.REMOVE_TIME_ARIA"),
		"save_error_default" => kia2lox_t("SETTINGS.SAVE_ERROR_DEFAULT"),
		"save_ok_default" => kia2lox_t("SETTINGS.SAVE_OK_DEFAULT"),
		"save_error_connection" => kia2lox_t("SETTINGS.SAVE_ERROR_CONNECTION"),
		"copied" => kia2lox_t("SETTINGS.COPIED_BUTTON"),
	]); ?>;

	// Speichern-Buttons: grau/inaktiv, bis sich etwas in der jeweiligen
	// Karte tatsaechlich geaendert hat (und die Pflichtfelder gueltig sind).
	// resetBaseline() wird nach einem erfolgreichen AJAX-Speichern
	// aufgerufen, damit der Button ohne Seiten-Reload wieder grau wird.
	var kia2loxSaveGroups = {};
	function kia2loxInitSaveGroup(name, fieldIds, btnId, extraCheck) {
		var fields = fieldIds.map(function (id) { return document.getElementById(id); });
		var btn = document.getElementById(btnId);
		if (!btn || fields.some(function (el) { return !el; })) { return null; }
		var initial = fields.map(function (el) { return el.value; });

		function check() {
			var dirty = fields.some(function (el, i) { return el.value !== initial[i]; });
			var ok = extraCheck ? extraCheck(fields) : true;
			btn.disabled = !(dirty && ok);
		}
		fields.forEach(function (el) {
			var evt = el.tagName === "SELECT" ? "change" : "input";
			el.addEventListener(evt, check);
		});
		check();

		var group = {
			resetBaseline: function () {
				initial = fields.map(function (el) { return el.value; });
				check();
			}
		};
		kia2loxSaveGroups[name] = group;
		return group;
	}

	// Speichert ein Formular per AJAX (kein Seiten-Reload, keine Banner-Box
	// oben). Zeigt stattdessen kurz einen Haken/Fehlertext neben dem
	// jeweiligen Button an - wie im abgestimmten Mockup.
	function kia2loxAjaxSave(form, feedbackId, onSuccess) {
		if (!form) { return; }
		var feedback = document.getElementById(feedbackId);
		var btn = form.querySelector('button[type="submit"]');
		form.addEventListener("submit", function (e) {
			e.preventDefault();
			if (btn) { btn.disabled = true; }
			var formData = new FormData(form);
			fetch(form.action, { method: "POST", body: formData })
				.then(function (resp) { return resp.json(); })
				.then(function (data) {
					if (feedback) {
						feedback.textContent = (data.ok ? "✓ " : "") + (data.message || (data.ok ? KIA2LOX_L.save_ok_default : KIA2LOX_L.save_error_default));
						feedback.classList.remove("kia2lox-save-ok", "kia2lox-save-error");
						feedback.classList.add(data.ok ? "kia2lox-save-ok" : "kia2lox-save-error", "show");
						clearTimeout(feedback._kia2loxHideTimer);
						feedback._kia2loxHideTimer = setTimeout(function () {
							feedback.classList.remove("show");
						}, data.ok ? 3000 : 6000);
					}
					if (data.ok && onSuccess) { onSuccess(data); }
					if (!data.ok && btn) { btn.disabled = false; }
				})
				.catch(function () {
					if (feedback) {
						feedback.textContent = KIA2LOX_L.save_error_connection;
						feedback.classList.remove("kia2lox-save-ok");
						feedback.classList.add("kia2lox-save-error", "show");
					}
					if (btn) { btn.disabled = false; }
				});
		});
	}

	var KIA2LOX_EMAIL_RE = /^[^\s@<>]+@[^\s@<>]+\.[A-Za-z]{2,}$/;
	function kia2loxCredComplete() {
		var name = document.getElementById("vehicle_name").value.trim();
		var email = document.getElementById("kia_username").value.trim();
		var password = document.getElementById("kia_password").value.trim();
		return name !== "" && KIA2LOX_EMAIL_RE.test(email) && password !== "";
	}
	kia2loxInitSaveGroup(
		"cred",
		["vehicle_name", "kia_username", "kia_password", "kia_pin"],
		"kia2lox-save-cred",
		function () { return kia2loxCredComplete(); }
	);
	kia2loxInitSaveGroup(
		"ms",
		["ms_number", "udp_target_port"],
		"kia2lox-save-ms",
		function (fields) {
			return fields[0].value !== "" && fields[1].value.trim() !== "";
		}
	);

	// Zugangsdaten-Karte rot hinterlegen, solange Fahrzeugname, eine
	// gueltige E-Mail und ein Passwort nicht (mehr) vollstaendig sind -
	// unabhaengig davon, ob schon gespeichert wurde. setConnected(true)
	// wird nach einem erfolgreichen Login-Test aufgerufen, damit die Karte
	// auch ohne Seiten-Reload dauerhaft gruen bleibt.
	var kia2loxCredCardState = (function () {
		var card = document.getElementById("kia2lox-cred-card");
		var fields = ["vehicle_name", "kia_username", "kia_password"].map(function (id) {
			return document.getElementById(id);
		});
		var wasConnected = card.dataset.connected === "1";
		function refresh() {
			var complete = kia2loxCredComplete();
			card.classList.toggle("kia2lox-card-incomplete", !complete);
			card.classList.toggle("kia2lox-card-connected", complete && wasConnected);
		}
		fields.forEach(function (el) { el.addEventListener("input", refresh); });
		refresh();
		return {
			setConnected: function (v) { wasConnected = v; refresh(); }
		};
	})();

	kia2loxAjaxSave(document.getElementById("kia2lox-cred-form"), "kia2lox-save-feedback-cred", function (data) {
		// Beim allerersten erfolgreichen Verbinden werden die zuvor
		// gesperrten Karten (Intervall, HTTP-Befehle, Vorlagen) erst nach
		// einem Neuladen server-seitig entsperrt angezeigt.
		var wasConnectedBefore = document.getElementById("kia2lox-cred-card").dataset.connected === "1";
		if (kia2loxSaveGroups.cred) { kia2loxSaveGroups.cred.resetBaseline(); }
		if (data.connected) { kia2loxCredCardState.setConnected(true); }
		if (data.name) {
			var pill = document.querySelector(".kia2lox-vehicle-pill.active .kia2lox-vehicle-pill-label");
			if (pill) { pill.textContent = data.name.trim(); }
		}
		if (data.connected && !wasConnectedBefore) {
			window.location.reload();
		}
	});
	kia2loxAjaxSave(document.getElementById("kia2lox-ms-form"), "kia2lox-save-feedback-ms", function () {
		if (kia2loxSaveGroups.ms) { kia2loxSaveGroups.ms.resetBaseline(); }
	});

	// Einzelnes Feld rot hinterlegen, sobald es beim Verlassen (blur)
	// ungueltig/leer ist. Waehrend des Tippens wird die Markierung schon
	// wieder entfernt, sobald der Wert gueltig ist.
	(function () {
		var checks = {
			vehicle_name: function (v) { return v.trim() !== ""; },
			kia_username: function (v) { return KIA2LOX_EMAIL_RE.test(v.trim()); },
			kia_password: function (v) { return v.trim() !== ""; }
		};
		Object.keys(checks).forEach(function (id) {
			var el = document.getElementById(id);
			var isValid = checks[id];
			el.addEventListener("blur", function () {
				el.classList.toggle("kia2lox-field-invalid", !isValid(el.value));
			});
			el.addEventListener("input", function () {
				if (isValid(el.value)) {
					el.classList.remove("kia2lox-field-invalid");
				}
			});
		});
	})();

	// Intervall-Karte: Modus-abhaengige Bereiche ein-/ausblenden, Zeiten
	// dynamisch hinzufuegen/entfernen, "Heute geplant" live nachrechnen,
	// und der Speichern-Button wird erst aktiv, wenn sich wirklich etwas
	// gegenueber dem geladenen Stand geaendert hat.
	(function () {
		var form = document.getElementById("kia2lox-interval-form");
		if (!form) { return; }

		var MAX_CUSTOM_TIMES = <?php echo KIA2LOX_MAX_CUSTOM_TIMES; ?>;
		var NOW_HHMM = "<?php echo date("H:i"); ?>";
		var FORCE_DEFAULT_TIMES = <?php echo json_encode(KIA2LOX_FORCE_DEFAULT_TIMES); ?>;
		// Heutige Abfrage-Versuche (Erfolg/Fehler) fuer "Heute geplant".
		var POLL_LOG = <?php echo json_encode($poll_log); ?>;

		var passiveModeSelect = document.getElementById("passive_mode");
		var windowToggleWrap = document.getElementById("kia2lox-window-toggle");
		var windowEnabledCheckbox = document.getElementById("passive_window_enabled");
		var windowFields = document.getElementById("kia2lox-window-fields");
		var customTimesWrap = document.getElementById("kia2lox-custom-times-wrap");
		var customTimesList = document.getElementById("kia2lox-custom-times");
		var addTimeBtn = document.getElementById("kia2lox-add-time");

		var forceFreqSelect = document.getElementById("force_freq");
		var forceTimesWrap = document.getElementById("kia2lox-force-times-wrap");
		var forceTimesList = document.getElementById("kia2lox-force-times");

		var saveBtn = document.getElementById("kia2lox-save-interval");
		var passiveScheduleRow = document.getElementById("kia2lox-passive-schedule-row");
		var passiveSchedulePills = document.getElementById("kia2lox-passive-schedule-pills");
		var forceScheduleRow = document.getElementById("kia2lox-force-schedule-row");
		var forceSchedulePills = document.getElementById("kia2lox-force-schedule-pills");

		function isIntervalMode(mode) { return mode !== "never" && mode !== "custom"; }

		// ---------- Passives Intervall: Modus-abhaengige Bereiche ----------
		function updatePassiveVisibility() {
			var mode = passiveModeSelect.value;
			windowToggleWrap.style.display = isIntervalMode(mode) ? "" : "none";
			customTimesWrap.style.display = mode === "custom" ? "" : "none";
			if (mode === "custom" && customTimesList.children.length === 0) {
				addCustomTimeRow("07:30");
			}
		}
		function updateWindowFieldsVisibility() {
			windowFields.style.display = windowEnabledCheckbox.checked ? "" : "none";
		}

		function renumberCustomTimes() {
			var rows = customTimesList.querySelectorAll(".kia2lox-time-row");
			rows.forEach(function (row, i) {
				row.querySelector("label").textContent = KIA2LOX_L.timepoint.replace("{n}", i + 1);
				row.querySelector(".kia2lox-time-remove").disabled = rows.length <= 1;
			});
			addTimeBtn.style.display = rows.length >= MAX_CUSTOM_TIMES ? "none" : "";
		}

		function addCustomTimeRow(value) {
			if (customTimesList.children.length >= MAX_CUSTOM_TIMES) { return; }
			var row = document.createElement("div");
			row.className = "kia2lox-time-row";

			var field = document.createElement("div");
			field.className = "kia2lox-field";
			var label = document.createElement("label");
			label.textContent = KIA2LOX_L.timepoint.replace("{n}", customTimesList.children.length + 1);
			var input = document.createElement("input");
			input.type = "time";
			input.name = "passive_custom_times[]";
			input.setAttribute("data-role", "none");
			input.value = value || "";
			input.addEventListener("input", checkDirty);
			field.appendChild(label);
			field.appendChild(input);

			var removeBtn = document.createElement("button");
			removeBtn.type = "button";
			removeBtn.className = "kia2lox-time-remove";
			removeBtn.setAttribute("aria-label", KIA2LOX_L.remove_time);
			removeBtn.innerHTML = "&times;";
			removeBtn.addEventListener("click", function () {
				row.remove();
				renumberCustomTimes();
				checkDirty();
			});

			row.appendChild(field);
			row.appendChild(removeBtn);
			customTimesList.appendChild(row);
			renumberCustomTimes();
		}

		customTimesList.querySelectorAll(".kia2lox-time-remove").forEach(function (btn) {
			btn.addEventListener("click", function () {
				btn.closest(".kia2lox-time-row").remove();
				renumberCustomTimes();
				checkDirty();
			});
		});
		renumberCustomTimes();
		addTimeBtn.addEventListener("click", function () {
			addCustomTimeRow("");
			checkDirty();
		});

		// ---------- Force-Refresh: Uhrzeit-Felder je nach Haeufigkeit ----------
		function renderForceTimeFields(count, existingValues) {
			forceTimesList.innerHTML = "";
			forceTimesWrap.style.display = count > 0 ? "" : "none";
			var defaults = FORCE_DEFAULT_TIMES[count] || [];
			for (var i = 0; i < count; i++) {
				var field = document.createElement("div");
				field.className = "kia2lox-field";
				var label = document.createElement("label");
				label.textContent = KIA2LOX_L.timepoint.replace("{n}", i + 1);
				var input = document.createElement("input");
				input.type = "time";
				input.name = "force_times[]";
				input.setAttribute("data-role", "none");
				input.value = (existingValues && existingValues[i]) || defaults[i] || "12:00";
				input.addEventListener("input", checkDirty);
				field.appendChild(label);
				field.appendChild(input);
				forceTimesList.appendChild(field);
			}
		}

		forceFreqSelect.addEventListener("change", function () {
			renderForceTimeFields(parseInt(this.value, 10), null);
			checkDirty();
		});

		// ---------- "Heute geplant": Vorschau der heutigen Ablauftermine ----------
		function passiveTimesToday() {
			var mode = passiveModeSelect.value;
			if (mode === "never") { return []; }
			if (mode === "custom") {
				return Array.prototype.map.call(
					customTimesList.querySelectorAll('input[type="time"]'),
					function (el) { return el.value; }
				).filter(function (v) { return v !== ""; }).sort();
			}
			var interval = parseInt(mode, 10);
			var fromStr = "00:00", toStr = "23:59";
			if (windowEnabledCheckbox.checked) {
				fromStr = document.getElementById("passive_window_from").value || fromStr;
				toStr = document.getElementById("passive_window_to").value || toStr;
			}
			var toMinutes = function (s) {
				var parts = s.split(":");
				return parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10);
			};
			var pad = function (n) { return (n < 10 ? "0" : "") + n; };
			var from = toMinutes(fromStr), to = toMinutes(toStr);
			var times = [];
			if (from <= to) {
				for (var m = from; m <= to; m += interval) {
					times.push(pad(Math.floor(m / 60) % 24) + ":" + pad(m % 60));
				}
			} else {
				for (var m2 = from; m2 < 24 * 60; m2 += interval) {
					times.push(pad(Math.floor(m2 / 60) % 24) + ":" + pad(m2 % 60));
				}
				for (var m3 = 0; m3 <= to; m3 += interval) {
					times.push(pad(Math.floor(m3 / 60) % 24) + ":" + pad(m3 % 60));
				}
			}
			return times;
		}

		// Zu einem geplanten Zeitpunkt (kind = "passive"/"force") den
		// passenden Log-Eintrag von heute suchen (falls vorhanden).
		function findLogEntry(kind, time) {
			for (var i = 0; i < POLL_LOG.length; i++) {
				if (POLL_LOG[i].kind === kind && POLL_LOG[i].time === time) { return POLL_LOG[i]; }
			}
			return null;
		}

		function renderPills(container, kind, times) {
			container.innerHTML = "";
			if (times.length === 0) {
				container.innerHTML = "<span class=\"kia2lox-pill kia2lox-pill-none\">&#10005;</span>";
				return;
			}
			times.forEach(function (time) {
				var entry = findLogEntry(kind, time);
				var cls, icon;
				if (entry && entry.ok) {
					cls = "kia2lox-pill-ok"; icon = "✓";
				} else if (entry && !entry.ok) {
					cls = "kia2lox-pill-error"; icon = "✕";
				} else {
					cls = "kia2lox-pill-none"; icon = "✕";
				}
				var pill = document.createElement("span");
				pill.className = "kia2lox-pill " + cls;
				pill.textContent = icon + " " + time;
				container.appendChild(pill);
			});
		}

		function scheduleAll() {
			var passiveTimes = passiveTimesToday();
			passiveScheduleRow.style.display = passiveModeSelect.value === "never" ? "none" : "";
			renderPills(passiveSchedulePills, "passive", passiveTimes);

			var forceTimes = Array.prototype.map.call(
				forceTimesList.querySelectorAll('input[type="time"]'),
				function (el) { return el.value; }
			).filter(function (v) { return v !== ""; }).sort();
			forceScheduleRow.style.display = forceTimes.length === 0 ? "none" : "";
			renderPills(forceSchedulePills, "force", forceTimes);
		}

		// ---------- Speichern-Button: nur aktiv, wenn wirklich geaendert ----------
		function snapshot() {
			var customTimes = Array.prototype.map.call(
				customTimesList.querySelectorAll('input[type="time"]'),
				function (el) { return el.value; }
			);
			var forceTimes = Array.prototype.map.call(
				forceTimesList.querySelectorAll('input[type="time"]'),
				function (el) { return el.value; }
			);
			return JSON.stringify({
				passiveMode: passiveModeSelect.value,
				windowEnabled: windowEnabledCheckbox.checked,
				from: document.getElementById("passive_window_from").value,
				to: document.getElementById("passive_window_to").value,
				customTimes: customTimes,
				forceFreq: forceFreqSelect.value,
				forceTimes: forceTimes
			});
		}
		var initialSnapshot = snapshot();
		function checkDirty() {
			saveBtn.disabled = snapshot() === initialSnapshot;
		}

		windowEnabledCheckbox.addEventListener("change", function () {
			updateWindowFieldsVisibility();
			checkDirty();
		});
		["passive_window_from", "passive_window_to"].forEach(function (id) {
			document.getElementById(id).addEventListener("input", checkDirty);
		});
		passiveModeSelect.addEventListener("change", function () {
			updatePassiveVisibility();
			checkDirty();
		});

		updatePassiveVisibility();
		updateWindowFieldsVisibility();
		checkDirty();
		scheduleAll();

		// "Heute geplant" erst nach erfolgreichem Speichern neu berechnen
		// (nicht schon waehrend des Eintippens/Auswaehlens).
		kia2loxAjaxSave(form, "kia2lox-save-feedback-interval", function () {
			initialSnapshot = snapshot();
			checkDirty();
			scheduleAll();
		});
	})();

	// HTTP-Befehle fuer Loxone: Adresse in die Zwischenablage kopieren.
	// document.execCommand statt navigator.clipboard, damit es auch ueber
	// normales HTTP (nicht nur HTTPS) funktioniert.
	document.querySelectorAll(".kia2lox-copy-btn").forEach(function (btn) {
		btn.addEventListener("click", function () {
			var target = document.getElementById(btn.dataset.copyTarget);
			if (!target) { return; }
			var temp = document.createElement("textarea");
			temp.value = target.textContent.trim();
			temp.style.position = "fixed";
			temp.style.opacity = "0";
			document.body.appendChild(temp);
			temp.focus();
			temp.select();
			try {
				document.execCommand("copy");
				var original = btn.textContent;
				btn.textContent = KIA2LOX_L.copied;
				setTimeout(function () { btn.textContent = original; }, 1500);
			} catch (e) { /* Zwischenablage nicht verfuegbar - Adresse ist trotzdem sichtbar. */ }
			document.body.removeChild(temp);
		});
	});
</script>
<?php require "inc_footer.php"; ?>
