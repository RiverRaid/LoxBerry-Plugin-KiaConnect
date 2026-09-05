# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Kia2Lox is a LoxBerry plugin (not a standalone app) that polls Kia Connect for
EV battery status (SoC, range, charging/plug state, battery-health warnings)
via the [hyundai_kia_connect_api](https://github.com/Hyundai-Kia-Connect/hyundai_kia_connect_api)
library, and sends the values via UDP to a Loxone Miniserver. It ships a full
bilingual (German/English) web UI with four tabs: Übersicht/Overview,
Einstellungen/Settings, Log, Hilfe/Help. Supports up to 4 vehicles, each with
its own Kia Connect account, Miniserver target, and poll schedule.

The project follows the official
[LoxBerry plugin structure](https://wiki.loxberry.de/entwickler/grundlagen_zur_erstellung_eines_plugins) —
fixed directory names/roles (`bin/`, `webfrontend/html/`,
`webfrontend/htmlauth/`, `templates/`, `cron/`, install-lifecycle scripts at
the repo root) are dictated by that framework, not by this project.

## Commands

There is no build step and no automated test suite. Validate changes like this:

- **Python syntax check**: `python -m py_compile bin/kia2lox_poll.py bin/kia2lox_test_login.py 01_test/test_battery.py icons/generate_icons.py`
- **PHP syntax check**: `php -l <file>` (not available in all dev environments — do a careful manual read if `php` isn't installed locally)
- **INI key parity check** (DE/EN language files and PHP `kia2lox_t()` calls must stay in sync): write a small ad-hoc Python script that parses `[SECTION]`/`KEY=` pairs from `templates/lang/*.ini` and diffs against `kia2lox_t("SECTION.KEY", ...)` calls found via grep across `webfrontend/**/*.php` — there's no checked-in script for this, it was done ad hoc during a cleanup audit.
- **Manual standalone test**: `01_test/test_battery.py` queries a single real Kia Connect account and prints/sends results — useful for testing credentials or the API library outside the plugin (copy `01_test/config.example.json` to `01_test/config.json` first, which is gitignored).

## Deploy-then-push workflow (important — always follow this)

This project is developed against a **live LoxBerry test device**, not by
running the plugin locally (it can't run standalone — it depends on the
LoxBerry framework's PHP includes, `$lbpconfigdir`/`$lbpdatadir`/etc. globals,
and system services).

The standing workflow, established by the user and re-confirmed repeatedly:

1. Implement a change.
2. Deploy it to the live LoxBerry via SCP (SSH key path and host are in the
   user's local memory/notes — ask if not available in context).
3. Verify remotely: syntax-check (`php -l` / `py_compile` over SSH), and
   where possible a real functional check (e.g. curl an HTTP trigger
   endpoint, or ask the user to reload the page and confirm visually since
   htmlauth pages require a LoxBerry login you likely don't have credentials
   for).
4. Report back that it's ready for the user to test — this is **not** a cue
   to commit or push.
5. Only `git commit` / `git push` once the user explicitly says to (e.g.
   "bitte committen und pushen", "kann released werden", "veröffentlichen").

**Releases**: Real fixes/features are shipped as an actual GitHub release
(version bump in `plugin.cfg`, git tag, `gh release create`), because the
user updates via LoxBerry's own Plugin Manager "Update" button, which reads
`release.cfg`/`prerelease.cfg` (`VERSION` + `ARCHIVEURL` pointing at a GitHub
tag archive) via the `AUTOUPDATE` section in `plugin.cfg`. A pure internal
cleanup with no functional change (e.g. removing dead CSS) does not need a
version bump — a plain commit is enough. When a version *is* bumped, update
in lockstep:
- `plugin.cfg` (`VERSION=`)
- `release.cfg` and/or `prerelease.cfg` (`VERSION=` and `ARCHIVEURL=`)
- `README.md`'s install ZIP URL (must point at the new tag)
- Release notes on GitHub must be written in **English**, even though the
  UI/commits/comments are German-first.

## Architecture

### Two Python entry points, one shared library dependency

- `bin/kia2lox_poll.py` — the real polling engine. Invoked either by cron
  (`cron/cron.05min`, every 5 minutes, one process for *all* configured
  vehicles) or by PHP with `--vehicle <id> [--force]` for a manual/HTTP-
  triggered single-vehicle refresh. Each vehicle decides independently
  whether/how it gets polled this cycle via `should_poll_passive_now()` /
  `should_force_refresh_now()`, based on its own settings in
  `pluginconfig.json`. Writes results to `state.json` (current status per
  vehicle) and appends to `history_<id>.jsonl` (SoC history for the chart,
  pruned to `HISTORY_RETENTION_DAYS`). Computes 4 battery-health flags
  (`FULL`, `FULLPARKED`, `RECHARGE100`, `LOWBATTERY`) from per-vehicle
  configurable thresholds, with hardcoded `DEFAULT_*` fallbacks for
  vehicles that predate that setting. Each flag is additionally suppressed
  (`update_battery_health_state()`) while `kia_last_updated_at` (the
  timestamp Kia Connect itself last updated the vehicle's data, as opposed
  to when we last polled) is older than that flag's own configured
  duration — a passive poll only reads Kia's cached state, which can be
  stale for longer than the warning threshold itself. When that happens
  and the vehicle's `stale_auto_refresh_enabled` setting (default on) is
  set, `main()` triggers a one-time out-of-schedule Force-Refresh via
  `stale_warning_needs_refresh()`, independent of the vehicle's own
  passive/force schedule; `vstate["stale_warning_pending"]` tracks this so
  the refresh fires only once per stale episode, resetting once fresh data
  arrives or the underlying condition clears. A 5th UDP output, `ERROR`,
  is sent after every poll attempt (from `main()`, not
  `update_battery_health_state()`, since it must also fire when
  `poll_vehicle_config()` never got far enough to compute the other four
  flags) — `1` on any failed attempt, reset to `0` on the next success;
  `overview.php` shows a matching red banner (no dismiss button, unlike
  the battery-health banners) driven by the same `vstate["last_poll_ok"]`
  flag. Logs using LoxBerry's own log-level
  convention (`<OK>`/`<INFO>`/`<WARNING>`/`<ERROR>`/`<CRITICAL>` tags,
  mapped onto LoxBerry's actual 5 selectable levels — Off/Errors/Warning/Info/Debug,
  values 0/3/4/6/7, not a contiguous 0-7 range — via `TAG_LOG_LEVELS`,
  filtered by the level set in LoxBerry's plugin management) — but an
  explicit `--vehicle` run always logs everything regardless of level, so
  the PHP caller can reliably detect failure via the `<ERROR>`/`<CRITICAL>`
  tag in the captured output.
- `bin/kia2lox_test_login.py` — minimal, separate script used only to test
  Kia Connect credentials without persisting them. Reads credentials as
  JSON from stdin (never as CLI args, so they don't leak into the process
  list), returns a one-line JSON result. Called from PHP via `proc_open()`.
- Both scripts run inside a **plugin-private Python 3.12 venv**
  (`$LBPDATA/kia2lox/venv`), built by `postinstall.sh` from a portable
  python-build-standalone release — the LoxBerry system Python is often too
  old (3.9) for `hyundai_kia_connect_api`, and is deliberately left
  untouched.
- `01_test/test_battery.py` is a standalone duplicate of the single-vehicle
  poll logic for testing outside the plugin entirely (own venv/deps, not
  installed on LoxBerry).

### PHP web UI: shared header/footer, one config file, one state file

- `webfrontend/htmlauth/inc_vehicles.php` is the shared library for all
  authenticated pages: loading/saving `pluginconfig.json` (the single
  source of truth for all per-vehicle settings — includes an in-place
  migration block in `kia2lox_load_vehicles()` that backfills missing keys
  for vehicles configured under older plugin versions), reading
  `state.json`/`history_<id>.jsonl`, running the Python scripts via
  `proc_open()`, computing the next scheduled poll time
  (`kia2lox_next_passive_time()`), and the `kia2lox_t()` translation helper.
- `inc_header.php` / `inc_footer.php` are `require`d (not included as
  functions) by every authenticated page (`settings.php`, `overview.php`,
  `log.php`, `help.php`) and expect specific variables to already be set by
  the caller (`$version`, `$vehicles`, `$active_id`, `$kia2lox_active_tab`).
  They render the shared hero/tabs/vehicle-picker chrome; the calling page
  must close the `</div></div>` wrapper opened in the header before
  `require`ing the footer.
- `index.php` is just a redirect to `overview.php` (preserving `?vehicle=`)
  — LoxBerry's plugin menu always links to `index.php` by framework
  convention, so this is what makes the plugin land on the Overview tab
  instead of Settings when opened from the menu.
- `settings.php` (the Settings page, formerly `index.php`) handles all POST
  actions (`add_vehicle`, `remove_vehicle`, `save_credentials`,
  `save_miniserver`, `save_interval`, `save_warnings`, `manual_refresh`) and
  answers AJAX saves with JSON via `kia2lox_json_response()` — note the
  `ob_start()` output buffering wrapping the whole POST handling block,
  needed so a stray PHP notice/warning can't corrupt the JSON response.
- `webfrontend/html/poll.php` and `refresh.php` are **unauthenticated**
  public HTTP endpoints (separate from `htmlauth/`) meant to be called by a
  Loxone virtual output — protected instead by a per-vehicle random
  `http_key` checked with `hash_equals()`. `poll.php` triggers a passive
  refresh, `refresh.php` a force-refresh.
- `template_input.php` / `template_output.php` generate downloadable Loxone
  Config XML import templates (virtual UDP input / virtual output),
  pre-filled per vehicle with port, address, and the security key.

### Bilingual UI: three independent `.ini` file pairs

Every `_de.ini` has a matching `_en.ini` under `templates/lang/`, all loaded
via `LBSystem::readlanguage()` (LoxBerry falls back to English for missing
keys automatically) and looked up through `kia2lox_t("SECTION.KEY", [...])`:

- `kia2lox_de.ini` / `kia2lox_en.ini` — the main UI (all four tabs)
- `help_de.ini` / `help_en.ini` — LoxBerry's own native help popup
  (`templates/help/help.html`, a `TMPL_VAR`-based LoxBerry template,
  triggered by the third argument to every `LBWeb::lbheader(...)` call);
  this is intentionally separate from and much shorter than the in-plugin
  `help.php` tab, which just points users to that tab for the full guide
- `language_de.ini` / `language_en.ini` — LoxBerry's own plugin-management
  chrome strings

**Any UI text change or new feature must update both DE and EN files
together** — there is no automated fallback that makes a German-only key
acceptable, and stale/missing keys have been checked before by manually
diffing section-qualified keys between the `.ini` files and the
`kia2lox_t()` call sites in PHP.

### LoxBerry/jQuery Mobile CSS quirks (apply proactively to new UI elements)

LoxBerry's frontend auto-enhances forms/buttons via jQuery Mobile, which
fights hand-built layouts. Recurring, already-solved problems in
`webfrontend/htmlauth/assets/kia2lox.css` and the PHP markup:

- Inputs/selects that should NOT be auto-enhanced need `data-role="none"`.
- Even with `data-role="none"`, jQuery Mobile can still restructure a
  `<form>` element's layout — prefer a plain `<button data-role="none">`
  wired up with JS/`fetch()` instead of a `<form>` when the control is a
  small inline action (e.g. the refresh icon buttons next to interval
  labels, the collapsible "Warnungen" card header) rather than an actual
  form submission.
- LoxBerry's own theme CSS sets its own `display` rule for `<button>` that
  can override an unweighted class selector — flex layouts on buttons
  sometimes need `!important` to actually stick (see the comment on
  `.kia2lox-collapse-toggle` in `kia2lox.css` for the canonical example).

### Install lifecycle scripts (LoxBerry-defined hooks, run in this order)

`preroot.sh` → `preupgrade.sh` (updates only) → `preinstall.sh` →
*(files copied)* → `postinstall.sh` → `postupgrade.sh` (updates only) →
`postroot.sh`. Key behaviors:

- `preupgrade.sh` backs up `pluginconfig.json` and `state.json`/
  `history_*.jsonl` to `/tmp` before LoxBerry resets the config directory;
  `postupgrade.sh` restores them. The Python venv is deliberately **not**
  backed up — `postinstall.sh` rebuilds it from scratch every time based on
  a version marker file (`$VENVDIR/.kia2lox_python_version`), discarding
  and recreating it if the pinned Python version changed.
- `postinstall.sh` also seeds a default single-vehicle `pluginconfig.json`
  on a genuinely fresh install (no pre-existing config).

### State files (not in git, live only on the LoxBerry)

- `$LBPCONFIG/kia2lox/pluginconfig.json` — all vehicle configs (credentials,
  Miniserver target, schedule, warning thresholds, `http_key`). Written with
  `chmod 640`.
- `$LBPDATA/kia2lox/state.json` — last known status + today's poll log per
  vehicle.
- `$LBPDATA/kia2lox/history_<vehicle_id>.jsonl` — one JSON object per line,
  used for the SoC chart on the Overview page; pruned by
  `bin/kia2lox_poll.py` to `HISTORY_RETENTION_DAYS` (90) on every write.

## Conventions

- Comments and identifiers are German-first throughout (PHP comments,
  Python docstrings/comments, commit messages); this matches the primary
  developer's language. English is used for: README, GitHub release notes,
  and the `_en.ini` translation files.
- `.gitattributes` forces `eol=lf` on all text file types (`.sh .cfg .ini
  .php .html .py .svg .md`) — this repo is edited on Windows but deployed
  to Linux, so don't let an editor reintroduce CRLF.
- Comments favor explaining *why* (a workaround, a non-obvious constraint,
  a past bug) over *what* — follow that style rather than restating code in
  prose.
