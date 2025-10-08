const FALLBACK_IMAGE = "/vendor/image-domain-replace/img/default_image.png";
const fallbackCache = new Map(); // Cache để tránh duplicate requests
const processingImages = new WeakSet(); // Theo dõi ảnh đang xử lý

// Xử lý lỗi ảnh
function handleImageError(img) {
    if (processingImages.has(img)) return; // Tránh xử lý trùng
    processingImages.add(img);
    
    img.onerror = null;
    
    // Ưu tiên lấy từ data-src, sau đó data-original-src, cuối cùng là src hiện tại
    const originalSrc = img.dataset.src || img.dataset.originalSrc || img.src;
    
    // Bỏ qua nếu đã là fallback image
    if (originalSrc === FALLBACK_IMAGE) {
        processingImages.delete(img);
        return;
    }
    
    img.src = FALLBACK_IMAGE;

    const metaToken = document.querySelector('meta[name="csrf-token"]');
    if (!metaToken) {
        processingImages.delete(img);
        return;
    }

    // Kiểm tra cache trước
    if (fallbackCache.has(originalSrc)) {
        img.src = fallbackCache.get(originalSrc);
        processingImages.delete(img);
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
        console.log('📦 API Data:', data); // ← DEBUG
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
        
        // Lưu originalSrc: ưu tiên data-src (lazy load) > src hiện tại
        if (!img.dataset.originalSrc) {
            img.dataset.originalSrc = img.dataset.src || img.src;
        }
        
        // Kiểm tra ảnh đã load lỗi (chỉ với ảnh không phải lazy loading)
        if (!img.dataset.src && img.complete && img.naturalWidth === 0) {
            handleImageError(img);
        } else if (!img.dataset.src) {
            // Chỉ bind error event cho ảnh thường (không có data-src)
            img.addEventListener("error", () => handleImageError(img), { once: true });
        }
    });
}

// Lazy loading observer - Hỗ trợ cả native và data-src pattern
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
                
                // Xử lý data-src lazy loading (jQuery Lazy, Lazyload, etc.)
                if (img.dataset.src && !img.dataset.lazyLoaded) {
                    img.dataset.lazyLoaded = "true";
                    const lazySrc = img.dataset.src;
                    
                    // Lưu originalSrc trước khi load
                    img.dataset.originalSrc = lazySrc;
                    
                    // Test load ảnh trong memory
                    const tempImg = new Image();
                    
                    tempImg.onload = () => {
                        // Load thành công → Swap src
                        img.src = lazySrc;
                    };
                    
                    tempImg.onerror = () => {
                        // Load thất bại → Gọi API fallback
                        handleImageError(img);
                    };
                    
                    tempImg.src = lazySrc;
                }
                // Xử lý native lazy loading đã lỗi
                else if (img.complete && img.naturalWidth === 0) {
                    handleImageError(img);
                }
                
                lazyObserver.unobserve(img);
            }
        });
    }, { rootMargin: "100px" });

    // Tìm cả 2 loại: native loading="lazy" VÀ data-src pattern
    const lazyImages = document.querySelectorAll(
        'img[loading="lazy"]:not([data-lazy-loaded]), img[data-src]:not([data-lazy-loaded])'
    );
    
    lazyImages.forEach(img => {
        if (!img.dataset.errorBound) {
            setupImageErrorHandlers(img); // Bind error handler trước
        }
        lazyObserver.observe(img);
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

// Export cho sử dụng bên ngoài nếu cần
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { handleImageError, cleanup, init };
}