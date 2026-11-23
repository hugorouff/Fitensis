(() => {
    "use strict";
    document.querySelectorAll("[data-chart]").forEach((chart) => {
        const points = Array.from(chart.querySelectorAll("[data-chart-point]"));
        function display(point) {
            chart.querySelector(".perf-chart-caption").textContent =
                point.dataset.chartPoint;
        }
        points.forEach((point, index) => {
            point.addEventListener("mouseenter", () => display(point));
            point.addEventListener("focus", () => display(point));
            point.addEventListener("click", () => display(point));
            point.addEventListener("keydown", (event) => {
                if (
                    ["ArrowRight", "ArrowLeft", "Enter", " "].includes(
                        event.key,
                    )
                )
                    event.preventDefault();
                if (event.key === "ArrowRight")
                    points[Math.min(index + 1, points.length - 1)].focus();
                if (event.key === "ArrowLeft")
                    points[Math.max(index - 1, 0)].focus();
                if (["Enter", " "].includes(event.key)) display(point);
            });
        });
    });
})();
