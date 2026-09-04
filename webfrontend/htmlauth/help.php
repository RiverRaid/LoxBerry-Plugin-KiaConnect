<?php

# Kia2Lox - Hilfe-Seite. Einfacher, statischer Text zur Bedienung des
# Plugins (keine interaktiven Elemente noetig).

require_once "loxberry_system.php";
require_once "loxberry_web.php";
require_once "inc_vehicles.php";

$version = LBSystem::pluginversion();
$vehicles = kia2lox_load_vehicles();
if (empty($vehicles)) {
	$vehicles = [kia2lox_default_vehicle("Fahrzeug 1", "v1")];
	kia2lox_save_vehicles($vehicles);
}
$active_id = $_GET["vehicle"] ?? $vehicles[0]["id"];

LBWeb::lbheader("Kia2Lox V$version", "https://github.com/RiverRaid/LoxBerry-Plugin-KiaConnect", "help.html");
$kia2lox_active_tab = "help";
require "inc_header.php";
?>

	<div class="kia2lox-card">
		<h2>&Uuml;ber Kia2Lox</h2>
		<p class="kia2lox-desc">
			Kia2Lox fragt regelm&auml;&szlig;ig den Ladezustand eines oder mehrerer Kia-e-Fahrzeuge &uuml;ber die
			(inoffizielle) Kia-Connect-Schnittstelle ab und schickt die Werte per UDP an einen Loxone Miniserver.
		</p>
		<p class="kia2lox-desc">
			Die Verbindung zu Kia Connect selbst &uuml;bernimmt die freie Bibliothek
			<a href="https://github.com/Hyundai-Kia-Connect/hyundai_kia_connect_api" target="_blank" rel="noopener"><code>hyundai_kia_connect_api</code></a>
			von Fuat Akgun (MIT-Lizenz) - ohne dieses Projekt g&auml;be es Kia2Lox nicht, vielen Dank daf&uuml;r!
		</p>
		<div class="kia2lox-about-meta">
			<span class="kia2lox-version-chip">Version <?php echo htmlspecialchars($version); ?></span>
			<a class="kia2lox-help-link" href="https://github.com/RiverRaid/LoxBerry-Plugin-KiaConnect" target="_blank" rel="noopener">Kia2Lox auf GitHub &rarr;</a>
		</div>
	</div>

	<div class="kia2lox-card">
		<h2>Erste Schritte</h2>
		<p class="kia2lox-desc">Pro Fahrzeug in den <strong>Einstellungen</strong>:</p>
		<ol class="kia2lox-help-list">
			<li><strong>Kia Connect Zugangsdaten</strong>: dieselben Zugangsdaten wie in der Kia-Connect-App eingeben und speichern. Beim Speichern wird der Login sofort getestet - erst nach einem erfolgreichen Test ist das Fahrzeug "verbunden" und alle weiteren Karten werden freigeschaltet.</li>
			<li><strong>Loxone Miniserver</strong>: Ziel-Miniserver und UDP-Port ausw&auml;hlen, an den die Werte gesendet werden sollen.</li>
			<li><strong>Abfrage-Intervall</strong>: festlegen, wie oft passiv abgefragt wird und ob zus&auml;tzlich ein Force-Refresh (weckt das Fahrzeug aktiv) stattfinden soll.</li>
			<li><strong>Loxone Vorlagen</strong> herunterladen und in Loxone Config importieren - Adresse, Port und Schl&uuml;ssel sind bereits eingetragen.</li>
		</ol>
		<p class="kia2lox-desc">Bis zu <?php echo KIA2LOX_MAX_VEHICLES; ?> Fahrzeuge k&ouml;nnen &uuml;ber "+ Fahrzeug" oben angelegt werden, jedes mit eigenen Zugangsdaten, Miniserver-Ziel und Intervall.</p>
	</div>

	<div class="kia2lox-card">
		<h2>Passive Abfrage vs. Force-Refresh</h2>
		<p class="kia2lox-desc">Kia2Lox unterscheidet zwei Arten der Abfrage, die unabh&auml;ngig voneinander eingestellt werden:</p>
		<ul class="kia2lox-help-list">
			<li><strong>Passive Abfrage</strong>: liest nur den zuletzt von Kia Connect gemeldeten (gecachten) Stand. Weckt das Fahrzeug nicht auf, belastet die 12V-Batterie nicht zus&auml;tzlich - daher f&uuml;r h&auml;ufige Abfragen geeignet.</li>
			<li><strong>Force-Refresh</strong>: fordert einen frischen Stand direkt vom Fahrzeug an. Weckt das Fahrzeug aktiv auf und verbraucht dabei etwas 12V-Batterie - daher nur 0-4&times; t&auml;glich zu festen Uhrzeiten vorgesehen (oder manuell &uuml;ber den "Jetzt aktualisieren"-Button bzw. den HTTP-Trigger).</li>
		</ul>
	</div>

	<div class="kia2lox-card">
		<h2>Gesendete UDP-Werte</h2>
		<p class="kia2lox-desc">Bei jeder Abfrage werden folgende Werte als einzelne UDP-Telegramme im Format <code>SCHL&Uuml;SSEL=WERT</code> gesendet:</p>
		<div class="kia2lox-help-table-wrap">
			<table class="kia2lox-help-table">
				<thead>
					<tr><th>Schl&uuml;ssel</th><th>Bedeutung</th></tr>
				</thead>
				<tbody>
					<tr><td><code>SOC</code></td><td>Ladezustand in Prozent (0-100)</td></tr>
					<tr><td><code>RANGE</code></td><td>Elektrische Reichweite in km</td></tr>
					<tr><td><code>CHARGING</code></td><td>1 = l&auml;dt gerade, 0 = l&auml;dt nicht</td></tr>
					<tr><td><code>PLUGGED</code></td><td>Steckerstatus (0 = nicht eingesteckt, 2 = eingesteckt, ggf. weitere Werte je nach Fahrzeug)</td></tr>
					<tr><td><code>FULL</code></td><td>1 = Akku ist seit mind. 3 Stunden ununterbrochen &ge;99&nbsp;%</td></tr>
					<tr><td><code>FULLPARKED</code></td><td>1 = zus&auml;tzlich seit mind. 3 Stunden eingesteckt und nicht mehr ladend</td></tr>
					<tr><td><code>RECHARGE100</code></td><td>1 = seit 30 Tagen keinen vollen Ladestand mehr erreicht (Zellausgleich empfohlen)</td></tr>
					<tr><td><code>LOWBATTERY</code></td><td>1 = Akku ist seit mind. 3 Stunden ununterbrochen unter 10&nbsp;% und l&auml;dt nicht</td></tr>
				</tbody>
			</table>
		</div>
		<p class="kia2lox-desc">Alle Werte werden bei jeder Abfrage gesendet, unabh&auml;ngig davon, ob sie sich ge&auml;ndert haben - das dient gleichzeitig als "Lebenszeichen", dass die Abfrage noch l&auml;uft.</p>
	</div>

	<div class="kia2lox-card">
		<h2>HTTP-Befehle &amp; Loxone-Vorlagen</h2>
		<p class="kia2lox-desc">
			Auf der Einstellungsseite finden sich zwei Adressen (Passive Abfrage / Force-Refresh), die als HTTP-Befehl in einem virtuellen
			Ausgang in Loxone Config hinterlegt werden k&ouml;nnen, um eine Abfrage direkt aus Loxone auszul&ouml;sen - z.&nbsp;B. per Zeitschaltung
			oder Taster. Jedes Fahrzeug hat dabei einen eigenen Sicherheits-Schl&uuml;ssel; kein LoxBerry-Login n&ouml;tig, der Schl&uuml;ssel
			sollte aber nicht weitergegeben werden.
		</p>
		<p class="kia2lox-desc">
			Die "Loxone Vorlagen" auf derselben Seite sind fertig ausgef&uuml;llte Import-Dateien f&uuml;r Loxone Config - ein virtueller
			UDP-Eingang (empf&auml;ngt die Werte oben) und ein virtueller Ausgang (l&ouml;st die HTTP-Befehle aus), jeweils bereits mit
			Adresse, Port und Schl&uuml;ssel des jeweiligen Fahrzeugs bef&uuml;llt.
		</p>
	</div>

	<div class="kia2lox-card">
		<h2>&Uuml;bersicht &amp; Log</h2>
		<p class="kia2lox-desc">
			Die <strong>&Uuml;bersicht</strong> zeigt den aktuellen Stand des ausgew&auml;hlten Fahrzeugs (Ladezustand, Steckerstatus,
			letzte/n&auml;chste Abfrage, Miniserver-Erreichbarkeit) sowie ein Diagramm des Ladeverlaufs. &Uuml;ber das Zahnrad-Symbol
			oben rechts k&ouml;nnen einzelne Boxen ein-/ausgeblendet werden.
		</p>
		<p class="kia2lox-desc">
			Das <strong>Log</strong> zeigt das Protokoll aller Abfrage-Versuche (alle Fahrzeuge zusammen) und kann bei Bedarf geleert werden.
		</p>
	</div>

</div>
</div>
<?php
require "inc_footer.php";
