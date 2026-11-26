"""
Generic re-skin engine for legacy device web pages.

parse(html)  -> Page model (sections, tables, controls, forms)
render(page) -> modern, mobile-first HTML

Design rules
  * Works on ANY device page; device profiles only tweak wording.
  * Form/control association follows the HTML5 "form element pointer" rule, so
    broken markup (forms wrapped around <tr>, unclosed tags) maps exactly the way
    a browser would map it.
  * Every original control is re-emitted with its original name/value and a
    data-i index; reskin.js submits in original document order, so the device
    receives the same request a browser would have sent from the original page.
  * If anything looks unsafe (frames, heavy scripts, controls lost in rendering),
    raise CannotReskin and the caller falls back to the classic view.
"""

import html as htmllib
import re
from dataclasses import dataclass, field
from html.parser import HTMLParser


class CannotReskin(Exception):
    pass


# ----------------------------------------------------------------------------
# Device profiles: matched against <title> / first heading. Wording only.
# ----------------------------------------------------------------------------
PROFILES = [
    {
        "match": re.compile(r"E1\.31 Bridge", re.I),
        "name": "DIYLEDExpress E1.31 Bridge",
        "column_noun": "Output",
        "titles": {  # section title overrides by detected title
            "System Settings": "Network",
            "E1.31 Packet Statistics": "Universes & Statistics",
        },
    },
    {
        "match": re.compile(r"SanDevices", re.I),
        "model": re.compile(r"Model\s+(E\d+\w*)", re.I),
        "name": "SanDevices",
        "column_noun": "Slot",
        "titles": {
            "System Information": "System",
            "Universe Selection and Packet Statistics": "Universes & Statistics",
        },
        # Numeric code fields shown as dropdowns. Values are submitted exactly as
        # if typed, so the controller sees no difference.
        # Source: SanDevices E6804 Operating Manual (firmware 4.026); test codes
        # 0-6 also match the E681 Operating Manual.
        "choices": [
            {
                "button": re.compile(r"^Update System Information$", re.I),
                "name": "H",
                "options": [("0", "Off (normal operation)")]
                + [(str(n), t) for n, t in (
                    (1, "All red"), (2, "All green"), (3, "All blue"),
                    (4, "Chase: bright red pixel over dim red"),
                    (5, "Chase: bright green pixel over dim green"),
                    (6, "Chase: bright blue pixel over dim blue"))]
                + [(str(n), f"RGB chase: {n - 10} red, {n - 10} green, {n - 10} blue")
                   for n in range(11, 30)],
                "test_off": "0",
                "hint": "While a test pattern is on, the controller ignores incoming data. "
                        "Colours may differ if the pixels aren't RGB order.",
            },
            {
                "button": re.compile(r"^System Command$", re.I),
                "name": "A",
                "options": [
                    ("0", "No command"),
                    ("10", "Erase configuration (keeps IP address)",
                     "Erase the controller's configuration memory? Everything except the IP "
                     "address is wiped."),
                    ("20", "Load test configuration (all outputs 2801, 50 pixels)",
                     "Replace the configuration with the test setup (all outputs WS2801, "
                     "50 pixels per string)?"),
                    ("30", "Load as-shipped defaults",
                     "Replace the configuration with the as-shipped defaults?"),
                ],
                "hint": "To return to factory defaults (keeping the IP address), run 10, then 20, then 30.",
            },
        ],
    },
]


def find_profile(*texts):
    for p in PROFILES:
        if any(t and p["match"].search(t) for t in texts):
            p = dict(p)
            m = p.get("model") and next((p["model"].search(t) for t in texts if t and p["model"].search(t)), None)
            if m:
                p["name"] = f'{p["name"]} {m.group(1)}'
            return p
    return {}


# ----------------------------------------------------------------------------
# Model
# ----------------------------------------------------------------------------
@dataclass
class Ctl:
    i: int
    kind: str                     # text, password, hidden, checkbox, radio, select,
    name: str = ""                # textarea, submit, button, reset, image, number...
    value: str = ""
    checked: bool = False
    disabled: bool = False
    readonly: bool = False
    size: str = ""
    maxlength: str = ""
    multiple: bool = False
    options: list = field(default_factory=list)   # [value, label, selected, disabled]
    label: str = ""               # text after a radio/checkbox, or button text
    form: int = None              # form index or None (unassociated)
    has_value_attr: bool = True

    @property
    def is_button(self):
        return self.kind in ("submit", "button", "reset", "image")

    @property
    def editable(self):
        return not self.is_button and self.kind != "hidden"


@dataclass
class Cell:
    tag: str
    colspan: int = 1
    rowspan: int = 1
    items: list = field(default_factory=list)  # ("t", text, flags) ("a", text, href) ("c", i) ("br",)

    def text(self):
        return norm(" ".join(x[1] for x in self.items if x[0] in ("t", "a")))

    def ctls(self):
        return [x[1] for x in self.items if x[0] == "c"]


@dataclass
class Table:
    idx: int
    rows: list = field(default_factory=list)


@dataclass
class Section:
    heading: str = ""
    items: list = field(default_factory=list)   # inline items + ("table", Table)


@dataclass
class Page:
    title: str = ""
    heading: str = ""
    refresh: tuple = None         # (seconds, url or None)
    sections: list = field(default_factory=list)
    forms: list = field(default_factory=list)   # {"action","method","enctype"}
    ctls: list = field(default_factory=list)
    tables: list = field(default_factory=list)
    frames: bool = False
    charset: str = ""
    script_chars: int = 0
    handlers: int = 0


def norm(s):
    return re.sub(r"\s+", " ", s).strip()


# ----------------------------------------------------------------------------
# Parser
# ----------------------------------------------------------------------------
BLOCK_BREAK = {"br", "p", "div", "li", "ul", "ol", "center", "pre", "h4", "h5", "h6",
               "dl", "dt", "dd", "blockquote"}
INPUT_KINDS = {"text", "password", "hidden", "checkbox", "radio", "submit", "button",
               "reset", "image", "number", "email", "tel", "url", "search", "range",
               "date", "time", "color", "file"}


class _Parser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.p = Page()
        self.sec = Section()
        self.p.sections.append(self.sec)
        self.form_ptr = None
        self.tstack = []          # [Table, current row(list) or None, current Cell or None]
        self.nested = 0           # depth of tables nested inside a cell (flattened)
        self.skip = None          # "style" | "script" | "title"
        self.select = None
        self.option = None
        self.textarea = None
        self.button = None
        self.link = None          # [href, text]
        self.heading = None       # [tag, text]
        self.red = []             # font-color stack
        self.muted = 0
        self.pending_label = None # radio/checkbox waiting for its trailing text

    # -- where inline content goes --------------------------------------------
    def _target(self):
        if self.tstack and self.tstack[-1][2] is not None:
            return self.tstack[-1][2].items
        return self.sec.items

    def _emit(self, item):
        self._target().append(item)

    def _flags(self):
        f = ""
        if self.red and self.red[-1]:
            f += "r"
        if self.muted:
            f += "m"
        return f

    # -- tags -----------------------------------------------------------------
    def handle_starttag(self, tag, attrs):
        a = {k.lower(): (v if v is not None else "") for k, v in attrs}
        self.p.handlers += sum(1 for k in a if k.startswith("on"))
        if tag in ("style", "script", "title"):
            self.skip = tag
            return
        if tag in ("frameset", "frame", "iframe"):
            self.p.frames = True
            return
        if tag == "meta" and not self.p.charset:
            cs = a.get("charset") or (re.search(r"charset=([\w-]+)", a.get("content", ""), re.I) or [None, ""])[1]
            if cs:
                self.p.charset = cs.strip().lower()
        if tag == "meta" and a.get("http-equiv", "").lower() == "refresh":
            m = re.match(r"\s*(\d+)\s*(?:;\s*url\s*=\s*(.*))?", a.get("content", ""), re.I)
            if m:
                self.p.refresh = (int(m.group(1)), (m.group(2) or "").strip(" '\"") or None)
            return
        if tag == "hr" and not self.tstack:
            self._close_label()
            self.sec = Section()
            self.p.sections.append(self.sec)
            return
        if tag in ("h1", "h2", "h3"):
            self.heading = [tag, ""]
            return
        if tag in ("h4", "h5", "h6"):
            self.muted += 1
        if tag == "font":
            c = a.get("color", "").strip().lower()
            if c:
                self.red.append(c in ("red", "#ff0000", "#f00", "#cc0000"))
            else:
                self.red.append(bool(self.red and self.red[-1]))
            return
        if tag == "a" and "href" in a:
            self.link = [a["href"], ""]
            return
        if tag in BLOCK_BREAK:
            self._close_label()
            self._emit(("br",))
            return

        if tag == "form":
            if self.form_ptr is None:  # HTML5: nested <form> start tags are ignored
                self.p.forms.append({
                    "action": a.get("action", ""),
                    "method": (a.get("method") or "get").lower(),
                    "enctype": a.get("enctype", ""),
                })
                self.form_ptr = len(self.p.forms) - 1
            return

        if tag == "table":
            if self.tstack and self.tstack[-1][2] is not None:
                self.nested += 1
                return
            t = Table(idx=len(self.p.tables))
            self.p.tables.append(t)
            self.sec.items.append(("table", t))
            self.tstack.append([t, None, None])
            return
        if self.nested:
            if tag in ("tr", "td", "th"):
                self._emit(("br",) if tag == "tr" else ("t", " ", ""))
            if tag not in ("input", "select", "option", "textarea", "button"):
                return
        if tag == "tr" and self.tstack:
            ts = self.tstack[-1]
            ts[1] = []
            ts[0].rows.append(ts[1])
            ts[2] = None
            return
        if tag in ("td", "th") and self.tstack:
            ts = self.tstack[-1]
            if ts[1] is None:
                ts[1] = []
                ts[0].rows.append(ts[1])
            cell = Cell(tag=tag, colspan=_int(a.get("colspan"), 1), rowspan=_int(a.get("rowspan"), 1))
            ts[1].append(cell)
            ts[2] = cell
            self.pending_label = None
            return

        if tag == "input":
            kind = a.get("type", "text").lower()
            kind = kind if kind in INPUT_KINDS else "text"
            c = self._new_ctl(kind, a)
            c.value = a.get("value", "on" if kind in ("checkbox", "radio") else "")
            c.has_value_attr = "value" in a
            c.checked = "checked" in a
            if kind in ("checkbox", "radio"):
                self.pending_label = c
            return
        if tag == "select":
            self.select = self._new_ctl("select", a)
            self.select.multiple = "multiple" in a
            return
        if tag == "option" and self.select is not None:
            self.option = [a.get("value"), "", "selected" in a, "disabled" in a]
            self.select.options.append(self.option)
            return
        if tag == "textarea":
            self.textarea = self._new_ctl("textarea", a)
            return
        if tag == "button":
            self.button = self._new_ctl(a.get("type", "submit").lower(), a)
            self.button.value = a.get("value", "")
            return

    def handle_startendtag(self, tag, attrs):
        self.handle_starttag(tag, attrs)

    def handle_endtag(self, tag):
        if tag == self.skip:
            self.skip = None
            return
        if tag in ("h1", "h2", "h3") and self.heading:
            text = norm(self.heading[1])
            self.heading = None
            if text:
                if not self.p.heading:
                    self.p.heading = text
                elif not self.tstack:
                    if self.sec.items or self.sec.heading:
                        self.sec = Section()
                        self.p.sections.append(self.sec)
                    self.sec.heading = text
                else:
                    self._emit(("t", text, "b"))
                    self._emit(("br",))
            return
        if tag in ("h4", "h5", "h6"):
            self.muted = max(0, self.muted - 1)
            self._emit(("br",))
            return
        if tag == "font":
            if self.red:
                self.red.pop()
            return
        if tag == "a" and self.link:
            href, text = self.link
            self.link = None
            if norm(text):
                self._emit(("a", norm(text), href, "m" if self.muted else ""))
            return
        if tag == "form":
            self.form_ptr = None
            return
        if tag == "option":
            self.option = None
            return
        if tag == "select":
            self.select = self.option = None
            return
        if tag == "textarea":
            self.textarea = None
            return
        if tag == "button" and self.button:
            self.button.label = norm(self.button.label)
            self.button = None
            return
        if tag == "table":
            if self.nested:
                self.nested -= 1
                return
            if self.tstack:
                self.tstack.pop()
            return
        if self.nested:
            return
        if tag in ("td", "th") and self.tstack:
            self.tstack[-1][2] = None
            self.pending_label = None
        if tag == "tr" and self.tstack:
            self.tstack[-1][1] = None
            self.tstack[-1][2] = None
        if tag in BLOCK_BREAK:
            self._close_label()
            self._emit(("br",))

    def handle_data(self, data):
        if self.skip == "title":
            self.p.title += data
            return
        if self.skip == "script":
            self.p.script_chars += len(data.strip())
            return
        if self.skip:
            return
        if self.option is not None:
            self.option[1] += data
            return
        if self.select is not None:
            return
        if self.textarea is not None:
            self.textarea.value += data
            return
        if self.button is not None:
            self.button.label += data
            return
        if self.heading is not None:
            self.heading[1] += data
            return
        if self.link is not None:
            self.link[1] += data
            return
        if not data.strip():
            if data and self._target() and self._target()[-1][0] == "t":
                self._emit(("t", " ", ""))
            return
        if self.tstack and self.tstack[-1][2] is None:
            # stray text inside <table> but outside cells: browsers foster it before the table
            self.sec.items.insert(len(self.sec.items) - 1, ("t", data, self._flags()))
            return
        if self.pending_label is not None and not self.pending_label.label:
            self.pending_label.label = norm(data)
            self.pending_label = None
            return
        self._emit(("t", data, self._flags()))

    # -- helpers --------------------------------------------------------------
    def _new_ctl(self, kind, a):
        c = Ctl(
            i=len(self.p.ctls), kind=kind, name=a.get("name", ""),
            disabled="disabled" in a, readonly="readonly" in a,
            size=a.get("size", ""), maxlength=a.get("maxlength", ""),
            form=self.form_ptr,
        )
        self.p.ctls.append(c)
        self._emit(("c", c.i))
        return c

    def _close_label(self):
        self.pending_label = None


def _int(v, d):
    try:
        return max(1, min(int(v), 50))
    except (TypeError, ValueError):
        return d


def parse(source: str) -> Page:
    p = _Parser()
    p.feed(source)
    p.close()
    page = p.p
    # browsers: select-one with nothing selected -> first enabled option is selected
    for c in page.ctls:
        if c.kind == "select":
            for o in c.options:
                if o[0] is None:
                    o[0] = norm(o[1])
            if not c.multiple and c.options and not any(o[2] for o in c.options):
                first = next((o for o in c.options if not o[3]), None)
                if first:
                    first[2] = True
        if c.kind == "textarea" and c.value.startswith("\n"):
            c.value = c.value[1:]
    return page


# ----------------------------------------------------------------------------
# Renderer
# ----------------------------------------------------------------------------
E = lambda s: htmllib.escape(str(s), quote=True)
VERBS = re.compile(r"^(update|change|save|set|apply|submit|edit|configure)\s+", re.I)
DANGER = re.compile(r"\b(reboot|restart|reset|firmware|factory|erase|format|delete|clear|upgrade|flash)\b", re.I)
NUMERICISH = re.compile(r"^[\d.:\s]*$")
TEXTLIKE = {"text", "number", "password", "email", "tel", "url", "search"}


def tidy_label(s):
    s = norm(s).rstrip(":").strip()
    m = re.match(r"^\((.+)\)\s*(.*)$", s)  # "(Renard Only) Baud" -> "Baud (Renard only)"
    if m and m.group(2):
        s = f"{m.group(2)} ({m.group(1)[0].upper()}{m.group(1)[1:].lower()})"
    s = re.sub(r"\s+is$", "", s)
    return s


class Renderer:
    def __init__(self, page: Page, profile: dict):
        self.p = page
        self.prof = profile
        self.used = set()
        self.live_keys = 0
        self.fid = lambda f: f"spf{f}"
        self.base = "/"
        self.host = ""
        self.footer = []
        self.lifted = []   # buttons pulled out of tables into the card's action bar
        self.form_edit = {c.form for c in page.ctls if c.form is not None and c.editable}
        self.choices = self._match_choices()

    # -- controls ---------------------------------------------------------------
    def ctl(self, i, compact=False):
        c = self.p.ctls[i]
        self.used.add(i)
        form = f' form="{self.fid(c.form)}"' if c.form is not None else ""
        common = f'{form} data-i="{c.i}"' + (" disabled" if c.disabled else "") + (" readonly" if c.readonly else "")
        name = f' name="{E(c.name)}"' if c.name else ""
        if c.kind == "hidden":
            return f'<input type="hidden"{name} value="{E(c.value)}"{common}>'
        if c.kind == "select":
            opts = "".join(
                f'<option value="{E(o[0])}"{" selected" if o[2] else ""}{" disabled" if o[3] else ""}>{E(norm(o[1]))}</option>'
                for o in c.options)
            return f'<select class="sp-in"{name}{common}{" multiple" if c.multiple else ""}>{opts}</select>'
        if c.kind == "textarea":
            return f'<textarea class="sp-in"{name}{common} rows="3">{E(c.value)}</textarea>'
        if c.kind in ("radio", "checkbox"):
            cls = "sp-seg" if c.kind == "radio" else "sp-check"
            return (f'<label class="{cls}"><input type="{c.kind}"{name} value="{E(c.value)}"'
                    f'{" checked" if c.checked else ""}{common}>'
                    + (f'<span>{E(c.label or c.value)}</span>' if (c.label or c.kind == "radio") else "")
                    + '</label>')
        if c.is_button:
            text = c.label or c.value or {"submit": "Submit", "reset": "Reset"}.get(c.kind, "Button")
            if text.isupper() and len(text) > 3:
                text = text.title()  # display only; the submitted value is unchanged
            danger = DANGER.search(text) is not None
            second = "" if danger or c.form in self.form_edit else " sp-btn-2"
            val = f' value="{E(c.value)}"' if c.has_value_attr else ""
            t = "submit" if c.kind in ("submit", "image") else c.kind
            return (f'<button type="{t}" class="sp-btn{" sp-danger" if danger else second}"{name}{val}{common}'
                    f'{" data-danger=1" if danger else ""}>{E(text)}</button>')
        if c.i in self.choices:
            return self.choice_select(c, self.choices[c.i], common, name)
        # text-like inputs
        t = c.kind if c.kind in ("password", "number", "email", "tel", "url", "search", "date", "time", "color", "range") else "text"
        mode = ' inputmode="decimal"' if t == "text" and c.value and NUMERICISH.match(c.value) else ""
        ml = f' maxlength="{E(c.maxlength)}"' if c.maxlength.isdigit() else ""
        width = ""
        if c.size.isdigit():
            width = f' style="--sz:{min(int(c.size), 40)}"'
        return (f'<input class="sp-in" type="{t}"{name} value="{E(c.value)}"{ml}{mode}{width}'
                f' autocomplete="off" autocapitalize="off" spellcheck="false"{common}>')

    def items_html(self, items, live_key=None):
        """Render a run of inline items (cell content or loose line)."""
        out, radios, run = [], [], []

        def flush_radios():
            if radios:
                out.append('<div class="sp-segs">' + "".join(radios) + "</div>")
                radios.clear()
            if run:
                if len(run) == 1:
                    out.append(self.ctl(run[0].i))
                else:
                    octets = len(run) == 4 and all(c.maxlength.isdigit() and int(c.maxlength) <= 3 for c in run)
                    sep = '<span class="sp-sep">.</span>' if octets else ""
                    cls = "sp-multi sp-octets" if octets else "sp-multi"
                    out.append(f'<div class="{cls}">' + sep.join(self.ctl(c.i) for c in run) + "</div>")
                run.clear()

        for it in items:
            if it[0] == "c":
                c = self.p.ctls[it[1]]
                if c.kind == "radio":
                    radios.append(self.ctl(it[1]))
                    continue
                if c.kind in TEXTLIKE and c.i not in self.choices:
                    if radios:
                        flush_radios()
                    run.append(c)
                    continue
                flush_radios()
                out.append(self.ctl(it[1]))
            elif it[0] == "t":
                if norm(it[1]):
                    flush_radios()
                    cls = " sp-warn" if "r" in it[2] else (" sp-muted" if "m" in it[2] else "")
                    out.append(f'<span class="sp-txt{cls}">{E(norm(it[1]))}</span>')
            elif it[0] == "a":
                flush_radios()
                out.append(f'<a href="{E(self.url(it[2]))}">{E(it[1])}</a>')
        flush_radios()
        html = " ".join(out)
        if not html:
            return ""
        if live_key and not any(it[0] == "c" for it in items):
            self.live_keys += 1
            return f'<span class="sp-val" data-k="{live_key}">{html}</span>'
        return html

    # -- tables ---------------------------------------------------------------
    def grid(self, t: Table):
        """Expand colspans/rowspans into a rectangular grid of Cell refs."""
        grid, carry = [], {}
        for row in t.rows:
            g, col = [], 0
            cells = list(row)
            while cells or col in carry:
                if col in carry:
                    cell, left = carry[col]
                    g.append(cell)
                    if left <= 1:
                        del carry[col]
                    else:
                        carry[col] = (cell, left - 1)
                    col += 1
                    continue
                cell = cells.pop(0)
                for k in range(cell.colspan):
                    g.append(cell)
                    if cell.rowspan > 1:
                        carry[col + k] = (cell, cell.rowspan - 1)
                col += cell.colspan
            grid.append(g)
        width = max((len(r) for r in grid), default=0)
        empty = Cell("td")
        return [r + [empty] * (width - len(r)) for r in grid], width

    def table(self, t: Table):
        grid, width = self.grid(t)
        if not grid or width == 0:
            return ""
        nh = 0
        while nh < len(grid) and all(c.tag == "th" or not (c.text() or c.ctls()) for c in grid[nh]) \
                and any(c.tag == "th" for c in grid[nh]):
            nh += 1
        heads, data = grid[:nh], grid[nh:]
        if not data:
            return ""
        labels = []
        for j in range(width):
            parts = []
            for r in heads:
                tx = r[j].text()
                if tx and tx not in parts:
                    parts.append(tx)
            labels.append(tidy_label(" ".join(parts)))

        first_is_label = all(r[0].text() and not r[0].ctls() for r in data)
        wordy = first_is_label and sum(bool(re.search(r"[A-Za-z]{3}", r[0].text())) for r in data) > len(data) / 2
        k = f"t{t.idx}"
        nonempty = lambda j: any(r[j].text() or r[j].ctls() for r in data)

        # F) a single row with no headers: "Label:" / value cells in a line
        if not heads and len(data) == 1:
            return self.inline_row(data[0], k)

        # A) record: header row(s) + one data row -> labeled field grid.
        #    Columns under one spanning header (e.g. 4 IP octets) become one field.
        if heads and len(data) == 1:
            groups = []
            for j in self._cols(*grid):
                key = tuple(id(h[j]) for h in heads)
                if groups and groups[-1][0] == key and heads[-1][j].colspan > 1:
                    groups[-1][1].append(j)
                else:
                    groups.append((key, [j]))
            entries = []
            for _, js in groups:
                cells = [data[0][j] for j in js]
                if len(js) > 1 and all(not c.ctls() for c in cells):
                    parts = [c.text() for c in cells]
                    joined = ".".join(parts) if all(p.isdigit() for p in parts) else " ".join(parts)
                    cell = Cell("td", items=[("t", joined, "")])
                else:
                    cell = Cell("td", items=[it for c in cells for it in c.items])
                entries.append((labels[js[0]], cell, f"{k}:0:{js[0]}"))
            return self.fields(entries)

        # B) label/value list (2 columns, labels in first column)
        if not heads and width == 2 and first_is_label:
            return self.fields([(tidy_label(r[0].text()), r[1], f"{k}:{n}:1")
                                for n, r in enumerate(data)])

        # C) matrix: metric names down the side, one column per channel/universe
        #    -> one small card per column (headers, if any, become group titles)
        if first_is_label and width > 2 and (not heads or (not labels[0] and wordy)):
            noun = self.prof.get("column_noun", "#")
            out, cur_group, cards, num = [], None, [], 0

            def flush():
                if cards:
                    if cur_group:
                        out.append(f'<div class="sp-group-h">{E(cur_group)}</div>')
                    out.append(f'<div class="sp-minis">{"".join(cards)}</div>')
                    cards.clear()

            for j in self._cols(*grid)[1:]:
                if not nonempty(j):
                    continue
                col_ctls = [i for r in data for i in r[j].ctls()]
                if col_ctls and all(self.p.ctls[i].is_button for i in col_ctls) and \
                        not any(r[j].text() for r in data):
                    self.lifted.extend(self.ctl(i) for i in col_ctls)
                    continue
                if labels[j] != cur_group:
                    flush()
                    cur_group = labels[j]
                num += 1
                rows = "".join(
                    f'<div class="sp-kv"><span class="sp-k">{E(tidy_label(r[0].text()))}</span>'
                    f'<span class="sp-v">{self.items_html(r[j].items, f"{k}:{n}:{j}")}</span></div>'
                    for n, r in enumerate(data) if r[j].text() or r[j].ctls())
                title = f"{noun} {num}" if noun != "#" else f"#{num}"
                cards.append(f'<div class="sp-mini"><div class="sp-mini-h">{E(title)}</div>{rows}</div>')
            flush()
            return "".join(out)

        # D) list: header row(s) + many records -> table on desktop, cards on phones
        if heads:
            cols = [j for j in self._cols(*grid)
                    if any(r[j].text() or r[j].ctls() for r in data)]
            noun = re.sub(r"\s*(number|no\.?|#)$", "", labels[0], flags=re.I) if labels else ""
            th = "".join(f"<th>{E(labels[j])}</th>" for j in cols)
            body = []
            for n, r in enumerate(data):
                tds = []
                for j in cols:
                    cell = r[j]
                    has_btn = any(self.p.ctls[i].is_button for i in cell.ctls())
                    if j == cols[0] and first_is_label:
                        title = f"{noun} {cell.text()}".strip() if len(cell.text()) <= 12 and noun else cell.text()
                        tds.append(f'<td class="sp-rowh"><span class="sp-dsk">'
                                   f'{self.items_html(cell.items, f"{k}:{n}:{j}")}</span>'
                                   f'<span class="sp-mob">{E(title)}</span></td>')
                    elif has_btn:
                        tds.append(f'<td class="sp-act">{self.items_html(cell.items)}</td>')
                    else:
                        tds.append(f'<td data-label="{E(labels[j])}">'
                                   f'{self.items_html(cell.items, f"{k}:{n}:{j}")}</td>')
                body.append(f"<tr>{''.join(tds)}</tr>")
            stack = " sp-stack" if len(cols) > 12 else ""
            return (f'<div class="sp-list{stack}"><table><thead><tr>{th}</tr></thead>'
                    f'<tbody>{"".join(body)}</tbody></table></div>')

        # E) anything else: plain scrollable grid
        rows = []
        for n, r in enumerate(grid):
            tds = "".join(
                f"<td>{self.items_html(r[j].items, f'{k}:{n}:{j}')}</td>" for j in self._cols(r))
            rows.append(f"<tr>{tds}</tr>")
        return f'<div class="sp-scroll"><table class="sp-plain">{"".join(rows)}</table></div>'

    @staticmethod
    def _cols(*rows):
        """Column indexes where any row starts a new cell (collapses colspans)."""
        width = max(len(r) for r in rows)
        return [j for j in range(width)
                if j == 0 or any(j < len(r) and r[j] is not r[j - 1] for r in rows)]

    def inline_row(self, row, k):
        """One headerless row: pair 'Label:' cells with the next value cell."""
        out, label = [], None
        for j in self._cols(row):
            cell = row[j]
            ctls = [self.p.ctls[i] for i in cell.ctls()]
            if ctls and all(c.is_button for c in ctls) and not cell.text():
                self.lifted.append(self.items_html(cell.items))
                continue
            if ctls:
                out.append(f'<div class="sp-field"><span class="sp-label">{E(label or "")}</span>'
                           f'{self.items_html(cell.items)}</div>')
                label = None
                continue
            tx = cell.text()
            if not tx:
                continue
            if tx.endswith(":") and label is None:
                label = tidy_label(tx)
            elif label is not None:
                self.live_keys += 1
                out.append(f'<div class="sp-stat"><span class="sp-k">{E(label)}</span>'
                           f'<span class="sp-v sp-val" data-k="{k}:0:{j}">{E(tx)}</span></div>')
                label = None
            else:
                out.append(f'<div class="sp-stat"><span class="sp-v sp-val" data-k="{k}:0:{j}">{E(tx)}</span></div>')
        return f'<div class="sp-stats">{"".join(out)}</div>' if out else ""

    def fields(self, entries):
        out = []
        for label, cell, key in entries:
            content = self.items_html(cell.items, key)
            if not content.strip():
                continue
            ctls = [self.p.ctls[i] for i in cell.ctls()]
            if ctls and all(c.is_button for c in ctls) and not cell.text():
                self.lifted.append(content)
                continue
            wide = " sp-field-wide" if any(c.kind == "radio" or c.i in self.choices for c in ctls) or \
                sum(c.kind in TEXTLIKE for c in ctls) > 1 else ""
            out.append(f'<div class="sp-field{wide}"><span class="sp-label">{E(label)}</span>{content}</div>')
        return f'<div class="sp-fields">{"".join(out)}</div>'

    # -- sections -------------------------------------------------------------
    def lines(self, items):
        """Split loose (non-table) items into lines on <br>."""
        cur = []
        for it in items:
            if it[0] == "br":
                if cur:
                    yield cur
                cur = []
            elif it[0] == "table":
                if cur:
                    yield cur
                cur = []
                yield [it]
            else:
                cur.append(it)
        if cur:
            yield cur

    def section(self, s: Section, n: int):
        blocks, buttons, title = [], [], s.heading
        lines = list(self.lines(s.items))

        # title = first text-only line ending in ':' before any table/control
        if not title:
            for ln in lines:
                if ln[0][0] == "table" or any(it[0] == "c" and self.p.ctls[it[1]].editable for it in ln):
                    break
                txt = norm(" ".join(it[1] for it in ln if it[0] == "t"))
                if txt.endswith(":") and len(txt) < 60 and all(it[0] in ("t", "c") for it in ln):
                    title = txt.rstrip(":")
                    lines.remove(ln)
                    # keep any (non-editable) controls on that line
                    rest = [it for it in ln if it[0] == "c"]
                    if rest:
                        lines.insert(0, rest)
                    break

        wide = False
        keep = []
        for ln in lines:
            if ln[0][0] != "table" and all(
                    (it[0] == "t" and ("m" in it[2] or not norm(it[1]))) or (it[0] == "a" and it[3] == "m")
                    for it in ln) and any(it[0] in ("t", "a") and norm(it[1]) for it in ln):
                self.footer.append(self.items_html(ln))
            else:
                keep.append(ln)
        lines = keep
        for ln in lines:
            if ln[0][0] == "table":
                t = ln[0][1]
                if any(len(r) > 4 for r in t.rows) and sum(1 for r in t.rows if r) > 3:
                    wide = True
                blocks.append(self.table(t))
                buttons.extend(self.lifted)
                self.lifted = []
                continue
            pending = []   # text waiting to become a label for the next control
            for it in ln:
                if it[0] == "c":
                    c = self.p.ctls[it[1]]
                    if c.is_button:
                        buttons.append(self.ctl(it[1]))
                        continue
                    if c.kind == "hidden":
                        blocks.append(self.ctl(it[1]))
                        continue
                    label = tidy_label(" ".join(x[1] for x in pending if x[0] == "t"))
                    pending = []
                    if not label and c.form is not None:  # borrow the form's button text
                        btn = next((b for b in self.p.ctls if b.form == c.form and b.is_button), None)
                        if btn:
                            t = btn.label or btn.value
                            label = t.title() if t.isupper() else t
                    if c.kind in ("checkbox", "radio"):
                        blocks.append(f'<div class="sp-field sp-field-wide">{self.ctl(it[1])}</div>')
                    else:
                        wide = " sp-field-wide" if c.i in self.choices else ""
                        blocks.append(f'<div class="sp-field{wide}"><span class="sp-label">{E(label)}</span>{self.ctl(it[1])}</div>')
                else:
                    pending.append(it)
            if pending:
                txt = norm(" ".join(x[1] for x in pending if x[0] in ("t", "a")))
                m = re.match(r"^([^:]{1,40}):\s*(\S.*)$", txt)
                if m and all(x[0] == "t" for x in pending):
                    self.live_keys += 1
                    blocks.append(f'<div class="sp-stat"><span class="sp-k">{E(tidy_label(m.group(1)))}</span>'
                                  f'<span class="sp-v sp-val" data-k="kv:{E(tidy_label(m.group(1)))}">{E(m.group(2))}</span></div>')
                elif txt:
                    blocks.append(f'<p class="sp-p">{self.items_html(pending)}</p>')

        if not title:
            for b in buttons:
                m = re.search(r">([^<]+)</button>", b)
                if m and VERBS.match(htmllib.unescape(m.group(1))):
                    title = VERBS.sub("", htmllib.unescape(m.group(1)))
                    break
        title = tidy_label(title or "")
        title = self.prof.get("titles", {}).get(title, title)

        if not blocks and not buttons:
            return ""
        # group consecutive stats / loose fields into tidy rows
        html = []
        for b in blocks:
            if b.startswith('<div class="sp-stat"'):
                if html and html[-1].startswith('<div class="sp-stats">'):
                    html[-1] = html[-1][:-6] + b + "</div>"
                else:
                    html.append(f'<div class="sp-stats">{b}</div>')
            elif b.startswith(('<div class="sp-field"', '<div class="sp-field ')):
                if html and html[-1].startswith('<div class="sp-fields sp-loose">'):
                    html[-1] = html[-1][:-6] + b + "</div>"
                else:
                    html.append(f'<div class="sp-fields sp-loose">{b}</div>')
            else:
                html.append(b)
        head = f'<h2 class="sp-card-h">{E(title)}</h2>' if title else ""
        acts = f'<div class="sp-actions">{"".join(buttons)}</div>' if buttons else ""
        cls = "sp-card sp-wide" if wide else "sp-card"
        return f'<section class="{cls}" id="s{n}">{head}{"".join(html)}{acts}</section>'

    # -- quick-pick: a form whose only editable control is a short <select> -----
    def _match_choices(self):
        """Control index -> choice rule, matched by field name + its form's button."""
        out = {}
        for rule in self.prof.get("choices", []):
            for c in self.p.ctls:
                if c.kind not in TEXTLIKE or c.name != rule["name"] or c.form is None:
                    continue
                btns = [b for b in self.p.ctls if b.form == c.form and b.is_button]
                if any(rule["button"].search(norm(b.label or b.value)) for b in btns):
                    out[c.i] = rule
        return out

    def choice_select(self, c, rule, common, name):
        opts, known = [], False
        for o in rule["options"]:
            val, text = o[0], o[1]
            sel = val == c.value
            known = known or sel
            conf = f' data-confirm="{E(o[2])}"' if len(o) > 2 else ""
            opts.append(f'<option value="{E(val)}"{" selected" if sel else ""}{conf}>{E(val)} – {E(text)}</option>')
        if not known:  # never change a value we don't know about
            opts.insert(0, f'<option value="{E(c.value)}" selected>{E(c.value)} – (not documented)</option>')
        hint = f'<span class="sp-hint">{E(rule["hint"])}</span>' if rule.get("hint") else ""
        return f'<select class="sp-in sp-choice"{name}{common}>{"".join(opts)}</select>{hint}'

    TEST_BTN = re.compile(r"\btest\b", re.I)
    OFF_TEXT = re.compile(r"^\s*(off|none|disabled?|normal)\b", re.I)

    def detect_test(self):
        """(control, readable value, off value) if a test pattern/mode is on, else None."""
        # 1) profile choice fields flagged as test mode (e.g. SanDevices code box)
        for i, rule in self.choices.items():
            if "test_off" in rule:
                c = self.p.ctls[i]
                if c.value.strip() != rule["test_off"]:
                    text = next((o[1] for o in rule["options"] if o[0] == c.value), "not documented")
                    return c, f"{c.value} – {text}", rule["test_off"]
        # 2) generic: a dropdown in a form whose button mentions "test", whose first
        #    option reads Off/None/Disabled/Normal, and which isn't on that option
        for c in self.p.ctls:
            if c.kind != "select" or c.form is None or not c.options or c.multiple:
                continue
            btns = [b for b in self.p.ctls if b.form == c.form and b.is_button]
            if not any(self.TEST_BTN.search(b.label or b.value) for b in btns):
                continue
            first = c.options[0]
            if not self.OFF_TEXT.match(first[1]):
                continue
            cur = next((o for o in c.options if o[2]), first)
            if cur is not first and not c.disabled:
                return c, norm(cur[1]), first[0]
        return None

    def test_banner(self):
        t = self.detect_test()
        if not t:
            return "", ""
        c, text, off = t
        banner = (
            f'<div class="sp-alert" role="alert" id="sp-test-alert">'
            f'<span class="sp-alert-icon" aria-hidden="true">&#9888;</span>'
            f'<div class="sp-alert-body"><b>Test pattern active:</b> {E(text)}'
            f'<div class="sp-alert-sub">The controller is ignoring incoming show data until this is turned off.</div></div>'
            f'<div class="sp-alert-acts">'
            f'<button type="button" class="sp-btn sp-alert-btn" data-test-off="{E(off)}" data-test-i="{c.i}">Turn off</button>'
            f'<a href="#" class="sp-alert-link" data-test-show="{c.i}">Show setting</a></div></div>'
        )
        badge = '<a href="#sp-test-alert" class="sp-test-badge" title="A test pattern is active">&#9888; Test on</a>'
        return banner, badge

    def quick_selects(self):
        by_form = {}
        for c in self.p.ctls:
            if c.form is not None and c.editable:
                by_form.setdefault(c.form, []).append(c)
        quick = set()
        for f, cs in by_form.items():
            if len(cs) == 1 and cs[0].kind == "select" and 2 <= len(cs[0].options) <= 10 \
                    and not cs[0].multiple and not cs[0].disabled:
                quick.add(cs[0].i)
        return quick

    def url(self, u):
        """Keep device-relative and same-device absolute URLs inside the proxy."""
        u = (u or "").strip()
        if self.host:
            m = re.match(r"^https?://" + re.escape(self.host) + r"(?::80)?(/.*)?$", u, re.I)
            if m:
                u = m.group(1) or "/"
        if u.startswith("/") and not u.startswith("//"):
            return self.base + u[1:]
        return u

    def render(self, ctx):
        self.base = ctx.get("base") or "/"
        self.host = ctx.get("host", "")
        quick = self.quick_selects()
        test_banner, test_badge = self.test_banner()
        cards = [self.section(s, n) for n, s in enumerate(self.p.sections)]
        missing = [c.i for c in self.p.ctls if c.i not in self.used]
        if missing:
            raise CannotReskin(f"{len(missing)} control(s) could not be placed")
        forms = "".join(
            f'<form id="{self.fid(n)}" data-sp="1" method="{E(f["method"])}" action="{E(self.url(f["action"]))}"'
            f'{(" enctype=" + chr(34) + E(f["enctype"]) + chr(34)) if f["enctype"] else ""}></form>'
            for n, f in enumerate(self.p.forms))
        name = self.prof.get("name") or self.p.heading or self.p.title or "Device"
        heading = self.p.heading or self.p.title
        refresh = ""
        if self.p.refresh and not any(c.editable for c in self.p.ctls):
            secs, url = self.p.refresh
            refresh = f'<meta http-equiv="refresh" content="{secs}{("; url=" + E(self.url(url))) if url else ""}">'
        live = (f'<button type="button" class="sp-chip" id="sp-live" aria-pressed="false" '
                f'title="Refresh values every few seconds">Live</button>') \
            if self.live_keys and ctx.get("live", True) and not self.p.refresh else ""
        quick_js = ",".join(str(i) for i in sorted(quick))
        return f"""<!DOCTYPE html>
<html lang="en"><head>{page_head(ctx, f'{ctx["label"]} · {name}', refresh)}
</head><body class="sp-modern">
<header class="sp-top">
  <a class="sp-back" href="{E(ctx.get("home", "/"))}" aria-label="All devices">&#8592;</a>
  <div class="sp-who"><div class="sp-who-name">{E(ctx["label"])}</div><div class="sp-who-sub">{E(name)}</div></div>
  {test_badge}{live}
  <details class="sp-menu"><summary aria-label="Menu">&#8943;</summary><div>
    <a href="{E(ctx["base"])}">Reload</a>
    <form method="post" action="{E(ctx.get("classic_url", "#"))}"><button type="submit" class="sp-menu-btn">Classic view</button></form>
    <a href="{E(ctx.get("home", "/"))}">All devices</a>
  </div></details>
</header>
<main class="sp-main">
{f'<div class="sp-hero"><h1>{E(heading)}</h1></div>' if heading and heading != name else ""}
{test_banner}<div class="sp-cards">{"".join(cards)}</div>
{f'<footer class="sp-foot">{" · ".join(self.footer)}</footer>' if self.footer else ""}
</main>
{forms}
<div id="sp-toast" role="status"></div>
<script>window.SP={{"target":{_js(ctx["target"])},"path":{_js(ctx["path"])},"liveUrl":{_js(ctx.get("live_url", ""))},"charset":{_js(self.p.charset or ctx.get("charset") or "windows-1252")},"quick":[{quick_js}]}};</script>
<script src="{E(static_url(ctx, "reskin.js"))}"></script>
</body></html>"""


def _js(s):
    import json
    return json.dumps(s).replace("</", "<\\/")


# ----------------------------------------------------------------------------
# Page shell shared by the modern and classic views (FPP theme + assets)
# ----------------------------------------------------------------------------
FPP_STYLESHEETS = [
    "/css/fpp-bootstrap/dist/fpp-bootstrap-5-3.css",
    "/css/fpp-system-design.css",
    "/css/fpp-dark.css",
]


def static_url(ctx, name):
    """Plugin static file URL, version-stamped so browser caches never serve a stale copy."""
    url = ctx.get("static", "/static/") + name
    ver = ctx.get("static_ver")
    return f"{url}{'&' if '?' in url else '?'}v={ver}" if ver else url


def theme_script(ctx):
    """Same rule FPP's own pages use: themeOverride wins, else the OS preference."""
    override = ctx.get("theme", "")
    return (
        "<script>(function(){var o=" + _js(override) + ",m=window.matchMedia('(prefers-color-scheme: dark)');"
        "function a(){var t=(o==='light'||o==='dark')?o:(m.matches?'dark':'light');"
        "document.documentElement.setAttribute('data-bs-theme',t);document.documentElement.style.colorScheme=t;}"
        "a();if(m.addEventListener)m.addEventListener('change',a);})();</script>"
    )


def page_head(ctx, title, extra=""):
    css = "".join(f'<link rel="stylesheet" href="{E(h)}">' for h in ctx.get("fpp_css", FPP_STYLESHEETS))
    return (
        '<meta charset="utf-8">'
        '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
        '<meta name="color-scheme" content="light dark">'
        f'<title>{E(title)}</title>{extra}'
        '<link rel="icon" href="data:,">'
        + theme_script(ctx) + css +
        f'<link rel="stylesheet" href="{E(static_url(ctx, "legacy-proxy.css"))}">'
        f'<link rel="stylesheet" href="{E(static_url(ctx, "reskin.css"))}">'
    )


# ----------------------------------------------------------------------------
# Classic view: the device's own page, cleaned up and restyled (fallback)
# ----------------------------------------------------------------------------
_ABS_ATTR = re.compile(r"""(\b(?:href|action|src)\s*=\s*)(['"])/(?!/)""", re.I)
_META_URL = re.compile(r"""(URL\s*=\s*)/(?!/)""", re.I)
_GLUED_ATTR = re.compile(r"""(=\s*'[^'<>]*'|=\s*"[^"<>]*")(?=[A-Za-z_-]+\s*=)""")
_HEAD_OPEN = re.compile(r"<head[^>]*>", re.I)
_BODY_OPEN = re.compile(r"<body[^>]*>", re.I)
_DOCTYPE = re.compile(r"^\s*<!doctype[^>]*>", re.I)
_META_CHARSET = re.compile(r"<meta[^>]+charset[^>]*>", re.I)


def classic(html: str, ctx: dict, note: str = "") -> str:
    base = ctx["base"]
    html = _ABS_ATTR.sub(lambda m: f"{m.group(1)}{m.group(2)}{base}", html)
    html = _META_URL.sub(lambda m: f"{m.group(1)}{base}", html)
    html = _GLUED_ATTR.sub(r"\1 ", html)
    cs = (re.search(r"charset\s*=\s*['\"]?([\w-]+)", "".join(_META_CHARSET.findall(html)), re.I) or [None, None])[1]
    html = _META_CHARSET.sub("", html)
    # The page is re-served as UTF-8; keep form submissions in the device's own
    # encoding (no declared charset means browsers used windows-1252).
    html = re.sub(r"<form\b", f'<form accept-charset="{cs or ctx.get("charset") or "windows-1252"}"', html, flags=re.I)
    html = _DOCTYPE.sub("", html)
    head_extra = page_head(ctx, f'{ctx["label"]} (classic view)')
    if ctx.get("action"):
        head_extra += f"<script>history.replaceState(null,'',{_js(base)});</script>"
    toggle = (f'<form method="post" action="{E(ctx["modern_url"])}" class="sp-inline-form">'
              f'<button type="submit" class="sp-reload sp-linkbtn">Modern view</button></form>'
              if ctx.get("modern_url") else "")
    bar = (
        f'<div class="sp-bar"><a class="sp-home" href="{E(ctx.get("home", "/"))}">&larr; All devices</a>'
        f'<span class="sp-dev">{E(ctx["label"])}</span>'
        f'<a class="sp-reload" href="{E(base)}">Reload</a>{toggle}</div>'
        + (f'<div class="sp-note">{E(note)}</div>' if note else "")
        + '<main class="sp-page">'
    )
    if _HEAD_OPEN.search(html):
        html = _HEAD_OPEN.sub(lambda m: m.group(0) + head_extra, html, count=1)
    else:
        html = f"<head>{head_extra}</head>" + html
    if _BODY_OPEN.search(html):
        html = _BODY_OPEN.sub(lambda m: m.group(0) + bar, html, count=1)
    else:
        html = bar + html
    html = re.sub(r"</body>", "</main></body>", html, count=1, flags=re.I)
    return "<!DOCTYPE html>\n" + html


# ----------------------------------------------------------------------------
# Public API
# ----------------------------------------------------------------------------
def check_safe(page: Page):
    if page.frames:
        raise CannotReskin("page uses frames")
    if page.script_chars > 300 or page.handlers > 4:
        raise CannotReskin("page depends on scripts")
    if not page.ctls and not page.tables and not any(
            it[0] in ("t", "a") and norm(it[1]) for s in page.sections for it in s.items):
        raise CannotReskin("empty page")


def reskin(source: str, ctx: dict) -> str:
    page = parse(source)
    check_safe(page)
    prof = find_profile(page.title, page.heading)
    return Renderer(page, prof).render(ctx)


def live_values(source: str) -> dict:
    """Text-only values keyed the same way render() keys them (for Live mode)."""
    page = parse(source)
    r = Renderer(page, find_profile(page.title, page.heading))
    out = {}
    # Re-render and harvest data-k spans: simplest way to guarantee identical keys.
    try:
        html = r.render({"label": "", "base": "", "target": "", "path": ""})
    except CannotReskin:
        return out
    for m in re.finditer(r'data-k="([^"]+)">(.*?)</span>(?:</span>)?', html):
        out[htmllib.unescape(m.group(1))] = htmllib.unescape(re.sub(r"<[^>]+>", "", m.group(2))).strip()
    return out
