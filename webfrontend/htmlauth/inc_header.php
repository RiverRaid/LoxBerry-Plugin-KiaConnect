<?php
// Kia2Lox - gemeinsamer Seiten-Kopf (Hero, Tab-Leiste, Fahrzeug-Leiste)
// fuer alle Unterseiten. Erwartet vom aufrufenden Script bereits gesetzt:
// $version, $vehicles, $active_id, $kia2lox_active_tab
// ("settings"/"log"/"help"/"overview").

function kia2lox_page_link($file, $vehicle_id) {
	return $file . ($vehicle_id ? ("?vehicle=" . urlencode($vehicle_id)) : "");
}
?>
<link rel="stylesheet" href="assets/kia2lox.css">

<div data-enhance="false">
<div class="kia2lox-shell">
	<div class="kia2lox-hero">
		<div class="kia2lox-hero-inner">
			<div>
				<h1>Kia2Lox</h1>
				<p>Sendet den Ladezustand eines Kia e-Autos per UDP an Loxone</p>
			</div>
			<div class="kia2lox-hero-actions">
				<?php if ($kia2lox_active_tab === "overview" && !empty($connected)): ?>
					<button class="kia2lox-settings-gear-btn" id="kia2lox-btn-toggle-boxes" type="button" data-role="none"
					        aria-haspopup="true" aria-expanded="false" aria-label="Boxen ausw&auml;hlen">
						<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
							<path fill="currentColor" d="M19.14,12.94c0.04,-0.3 0.06,-0.61 0.06,-0.94c0,-0.32 -0.02,-0.64 -0.07,-0.94l2.03,-1.58c0.18,-0.14 0.23,-0.41 0.12,-0.61l-1.92,-3.32c-0.12,-0.22 -0.37,-0.29 -0.59,-0.22l-2.39,0.96c-0.5,-0.38 -1.03,-0.7 -1.62,-0.94L14.4,2.81c-0.04,-0.24 -0.24,-0.41 -0.48,-0.41h-3.84c-0.24,0 -0.43,0.17 -0.47,0.41L9.25,5.35C8.66,5.59 8.12,5.92 7.63,6.29L5.24,5.33c-0.22,-0.08 -0.47,0 -0.59,0.22L2.74,8.87C2.62,9.08 2.66,9.34 2.86,9.48l2.03,1.58C4.84,11.36 4.8,11.69 4.8,12s0.02,0.64 0.07,0.94l-2.03,1.58c-0.18,0.14 -0.23,0.41 -0.12,0.61l1.92,3.32c0.12,0.22 0.37,0.29 0.59,0.22l2.39,-0.96c0.5,0.38 1.03,0.7 1.62,0.94l0.36,2.54c0.05,0.24 0.24,0.41 0.48,0.41h3.84c0.24,0 0.44,-0.17 0.47,-0.41l0.36,-2.54c0.59,-0.24 1.13,-0.56 1.62,-0.94l2.39,0.96c0.22,0.08 0.47,0 0.59,-0.22l1.92,-3.32c0.12,-0.22 0.07,-0.47 -0.12,-0.61L19.14,12.94z M12,15.6c-1.98,0 -3.6,-1.62 -3.6,-3.6s1.62,-3.6 3.6,-3.6s3.6,1.62 3.6,3.6S13.98,15.6 12,15.6z"/>
						</svg>
					</button>
				<?php endif; ?>
				<span class="kia2lox-version-badge">V<?php echo htmlspecialchars($version); ?></span>
			</div>
		</div>
	</div>

	<?php if ($kia2lox_active_tab === "overview" && !empty($connected)): ?>
	<div class="kia2lox-boxes-popover" id="kia2lox-boxes-popover" hidden>
		<p class="kia2lox-boxes-popover-title">Boxen anzeigen</p>
		<div class="kia2lox-boxes-popover-list" id="kia2lox-boxes-popover-list"></div>
		<p class="kia2lox-boxes-popover-footer">Wird in diesem Browser gespeichert.</p>
	</div>
	<?php endif; ?>

	<div class="kia2lox-tabs">
		<a class="kia2lox-tab<?php echo $kia2lox_active_tab === "overview" ? " active" : ""; ?>"
		   href="<?php echo kia2lox_page_link("overview.php", $active_id); ?>">&Uuml;bersicht</a>
		<a class="kia2lox-tab<?php echo $kia2lox_active_tab === "settings" ? " active" : ""; ?>"
		   href="<?php echo kia2lox_page_link("index.php", $active_id); ?>">Einstellungen</a>
		<a class="kia2lox-tab<?php echo $kia2lox_active_tab === "log" ? " active" : ""; ?>"
		   href="<?php echo kia2lox_page_link("log.php", $active_id); ?>">Log</a>
		<a class="kia2lox-tab<?php echo $kia2lox_active_tab === "help" ? " active" : ""; ?>"
		   href="<?php echo kia2lox_page_link("help.php", $active_id); ?>">Hilfe</a>
	</div>
</div>

<div class="kia2lox-page">

	<?php if ($kia2lox_active_tab === "settings" || ($kia2lox_active_tab === "overview" && count($vehicles) > 1)): ?>
	<div class="kia2lox-vehicle-bar">
		<?php foreach ($vehicles as $v): ?>
			<div class="kia2lox-vehicle-pill<?php echo $v["id"] === $active_id ? " active" : ""; ?>">
				<a class="kia2lox-vehicle-pill-label" href="<?php echo kia2lox_page_link(basename($_SERVER["SCRIPT_NAME"]), $v["id"]); ?>">
					<?php echo htmlspecialchars($v["name"]); ?>
				</a>
				<?php if ($kia2lox_active_tab === "settings" && count($vehicles) > 1): ?>
					<form method="post" action="index.php" class="kia2lox-vehicle-pill-remove-form"
					      onsubmit="return confirm('Fahrzeug &quot;<?php echo htmlspecialchars(addslashes($v["name"])); ?>&quot; wirklich entfernen?');">
						<input type="hidden" name="kia2lox_action" value="remove_vehicle">
						<input type="hidden" name="vehicle_id" value="<?php echo htmlspecialchars($v["id"]); ?>">
						<button type="submit" class="kia2lox-vehicle-pill-remove" aria-label="Fahrzeug &quot;<?php echo htmlspecialchars($v["name"]); ?>&quot; entfernen">&times;</button>
					</form>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>

		<?php if ($kia2lox_active_tab === "settings" && count($vehicles) < KIA2LOX_MAX_VEHICLES): ?>
			<button type="button" class="kia2lox-vehicle-pill-add" onclick="kia2loxAddVehicle()">+ Fahrzeug</button>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<?php if ($kia2lox_active_tab === "settings"): ?>
	<form method="post" action="index.php" id="kia2lox-add-vehicle-form" style="display:none;">
		<input type="hidden" name="kia2lox_action" value="add_vehicle">
		<input type="hidden" name="new_vehicle_name" id="kia2lox-new-vehicle-name">
	</form>
	<script>
		function kia2loxAddVehicle() {
			var name = prompt("Name des neuen Fahrzeugs:");
			if (!name) { return; }
			document.getElementById("kia2lox-new-vehicle-name").value = name;
			document.getElementById("kia2lox-add-vehicle-form").submit();
		}
	</script>
	<?php endif; ?>
