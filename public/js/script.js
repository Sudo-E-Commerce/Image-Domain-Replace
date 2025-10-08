/**
 * Universal Image Error Handler
 * Tự động phát hiện và xử lý lỗi ảnh cho mọi pattern:
 * - Ảnh thường (không lazy)
 * - data-src (LazyLoad, Lazyload.js)
 * - data-original (Owl Carousel, Lazy Load XT)
 * - loading="lazy" (Native browser lazy loading)
 */

const FALLBACK_IMAGE = "/vendor/image-domain-replace/img/default_image.png";
const fallbackCache = new Map();
const processingImages = new WeakSet();

// ========================================
// CORE: Xử lý lỗi ảnh
// ========================================
function handleImageError(img) {
    if (processingImages.has(img)) return;
    processingImages.add(img);
    
    img.onerror = null;
    
    // ✅ Universal: Detect tất cả các pattern
    const originalSrc = getOriginalImageUrl(img);
    
    // Skip nếu không có URL hợp lệ
    if (!originalSrc || originalSrc === FALLBACK_IMAGE) {
        processingImages.delete(img);
        return;
    }
    
    img.src = FALLBACK_IMAGE;

    const metaToken = document.querySelector('meta[name="csrf-token"]');
    if (!metaToken) {
        processingImages.delete(img);
        return;
    }

    // Check cache
    if (fallbackCache.has(originalSrc)) {
        img.src = fallbackCache.get(originalSrc);
        processingImages.delete(img);
        return;
    }

    // Call API
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

// ========================================
// HELPER: Lấy URL gốc từ mọi pattern
// ========================================
function getOriginalImageUrl(img) {
    // Priority order: data-original > data-src > data-lazy-src > data-original-src > src
    return img.dataset.original || 
           img.dataset.src || 
           img.dataset.lazySrc ||
           img.dataset.originalSrc || 
           img.src;
}

// ========================================
// HELPER: Kiểm tra có phải lazy load không
// ========================================
function isLazyImage(img) {
    return !!(
        img.dataset.original || 
        img.dataset.src || 
        img.dataset.lazySrc ||
        img.getAttribute('loading') === 'lazy'
    );
}

// ========================================
// HELPER: Lấy lazy URL (nếu có)
// ========================================
function getLazyUrl(img) {
    return img.dataset.original || 
           img.dataset.src || 
           img.dataset.lazySrc;
}

// ========================================
// SETUP: Gán error handlers
// ========================================
function setupImageErrorHandlers(container = document) {
    const images = container.tagName === "IMG" 
        ? [container] 
        : container.querySelectorAll("img:not([data-error-bound])");
    
    images.forEach(img => {
        img.dataset.errorBound = "true";
        
        // Lưu URL gốc để dùng sau này
        if (!img.dataset.originalSrc) {
            img.dataset.originalSrc = getOriginalImageUrl(img);
        }
        
        const isLazy = isLazyImage(img);
        
        if (!isLazy) {
            // Ảnh thường: Xử lý ngay nếu đã lỗi
            if (img.complete && img.naturalWidth === 0) {
                handleImageError(img);
            } else {
                // Bind error event
                img.addEventListener("error", () => handleImageError(img), { once: true });
            }
        }
        // Ảnh lazy sẽ được xử lý bởi observer
    });
}

// ========================================
// OBSERVER: Lazy Loading (Universal)
// ========================================
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
                
                // Get lazy URL từ bất kỳ pattern nào
                const lazySrc = getLazyUrl(img);
                
                if (lazySrc && !img.dataset.lazyLoaded) {
                    img.dataset.lazyLoaded = "true";
                    img.dataset.originalSrc = lazySrc;
                    
                    // Test load trong memory
                    const tempImg = new Image();
                    
                    tempImg.onload = () => {
                        // Success: Swap src
                        img.src = lazySrc;
                        
                        // Cleanup lazy attributes
                        delete img.dataset.original;
                        delete img.dataset.src;
                        delete img.dataset.lazySrc;
                    };
                    
                    tempImg.onerror = () => {
                        // Failed: Call API fallback
                        handleImageError(img);
                    };
                    
                    tempImg.src = lazySrc;
                }
                // Native lazy loading đã lỗi
                else if (img.complete && img.naturalWidth === 0) {
                    handleImageError(img);
                }
                
                lazyObserver.unobserve(img);
            }
        });
    }, { rootMargin: "100px" });

    // ✅ Query tất cả các pattern lazy loading
    const lazyImages = document.querySelectorAll(
        'img[loading="lazy"]:not([data-lazy-loaded]), ' +
        'img[data-original]:not([data-lazy-loaded]), ' +
        'img[data-src]:not([data-lazy-loaded]), ' +
        'img[data-lazy-src]:not([data-lazy-loaded])'
    );
    
    lazyImages.forEach(img => {
        if (!img.dataset.errorBound) {
            setupImageErrorHandlers(img);
        }
        lazyObserver.observe(img);
    });
}

// ========================================
// OBSERVER: DOM Mutations
// ========================================
let mutationObserver = null;
let mutationTimeout = null;

function observeNewImages(container = document.body) {
    mutationObserver = new MutationObserver(() => {
        clearTimeout(mutationTimeout);
        mutationTimeout = setTimeout(() => {
            setupImageErrorHandlers();
            observeLazyImages();
        }, 100);
    });

    mutationObserver.observe(container, { 
        childList: true, 
        subtree: true 
    });
}

// ========================================
// UTILITY: Cleanup
// ========================================
function cleanup() {
    if (lazyObserver) lazyObserver.disconnect();
    if (mutationObserver) mutationObserver.disconnect();
    clearTimeout(mutationTimeout);
    fallbackCache.clear();
}

// ========================================
// UTILITY: Manual trigger (for AJAX content)
// ========================================
function scanImages(container = document) {
    setupImageErrorHandlers(container);
    observeLazyImages();
}

// ========================================
// INIT
// ========================================
function init() {
    setupImageErrorHandlers();
    observeLazyImages();
    observeNewImages();
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
} else {
    init();
}

// ========================================
// EXPORTS (for manual control)
// ========================================
if (typeof window !== 'undefined') {
    window.ImageErrorHandler = {
        init,
        cleanup,
        scanImages,
        handleImageError
    };
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = { init, cleanup, scanImages, handleImageError };
}