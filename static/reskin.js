/* sacnProxy re-skin client: ordered submits, unsaved-change tracking,
   quick-pick chips, Live values, classic/modern toggle. */
(() => {
  const SP = window.SP || {};
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const toast = (msg) => {
    const t = document.getElementById("sp-toast");
    if (!t) return;
    t.textContent = msg; t.classList.add("on");
    clearTimeout(toast._t); toast._t = setTimeout(() => t.classList.remove("on"), 2200);
  };

  // ---- Submit exactly like the original page would (document order) ----------
  document.addEventListener("submit", (e) => {
    const f = e.target;
    if (!f.matches("form[data-sp]")) return;
    e.preventDefault();
    const btn = e.submitter && e.submitter.form === f ? e.submitter : null;
    if (btn && btn.dataset.danger &&
        !confirm(`${btn.textContent.trim()}?\n\nThe device may be unreachable for a moment.`)) return;
    // Dropdown choices that are destructive (e.g. "Erase configuration") confirm too.
    for (const sel of [...f.elements].filter((el) => el.tagName === "SELECT" && el.dataset.i !== undefined)) {
      const o = sel.selectedOptions[0];
      if (o && o.dataset.confirm && !confirm(o.dataset.confirm)) return;
    }

    const out = document.createElement("form");
    out.method = f.getAttribute("method") || "get";
    out.action = f.action;
    // Encode values the way the device's own page would have (no charset on
    // these pages means the browser used windows-1252).
    if (SP.charset) out.acceptCharset = SP.charset;
    if (f.getAttribute("enctype")) out.enctype = f.getAttribute("enctype");
    out.style.display = "none";
    const add = (n, v) => {
      const i = document.createElement("input");
      i.type = "hidden"; i.name = n; i.value = v; out.appendChild(i);
    };
    const els = [...f.elements].filter((el) => el.dataset.i !== undefined)
      .sort((a, b) => a.dataset.i - b.dataset.i);
    for (const el of els) {
      if (el.disabled || !el.name) continue;
      const t = (el.type || "").toLowerCase();
      if (["submit", "button", "reset", "image"].includes(t) || el.tagName === "BUTTON") {
        if (el === btn && t !== "reset" && t !== "button") add(el.name, el.value);
        continue;
      }
      if (t === "radio" || t === "checkbox") { if (el.checked) add(el.name, el.value); continue; }
      if (el.tagName === "SELECT") {
        for (const o of el.selectedOptions) if (!o.disabled) add(el.name, o.value);
        continue;
      }
      add(el.name, el.value);
    }
    document.body.appendChild(out);
    sessionStorage.setItem("sp-scroll", JSON.stringify([location.pathname, scrollY]));
    document.body.classList.add("sp-busy");
    out.submit();
  });

  // keep the scroll position across the device's full-page reply
  try {
    const s = JSON.parse(sessionStorage.getItem("sp-scroll") || "null");
    if (s && s[0] === location.pathname) scrollTo(0, s[1]);
    sessionStorage.removeItem("sp-scroll");
  } catch (_) {}

  // ---- Unsaved-change tracking ------------------------------------------------
  const original = new Map();
  const snap = (el) => (el.type === "radio" || el.type === "checkbox") ? el.checked : el.value;
  $$("[data-i]").forEach((el) => original.set(el, snap(el)));
  const dirtyForms = new Set();
  const refreshDirty = (form) => {
    if (!form) return;
    const dirty = [...form.elements].some((el) => original.has(el) && snap(el) !== original.get(el));
    dirty ? dirtyForms.add(form.id) : dirtyForms.delete(form.id);
    $$(`button[form="${form.id}"][type="submit"]`).forEach((b) => b.classList.toggle("sp-dirty", dirty));
    $$("[data-i]").filter((el) => el.form === form).forEach((el) =>
      el.classList.toggle("sp-changed", original.has(el) && snap(el) !== original.get(el)));
  };
  document.addEventListener("input", (e) => e.target.form && refreshDirty(e.target.form));
  document.addEventListener("change", (e) => e.target.form && refreshDirty(e.target.form));
  addEventListener("beforeunload", (e) => {
    if (dirtyForms.size && !document.body.classList.contains("sp-busy")) { e.preventDefault(); e.returnValue = ""; }
  });

  // ---- Quick-pick chips (forms whose only setting is a short dropdown) ---------
  for (const i of SP.quick || []) {
    const sel = document.querySelector(`select[data-i="${i}"]`);
    if (!sel || !sel.form) continue;
    const form = sel.form;
    const submits = $$(`button[form="${form.id}"][type="submit"]`);
    const wrap = document.createElement("div");
    wrap.className = "sp-chips";
    for (const o of sel.options) {
      if (o.disabled) continue;
      const b = document.createElement("button");
      b.type = "button"; b.className = "sp-chip" + (o.selected ? " on" : "");
      b.textContent = o.textContent.trim();
      b.onclick = () => {
        sel.value = o.value;
        form.requestSubmit(submits.length === 1 ? submits[0] : undefined);
      };
      wrap.appendChild(b);
    }
    sel.classList.add("sp-hide");
    sel.after(wrap);
    const field = sel.closest(".sp-field");
    if (field) {
      field.classList.add("sp-field-wide");
      const lab = field.querySelector(".sp-label"), card = field.closest(".sp-card");
      const h = card && card.querySelector(".sp-card-h");
      if (lab && h && lab.textContent.trim() === h.textContent.trim()) lab.classList.add("sp-hide");
    }
    submits.forEach((b) => b.classList.add("sp-hide"));
  }

  // ---- Test-pattern alert: "Turn off" = pick Off + press that form's own button ---
  const ctlByI = (i) => document.querySelector(`[data-i="${i}"]:not([type="hidden"])`) ||
                        document.querySelector(`[data-i="${i}"]`);
  const showSetting = (el) => {
    const target = el.closest(".sp-field, .sp-card") || el;
    target.scrollIntoView({ behavior: "smooth", block: "center" });
    target.classList.remove("sp-pulse"); void target.offsetWidth; target.classList.add("sp-pulse");
  };
  document.addEventListener("click", (e) => {
    const off = e.target.closest("[data-test-off]");
    const show = e.target.closest("[data-test-show]");
    if (show) { e.preventDefault(); const el = ctlByI(show.dataset.testShow); if (el) showSetting(el); return; }
    if (!off) return;
    const el = ctlByI(off.dataset.testI);
    if (!el || !el.form) return;
    if (dirtyForms.has(el.form.id)) {   // don't send someone's half-finished edits
      showSetting(el);
      toast("You have unsaved changes in this section — save or undo them first.");
      return;
    }
    el.value = off.dataset.testOff;
    const submits = $$(`button[form="${el.form.id}"][type="submit"]`);
    off.disabled = true; off.textContent = "Turning off…";
    el.form.requestSubmit(submits.length === 1 ? submits[0] : undefined);
  });

  // ---- Live values --------------------------------------------------------------
  const liveBtn = document.getElementById("sp-live");
  if (liveBtn) {
    const KEY = "sp-live:" + SP.target;
    let timer = null, busy = false;
    const tick = async () => {
      if (busy || document.hidden || dirtyForms.size ||
          (document.activeElement && document.activeElement.dataset.i !== undefined)) return;
      busy = true;
      try {
        const r = await fetch(`${SP.liveUrl}?path=${encodeURIComponent(SP.path)}`);
        if (!r.ok) throw new Error();
        const vals = await r.json();
        $$("[data-k]").forEach((el) => {
          const v = vals[el.dataset.k];
          if (v !== undefined && el.textContent.trim() !== v) {
            el.textContent = v;
            el.classList.remove("sp-flash"); void el.offsetWidth; el.classList.add("sp-flash");
          }
        });
        liveBtn.classList.remove("sp-err");
      } catch (_) { liveBtn.classList.add("sp-err"); }
      busy = false;
    };
    const set = (on) => {
      liveBtn.setAttribute("aria-pressed", on);
      liveBtn.classList.toggle("on", on);
      clearInterval(timer); timer = on ? setInterval(tick, 4000) : null;
      try { localStorage.setItem(KEY, on ? "1" : ""); } catch (_) {}
      if (on) tick();
    };
    liveBtn.onclick = () => { const on = liveBtn.getAttribute("aria-pressed") !== "true"; set(on); toast(on ? "Live values on" : "Live values off"); };
    try { if (localStorage.getItem(KEY)) set(true); } catch (_) {}
  }

  // close the ⋯ menu when tapping elsewhere
  document.addEventListener("click", (e) => {
    $$("details.sp-menu[open]").forEach((d) => { if (!d.contains(e.target)) d.open = false; });
  });
})();
