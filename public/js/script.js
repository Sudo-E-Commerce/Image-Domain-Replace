const FALLBACK_IMAGE = "/public/vendor/image-domain-replace/img/default_image.png";

// Xử lý lỗi ảnh
function handleImageError(img) {
    const metaToken = document.querySelector('meta[name="csrf-token"]');
    img.onerror = null; // tránh loop vô hạn
    img.src = FALLBACK_IMAGE;

    if (!metaToken) return;

    // Fetch fallback động 1 lần duy nhất
    fetch("/ajax/get-fallback-image-url", {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": metaToken.getAttribute("content"),
        },
        body: JSON.stringify({ imageUrl: img.dataset.originalSrc || img.src }),
    })
    .then(res => res.ok ? res.json() : null)
    .then(data => {
        if (data?.fallbackImageUrl) img.src = data.fallbackImageUrl;
    })
    .catch(() => {});
}

// Gán handler lỗi cho ảnh mới
function setupImageErrorHandlers(container = document) {
    container.querySelectorAll("img:not([data-error-bound])").forEach(img => {
        img.dataset.errorBound = "true";
        img.dataset.originalSrc = img.src;
        img.addEventListener("error", () => handleImageError(img), { once: true });
    });
}

// Quan sát ảnh lazy bằng 1 IntersectionObserver duy nhất
function observeLazyImages() {
    if (!("IntersectionObserver" in window)) {
        setupImageErrorHandlers();
        return;
    }

    const observer = new IntersectionObserver((entries, obs) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                if (img.complete && img.naturalWidth === 0) handleImageError(img);
                obs.unobserve(img);
            }
        });
    }, { rootMargin: "100px" });

    document.querySelectorAll("img[loading='lazy']").forEach(img => observer.observe(img));
}

// Quan sát DOM mới thêm ảnh, chỉ observe container chính nếu có
function observeNewImages(container = document.body) {
    const observer = new MutationObserver(mutations => {
        mutations.forEach(m => {
            m.addedNodes.forEach(node => {
                if (node.tagName === "IMG") setupImageErrorHandlers(node.parentNode);
                else if (node.querySelectorAll) setupImageErrorHandlers(node);
            });
        });
    });

    observer.observe(container, { childList: true, subtree: true });
}

// Khởi tạo
document.addEventListener("DOMContentLoaded", () => {
    setupImageErrorHandlers();
    observeLazyImages();
    observeNewImages();
});