/* Legacy Controller Proxy - main page logic (vanilla JS; FPP's jGrowl for messages). */
(() => {
  const API = "/api/plugin/fpp-legacy-proxy";
  const $ = (id) => document.getElementById(id);
  const notify = (msg, bad) => {
    if (window.jQuery && jQuery.jGrowl) jQuery.jGrowl(msg, { themeState: bad ? "danger" : "success" });
    else if (bad) alert(msg);
  };
  let devices = [];

  async function call(method, path, body) {
    const r = await fetch(API + path, {
      method,
      headers: body ? { "Content-Type": "application/json" } : {},
      body: body ? JSON.stringify(body) : undefined,
    });
    const text = await r.text();
    let j;
    try { j = JSON.parse(text); } catch (_) {
      // Not JSON = not our answer (PHP error, login page, proxy error...). Never report success.
      const snippet = text.replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim().slice(0, 160);
      throw new Error(`The plugin returned an unexpected response (HTTP ${r.status})` +
        (snippet ? `: ${snippet}` : ". Check the plugin log (plugin-fpp-legacy-proxy.log)."));
    }
    if (!r.ok || j.status === false) throw new Error(j.message || `Request failed (HTTP ${r.status})`);
    return j;
  }

  // ---------------------------------------------------------------- render
  function row(d) {
    const el = document.createElement("div");
    el.className = "flp-dev";
    el.innerHTML = `
      <span class="flp-dot" title="Checking…"></span>
      <div class="flp-info">
        <div class="flp-name"></div>
        <div class="flp-meta"><code class="flp-target"></code><span class="flp-extra"></span></div>
      </div>
      <div class="flp-btns"></div>`;
    el.querySelector(".flp-name").textContent = d.name;
    const badge = document.createElement("span");
    badge.className = "flp-badge";
    badge.textContent = d.source === "fpp" ? "FPP proxy" : (d.inFpp ? "Manual · also in FPP" : "Manual");
    el.querySelector(".flp-name").appendChild(badge);
    if (d.view === "classic") {
      const b = document.createElement("span");
      b.className = "flp-badge"; b.textContent = "Classic view";
      el.querySelector(".flp-name").appendChild(b);
    }
    el.querySelector(".flp-target").textContent = d.target;
    if (d.notes && d.notes !== d.name) el.querySelector(".flp-extra").textContent = " · " + d.notes;

    const btns = el.querySelector(".flp-btns");
    const open = document.createElement("a");
    open.className = "buttons btn-success flp-open";
    open.href = d.url;
    open.innerHTML = '<i class="fas fa-external-link-alt"></i> Open';
    open.title = "Open " + d.target;
    btns.appendChild(open);

    const edit = button("fas fa-pen", d.source === "fpp" ? "Rename" : "Edit", () => showForm(d));
    btns.appendChild(edit);
    if (d.source === "fpp") {
      btns.appendChild(button(d.hidden ? "fas fa-eye" : "fas fa-eye-slash", d.hidden ? "Show" : "Hide",
        () => save("POST", "/devices/fpp", { target: d.target, hidden: !d.hidden })));
    } else {
      btns.appendChild(button("fas fa-trash", "Remove", () => {
        if (confirm(`Remove ${d.name} (${d.target}) from the list?`)) save("POST", "/devices/delete", { id: d.id });
      }, "btn-danger"));
    }
    return el;
  }

  function button(icon, label, onClick, extra) {
    const b = document.createElement("button");
    b.type = "button";
    b.className = "buttons" + (extra ? " " + extra : "");
    b.innerHTML = `<i class="${icon}"></i> `;
    b.appendChild(document.createTextNode(label));
    b.onclick = onClick;
    return b;
  }

  function render() {
    const shown = devices.filter((d) => !d.hidden);
    const hidden = devices.filter((d) => d.hidden);
    const list = $("flpList");
    list.innerHTML = "";
    if (!shown.length) {
      list.innerHTML = '<div class="flp-empty">No devices yet. Use <b>Add device</b>, or add controllers in FPP\'s Proxy Settings.</div>';
    }
    shown.forEach((d) => list.appendChild(row(d)));
    $("flpHiddenWrap").classList.toggle("hidden", !hidden.length);
    $("flpHiddenCount").textContent = hidden.length;
    const hl = $("flpHiddenList");
    hl.innerHTML = "";
    hidden.forEach((d) => hl.appendChild(row(d)));
    checkStatus();
  }

  // One status check at a time, so busy controllers aren't hammered.
  async function checkStatus() {
    for (const el of document.querySelectorAll("#flpList .flp-dev")) {
      const target = el.querySelector(".flp-target").textContent;
      const dot = el.querySelector(".flp-dot");
      try {
        const s = await (await fetch(`${API}/status/${encodeURIComponent(target)}`)).json();
        dot.className = "flp-dot " + (s.online ? "on" : "off");
        dot.title = s.online ? (s.ms != null ? `Online (${s.ms} ms)` : "Online (busy)") : "Not reachable";
      } catch (_) { dot.title = "Status unknown"; }
    }
  }


  async function load() {
    try {
      const j = await call("GET", "/devices");
      devices = j.devices || [];
      $("flpFppCount").textContent = j.fppProxiesCount;
      const warn = $("flpStorageWarn");
      warn.classList.toggle("hidden", j.storageWritable !== false);
      $("flpStoragePath").textContent = j.storagePath || "";
      render();
    } catch (e) {
      notify(e.message, true);
      $("flpList").innerHTML = "";
      const d = document.createElement("div");
      d.className = "flp-empty"; d.textContent = "Couldn't load devices: " + e.message;
      $("flpList").appendChild(d);
    }
  }

  async function save(method, path, body) {
    try {
      const j = await call(method, path, body);
      if (j.devices) { devices = j.devices; render(); }
      notify(j.message || "Saved");
      return true;
    } catch (e) { notify(e.message, true); return false; }
  }

  // ------------------------------------------------------------------ form
  function showForm(d) {
    const fpp = d && d.source === "fpp";
    $("flpFormTitle").textContent = !d ? "Add device" : fpp ? `Rename ${d.target}` : `Edit ${d.name}`;
    $("flpId").value = d && !fpp ? d.id : "";
    $("flpKind").value = fpp ? "fpp" : "manual";
    $("flpName").value = d ? d.name : "";
    $("flpHost").value = d ? d.host : "";
    $("flpPort").value = d && d.port !== 80 ? d.port : "";
    $("flpNotes").value = d && !fpp ? d.notes : "";
    $("flpHost").required = !fpp;
    ["flpHostField", "flpPortField", "flpNotesField"].forEach((id) => $(id).classList.toggle("hidden", fpp));
    $("flpForm").classList.remove("hidden");
    $("flpForm").dataset.target = d ? d.target : "";
    $("flpForm").scrollIntoView({ behavior: "smooth", block: "start" });
    (fpp ? $("flpName") : (d ? $("flpName") : $("flpHost"))).focus();
  }
  const hideForm = () => $("flpForm").classList.add("hidden");

  $("flpDeviceForm").onsubmit = async (e) => {
    e.preventDefault();
    let ok;
    if ($("flpKind").value === "fpp") {
      ok = await save("POST", "/devices/fpp", { target: $("flpForm").dataset.target, name: $("flpName").value });
    } else {
      ok = await save("POST", "/devices", {
        id: $("flpId").value, name: $("flpName").value, host: $("flpHost").value.trim(),
        port: $("flpPort").value, notes: $("flpNotes").value,
      });
    }
    if (ok) hideForm();
  };
  $("flpAddBtn").onclick = () => showForm(null);
  $("flpCancel").onclick = hideForm;
  $("flpRefresh").onclick = load;

  // Settings are saved by FPP's own PrintSetting* helpers; refresh the list after a change
  // (e.g. turning the FPP Proxy Settings list on or off).
  window.fpplegacyproxySettingChanged = () => load();

  load();
})();
