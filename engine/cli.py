#!/usr/bin/env python3
"""
Bridge between the plugin's PHP endpoint and the re-skin engine.

    python3 cli.py page    < {"html": "...", "ctx": {...}, "view": "modern|classic"}
        -> {"mode": "modern|classic", "note": "...", "html": "..."}
    python3 cli.py live    < {"html": "..."}
        -> {"values": {key: text}}

Standard library only (FPP ships python3; nothing to pip install).
"""
import json
import os
import sys
import traceback

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import reskin  # noqa: E402


def page(req):
    html, ctx = req["html"], req["ctx"]
    note = ""
    if req.get("view", "modern") != "classic":
        try:
            out = reskin.reskin(html, ctx)
            if ctx.get("action"):
                out = out.replace(
                    "</head>",
                    "<script>history.replaceState(null,''," + json.dumps(ctx["base"]) + ");</script></head>", 1)
            return {"mode": "modern", "note": "", "html": out}
        except reskin.CannotReskin as e:
            note = f"Modern view isn't available for this page: {e}. Showing the classic view."
        except Exception:  # never lose the page because of a renderer bug
            traceback.print_exc(file=sys.stderr)
            note = "Modern view failed on this page, so the classic view is shown (details in the plugin log)."
        ctx = dict(ctx, modern_url="")  # no point offering a toggle that would fall back again
    return {"mode": "classic", "note": note, "html": reskin.classic(html, ctx, note)}


def live(req):
    return {"values": reskin.live_values(req["html"])}


def main():
    mode = sys.argv[1] if len(sys.argv) > 1 else ""
    req = json.load(sys.stdin)
    if mode == "page":
        out = page(req)
    elif mode == "live":
        out = live(req)
    else:
        print(json.dumps({"error": f"unknown mode {mode!r}"}))
        return 2
    sys.stdout.write(json.dumps(out))
    return 0


if __name__ == "__main__":
    sys.exit(main())
