/* Shared by dynamically inserted recommendations and saved recipes. */
(function (root) {
    "use strict";
    function scale(line, factor) {
        if (Math.abs(factor - 1) < 0.00001) return line;
        const fractions = {
            "½": " 1/2",
            "¼": " 1/4",
            "¾": " 3/4",
            "⅓": " 1/3",
            "⅔": " 2/3",
            "⅛": " 1/8",
        };
        const normalized = line.replace(/[½¼¾⅓⅔⅛]/g, (char) => fractions[char]);
        const number = "(?:\\d+\\s+\\d+\\s*\\/\\s*\\d+|\\d+\\s*\\/\\s*\\d+|\\d+(?:[.,]\\d+)?)";
        const match = normalized.match(
            new RegExp("^\\s*(" + number + ")(?:\\s*[-–]\\s*(" + number + "))?(.*)$"),
        );
        if (!match) return line;
        function parse(value) {
            const fraction = value.match(/^(?:(\d+)\s+)?(\d+)\s*\/\s*(\d+)$/);
            return fraction
                ? Number(fraction[1] || 0) + Number(fraction[2]) / Math.max(1, Number(fraction[3]))
                : Number(value.replace(",", "."));
        }
        const format = (value) =>
            String(Math.round((value + Number.EPSILON) * 100) / 100).replace(".", ",");
        return (
            format(parse(match[1]) * factor) +
            (match[2] ? "–" + format(parse(match[2]) * factor) : "") +
            match[3]
        );
    }
    if (typeof module !== "undefined") module.exports = { scale };
    if (typeof document === "undefined") return;
    document.addEventListener("input", (event) => {
        if (!event.target.matches(".recipe-servings, .recipe-base")) return;
        const card = event.target.closest(".recipe-portions");
        const portions = Number(card.querySelector(".recipe-servings").value);
        const base = Number(card.querySelector(".recipe-base").value);
        const valid = [portions, base].every((n) => Number.isInteger(n) && n >= 1 && n <= 12);
        card.querySelector(".recipe-scale-status").textContent = valid
            ? `Quantités pour ${portions} portion(s), calculées à partir d’une base de ${base}.`
            : "Choisis des nombres entiers entre 1 et 12.";
        const form = card.parentElement.querySelector("form");
        if (form?.querySelector('[name="portions"]')) {
            form.querySelector("button").disabled = !valid;
            if (valid) {
                form.querySelector('[name="portions"]').value = portions;
                form.querySelector('[name="base_portions"]').value = base;
            }
        }
        if (!valid) return;
        const source = JSON.parse(card.dataset.sourceIngredients);
        card.querySelector(".recipe-ingredients").replaceChildren(
            ...source.map((line) => {
                const item = document.createElement("li");
                item.textContent = scale(line, portions / base);
                return item;
            }),
        );
    });
})(typeof globalThis !== "undefined" ? globalThis : this);
