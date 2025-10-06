function onErrorImage() {
    document.querySelectorAll("img").forEach(function (img) {
        if (img.dataset.errorBound) return; // tránh gán lặp
        img.dataset.errorBound = true;

        img.onerror = function () {
            const fallbackImageUrl = "/assets/img/default_image.png";

            if (!img.hasOwnProperty("errorCount")) img.errorCount = 0;
            img.errorCount++;

            // Giới hạn thử 1 lần
            if (img.errorCount > 1) {
                img.src = fallbackImageUrl;
                if (img.hasAttribute("data-src")) img.setAttribute("data-src", fallbackImageUrl);
                if (img.hasAttribute("data-original")) img.setAttribute("data-original", fallbackImageUrl);
                return;
            }

            const metaToken = document.querySelector('meta[name="csrf-token"]');
            if (!metaToken) {
                img.src = fallbackImageUrl;
                if (img.hasAttribute("data-src")) img.setAttribute("data-src", fallbackImageUrl);
                if (img.hasAttribute("data-original")) img.setAttribute("data-original", fallbackImageUrl);
                return;
            }

            fetch("/ajax/get-fallback-image-url", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": metaToken.getAttribute("content"),
                },
                body: JSON.stringify({ imageUrl: img.src }),
            })
                .then((response) => (response.ok ? response.json() : Promise.reject()))
                .then((data) => {
                    const url = data?.fallbackImageUrl || fallbackImageUrl;
                    img.src = url;
                    if (img.hasAttribute("data-src")) img.setAttribute("data-src", url);
                    if (img.hasAttribute("data-original")) img.setAttribute("data-original", url);
                })
                .catch(() => {
                    img.src = fallbackImageUrl;
                    if (img.hasAttribute("data-src")) img.setAttribute("data-src", fallbackImageUrl);
                    if (img.hasAttribute("data-original")) img.setAttribute("data-original", fallbackImageUrl);
                });
        };
    });
}

// :mag: Chỉ quan sát vùng slide do sudoSlide hoặc các slider phổ biến
function observeSlideImages() {
    const selectors = [".s-wrap", ".s-content", ".swiper", ".carousel", ".slick-slider"];

    selectors.forEach(selector => {
        document.querySelectorAll(selector).forEach(container => {
            const observer = new MutationObserver(mutations => {
                let hasNewImage = false;

                for (const mutation of mutations) {
                    for (const node of mutation.addedNodes) {
                        if (node.tagName === "IMG" || node.querySelector?.("img")) {
                            hasNewImage = true;
                            break;
                        }
                    }
                    if (hasNewImage) break;
                }

                if (hasNewImage) onErrorImage();
            });

            observer.observe(container, { childList: true, subtree: true });
        });
    });
}

document.addEventListener("DOMContentLoaded", function () {
    onErrorImage();
    observeSlideImages();
});