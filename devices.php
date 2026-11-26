<?php
// Legacy Controller Proxy - main page (rendered inside FPP's UI by plugin.php)
$flpStatic = 'plugin.php?plugin=fpp-legacy-proxy&nopage=1&file=static/';
?>
<link rel="stylesheet" href="<?= $flpStatic ?>dashboard.css">

<div id="flp" class="container-fluid flp">
  <div class="flp-head">
    <div>
      <div class="flp-sub">Modern, phone-friendly pages for older E1.31 / sACN controllers. Settings are sent to each controller exactly as its own page would send them.</div>
    </div>
    <button type="button" class="buttons btn-success flp-add-btn" id="flpAddBtn"><i class="fas fa-plus"></i> Add device</button>
  </div>

  <div id="flpStorageWarn" class="alert alert-danger hidden" role="alert">
    The plugin can't write to its data folder (<code id="flpStoragePath"></code>), so devices can't be saved.
    Reinstall the plugin from the Plugin Manager, or run <code>scripts/fpp_install.sh</code> from the plugin folder.
  </div>

  <!-- add / edit form -->
  <div class="backdrop flp-form hidden" id="flpForm">
    <h3 id="flpFormTitle">Add device</h3>
    <form id="flpDeviceForm" autocomplete="off">
      <input type="hidden" id="flpId">
      <input type="hidden" id="flpKind" value="manual">
      <div class="flp-grid">
        <label class="flp-field"><span>Name</span>
          <input type="text" class="form-control" id="flpName" maxlength="64" placeholder="e.g. Garage bridge"></label>
        <label class="flp-field" id="flpHostField"><span>IP address or host name</span>
          <input type="text" class="form-control" id="flpHost" maxlength="253" placeholder="192.168.1.50" inputmode="url" required></label>
        <label class="flp-field flp-port" id="flpPortField"><span>Port</span>
          <input type="number" class="form-control" id="flpPort" min="1" max="65535" placeholder="80"></label>
        <label class="flp-field flp-wide" id="flpNotesField"><span>Notes (optional)</span>
          <input type="text" class="form-control" id="flpNotes" maxlength="200" placeholder="e.g. Model E681, roof line"></label>
      </div>
      <div class="flp-actions">
        <button type="submit" class="buttons btn-success" id="flpSave">Save</button>
        <button type="button" class="buttons" id="flpCancel">Cancel</button>
      </div>
    </form>
  </div>

  <!-- device list -->
  <div class="backdrop">
    <div class="flp-list-head">
      <h3>Devices</h3>
      <button type="button" class="buttons btn-sm" id="flpRefresh" title="Refresh list and status"><i class="fas fa-sync"></i></button>
    </div>
    <div id="flpList" class="flp-list" aria-live="polite"><div class="flp-empty">Loading…</div></div>
    <details id="flpHiddenWrap" class="flp-hidden-wrap hidden">
      <summary>Hidden FPP proxy devices (<span id="flpHiddenCount">0</span>)</summary>
      <div id="flpHiddenList" class="flp-list"></div>
    </details>
  </div>

  <!-- settings -->
  <div class="backdrop">
    <h3>Settings</h3>
    <div class="flp-grid">
      <label class="flp-field flp-check">
        <input type="checkbox" id="flpIncludeFpp">
        <span>Include devices from FPP's <a href="proxies.php">Proxy Settings</a> (<span id="flpFppCount">0</span> found)</span>
      </label>
      <label class="flp-field"><span>Default view</span>
        <select class="form-select" id="flpDefaultView">
          <option value="modern">Modern</option>
          <option value="classic">Classic</option>
        </select>
      </label>
      <label class="flp-field"><span>Device response timeout (seconds)</span>
        <input type="number" class="form-control" id="flpReadTimeout" min="2" max="120"></label>
    </div>
    <div class="flp-note"><b>Modern</b> redraws each device page for phones and desktops and falls back to Classic automatically if a page can't be redrawn. <b>Classic</b> shows the device's own page, tidied up. Each device can be switched between Modern and Classic from its own page (⋯ menu). Only devices listed here can be reached through this plugin.</div>
  </div>
</div>

<script src="<?= $flpStatic ?>dashboard.js"></script>
