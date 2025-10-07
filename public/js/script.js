const FALLBACK_IMAGE = "/public/vendor/image-domain-replace/img/default_image.png";
const fallbackCache = new Map(); // Cache để tránh duplicate requests
const processingImages = new WeakSet(); // Theo dõi ảnh đang xử lý

// Xử lý lỗi ảnh
function handleImageError(img) {
    if (processingImages.has(img)) return; // Tránh xử lý trùng
    processingImages.add(img);
    
    img.onerror = null;
    const originalSrc = img.dataset.originalSrc || img.src;
    img.src = FALLBACK_IMAGE;

    const metaToken = document.querySelector('meta[name="csrf-token"]');
    if (!metaToken) return;

    // Kiểm tra cache trước
    if (fallbackCache.has(originalSrc)) {
        img.src = fallbackCache.get(originalSrc);
        return;
    }

    fetch("/ajax/get-fallback-image-url", {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": metaToken.getAttribute("content"),
        },
        body: JSON.stringify({ imageUrl: originalSrc }),
    })
    .then(res => res.ok ? res.json() : null)
    .then(data => {
        if (data?.fallbackImageUrl) {
            fallbackCache.set(originalSrc, data.fallbackImageUrl);
            img.src = data.fallbackImageUrl;
        }
    })
    .catch(() => {})
    .finally(() => processingImages.delete(img));
}

// Gán handler lỗi cho ảnh
function setupImageErrorHandlers(container = document) {
    const images = container.tagName === "IMG" 
        ? [container] 
        : container.querySelectorAll("img:not([data-error-bound])");
    
    images.forEach(img => {
        img.dataset.errorBound = "true";
        img.dataset.originalSrc = img.src;
        
        // Kiểm tra ảnh đã load lỗi
        if (img.complete && img.naturalWidth === 0) {
            handleImageError(img);
        } else {
            img.addEventListener("error", () => handleImageError(img), { once: true });
        }
    });
}

// Lazy loading observer
let lazyObserver = null;
function observeLazyImages() {
    if (!("IntersectionObserver" in window)) {
        setupImageErrorHandlers();
        return;
    }

    lazyObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                if (img.complete && img.naturalWidth === 0) handleImageError(img);
                lazyObserver.unobserve(img);
            }
        });
    }, { rootMargin: "100px" });

    document.querySelectorAll("img[loading='lazy']").forEach(img => {
        if (!img.dataset.errorBound) lazyObserver.observe(img);
    });
}

// Mutation observer với debounce
let mutationObserver = null;
let mutationTimeout = null;
function observeNewImages(container = document.body) {
    mutationObserver = new MutationObserver(() => {
        clearTimeout(mutationTimeout);
        mutationTimeout = setTimeout(() => {
            setupImageErrorHandlers();
            observeLazyImages();
        }, 100); // Debounce 100ms
    });

    mutationObserver.observe(container, { childList: true, subtree: true });
}

// Cleanup khi cần
function cleanup() {
    if (lazyObserver) lazyObserver.disconnect();
    if (mutationObserver) mutationObserver.disconnect();
    clearTimeout(mutationTimeout);
}

// Khởi tạo
if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
} else {
    init();
}

function init() {
    setupImageErrorHandlers();
    observeLazyImages();
    observeNewImages();
}