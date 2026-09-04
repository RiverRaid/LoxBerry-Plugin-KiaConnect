<?php

# Kia2Lox - Hilfe-Seite. Einfacher, statischer Text zur Bedienung des
# Plugins (keine interaktiven Elemente noetig).

require_once "loxberry_system.php";
require_once "loxberry_web.php";
require_once "inc_vehicles.php";

$version = LBSystem::pluginversion();
$vehicles = kia2lox_load_vehicles();
if (empty($vehicles)) {
	$vehicles = [kia2lox_default_vehicle(kia2lox_t("VEHICLES.DEFAULT_NAME", ["n" => 1]), "v1")];
	kia2lox_save_vehicles($vehicles);
}
$active_id = $_GET["vehicle"] ?? $vehicles[0]["id"];

LBWeb::lbheader("Kia2Lox V$version", "https://github.com/RiverRaid/LoxBerry-Plugin-KiaConnect", "help.html");
$kia2lox_active_tab = "help";
require "inc_header.php";
?>

	<div class="kia2lox-card">
		<h2><?php echo kia2lox_t("HELP.ABOUT_TITLE"); ?></h2>
		<p class="kia2lox-desc"><?php echo kia2lox_t("HELP.ABOUT_TEXT_1"); ?></p>
		<p class="kia2lox-desc"><?php echo kia2lox_t("HELP.ABOUT_TEXT_2"); ?></p>
		<div class="kia2lox-about-meta">
			<span class="kia2lox-version-chip"><?php echo htmlspecialchars(kia2lox_t("HELP.VERSION_LABEL")); ?> <?php echo htmlspecialchars($version); ?></span>
			<a class="kia2lox-help-link" href="https://github.com/RiverRaid/LoxBerry-Plugin-KiaConnect" target="_blank" rel="noopener"><?php echo htmlspecialchars(kia2lox_t("HELP.GITHUB_LINK")); ?></a>
		</div>
	</div>

	<div class="kia2lox-card">
		<h2><?php echo kia2lox_t("HELP.GETTING_STARTED_TITLE"); ?></h2>
		<p class="kia2lox-desc"><?php echo kia2lox_t("HELP.GETTING_STARTED_INTRO"); ?></p>
		<ol class="kia2lox-help-list">
			<li><?php echo kia2lox_t("HELP.STEP_1"); ?></li>
			<li><?php echo kia2lox_t("HELP.STEP_2"); ?></li>
			<li><?php echo kia2lox_t("HELP.STEP_3"); ?></li>
			<li><?php echo kia2lox_t("HELP.STEP_4"); ?></li>
		</ol>
		<p class="kia2lox-desc"><?php echo kia2lox_t("HELP.MAX_VEHICLES_TEXT", ["max" => KIA2LOX_MAX_VEHICLES]); ?></p>
	</div>

	<div class="kia2lox-card">
		<h2><?php echo kia2lox_t("HELP.PASSIVE_TITLE"); ?></h2>
		<p class="kia2lox-desc"><?php echo kia2lox_t("HELP.PASSIVE_INTRO"); ?></p>
		<ul class="kia2lox-help-list">
			<li><?php echo kia2lox_t("HELP.PASSIVE_ITEM"); ?></li>
			<li><?php echo kia2lox_t("HELP.FORCE_ITEM"); ?></li>
		</ul>
	</div>

	<div class="kia2lox-card">
		<h2><?php echo kia2lox_t("HELP.UDP_TITLE"); ?></h2>
		<p class="kia2lox-desc"><?php echo kia2lox_t("HELP.UDP_INTRO"); ?></p>
		<div class="kia2lox-help-table-wrap">
			<table class="kia2lox-help-table">
				<thead>
					<tr><th><?php echo htmlspecialchars(kia2lox_t("HELP.TABLE_KEY")); ?></th><th><?php echo htmlspecialchars(kia2lox_t("HELP.TABLE_MEANING")); ?></th></tr>
				</thead>
				<tbody>
					<tr><td><code>SOC</code></td><td><?php echo htmlspecialchars(kia2lox_t("HELP.ROW_SOC")); ?></td></tr>
					<tr><td><code>RANGE</code></td><td><?php echo htmlspecialchars(kia2lox_t("HELP.ROW_RANGE")); ?></td></tr>
					<tr><td><code>CHARGING</code></td><td><?php echo htmlspecialchars(kia2lox_t("HELP.ROW_CHARGING")); ?></td></tr>
					<tr><td><code>PLUGGED</code></td><td><?php echo htmlspecialchars(kia2lox_t("HELP.ROW_PLUGGED")); ?></td></tr>
					<tr><td><code>FULL</code></td><td><?php echo htmlspecialchars(kia2lox_t("HELP.ROW_FULL")); ?></td></tr>
					<tr><td><code>FULLPARKED</code></td><td><?php echo htmlspecialchars(kia2lox_t("HELP.ROW_FULLPARKED")); ?></td></tr>
					<tr><td><code>RECHARGE100</code></td><td><?php echo htmlspecialchars(kia2lox_t("HELP.ROW_RECHARGE100")); ?></td></tr>
					<tr><td><code>LOWBATTERY</code></td><td><?php echo htmlspecialchars(kia2lox_t("HELP.ROW_LOWBATTERY")); ?></td></tr>
				</tbody>
			</table>
		</div>
		<p class="kia2lox-desc"><?php echo kia2lox_t("HELP.UDP_OUTRO"); ?></p>
	</div>

	<div class="kia2lox-card">
		<h2><?php echo kia2lox_t("HELP.HTTP_TITLE"); ?></h2>
		<p class="kia2lox-desc"><?php echo kia2lox_t("HELP.HTTP_TEXT_1"); ?></p>
		<p class="kia2lox-desc"><?php echo kia2lox_t("HELP.HTTP_TEXT_2"); ?></p>
	</div>

	<div class="kia2lox-card">
		<h2><?php echo kia2lox_t("HELP.OVERVIEW_LOG_TITLE"); ?></h2>
		<p class="kia2lox-desc"><?php echo kia2lox_t("HELP.OVERVIEW_TEXT"); ?></p>
		<p class="kia2lox-desc"><?php echo kia2lox_t("HELP.LOG_TEXT"); ?></p>
	</div>

</div>
</div>
<?php
require "inc_footer.php";
