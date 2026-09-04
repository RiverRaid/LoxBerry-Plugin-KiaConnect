<?php

# Kia2Lox - Uebersichtsseite: Statuskarten, Warn-Banner und
# Ladezustands-Diagramm, alles mit echten Daten aus state.json bzw.
# history_<id>.jsonl (von bin/kia2lox_poll.py gepflegt).

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

$connected = !empty($active["kia_connected"]);

$state = kia2lox_load_state();
$vstate = $state[$active_id] ?? [];
$last_values = is_array($vstate["last_values"] ?? null) ? $vstate["last_values"] : null;
$last_poll_ok = $vstate["last_poll_ok"] ?? null;
$last_poll_at = $vstate["last_passive_poll_at"] ?? null;

$ms_number = (string)($active["ms_number"] ?? "");
$ms_name = "";
foreach (LBSystem::get_miniservers() as $msnr => $ms) {
	if ((string)$msnr === $ms_number) {
		$ms_name = $ms["Name"] ?? "";
		break;
	}
}
$ms_ip = kia2lox_miniserver_ip($ms_number);
$ms_reachable = $ms_ip !== "" ? kia2lox_ping_miniserver($ms_ip) : null;

$next_time = kia2lox_next_passive_time($active, date("H:i"));
$history = kia2lox_load_history($active_id);

LBWeb::lbheader("Kia2Lox", "https://github.com/RiverRaid/LoxBerry-Plugin-KiaConnect", "help.html");
$kia2lox_active_tab = "overview";
require "inc_header.php";
?>

	<?php if (!$connected): ?>
		<div class="kia2lox-connect-notice">
			<p class="kia2lox-connect-notice-title"><?php echo htmlspecialchars(kia2lox_t("OVERVIEW.CONNECT_NOTICE_TITLE")); ?></p>
			<p class="kia2lox-connect-notice-text"><?php echo htmlspecialchars(kia2lox_t("OVERVIEW.CONNECT_NOTICE_TEXT")); ?></p>
			<a class="kia2lox-vehicle-pill-add" href="index.php?vehicle=<?php echo urlencode($active_id); ?>"><?php echo htmlspecialchars(kia2lox_t("OVERVIEW.CONNECT_NOTICE_BUTTON")); ?></a>
		</div>
	<?php else: ?>

		<?php
		$soc = $last_values["soc"] ?? null;
		$range_km = $last_values["range_km"] ?? null;
		$charging = (int)($last_values["charging"] ?? 0);
		$plugged = $last_values["plugged"] ?? 0;
		$full_parked = (int)($last_values["full_parked"] ?? 0);
		$recharge_needed = (int)($last_values["recharge_needed"] ?? 0);
		$low_battery = (int)($last_values["low_battery"] ?? 0);
		$is_plugged = !empty($plugged);

		if (!$is_plugged) {
			$plug_state_class = "";
			$plug_value = kia2lox_t("OVERVIEW.NOT_PLUGGED");
			$plug_sub = "&ndash;";
		} elseif ($charging) {
			$plug_state_class = " kia2lox-plug-in kia2lox-plug-charging";
			$plug_value = kia2lox_t("OVERVIEW.PLUGGED");
			$plug_sub = kia2lox_t("OVERVIEW.CHARGING_NOW");
		} else {
			$plug_state_class = " kia2lox-plug-in";
			$plug_value = kia2lox_t("OVERVIEW.PLUGGED");
			$plug_sub = kia2lox_t("OVERVIEW.NOT_CHARGING");
		}
		?>

		<div class="kia2lox-stat-grid">
			<div class="kia2lox-stat-card" id="kia2lox-vehicle-card" data-soc="<?php echo $soc !== null ? (int)$soc : ""; ?>">
				<p class="kia2lox-stat-label"><?php echo htmlspecialchars(kia2lox_t("OVERVIEW.LABEL_VEHICLE")); ?></p>
				<div class="kia2lox-stat-value"><?php echo htmlspecialchars($active["name"]); ?></div>
				<p class="kia2lox-stat-sub">
					<?php echo $soc !== null ? (int)$soc . "&nbsp;%" : "&ndash;"; ?>
					&middot;
					<?php echo htmlspecialchars($range_km !== null ? kia2lox_t("OVERVIEW.RANGE_KM", ["km" => (int)$range_km]) : kia2lox_t("OVERVIEW.RANGE_UNKNOWN")); ?>
				</p>
			</div>

			<div class="kia2lox-stat-card<?php echo $plug_state_class; ?>" id="kia2lox-plug-card">
				<p class="kia2lox-stat-label"><?php echo htmlspecialchars(kia2lox_t("OVERVIEW.LABEL_PLUG_STATUS")); ?></p>
				<div class="kia2lox-stat-value"><?php echo htmlspecialchars($plug_value); ?></div>
				<p class="kia2lox-stat-sub"><?php echo $plug_sub === "&ndash;" ? $plug_sub : htmlspecialchars($plug_sub); ?></p>
			</div>

			<div class="kia2lox-stat-card<?php echo $last_poll_ok === false ? " kia2lox-status-bad" : ($last_poll_ok === true ? " kia2lox-status-ok" : ""); ?>" id="kia2lox-lastpoll-card">
				<p class="kia2lox-stat-label"><?php echo htmlspecialchars(kia2lox_t("OVERVIEW.LABEL_LAST_POLL")); ?></p>
				<div class="kia2lox-stat-value mono"><?php echo $last_poll_at ? htmlspecialchars(date("H:i", strtotime($last_poll_at))) : "&ndash;"; ?></div>
				<p class="kia2lox-stat-sub">
					<?php if ($last_poll_ok === true): ?>
						<span class="kia2lox-badge-dot"></span><?php echo htmlspecialchars(kia2lox_t("OVERVIEW.STATUS_OK")); ?>
					<?php elseif ($last_poll_ok === false): ?>
						<span class="kia2lox-badge-dot kia2lox-badge-dot-bad"></span><?php echo htmlspecialchars(kia2lox_t("OVERVIEW.STATUS_FAILED")); ?>
					<?php else: ?>
						<?php echo htmlspecialchars(kia2lox_t("OVERVIEW.STATUS_NONE")); ?>
					<?php endif; ?>
				</p>
			</div>

			<div class="kia2lox-stat-card" id="kia2lox-nextpoll-card">
				<p class="kia2lox-stat-label"><?php echo htmlspecialchars(kia2lox_t("OVERVIEW.LABEL_NEXT_POLL")); ?></p>
				<div class="kia2lox-stat-value mono"><?php echo $next_time ? htmlspecialchars($next_time) : "&ndash;"; ?></div>
				<p class="kia2lox-stat-sub">
					<?php
					$mode = $active["passive_mode"] ?? "interval";
					if ($mode === "never") {
						echo htmlspecialchars(kia2lox_t("OVERVIEW.MODE_NEVER"));
					} elseif ($mode === "custom") {
						echo htmlspecialchars(kia2lox_t("OVERVIEW.MODE_CUSTOM"));
					} else {
						echo htmlspecialchars(kia2lox_t("OVERVIEW.MODE_INTERVAL", ["n" => (int)($active["passive_interval_minutes"] ?? 60)]));
					}
					?>
				</p>
			</div>

			<div class="kia2lox-stat-card<?php echo $ms_reachable === true ? " kia2lox-status-ok" : ($ms_reachable === false ? " kia2lox-status-bad" : ""); ?>" id="kia2lox-ms-status-card">
				<p class="kia2lox-stat-label"><?php echo htmlspecialchars(kia2lox_t("OVERVIEW.LABEL_MINISERVER")); ?></p>
				<div class="kia2lox-stat-value"><?php echo $ms_name !== "" ? htmlspecialchars($ms_name) : "&ndash;"; ?></div>
				<p class="kia2lox-stat-sub">
					<?php if ($ms_reachable === true): ?>
						<span class="kia2lox-badge-dot"></span><?php echo htmlspecialchars(kia2lox_t("OVERVIEW.MS_REACHABLE")); ?>
					<?php elseif ($ms_reachable === false): ?>
						<span class="kia2lox-badge-dot kia2lox-badge-dot-bad"></span><?php echo htmlspecialchars(kia2lox_t("OVERVIEW.MS_UNREACHABLE")); ?>
					<?php else: ?>
						&ndash;
					<?php endif; ?>
				</p>
			</div>
		</div>

		<?php if ($recharge_needed): ?>
			<div class="kia2lox-info-banner" id="kia2lox-banner-balance">
				<div class="kia2lox-info-banner-body">
					<p class="kia2lox-info-banner-title"><?php echo htmlspecialchars(kia2lox_t("OVERVIEW.BALANCE_TITLE")); ?></p>
					<p class="kia2lox-info-banner-text"><?php echo htmlspecialchars(kia2lox_t("OVERVIEW.BALANCE_TEXT")); ?></p>
					<div class="kia2lox-info-banner-actions">
						<button class="kia2lox-btn-banner-ok" data-banner="balance" type="button"><?php echo htmlspecialchars(kia2lox_t("OVERVIEW.BANNER_OK")); ?></button>
						<button class="kia2lox-btn-banner-mute" data-banner="balance" type="button"><?php echo htmlspecialchars(kia2lox_t("OVERVIEW.BANNER_MUTE")); ?></button>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<?php if ($full_parked): ?>
			<div class="kia2lox-info-banner" id="kia2lox-banner-full">
				<div class="kia2lox-info-banner-body">
					<p class="kia2lox-info-banner-title"><?php echo htmlspecialchars(kia2lox_t("OVERVIEW.FULL_TITLE")); ?></p>
					<p class="kia2lox-info-banner-text"><?php echo htmlspecialchars(kia2lox_t("OVERVIEW.FULL_TEXT")); ?></p>
					<div class="kia2lox-info-banner-actions">
						<button class="kia2lox-btn-banner-ok" data-banner="full" type="button"><?php echo htmlspecialchars(kia2lox_t("OVERVIEW.BANNER_OK")); ?></button>
						<button class="kia2lox-btn-banner-mute" data-banner="full" type="button"><?php echo htmlspecialchars(kia2lox_t("OVERVIEW.BANNER_MUTE")); ?></button>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<?php if ($low_battery): ?>
			<div class="kia2lox-info-banner" id="kia2lox-banner-lowbattery">
				<div class="kia2lox-info-banner-body">
					<p class="kia2lox-info-banner-title"><?php echo htmlspecialchars(kia2lox_t("OVERVIEW.LOWBATTERY_TITLE")); ?></p>
					<p class="kia2lox-info-banner-text"><?php echo htmlspecialchars(kia2lox_t("OVERVIEW.LOWBATTERY_TEXT")); ?></p>
					<div class="kia2lox-info-banner-actions">
						<button class="kia2lox-btn-banner-ok" data-banner="lowbattery" type="button"><?php echo htmlspecialchars(kia2lox_t("OVERVIEW.BANNER_OK")); ?></button>
						<button class="kia2lox-btn-banner-mute" data-banner="lowbattery" type="button"><?php echo htmlspecialchars(kia2lox_t("OVERVIEW.BANNER_MUTE")); ?></button>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<section class="kia2lox-card" id="kia2lox-chart-card">
			<h2><?php echo htmlspecialchars(kia2lox_t("OVERVIEW.CHART_TITLE")); ?></h2>
			<?php if (empty($history)): ?>
				<p class="kia2lox-hint"><?php echo htmlspecialchars(kia2lox_t("OVERVIEW.CHART_EMPTY_HINT")); ?></p>
			<?php else: ?>
				<div class="kia2lox-chart-controls">
					<div class="kia2lox-chart-range-group" role="group" aria-label="<?php echo htmlspecialchars(kia2lox_t("OVERVIEW.RANGE_ARIA")); ?>">
						<button class="kia2lox-chart-range-btn" data-range="24h" type="button"><?php echo htmlspecialchars(kia2lox_t("OVERVIEW.RANGE_24H")); ?></button>
						<button class="kia2lox-chart-range-btn active" data-range="7d" type="button"><?php echo htmlspecialchars(kia2lox_t("OVERVIEW.RANGE_7D")); ?></button>
						<button class="kia2lox-chart-range-btn" data-range="30d" type="button"><?php echo htmlspecialchars(kia2lox_t("OVERVIEW.RANGE_30D")); ?></button>
					</div>
					<span class="kia2lox-chart-range-label" id="kia2lox-chart-range-label"></span>
				</div>
				<div class="kia2lox-chart-wrap">
					<svg viewBox="0 0 640 220" id="kia2lox-soc-chart-svg" role="img" aria-label="<?php echo htmlspecialchars(kia2lox_t("OVERVIEW.CHART_ARIA_LABEL")); ?>">
						<line x1="36" y1="16" x2="628" y2="16" class="kia2lox-chart-grid"/>
						<line x1="36" y1="60" x2="628" y2="60" class="kia2lox-chart-grid"/>
						<line x1="36" y1="104" x2="628" y2="104" class="kia2lox-chart-grid"/>
						<line x1="36" y1="148" x2="628" y2="148" class="kia2lox-chart-grid"/>
						<line x1="36" y1="192" x2="628" y2="192" class="kia2lox-chart-grid kia2lox-chart-grid-base"/>

						<text x="30" y="20" class="kia2lox-chart-axis-label" text-anchor="end">100%</text>
						<text x="30" y="64" class="kia2lox-chart-axis-label" text-anchor="end">75%</text>
						<text x="30" y="108" class="kia2lox-chart-axis-label" text-anchor="end">50%</text>
						<text x="30" y="152" class="kia2lox-chart-axis-label" text-anchor="end">25%</text>
						<text x="30" y="196" class="kia2lox-chart-axis-label" text-anchor="end">0%</text>

						<g id="kia2lox-chart-dynamic"></g>
					</svg>
					<div class="kia2lox-chart-tooltip" id="kia2lox-chart-tooltip" hidden></div>
				</div>
			<?php endif; ?>
		</section>

	<?php endif; ?>

</div>
</div>
<script>
	var KIA2LOX_HISTORY = <?php echo json_encode($history); ?>;
	var KIA2LOX_L = <?php echo json_encode([
		"records_one" => kia2lox_t("OVERVIEW.RECORDS_ONE"),
		"records_other" => kia2lox_t("OVERVIEW.RECORDS_OTHER"),
		"chart_no_data" => kia2lox_t("OVERVIEW.CHART_NO_DATA"),
	]); ?>;
</script>
<script>
	// Fahrzeug-Box: Hintergrund faerbt sich stufenlos von Rot (<=20%) ueber
	// eine Mischfarbe bis Hellgruen (>=80%) - abhaengig vom aktiven Theme
	// (liest die echten CSS-Token-Werte aus, damit Hell-/Dunkelmodus passt).
	(function () {
		var card = document.getElementById("kia2lox-vehicle-card");
		if (!card || card.dataset.soc === "") { return; }
		var pct = parseInt(card.dataset.soc, 10);
		if (isNaN(pct)) { return; }

		function hexToRgb(hex) {
			hex = hex.trim().replace('#', '');
			return [
				parseInt(hex.substring(0, 2), 16),
				parseInt(hex.substring(2, 4), 16),
				parseInt(hex.substring(4, 6), 16)
			];
		}
		function mixRgb(a, b, t) {
			return a.map(function (c, i) { return Math.round(c + (b[i] - c) * t); });
		}
		var t = Math.max(0, Math.min(1, (pct - 20) / (80 - 20)));
		var styles = getComputedStyle(document.documentElement);
		var low = hexToRgb(styles.getPropertyValue('--danger-bg'));
		var high = hexToRgb(styles.getPropertyValue('--success-bg'));
		var lowBorder = hexToRgb(styles.getPropertyValue('--danger-border'));
		var highBorder = hexToRgb(styles.getPropertyValue('--success-bg-strong'));
		card.style.background = 'rgb(' + mixRgb(low, high, t).join(',') + ')';
		card.style.borderColor = 'rgb(' + mixRgb(lowBorder, highBorder, t).join(',') + ')';
	})();

	// Boxen-Auswahl (Zahnrad neben der Versionsnummer): welche
	// Uebersichts-Boxen angezeigt werden, wird pro Browser gespeichert.
	(function () {
		var gearBtn = document.getElementById("kia2lox-btn-toggle-boxes");
		var popover = document.getElementById("kia2lox-boxes-popover");
		if (!gearBtn || !popover) { return; }

		var BOX_DEFS = [
			{ key: "vehicle", label: <?php echo json_encode(kia2lox_t("OVERVIEW.LABEL_VEHICLE")); ?>, el: "kia2lox-vehicle-card" },
			{ key: "plug", label: <?php echo json_encode(kia2lox_t("OVERVIEW.LABEL_PLUG_STATUS")); ?>, el: "kia2lox-plug-card" },
			{ key: "lastpoll", label: <?php echo json_encode(kia2lox_t("OVERVIEW.LABEL_LAST_POLL")); ?>, el: "kia2lox-lastpoll-card" },
			{ key: "nextpoll", label: <?php echo json_encode(kia2lox_t("OVERVIEW.LABEL_NEXT_POLL")); ?>, el: "kia2lox-nextpoll-card" },
			{ key: "miniserver", label: <?php echo json_encode(kia2lox_t("OVERVIEW.LABEL_MINISERVER")); ?>, el: "kia2lox-ms-status-card" },
			{ key: "chart", label: <?php echo json_encode(kia2lox_t("OVERVIEW.CHART_BOX_LABEL")); ?>, el: "kia2lox-chart-card" }
		];
		var STORAGE_KEY = "kia2lox_visible_boxes_" + <?php echo json_encode($active_id); ?>;

		function loadBoxPrefs() {
			try {
				var raw = localStorage.getItem(STORAGE_KEY);
				return raw ? JSON.parse(raw) : {};
			} catch (e) { return {}; }
		}
		function saveBoxPrefs(prefs) {
			try { localStorage.setItem(STORAGE_KEY, JSON.stringify(prefs)); } catch (e) {}
		}
		function applyBoxVisibility(prefs) {
			BOX_DEFS.forEach(function (def) {
				var el = document.getElementById(def.el);
				if (el) { el.hidden = prefs[def.key] === false; }
			});
		}

		var boxPrefs = loadBoxPrefs();
		var list = document.getElementById("kia2lox-boxes-popover-list");
		BOX_DEFS.forEach(function (def) {
			var label = document.createElement("label");
			var cb = document.createElement("input");
			cb.type = "checkbox";
			cb.setAttribute("data-role", "none");
			cb.checked = boxPrefs[def.key] !== false;
			cb.addEventListener("change", function () {
				boxPrefs[def.key] = cb.checked;
				saveBoxPrefs(boxPrefs);
				applyBoxVisibility(boxPrefs);
			});
			label.appendChild(cb);
			label.appendChild(document.createTextNode(def.label));
			list.appendChild(label);
		});
		applyBoxVisibility(boxPrefs);

		function closePopover() {
			popover.hidden = true;
			gearBtn.setAttribute("aria-expanded", "false");
		}
		function openPopover() {
			var rect = gearBtn.getBoundingClientRect();
			popover.style.top = (rect.bottom + 8) + "px";
			popover.style.left = (rect.right - 250) + "px";
			popover.hidden = false;
			gearBtn.setAttribute("aria-expanded", "true");
		}
		gearBtn.addEventListener("click", function (evt) {
			evt.stopPropagation();
			if (popover.hidden) { openPopover(); } else { closePopover(); }
		});
		popover.addEventListener("click", function (evt) { evt.stopPropagation(); });
		document.addEventListener("click", function () {
			if (!popover.hidden) { closePopover(); }
		});
		document.addEventListener("keydown", function (evt) {
			if (evt.key === "Escape" && !popover.hidden) { closePopover(); }
		});
		window.addEventListener("scroll", function () {
			if (!popover.hidden) { closePopover(); }
		}, true);
	})();

	// Warn-Banner: "Nicht mehr anzeigen" merkt sich das pro Fahrzeug und
	// Banner-Art im Browser (localStorage), "Verstanden" blendet nur bis
	// zum naechsten Laden der Seite aus.
	(function () {
		var vehicleId = <?php echo json_encode($active_id); ?>;
		function storageKey(banner) { return "kia2lox_muted_" + vehicleId + "_" + banner; }
		document.querySelectorAll("[data-banner]").forEach(function (btn) {
			var banner = btn.dataset.banner;
			try {
				if (localStorage.getItem(storageKey(banner)) === "1") {
					var el = document.getElementById("kia2lox-banner-" + banner);
					if (el) { el.hidden = true; }
				}
			} catch (e) {}
		});
		document.querySelectorAll(".kia2lox-btn-banner-ok").forEach(function (btn) {
			btn.addEventListener("click", function () {
				var el = document.getElementById("kia2lox-banner-" + btn.dataset.banner);
				if (el) { el.hidden = true; }
			});
		});
		document.querySelectorAll(".kia2lox-btn-banner-mute").forEach(function (btn) {
			btn.addEventListener("click", function () {
				var el = document.getElementById("kia2lox-banner-" + btn.dataset.banner);
				if (el) { el.hidden = true; }
				try { localStorage.setItem(storageKey(btn.dataset.banner), "1"); } catch (e) {}
			});
		});
	})();

	// Ladezustands-Diagramm: echte Verlaufsdaten (KIA2LOX_HISTORY), lokal
	// nach gewaehltem Zeitraum gefiltert und als SVG gezeichnet.
	(function () {
		var svg = document.getElementById("kia2lox-soc-chart-svg");
		if (!svg || typeof KIA2LOX_HISTORY === "undefined") { return; }

		var dynamic = document.getElementById("kia2lox-chart-dynamic");
		var tooltip = document.getElementById("kia2lox-chart-tooltip");
		var rangeLabel = document.getElementById("kia2lox-chart-range-label");
		var rangeButtons = Array.prototype.slice.call(document.querySelectorAll(".kia2lox-chart-range-btn"));

		var RANGE_HOURS = { "24h": 24, "7d": 24 * 7, "30d": 24 * 30 };
		var currentRange = "7d";

		var padL = 36, padR = 12, padT = 16, padB = 28;
		var W = 640, H = 220;
		var innerW = W - padL - padR, innerH = H - padT - padB;

		var CHART_LOCALE = <?php echo json_encode(kia2lox_t("OVERVIEW.CHART_LOCALE")); ?>;
		var WEEKDAYS = <?php echo json_encode(explode(",", kia2lox_t("OVERVIEW.WEEKDAYS"))); ?>;
		function fmtDate(d) {
			return d.toLocaleDateString(CHART_LOCALE, { day: '2-digit', month: '2-digit', year: 'numeric' });
		}
		function fmtTime(d) {
			return d.toLocaleTimeString(CHART_LOCALE, { hour: '2-digit', minute: '2-digit' });
		}

		function svgEl(tag, attrs) {
			var el = document.createElementNS("http://www.w3.org/2000/svg", tag);
			for (var k in attrs) { el.setAttribute(k, attrs[k]); }
			return el;
		}

		function render() {
			var hours = RANGE_HOURS[currentRange];
			var cutoff = Date.now() - hours * 60 * 60 * 1000;
			var points = KIA2LOX_HISTORY
				.map(function (e) { return { date: new Date(e.at), soc: e.soc, charging: e.charging, plugged: e.plugged }; })
				.filter(function (p) { return !isNaN(p.date.getTime()) && p.date.getTime() >= cutoff && p.soc !== null; })
				.sort(function (a, b) { return a.date - b.date; });

			dynamic.innerHTML = "";
			if (points.length === 0) {
				rangeLabel.textContent = "";
			} else {
				var fromDate = points[0].date, toDate = points[points.length - 1].date;
				var rangeText = fmtDate(fromDate) === fmtDate(toDate) ? fmtDate(fromDate) : (fmtDate(fromDate) + " – " + fmtDate(toDate));
				var countText = points.length === 1 ? KIA2LOX_L.records_one : KIA2LOX_L.records_other.replace("{n}", points.length);
				rangeLabel.textContent = rangeText + " (" + countText + ")";
			}

			if (points.length === 0) {
				var msg = svgEl("text", { x: W / 2, y: H / 2, "text-anchor": "middle", class: "kia2lox-chart-axis-label" });
				msg.textContent = KIA2LOX_L.chart_no_data;
				dynamic.appendChild(msg);
				return;
			}

			var minTime = points[0].date.getTime();
			var maxTime = points[points.length - 1].date.getTime();
			var span = Math.max(maxTime - minTime, 1);

			function xAt(p) {
				return points.length === 1 ? padL + innerW / 2 : padL + ((p.date.getTime() - minTime) / span) * innerW;
			}
			function yAt(soc) { return padT + ((100 - soc) / 100) * innerH; }

			points.forEach(function (p) { p.x = xAt(p); p.y = yAt(p.soc); });

			var linePath = "M " + points.map(function (p) { return p.x.toFixed(1) + " " + p.y.toFixed(1); }).join(" L ");
			var baseline = padT + innerH;
			var areaPath = linePath + " L " + points[points.length - 1].x.toFixed(1) + " " + baseline
				+ " L " + points[0].x.toFixed(1) + " " + baseline + " Z";

			dynamic.appendChild(svgEl("path", { d: areaPath, class: "kia2lox-chart-area" }));
			dynamic.appendChild(svgEl("path", { d: linePath, class: "kia2lox-chart-line" }));

			// Datenpunkte auf der Linie: Farbe zeigt Stecker-/Ladestatus zu
			// diesem Zeitpunkt (schwarz = nicht eingesteckt, blau =
			// eingesteckt, gruen = eingesteckt und laedt gerade).
			points.forEach(function (p) {
				var pointClass = "kia2lox-chart-point-unplugged";
				if (p.plugged && p.charging) {
					pointClass = "kia2lox-chart-point-charging";
				} else if (p.plugged) {
					pointClass = "kia2lox-chart-point-plugged";
				}
				dynamic.appendChild(svgEl("circle", { cx: p.x, cy: p.y, r: 3, class: "kia2lox-chart-point " + pointClass }));
			});

			// X-Achsen-Beschriftung: bis zu 6 gleichmaessig verteilte Marken.
			var tickCount = Math.min(6, points.length);
			for (var i = 0; i < tickCount; i++) {
				var idx = tickCount === 1 ? 0 : Math.round(i * (points.length - 1) / (tickCount - 1));
				var p = points[idx];
				var label = svgEl("text", {
					x: p.x, y: H - 6, "text-anchor": "middle", class: "kia2lox-chart-axis-label"
				});
				label.textContent = currentRange === "24h"
					? p.date.toLocaleTimeString(CHART_LOCALE, { hour: '2-digit', minute: '2-digit' })
					: p.date.toLocaleDateString(CHART_LOCALE, { day: '2-digit', month: '2-digit' });
				dynamic.appendChild(label);
			}

			// Hover: durchsichtiges Hit-Rechteck, das per Mauszeiger den
			// naechstgelegenen Punkt findet und Linie/Punkt/Tooltip anzeigt.
			var hoverLine = svgEl("line", { x1: 0, y1: padT, x2: 0, y2: baseline, class: "kia2lox-chart-hover-line" });
			var hoverPoint = svgEl("circle", { r: 4, class: "kia2lox-chart-hover-point" });
			hoverLine.style.display = "none";
			hoverPoint.style.display = "none";
			dynamic.appendChild(hoverLine);
			dynamic.appendChild(hoverPoint);

			var hit = svgEl("rect", { x: padL, y: padT, width: innerW, height: innerH, class: "kia2lox-chart-hit" });
			hit.addEventListener("mousemove", function (evt) {
				var rect = svg.getBoundingClientRect();
				var scale = W / rect.width;
				var mouseX = (evt.clientX - rect.left) * scale;
				var nearest = points[0], nearestDist = Infinity;
				points.forEach(function (p) {
					var dist = Math.abs(p.x - mouseX);
					if (dist < nearestDist) { nearest = p; nearestDist = dist; }
				});
				hoverLine.setAttribute("x1", nearest.x);
				hoverLine.setAttribute("x2", nearest.x);
				hoverLine.style.display = "";
				hoverPoint.setAttribute("cx", nearest.x);
				hoverPoint.setAttribute("cy", nearest.y);
				hoverPoint.style.display = "";

				tooltip.innerHTML = "<strong>" + WEEKDAYS[nearest.date.getDay()] + ", " + fmtDate(nearest.date) + "</strong><br>"
					+ fmtTime(nearest.date) + ": <span class=\"kia2lox-tt-value\">" + nearest.soc + "%</span>";
				tooltip.hidden = false;
				var wrapRect = svg.parentElement.getBoundingClientRect();
				var tooltipX = (nearest.x / W) * wrapRect.width;
				tooltip.style.left = Math.min(tooltipX + 10, wrapRect.width - 150) + "px";
				tooltip.style.top = (nearest.y / H) * wrapRect.height - 10 + "px";
			});
			hit.addEventListener("mouseleave", function () {
				hoverLine.style.display = "none";
				hoverPoint.style.display = "none";
				tooltip.hidden = true;
			});
			dynamic.appendChild(hit);
		}

		rangeButtons.forEach(function (btn) {
			btn.addEventListener("click", function () {
				rangeButtons.forEach(function (b) { b.classList.remove("active"); });
				btn.classList.add("active");
				currentRange = btn.dataset.range;
				render();
			});
		});

		render();
	})();
</script>
<?php require "inc_footer.php"; ?>
