"""Normalize CSS formatting while retaining selectors, values and string contents."""

from decimal import Decimal

import hashlib
import re


def canonical_css(css):
    literals = []

    def protect(match):
        literal = match.group(0)
        if literal.startswith("/*"):
            return ""
        literals.append(literal[1:-1])
        return f"__css_literal_{len(literals) - 1}__"

    css = re.sub(r'/\*.*?\*/|"(?:\\.|[^"\\])*"|\'(?:\\.|[^\'\\])*\'', protect, css, flags=re.S)
    css = re.sub(r"\s+", " ", css)
    css = re.sub(r"\s*([{}:;,()>+~])\s*", r"\1", css)
    css = re.sub(r";}", "}", css)
    css = re.sub(r"(?<![\w])\.(\d+)", r"0.\1", css)
    css = re.sub(
        r"(?<![\w-])\d+\.\d+",
        lambda m: format(Decimal(m.group(0)), "f").rstrip("0").rstrip("."),
        css,
    )
    css = re.sub(r"\s*!\s*important", "!important", css)
    for index, value in enumerate(literals):
        css = css.replace(f"__css_literal_{index}__", repr(value))
    return css.strip()


def css_fingerprint(css):
    return hashlib.sha256(canonical_css(css).encode()).hexdigest()
