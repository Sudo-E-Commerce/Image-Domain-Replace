const slideSelectors = [
    '.swiper-slide',
    '.slick-slide',
    '.owl-carousel .owl-item',
    '.slides',
    '.dots-custom'
];

function handleImageError(img) {
    const fallbackImageUrl = "/vendor/image-domain-replace/img/default_image.png";

    if (!img.errorCount) img.errorCount = 0;
    img.errorCount++;

    if (img.errorCount > 1) {
        setImageSrc(img, fallbackImageUrl);
        return;
    }

    const metaToken = document.querySelector('meta[name="csrf-token"]');
    if (!metaToken) {
        setImageSrc(img, fallbackImageUrl);
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
    .then(res => res.ok ? res.json() : Promise.reject())
    .then(data => setImageSrc(img, data.fallbackImageUrl || fallbackImageUrl))
    .catch(() => setImageSrc(img, fallbackImageUrl));
}

function setImageSrc(img, url) {
    img.src = url;
    if (img.hasAttribute('data-src')) img.setAttribute('data-src', url);
    if (img.hasAttribute('data-original')) img.setAttribute('data-original', url);
}

function attachErrorHandler(img) {
    if (img.dataset.errorHandlerAttached) return;
    img.dataset.errorHandlerAttached = 'true';
    img.onerror = function() {
        handleImageError(this);
    };
}

function onErrorImageInSlides() {
    slideSelectors.forEach(selector => {
        document.querySelectorAll(`${selector} img`).forEach(attachErrorHandler);

        // Theo dõi ảnh mới thêm vào slide (lazy load)
        document.querySelectorAll(selector).forEach(slide => {
            const observer = new MutationObserver(mutations => {
                mutations.forEach(mutation => {
                    mutation.addedNodes.forEach(node => {
                        if (node.nodeName === 'IMG') attachErrorHandler(node);
                        else if (node.querySelectorAll) node.querySelectorAll('img').forEach(attachErrorHandler);
                    });
                });
            });
            observer.observe(slide, { childList: true, subtree: true });
        });
    });
}

window.onErrorImageInSlides = onErrorImageInSlides;

document.addEventListener("DOMContentLoaded", function () {
    onErrorImageInSlides();
});