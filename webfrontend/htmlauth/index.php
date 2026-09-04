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

if ($_SERVER["REQUEST_METHOD"] === "POST") {
	$action = $_POST["action"] ?? "";

	if ($action === "add_vehicle") {
		if (count($vehicles) >= KIA2LOX_MAX_VEHICLES) {
			$message = "Maximal " . KIA2LOX_MAX_VEHICLES . " Fahrzeuge moeglich.";
			$message_type = "error";
		} else {
			$name = trim($_POST["new_vehicle_name"] ?? "");
			if ($name === "") {
				$name = "Fahrzeug " . (count($vehicles) + 1);
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
			$message = "Fahrzeugname, Benutzername und Passwort sind erforderlich.";
			$message_type = "error";
		} else {
			$test = kia2lox_test_login($username, $password, $pin);
			if (!$test["ok"]) {
				$message = "Login fehlgeschlagen: " . ($test["error"] ?? "Unbekannter Fehler");
				$message_type = "error";
			} else {
				foreach ($vehicles as &$v) {
					if ($v["id"] === $id) {
						$v["name"] = $name;
						$v["kia_username"] = $username;
						$v["kia_password"] = $password;
						$v["kia_pin"] = $pin;
					}
				}
				unset($v);
				kia2lox_save_vehicles($vehicles);
				header("Location: index.php?vehicle=" . urlencode($id) . "&saved=1");
				exit;
			}
		}
	}
}

// Es sollte immer mindestens ein Fahrzeug existieren (wird eigentlich
// schon bei der Installation angelegt) - hier zur Sicherheit nochmal.
if (empty($vehicles)) {
	$vehicles = [kia2lox_default_vehicle("Fahrzeug 1", "v1")];
	kia2lox_save_vehicles($vehicles);
}

$active_id = $_GET["vehicle"] ?? $vehicles[0]["id"];
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

if (isset($_GET["saved"]) && $message === null) {
	$message = "Zugangsdaten gespeichert, Login wurde erfolgreich getestet.";
	$message_type = "ok";
}

LBWeb::lbheader("Kia2Lox V$version", "https://github.com/RiverRaid/LoxBerry-Plugin-KiaConnect", "help.html");
?>
<link rel="stylesheet" href="assets/kia2lox.css">

<div class="kia2lox-page">

	<?php if (count($vehicles) > 1): ?>
	<div class="kia2lox-vehicle-bar">
		<?php foreach ($vehicles as $v): ?>
			<a class="kia2lox-vehicle-pill<?php echo $v["id"] === $active_id ? " active" : ""; ?>"
			   href="index.php?vehicle=<?php echo urlencode($v["id"]); ?>">
				<?php echo htmlspecialchars($v["name"]); ?>
			</a>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

	<?php if ($message): ?>
		<p class="kia2lox-message kia2lox-message-<?php echo htmlspecialchars($message_type); ?>">
			<?php echo htmlspecialchars($message); ?>
		</p>
	<?php endif; ?>

	<div class="kia2lox-card">
		<h2>Kia Connect Zugangsdaten</h2>
		<p class="kia2lox-desc">Dieselben Zugangsdaten wie in der Kia-Connect-App. Beim Speichern wird ein echter Login getestet.</p>
		<form method="post" action="index.php">
			<input type="hidden" name="action" value="save_credentials">
			<input type="hidden" name="vehicle_id" value="<?php echo htmlspecialchars($active_id); ?>">

			<div class="kia2lox-field-grid">
				<div class="kia2lox-field">
					<label for="vehicle_name">Fahrzeugname</label>
					<input type="text" id="vehicle_name" name="vehicle_name"
					       value="<?php echo htmlspecialchars($active["name"]); ?>" required>
				</div>
				<div class="kia2lox-field">
					<label for="kia_username">Benutzername (E-Mail)</label>
					<input type="email" id="kia_username" name="kia_username"
					       value="<?php echo htmlspecialchars($active["kia_username"]); ?>" required>
				</div>
				<div class="kia2lox-field">
					<label for="kia_password">Passwort</label>
					<input type="password" id="kia_password" name="kia_password"
					       value="<?php echo htmlspecialchars($active["kia_password"]); ?>" required>
				</div>
				<div class="kia2lox-field">
					<label for="kia_pin">PIN</label>
					<input type="password" id="kia_pin" name="kia_pin"
					       value="<?php echo htmlspecialchars($active["kia_pin"]); ?>">
					<p class="kia2lox-hint">Nur n&ouml;tig, falls dein Account eine PIN verlangt.</p>
				</div>
			</div>

			<button type="submit" class="kia2lox-btn">Zugangsdaten speichern</button>
		</form>
	</div>

	<?php if (count($vehicles) < KIA2LOX_MAX_VEHICLES): ?>
	<div class="kia2lox-card">
		<h3>Neues Fahrzeug</h3>
		<form method="post" action="index.php" class="kia2lox-inline-form">
			<input type="hidden" name="action" value="add_vehicle">
			<div class="kia2lox-field">
				<label for="new_vehicle_name">Name</label>
				<input type="text" id="new_vehicle_name" name="new_vehicle_name" placeholder="z.B. Zweitwagen">
			</div>
			<button type="submit" class="kia2lox-btn-secondary">+ Fahrzeug hinzuf&uuml;gen</button>
		</form>
	</div>
	<?php endif; ?>

	<?php if (count($vehicles) > 1): ?>
	<form method="post" action="index.php" onsubmit="return confirm('Fahrzeug &quot;<?php echo htmlspecialchars(addslashes($active['name'])); ?>&quot; wirklich entfernen?');">
		<input type="hidden" name="action" value="remove_vehicle">
		<input type="hidden" name="vehicle_id" value="<?php echo htmlspecialchars($active_id); ?>">
		<button type="submit" class="kia2lox-btn-danger">Dieses Fahrzeug entfernen</button>
	</form>
	<?php endif; ?>

</div>
<?php

LBWeb::lbfooter();
exit;
