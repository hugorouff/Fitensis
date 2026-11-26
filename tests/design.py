"""Vérifie les styles Fitensis et composants d’origine des trois pages."""

from css_normalize import css_fingerprint

import hashlib, json, pathlib, re

root = pathlib.Path(__file__).resolve().parents[1]
reference = json.loads((root / "tests/original_ui.json").read_text())
folders = {"index": "accueil", "exercises": "exercices", "recipes": "recettes"}
for name, expected in reference.items():
    text = (root / "templates" / folders[name] / "index.html.twig").read_text()
    styles = [css_fingerprint(css) for css in re.findall(r"<style[^>]*>(.*?)</style>", text, re.S)]
    assert styles == expected["normalized_styles"], f"{name}: styles différents de la référence Fitensis"
    assert set(expected["ids"]) <= set(
        re.findall(r'\bid="([^"]+)"', text)
    ), f"{name}: composants originaux manquants"
    assert "recherche-web" not in text and "SerpApi" not in text
print(
    "OK — palette Fitensis et composants vérifiés sur les trois pages."
)
