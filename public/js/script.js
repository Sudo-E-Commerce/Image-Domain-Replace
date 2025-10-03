function handleImageError(img) {
    const fallbackImageUrl = "/vendor/core/core/base/img/placeholder.png";

    if (!img.errorCount) {
        img.errorCount = 0;
    }
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
        body: JSON.stringify({
            imageUrl: img.src,
        }),
    })
    .then((response) => {
        if (response.ok) {
            return response.json();
        } else {
            throw new Error("Failed to fetch fallback image URL");
        }
    })
    .then((data) => {
        if (data.fallbackImageUrl) {
            setImageSrc(img, data.fallbackImageUrl);
        } else {
            setImageSrc(img, fallbackImageUrl);
        }
    })
    .catch((error) => {
        setImageSrc(img, fallbackImageUrl);
    });
}

function setImageSrc(img, url) {
    img.src = url;
    if (img.hasAttribute('data-src')) {
        img.setAttribute('data-src', url);
    }
    if (img.hasAttribute('data-original')) {
        img.setAttribute('data-original', url);
    }
}

function attachErrorHandler(img) {
    // Tránh gắn handler nhiều lần
    if (img.dataset.errorHandlerAttached) {
        return;
    }

    img.dataset.errorHandlerAttached = 'true';

    img.onerror = function() {
        handleImageError(this);
    };

    // Kiểm tra nếu ảnh đã lỗi trước khi attach handler
    if (img.complete && img.naturalHeight === 0 && img.naturalWidth === 0) {
        handleImageError(img);
    }
}

function onErrorImage() {
    // Xử lý tất cả ảnh hiện tại
    document.querySelectorAll("img").forEach(function (img) {
        attachErrorHandler(img);
    });

    // Theo dõi ảnh mới được thêm vào DOM (cho Swiper lazy load)
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            mutation.addedNodes.forEach(function(node) {
                // Nếu node là img
                if (node.nodeName === 'IMG') {
                    attachErrorHandler(node);
                }
                // Nếu node chứa img
                if (node.querySelectorAll) {
                    node.querySelectorAll('img').forEach(function(img) {
                        attachErrorHandler(img);
                    });
                }
            });
        });
    });

    // Bắt đầu observe toàn bộ body
    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

    // Lưu observer để có thể disconnect sau này nếu cần
    window.imageErrorObserver = observer;
}

window.onErrorImage = onErrorImage;

document.addEventListener("DOMContentLoaded", function () {
    onErrorImage();
});