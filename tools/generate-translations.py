#!/usr/bin/env python3
"""Build the translation catalog, including the native app's dynamic helpers.

WordPress' standard extractor cannot see field('Label', ...) or a label taken
from a resource map. This tokenizer extracts those actual helper arguments and
display properties, ordinary PHP/JS gettext calls, and literal exception
messages translated by the REST presentation layer. It never executes source.
Run from any directory: python3 tools/generate-translations.py
"""
from __future__ import annotations

import json
import re
from collections import defaultdict
from dataclasses import dataclass
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOMAIN = "woocommerce-marketing-os"
LEXER = re.compile(
    r"(?P<comment>/\*[\s\S]*?\*/|//[^\n]*|#[^\n]*)"
    r"|(?P<string>'(?:\\[\s\S]|[^'\\])*'|\"(?:\\[\s\S]|[^\"\\])*\")"
    r"|(?P<identifier>\\?[A-Za-z_$][A-Za-z0-9_$\\]*)"
    r"|(?P<punctuation>[^\s])"
)


@dataclass(frozen=True)
class Token:
    kind: str
    raw: str
    line: int


def tokenize(source: str) -> list[Token]:
    tokens = []
    previous, line = 0, 1
    for match in LEXER.finditer(source):
        line += source.count("\n", previous, match.start())
        previous = match.start()
        if match.lastgroup != "comment":
            tokens.append(Token(match.lastgroup, match.group(), line))
    return tokens


def literal(token: Token, php: bool = False) -> str | None:
    if token.kind != "string":
        return None
    body = token.raw[1:-1]
    if php and token.raw[0] == "'":
        return re.sub(r"\\(['\\])", r"\1", body)
    if php and re.search(r"(?<!\\)\$", body):
        return None
    substitutions = {"n": "\n", "r": "\r", "t": "\t", "b": "\b", "f": "\f", "v": "\v"}
    def decode(match):
        escaped = match.group(1)
        if escaped.startswith("u") and len(escaped) == 5:
            return chr(int(escaped[1:], 16))
        if escaped.startswith("x") and len(escaped) == 3:
            return chr(int(escaped[1:], 16))
        return substitutions.get(escaped, escaped)
    return re.sub(r"\\(u[0-9a-fA-F]{4}|x[0-9a-fA-F]{2}|[\s\S])", decode, body)


def arguments(tokens: list[Token], start: int) -> tuple[list[list[Token]], int]:
    """Split one call's arguments while respecting nested objects and arrays."""
    parts, current, stack = [], [], ["("]
    for index in range(start + 1, len(tokens)):
        raw = tokens[index].raw
        if raw in ("(", "[", "{"):
            stack.append(raw)
        elif raw in (")", "]", "}"):
            stack.pop()
            if not stack:
                return parts + [current], index
        if raw == "," and len(stack) == 1:
            parts.append(current)
            current = []
        else:
            current.append(tokens[index])
    return [], start


def static_string(part: list[Token], php: bool) -> str | None:
    return literal(part[0], php) if len(part) == 1 else None


def build_catalog() -> dict:
    entries = defaultdict(set)
    notes = defaultdict(set)
    def add(message, path, token, context=None, plural=None, note=None):
        if not message:
            return
        key = (context, message, plural)
        entries[key].add(f"{path.relative_to(ROOT).as_posix()}:{token.line}")
        if note:
            notes[key].add(note)

    paths = sorted(ROOT.glob("src/**/*.php")) + [
        ROOT / "woocommerce-marketing-os.php", ROOT / "uninstall.php",
        ROOT / "assets/admin.js", ROOT / "assets/builders.js", ROOT / "assets/tracker.js",
    ]
    gettext = {"__", "_e", "esc_html__", "esc_attr__", "_x", "_ex", "esc_html_x", "esc_attr_x", "_n", "_nx"}
    helpers = {"button", "field", "select"}
    for path in paths:
        tokens = tokenize(path.read_text())
        php = path.suffix == ".php"
        for index, token in enumerate(tokens):
            following = tokens[index + 1].raw if index + 1 < len(tokens) else ""
            if token.kind == "identifier" and following == "(":
                name = token.raw
                parts, end = arguments(tokens, index + 1)
                if not parts:
                    continue
                if name in gettext:
                    first = static_string(parts[0], php)
                    context = None
                    plural = None
                    if name in {"_x", "_ex", "esc_html_x", "esc_attr_x"} and len(parts) > 1:
                        context = static_string(parts[1], php)
                    if name in {"_n", "_nx"} and len(parts) > 1:
                        plural = static_string(parts[1], php)
                    if name == "_nx" and len(parts) > 3:
                        context = static_string(parts[3], php)
                    add(first, path, token, context, plural)
                    # Fallback labels in __(props.label || 'Default label').
                    if first is None:
                        for fallback in parts[0]:
                            if fallback.kind == "string":
                                add(literal(fallback, php), path, fallback)
                elif not php and name in helpers:
                    add(static_string(parts[0], False), path, token)
                    for displayed in parts[0]:
                        if displayed.kind == "string":
                            add(literal(displayed), path, displayed)
                    if name == "select" and len(parts) > 2:
                        for option in parts[2]:
                            if option.kind == "string":
                                add(literal(option), path, option)
                elif name.rsplit("\\", 1)[-1].endswith("Exception") and index and tokens[index - 1].raw == "new":
                    message = static_string(parts[0], php)
                    add(message, path, token, note="Server validation message; translated by the REST presentation layer.")
                    if message is None:
                        # The two dynamic validation messages are formatted by
                        # the REST layer with a translated, stable %s template.
                        first = literal(parts[0][0], php) if parts[0] else None
                        if first in {"Node is missing a required outcome: ", "Unknown request field: "}:
                            add(first + "%s", path, token, note="REST formats the dynamic value into this translated validation message.")
                        for fallback in parts[0]:
                            if fallback.kind == "string" and literal(fallback, php) == "Provider retry required.":
                                add(literal(fallback, php), path, fallback)
                elif not php and name == "Error" and index and tokens[index - 1].raw == "new":
                    add(static_string(parts[0], False), path, token)
                elif php and name.endswith("WP_Error") and len(parts) > 1:
                    add(static_string(parts[1], True), path, token)
            if not php:
                # All native UI display properties passed to JsonField/List/Empty.
                if token.raw in {"label", "help", "caption", "title"} and following == ":" and index + 2 < len(tokens):
                    add(literal(tokens[index + 2]), path, tokens[index + 2])
                # Graph validation errors are translated where they are rendered.
                if token.raw == "push" and following == "(" and index >= 2 and tokens[index - 2].raw == "errors":
                    parts, _ = arguments(tokens, index + 1)
                    if parts:
                        add(static_string(parts[0], False), path, token)
                # Resource menus: ['Campaigns','campaign','manage_campaigns'];
                # navigation maps: ['contacts','Customers and consent','view_contacts'].
                if token.kind == "string":
                    value = literal(token)
                    previous = tokens[index - 1].raw if index else ""
                    if value and previous in {"[", ","} and any(character.isupper() for character in value) and re.fullmatch(r"[A-Za-z][A-Za-z0-9 /&()-]*", value):
                        add(value, path, token)
        if not php and path.name == "admin.js":
            # These constants are labels as well as persistent select values.
            # Translation changes presentation only; submitted enums remain raw.
            for token in tokens:
                if token.kind == "string":
                    value = literal(token)
                    if value in {
                        "email", "sms", "push", "whatsapp", "telegram", "ads", "social", "webhook",
                        "programs", "promotions", "campaigns", "automations", "segments", "measurement",
                        "recommendations", "personalization", "analytics", "profiling", "phone",
                        "tag", "message", "coupon", "points", "review", "banner", "landing",
                        "latest", "category", "bestsellers", "trending", "pinned", "recent_views",
                        "purchase_history", "profile", "session", "conversion", "click", "revenue",
                        "percent", "fixed_cart", "fixed_product", "loyalty", "referral", "affiliate",
                        "all", "selected", "materialized", "dynamic", "once", "always",
                        "linear", "first_touch", "last_touch", "last_non_direct", "time_decay", "position_based", "custom",
                    }:
                        add(value, path, token)
        if not php and path.name == "builders.js":
            for variable in {"fields", "operators", "nodeTypes"}:
                for index, token in enumerate(tokens):
                    if token.raw == variable and index + 2 < len(tokens) and tokens[index + 1].raw == "=" and tokens[index + 2].raw == "[":
                        for candidate in tokens[index + 3:]:
                            if candidate.raw == "]":
                                break
                            add(literal(candidate), path, candidate)
    return entries, notes


def write_catalog() -> None:
    entries, notes = build_catalog()
    main = (ROOT / "woocommerce-marketing-os.php").read_text()
    version = re.search(r"Version:\s*(\S+)", main).group(1)
    headers = [
        f"Project-Id-Version: WooCommerce Marketing OS {version}\n",
        "Report-Msgid-Bugs-To: https://github.com/ildrm/woocommerce-marketing/issues\n",
        "POT-Creation-Date: 2026-10-04 00:00+0000\n",
        "PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\n",
        "Last-Translator: FULL NAME <EMAIL@ADDRESS>\n",
        "Language-Team: LANGUAGE <LL@li.org>\n", "Language: \n",
        "MIME-Version: 1.0\n", "Content-Type: text/plain; charset=UTF-8\n",
        "Content-Transfer-Encoding: 8bit\n", f"X-Domain: {DOMAIN}\n",
        "X-Generator: WMOS source tokenizer (tools/generate-translations.py)\n",
    ]
    quote = lambda value: json.dumps(value, ensure_ascii=False)
    output = [
        "# Copyright (C) 2026 WooCommerce Marketing OS contributors",
        "# This file is distributed under the same license as the plugin.",
        'msgid ""', 'msgstr ""', *[quote(header) for header in headers], "",
    ]
    for key in sorted(entries, key=lambda key: (key[1].casefold(), key[0] or "", key[2] or "")):
        context, message, plural = key
        output.extend("#. " + note for note in sorted(notes[key]))
        output.append("#: " + " ".join(sorted(entries[key])))
        if re.search(r"%(?:\d+\$)?[sd]", message):
            output.append("#, php-format")
        if context:
            output.append("msgctxt " + quote(context))
        output.append("msgid " + quote(message))
        if plural:
            output.extend(["msgid_plural " + quote(plural), 'msgstr[0] ""', 'msgstr[1] ""'])
        else:
            output.append('msgstr ""')
        output.append("")
    target = ROOT / "languages" / f"{DOMAIN}.pot"
    target.parent.mkdir(exist_ok=True)
    target.write_text("\n".join(output))
    print(f"Generated {target.relative_to(ROOT)} with {len(entries)} messages.")


if __name__ == "__main__":
    write_catalog()
