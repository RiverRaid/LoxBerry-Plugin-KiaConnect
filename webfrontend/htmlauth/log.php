<?php

# Kia2Lox - Log-Seite. Zeigt das Poll-Protokoll (bin/kia2lox_poll.py,
# per Cron alle 5 Minuten aufgerufen) direkt aus der Log-Datei an.

require_once "loxberry_system.php";
require_once "loxberry_web.php";
require_once "inc_vehicles.php";

$version = LBSystem::pluginversion();

$vehicles = kia2lox_load_vehicles();
if (empty($vehicles)) {
	$vehicles = [kia2lox_default_vehicle(kia2lox_t("VEHICLES.DEFAULT_NAME", ["n" => 1]), "v1")];
	kia2lox_save_vehicles($vehicles);
}

global $lbplogdir;
$log_path = $lbplogdir . "/poll.log";

if (($_SERVER["REQUEST_METHOD"] ?? "") === "POST" && ($_POST["kia2lox_action"] ?? "") === "clear_log") {
	if (file_exists($log_path)) {
		file_put_contents($log_path, "", LOCK_EX);
	}
	header("Location: log.php?vehicle=" . urlencode($_POST["vehicle_id"] ?? ""));
	exit;
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

// Das Protokoll enthaelt alle Fahrzeuge durchmischt (so wie sie
// tatsaechlich der Reihe nach abgefragt wurden) - keine Filterung nach
// Fahrzeug, nur die Fahrzeug-Leiste bleibt fuer die Navigation gleich.
$log_exists = file_exists($log_path);
$log_lines = [];
if ($log_exists) {
	$content = file_get_contents($log_path);
	$log_lines = explode("\n", rtrim($content, "\n"));
}
$max_lines = 500;
$log_total = count($log_lines);
if ($log_total > $max_lines) {
	$log_lines = array_slice($log_lines, -$max_lines);
}
$log_display = implode("\n", $log_lines);

$loglevel = kia2lox_current_loglevel();
$logging_disabled = $loglevel === KIA2LOX_LOGLEVEL_OFF;

LBWeb::lbheader("Kia2Lox", "https://github.com/RiverRaid/LoxBerry-Plugin-KiaConnect", "help.html");
$kia2lox_active_tab = "log";
require "inc_header.php";
?>

	<div class="kia2lox-card">
		<div class="kia2lox-card-head">
			<div>
				<h2><?php echo htmlspecialchars(kia2lox_t("LOG.TITLE")); ?> (<?php echo htmlspecialchars(kia2lox_loglevel_label($loglevel)); ?>)</h2>
				<p class="kia2lox-desc">
					<?php echo htmlspecialchars(kia2lox_t("LOG.DESC")); ?>
					<?php if ($log_total > $max_lines): ?>
						<?php echo htmlspecialchars(kia2lox_t("LOG.TRUNCATED", ["shown" => $max_lines, "total" => $log_total])); ?>
					<?php endif; ?>
				</p>
			</div>
			<div class="kia2lox-log-actions">
				<a class="kia2lox-vehicle-pill-add" href="log.php?vehicle=<?php echo urlencode($active_id); ?>"><?php echo htmlspecialchars(kia2lox_t("LOG.REFRESH_BUTTON")); ?></a>
				<form method="post" action="log.php"
				      onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(kia2lox_t("LOG.CLEAR_CONFIRM"))); ?>);">
					<input type="hidden" name="kia2lox_action" value="clear_log">
					<input type="hidden" name="vehicle_id" value="<?php echo htmlspecialchars($active_id); ?>">
					<button type="submit" class="kia2lox-btn-danger"><?php echo htmlspecialchars(kia2lox_t("LOG.CLEAR_BUTTON")); ?></button>
				</form>
			</div>
		</div>

		<?php if ($logging_disabled): ?>
			<p class="kia2lox-hint"><?php echo htmlspecialchars(kia2lox_t("LOG.DISABLED_HINT")); ?></p>
		<?php elseif (!$log_exists || trim($log_display) === ""): ?>
			<p class="kia2lox-hint"><?php echo htmlspecialchars(kia2lox_t("LOG.EMPTY_HINT")); ?></p>
		<?php else: ?>
			<pre class="kia2lox-log-box" id="kia2lox-log-box"><?php echo htmlspecialchars($log_display); ?></pre>
		<?php endif; ?>
	</div>

</div>
</div>
<script>
	// Ans Ende scrollen, damit die neuesten Eintraege sofort sichtbar sind.
	(function () {
		var box = document.getElementById("kia2lox-log-box");
		if (box) { box.scrollTop = box.scrollHeight; }
	})();
</script>
<?php require "inc_footer.php"; ?>
